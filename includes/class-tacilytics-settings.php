<?php
/**
 * Settings storage, defaults and sanitising. One option, never autoloaded secrets into the front end.
 *
 * @package Tacilytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates the plugin settings.
 */
final class Tacilytics_Settings {

	const OPTION = 'tacilytics_settings';

	/** Roles that are never counted by default (their own visits would skew small sites). */
	const DEFAULT_EXCLUDED_ROLES = array( 'administrator', 'editor', 'shop_manager' );

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'              => '',           // '' (not set up yet) | 'self' | 'cloud'.
			'server_url'        => '',           // Self-hosted: https://analytics.example.com.
			'domain'            => self::site_domain(),
			'api_key'           => '',           // Read-only personal key (tk_…), only for the dashboard widget.
			'exclude_roles'     => self::DEFAULT_EXCLUDED_ROLES,
			'track_outbound'    => 1,
			'track_downloads'   => 1,
			'track_search'      => 1,
			'track_404'         => 1,
			'honor_dnt'         => 0,
			'woocommerce'       => 1,
		);
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function value( $key ) {
		$s = self::get();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/**
	 * Host name of this WordPress site, without "www." — what Tacilytics expects as data-domain.
	 *
	 * @return string
	 */
	public static function site_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Base URL of the server the tracker talks to, without trailing slash. '' if not configured.
	 *
	 * @return string
	 */
	public static function server_base() {
		$s = self::get();
		if ( 'cloud' === $s['mode'] ) {
			return untrailingslashit( TACILYTICS_CLOUD_URL );
		}
		if ( 'self' === $s['mode'] && '' !== $s['server_url'] ) {
			return untrailingslashit( $s['server_url'] );
		}
		return '';
	}

	/**
	 * Whether tracking is fully configured.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		$s = self::get();
		return '' !== self::server_base() && '' !== $s['domain'];
	}

	/**
	 * Validate a server URL: https only (http allowed for localhost), no credentials, query or fragment.
	 *
	 * @param string $url Raw input.
	 * @return string Clean URL or '' if invalid.
	 */
	public static function clean_server_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$local  = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
		/**
		 * Allow a plain-http server address (e.g. inside a private network). Off by default:
		 * over http the API key would travel unencrypted.
		 *
		 * @param bool   $allow Allow http.
		 * @param string $host  Host name.
		 */
		$http_ok = $local || (bool) apply_filters( 'tacilytics_allow_http_server', false, $host );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $http_ok ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		// The tracker URL is the dashboard root; drop a pasted script path.
		$path = preg_replace( '#/(tacilytics|nyx)\.js$#i', '', $path );
		return esc_url_raw( $scheme . '://' . $host . $port . $path );
	}

	/**
	 * Validate a site domain (punycode allowed).
	 *
	 * @param string $domain Raw input.
	 * @return string
	 */
	public static function clean_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = preg_replace( '#[/?\#].*$#', '', $domain );
		$domain = preg_replace( '/^www\./', '', $domain );
		return preg_match( '/^[a-z0-9.-]+\.[a-z0-9-]{2,}$/', $domain ) ? $domain : '';
	}

	/**
	 * Sanitise the whole option on save (register_setting callback).
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old   = self::get();
		$input = is_array( $input ) ? $input : array();
		$out   = $old;

		$mode = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : $old['mode'];
		$mode = in_array( $mode, array( '', 'self', 'cloud' ), true ) ? $mode : $old['mode'];

		if ( isset( $input['server_url'] ) ) {
			$url = self::clean_server_url( wp_unslash( $input['server_url'] ) );
			if ( '' === $url && '' !== trim( (string) $input['server_url'] ) ) {
				add_settings_error( self::OPTION, 'server_url', __( 'The server address must start with https:// (http is only allowed for localhost).', 'tacilytics' ) );
				$url = $old['server_url'];
			}
			$out['server_url'] = $url;
		}

		if ( isset( $input['domain'] ) ) {
			$domain = self::clean_domain( wp_unslash( $input['domain'] ) );
			if ( '' === $domain ) {
				add_settings_error( self::OPTION, 'domain', __( 'Please enter a valid domain, e.g. example.com.', 'tacilytics' ) );
				$domain = $old['domain'];
			}
			$out['domain'] = $domain;
		}

		// Switch the mode only when the connection is complete, otherwise the first-run choice would
		// disappear behind a half-configured settings page after a typo in the address.
		if ( 'self' === $mode && '' === $out['server_url'] ) {
			$mode = $old['mode'];
		}
		$out['mode'] = $mode;

		if ( isset( $input['api_key'] ) ) {
			$key = trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) );
			// Leaving the masked field untouched keeps the stored key.
			if ( '' === $key || preg_match( '/^tk_[A-Za-z0-9_-]{16,}$/', $key ) ) {
				$out['api_key'] = $key;
			} elseif ( self::mask( $old['api_key'] ) !== $key ) {
				add_settings_error( self::OPTION, 'api_key', __( 'API keys start with tk_. Create a read-only key under Settings → Security in your Tacilytics dashboard.', 'tacilytics' ) );
			}
		}

		// Checkboxes and the role list only exist on the full settings form. The setup step
		// (choose self-hosted / cloud) must not switch them all off just because they were not sent.
		if ( isset( $input['_form'] ) && 'settings' === $input['_form'] ) {
			$roles = isset( $input['exclude_roles'] ) && is_array( $input['exclude_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $input['exclude_roles'] ) ) : array();
			$known = array_keys( wp_roles()->get_names() );
			// Roles that do not exist right now (e.g. shop_manager before WooCommerce is installed)
			// were not on the form, so the user could not have unticked them: keep them.
			$hidden               = array_diff( (array) $old['exclude_roles'], $known );
			$out['exclude_roles'] = array_values( array_unique( array_merge( array_intersect( $roles, $known ), $hidden ) ) );

			$flags = array( 'track_outbound', 'track_downloads', 'track_search', 'track_404', 'honor_dnt' );
			if ( class_exists( 'WooCommerce' ) ) {
				$flags[] = 'woocommerce'; // Only on the form when WooCommerce is active.
			}
			foreach ( $flags as $flag ) {
				$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
			}
		}

		if ( $out['api_key'] !== $old['api_key'] || $out['server_url'] !== $old['server_url'] || $out['domain'] !== $old['domain'] || $out['mode'] !== $old['mode'] ) {
			Tacilytics_Api::flush_cache();
		}
		return $out;
	}

	/**
	 * Show only the start of a secret.
	 *
	 * @param string $secret Secret value.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		return '' === $secret ? '' : substr( $secret, 0, 7 ) . str_repeat( '•', 12 );
	}
}
