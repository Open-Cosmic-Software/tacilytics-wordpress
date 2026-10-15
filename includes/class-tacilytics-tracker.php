<?php
/**
 * Prints the Tacilytics tracker in <head>.
 *
 * Excluding logged-in roles is done in the browser, not by leaving the script out in PHP:
 * with a page cache (WP Rocket, LiteSpeed, Cloudflare APO …) the first page that gets cached
 * would decide for every visitor. Instead WordPress sets a small, non-identifying marker in
 * localStorage for excluded roles, and the tracker's own "don't count this browser" switch
 * does the rest. No cookie is set.
 *
 * @package Tacilytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end tracker output.
 */
final class Tacilytics_Tracker {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 1 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
	}

	/**
	 * Data attributes for the <script> tag.
	 *
	 * @return array
	 */
	public static function attributes() {
		$s     = Tacilytics_Settings::get();
		$attrs = array( 'data-domain' => $s['domain'] );
		if ( $s['track_outbound'] ) {
			$attrs['data-track-outbound'] = '';
		}
		if ( $s['track_downloads'] ) {
			$attrs['data-track-downloads'] = '';
		}
		if ( $s['track_search'] ) {
			// WordPress core search uses ?s=. Count results only inside <main> (block themes show
			// "latest posts" suggestions on an empty result page); "no results" comes from the
			// body class WordPress sets itself (see print_config()).
			$attrs['data-track-search']   = 's';
			$attrs['data-search-results'] = 'body.search-results main article, body.search-results main .wp-block-post, body.search-results li.product';
		}
		if ( $s['track_404'] ) {
			$attrs['data-track-404'] = 'body.error404';
		}
		if ( $s['honor_dnt'] ) {
			$attrs['data-honor-dnt'] = '';
		}
		/**
		 * Filter the data attributes of the tracker <script> tag.
		 *
		 * @param array $attrs Attribute => value ('' for boolean attributes).
		 */
		return (array) apply_filters( 'tacilytics_script_attributes', $attrs );
	}

	const HANDLE = 'tacilytics';

	/**
	 * Should this request be tracked at all?
	 *
	 * @return bool
	 */
	private static function should_track() {
		if ( ! Tacilytics_Settings::is_ready() || is_preview() || is_customize_preview() ) {
			return false;
		}
		/** Allow sites to switch tracking off for a request, e.g. on private pages. */
		return (bool) apply_filters( 'tacilytics_should_track', true );
	}

	/**
	 * Enqueue the tracker (from the Tacilytics server, deferred, in <head>) plus two tiny inline parts:
	 * the search configuration before it, and the role marker for logged-in users.
	 */
	public static function enqueue() {
		if ( ! self::should_track() ) {
			return;
		}
		// The tracker belongs to the Tacilytics server and is cached there; a ?ver= from WordPress
		// would only split that cache. So no version on purpose.
		wp_enqueue_script(
			self::HANDLE,
			Tacilytics_Settings::server_base() . '/tacilytics.js',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			array(
				'in_footer' => false,
				'strategy'  => 'defer',
			)
		);
		if ( Tacilytics_Settings::value( 'track_search' ) ) {
			// WordPress marks an empty search with body.search-no-results.
			wp_add_inline_script( self::HANDLE, 'window.tacilyticsConfig=Object.assign({searchEmpty:"body.search-no-results"},window.tacilyticsConfig||{});', 'before' );
		}
		$marker = self::role_marker();
		if ( '' !== $marker ) {
			// Must run before the deferred tracker reads localStorage.
			wp_add_inline_script( self::HANDLE, $marker, 'before' );
		}
	}

	/**
	 * Put our data-* attributes on the tracker's <script> tag.
	 *
	 * @param string $tag    Tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function script_tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$attrs = '';
		foreach ( self::attributes() as $name => $value ) {
			$attrs .= ' ' . esc_attr( $name ) . ( '' === $value ? '' : '="' . esc_attr( $value ) . '"' );
		}
		// Only the tag that loads tacilytics.js, not the inline "before" parts.
		return preg_replace( '#<script(?=[^>]*\ssrc=)#', '<script' . $attrs, $tag, 1 );
	}

	/**
	 * For logged-in users: mark or unmark this browser as "don't count". Logged-in pages are never
	 * served from a page cache, so this cannot leak to other visitors.
	 *
	 * The tracker reads tacilytics_ignore. We only remove a marker that WE set (tacilytics_wp),
	 * so a visitor's own ?tacilytics=off choice is never undone.
	 *
	 * @return string JavaScript or ''.
	 */
	private static function role_marker() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$excluded = (array) Tacilytics_Settings::value( 'exclude_roles' );
		$skip     = (bool) array_intersect( $excluded, (array) wp_get_current_user()->roles );
		return '(function(){try{var s=window.localStorage;if(' . ( $skip ? 'true' : 'false' ) . '){s.setItem("tacilytics_ignore","1");s.setItem("tacilytics_wp","1");}else if(s.getItem("tacilytics_wp")==="1"){s.removeItem("tacilytics_ignore");s.removeItem("tacilytics_wp");}}catch(e){}})();';
	}
}
