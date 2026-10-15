<?php
/**
 * Plugin Name:       Tacilytics – Privacy-Friendly Analytics
 * Plugin URI:        https://tacilytics.com/wordpress
 * Description:       Cookieless, privacy-friendly analytics for WordPress and WooCommerce. Use your own self-hosted Tacilytics server or Tacilytics Cloud. No cookies, no consent banner needed for the tracker.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Open Cosmic Software
 * Author URI:        https://github.com/Open-Cosmic-Software
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tacilytics
 * Domain Path:       /languages
 *
 * @package Tacilytics
 */

defined( 'ABSPATH' ) || exit;

define( 'TACILYTICS_VERSION', '1.0.0' );
define( 'TACILYTICS_FILE', __FILE__ );
define( 'TACILYTICS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TACILYTICS_URL', plugin_dir_url( __FILE__ ) );
/** Base URL of Tacilytics Cloud. Only contacted when the site owner chooses "Tacilytics Cloud". */
if ( ! defined( 'TACILYTICS_CLOUD_URL' ) ) {
	define( 'TACILYTICS_CLOUD_URL', 'https://cloud.tacilytics.com' );
}

require_once TACILYTICS_DIR . 'includes/class-tacilytics-settings.php';
require_once TACILYTICS_DIR . 'includes/class-tacilytics-tracker.php';
require_once TACILYTICS_DIR . 'includes/class-tacilytics-woocommerce.php';
require_once TACILYTICS_DIR . 'includes/class-tacilytics-api.php';
require_once TACILYTICS_DIR . 'includes/class-tacilytics-admin.php';
require_once TACILYTICS_DIR . 'includes/class-tacilytics-dashboard-widget.php';

/*
 * Bundled German translations. Translations from wordpress.org (wp-content/languages/plugins)
 * win when they exist; this only fills the gap for installs outside the directory.
 */
add_filter(
	'load_textdomain_mofile',
	static function ( $mofile, $domain ) {
		if ( 'tacilytics' !== $domain || is_readable( $mofile ) ) {
			return $mofile;
		}
		$bundled = TACILYTICS_DIR . 'languages/' . basename( $mofile );
		return is_readable( $bundled ) ? $bundled : $mofile;
	},
	10,
	2
);

add_action(
	'plugins_loaded',
	static function () {
		Tacilytics_Tracker::init();
		Tacilytics_WooCommerce::init();
		if ( is_admin() ) {
			Tacilytics_Admin::init();
			Tacilytics_Dashboard_Widget::init();
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( false === get_option( Tacilytics_Settings::OPTION, false ) ) {
			add_option( Tacilytics_Settings::OPTION, Tacilytics_Settings::defaults(), '', false );
		}
		set_transient( 'tacilytics_activation_redirect', 1, 60 );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'options-general.php?page=tacilytics' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tacilytics' ) . '</a>' );
		return $links;
	}
);
