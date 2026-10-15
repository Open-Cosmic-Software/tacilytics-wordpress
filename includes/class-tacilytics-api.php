<?php
/**
 * Server-side reads from the Tacilytics API (dashboard widget, connection test).
 *
 * The API key never reaches the browser: WordPress fetches the numbers and caches them
 * for a few minutes in a transient.
 *
 * @package Tacilytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small API client.
 */
final class Tacilytics_Api {

	const CACHE_PREFIX = 'tacilytics_api_';
	const CACHE_TTL    = 300;

	/**
	 * GET a JSON endpoint.
	 *
	 * @param string $path  Path below the server base, e.g. '/api/v1/stats'.
	 * @param array  $query Query arguments.
	 * @param bool   $auth  Send the API key.
	 * @return array|WP_Error Decoded JSON.
	 */
	public static function get( $path, $query = array(), $auth = true ) {
		$base = Tacilytics_Settings::server_base();
		if ( '' === $base ) {
			return new WP_Error( 'tacilytics_not_configured', __( 'Tacilytics is not set up yet.', 'tacilytics' ) );
		}
		$headers = array( 'Accept' => 'application/json' );
		if ( $auth ) {
			$key = (string) Tacilytics_Settings::value( 'api_key' );
			if ( '' === $key ) {
				return new WP_Error( 'tacilytics_no_key', __( 'No API key saved.', 'tacilytics' ) );
			}
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$url = add_query_arg( array_map( 'rawurlencode', $query ), $base . $path );
		$args = array(
			'timeout'     => 8,
			'redirection' => 0, // Never follow redirects with the key attached.
			'headers'     => $headers,
			'user-agent'  => 'Tacilytics-WordPress/' . TACILYTICS_VERSION . '; ' . home_url( '/' ),
		);
		/**
		 * Self-hosted servers on a private network (e.g. the same machine) are blocked by
		 * wp_safe_remote_get(). Return true here to allow them.
		 *
		 * @param bool   $allow Allow private addresses.
		 * @param string $base  Server base URL.
		 */
		$allow_private = (bool) apply_filters( 'tacilytics_allow_private_server', false, $base );
		$res           = $allow_private ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'tacilytics_auth', __( 'The API key was rejected. Check that it belongs to this server and has access to this site.', 'tacilytics' ) );
		}
		if ( 404 === $code && $auth ) {
			return new WP_Error( 'tacilytics_site', __( 'This site was not found on the server. Add it in your Tacilytics dashboard first.', 'tacilytics' ) );
		}
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'tacilytics_http', sprintf( __( 'Unexpected answer from the server (HTTP %d).', 'tacilytics' ), $code ) );
		}
		return $body;
	}

	/**
	 * Stats for this site, cached.
	 *
	 * @param string $period today|7d|30d.
	 * @return array|WP_Error
	 */
	public static function stats( $period = '7d' ) {
		$period = in_array( $period, array( 'today', '7d', '30d' ), true ) ? $period : '7d';
		$key    = self::CACHE_PREFIX . md5( Tacilytics_Settings::server_base() . '|' . Tacilytics_Settings::value( 'domain' ) . '|' . $period );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = self::get(
			'/api/v1/stats',
			array(
				'site'   => Tacilytics_Settings::value( 'domain' ),
				'period' => $period,
			)
		);
		if ( ! is_wp_error( $data ) ) {
			set_transient( $key, self::slim( $data ), self::CACHE_TTL );
			return self::slim( $data );
		}
		return $data;
	}

	/**
	 * Keep only what the widget shows (the full answer can be large).
	 *
	 * @param array $d Full stats.
	 * @return array
	 */
	public static function slim( $d ) {
		$top = static function ( $list, $n ) {
			$out = array();
			foreach ( array_slice( is_array( $list ) ? $list : array(), 0, $n ) as $row ) {
				$out[] = array(
					'name'     => isset( $row['name'] ) ? (string) $row['name'] : '',
					'visitors' => isset( $row['visitors'] ) ? (int) $row['visitors'] : 0,
				);
			}
			return $out;
		};
		$totals = isset( $d['totals'] ) && is_array( $d['totals'] ) ? $d['totals'] : array();
		$trend  = isset( $d['trend'] ) && is_array( $d['trend'] ) ? $d['trend'] : array();
		$series = array();
		foreach ( ( isset( $d['series'] ) && is_array( $d['series'] ) ? $d['series'] : array() ) as $p ) {
			$series[] = array(
				'label'    => isset( $p['label'] ) ? (string) $p['label'] : '',
				'visitors' => isset( $p['visitors'] ) ? (int) $p['visitors'] : 0,
			);
		}
		return array(
			'visitors'  => isset( $totals['visitors'] ) ? (int) $totals['visitors'] : 0,
			'pageviews' => isset( $totals['pageviews'] ) ? (int) $totals['pageviews'] : 0,
			'bounce'    => isset( $totals['bounce'] ) ? (float) $totals['bounce'] : null,
			'trend'     => isset( $trend['visitors'] ) && is_numeric( $trend['visitors'] ) ? (float) $trend['visitors'] : null,
			'series'    => $series,
			'pages'     => $top( isset( $d['pages'] ) ? $d['pages'] : array(), 5 ),
			'sources'   => $top( isset( $d['sources'] ) ? $d['sources'] : array(), 5 ),
			'revenue'   => isset( $d['commerce']['revenue'] ) && is_numeric( $d['commerce']['revenue'] ) ? (float) $d['commerce']['revenue'] : null,
			'orders'    => isset( $d['commerce']['orders'] ) && is_numeric( $d['commerce']['orders'] ) ? (int) $d['commerce']['orders'] : null,
		);
	}

	/**
	 * Is the tracker reachable? (No key needed.)
	 *
	 * @return true|WP_Error
	 */
	public static function check_tracker() {
		$base = Tacilytics_Settings::server_base();
		if ( '' === $base ) {
			return new WP_Error( 'tacilytics_not_configured', __( 'Tacilytics is not set up yet.', 'tacilytics' ) );
		}
		$args = array(
			'timeout'     => 8,
			'redirection' => 2,
		);
		$allow = (bool) apply_filters( 'tacilytics_allow_private_server', false, $base );
		$res   = $allow ? wp_remote_head( $base . '/tacilytics.js', $args ) : wp_safe_remote_head( $base . '/tacilytics.js', $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'tacilytics_tracker', sprintf( __( 'The tracker script was not found on this server (HTTP %d). Is the address right?', 'tacilytics' ), $code ) );
		}
		return true;
	}

	/**
	 * Drop cached numbers (after settings change).
	 */
	public static function flush_cache() {
		foreach ( array( 'today', '7d', '30d' ) as $p ) {
			delete_transient( self::CACHE_PREFIX . md5( Tacilytics_Settings::server_base() . '|' . Tacilytics_Settings::value( 'domain' ) . '|' . $p ) );
		}
	}
}
