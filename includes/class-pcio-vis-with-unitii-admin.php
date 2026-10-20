<?php
/**
 * WP Admin → Vis → Unitii settings page.
 *
 * @package PCIO_VIS_With_Unitii
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the Unitii settings page.
 */
class PCIO_VIS_With_Unitii_Admin {

	const PAGE_SLUG    = 'pcio-me-unitii';
	const NONCE_ACTION = 'pcio_vis_unitii_save';
	const NONCE_FIELD  = 'pcio_vis_unitii_nonce';

	/**
	 * Register the submenu under the Vis menu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'pcio-me-settings',
			__( 'Unitii', 'pcio-vis-with-unitii' ),
			__( 'Unitii', 'pcio-vis-with-unitii' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Render the settings page and handle its form posts.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-with-unitii' ) );
		}

		$notice = self::handle_post();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Vis — Unitii Settings', 'pcio-vis-with-unitii' ); ?></h1>
			<?php echo wp_kses_post( $notice ); ?>
			<p class="description">
				<?php esc_html_e( 'The Unitii sync cron job imports volunteers, events and shifts from Unitii: volunteers become members of the selected workgroup, and shifts are added to the crew list of the matching Vis event.', 'pcio-vis-with-unitii' ); ?>
			</p>
			<?php
			self::render_settings_form();
			self::render_email_map();
			self::render_status();
			?>
		</div>
		<?php
	}

	// ── POST handling ─────────────────────────────────────────────

	/**
	 * Process a submitted form. Returns notice HTML (already escaped).
	 */
	private static function handle_post(): string {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return '';
		}

		if ( isset( $_POST['pcio_vis_unitii_save'] ) ) {
			return self::save_settings();
		}
		if ( isset( $_POST['pcio_vis_unitii_map_add'] ) ) {
			return self::add_mapping();
		}
		if ( isset( $_POST['pcio_vis_unitii_map_remove'] ) ) {
			$unitii_email = sanitize_email( wp_unslash( $_POST['pcio_vis_unitii_map_remove'] ) );
			PCIO_VIS_With_Unitii_Settings::remove_email_mapping( $unitii_email );
			return self::notice( 'success', __( 'Email mapping removed.', 'pcio-vis-with-unitii' ) );
		}
		return '';
	}

	/**
	 * Validate and store the main settings.
	 */
	private static function save_settings(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle_post().
		$values = [
			'api_url'           => esc_url_raw( wp_unslash( $_POST['unitii_api_url'] ?? '' ) ),
			'username'          => sanitize_text_field( wp_unslash( $_POST['unitii_username'] ?? '' ) ),
			// Passwords may contain any character; only strip slashes and control characters.
			'password'          => trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', wp_unslash( $_POST['unitii_password'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'event_type_id'     => absint( $_POST['unitii_event_type_id'] ?? 0 ),
			'role_id'           => absint( $_POST['unitii_role_id'] ?? 0 ),
			'workgroup_id'      => absint( $_POST['unitii_workgroup_id'] ?? 0 ),
			'template_event_id' => absint( $_POST['unitii_template_event_id'] ?? 0 ),
		];
		// phpcs:enable

		$errors = self::validate( $values );
		if ( [] !== $errors ) {
			$list = '';
			foreach ( $errors as $error ) {
				$list .= '<li>' . esc_html( $error ) . '</li>';
			}
			return '<div class="notice notice-error"><p><strong>'
				. esc_html__( 'Unitii settings were not saved — please fix the following:', 'pcio-vis-with-unitii' )
				. '</strong></p><ul class="ul-disc">' . $list . '</ul></div>';
		}

		update_option( PCIO_VIS_With_Unitii_Settings::OPT_API_URL, $values['api_url'] );
		update_option( PCIO_VIS_With_Unitii_Settings::OPT_USERNAME, $values['username'] );
		if ( '' !== $values['password'] ) {
			update_option( PCIO_VIS_With_Unitii_Settings::OPT_PASSWORD, $values['password'], false );
		}
		update_option( PCIO_VIS_With_Unitii_Settings::OPT_EVENT_TYPE_ID, $values['event_type_id'] );
		update_option( PCIO_VIS_With_Unitii_Settings::OPT_ROLE_ID, $values['role_id'] );
		update_option( PCIO_VIS_With_Unitii_Settings::OPT_WORKGROUP_ID, $values['workgroup_id'] );
		update_option( PCIO_VIS_With_Unitii_Settings::OPT_TEMPLATE_EVENT_ID, $values['template_event_id'] );

		return self::notice( 'success', __( 'Unitii settings saved.', 'pcio-vis-with-unitii' ) )
			. self::template_preview( $values['template_event_id'] );
	}

	/**
	 * Validate submitted settings.
	 *
	 * @param array<string,mixed> $values Sanitized form values.
	 * @return string[] Error messages; empty when valid.
	 */
	private static function validate( array $values ): array {
		$errors = [];
		if ( '' !== $values['api_url'] && ! wp_http_validate_url( $values['api_url'] ) ) {
			$errors[] = __( 'The API URL is not a valid http(s) URL.', 'pcio-vis-with-unitii' );
		}
		if ( '' === $values['username'] ) {
			$errors[] = __( 'The Unitii username is required.', 'pcio-vis-with-unitii' );
		}
		if ( '' === $values['password'] && '' === PCIO_VIS_With_Unitii_Settings::password() ) {
			$errors[] = __( 'The Unitii password is required.', 'pcio-vis-with-unitii' );
		}
		if ( ! PCIO_VIS_Event_Types_DB::get( $values['event_type_id'] ) ) {
			$errors[] = __( 'Select a Vis event type.', 'pcio-vis-with-unitii' );
		}
		if ( ! PCIO_VIS_Event_Roles_DB::get( $values['role_id'] ) ) {
			$errors[] = __( 'Select a Vis event crew role.', 'pcio-vis-with-unitii' );
		}
		if ( ! PCIO_VIS_Workgroups_DB::get( $values['workgroup_id'] ) ) {
			$errors[] = __( 'Select a Vis workgroup.', 'pcio-vis-with-unitii' );
		}

		$template = PCIO_VIS_Events_DB::get( $values['template_event_id'] );
		if ( ! $template ) {
			$errors[] = __( 'The template event ID does not match an existing Vis event.', 'pcio-vis-with-unitii' );
		} elseif ( (int) $template['event_type_id'] !== $values['event_type_id'] ) {
			$errors[] = __( 'The template event must have the selected Vis event type.', 'pcio-vis-with-unitii' );
		}
		return $errors;
	}

	/**
	 * Add one email exception from the posted form.
	 */
	private static function add_mapping(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle_post().
		$unitii_email = sanitize_email( wp_unslash( $_POST['unitii_map_unitii'] ?? '' ) );
		$vis_email    = sanitize_email( wp_unslash( $_POST['unitii_map_vis'] ?? '' ) );
		// phpcs:enable

		if ( ! is_email( $unitii_email ) || ! is_email( $vis_email ) ) {
			return self::notice( 'error', __( 'Enter two valid email addresses.', 'pcio-vis-with-unitii' ) );
		}
		if ( strtolower( $unitii_email ) === strtolower( $vis_email ) ) {
			return self::notice( 'error', __( 'The two email addresses are the same; no mapping is needed.', 'pcio-vis-with-unitii' ) );
		}

		PCIO_VIS_With_Unitii_Settings::add_email_mapping( $unitii_email, $vis_email );
		$notice = self::notice( 'success', __( 'Email mapping saved.', 'pcio-vis-with-unitii' ) );
		if ( PCIO_VIS_DB::find_id_by_email( $vis_email ) <= 0 ) {
			$notice .= self::notice(
				'warning',
				sprintf(
					/* translators: %s: email address */
					__( 'No Vis member has the email %s yet.', 'pcio-vis-with-unitii' ),
					$vis_email
				)
			);
		}
		return $notice;
	}

	// ── Rendering ─────────────────────────────────────────────────

	/**
	 * Main settings form.
	 */
	private static function render_settings_form(): void {
		$has_password = '' !== PCIO_VIS_With_Unitii_Settings::password();
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<h2><?php esc_html_e( 'Connection', 'pcio-vis-with-unitii' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="unitii_api_url"><?php esc_html_e( 'API URL', 'pcio-vis-with-unitii' ); ?></label></th>
					<td>
						<input name="unitii_api_url" id="unitii_api_url" type="url" class="large-text"
							value="<?php echo esc_attr( PCIO_VIS_With_Unitii_Settings::api_url() ); ?>">
						<p class="description">
							<?php
							printf(
								/* translators: %s: default API URL */
								esc_html__( 'The Unitii GraphQL endpoint. Default: %s', 'pcio-vis-with-unitii' ),
								'<code>' . esc_html( PCIO_VIS_With_Unitii_Settings::DEFAULT_API_URL ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="unitii_username"><?php esc_html_e( 'Username', 'pcio-vis-with-unitii' ); ?></label></th>
					<td>
						<input name="unitii_username" id="unitii_username" type="text" class="regular-text" autocomplete="off"
							value="<?php echo esc_attr( PCIO_VIS_With_Unitii_Settings::username() ); ?>">
						<p class="description"><?php esc_html_e( 'The email address used to log in to Unitii.', 'pcio-vis-with-unitii' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="unitii_password"><?php esc_html_e( 'Password', 'pcio-vis-with-unitii' ); ?></label></th>
					<td>
						<input name="unitii_password" id="unitii_password" type="password" class="regular-text" autocomplete="new-password" value="">
						<p class="description">
							<?php
							if ( $has_password ) {
								esc_html_e( 'A password is saved. Leave empty to keep it.', 'pcio-vis-with-unitii' );
							} else {
								esc_html_e( 'No password saved yet.', 'pcio-vis-with-unitii' );
							}
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Vis mapping', 'pcio-vis-with-unitii' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="unitii_event_type_id"><?php esc_html_e( 'Vis event type', 'pcio-vis-with-unitii' ); ?></label></th>
					<td>
						<?php self::render_select( 'unitii_event_type_id', PCIO_VIS_Event_Types_DB::get_all(), PCIO_VIS_With_Unitii_Settings::event_type_id() ); ?>
						<p class="description"><?php esc_html_e( 'Unitii shifts are added to the Vis event of this type on the same day.', 'pcio-vis-with-unitii' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="unitii_role_id"><?php esc_html_e( 'Vis event crew role', 'pcio-vis-with-unitii' ); ?></label></th>
					<td>
						<?php self::render_select( 'unitii_role_id', PCIO_VIS_Event_Roles_DB::get_all(), PCIO_VIS_With_Unitii_Settings::role_id() ); ?>
						<p class="description"><?php esc_html_e( 'The crew role given to the shifts imported from Unitii.', 'pcio-vis-with-unitii' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="unitii_workgroup_id"><?php esc_html_e( 'Vis workgroup', 'pcio-vis-with-unitii' ); ?></label></th>
					<td>
						<?php self::render_select( 'unitii_workgroup_id', PCIO_VIS_Workgroups_DB::get_all(), PCIO_VIS_With_Unitii_Settings::workgroup_id() ); ?>
						<p class="description"><?php esc_html_e( 'All Unitii volunteers are mapped 1:1 to this workgroup and given the vis_volunteer capability.', 'pcio-vis-with-unitii' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="unitii_template_event_id"><?php esc_html_e( 'Template event ID', 'pcio-vis-with-unitii' ); ?></label></th>
					<td>
						<input name="unitii_template_event_id" id="unitii_template_event_id" type="number" min="1" class="small-text"
							value="<?php echo esc_attr( (string) ( PCIO_VIS_With_Unitii_Settings::template_event_id() ?: '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'When no Vis event of the selected type exists on a Unitii event day, a new event is created as a copy of this event (title, description, resume, homepage setting, event type and times).', 'pcio-vis-with-unitii' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'pcio-vis-with-unitii' ), 'primary', 'pcio_vis_unitii_save' ); ?>
		</form>
		<?php
	}

	/**
	 * Dropdown of id/name rows.
	 *
	 * @param string                         $name     Field name and id.
	 * @param array<int,array<string,mixed>> $rows     Rows with id and name.
	 * @param int                            $selected Selected id.
	 */
	private static function render_select( string $name, array $rows, int $selected ): void {
		?>
		<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>">
			<option value="0"><?php esc_html_e( '— Select —', 'pcio-vis-with-unitii' ); ?></option>
			<?php foreach ( $rows as $row ) : ?>
				<option value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>" <?php selected( (int) $row['id'], $selected ); ?>>
					<?php echo esc_html( (string) $row['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Notice with the template event's title and times.
	 */
	private static function template_preview( int $event_id ): string {
		$event = PCIO_VIS_Events_DB::get( $event_id );
		if ( ! $event ) {
			return '';
		}
		$type = PCIO_VIS_Event_Types_DB::get( (int) $event['event_type_id'] );
		$rows = [
			__( 'Title', 'pcio-vis-with-unitii' )      => (string) $event['title'],
			__( 'Event type', 'pcio-vis-with-unitii' ) => $type ? (string) $type['name'] : '',
			__( 'Start', 'pcio-vis-with-unitii' )      => self::format_time( (string) $event['start_datetime'] ),
			__( 'End', 'pcio-vis-with-unitii' )        => self::format_time( (string) ( $event['end_datetime'] ?? '' ) ),
			__( 'Get-in time', 'pcio-vis-with-unitii' ) => self::format_time( (string) ( $event['get_in_datetime'] ?? '' ) ),
			__( 'Door opening', 'pcio-vis-with-unitii' ) => self::format_time( (string) ( $event['door_opening_datetime'] ?? '' ) ),
		];
		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th style="text-align:left;padding-right:12px;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		return '<div class="notice notice-info"><p><strong>'
			. esc_html(
				sprintf(
					/* translators: %d: event id */
					__( 'Template event %d', 'pcio-vis-with-unitii' ),
					$event_id
				)
			)
			. '</strong></p><table><tbody>' . $html . '</tbody></table></div>';
	}

	/**
	 * Format a site-local MySQL datetime for display.
	 */
	private static function format_time( string $datetime ): string {
		if ( '' === $datetime ) {
			return '—';
		}
		$dt = date_create_immutable( $datetime, wp_timezone() );
		if ( false === $dt ) {
			return $datetime;
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp() );
	}

	/**
	 * Email exception table with add/remove.
	 */
	private static function render_email_map(): void {
		$map = PCIO_VIS_With_Unitii_Settings::email_map();
		?>
		<h2><?php esc_html_e( 'Email mapping', 'pcio-vis-with-unitii' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Unitii volunteers are matched to Vis members by email address. Add an exception here when a person uses a different email address in Unitii and in Vis.', 'pcio-vis-with-unitii' ); ?>
		</p>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Unitii email', 'pcio-vis-with-unitii' ); ?></th>
						<th><?php esc_html_e( 'Vis member email', 'pcio-vis-with-unitii' ); ?></th>
						<th><?php esc_html_e( 'Vis member', 'pcio-vis-with-unitii' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( [] === $map ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No exceptions.', 'pcio-vis-with-unitii' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $map as $unitii_email => $vis_email ) : ?>
						<?php
						$member_id = PCIO_VIS_DB::find_id_by_email( (string) $vis_email );
						$member    = $member_id > 0 ? PCIO_VIS_DB::get( $member_id ) : null;
						?>
						<tr>
							<td><?php echo esc_html( (string) $unitii_email ); ?></td>
							<td><?php echo esc_html( (string) $vis_email ); ?></td>
							<td>
								<?php
								if ( $member ) {
									echo esc_html( (string) $member['name'] );
								} else {
									echo '<em>' . esc_html__( 'Not found', 'pcio-vis-with-unitii' ) . '</em>';
								}
								?>
							</td>
							<td>
								<button type="submit" class="button-link-delete" name="pcio_vis_unitii_map_remove"
									value="<?php echo esc_attr( (string) $unitii_email ); ?>">
									<?php esc_html_e( 'Remove', 'pcio-vis-with-unitii' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<td>
							<label class="screen-reader-text" for="unitii_map_unitii"><?php esc_html_e( 'Unitii email', 'pcio-vis-with-unitii' ); ?></label>
							<input type="email" name="unitii_map_unitii" id="unitii_map_unitii" class="regular-text"
								placeholder="<?php esc_attr_e( 'Unitii email', 'pcio-vis-with-unitii' ); ?>">
						</td>
						<td>
							<label class="screen-reader-text" for="unitii_map_vis"><?php esc_html_e( 'Vis member email', 'pcio-vis-with-unitii' ); ?></label>
							<input type="email" name="unitii_map_vis" id="unitii_map_vis" class="regular-text"
								placeholder="<?php esc_attr_e( 'Vis member email', 'pcio-vis-with-unitii' ); ?>">
						</td>
						<td></td>
						<td>
							<button type="submit" class="button" name="pcio_vis_unitii_map_add" value="1">
								<?php esc_html_e( 'Add', 'pcio-vis-with-unitii' ); ?>
							</button>
						</td>
					</tr>
				</tfoot>
			</table>
		</form>
		<?php
	}

	/**
	 * Sync status: staging counts and the last run log.
	 */
	private static function render_status(): void {
		$counts  = PCIO_VIS_With_Unitii_DB::counts();
		$missing = PCIO_VIS_With_Unitii_Settings::missing();
		$last    = get_option( PCIO_VIS_With_Unitii_Settings::OPT_LAST_LOG, [] );
		?>
		<h2><?php esc_html_e( 'Sync status', 'pcio-vis-with-unitii' ); ?></h2>
		<?php if ( [] !== $missing ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of missing settings */
					esc_html__( 'Not configured yet. Missing: %s.', 'pcio-vis-with-unitii' ),
					esc_html( implode( ', ', $missing ) )
				);
				?>
			</p>
		<?php endif; ?>
		<p>
			<?php
			printf(
				/* translators: 1: volunteers, 2: events, 3: shifts, 4: volunteer shifts */
				esc_html__( 'Staged from Unitii: %1$d volunteers, %2$d events, %3$d shifts, %4$d volunteer shifts.', 'pcio-vis-with-unitii' ),
				(int) ( $counts['volunteers'] ?? 0 ),
				(int) ( $counts['events'] ?? 0 ),
				(int) ( $counts['shifts'] ?? 0 ),
				(int) ( $counts['volunteer_shifts'] ?? 0 )
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'The sync runs as the "Unitii sync" job. Enable it, set its frequency or run it now on the Vis Sync page.', 'pcio-vis-with-unitii' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pcio-me-sync' ) ); ?>"><?php esc_html_e( 'Open Vis Sync', 'pcio-vis-with-unitii' ); ?></a>
		</p>
		<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?>
			<h3>
				<?php
				printf(
					/* translators: %s: date and time of the last run */
					esc_html__( 'Last run: %s', 'pcio-vis-with-unitii' ),
					esc_html( (string) $last['time'] )
				);
				?>
			</h3>
			<textarea class="large-text code" rows="16" readonly><?php echo esc_textarea( implode( "\n", array_merge( (array) ( $last['summary'] ?? [] ), [ '' ], (array) ( $last['log'] ?? [] ) ) ) ); ?></textarea>
		<?php endif; ?>
		<?php
	}

	/**
	 * Escaped notice HTML.
	 */
	private static function notice( string $type, string $message ): string {
		return '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}
}
