<?php
/**
 * Plugin Name:       Custom GCC & Jordan Multi-Currency for WooCommerce
 * Plugin URI:        https://github.com/uaedarling/currency-plugin
 * Description:       Dynamically switches WooCommerce store currency based on visitor geolocation for GCC nations (UAE, Saudi Arabia, Qatar, Kuwait, Bahrain, Oman) and Jordan, defaulting all other traffic to USD. Includes live FX rate caching, charm pricing, per-product fixed overrides, and full-page cache compatibility.
 * Version:           1.0.0
 * Author:            uaedarling
 * Text Domain:       gcc-currency-switcher
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * WC tested up to:   8.5
 *
 * @package Gcc_Currency_Switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Plugin constants.
define( 'GCC_CS_VERSION', '1.0.0' );
define( 'GCC_CS_PLUGIN_FILE', __FILE__ );
define( 'GCC_CS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GCC_CS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GCC_CS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Check whether WooCommerce is active (including network-activated).
 *
 * @return bool
 */
function gcc_cs_is_woocommerce_active() {
	if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ), true ) ) {
		return true;
	}

	if ( is_multisite() ) {
		$plugins = get_site_option( 'active_sitewide_plugins', array() );
		if ( isset( $plugins['woocommerce/woocommerce.php'] ) ) {
			return true;
		}
	}

	return class_exists( 'WooCommerce' );
}

/**
 * Show an admin notice when WooCommerce is missing/inactive.
 */
function gcc_cs_missing_woocommerce_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html__( 'Custom GCC & Jordan Multi-Currency for WooCommerce requires WooCommerce to be installed and active.', 'gcc-currency-switcher' );
			?>
		</p>
	</div>
	<?php
}

/**
 * Require all plugin class files.
 */
function gcc_cs_load_includes() {
	$includes = array(
		'includes/class-geolocation.php',
		'includes/class-fx-rates.php',
		'includes/class-price-converter.php',
		'includes/class-checkout.php',
		'includes/class-admin-metabox.php',
		'includes/class-cache-compat.php',
		'includes/class-core.php',
	);

	foreach ( $includes as $file ) {
		$path = GCC_CS_PLUGIN_DIR . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

/**
 * Bootstraps the plugin once all plugins are loaded so WooCommerce is available.
 */
function gcc_cs_bootstrap() {
	if ( ! gcc_cs_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'gcc_cs_missing_woocommerce_notice' );
		return;
	}

	gcc_cs_load_includes();

	if ( class_exists( 'GCC_CS_Core' ) ) {
		GCC_CS_Core::instance();
	}
}
add_action( 'plugins_loaded', 'gcc_cs_bootstrap' );

/**
 * Gracefully disable the plugin if WooCommerce is deactivated after our plugin was active.
 */
add_action(
	'admin_init',
	function () {
		if ( is_admin() && ! gcc_cs_is_woocommerce_active() ) {
			add_action( 'admin_notices', 'gcc_cs_missing_woocommerce_notice' );
		}
	}
);
