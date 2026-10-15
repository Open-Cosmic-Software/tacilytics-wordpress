<?php
/**
 * Settings page (Settings → Tacilytics): first-run choice between self-hosted and Cloud,
 * then the regular settings.
 *
 * @package Tacilytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
final class Tacilytics_Admin {

	const PAGE = 'tacilytics';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_action( 'admin_post_tacilytics_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_tacilytics_reset', array( __CLASS__, 'handle_reset' ) );
	}

	/**
	 * Settings → Tacilytics.
	 */
	public static function menu() {
		add_options_page(
			__( 'Tacilytics', 'tacilytics' ),
			__( 'Tacilytics', 'tacilytics' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option with its sanitiser.
	 */
	public static function register() {
		register_setting(
			'tacilytics',
			Tacilytics_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Tacilytics_Settings', 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => Tacilytics_Settings::defaults(),
			)
		);
	}

	/**
	 * Open the settings once after activation (not for bulk activation or network admin).
	 */
	public static function activation_redirect() {
		if ( ! get_transient( 'tacilytics_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'tacilytics_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a core flag.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * CSS only on our page.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook && 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'tacilytics-admin', TACILYTICS_URL . 'assets/admin.css', array(), TACILYTICS_VERSION );
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_script( 'tacilytics-admin', TACILYTICS_URL . 'assets/admin.js', array(), TACILYTICS_VERSION, true );
		}
	}

	/**
	 * Remind admins until the plugin is set up.
	 */
	public static function setup_notice() {
		if ( Tacilytics_Settings::is_ready() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'settings_page_' . self::PAGE === $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Tacilytics is installed but not counting yet.', 'tacilytics' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Finish the setup', 'tacilytics' )
		);
	}

	/**
	 * Connection test (button on the settings page).
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'tacilytics' ), 403 );
		}
		check_admin_referer( 'tacilytics_test' );
		Tacilytics_Api::flush_cache();
		$result = array();
		$t      = Tacilytics_Api::check_tracker();
		$result[] = is_wp_error( $t ) ? array( 'error', $t->get_error_message() ) : array( 'success', __( 'Tracker script reachable.', 'tacilytics' ) );
		if ( '' !== (string) Tacilytics_Settings::value( 'api_key' ) ) {
			$s        = Tacilytics_Api::stats( 'today' );
			$result[] = is_wp_error( $s )
				? array( 'error', $s->get_error_message() )
				/* translators: %d: visitors today */
				: array( 'success', sprintf( __( 'API key works: %d visitors today.', 'tacilytics' ), (int) $s['visitors'] ) );
		}
		set_transient( 'tacilytics_test_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Back to the first-run choice (keeps nothing secret behind).
	 */
	public static function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'tacilytics' ), 403 );
		}
		check_admin_referer( 'tacilytics_reset' );
		Tacilytics_Api::flush_cache();
		$s            = Tacilytics_Settings::get();
		$s['mode']    = '';
		$s['api_key'] = '';
		update_option( Tacilytics_Settings::OPTION, $s, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Page output.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Tacilytics_Settings::get();
		echo '<div class="wrap tacilytics-wrap">';
		echo '<h1 class="tacilytics-title"><span class="tacilytics-logo" aria-hidden="true"></span>' . esc_html__( 'Tacilytics', 'tacilytics' ) . '</h1>';
		settings_errors( Tacilytics_Settings::OPTION );
		self::render_test_result();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only switches which form is shown.
		$choose = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		if ( '' === $s['mode'] && ! in_array( $choose, array( 'self', 'cloud' ), true ) ) {
			self::render_choice();
		} elseif ( '' === $s['mode'] ) {
			self::render_setup( $choose, $s );
		} else {
			self::render_settings( $s );
		}
		echo '</div>';
	}

	/**
	 * Show the result of the last connection test.
	 */
	private static function render_test_result() {
		$key = 'tacilytics_test_' . get_current_user_id();
		$res = get_transient( $key );
		if ( ! is_array( $res ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $res as $row ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $row[0] ), esc_html( $row[1] ) );
		}
	}

	/**
	 * Step 1: choose self-hosted or Cloud.
	 */
	private static function render_choice() {
		$base = admin_url( 'options-general.php?page=' . self::PAGE );
		?>
		<p class="tacilytics-lead"><?php esc_html_e( 'Cookieless analytics: no cookies, no IP addresses stored, no personal profiles. Where should your statistics live?', 'tacilytics' ); ?></p>
		<div class="tacilytics-choices">
			<a class="tacilytics-choice" href="<?php echo esc_url( add_query_arg( 'mode', 'self', $base ) ); ?>">
				<span class="tacilytics-choice-icon dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Self-hosted', 'tacilytics' ); ?></strong>
				<span><?php esc_html_e( 'You run Tacilytics on your own server (open source, MIT). Free forever, your data never leaves your server.', 'tacilytics' ); ?></span>
				<span class="tacilytics-choice-cta"><?php esc_html_e( 'Connect my server →', 'tacilytics' ); ?></span>
			</a>
			<a class="tacilytics-choice tacilytics-choice-cloud" href="<?php echo esc_url( add_query_arg( 'mode', 'cloud', $base ) ); ?>">
				<span class="tacilytics-choice-icon dashicons dashicons-cloud" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Tacilytics Cloud', 'tacilytics' ); ?></strong>
				<span><?php esc_html_e( 'We host it for you in Germany. Nothing to install or maintain.', 'tacilytics' ); ?></span>
				<span class="tacilytics-price"><?php esc_html_e( '90 days free, then €1.99 per site per month', 'tacilytics' ); ?></span>
				<span class="tacilytics-choice-cta"><?php esc_html_e( 'Use Tacilytics Cloud →', 'tacilytics' ); ?></span>
			</a>
		</div>
		<?php
	}

	/**
	 * Step 2: connect.
	 *
	 * @param string $mode self|cloud.
	 * @param array  $s    Settings.
	 */
	private static function render_setup( $mode, $s ) {
		$back = admin_url( 'options-general.php?page=' . self::PAGE );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back', 'tacilytics' ) . '</a></p>';

		if ( 'cloud' === $mode ) {
			self::render_cloud_setup( $s );
			return;
		}
		?>
		<div class="tacilytics-card">
			<h2><?php esc_html_e( 'Connect your Tacilytics server', 'tacilytics' ); ?></h2>
			<ol class="tacilytics-steps">
				<li><?php esc_html_e( 'In your Tacilytics dashboard, add this site (the domain below).', 'tacilytics' ); ?></li>
				<li><?php esc_html_e( 'Enter the address of your dashboard here and save.', 'tacilytics' ); ?></li>
			</ol>
			<form method="post" action="options.php">
				<?php settings_fields( 'tacilytics' ); ?>
				<input type="hidden" name="<?php echo esc_attr( Tacilytics_Settings::OPTION ); ?>[mode]" value="self">
				<table class="form-table" role="presentation">
					<?php self::field_server_url( $s ); ?>
					<?php self::field_domain( $s ); ?>
				</table>
				<?php submit_button( __( 'Save and start counting', 'tacilytics' ) ); ?>
			</form>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the GitHub repository */
					esc_html__( 'No server yet? Tacilytics is open source: %s', 'tacilytics' ),
					'<a href="https://github.com/Open-Cosmic-Software/tacilytics" target="_blank" rel="noopener noreferrer">github.com/Open-Cosmic-Software/tacilytics</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Cloud setup. The Cloud service is not open yet, so this explains and stays honest.
	 *
	 * @param array $s Settings.
	 */
	private static function render_cloud_setup( $s ) {
		/**
		 * Whether Tacilytics Cloud accepts sign-ups. Flipped in a later plugin release once
		 * the service is live; until then the plugin never contacts the Cloud.
		 *
		 * @param bool $open Cloud open for sign-ups.
		 */
		$open = (bool) apply_filters( 'tacilytics_cloud_open', false );
		?>
		<div class="tacilytics-card">
			<h2><?php esc_html_e( 'Tacilytics Cloud', 'tacilytics' ); ?></h2>
			<ul class="tacilytics-checks">
				<li><?php esc_html_e( 'Hosted in Germany, data processing agreement included', 'tacilytics' ); ?></li>
				<li><?php esc_html_e( 'Up to 50,000 page views per site and month for €1.99', 'tacilytics' ); ?></li>
				<li><?php esc_html_e( 'First 90 days free, no credit card needed to start', 'tacilytics' ); ?></li>
				<li><?php esc_html_e( 'Cancel any time, export your data as CSV', 'tacilytics' ); ?></li>
			</ul>
			<?php if ( ! $open ) : ?>
				<p class="tacilytics-soon"><strong><?php esc_html_e( 'Coming soon.', 'tacilytics' ); ?></strong> <?php esc_html_e( 'Tacilytics Cloud is opening shortly. Until then you can use your own server, it takes about five minutes.', 'tacilytics' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'mode', 'self', admin_url( 'options-general.php?page=' . self::PAGE ) ) ); ?>"><?php esc_html_e( 'Use my own server instead', 'tacilytics' ); ?></a></p>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'tacilytics' ); ?>
					<input type="hidden" name="<?php echo esc_attr( Tacilytics_Settings::OPTION ); ?>[mode]" value="cloud">
					<table class="form-table" role="presentation">
						<?php self::field_domain( $s ); ?>
					</table>
					<p class="description"><?php esc_html_e( 'After saving, create your free Cloud account with this domain. Your API key is shown in the Cloud dashboard.', 'tacilytics' ); ?></p>
					<?php submit_button( __( 'Connect to Tacilytics Cloud', 'tacilytics' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Full settings once connected.
	 *
	 * @param array $s Settings.
	 */
	private static function render_settings( $s ) {
		$dash  = Tacilytics_Settings::server_base();
		$opt   = Tacilytics_Settings::OPTION;
		$roles = wp_roles()->get_names();
		?>
		<div class="tacilytics-status">
			<span class="tacilytics-dot" aria-hidden="true"></span>
			<?php
			printf(
				/* translators: 1: domain, 2: server */
				esc_html__( 'Counting %1$s on %2$s', 'tacilytics' ),
				'<strong>' . esc_html( $s['domain'] ) . '</strong>',
				'<a href="' . esc_url( $dash ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_parse_url( $dash, PHP_URL_HOST ) ) . '</a>'
			);
			?>
			<span class="tacilytics-mode"><?php echo 'cloud' === $s['mode'] ? esc_html__( 'Cloud', 'tacilytics' ) : esc_html__( 'Self-hosted', 'tacilytics' ); ?></span>
		</div>

		<form method="post" action="options.php" class="tacilytics-card">
			<?php settings_fields( 'tacilytics' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[mode]" value="<?php echo esc_attr( $s['mode'] ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[_form]" value="settings">
			<h2><?php esc_html_e( 'Connection', 'tacilytics' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				if ( 'self' === $s['mode'] ) {
					self::field_server_url( $s );
				}
				self::field_domain( $s );
				?>
				<tr>
					<th scope="row"><label for="tacilytics-api-key"><?php esc_html_e( 'API key (optional)', 'tacilytics' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="tacilytics-api-key" name="<?php echo esc_attr( $opt ); ?>[api_key]" value="<?php echo esc_attr( Tacilytics_Settings::mask( $s['api_key'] ) ); ?>" autocomplete="off" spellcheck="false" placeholder="tk_…">
						<p class="description"><?php esc_html_e( 'Shows your numbers on the WordPress dashboard. Create a read-only key in Tacilytics under Settings → Security → API keys. The key stays on your server and is never sent to visitors.', 'tacilytics' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'What to count', 'tacilytics' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic events', 'tacilytics' ); ?></th>
					<td><fieldset>
						<?php
						self::checkbox( 'track_outbound', __( 'Clicks on links to other websites', 'tacilytics' ), $s );
						self::checkbox( 'track_downloads', __( 'File downloads (PDF, ZIP, …)', 'tacilytics' ), $s );
						self::checkbox( 'track_search', __( 'Site search: search terms and whether there were results', 'tacilytics' ), $s );
						self::checkbox( 'track_404', __( 'Broken links (404 pages)', 'tacilytics' ), $s );
						if ( class_exists( 'WooCommerce' ) ) {
							self::checkbox( 'woocommerce', __( 'WooCommerce purchases with revenue (no customer data)', 'tacilytics' ), $s );
						}
						?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Do not count', 'tacilytics' ); ?></th>
					<td><fieldset class="tacilytics-roles">
						<?php foreach ( $roles as $role => $label ) : ?>
							<label class="tacilytics-role"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[exclude_roles][]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, (array) $s['exclude_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $label ) ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Visits of logged-in users with these roles are not counted, also on cached pages. The browser is remembered until the user logs in with another role.', 'tacilytics' ); ?></p>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Privacy', 'tacilytics' ); ?></th>
					<td><fieldset>
						<?php self::checkbox( 'honor_dnt', __( 'Respect "Do Not Track" and Global Privacy Control', 'tacilytics' ), $s ); ?>
					</fieldset></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<div class="tacilytics-card tacilytics-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tacilytics_test">
				<?php wp_nonce_field( 'tacilytics_test' ); ?>
				<?php submit_button( __( 'Test connection', 'tacilytics' ), 'secondary', 'submit', false ); ?>
			</form>
			<a class="button" href="<?php echo esc_url( $dash ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open dashboard', 'tacilytics' ); ?></a>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tacilytics-reset" data-confirm="<?php echo esc_attr__( 'Disconnect this site? Tracking stops until you set it up again. Data on the server is not deleted.', 'tacilytics' ); ?>">
				<input type="hidden" name="action" value="tacilytics_reset">
				<?php wp_nonce_field( 'tacilytics_reset' ); ?>
				<?php submit_button( __( 'Disconnect', 'tacilytics' ), 'link-delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Server URL row.
	 *
	 * @param array $s Settings.
	 */
	private static function field_server_url( $s ) {
		?>
		<tr>
			<th scope="row"><label for="tacilytics-server"><?php esc_html_e( 'Server address', 'tacilytics' ); ?></label></th>
			<td>
				<input type="url" class="regular-text code" id="tacilytics-server" name="<?php echo esc_attr( Tacilytics_Settings::OPTION ); ?>[server_url]" value="<?php echo esc_attr( $s['server_url'] ); ?>" placeholder="https://analytics.example.com" required>
				<p class="description"><?php esc_html_e( 'The address of your Tacilytics dashboard.', 'tacilytics' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Domain row.
	 *
	 * @param array $s Settings.
	 */
	private static function field_domain( $s ) {
		?>
		<tr>
			<th scope="row"><label for="tacilytics-domain"><?php esc_html_e( 'Site domain', 'tacilytics' ); ?></label></th>
			<td>
				<input type="text" class="regular-text code" id="tacilytics-domain" name="<?php echo esc_attr( Tacilytics_Settings::OPTION ); ?>[domain]" value="<?php echo esc_attr( $s['domain'] ); ?>" required>
				<p class="description"><?php esc_html_e( 'Must match the site in Tacilytics exactly (without www.).', 'tacilytics' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Checkbox line.
	 *
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 */
	private static function checkbox( $key, $label, $s ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label><br>',
			esc_attr( Tacilytics_Settings::OPTION ),
			esc_attr( $key ),
			checked( ! empty( $s[ $key ] ), true, false ),
			esc_html( $label )
		);
	}
}
