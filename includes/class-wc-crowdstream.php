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
		$this->method_title       = __('Crowdstream', 'woocommerce-crowdstream');
		$this->method_description = __('Crowdstream.io is a customer insights and analytics platform.',
			'woocommerce-crowdstream');

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->crowdstream_app_id           = $this->get_option('crowdstream_app_id');
		$this->crowdstream_tracking_enabled = $this->get_option('crowdstream_tracking_enabled') == 'yes';

		if(!$this->crowdstream_app_id) {
			$this->crowdstream_tracking_enabled = false;
		}

		add_action('woocommerce_update_options_integration_crowdstream_io', array(
			$this, 'process_admin_options'));

		// Tracking code
		add_action('wp_head', array($this, 'invoke_tracking'));

		// Event tracking codes
		add_action('woocommerce_after_add_to_cart_button', array($this, 'queue_cart_buttons'));
		add_action('wp_footer', array($this, 'post_add_to_cart'));
	}

	/**
	 * Initialise Settings Form Fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'crowdstream_app_id' => array(
				'title' 			=> __('Crowdstream App ID', 'woocommerce-crowdstream'),
				'description' 		=> __('Log into your Crowdstream account to find your App ID.', 'woocommerce-crowdstream'),
				'type' 				=> 'text',
				'default' 			=> get_option('woocommerce_crowdstream_app_id') // Backwards compat
			),
			'crowdstream_tracking_enabled' => array(
				'title' 			=> __('Tracking Enabled', 'woocommerce-crowdstream'),
				'label' 			=> __('Add tracking code to your site.', 'woocommerce-crowdstream'),
				'type' 				=> 'checkbox',
				'checkboxgroup'		=> 'start',
				'default' 			=> get_option(
					'woocommerce_crowdstream_tracking_enabled') ? 
						get_option( 'woocommerce_crowdstream_tracking_enabled' ) : 'no'
			),
		);
	}

	/**
	 * Display the tracking codes
	 *
	 * @return string
	 */
	public function invoke_tracking() {
		if($this->crowdstream_tracking_enabled) {
			echo $this->get_crowdstream_tracking_code($this->crowdstream_app_id);
		}
	}

	protected function get_tracking_template($appId, $userId, $username, $email) {
		global $wp;
		$identityCode = '';
		$ecommerceCode = '';

		if($userId) {
			$identityCode = 'crowdstream.events.identify("' . $userId . '", {
				username: "' . $username . '", email: "' . $email . '"});';
		}

		if(is_order_received_page()) {
			$orderId = isset($wp->query_vars['order-received']) ? $wp->query_vars['order-received'] : 0;

			if (0 < $orderId && 1 != get_post_meta($orderId, '_crowdstream_tracked', true)) {
				$ecommerceCode = $this->get_ecommerce_tracking_code($orderId);
			}
		}

		return <<<EOF
!function(){var t=window.crowdstream=window.crowdstream||{};if("function"!=typeof t.load){t._preload=[],t.events={};for(var e=["page","track","custom","identify","logout","cart","checkout","addItems","addItem"];e.length;){var o=e.shift();t.events[o]=function(e){return function(){t._preload.push([e,arguments])}}(o)}t.load=function(e){var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=("https:"===document.location.protocol?"https://":"http://")+"s3.eu-central-1.amazonaws.com/crowdstream/crowdstream.js";var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a),t.ready=function(){t.appId(e)}},t.load('$appId')}}();
crowdstream.events.page();
$identityCode
$ecommerceCode
EOF;
	}

	/**
	 * Crowdstream standard tracking
	 *
	 * @return string
	 */
	protected function get_crowdstream_tracking_code() {
		if(is_user_logged_in()) {
			$userId      = get_current_user_id();
			$currentUser = get_user_by('id', $userId);
			$username    = $currentUser->user_login;
			$email       = $currentUser->user_email;
		} else {
			$userId   = false;
			$username = false;
			$email    = false;
		}

		$code = $this->get_tracking_template($this->crowdstream_app_id, $userId, $username, $email);

		return "
<!-- WooCommerce Crowdstream Integration -->
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
					'item' => esc_js( $item['name'] ),
					'id' => esc_js( $_product->get_sku() ? $_product->get_sku() : $_product->id ),
					'sku' => esc_js( $_product->get_sku() ? $_product->get_sku() : $_product->id ),
					'variant' => esc_js( woocommerce_get_formatted_variation( $_product->variation_data, true ) ),
					'amount' => esc_js( $order->get_item_total( $item ) ),
					'quantity' => esc_js( $item['qty'] ),
					'currency' => esc_js( get_woocommerce_currency() )
				);

				$quantity += $item['qty'];

				$out = array();
				$categories = get_the_terms($_product->id, 'product_cat');
				if ( $categories ) {
					foreach ( $categories as $category ) {
						$out[] = $category->name;
					}
				}

				// $data['category'] = esc_js( join( "/", $out) );

				$items[] = $data;
			}
		}

		if($items) {
			$lines[] = 'crowdstream.events.addItems(';
			$lines[] = json_encode($items);
			$lines[] = ');';
		}
		 
		$orderNum = esc_js( $order->get_order_number());
		$affiliation = esc_js( get_bloginfo( 'name' ) );
		$revenue = esc_js( $order->get_total() );
		$shipping = esc_js( $order->get_total_shipping() );
		$tax = esc_js( $order->get_total_tax() );
		$currency = esc_js( $order->get_order_currency() );
		
		$lines[] = <<<EOF
	crowdstream.events.checkout({
		'order_id': '$orderNum',
		'total': '$revenue', 
		'shipping': '$shipping',
		'currency': '$currency',
		'items': '$quantity'
	});
EOF;

        update_post_meta( $orderNum, '_crowdstream_tracked', 1 );

		return join("\n", $lines);
	}

	/**
	 * Crowdstream event tracking for single product add to cart
	 *
	 * @return void
	 */
	public function post_add_to_cart() {
		if(!$this->crowdstream_tracking_enabled) {
			return;
		}

		global $product;

		$id = $product->get_sku() ? 
			__('SKU:', 'woocommerce-crowdstream') . ' ' . $product->get_sku() : "#" . $product->id;
		$sku = $product->get_sku() ? 
			__('SKU:', 'woocommerce-crowdstream') . ' ' . $product->get_sku() : "#" . $product->id;

		$parameters = array();
		$parameters['id']    = "'" . esc_js($id) . "'";
		$parameters['sku']   = "'" . esc_js($sku) . "'";
		$parameters['item']  = "'" . esc_js($product->get_title()) . "'";
		// $parameters['variant'] = "cs_variation_current.variation_id";

		$this->cart_event_tracking_code($parameters, '.single_add_to_cart_button');
	}


	/**
	 * Crowdstream event tracking for loop add to cart
	 *
	 * @return void
	 */
	public function queue_cart_buttons() {
		if(!$this->crowdstream_tracking_enabled) {
			return;
		}

		$parameters = array();
		$parameters['id']   = "($(this).data('product_sku')) ? ('SKU: ' + $(this).data('product_sku')) : ($(this).data('product_id'))"; // Product SKU or ID
		$parameters['sku']  = "($(this).data('product_sku')) ? ($(this).data('product_sku')) : ('#' + $(this).data('product_id'))"; // Product SKU or ID
		$parameters['item'] = "$('[itemprop=name]').text()";

		$this->cart_event_tracking_code($parameters, '.add_to_cart_button:not(.product_type_variable, .product_type_grouped)');
	}

	/**
	 * Crowdstream event tracking for loop add to cart
	 *
	 * @param array $parameters associative array of _trackEvent parameters
	 * @param string $selector jQuery selector for binding click event
	 *
	 * @return void
	 */
	private function cart_event_tracking_code( $parameters, $selector ) {
		$parameters = apply_filters( 'woocommerce_crowdstream_event_tracking_parameters', $parameters );

		$track_event = "crowdstream.events.cart(%s);";

		wc_enqueue_js("
	$('" . $selector . "').click( function() {
		" . sprintf($track_event, json_encode($parameters)) . "
	});
");
	}

	/**
	 * Crowdstream event tracking for loop add to cart
	 *
	 * @param array $parameters associative array of _trackEvent parameters
	 * @param string $selector jQuery selector for binding click event
	 *
	 * @return void
	 */
	private function generic_event_tracking_code( $parameters, $selector ) {
		$parameters = apply_filters('woocommerce_crowdstream_event_tracking_parameters', $parameters);

		$track_event = "crowdstream.events.track(%s, %s, %s);";

		wc_enqueue_js( "
	$('" . $selector . "').click( function() {
		" . sprintf($track_event, $parameters['category'], $parameters['action'], $parameters['label']) . "
	});
" );
	}
}
