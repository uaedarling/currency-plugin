<?php
/**
 * Geolocation detection and currency cookie management.
 *
 * @package Gcc_Currency_Switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GCC_CS_Geolocation.
 *
 * Detects the visitor's country and resolves the target currency for the
 * Currency Matrix defined in the plugin specification.
 */
class GCC_CS_Geolocation {

	/**
	 * Name of the cookie used to persist the resolved currency.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'gcc_currency';

	/**
	 * Country => currency map (Currency Matrix).
	 *
	 * @var array
	 */
	private static $country_currency_map = array(
		'AE' => 'AED',
		'SA' => 'SAR',
		'QA' => 'QAR',
		'KW' => 'KWD',
		'BH' => 'BHD',
		'OM' => 'OMR',
		'JO' => 'JOD',
	);

	/**
	 * Currency => decimal places map.
	 *
	 * @var array
	 */
	private static $currency_decimals = array(
		'AED' => 2,
		'SAR' => 2,
		'QAR' => 2,
		'KWD' => 3,
		'BHD' => 3,
		'OMR' => 3,
		'JOD' => 3,
		'USD' => 2,
	);

	/**
	 * Default fallback FX rates versus USD.
	 *
	 * @var array
	 */
	private static $default_fx_rates = array(
		'AED' => 3.67,
		'SAR' => 3.75,
		'QAR' => 3.64,
		'KWD' => 0.31,
		'BHD' => 0.38,
		'OMR' => 0.38,
		'JOD' => 0.71,
		'USD' => 1.00,
	);

	/**
	 * Cached resolved currency for the current request.
	 *
	 * @var string|null
	 */
	private $resolved_currency = null;

	/**
	 * Get the supported country => currency map.
	 *
	 * @return array
	 */
	public static function get_country_currency_map() {
		return apply_filters( 'gcc_cs_country_currency_map', self::$country_currency_map );
	}

	/**
	 * Get the currency => decimal places map.
	 *
	 * @return array
	 */
	public static function get_currency_decimals_map() {
		return apply_filters( 'gcc_cs_currency_decimals_map', self::$currency_decimals );
	}

	/**
	 * Get the default fallback FX rates map (vs USD).
	 *
	 * @return array
	 */
	public static function get_default_fx_rates() {
		return apply_filters( 'gcc_cs_default_fx_rates', self::$default_fx_rates );
	}

	/**
	 * Get the number of decimal places for a given currency.
	 *
	 * @param string $currency Currency code.
	 * @return int
	 */
	public static function get_decimals_for_currency( $currency ) {
		$map = self::get_currency_decimals_map();
		return isset( $map[ $currency ] ) ? (int) $map[ $currency ] : 2;
	}

	/**
	 * List of currencies supported by the plugin (excluding USD default).
	 *
	 * @return array
	 */
	public static function get_supported_currencies() {
		return array_values( self::get_country_currency_map() );
	}

	/**
	 * Detect the visitor's ISO 3166-1 alpha-2 country code.
	 *
	 * Priority: Cloudflare header -> WooCommerce geolocation -> filterable default.
	 *
	 * @return string
	 */
	public function detect_country_code() {
		$country = '';

		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$country = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		}

		if ( empty( $country ) && class_exists( 'WC_Geolocation' ) ) {
			$location = WC_Geolocation::geolocate_ip();
			if ( ! empty( $location['country'] ) ) {
				$country = $location['country'];
			}
		}

		if ( empty( $country ) ) {
			$country = apply_filters( 'gcc_cs_default_country', 'US' );
		}

		$country = strtoupper( sanitize_text_field( $country ) );

		return apply_filters( 'gcc_cs_detected_country', $country );
	}

	/**
	 * Map a country code to its target currency, defaulting to USD.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code.
	 * @return string
	 */
	public function map_country_to_currency( $country_code ) {
		$map      = self::get_country_currency_map();
		$currency = isset( $map[ $country_code ] ) ? $map[ $country_code ] : 'USD';

		return apply_filters( 'gcc_cs_mapped_currency', $currency, $country_code );
	}

	/**
	 * Validate a currency code against the currencies supported by this plugin.
	 *
	 * @param string $currency Currency code to validate.
	 * @return bool
	 */
	public function is_valid_currency( $currency ) {
		$valid = array_merge( array( 'USD' ), self::get_supported_currencies() );
		return in_array( $currency, $valid, true );
	}

	/**
	 * Resolve the currency for the current visitor.
	 *
	 * Manual overrides (cookie set via the dropdown) take precedence over
	 * geolocation on subsequent requests.
	 *
	 * @return string
	 */
	public function get_current_currency() {
		if ( null !== $this->resolved_currency ) {
			return $this->resolved_currency;
		}

		$currency = '';

		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
			$cookie_value = strtoupper( $cookie_value );

			if ( $this->is_valid_currency( $cookie_value ) ) {
				$currency = $cookie_value;
			}
		}

		if ( empty( $currency ) ) {
			$country  = $this->detect_country_code();
			$currency = $this->map_country_to_currency( $country );
			$this->set_currency_cookie( $currency );
		}

		$this->resolved_currency = $currency;

		return $this->resolved_currency;
	}

	/**
	 * Manually override the visitor's currency (e.g. from the dropdown) and persist it.
	 *
	 * @param string $currency Currency code to set.
	 * @return bool True on success, false if the currency is invalid.
	 */
	public function set_currency_override( $currency ) {
		$currency = strtoupper( sanitize_text_field( $currency ) );

		if ( ! $this->is_valid_currency( $currency ) ) {
			return false;
		}

		$this->set_currency_cookie( $currency );
		$this->resolved_currency = $currency;

		return true;
	}

	/**
	 * Persist the resolved currency in a cookie.
	 *
	 * @param string $currency Currency code.
	 * @return void
	 */
	private function set_currency_cookie( $currency ) {
		if ( headers_sent() ) {
			return;
		}

		$duration_days = (int) apply_filters( 'gcc_cs_cookie_duration_days', 30 );
		$expire        = time() + ( $duration_days * DAY_IN_SECONDS );

		setcookie(
			self::COOKIE_NAME,
			$currency,
			array(
				'expires'  => $expire,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE_NAME ] = $currency;
	}
}
