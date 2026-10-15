<?php
/**
 * Remove everything the plugin stored. Data on the Tacilytics server is not touched.
 *
 * @package Tacilytics
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tacilytics_settings' );
delete_transient( 'tacilytics_activation_redirect' );

global $wpdb;
// Cached stats and test results (transients with our prefix).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_tacilytics_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_tacilytics_' ) . '%',
		$wpdb->esc_like( '_site_transient_tacilytics_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_tacilytics_' ) . '%',
		$wpdb->esc_like( 'tacilytics_p_' ) . '%' // Per-order purchase locks.
	)
);
