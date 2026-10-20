<?php
/**
 * Uninstall handler for PCIO VIS With Unitii.
 *
 * Removes the plugin's options and its Unitii staging tables. Vis members,
 * workgroup memberships, events and crew rows created by the sync are left in
 * place — they belong to the Member Event core and may still be in use.
 *
 * @package PCIO_VIS_With_Unitii
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-pcio-vis-with-unitii-db.php';
require_once __DIR__ . '/includes/class-pcio-vis-with-unitii-settings.php';

foreach ( PCIO_VIS_With_Unitii_Settings::all_options() as $pcio_vis_unitii_option ) {
	delete_option( $pcio_vis_unitii_option );
}
delete_option( PCIO_VIS_With_Unitii_DB::OPT_DB_VERSION );

PCIO_VIS_With_Unitii_DB::drop_tables();
