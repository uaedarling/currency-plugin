<?php
/**
 * Fixed product price overrides per currency (admin metabox).
 *
 * @package Gcc_Currency_Switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GCC_CS_Admin_Metabox.
 *
 * Adds a metabox to the WooCommerce Product edit screen allowing a fixed
 * price override per supported currency, which takes precedence over the
 * calculated FX conversion.
 *
 * Limitation: fixed overrides are only supported on simple products. For
 * variable products, per-variation overrides are not currently implemented;
 * variations continue to use the calculated FX conversion. See README.md.
 */
class GCC_CS_Admin_Metabox {

	/**
	 * Nonce action/name used to protect the metabox save.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'gcc_cs_save_price_overrides';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'gcc_cs_price_overrides_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 1 );
	}

	/**
	 * Register the metabox on the product edit screen.
	 *
	 * @return void
	 */
	public function register_metabox() {
		add_meta_box(
			'gcc_cs_price_overrides',
			__( 'GCC & Jordan Currency Price Overrides', 'gcc-currency-switcher' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the metabox fields.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$currencies = GCC_CS_Geolocation::get_supported_currencies();
		$product    = wc_get_product( $post->ID );
		$is_variable = $product && $product->is_type( 'variable' );

		echo '<p>' . esc_html__( 'Enter a fixed price override for this product in each currency. Leave blank to use the automatically calculated FX conversion.', 'gcc-currency-switcher' ) . '</p>';

		if ( $is_variable ) {
			echo '<p><em>' . esc_html__( 'This product is a variable product. Fixed price overrides currently apply only to simple products; variations will use the calculated FX conversion.', 'gcc-currency-switcher' ) . '</em></p>';
		}

		echo '<table class="form-table">';

		foreach ( $currencies as $currency ) {
			$regular_value = get_post_meta( $post->ID, '_gcc_price_override_' . $currency, true );
			$sale_value    = get_post_meta( $post->ID, '_gcc_sale_price_override_' . $currency, true );
			$decimals      = GCC_CS_Geolocation::get_decimals_for_currency( $currency );

			echo '<tr>';
			echo '<th scope="row">' . esc_html( $currency ) . '</th>';
			echo '<td>';

			echo '<label style="display:inline-block;min-width:120px;">' . esc_html__( 'Regular price', 'gcc-currency-switcher' ) . '</label>';
			printf(
				'<input type="number" step="%1$s" min="0" name="gcc_cs_price_override[%2$s]" value="%3$s" style="width:120px;margin-right:16px;" />',
				esc_attr( '0.' . str_repeat( '0', max( 0, $decimals - 1 ) ) . '1' ),
				esc_attr( $currency ),
				esc_attr( $regular_value )
			);

			echo '<label style="display:inline-block;min-width:100px;">' . esc_html__( 'Sale price', 'gcc-currency-switcher' ) . '</label>';
			printf(
				'<input type="number" step="%1$s" min="0" name="gcc_cs_sale_price_override[%2$s]" value="%3$s" style="width:120px;" />',
				esc_attr( '0.' . str_repeat( '0', max( 0, $decimals - 1 ) ) . '1' ),
				esc_attr( $currency ),
				esc_attr( $sale_value )
			);

			echo '</td>';
			echo '</tr>';
		}

		echo '</table>';
	}

	/**
	 * Save posted overrides with nonce, capability, and sanitization checks.
	 *
	 * @param int $post_id Product post ID.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$currencies = GCC_CS_Geolocation::get_supported_currencies();

		$regular_input = isset( $_POST['gcc_cs_price_override'] ) && is_array( $_POST['gcc_cs_price_override'] )
			? wp_unslash( $_POST['gcc_cs_price_override'] )
			: array();

		$sale_input = isset( $_POST['gcc_cs_sale_price_override'] ) && is_array( $_POST['gcc_cs_sale_price_override'] )
			? wp_unslash( $_POST['gcc_cs_sale_price_override'] )
			: array();

		foreach ( $currencies as $currency ) {
			$this->save_single_value( $post_id, '_gcc_price_override_' . $currency, isset( $regular_input[ $currency ] ) ? $regular_input[ $currency ] : '' );
			$this->save_single_value( $post_id, '_gcc_sale_price_override_' . $currency, isset( $sale_input[ $currency ] ) ? $sale_input[ $currency ] : '' );
		}
	}

	/**
	 * Sanitize, validate, and persist a single override value.
	 *
	 * @param int    $post_id  Product post ID.
	 * @param string $meta_key Meta key to store under.
	 * @param mixed  $raw      Raw posted value.
	 * @return void
	 */
	private function save_single_value( $post_id, $meta_key, $raw ) {
		$value = sanitize_text_field( $raw );

		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		if ( ! is_numeric( $value ) || (float) $value < 0 ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, wc_format_decimal( $value ) );
	}
}
