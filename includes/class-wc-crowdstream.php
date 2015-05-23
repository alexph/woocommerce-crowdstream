<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Crowdstream Integration
 *
 * Allows tracking code to be inserted into store pages.
 *
 * @class   WC_Google_Analytics
 * @extends WC_Integration
 */
class WC_Crowdstream extends WC_Integration {

	/**
	 * Init and hook in the integration.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->id                 = 'crowdstream_io';
		$this->method_title       = __( 'Crowdstream', 'woocommerce-crowdstream' );
		$this->method_description = __( 'Crowdstream.io is a customer insights and analytics platform.', 'woocommerce-crowdstream' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->crowdstream_app_id = $this->get_option('crowdstream_app_id');
		$this->crowdstream_tracking_enabled = $this->get_option('crowdstream_tracking_enabled');

		// Actions
		add_action( 'woocommerce_update_options_integration_crowdstream_io', array( $this, 'process_admin_options') );

		// Tracking code
		add_action( 'wp_head', array( $this, 'tracking_code_display' ), 999999 );

		// Event tracking code
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'add_to_cart' ) );
		add_action( 'wp_footer', array( $this, 'loop_add_to_cart' ) );

		// utm_nooverride parameter for Google AdWords
		// add_filter( 'woocommerce_get_return_url', array( $this, 'utm_nooverride' ) );
	}

	/**
	 * Initialise Settings Form Fields
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'crowdstream_app_id' => array(
				'title' 			=> __( 'Crowdstream App ID', 'woocommerce-crowdstream' ),
				'description' 		=> __( 'Log into your Crowdstream account to find your App ID.', 'woocommerce-crowdstream' ),
				'type' 				=> 'text',
				'default' 			=> get_option( 'woocommerce_crowdstream_app_id' ) // Backwards compat
			),
			'crowdstream_tracking_enabled' => array(
				'title' 			=> __( 'Tracking Enabled', 'woocommerce-crowdstream' ),
				'label' 			=> __( 'Add tracking code to your site.', 'woocommerce-crowdstream' ),
				'type' 				=> 'checkbox',
				'checkboxgroup'		=> 'start',
				'default' 			=> get_option( 'woocommerce_crowdstream_tracking_enabled' ) ? get_option( 'woocommerce_crowdstream_tracking_enabled' ) : 'no'  // Backwards compat
			),
		);
	}

	/**
	 * Display the tracking codes
	 *
	 * @return string
	 */
	public function tracking_code_display() {
		global $wp;

		if ( is_admin() || current_user_can( 'manage_options' ) || ! $this->crowdstream_app_id ) {
			return;
		}

		if('yes' == $this->crowdstream_tracking_enabled) {
			echo $this->get_crowdstream_tracking_code($this->crowdstream_app_id);	
		}
	}

	protected function get_tracking_template($appId, $userId, $username) {
		global $wp;
		$identityCode = '';
		$ecommerceCode = '';

		if($userId) {
			$identityCode = 'crowdstream.events.identify("' . $userId . '", {username: "' . $username . '"});';
		}

		if(is_order_received_page()) {
			$orderId = isset( $wp->query_vars['order-received'] ) ? $wp->query_vars['order-received'] : 0;

			if (0 < $orderId && 1 != get_post_meta( $orderId, '_crowdstream_tracked', true )) {
				$ecommerceCode = $this->get_ecommerce_tracking_code($orderId);
			}
		}

		return <<<EOF
<script>
    (function() {
        var crowdstream = window.crowdstream = window.crowdstream || {};

        if(typeof crowdstream.load == 'function') return;

        crowdstream.load = function(key) {
            var script = document.createElement('script');
            script.type = 'text/javascript';
            script.async = true;
            script.src = ('https:' === document.location.protocol
                    ? 'https://' : 'http://')
                    + 's3.eu-central-1.amazonaws.com/crowdstream/crowdstream.js';
            var first = document.getElementsByTagName('script')[0];
            first.parentNode.insertBefore(script, first);

            crowdstream.ready = function() {
                crowdstream.appId(key);
                crowdstream.events.page();
                $identityCode

                $ecommerceCode
            }
        };

        crowdstream.load('$appId');
    })();
</script>
EOF;
	}

	/**
	 * Crowdstream standard tracking
	 *
	 * @return string
	 */
	protected function get_crowdstream_tracking_code() {
		$logged_in = ( is_user_logged_in() ) ? 'yes' : 'no';

		if ( 'yes' === $logged_in ) {
			$userId       = get_current_user_id();
			$current_user = get_user_by('id', $user_id);
			$username     = $current_user->user_login;
		} else {
			$userId   = false;
			$username = false;
		}

		return "
<!-- WooCommerce Crowdstream Integration -->
" . $this->get_tracking_template($this->crowdstream_app_id, $userId, $username) . "
<script type='text/javascript'>$code</script>

<!-- /WooCommerce Crowdstream Integration -->

";

	}

	/**
	 * Crowdstream eCommerce tracking
	 *
	 * @param int $order_id
	 *
	 * @return string
	 */
	protected function get_ecommerce_tracking_code( $order_id ) {
		// Get the order and output tracking code
		$order = new WC_Order( $order_id );

		// $logged_in = is_user_logged_in() ? 'yes' : 'no';

		$lines = array();
		$items = array();
		$quantity = 0;

		// Order items
		if ( $order->get_items() ) {
			foreach ( $order->get_items() as $item ) {
				$_product = $order->get_product_from_item( $item );

				$data = array(
					'order_id' => esc_js( $order->get_order_number() ),
					'name' => esc_js( $item['name'] ),
					'sku' => esc_js( $_product->get_sku() ? $_product->get_sku() : $_product->id ),
					'variation' => esc_js( woocommerce_get_formatted_variation( $_product->variation_data, true ) ),
					'price' => esc_js( $order->get_item_total( $item ) ),
					'quantity' => esc_js( $item['qty'] )
				);

				$quantity += $item['qty'];

				$out = array();
				$categories = get_the_terms($_product->id, 'product_cat');
				if ( $categories ) {
					foreach ( $categories as $category ) {
						$out[] = $category->name;
					}
				}

				$data['category'] = esc_js( join( "/", $out) );

				$items[] = $data;
			}
		}

		if($items) {
			$lines[] = 'crowdstream.events.add_items(';
			$lines[] = json_encode($items);
			$lines[] = ');';
		}	

		// _gaq.push(['_addTrans',
		// 	'" . esc_js( $order->get_order_number() ) . "', 	// order ID - required
		// 	'" . esc_js( get_bloginfo( 'name' ) ) . "',  		// affiliation or store name
		// 	'" . esc_js( $order->get_total() ) . "',   	    	// total - required
		// 	'" . esc_js( $order->get_total_tax() ) . "',    	// tax
		// 	'" . esc_js( $order->get_total_shipping() ) . "',	// shipping
		// 	'" . esc_js( $order->billing_city ) . "',       	// city
		// 	'" . esc_js( $order->billing_state ) . "',      	// state or province
		// 	'" . esc_js( $order->billing_country ) . "'     	// country
		// ]);
		 
		 
		$orderNum = esc_js( $order->get_order_number());
		$affiliation = esc_js( get_bloginfo( 'name' ) );
		$revenue = esc_js( $order->get_total() );
		$shipping = esc_js( $order->get_total_shipping() );
		$tax = esc_js( $order->get_total_tax() );
		$currency = esc_js( $order->get_order_currency() );
		
		$lines[] = <<<EOF
	crowdstream.events.checkout({
		'order_id': '$orderNum',
		'affiliation': '$affiliation',
		'revenue': '$revenue', 
		'shipping': '$shipping',
		'tax': '$tax',
		'currency': '$currency',
		'items': $quantity
	});
EOF;

                update_post_meta( $orderNum, '_crowdstream_tracked', 1 );

		return join("\n", $lines);
	}

	/**
	 * Check if tracking is disabled
	 *
	 * @param string $type
	 *
	 * @return bool
	 */
	private function disable_tracking( $type ) {
		if ( is_admin() || current_user_can( 'manage_options' ) || ( ! $this->crowdstream_app_id ) || 'no' == $type ) {
			return true;
		}
	}

	/**
	 * Crowdstream event tracking for single product add to cart
	 *
	 * @return void
	 */
	public function add_to_cart() {

		if ( $this->disable_tracking($this->crowdstream_tracking_enabled) ) {
			return;
		}
		
		if ( ! is_single() ) {
			return;
		}

		global $product;

		$parameters = array();
		// Add single quotes to allow jQuery to be substituted into _trackEvent parameters
		$parameters['category'] = "'" . __( 'Products', 'woocommerce-crowdstream' ) . "'";
		$parameters['action']   = "'" . __( 'Add to Cart', 'woocommerce-crowdstream' ) . "'";
		$parameters['label']    = "'" . esc_js( $product->get_sku() ? __( 'SKU:', 'woocommerce-crowdstream' ) . ' ' . $product->get_sku() : "#" . $product->id ) . "'";

		$this->event_tracking_code( $parameters, '.single_add_to_cart_button' );
	}


	/**
	 * Crowdstream event tracking for loop add to cart
	 *
	 * @return void
	 */
	public function loop_add_to_cart() {

		if ( $this->disable_tracking( $this->crowdstream_tracking_enabled ) ) {
			return;
		}

		$parameters = array();
		// Add single quotes to allow jQuery to be substituted into _trackEvent parameters
		$parameters['category'] = "'" . __( 'Products', 'woocommerce-crowdstream' ) . "'";
		$parameters['action']   = "'" . __( 'Add to Cart', 'woocommerce-crowdstream' ) . "'";
		$parameters['label']    = "($(this).data('product_sku')) ? ('SKU: ' + $(this).data('product_sku')) : ('#' + $(this).data('product_id'))"; // Product SKU or ID

		$this->event_tracking_code( $parameters, '.add_to_cart_button:not(.product_type_variable, .product_type_grouped)' );
	}

	/**
	 * Crowdstream event tracking for loop add to cart
	 *
	 * @param array $parameters associative array of _trackEvent parameters
	 * @param string $selector jQuery selector for binding click event
	 *
	 * @return void
	 */
	private function event_tracking_code( $parameters, $selector ) {
		$parameters = apply_filters( 'woocommerce_crowdstream_event_tracking_parameters', $parameters );

		$track_event = "crowdstream.events.track(%s, %s, %s);";

		wc_enqueue_js( "
	$( '" . $selector . "' ).click( function() {
		" . sprintf( $track_event, $parameters['category'], $parameters['action'], $parameters['label'] ) . "
	});
" );
	}

	/**
	 * Add the utm_nooverride parameter to any return urls. This makes sure Google Adwords doesn't mistake the offsite gateway as the referrer.
	 *
	 * @param  string $type
	 *
	 * @return string
	 */
	// public function utm_nooverride( $return_url ) {

	// 	// We don't know if the URL already has the parameter so we should remove it just in case
	// 	$return_url = remove_query_arg( 'utm_nooverride', $return_url );

	// 	// Now add the utm_nooverride query arg to the URL
	// 	$return_url = add_query_arg( 'utm_nooverride', '1', $return_url );

	// 	return $return_url;
	// }
}
