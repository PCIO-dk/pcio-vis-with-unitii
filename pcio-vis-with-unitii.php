<?php
/**
 * Plugin Name:       PCIO VIS With Unitii
 * Plugin URI:        https://www.pcio.dk/
 * Description:       Synchronises volunteers, events and shifts from the Unitii volunteer platform into PCIO VIS Member Event. Volunteers become members of a Vis workgroup with the vis_volunteer capability, and Unitii shifts are shown in the crew list of the matching Vis event.
 * Version:           1.0.0
 * Author:            PCIO
 * Author URI:        https://www.pcio.dk
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Requires Plugins:  pcio-vis-member-event
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pcio-vis-with-unitii
 * Domain Path:       /languages
 *
 * @package PCIO_VIS_With_Unitii
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCIO_VIS_UNITII_DIR', plugin_dir_path( __FILE__ ) );

require_once PCIO_VIS_UNITII_DIR . 'includes/class-pcio-vis-with-unitii-db.php';
require_once PCIO_VIS_UNITII_DIR . 'includes/class-pcio-vis-with-unitii-settings.php';
require_once PCIO_VIS_UNITII_DIR . 'includes/class-pcio-vis-with-unitii-client.php';
require_once PCIO_VIS_UNITII_DIR . 'includes/class-pcio-vis-with-unitii.php';
require_once PCIO_VIS_UNITII_DIR . 'includes/class-pcio-vis-with-unitii-admin.php';

/**
 * Hook registration for the Unitii extension.
 */
class PCIO_VIS_With_Unitii_Plugin {

	const CRON_NAME = 'pcio_me_unitii_sync';
	const CALLABLE  = 'PCIO_VIS_With_Unitii::run_sync';

	/**
	 * Register hooks (core plugin is active).
	 */
	public static function register(): void {
		add_action( 'admin_init', [ 'PCIO_VIS_With_Unitii_DB', 'install' ] );
		add_action( 'admin_menu', [ 'PCIO_VIS_With_Unitii_Admin', 'add_menu' ], 20 );
		add_action( 'pcio_me_sync_register_jobs', [ __CLASS__, 'register_job' ] );
		add_filter( 'pcio_me_sync_callables', [ __CLASS__, 'register_callable' ] );
	}

	/**
	 * Seed the Unitii sync job into the me_sync registry (disabled until configured).
	 * Fires on 'pcio_me_sync_register_jobs'.
	 */
	public static function register_job(): void {
		if ( ! class_exists( 'PCIO_VIS_Sync_DB' ) || PCIO_VIS_Sync_DB::get_by_cron_name( self::CRON_NAME ) ) {
			return;
		}
		PCIO_VIS_Sync_DB::create(
			[
				'name'         => __( 'Unitii sync', 'pcio-vis-with-unitii' ),
				'description'  => __( 'Imports volunteers, events and shifts from Unitii: updates the Unitii workgroup, grants vis_volunteer, creates missing Vis events from the template and syncs the Unitii shifts into the event crew lists.', 'pcio-vis-with-unitii' ),
				'cron_name'    => self::CRON_NAME,
				'php_function' => self::CALLABLE,
				'sync_url'     => '',
				'frequence'    => 24,
				'enabled'      => 0,
			]
		);
	}

	/**
	 * Allow the core sync executor to run this extension's callable.
	 * Filter: pcio_me_sync_callables
	 *
	 * @param array<string,string> $callables Map of callable => label.
	 * @return array<string,string>
	 */
	public static function register_callable( array $callables ): array {
		$callables[ self::CALLABLE ] = __( 'Unitii sync', 'pcio-vis-with-unitii' );
		return $callables;
	}
}

register_activation_hook( __FILE__, [ 'PCIO_VIS_With_Unitii_DB', 'install' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		// Bail if the core plugin is not active.
		if ( ! class_exists( 'PCIO_VIS_Plugin' ) ) {
			return;
		}
		PCIO_VIS_With_Unitii_Plugin::register();
	}
);
