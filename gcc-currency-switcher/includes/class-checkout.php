<?php
/**
 * Cart totals, checkout sync, and order currency locking.
 *
 * @package Gcc_Currency_Switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GCC_CS_Checkout.
 *
 * Ensures cart/checkout totals and order line items are calculated and
 * stored consistently in the visitor's resolved currency, and locks that
 * currency on the order so later recalculations/FX changes cannot alter
 * historical order values.
 */
class GCC_CS_Checkout {

	/**
	 * Order meta key used to lock the currency used at checkout.
	 *
	 * @var string
	 */
	const ORDER_META_KEY = '_gcc_locked_currency';

	/**
	 * Geolocation service instance.
	 *
	 * @var GCC_CS_Geolocation
	 */
	private $geolocation;

	/**
	 * Constructor.
	 *
	 * @param GCC_CS_Geolocation $geolocation Geolocation service.
	 */
	public function __construct( GCC_CS_Geolocation $geolocation ) {
		$this->geolocation = $geolocation;

		$this->hooks();
	}

	/**
	 * Register WooCommerce hooks.
	 *
	 * @return void
	 */
	private function hooks() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'lock_order_currency' ), 10, 2 );
		add_action( 'woocommerce_new_order', array( $this, 'lock_order_currency_on_new_order' ) );
		add_filter( 'woocommerce_order_get_currency', array( $this, 'maybe_use_locked_currency' ), 10, 2 );
	}

	/**
	 * Persist the resolved currency on the order at checkout time.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted checkout data.
	 * @return void
	 */
	public function lock_order_currency( $order, $data ) {
		unset( $data );

		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$currency = $this->geolocation->get_current_currency();
		$order->update_meta_data( self::ORDER_META_KEY, $currency );
	}

	/**
	 * Fallback lock for orders created outside the standard checkout flow.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function lock_order_currency_on_new_order( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_meta( self::ORDER_META_KEY ) ) {
			return;
		}

		$currency = $this->geolocation->get_current_currency();
		$order->update_meta_data( self::ORDER_META_KEY, $currency );
		$order->save();
	}

	/**
	 * Ensure the order always reports the currency it was locked with,
	 * so subsequent FX rate changes never alter historical order values.
	 *
	 * @param string   $currency Currency currently set on the order.
	 * @param WC_Order $order    Order object.
	 * @return string
	 */
	public function maybe_use_locked_currency( $currency, $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return $currency;
		}

		$locked = $order->get_meta( self::ORDER_META_KEY );

		return $locked ? $locked : $currency;
	}
}
