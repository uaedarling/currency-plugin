<?php
/**
 * AJAX endpoint for full-page cache compatibility.
 *
 * @package Gcc_Currency_Switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GCC_CS_Cache_Compat.
 *
 * Exposes a lightweight, nonce-protected admin-ajax endpoint that returns the
 * currently resolved currency, symbol, and decimal rule as JSON, so pages
 * served from full-page cache (Varnish, WP Rocket, Cloudflare cache, etc.)
 * can be synced client-side after page load without needing to bust the
 * page cache itself.
 */
class GCC_CS_Cache_Compat {

	/**
	 * AJAX action name.
	 *
	 * @var string
	 */
	const ACTION = 'gcc_cs_get_currency';

	/**
	 * AJAX action name for setting a manual currency override.
	 *
	 * @var string
	 */
	const SET_ACTION = 'gcc_cs_set_currency';

	/**
	 * Geolocation service instance.
	 *
	 * @var GCC_CS_Geolocation
	 */
	private $geolocation;

	/**
	 * FX rates service instance.
	 *
	 * @var GCC_CS_FX_Rates
	 */
	private $fx_rates;

	/**
	 * Constructor.
	 *
	 * @param GCC_CS_Geolocation $geolocation Geolocation service.
	 * @param GCC_CS_FX_Rates    $fx_rates    FX rate service.
	 */
	public function __construct( GCC_CS_Geolocation $geolocation, GCC_CS_FX_Rates $fx_rates ) {
		$this->geolocation = $geolocation;
		$this->fx_rates    = $fx_rates;

		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_get_currency' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle_get_currency' ) );

		add_action( 'wp_ajax_' . self::SET_ACTION, array( $this, 'handle_set_currency' ) );
		add_action( 'wp_ajax_nopriv_' . self::SET_ACTION, array( $this, 'handle_set_currency' ) );
	}

	/**
	 * Return the resolved currency, symbol, and decimal rule as JSON.
	 *
	 * Relies only on the cookie/header/IP resolution in the geolocation
	 * class so it works safely regardless of session availability in a
	 * cached context.
	 *
	 * @return void
	 */
	public function handle_get_currency() {
		check_ajax_referer( 'gcc_cs_cache_nonce', 'nonce' );

		$currency = $this->geolocation->get_current_currency();
		$decimals = GCC_CS_Geolocation::get_decimals_for_currency( $currency );
		$symbol   = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol( $currency )
			: $currency;

		wp_send_json_success(
			array(
				'currency' => $currency,
				'symbol'   => $symbol,
				'decimals' => $decimals,
				'rate'     => $this->fx_rates->get_rate( $currency ),
			)
		);
	}

	/**
	 * Set a manual currency override (from the dropdown) and echo the result.
	 *
	 * @return void
	 */
	public function handle_set_currency() {
		check_ajax_referer( 'gcc_cs_cache_nonce', 'nonce' );

		$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : '';
		$currency = strtoupper( $currency );

		$success = $this->geolocation->set_currency_override( $currency );

		if ( ! $success ) {
			wp_send_json_error( array( 'message' => __( 'Invalid currency.', 'gcc-currency-switcher' ) ) );
		}

		wp_send_json_success(
			array(
				'currency' => $currency,
				'decimals' => GCC_CS_Geolocation::get_decimals_for_currency( $currency ),
			)
		);
	}
}
