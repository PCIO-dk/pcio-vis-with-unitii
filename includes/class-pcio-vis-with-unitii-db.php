<?php
/**
 * Local staging copy of the Unitii volunteers, events and shifts.
 *
 * @package PCIO_VIS_With_Unitii
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own staging tables; values change on every sync run.

/**
 * Four staging tables mirror the Unitii data between sync runs:
 *
 * me_unitii_volunteers       — one row per Unitii volunteer.
 * me_unitii_events           — one row per Unitii event.
 * me_unitii_shifts           — one row per Unitii shift (times stored in UTC).
 * me_unitii_volunteer_shifts — volunteer ↔ shift link with a sync status and
 *                              the Vis crew row it was mapped to.
 */
class PCIO_VIS_With_Unitii_DB {

	const DB_VERSION     = '1.0.0';
	const OPT_DB_VERSION = 'pcio_vis_unitii_db_version';

	const STATUS_NEW    = 'new';
	const STATUS_FOUND  = 'found';
	const STATUS_REMOVE = 'remove';

	/**
	 * Full table name for a staging table.
	 *
	 * @param string $name One of volunteers|events|shifts|volunteer_shifts.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'me_unitii_' . $name;
	}

	// ── Schema ────────────────────────────────────────────────────

	/**
	 * Create the staging tables on activation / version bump.
	 *
	 * Uses prepared CREATE TABLE with the %i identifier placeholder instead of
	 * dbDelta(), which cannot take prepared SQL. The charset is hardcoded
	 * because it cannot be a placeholder.
	 */
	public static function install(): void {
		if ( get_option( self::OPT_DB_VERSION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"CREATE TABLE IF NOT EXISTS %i (
					id           INT           NOT NULL AUTO_INCREMENT,
					unitii_id    VARCHAR(64)   NOT NULL DEFAULT '',
					name         VARCHAR(200)  NOT NULL DEFAULT '',
					email        VARCHAR(190)  NOT NULL DEFAULT '',
					phone        VARCHAR(40)   NOT NULL DEFAULT '',
					total_hours  DECIMAL(10,2) NOT NULL DEFAULT 0,
					deleted      TINYINT(1)    NOT NULL DEFAULT 0,
					user_type    VARCHAR(40)   NOT NULL DEFAULT '',
					created      DATETIME               DEFAULT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY unitii_id (unitii_id),
					KEY email (email)
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
				self::table( 'volunteers' )
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"CREATE TABLE IF NOT EXISTS %i (
					id           INT          NOT NULL AUTO_INCREMENT,
					unitii_id    VARCHAR(64)  NOT NULL DEFAULT '',
					title        VARCHAR(255) NOT NULL DEFAULT '',
					start_utc    DATETIME              DEFAULT NULL,
					end_utc      DATETIME              DEFAULT NULL,
					description  TEXT,
					PRIMARY KEY  (id),
					UNIQUE KEY unitii_id (unitii_id)
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
				self::table( 'events' )
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"CREATE TABLE IF NOT EXISTS %i (
					id           INT          NOT NULL AUTO_INCREMENT,
					unitii_id    VARCHAR(64)  NOT NULL DEFAULT '',
					event_id     INT          NOT NULL DEFAULT 0,
					start_utc    DATETIME              DEFAULT NULL,
					end_utc      DATETIME              DEFAULT NULL,
					location     VARCHAR(255) NOT NULL DEFAULT '',
					PRIMARY KEY  (id),
					UNIQUE KEY unitii_id (unitii_id),
					KEY start_utc (start_utc)
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
				self::table( 'shifts' )
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"CREATE TABLE IF NOT EXISTS %i (
					id           INT          NOT NULL AUTO_INCREMENT,
					volunteer_id INT          NOT NULL DEFAULT 0,
					shift_id     INT          NOT NULL DEFAULT 0,
					status       VARCHAR(10)  NOT NULL DEFAULT 'new',
					crew_id      INT          NOT NULL DEFAULT 0,
					PRIMARY KEY  (id),
					UNIQUE KEY volunteer_shift (volunteer_id, shift_id),
					KEY shift_id (shift_id)
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
				self::table( 'volunteer_shifts' )
			)
		);

		update_option( self::OPT_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * Drop all staging tables. Used by uninstall.php.
	 */
	public static function drop_tables(): void {
		global $wpdb;
		foreach ( [ 'volunteer_shifts', 'shifts', 'events', 'volunteers' ] as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name ) ) );
		}
		delete_option( self::OPT_DB_VERSION );
	}

	// ── Upserts (keyed on the Unitii id) ──────────────────────────

	/**
	 * Insert or update a staged row keyed on its Unitii id.
	 *
	 * @param string              $name      Staging table short name.
	 * @param string              $unitii_id Unitii id.
	 * @param array<string,mixed> $data      Column values (excluding id/unitii_id).
	 * @return array{id:int,is_new:bool}
	 */
	private static function upsert( string $name, string $unitii_id, array $data ): array {
		global $wpdb;
		$table = self::table( $name );
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE unitii_id = %s', $table, $unitii_id )
		);
		if ( $id > 0 ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
			return [ 'id' => $id, 'is_new' => false ];
		}
		$wpdb->insert( $table, array_merge( $data, [ 'unitii_id' => $unitii_id ] ) );
		return [ 'id' => (int) $wpdb->insert_id, 'is_new' => true ];
	}

	/**
	 * Insert or update a staged volunteer.
	 *
	 * @param string              $unitii_id Unitii volunteer id.
	 * @param array<string,mixed> $data      Volunteer columns.
	 * @return array{id:int,is_new:bool}
	 */
	public static function upsert_volunteer( string $unitii_id, array $data ): array {
		return self::upsert( 'volunteers', $unitii_id, $data );
	}

	/**
	 * Insert or update a staged event.
	 *
	 * @param string              $unitii_id Unitii event id.
	 * @param array<string,mixed> $data      Event columns.
	 * @return array{id:int,is_new:bool}
	 */
	public static function upsert_event( string $unitii_id, array $data ): array {
		return self::upsert( 'events', $unitii_id, $data );
	}

	/**
	 * Insert or update a staged shift.
	 *
	 * @param string              $unitii_id Unitii shift id.
	 * @param array<string,mixed> $data      Shift columns.
	 * @return array{id:int,is_new:bool}
	 */
	public static function upsert_shift( string $unitii_id, array $data ): array {
		return self::upsert( 'shifts', $unitii_id, $data );
	}

	/**
	 * Link a volunteer to a shift. Existing links are marked "found",
	 * new links are created with status "new".
	 *
	 * @return bool True when the link is new.
	 */
	public static function touch_volunteer_shift( int $volunteer_id, int $shift_id ): bool {
		global $wpdb;
		$table = self::table( 'volunteer_shifts' );
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE volunteer_id = %d AND shift_id = %d',
				$table,
				$volunteer_id,
				$shift_id
			)
		);
		if ( $id > 0 ) {
			$wpdb->update( $table, [ 'status' => self::STATUS_FOUND ], [ 'id' => $id ] );
			return false;
		}
		$wpdb->insert(
			$table,
			[
				'volunteer_id' => $volunteer_id,
				'shift_id'     => $shift_id,
				'status'       => self::STATUS_NEW,
			]
		);
		return true;
	}

	// ── Volunteer shifts in a period ──────────────────────────────

	/**
	 * Mark every volunteer-shift inside the period as "remove". The rows that
	 * are still present in Unitii are flipped back by touch_volunteer_shift().
	 */
	public static function mark_period_for_removal( string $start_utc, string $end_utc ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i vs
				 INNER JOIN %i s ON vs.shift_id = s.id
				 SET vs.status = %s
				 WHERE s.start_utc >= %s AND s.end_utc <= %s',
				self::table( 'volunteer_shifts' ),
				self::table( 'shifts' ),
				self::STATUS_REMOVE,
				$start_utc,
				$end_utc
			)
		);
	}

	/**
	 * All volunteer-shifts in the period, joined with volunteer and shift data.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_period_volunteer_shifts( string $start_utc, string $end_utc ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT vs.id, vs.status, vs.crew_id, vs.shift_id,
				        v.id AS volunteer_id, v.name AS volunteer_name, v.email AS volunteer_email,
				        s.unitii_id AS shift_unitii_id, s.start_utc, s.end_utc
				 FROM %i vs
				 LEFT JOIN %i v ON vs.volunteer_id = v.id
				 INNER JOIN %i s ON vs.shift_id = s.id
				 WHERE s.start_utc >= %s AND s.end_utc <= %s
				 ORDER BY s.start_utc ASC, vs.id ASC',
				self::table( 'volunteer_shifts' ),
				self::table( 'volunteers' ),
				self::table( 'shifts' ),
				$start_utc,
				$end_utc
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Crew ids already linked to a volunteer-shift row.
	 *
	 * @return int[]
	 */
	public static function get_linked_crew_ids(): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT crew_id FROM %i WHERE crew_id > 0', self::table( 'volunteer_shifts' ) )
		);
		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Remember which Vis crew row a volunteer-shift was mapped to.
	 */
	public static function set_crew_id( int $volunteer_shift_id, int $crew_id ): void {
		global $wpdb;
		$wpdb->update( self::table( 'volunteer_shifts' ), [ 'crew_id' => $crew_id ], [ 'id' => $volunteer_shift_id ] );
	}

	/**
	 * Delete a volunteer-shift link.
	 */
	public static function delete_volunteer_shift( int $volunteer_shift_id ): void {
		global $wpdb;
		$wpdb->delete( self::table( 'volunteer_shifts' ), [ 'id' => $volunteer_shift_id ], [ '%d' ] );
	}

	// ── Volunteers ────────────────────────────────────────────────

	/**
	 * All staged volunteers with their latest shift end (UTC).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_volunteers(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT v.*, MAX(s.end_utc) AS last_shift_utc
				 FROM %i v
				 LEFT JOIN %i vs ON vs.volunteer_id = v.id
				 LEFT JOIN %i s ON vs.shift_id = s.id
				 GROUP BY v.id
				 ORDER BY v.name ASC',
				self::table( 'volunteers' ),
				self::table( 'volunteer_shifts' ),
				self::table( 'shifts' )
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Delete staged volunteers (and their shift links) that are no longer in Unitii.
	 *
	 * @param string[] $valid_unitii_ids Unitii ids returned by the API.
	 * @return int Number of volunteers deleted.
	 */
	public static function delete_obsolete_volunteers( array $valid_unitii_ids ): int {
		global $wpdb;
		if ( [] === $valid_unitii_ids ) {
			return 0; // Never wipe everything on an empty result.
		}
		$table = self::table( 'volunteers' );
		$keep  = array_flip( $valid_unitii_ids );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, unitii_id FROM %i', $table ),
			ARRAY_A
		) ?: [];

		$deleted = 0;
		foreach ( $rows as $row ) {
			if ( isset( $keep[ (string) $row['unitii_id'] ] ) ) {
				continue;
			}
			$wpdb->delete( self::table( 'volunteer_shifts' ), [ 'volunteer_id' => (int) $row['id'] ], [ '%d' ] );
			$wpdb->delete( $table, [ 'id' => (int) $row['id'] ], [ '%d' ] );
			++$deleted;
		}
		return $deleted;
	}

	/**
	 * Row counts of the staging tables (for the admin status box).
	 *
	 * @return array<string,int>
	 */
	public static function counts(): array {
		global $wpdb;
		$out = [];
		foreach ( [ 'volunteers', 'events', 'shifts', 'volunteer_shifts' ] as $name ) {
			$out[ $name ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table( $name ) ) );
		}
		return $out;
	}
}
