<?php
/**
 * Plugin Name: Crowdstream for WooCommerce
 * Plugin URI: http://wordpress.org/plugins/woocommerce-crowdstream/
 * Description: Allows Crowdstream.io tracking code to be inserted into WooCommerce store pages.
 * Author: Crowdstream
 * Author URI: https://www.crowdstream.io
 * Version: 1.0.0
 * License: GPLv2 or later
 * Text Domain: woocommerce-crowdstream
 * Domain Path: languages/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Crowdstream_Integration' ) ) :

/**
 * WooCommerce Google Analytics Integration main class.
 */
class WC_Crowdstream_Integration {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '0.0.1';

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin.
	 */
	private function __construct() {
		// Load plugin text domain
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// Checks with WooCommerce is installed.
		if ( class_exists( 'WC_Integration' ) && defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.1-beta-1', '>=' ) ) {
			include_once 'includes/class-wc-crowdstream.php';

			// Register the integration.
			add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );
		} else {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
		}
	}

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @return void
	 */
	public function load_plugin_textdomain() {
		$locale = apply_filters( 'plugin_locale', get_locale(), 'woocommerce-crowdstream' );

		load_textdomain( 'woocommerce-crowdstream', trailingslashit( WP_LANG_DIR ) . 'woocommerce-crowdstream/woocommerce-crowdstream-' . $locale . '.mo' );
		load_plugin_textdomain( 'woocommerce-crowdstream', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * WooCommerce fallback notice.
	 *
	 * @return string
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="error"><p>' . sprintf( __( 'WooCommerce Crowdstream depends on the last version of %s to work!', 'woocommerce-crowdstream' ), '<a href="http://www.woothemes.com/woocommerce/" target="_blank">' . __( 'WooCommerce', 'woocommerce-crowdsream' ) . '</a>' ) . '</p></div>';
	}

	/**
	 * Add a new integration to WooCommerce.
	 *
	 * @param  array $integrations WooCommerce integrations.
	 *
	 * @return array               Google Analytics integration.
	 */
	public function add_integration( $integrations ) {
		$integrations[] = 'WC_Crowdstream';

		return $integrations;
	}
}

add_action( 'plugins_loaded', array( 'WC_Crowdstream_Integration', 'get_instance' ), 0 );

endif;
