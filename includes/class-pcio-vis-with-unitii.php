<?php
/**
 * Unitii → Vis sync orchestrator.
 *
 * @package PCIO_VIS_With_Unitii
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Port of the Fjordglimt sync_unitii.php job, driven by the settings page:
 *
 * 1. Read volunteers, events and shifts from the Unitii API into the staging tables.
 * 2. Delete staged volunteers that are no longer in Unitii.
 * 3. Create/update Vis members, grant vis_volunteer and add them to the workgroup.
 * 4. Map every staged volunteer-shift to a Vis event crew row (creating the
 *    event from the template when no event of the configured type exists that day).
 * 5. Remove workgroup members that are no longer Unitii volunteers.
 */
class PCIO_VIS_With_Unitii {

	const MONTHS_BACK      = 3;
	const MAX_RESULT_LINES = 60;

	/** Template times re-dated onto the new event's day. */
	const TEMPLATE_TIME_FIELDS = [ 'start_datetime', 'end_datetime', 'get_in_datetime', 'door_opening_datetime' ];

	/** @var string[] */
	private $log = [];

	/** @var array<string,int> */
	private $count = [
		'errors'               => 0,
		'items'                => 0,
		'non_users'            => 0,
		'new_volunteers'       => 0,
		'new_events'           => 0,
		'new_shifts'           => 0,
		'new_volunteer_shifts' => 0,
		'deleted_volunteers'   => 0,
		'members_created'      => 0,
		'members_updated'      => 0,
		'members_skipped'      => 0,
		'wp_accounts'          => 0,
		'caps_granted'         => 0,
		'wg_added'             => 0,
		'wg_removed'           => 0,
		'shifts_analyzed'      => 0,
		'vis_events_created'   => 0,
		'vis_events_found'     => 0,
		'crew_created'         => 0,
		'crew_updated'         => 0,
		'crew_ok'              => 0,
		'crew_deleted'         => 0,
		'staging_deleted'      => 0,
	];

	/** @var DateTimeZone */
	private $tz;

	/** @var array<int,int> Staged volunteer id => Vis member id. */
	private $member_by_volunteer = [];

	/** @var array<string,int> Local date (Y-m-d) => Vis event id. */
	private $event_by_day = [];

	/** @var array<int,array<int,array<string,mixed>>> Vis event id => crew rows. */
	private $crew_by_event = [];

	/** @var array<int,bool> Crew ids owned by a volunteer-shift row. */
	private $linked_crew = [];

	/** @var array<string,mixed>|null */
	private $template;

	/**
	 * Cron entry point. Called by the core sync executor.
	 *
	 * @param array<string,mixed> $job The me_sync row (unused).
	 * @return string Summary stored as the job result.
	 */
	public static function run_sync( array $job = [] ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Executor signature.
		$sync = new self();
		return $sync->run();
	}

	private function __construct() {
		$this->tz = wp_timezone();
	}

	/**
	 * Run all steps and return the summary.
	 */
	private function run(): string {
		$config_error = $this->check_config();
		if ( '' !== $config_error ) {
			return $this->finish( $config_error );
		}

		$period_start = ( new DateTimeImmutable( 'today', $this->tz ) )->modify( '-' . self::MONTHS_BACK . ' months' );
		$period_end   = $period_start->modify( '+1 year' );
		$start_utc    = $this->to_utc_string( $period_start );
		$end_utc      = $this->to_utc_string( $period_end );

		$this->log(
			sprintf(
				/* translators: 1: period start date, 2: period end date */
				__( 'Period %1$s – %2$s', 'pcio-vis-with-unitii' ),
				$period_start->format( 'Y-m-d' ),
				$period_end->format( 'Y-m-d' )
			)
		);

		$client = new PCIO_VIS_With_Unitii_Client( PCIO_VIS_With_Unitii_Settings::api_url() );
		$login  = $client->login( PCIO_VIS_With_Unitii_Settings::username(), PCIO_VIS_With_Unitii_Settings::password() );
		if ( is_wp_error( $login ) ) {
			return $this->finish( $login->get_error_message() );
		}

		$items = $client->get_volunteers_and_shifts(
			$period_start->getTimestamp() * 1000,
			$period_end->getTimestamp() * 1000
		);
		// Abort before touching anything: a partial fetch would remove valid shifts.
		if ( is_wp_error( $items ) ) {
			return $this->finish( $items->get_error_message() );
		}

		PCIO_VIS_History::begin( __( 'Unitii sync', 'pcio-vis-with-unitii' ) );

		// 1. Read from the Unitii API into the staging tables.
		PCIO_VIS_With_Unitii_DB::mark_period_for_removal( $start_utc, $end_utc );
		$valid_ids = $this->stage_items( $items );

		// 2. Ensure the staged volunteers match Unitii.
		$this->count['deleted_volunteers'] = PCIO_VIS_With_Unitii_DB::delete_obsolete_volunteers( $valid_ids );

		// 3. Volunteers → Vis members + workgroup.
		$this->sync_members();

		// 4. Register the volunteers' shifts in the Vis event crew lists.
		$this->map_shifts( $start_utc, $end_utc );

		// 5. Remove workgroup members that are no longer Unitii volunteers.
		if ( [] !== $valid_ids ) {
			$this->remove_obsolete_workgroup_members();
		}

		PCIO_VIS_History::commit();

		return $this->finish( '' );
	}

	// ── Configuration ─────────────────────────────────────────────

	/**
	 * Validate the settings. Returns an error message, or '' when OK.
	 */
	private function check_config(): string {
		$missing = PCIO_VIS_With_Unitii_Settings::missing();
		if ( [] !== $missing ) {
			return sprintf(
				/* translators: %s: comma-separated list of missing settings */
				__( 'Unitii sync is not configured. Missing: %s.', 'pcio-vis-with-unitii' ),
				implode( ', ', $missing )
			);
		}
		if ( ! PCIO_VIS_Event_Types_DB::get( PCIO_VIS_With_Unitii_Settings::event_type_id() ) ) {
			return __( 'The configured Vis event type no longer exists.', 'pcio-vis-with-unitii' );
		}
		if ( ! PCIO_VIS_Event_Roles_DB::get( PCIO_VIS_With_Unitii_Settings::role_id() ) ) {
			return __( 'The configured Vis event crew role no longer exists.', 'pcio-vis-with-unitii' );
		}
		if ( ! PCIO_VIS_Workgroups_DB::get( PCIO_VIS_With_Unitii_Settings::workgroup_id() ) ) {
			return __( 'The configured Vis workgroup no longer exists.', 'pcio-vis-with-unitii' );
		}
		$this->template = PCIO_VIS_Events_DB::get( PCIO_VIS_With_Unitii_Settings::template_event_id() );
		if ( ! $this->template || empty( $this->template['start_datetime'] ) ) {
			return __( 'The configured template event no longer exists.', 'pcio-vis-with-unitii' );
		}
		if ( (int) $this->template['event_type_id'] !== PCIO_VIS_With_Unitii_Settings::event_type_id() ) {
			return __( 'The template event does not have the configured Vis event type.', 'pcio-vis-with-unitii' );
		}
		return '';
	}

	// ── Step 1: staging ───────────────────────────────────────────

	/**
	 * Store the API items in the staging tables.
	 *
	 * @param array<int,mixed> $items API items.
	 * @return string[] Unitii ids of all volunteers returned by the API.
	 */
	private function stage_items( array $items ): array {
		$valid_ids = [];
		foreach ( $items as $item ) {
			++$this->count['items'];
			$volunteer = is_array( $item ) ? ( $item['volunteer'] ?? null ) : null;
			if ( null === $volunteer ) {
				++$this->count['non_users'];
				continue;
			}
			if ( ! is_array( $volunteer ) || empty( $volunteer['id'] ) ) {
				$this->error( __( 'Invalid volunteer data structure, item skipped.', 'pcio-vis-with-unitii' ) );
				continue;
			}

			$unitii_id   = (string) $volunteer['id'];
			$valid_ids[] = $unitii_id;
			$staged      = PCIO_VIS_With_Unitii_DB::upsert_volunteer(
				$unitii_id,
				[
					'name'        => sanitize_text_field( trim( ( $volunteer['firstName'] ?? '' ) . ' ' . ( $volunteer['lastName'] ?? '' ) ) ),
					'email'       => sanitize_email( (string) ( $volunteer['email'] ?? '' ) ),
					'phone'       => sanitize_text_field( (string) ( $volunteer['phoneNumber'] ?? '' ) ),
					'total_hours' => round( (float) ( $volunteer['hours'] ?? 0 ) / 3600000, 2 ),
					'deleted'     => empty( $volunteer['deleted'] ) ? 0 : 1,
					'user_type'   => sanitize_text_field( (string) ( $volunteer['userType'] ?? '' ) ),
					'created'     => $this->ms_to_utc( $volunteer['createdAt'] ?? null ),
				]
			);
			if ( $staged['is_new'] ) {
				++$this->count['new_volunteers'];
			}

			$shifts = $item['shifts'] ?? null;
			if ( ! is_array( $shifts ) ) {
				$this->error(
					sprintf(
						/* translators: %s: Unitii volunteer id */
						__( 'No shifts array for Unitii volunteer %s.', 'pcio-vis-with-unitii' ),
						$unitii_id
					)
				);
				continue;
			}
			foreach ( $shifts as $shift ) {
				$this->stage_shift( $staged['id'], $shift );
			}
		}
		return $valid_ids;
	}

	/**
	 * Store one shift (and its event) and link it to the volunteer.
	 *
	 * @param int   $volunteer_id Staged volunteer id.
	 * @param mixed $shift        Shift array from the API.
	 */
	private function stage_shift( int $volunteer_id, $shift ): void {
		if ( ! is_array( $shift ) || empty( $shift['id'] ) ) {
			$this->error( __( 'Shift without id skipped.', 'pcio-vis-with-unitii' ) );
			return;
		}
		$event = $shift['event'] ?? null;
		if ( ! is_array( $event ) || empty( $event['id'] ) ) {
			$this->error(
				sprintf(
					/* translators: %s: Unitii shift id */
					__( 'Unitii shift %s has no valid event, skipped.', 'pcio-vis-with-unitii' ),
					(string) $shift['id']
				)
			);
			return;
		}

		$staged_event = PCIO_VIS_With_Unitii_DB::upsert_event(
			(string) $event['id'],
			[
				'title'       => sanitize_text_field( (string) ( $event['title'] ?? '' ) ),
				'start_utc'   => $this->ms_to_utc( $event['startDateTime'] ?? null ),
				'end_utc'     => $this->ms_to_utc( $event['endDateTime'] ?? null ),
				'description' => sanitize_textarea_field( (string) ( $event['description'] ?? '' ) ),
			]
		);
		if ( $staged_event['is_new'] ) {
			++$this->count['new_events'];
		}

		$start = $this->ms_to_utc( $shift['startDateTime'] ?? null );
		$end   = $this->ms_to_utc( $shift['endDateTime'] ?? null );
		if ( null === $start || null === $end ) {
			$this->error(
				sprintf(
					/* translators: %s: Unitii shift id */
					__( 'Unitii shift %s has a missing or invalid start/end time.', 'pcio-vis-with-unitii' ),
					(string) $shift['id']
				)
			);
		}

		$staged_shift = PCIO_VIS_With_Unitii_DB::upsert_shift(
			(string) $shift['id'],
			[
				'event_id'  => $staged_event['id'],
				'start_utc' => $start,
				'end_utc'   => $end,
				'location'  => sanitize_text_field( (string) ( $shift['location'] ?? '' ) ),
			]
		);
		if ( $staged_shift['is_new'] ) {
			++$this->count['new_shifts'];
		}

		if ( PCIO_VIS_With_Unitii_DB::touch_volunteer_shift( $volunteer_id, $staged_shift['id'] ) ) {
			++$this->count['new_volunteer_shifts'];
		}
	}

	// ── Step 3: members + workgroup ───────────────────────────────

	/**
	 * Create/update Vis members for the staged volunteers, grant the
	 * vis_volunteer capability and add them to the configured workgroup.
	 */
	private function sync_members(): void {
		$workgroup_id = PCIO_VIS_With_Unitii_Settings::workgroup_id();
		$workgroup    = PCIO_VIS_Workgroups_DB::get( $workgroup_id );
		$in_group     = [];
		foreach ( (array) ( $workgroup['members'] ?? [] ) as $row ) {
			$in_group[ (int) $row['id'] ] = true;
		}

		foreach ( PCIO_VIS_With_Unitii_DB::get_volunteers() as $volunteer ) {
			$member_id = $this->sync_member( $volunteer );
			if ( $member_id <= 0 ) {
				continue;
			}
			$this->member_by_volunteer[ (int) $volunteer['id'] ] = $member_id;

			if ( ! isset( $in_group[ $member_id ] ) ) {
				PCIO_VIS_Workgroups_DB::add_member( $workgroup_id, $member_id );
				$in_group[ $member_id ] = true;
				++$this->count['wg_added'];
			}
		}
	}

	/**
	 * Find or create the Vis member for one staged volunteer.
	 *
	 * @param array<string,mixed> $volunteer Staged volunteer row.
	 * @return int Member id, or 0 when there is none.
	 */
	private function sync_member( array $volunteer ): int {
		$email = sanitize_email( PCIO_VIS_With_Unitii_Settings::vis_email_for( (string) $volunteer['email'] ) );
		if ( ! is_email( $email ) ) {
			$this->error(
				sprintf(
					/* translators: 1: volunteer name, 2: email address */
					__( 'Unitii volunteer %1$s has an invalid email "%2$s".', 'pcio-vis-with-unitii' ),
					(string) $volunteer['name'],
					(string) $volunteer['email']
				)
			);
			return 0;
		}

		$last_shift = $this->utc_to_local( $volunteer['last_shift_utc'] ?? null );
		$joined     = $this->utc_to_local( $volunteer['created'] ?? null );
		$joined     = null !== $joined ? substr( $joined, 0, 10 ) : null;
		$member_id  = PCIO_VIS_DB::find_id_by_email( $email );

		if ( $member_id > 0 ) {
			$member = PCIO_VIS_DB::get( $member_id );
			if ( ! $member ) {
				return 0;
			}
			$this->update_member( $member, $volunteer, $joined );
		} else {
			// Same rule as prod: only volunteers that have actually worked become Vis members.
			if ( (float) $volunteer['total_hours'] <= 0 || null === $last_shift ) {
				++$this->count['members_skipped'];
				return 0;
			}
			$member_id = (int) PCIO_VIS_DB::create_with_next_number(
				[
					'name'         => (string) $volunteer['name'],
					'email'        => $email,
					'phone'        => (string) $volunteer['phone'],
					'join_date'    => (string) $joined,
					'is_volunteer' => 1,
				]
			);
			if ( $member_id <= 0 ) {
				$this->error(
					sprintf(
						/* translators: %s: email address */
						__( 'Could not create Vis member %s.', 'pcio-vis-with-unitii' ),
						$email
					)
				);
				return 0;
			}
			++$this->count['members_created'];
			$this->log(
				sprintf(
					/* translators: 1: member name, 2: email address */
					__( 'Created Vis volunteer %1$s <%2$s>.', 'pcio-vis-with-unitii' ),
					(string) $volunteer['name'],
					$email
				)
			);
			$member = PCIO_VIS_DB::get( $member_id );
		}

		PCIO_VIS_Meta_DB::set( $member_id, 'unitii_hours', (string) $volunteer['total_hours'] );
		if ( null !== $last_shift ) {
			PCIO_VIS_Meta_DB::set( $member_id, 'unitii_last_shift', $last_shift );
		}

		$this->ensure_volunteer_cap( $member_id, (array) $member, $email, (string) $volunteer['name'] );
		return $member_id;
	}

	/**
	 * Fill in missing member data from Unitii (prod: phone, active-since, volunteer flag).
	 *
	 * @param array<string,mixed> $member    Vis member row.
	 * @param array<string,mixed> $volunteer Staged volunteer row.
	 * @param string|null         $joined    Unitii creation date (local Y-m-d).
	 */
	private function update_member( array $member, array $volunteer, ?string $joined ): void {
		$changes = [];
		if ( '' === (string) $member['phone'] && '' !== (string) $volunteer['phone'] ) {
			$changes['phone'] = (string) $volunteer['phone'];
		}
		if ( empty( $member['is_volunteer'] ) ) {
			$changes['is_volunteer'] = 1;
		}
		if ( null !== $joined && ( empty( $member['join_date'] ) || (string) $member['join_date'] > $joined ) ) {
			$changes['join_date'] = $joined;
		}
		if ( [] === $changes ) {
			return;
		}

		// PCIO_VIS_DB::update() always writes the core columns, so pass them all.
		$data = array_merge(
			[
				'member_number' => (int) $member['member_number'],
				'name'          => (string) $member['name'],
				'email'         => (string) $member['email'],
				'phone'         => (string) $member['phone'],
				'address'       => (string) ( $member['address'] ?? '' ),
			],
			$changes
		);
		if ( false === PCIO_VIS_DB::update( (int) $member['id'], $data ) ) {
			$this->error(
				sprintf(
					/* translators: %s: email address */
					__( 'Could not update Vis member %s.', 'pcio-vis-with-unitii' ),
					(string) $member['email']
				)
			);
			return;
		}
		++$this->count['members_updated'];
	}

	/**
	 * Make sure the member has a WordPress account with the vis_volunteer capability.
	 *
	 * @param int                 $member_id Member id.
	 * @param array<string,mixed> $member    Member row.
	 * @param string              $email     Member email.
	 * @param string              $name      Member name.
	 */
	private function ensure_volunteer_cap( int $member_id, array $member, string $email, string $name ): void {
		$wp_user_id = (int) ( $member['wp_user_id'] ?? 0 );
		if ( $wp_user_id <= 0 ) {
			$role       = '' !== (string) ( $member['vis_role'] ?? '' ) ? (string) $member['vis_role'] : 'volunteer';
			$provision  = PCIO_VIS_Caps::provision_member_wp_account( $member_id, $email, $name, $role );
			$wp_user_id = (int) $provision['wp_user_id'];
			if ( ! empty( $provision['is_new'] ) ) {
				++$this->count['wp_accounts'];
			}
		}
		if ( $wp_user_id <= 0 ) {
			$this->error(
				sprintf(
					/* translators: %s: email address */
					__( 'No WordPress account for %s; vis_volunteer not granted.', 'pcio-vis-with-unitii' ),
					$email
				)
			);
			return;
		}
		if ( ! PCIO_VIS_Caps::is_volunteer( $wp_user_id ) ) {
			PCIO_VIS_Caps::grant_volunteer( $wp_user_id );
			++$this->count['caps_granted'];
		}
	}

	// ── Step 4: shifts → crew ─────────────────────────────────────

	/**
	 * Map every staged volunteer-shift in the period onto a Vis crew row.
	 */
	private function map_shifts( string $start_utc, string $end_utc ): void {
		foreach ( PCIO_VIS_With_Unitii_DB::get_linked_crew_ids() as $crew_id ) {
			$this->linked_crew[ $crew_id ] = true;
		}

		foreach ( PCIO_VIS_With_Unitii_DB::get_period_volunteer_shifts( $start_utc, $end_utc ) as $row ) {
			++$this->count['shifts_analyzed'];
			$this->map_shift( $row );
		}
	}

	/**
	 * Map one volunteer-shift.
	 *
	 * @param array<string,mixed> $row Joined staging row.
	 */
	private function map_shift( array $row ): void {
		$vs_id   = (int) $row['id'];
		$crew_id = (int) $row['crew_id'];

		if ( PCIO_VIS_With_Unitii_DB::STATUS_REMOVE === $row['status'] ) {
			if ( $crew_id > 0 && PCIO_VIS_Event_Crew_DB::delete( $crew_id ) ) {
				++$this->count['crew_deleted'];
			}
			PCIO_VIS_With_Unitii_DB::delete_volunteer_shift( $vs_id );
			++$this->count['staging_deleted'];
			return;
		}

		$member_id = $this->member_by_volunteer[ (int) $row['volunteer_id'] ] ?? 0;
		if ( $member_id <= 0 ) {
			$this->error(
				sprintf(
					/* translators: %s: email address */
					__( 'No Vis volunteer with email %s.', 'pcio-vis-with-unitii' ),
					PCIO_VIS_With_Unitii_Settings::vis_email_for( (string) $row['volunteer_email'] )
				)
			);
			return;
		}

		$start = $this->utc_to_local( $row['start_utc'] );
		$end   = $this->utc_to_local( $row['end_utc'] );
		if ( null === $start || null === $end || substr( $start, 0, 10 ) !== substr( $end, 0, 10 ) || $start > $end ) {
			$this->error(
				sprintf(
					/* translators: %s: Unitii shift id */
					__( 'Unitii shift %s does not start and end on the same day.', 'pcio-vis-with-unitii' ),
					(string) $row['shift_unitii_id']
				)
			);
			return;
		}

		$event_id = $this->find_or_create_event( substr( $start, 0, 10 ) );
		if ( $event_id <= 0 ) {
			return;
		}

		$this->upsert_crew( $vs_id, $crew_id, $event_id, $member_id, $start, $end );
	}

	/**
	 * Create or update the crew row a volunteer-shift maps to.
	 */
	private function upsert_crew( int $vs_id, int $crew_id, int $event_id, int $member_id, string $start, string $end ): void {
		$role_id = PCIO_VIS_With_Unitii_Settings::role_id();
		$crew    = $crew_id > 0 ? PCIO_VIS_Event_Crew_DB::get( $crew_id ) : null;

		// The shift moved to another day: drop the old crew row.
		if ( $crew && (int) $crew['event_id'] !== $event_id ) {
			PCIO_VIS_Event_Crew_DB::delete( $crew_id );
			unset( $this->linked_crew[ $crew_id ] );
			$crew = null;
		}
		if ( ! $crew ) {
			$crew = $this->find_unlinked_crew( $event_id, $member_id, $role_id );
		}

		$wanted = [
			'event_id'       => $event_id,
			'member_id'      => $member_id,
			'role_id'        => $role_id,
			'start_datetime' => $start,
			'end_datetime'   => $end,
		];

		if ( ! $crew ) {
			$new_id = PCIO_VIS_Event_Crew_DB::create( $wanted );
			if ( ! $new_id ) {
				$this->error(
					sprintf(
						/* translators: %d: Vis event id */
						__( 'Could not create crew shift on Vis event %d.', 'pcio-vis-with-unitii' ),
						$event_id
					)
				);
				return;
			}
			$this->link_crew( $vs_id, (int) $new_id, $event_id );
			++$this->count['crew_created'];
			return;
		}

		if ( (int) $crew['id'] !== $crew_id ) {
			$this->link_crew( $vs_id, (int) $crew['id'], $event_id );
		}

		$changes = [];
		foreach ( [ 'member_id', 'role_id' ] as $key ) {
			if ( (int) $crew[ $key ] !== $wanted[ $key ] ) {
				$changes[ $key ] = $wanted[ $key ];
			}
		}
		foreach ( [ 'start_datetime', 'end_datetime' ] as $key ) {
			if ( substr( (string) $crew[ $key ], 0, 16 ) !== substr( $wanted[ $key ], 0, 16 ) ) {
				$changes[ $key ] = $wanted[ $key ];
			}
		}
		if ( [] === $changes ) {
			++$this->count['crew_ok'];
			return;
		}
		PCIO_VIS_Event_Crew_DB::update( (int) $crew['id'], $changes );
		++$this->count['crew_updated'];
	}

	/**
	 * Existing crew row for the member/role on the event that no other
	 * volunteer-shift owns (e.g. entered by hand before the first sync).
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_unlinked_crew( int $event_id, int $member_id, int $role_id ): ?array {
		if ( ! isset( $this->crew_by_event[ $event_id ] ) ) {
			$this->crew_by_event[ $event_id ] = PCIO_VIS_Event_Crew_DB::get_for_event( $event_id );
		}
		foreach ( $this->crew_by_event[ $event_id ] as $crew ) {
			if ( (int) $crew['member_id'] === $member_id
				&& (int) $crew['role_id'] === $role_id
				&& ! isset( $this->linked_crew[ (int) $crew['id'] ] ) ) {
				return $crew;
			}
		}
		return null;
	}

	/**
	 * Remember that a volunteer-shift owns a crew row.
	 */
	private function link_crew( int $vs_id, int $crew_id, int $event_id ): void {
		PCIO_VIS_With_Unitii_DB::set_crew_id( $vs_id, $crew_id );
		$this->linked_crew[ $crew_id ] = true;
		unset( $this->crew_by_event[ $event_id ] );
	}

	// ── Vis events ────────────────────────────────────────────────

	/**
	 * Vis event of the configured type that starts on the given day;
	 * created from the template when none exists.
	 *
	 * @param string $day Local date, Y-m-d.
	 * @return int Event id, or 0 on failure.
	 */
	private function find_or_create_event( string $day ): int {
		if ( isset( $this->event_by_day[ $day ] ) ) {
			return $this->event_by_day[ $day ];
		}

		$type_id   = PCIO_VIS_With_Unitii_Settings::event_type_id();
		$day_start = $day . ' 00:00:00';
		$day_end   = ( new DateTimeImmutable( $day, $this->tz ) )->modify( '+1 day' )->format( 'Y-m-d 00:00:00' );

		foreach ( PCIO_VIS_Events_DB::get_range( $day_start, $day_end ) as $event ) {
			if ( (int) $event['event_type_id'] === $type_id && substr( (string) $event['start_datetime'], 0, 10 ) === $day ) {
				++$this->count['vis_events_found'];
				$this->event_by_day[ $day ] = (int) $event['id'];
				return $this->event_by_day[ $day ];
			}
		}

		$event_id = $this->create_event_from_template( $day );
		if ( $event_id > 0 ) {
			$this->event_by_day[ $day ] = $event_id;
		}
		return $event_id;
	}

	/**
	 * Clone the template event onto a new day. Title, description, resume,
	 * homepage flag and event type are copied; start, end, get-in and door
	 * opening keep their time of day and their day offset from the template
	 * start (e.g. an end after midnight or a get-in the evening before).
	 *
	 * @param string $day Local date, Y-m-d.
	 * @return int New event id, or 0 on failure.
	 */
	private function create_event_from_template( string $day ): int {
		$template = (array) $this->template;
		$data     = [
			'title'            => (string) $template['title'],
			'description'      => (string) ( $template['description'] ?? '' ),
			'resume'           => (string) ( $template['resume'] ?? '' ),
			'publish_homepage' => (int) ( $template['publish_homepage'] ?? 0 ),
			'event_type_id'    => PCIO_VIS_With_Unitii_Settings::event_type_id(),
		];

		$template_day = new DateTimeImmutable( substr( (string) $template['start_datetime'], 0, 10 ), $this->tz );
		$new_day      = new DateTimeImmutable( $day, $this->tz );
		foreach ( self::TEMPLATE_TIME_FIELDS as $key ) {
			$value = $template[ $key ] ?? null;
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$moved = $this->move_to_day( $value, $template_day, $new_day );
			if ( null !== $moved ) {
				$data[ $key ] = $moved;
			}
		}

		$event_id = (int) PCIO_VIS_Events_DB::create( $data );
		if ( $event_id <= 0 ) {
			$this->error(
				sprintf(
					/* translators: %s: date */
					__( 'Could not create a Vis event from the template on %s.', 'pcio-vis-with-unitii' ),
					$day
				)
			);
			return 0;
		}
		++$this->count['vis_events_created'];
		$this->log(
			sprintf(
				/* translators: 1: event id, 2: event title, 3: start date and time */
				__( 'Created Vis event %1$d "%2$s" at %3$s from the template.', 'pcio-vis-with-unitii' ),
				$event_id,
				$data['title'],
				(string) ( $data['start_datetime'] ?? $day )
			)
		);
		return $event_id;
	}

	/**
	 * Re-date a template datetime onto a new day, keeping the time of day and
	 * the day offset relative to the template start (e.g. an end after midnight).
	 */
	private function move_to_day( string $value, DateTimeImmutable $template_day, DateTimeImmutable $new_day ): ?string {
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', substr( $value, 0, 19 ), $this->tz );
		if ( false === $dt ) {
			return null;
		}
		$offset = (int) $template_day->diff( new DateTimeImmutable( $dt->format( 'Y-m-d' ), $this->tz ) )->format( '%r%a' );
		return $new_day->modify( sprintf( '%+d days', $offset ) )->format( 'Y-m-d' ) . ' ' . $dt->format( 'H:i:s' );
	}

	// ── Step 5: workgroup clean-up ────────────────────────────────

	/**
	 * Remove workgroup members that do not map to a current Unitii volunteer.
	 */
	private function remove_obsolete_workgroup_members(): void {
		$workgroup_id = PCIO_VIS_With_Unitii_Settings::workgroup_id();
		$workgroup    = PCIO_VIS_Workgroups_DB::get( $workgroup_id );
		$keep         = array_flip( $this->member_by_volunteer );

		foreach ( (array) ( $workgroup['members'] ?? [] ) as $member ) {
			if ( isset( $keep[ (int) $member['id'] ] ) ) {
				continue;
			}
			PCIO_VIS_Workgroups_DB::remove_member( $workgroup_id, (int) $member['id'] );
			++$this->count['wg_removed'];
			$this->log(
				sprintf(
					/* translators: 1: member name, 2: email address */
					__( 'Removed %1$s <%2$s> from the workgroup.', 'pcio-vis-with-unitii' ),
					(string) $member['name'],
					(string) $member['email']
				)
			);
		}
	}

	// ── Time helpers ──────────────────────────────────────────────

	/**
	 * Unix milliseconds → UTC MySQL datetime, or null when not numeric.
	 *
	 * @param mixed $ms Milliseconds.
	 */
	private function ms_to_utc( $ms ): ?string {
		if ( ! is_numeric( $ms ) ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', (int) floor( (float) $ms / 1000 ) );
	}

	/**
	 * UTC MySQL datetime → site-local MySQL datetime.
	 *
	 * @param mixed $utc UTC datetime string.
	 */
	private function utc_to_local( $utc ): ?string {
		if ( ! is_string( $utc ) || '' === $utc ) {
			return null;
		}
		try {
			$dt = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}
		return $dt->setTimezone( $this->tz )->format( 'Y-m-d H:i:s' );
	}

	private function to_utc_string( DateTimeImmutable $dt ): string {
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	// ── Logging / result ──────────────────────────────────────────

	private function log( string $line ): void {
		$this->log[] = $line;
	}

	private function error( string $line ): void {
		++$this->count['errors'];
		$this->log[] = __( 'ERROR:', 'pcio-vis-with-unitii' ) . ' ' . $line;
	}

	/**
	 * Build the summary, store the full log for the settings page and return
	 * a size-capped version for the sync job result.
	 *
	 * @param string $fatal Fatal error message, '' when the run completed.
	 */
	private function finish( string $fatal ): string {
		if ( '' !== $fatal ) {
			$this->error( $fatal );
		}
		$c       = $this->count;
		$summary = [
			sprintf(
				/* translators: 1: API items, 2: new volunteers, 3: new events, 4: new shifts, 5: new volunteer-shifts, 6: deleted volunteers */
				__( 'Unitii: %1$d items, %2$d new volunteers, %3$d new events, %4$d new shifts, %5$d new volunteer-shifts, %6$d volunteers deleted.', 'pcio-vis-with-unitii' ),
				$c['items'],
				$c['new_volunteers'],
				$c['new_events'],
				$c['new_shifts'],
				$c['new_volunteer_shifts'],
				$c['deleted_volunteers']
			),
			sprintf(
				/* translators: 1: created, 2: updated, 3: skipped (no work), 4: new WP accounts, 5: capabilities granted, 6: added to workgroup, 7: removed from workgroup */
				__( 'Members: %1$d created, %2$d updated, %3$d skipped (no work done), %4$d WP accounts, %5$d vis_volunteer granted, workgroup +%6$d / -%7$d.', 'pcio-vis-with-unitii' ),
				$c['members_created'],
				$c['members_updated'],
				$c['members_skipped'],
				$c['wp_accounts'],
				$c['caps_granted'],
				$c['wg_added'],
				$c['wg_removed']
			),
			sprintf(
				/* translators: 1: analysed shifts, 2: events found, 3: events created, 4: crew created, 5: crew updated, 6: crew unchanged, 7: crew deleted */
				__( 'Shifts: %1$d analysed, events %2$d found / %3$d created, crew %4$d created / %5$d updated / %6$d OK / %7$d deleted.', 'pcio-vis-with-unitii' ),
				$c['shifts_analyzed'],
				$c['vis_events_found'],
				$c['vis_events_created'],
				$c['crew_created'],
				$c['crew_updated'],
				$c['crew_ok'],
				$c['crew_deleted']
			),
			sprintf(
				/* translators: %d: number of errors */
				__( 'Errors: %d', 'pcio-vis-with-unitii' ),
				$c['errors']
			),
		];

		update_option(
			PCIO_VIS_With_Unitii_Settings::OPT_LAST_LOG,
			[
				'time'    => current_time( 'mysql' ),
				'summary' => $summary,
				'log'     => $this->log,
			],
			false
		);

		$lines = array_merge( $summary, array_slice( $this->log, 0, self::MAX_RESULT_LINES ) );
		if ( count( $this->log ) > self::MAX_RESULT_LINES ) {
			$lines[] = __( '… (see the Unitii settings page for the full log)', 'pcio-vis-with-unitii' );
		}
		return implode( "\n", $lines );
	}
}
