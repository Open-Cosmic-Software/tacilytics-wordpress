<?php
/**
 * WooCommerce: one "Purchase" event on the order-received page.
 *
 * Sends only order value, net value, currency and number of items — no order number,
 * no customer data. A reload of the thank-you page is not counted twice (sessionStorage
 * in the browser plus an order meta flag on the server).
 *
 * @package Tacilytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration.
 */
final class Tacilytics_WooCommerce {

	const META = '_tacilytics_purchase_sent';

	/**
	 * Hook into WooCommerce if it is active.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'purchase_event' ), 20 );
		// Declare HPOS compatibility: we only read the order and set one meta flag through the CRUD API.
		add_action(
			'before_woocommerce_init',
			static function () {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TACILYTICS_FILE, true );
				}
			}
		);
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
		$order->update_meta_data( self::META, time() );
		$order->save_meta_data();

		$props = self::props( $order );
		// Key for the browser-side guard: a hash, so the order id does not end up in storage.
		$key = 'tacilytics-purchase-' . substr( hash( 'sha256', $order->get_order_key() ), 0, 16 );
		?>
<script>
(function () {
	try {
		var key = <?php echo wp_json_encode( $key ); ?>;
		if (window.sessionStorage && sessionStorage.getItem(key)) { return; }
		var props = <?php echo wp_json_encode( $props ); ?>;
		var tries = 0;
		(function fire() {
			if (typeof window.tacilytics === 'function') {
				window.tacilytics('Purchase', props);
				try { sessionStorage.setItem(key, '1'); } catch (e) {}
			} else if (tries++ < 40) {
				setTimeout(fire, 250);
			}
		})();
	} catch (e) {}
})();
</script>
		<?php
	}
}
