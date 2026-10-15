<?php
/**
 * WooCommerce: one "Purchase" event on the order-received page.
 *
 * Sends only order value, net value, currency and number of items — no order number,
 * no customer data.
 *
 * Counted at most once per order:
 * - The browser only marks the order as sent AFTER the tracker took the event
 *   (sessionStorage for this tab, plus a confirm call to WordPress).
 * - The confirm call claims the order with add_option(), which is atomic in the database,
 *   so two tabs or a reload can never both count it. A blocked or slow tracker leaves the
 *   order unclaimed, so a later visit of the thank-you page can still count it.
 *
 * Orders that are not paid yet (bank transfer: on-hold / pending) are counted when the
 * order is placed, like in most shop analytics. Failed and cancelled orders are not counted.
 *
 * @package Tacilytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration.
 */
final class Tacilytics_WooCommerce {

	const META   = '_tacilytics_purchase_sent';
	const ACTION = 'tacilytics_purchase_sent';

	/**
	 * Hook into WooCommerce if it is active.
	 */
	public static function init() {
		add_action(
			'before_woocommerce_init',
			static function () {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TACILYTICS_FILE, true );
				}
			}
		);
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'purchase_event' ), 20 );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'confirm' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'confirm' ) );
	}

	/**
	 * Values sent for an order. Public for tests.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function props( $order ) {
		$total = (float) $order->get_total();
		$tax   = (float) $order->get_total_tax();
		$items = 0;
		foreach ( $order->get_items() as $item ) {
			$items += (int) $item->get_quantity();
		}
		return array(
			'value'    => round( $total, 2 ),
			'net'      => round( $total - $tax, 2 ),
			'currency' => (string) $order->get_currency(),
			'items'    => $items,
		);
	}

	/**
	 * Name of the per-order lock option. Hash of the order key: not guessable, no order id inside.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function lock_name( $order ) {
		return 'tacilytics_p_' . substr( hash( 'sha256', $order->get_id() . '|' . $order->get_order_key() ), 0, 32 );
	}

	/**
	 * Print the purchase event.
	 *
	 * @param int $order_id Order id.
	 */
	public static function purchase_event( $order_id ) {
		if ( ! Tacilytics_Settings::is_ready() || ! Tacilytics_Settings::value( 'woocommerce' ) ) {
			return;
		}
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || $order->has_status( array( 'failed', 'cancelled' ) ) || $order->get_meta( self::META ) ) {
			return;
		}
		$props = self::props( $order );
		$conf  = array(
			'key'    => 'tacilytics-purchase-' . substr( hash( 'sha256', $order->get_order_key() ), 0, 16 ),
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => self::ACTION,
			'order'  => (int) $order->get_id(),
			// Proof that the confirm call belongs to this order: only someone who saw the thank-you page has it.
			'token'  => hash_hmac( 'sha256', (string) $order->get_id(), $order->get_order_key() . wp_salt( 'nonce' ) ),
			'props'  => $props,
		);
		// Printed through the script API, not into the page content: block themes run the content
		// through filters that would turn && into &#038; and break inline JavaScript.
		wp_register_script( 'tacilytics-purchase', '', array(), TACILYTICS_VERSION, true );
		wp_enqueue_script( 'tacilytics-purchase' );
		wp_add_inline_script( 'tacilytics-purchase', 'window.tacilyticsPurchase=' . wp_json_encode( $conf ) . ';' . self::purchase_js() );
	}

	/**
	 * Browser side of the purchase event (static, data comes from window.tacilyticsPurchase).
	 *
	 * @return string
	 */
	private static function purchase_js() {
		return <<<'JS'
(function () {
	try {
		var c = window.tacilyticsPurchase;
		if (!c || (window.sessionStorage && sessionStorage.getItem(c.key))) { return; }
		var tries = 0;
		(function fire() {
			if (typeof window.tacilytics !== 'function') {
				if (tries++ < 40) { setTimeout(fire, 250); }
				return; // Tracker never came (blocked, offline): the order stays unclaimed.
			}
			// Claim first, send only if we got the order: two tabs can never both count it.
			var body = new URLSearchParams({ action: c.action, order: String(c.order), token: c.token });
			fetch(c.url, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j && j.success && j.data && j.data.claimed) { window.tacilytics('Purchase', c.props); }
					try { sessionStorage.setItem(c.key, '1'); } catch (e) {}
				})
				.catch(function () {});
		})();
	} catch (e) {}
})();
JS;
	}

	/**
	 * Claim an order for counting (AJAX). Answers claimed=true exactly once per order.
	 */
	public static function confirm() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guests have no session nonce; the HMAC token proves the order.
		$order_id = isset( $_POST['order'] ) ? absint( wp_unslash( $_POST['order'] ) ) : 0;
		$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || '' === $token || ! hash_equals( hash_hmac( 'sha256', (string) $order->get_id(), $order->get_order_key() . wp_salt( 'nonce' ) ), $token ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( $order->get_meta( self::META ) ) {
			wp_send_json_success( array( 'claimed' => false ) );
		}
		// add_option() inserts with a unique key: only one request can win, also across tabs.
		$won = add_option( self::lock_name( $order ), time(), '', false );
		if ( $won ) {
			// The lock option stays: deleting it would let a request that read the meta just
			// before this save claim the order a second time. Removed on uninstall.
			$order->update_meta_data( self::META, time() );
			$order->save_meta_data();
		}
		wp_send_json_success( array( 'claimed' => (bool) $won ) );
	}
}
