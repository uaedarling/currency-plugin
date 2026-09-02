<?php
/**
 * Plugin lifecycle and hooks registration.
 *
 * @package Gcc_Currency_Switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GCC_CS_Core.
 *
 * Bootstraps all plugin services, registers front-end assets, the currency
 * switcher shortcode/hook, and the admin settings page.
 */
class GCC_CS_Core {

	/**
	 * Singleton instance.
	 *
	 * @var GCC_CS_Core|null
	 */
	private static $instance = null;

	/**
	 * Geolocation service.
	 *
	 * @var GCC_CS_Geolocation
	 */
	public $geolocation;

	/**
	 * FX rates service.
	 *
	 * @var GCC_CS_FX_Rates
	 */
	public $fx_rates;

	/**
	 * Price converter service.
	 *
	 * @var GCC_CS_Price_Converter
	 */
	public $price_converter;

	/**
	 * Checkout service.
	 *
	 * @var GCC_CS_Checkout
	 */
	public $checkout;

	/**
	 * Admin metabox service.
	 *
	 * @var GCC_CS_Admin_Metabox
	 */
	public $admin_metabox;

	/**
	 * Cache compatibility service.
	 *
	 * @var GCC_CS_Cache_Compat
	 */
	public $cache_compat;

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return GCC_CS_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Sets up services and hooks.
	 */
	private function __construct() {
		$this->geolocation     = new GCC_CS_Geolocation();
		$this->fx_rates        = new GCC_CS_FX_Rates();
		$this->price_converter = new GCC_CS_Price_Converter( $this->geolocation, $this->fx_rates );
		$this->checkout        = new GCC_CS_Checkout( $this->geolocation );
		$this->cache_compat    = new GCC_CS_Cache_Compat( $this->geolocation, $this->fx_rates );

		if ( is_admin() ) {
			$this->admin_metabox = new GCC_CS_Admin_Metabox();
		}

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'gcc_currency_switcher', array( $this, 'render_switcher' ) );
		add_action( 'gcc_currency_switcher', array( $this, 'render_switcher_action' ) );

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Load the plugin's translation files.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'gcc-currency-switcher',
			false,
			dirname( GCC_CS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Enqueue front-end assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'gcc-cs-switcher',
			GCC_CS_PLUGIN_URL . 'assets/css/switcher.css',
			array(),
			GCC_CS_VERSION
		);

		wp_enqueue_script(
			'gcc-cs-switcher',
			GCC_CS_PLUGIN_URL . 'assets/js/switcher.js',
			array(),
			GCC_CS_VERSION,
			true
		);

		wp_localize_script(
			'gcc-cs-switcher',
			'gccCsSettings',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'gcc_cs_cache_nonce' ),
				'getAction'       => GCC_CS_Cache_Compat::ACTION,
				'setAction'       => GCC_CS_Cache_Compat::SET_ACTION,
				'currentCurrency' => $this->geolocation->get_current_currency(),
			)
		);
	}

	/**
	 * Render the currency switcher dropdown markup.
	 *
	 * @return string
	 */
	public function get_switcher_markup() {
		$currencies = array_merge( array( 'USD' ), GCC_CS_Geolocation::get_supported_currencies() );
		$current    = $this->geolocation->get_current_currency();

		ob_start();
		?>
		<div class="gcc-cs-switcher">
			<label class="screen-reader-text" for="gcc-cs-currency-select">
				<?php esc_html_e( 'Currency', 'gcc-currency-switcher' ); ?>
			</label>
			<select id="gcc-cs-currency-select" class="gcc-cs-currency-select">
				<?php foreach ( $currencies as $currency ) : ?>
					<option value="<?php echo esc_attr( $currency ); ?>" <?php selected( $current, $currency ); ?>>
						<?php echo esc_html( $currency ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode callback: [gcc_currency_switcher].
	 *
	 * @return string
	 */
	public function render_switcher() {
		return $this->get_switcher_markup();
	}

	/**
	 * Action hook callback so themes can call do_action( 'gcc_currency_switcher' ).
	 *
	 * @return void
	 */
	public function render_switcher_action() {
		echo $this->get_switcher_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built and escaped in get_switcher_markup().
	}

	/**
	 * Register the Settings > Currency Switcher admin page.
	 *
	 * @return void
	 */
	public function register_settings_page() {
		add_options_page(
			__( 'Currency Switcher', 'gcc-currency-switcher' ),
			__( 'Currency Switcher', 'gcc-currency-switcher' ),
			'manage_woocommerce',
			'gcc-currency-switcher',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'gcc_cs_settings_group',
			'gcc_cs_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw posted settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['markup_percent'] = isset( $input['markup_percent'] ) && is_numeric( $input['markup_percent'] )
			? max( 0, (float) $input['markup_percent'] )
			: 2.0;

		$enabled_countries = array();
		if ( isset( $input['enabled_countries'] ) && is_array( $input['enabled_countries'] ) ) {
			foreach ( $input['enabled_countries'] as $country ) {
				$enabled_countries[] = strtoupper( sanitize_text_field( $country ) );
			}
		}
		$sanitized['enabled_countries'] = $enabled_countries;

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$options    = get_option( 'gcc_cs_settings', array() );
		$markup     = isset( $options['markup_percent'] ) ? $options['markup_percent'] : 2.0;
		$enabled    = isset( $options['enabled_countries'] ) ? (array) $options['enabled_countries'] : array_keys( GCC_CS_Geolocation::get_country_currency_map() );
		$all_countries = GCC_CS_Geolocation::get_country_currency_map();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GCC & Jordan Currency Switcher Settings', 'gcc-currency-switcher' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gcc_cs_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="gcc_cs_markup_percent"><?php esc_html_e( 'FX Markup / Buffer (%)', 'gcc-currency-switcher' ); ?></label></th>
						<td>
							<input type="number" step="0.1" min="0" id="gcc_cs_markup_percent" name="gcc_cs_settings[markup_percent]" value="<?php echo esc_attr( $markup ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled Countries', 'gcc-currency-switcher' ); ?></th>
						<td>
							<?php foreach ( $all_countries as $code => $currency ) : ?>
								<label style="display:block;">
									<input type="checkbox" name="gcc_cs_settings[enabled_countries][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $enabled, true ) ); ?> />
									<?php echo esc_html( $code . ' - ' . $currency ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
