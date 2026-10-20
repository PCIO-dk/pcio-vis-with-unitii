<?php
/**
 * Settings accessor for the Unitii integration.
 *
 * @package PCIO_VIS_With_Unitii
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option names and typed getters for the Unitii settings page.
 */
class PCIO_VIS_With_Unitii_Settings {

	const DEFAULT_API_URL = 'https://api.unitii.dk/graphql';

	const OPT_API_URL           = 'pcio_vis_unitii_api_url';
	const OPT_USERNAME          = 'pcio_vis_unitii_username';
	const OPT_PASSWORD          = 'pcio_vis_unitii_password';
	const OPT_EVENT_TYPE_ID     = 'pcio_vis_unitii_event_type_id';
	const OPT_ROLE_ID           = 'pcio_vis_unitii_role_id';
	const OPT_WORKGROUP_ID      = 'pcio_vis_unitii_workgroup_id';
	const OPT_TEMPLATE_EVENT_ID = 'pcio_vis_unitii_template_event_id';
	const OPT_EMAIL_MAP         = 'pcio_vis_unitii_email_map';
	const OPT_LAST_LOG          = 'pcio_vis_unitii_last_log';

	/**
	 * All option names (used by uninstall).
	 *
	 * @return string[]
	 */
	public static function all_options(): array {
		return [
			self::OPT_API_URL,
			self::OPT_USERNAME,
			self::OPT_PASSWORD,
			self::OPT_EVENT_TYPE_ID,
			self::OPT_ROLE_ID,
			self::OPT_WORKGROUP_ID,
			self::OPT_TEMPLATE_EVENT_ID,
			self::OPT_EMAIL_MAP,
			self::OPT_LAST_LOG,
		];
	}

	public static function api_url(): string {
		$url = (string) get_option( self::OPT_API_URL, '' );
		return '' !== $url ? $url : self::DEFAULT_API_URL;
	}

	public static function username(): string {
		return (string) get_option( self::OPT_USERNAME, '' );
	}

	public static function password(): string {
		return (string) get_option( self::OPT_PASSWORD, '' );
	}

	public static function event_type_id(): int {
		return (int) get_option( self::OPT_EVENT_TYPE_ID, 0 );
	}

	public static function role_id(): int {
		return (int) get_option( self::OPT_ROLE_ID, 0 );
	}

	public static function workgroup_id(): int {
		return (int) get_option( self::OPT_WORKGROUP_ID, 0 );
	}

	public static function template_event_id(): int {
		return (int) get_option( self::OPT_TEMPLATE_EVENT_ID, 0 );
	}

	/**
	 * Email exceptions: Unitii email (lower case) => Vis member email.
	 *
	 * @return array<string,string>
	 */
	public static function email_map(): array {
		$map = get_option( self::OPT_EMAIL_MAP, [] );
		return is_array( $map ) ? $map : [];
	}

	/**
	 * Add or replace one email exception.
	 */
	public static function add_email_mapping( string $unitii_email, string $vis_email ): void {
		$map                                = self::email_map();
		$map[ strtolower( $unitii_email ) ] = $vis_email;
		ksort( $map );
		update_option( self::OPT_EMAIL_MAP, $map, false );
	}

	/**
	 * Remove one email exception.
	 */
	public static function remove_email_mapping( string $unitii_email ): void {
		$map = self::email_map();
		unset( $map[ strtolower( $unitii_email ) ] );
		update_option( self::OPT_EMAIL_MAP, $map, false );
	}

	/**
	 * Vis email for a Unitii email: the mapped exception, or the same address.
	 */
	public static function vis_email_for( string $unitii_email ): string {
		$map = self::email_map();
		$key = strtolower( trim( $unitii_email ) );
		return $map[ $key ] ?? trim( $unitii_email );
	}

	/**
	 * Human-readable list of what is still missing before the sync can run.
	 *
	 * @return string[]
	 */
	public static function missing(): array {
		$missing = [];
		if ( '' === self::username() || '' === self::password() ) {
			$missing[] = __( 'Unitii username and password', 'pcio-vis-with-unitii' );
		}
		if ( self::event_type_id() <= 0 ) {
			$missing[] = __( 'Vis event type', 'pcio-vis-with-unitii' );
		}
		if ( self::role_id() <= 0 ) {
			$missing[] = __( 'Vis event crew role', 'pcio-vis-with-unitii' );
		}
		if ( self::workgroup_id() <= 0 ) {
			$missing[] = __( 'Vis workgroup', 'pcio-vis-with-unitii' );
		}
		if ( self::template_event_id() <= 0 ) {
			$missing[] = __( 'Template event', 'pcio-vis-with-unitii' );
		}
		return $missing;
	}
}
