<?php
/*
Plugin Name: WP Migrate DB Pro Multisite Tools
Plugin URI: http://deliciousbrains.com/wp-migrate-db-pro/
Description: An extension to WP Migrate DB Pro, supporting Multisite migrations.
Author: Delicious Brains
Version: 1.1.3
Author URI: http://deliciousbrains.com
Network: True
*/

// Copyright (c) 2015 Delicious Brains. All rights reserved.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************

require_once 'version.php';
$GLOBALS['wpmdb_meta']['wp-migrate-db-pro-multisite-tools']['folder'] = basename( plugin_dir_path( __FILE__ ) );

/**
 * Populate the $wpmdbpro_multisite_tools global with an instance of the WPMDBPro_Multisite_Tools class and return it.
 *
 * @param bool $cli
 *
 * @return WPMDBPro_Multisite_Tools The one true global instance of the WPMDBPro_Multisite_Tools class.
 */
function wp_migrate_db_pro_multisite_tools( $cli = false ) {
	global $wpmdbpro_multisite_tools;

	if ( ! class_exists( 'WPMDBPro_Addon' ) ) {
		return false;
	}

	// Allows hooks to bypass the regular admin / ajax checks to force load the addon (required for the CLI addon).
	$force_load = apply_filters( 'wp_migrate_db_pro_multisite_tools_force_load', false );

	if ( false === $force_load && ! is_null( $wpmdbpro_multisite_tools ) ) {
		return $wpmdbpro_multisite_tools;
	}

	if ( false === $force_load && ( ! function_exists( 'wp_migrate_db_pro_loaded' ) || ! wp_migrate_db_pro_loaded() || ( is_multisite() && wp_is_large_network() ) ) ) {
		return false;
	}

	load_plugin_textdomain( 'wp-migrate-db-pro-multisite-tools', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	require_once dirname( __FILE__ ) . '/class/wpmdbpro-multisite-tools.php';

	if ( $cli ) {
		require_once dirname( __FILE__ ) . '/class/cli/wpmdbpro-multisite-tools-cli.php';

		$wpmdbpro_multisite_tools = new WPMDBPro_Multisite_Tools_CLI( __FILE__ );
	} else {
		$wpmdbpro_multisite_tools = new WPMDBPro_Multisite_Tools( __FILE__ );
	}

	return $wpmdbpro_multisite_tools;
}

/**
 * By default load plugin on admin pages, a little later than WP Migrate DB Pro.
 */
add_action( 'admin_init', 'wp_migrate_db_pro_multisite_tools', 20 );

/**
 * Loads up an instance of the WPMDBPro_Multisite_Tools class, allowing Multisite Tools functionality to be used during CLI migrations.
 */
function wp_migrate_db_pro_multisite_tools_before_cli_load() {
	// Force load the Multisite Tools addon
	add_filter( 'wp_migrate_db_pro_multisite_tools_force_load', '__return_true' );

	wp_migrate_db_pro_multisite_tools( true );
}

add_action( 'wp_migrate_db_pro_cli_before_load', 'wp_migrate_db_pro_multisite_tools_before_cli_load' );
