<?php
/**
 * WooCommerce price filters, charm rounding, and per-currency overrides.
 *
 * @package Gcc_Currency_Switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GCC_CS_Price_Converter.
 *
 * Converts base USD product prices into the visitor's resolved currency,
 * applies charm (psychological) rounding, honours fixed per-product/currency
 * overrides, and keeps wc_price() output aligned with the resolved currency.
 */
class GCC_CS_Price_Converter {

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

		$this->hooks();
	}

	/**
	 * Register WooCommerce hooks.
	 *
	 * @return void
	 */
	private function hooks() {
		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_sale_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'filter_sale_price' ), 20, 2 );

		add_filter( 'woocommerce_currency', array( $this, 'filter_currency' ) );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'filter_currency_symbol' ), 10, 2 );
		add_filter( 'wc_get_price_decimals', array( $this, 'filter_price_decimals' ) );
	}

	/**
	 * Get the currency currently resolved for the visitor.
	 *
	 * @return string
	 */
	private function current_currency() {
		return $this->geolocation->get_current_currency();
	}

	/**
	 * Filter a regular/base price for the resolved currency.
	 *
	 * @param string     $price   Price in USD.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	public function filter_price( $price, $product ) {
		if ( '' === $price || null === $price ) {
			return $price;
		}

		return $this->convert_price( $price, $product, 'regular' );
	}

	/**
	 * Filter a sale price for the resolved currency.
	 *
	 * @param string     $price   Price in USD.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	public function filter_sale_price( $price, $product ) {
		if ( '' === $price || null === $price ) {
			return $price;
		}

		return $this->convert_price( $price, $product, 'sale' );
	}

	/**
	 * Convert a USD price to the resolved currency, honouring fixed overrides.
	 *
	 * @param string     $price   Base USD price.
	 * @param WC_Product $product Product object.
	 * @param string     $type    Either 'regular' or 'sale'.
	 * @return string
	 */
	private function convert_price( $price, $product, $type ) {
		$currency = $this->current_currency();

		if ( 'USD' === $currency ) {
			return $price;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $price;
		}

		$override = $this->get_price_override( $product, $currency, $type );

		if ( null !== $override ) {
			return (string) $override;
		}

		$rate      = $this->fx_rates->get_rate( $currency );
		$converted = (float) $price * $rate;
		$rounded   = $this->apply_charm_rounding( $converted, $currency );

		return (string) $rounded;
	}

	/**
	 * Fetch a fixed manual price override for a product/currency/type, if set.
	 *
	 * @param WC_Product $product  Product object.
	 * @param string     $currency Currency code.
	 * @param string     $type     Either 'regular' or 'sale'.
	 * @return float|null
	 */
	private function get_price_override( $product, $currency, $type ) {
		$meta_key = 'sale' === $type
			? '_gcc_sale_price_override_' . $currency
			: '_gcc_price_override_' . $currency;

		$product_id = $product->get_id();
		$value      = get_post_meta( $product_id, $meta_key, true );

		if ( '' === $value || null === $value || false === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Apply psychological ("charm") rounding to a converted price.
	 *
	 * Rounds to the nearest whole unit and then applies a configurable charm
	 * ending (default .99 for 2-decimal currencies, .999 for 3-decimal ones),
	 * always respecting the currency's decimal-place rule.
	 *
	 * @param float  $amount   Converted price.
	 * @param string $currency Currency code.
	 * @return float
	 */
	public function apply_charm_rounding( $amount, $currency ) {
		$decimals = GCC_CS_Geolocation::get_decimals_for_currency( $currency );
		$rule     = apply_filters( 'gcc_cs_charm_rounding_rule', 0.99, $currency, $decimals );

		if ( false === $rule || null === $rule ) {
			return round( $amount, $decimals );
		}

		if ( $amount <= 0 ) {
			return round( $amount, $decimals );
		}

		$whole  = floor( $amount );
		$charmed = $whole + (float) $rule;

		// If rounding down would produce something oddly small, keep the ceiling instead.
		if ( $charmed < $amount - 1 ) {
			$whole   = ceil( $amount );
			$charmed = $whole - 1 + (float) $rule;
		}

		return round( $charmed, $decimals );
	}

	/**
	 * Filter the store currency for the resolved currency.
	 *
	 * @param string $currency Original currency code.
	 * @return string
	 */
	public function filter_currency( $currency ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $currency;
		}

		return $this->current_currency();
	}

	/**
	 * Filter the currency symbol so it matches the resolved currency.
	 *
	 * @param string $symbol   Original symbol.
	 * @param string $currency Currency code being resolved.
	 * @return string
	 */
	public function filter_currency_symbol( $symbol, $currency ) {
		$symbols = array(
			'AED' => 'AED',
			'SAR' => 'SAR',
			'QAR' => 'QAR',
			'KWD' => 'KWD',
			'BHD' => 'BHD',
			'OMR' => 'OMR',
			'JOD' => 'JOD',
			'USD' => '$',
		);

		$symbols = apply_filters( 'gcc_cs_currency_symbols', $symbols );

		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $symbol;
	}

	/**
	 * Filter the number of price decimals for the resolved currency.
	 *
	 * @param int $decimals Original decimal count.
	 * @return int
	 */
	public function filter_price_decimals( $decimals ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $decimals;
		}

		return GCC_CS_Geolocation::get_decimals_for_currency( $this->current_currency() );
	}
}
