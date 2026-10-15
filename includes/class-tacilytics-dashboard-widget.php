<?php
/**
 * WordPress dashboard widget: visitors, page views, a small chart, top pages and sources.
 *
 * @package Tacilytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard widget.
 */
final class Tacilytics_Dashboard_Widget {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	/**
	 * Who may see the numbers. Filterable, defaults to admins and shop managers.
	 *
	 * @return bool
	 */
	private static function can_view() {
		$cap = (string) apply_filters( 'tacilytics_dashboard_capability', 'manage_options' );
		return current_user_can( $cap ) || ( class_exists( 'WooCommerce' ) && current_user_can( 'view_woocommerce_reports' ) );
	}

	/**
	 * Register the widget.
	 */
	public static function register() {
		if ( ! self::can_view() ) {
			return;
		}
		wp_add_dashboard_widget( 'tacilytics_dashboard', __( 'Tacilytics – last 7 days', 'tacilytics' ), array( __CLASS__, 'render' ) );
	}

	/**
	 * Widget body.
	 */
	public static function render() {
		$settings_url = admin_url( 'options-general.php?page=' . Tacilytics_Admin::PAGE );
		if ( ! Tacilytics_Settings::is_ready() ) {
			echo '<p>' . esc_html__( 'Tacilytics is not set up yet.', 'tacilytics' ) . ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Set it up', 'tacilytics' ) . '</a></p>';
			return;
		}
		if ( '' === (string) Tacilytics_Settings::value( 'api_key' ) ) {
			echo '<p>' . esc_html__( 'Tracking is active. Add a read-only API key to see your numbers here.', 'tacilytics' ) . ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Add API key', 'tacilytics' ) . '</a></p>';
			return;
		}
		$d = Tacilytics_Api::stats( '7d' );
		if ( is_wp_error( $d ) ) {
			echo '<p class="tacilytics-error">' . esc_html( $d->get_error_message() ) . '</p>';
			return;
		}
		$n = static function ( $v ) {
			return esc_html( number_format_i18n( (float) $v ) );
		};
		?>
		<div class="tacilytics-widget">
			<div class="tacilytics-kpis">
				<div><span class="tacilytics-kpi-value"><?php echo $n( $d['visitors'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="tacilytics-kpi-label"><?php esc_html_e( 'Visitors', 'tacilytics' ); ?>
					<?php if ( null !== $d['trend'] ) : ?>
						<em class="<?php echo $d['trend'] >= 0 ? 'up' : 'down'; ?>"><?php echo esc_html( ( $d['trend'] >= 0 ? '▲ ' : '▼ ' ) . abs( round( $d['trend'] ) ) . ' %' ); ?></em>
					<?php endif; ?>
				</span></div>
				<div><span class="tacilytics-kpi-value"><?php echo $n( $d['pageviews'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="tacilytics-kpi-label"><?php esc_html_e( 'Page views', 'tacilytics' ); ?></span></div>
				<?php if ( null !== $d['revenue'] ) : ?>
					<div><span class="tacilytics-kpi-value"><?php echo esc_html( self::money( $d['revenue'] ) ); ?></span><span class="tacilytics-kpi-label"><?php esc_html_e( 'Revenue', 'tacilytics' ); ?></span></div>
				<?php elseif ( null !== $d['bounce'] ) : ?>
					<div><span class="tacilytics-kpi-value"><?php echo esc_html( round( $d['bounce'] ) . ' %' ); ?></span><span class="tacilytics-kpi-label"><?php esc_html_e( 'Bounce rate', 'tacilytics' ); ?></span></div>
				<?php endif; ?>
			</div>
			<?php self::chart( $d['series'] ); ?>
			<div class="tacilytics-lists">
				<?php self::top_list( __( 'Top pages', 'tacilytics' ), $d['pages'] ); ?>
				<?php self::top_list( __( 'Top sources', 'tacilytics' ), $d['sources'] ); ?>
			</div>
			<p class="tacilytics-more"><a href="<?php echo esc_url( Tacilytics_Settings::server_base() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open full dashboard →', 'tacilytics' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Inline SVG bar chart (no third-party library, no requests from the browser).
	 *
	 * @param array $series Points with label + visitors.
	 */
	private static function chart( $series ) {
		if ( empty( $series ) ) {
			return;
		}
		$max = 1;
		foreach ( $series as $p ) {
			$max = max( $max, (int) $p['visitors'] );
		}
		$count = count( $series );
		$w     = 100 / $count;
		echo '<svg class="tacilytics-chart" viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Visitors per day', 'tacilytics' ) . '">';
		echo '<rect class="tacilytics-baseline" x="0" y="39.5" width="100" height="0.5" />';
		foreach ( array_values( $series ) as $i => $p ) {
			if ( (int) $p['visitors'] <= 0 ) {
				continue; // An empty day is just the baseline.
			}
			$h = max( 0.8, 34 * (int) $p['visitors'] / $max ); // Headroom above the tallest bar.
			printf(
				'<rect x="%1$s" y="%2$s" width="%3$s" height="%4$s" rx="0.8"><title>%5$s: %6$s</title></rect>',
				esc_attr( round( $i * $w + $w * 0.15, 2 ) ),
				esc_attr( round( 40 - $h, 2 ) ),
				esc_attr( round( $w * 0.7, 2 ) ),
				esc_attr( round( $h, 2 ) ),
				esc_html( $p['label'] ),
				esc_html( number_format_i18n( (int) $p['visitors'] ) )
			);
		}
		echo '</svg>';
	}

	/**
	 * Revenue with the shop's currency when WooCommerce knows it (Tacilytics stores the amount only).
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		$num = number_format_i18n( (float) $amount, 2 );
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) . ' ' . $num;
		}
		return $num;
	}

	/**
	 * A short ranked list.
	 *
	 * @param string $title Heading.
	 * @param array  $rows  name + visitors.
	 */
	private static function top_list( $title, $rows ) {
		echo '<div><h3>' . esc_html( $title ) . '</h3>';
		if ( empty( $rows ) ) {
			echo '<p class="tacilytics-empty">' . esc_html__( 'No data yet', 'tacilytics' ) . '</p></div>';
			return;
		}
		echo '<ol>';
		foreach ( $rows as $r ) {
			printf( '<li><span title="%1$s">%1$s</span><b>%2$s</b></li>', esc_attr( $r['name'] ), esc_html( number_format_i18n( $r['visitors'] ) ) );
		}
		echo '</ol></div>';
	}
}
