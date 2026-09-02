<?php
/**
 * Live FX rate fetching, transient caching, and markup buffer.
 *
 * @package Gcc_Currency_Switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GCC_CS_FX_Rates.
 *
 * Fetches live exchange rates (base USD) from a public API, caches them in a
 * transient, applies an admin-configurable markup, and falls back to the
 * static defaults from the Currency Matrix when the remote fetch fails.
 */
class GCC_CS_FX_Rates {

	/**
	 * Transient key used to cache fetched rates.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'gcc_fx_rates';

	/**
	 * Remote API endpoint (base currency USD).
	 *
	 * @var string
	 */
	const API_URL = 'https://api.exchangerate.host/latest?base=USD';

	/**
	 * Fetch (or retrieve cached) exchange rates, base USD.
	 *
	 * @return array Currency code => rate.
	 */
	public function get_rates() {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$rates = $this->fetch_remote_rates();

		if ( empty( $rates ) ) {
			$rates = GCC_CS_Geolocation::get_default_fx_rates();
		}

		$duration_hours = (int) apply_filters( 'gcc_cs_fx_cache_duration_hours', 6 );
		set_transient( self::TRANSIENT_KEY, $rates, $duration_hours * HOUR_IN_SECONDS );

		return $rates;
	}

	/**
	 * Get the rate for a specific currency (base USD), including markup.
	 *
	 * @param string $currency_code Currency code, e.g. 'AED'.
	 * @return float
	 */
	public function get_rate( $currency_code ) {
		$currency_code = strtoupper( sanitize_text_field( $currency_code ) );

		if ( 'USD' === $currency_code ) {
			return 1.0;
		}

		$rates = $this->get_rates();
		$rate  = isset( $rates[ $currency_code ] ) ? (float) $rates[ $currency_code ] : 0.0;

		if ( $rate <= 0 ) {
			$defaults = GCC_CS_Geolocation::get_default_fx_rates();
			$rate     = isset( $defaults[ $currency_code ] ) ? (float) $defaults[ $currency_code ] : 1.0;
		}

		$markup = $this->get_markup_percent();

		if ( $markup > 0 ) {
			$rate = $rate * ( 1 + ( $markup / 100 ) );
		}

		return (float) apply_filters( 'gcc_cs_fx_rate', $rate, $currency_code );
	}

	/**
	 * Get the admin-configured markup/buffer percentage.
	 *
	 * @return float
	 */
	public function get_markup_percent() {
		$options = get_option( 'gcc_cs_settings', array() );
		$markup  = isset( $options['markup_percent'] ) ? (float) $options['markup_percent'] : 2.0;

		return (float) apply_filters( 'gcc_cs_fx_markup_percent', $markup );
	}

	/**
	 * Attempt to fetch live rates from the remote API.
	 *
	 * @return array Empty array on failure.
	 */
	private function fetch_remote_rates() {
		$url = apply_filters( 'gcc_cs_fx_api_url', self::API_URL );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 8,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || empty( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
			return array();
		}

		$supported = array_merge( array( 'USD' ), GCC_CS_Geolocation::get_supported_currencies() );
		$rates     = array();

		foreach ( $supported as $currency ) {
			if ( isset( $data['rates'][ $currency ] ) && is_numeric( $data['rates'][ $currency ] ) ) {
				$rates[ $currency ] = (float) $data['rates'][ $currency ];
			}
		}

		if ( empty( $rates ) ) {
			return array();
		}

		return $rates;
	}

	/**
	 * Manually clear the cached FX rate transient.
	 *
	 * @return void
	 */
	public function clear_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}
}
