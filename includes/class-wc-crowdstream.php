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
	private $event_queue          = array();
	private $item_tracked         = false;
	private $identify_data        = false;
	private $woo                  = false;
	private $has_events_in_cookie = false;

	/**
	 * Init and hook in the integration.
	 *
	 * @return void
	 */
	public function __construct() {
		global $woocommerce;
		$this->woo = function_exists('WC') ? WC() : $woocommerce;

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

		define('CROWDSTREAM_PLUGIN_PATH', dirname(__FILE__));

		add_action('woocommerce_update_options_integration_crowdstream_io', array(
			$this, 'process_admin_options'));

		add_action('woocommerce_init', array($this, 'on_woocommerce_init'));
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

	public function on_woocommerce_init() {
		if($this->crowdstream_tracking_enabled) {
			$this->init_hooks();
			$this->process_queue();
			$this->setup_identity_data();
		}
	}

	public function init_hooks() {
		add_filter('wp_head', array($this, 'render_snippet'));
		add_filter('wp_head', array($this, 'setup_tracking'));

		add_action('woocommerce_add_to_cart', array($this, 'add_to_cart'), 10, 6);
		add_action('woocommerce_before_cart_item_quantity_zero', array($this, 'remove_from_cart'), 10);
		add_filter('woocommerce_applied_coupon', array($this, 'applied_coupon'), 10);
		add_action('woocommerce_checkout_order_processed', array($this, 'action_order_event'), 10);
		add_action('woocommerce_checkout_update_order_review', array($this, 'action_update_order_review'), 10);

		add_action('wp_ajax_crowdstream_clear', array($this, 'clear_queue'));
	}

	public function render_snippet() {
		include_once(CROWDSTREAM_PLUGIN_PATH . '/views/snippet.php');
	}

	public function render_identify() {
		include_once(CROWDSTREAM_PLUGIN_PATH . '/views/identify.php');
	}

	public function render_events() {
		include_once(CROWDSTREAM_PLUGIN_PATH . '/views/js.php');
	}

	public function setup_tracking() {
		$this->track_event('page');
		if(class_exists('WooCommerce')) {
			if(is_product()) {
				$product = get_product(get_queried_object_id());
				$this->track_event('custom', 'Viewed Product', array(
					array('item' => $this->get_product($product))));
			}

			if(is_product_category()) {
				$this->track_event('custom', 'Viewed Product Category', array(
					array($this->get_category(get_queried_object()))));
			}

			if(is_cart()) {
				$product = get_product(get_queried_object_id());
				$this->track_event('custom', 'Viewed Cart', array(
					array('item' => $this->get_product($product))));
			}

			if(is_order_received_page()) {
				$this->track_event('custom', 'Checkout Complete');
			}

			if(function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
				$product = get_product(get_queried_object_id());
				$this->track_event('custom', 'Viewed Checkout');
			}

			if(count($this->event_queue) > 0) $this->render_events();
			if($this->identify_data !== false) $this->render_identify();
		}
	}

	public function track_event($method, $event = false, $params = array()) {
		array_push($this->event_queue, $this->prepare_event($method, $event, $params));
	}

	public function defer_event($method, $event, $params = array()) {
		$this->put_in_queue($this->prepare_event($method, $event, $params));
	}

	public function prepare_event($method, $event = false, $params = array()) {
		return array(
			'method' => $method,
			'event'  => $event,
			'params' => $params
		);
	}

	public function setup_identity_data() {
		if(is_user_logged_in()) {
			$userId = get_current_user_id();
			$user   = wp_get_current_user();

			$this->identify_data = array(
				'id' => $userId,
				'params' => array(
					'name'     => $user->display_name,
					'username' => $user->user_login,
					'email'    => $user->user_email
				)
			);

			if($user->user_firstname != '' && $user->user_lastname){
				$this->identify_data['params']['first_name'] = $user->user_firstname;
				$this->identify_data['params']['last_name']  = $user->user_lastname;
			}
		}
	}

	public function action_order_event($order_id) {
		$order = new WC_Order($order_id);

		if($this->identify_data === false) {
			$this->identify_data = array(
				'id'		=> get_post_meta($order_id, '_billing_email', true),
				'params'	=> array(
					'email' 		=> get_post_meta($order_id, '_billing_email', true),
					'first_name' 	=> get_post_meta($order_id, '_billing_first_name', true),
					'last_name' 	=> get_post_meta($order_id, '_billing_last_name', true),
					'name'			=> get_post_meta($order_id, '_billing_first_name', true)
									   . ' ' . get_post_meta($order_id, '_billing_last_name', true),
					)
				);
		}

		$lines = array();
		$items = array();
		$quantity = 0;

		// Order items
		if ($order->get_items()) {
			foreach ($order->get_items() as $item) {
				$_product = $order->get_product_from_item($item);

				$data = array_merge(array(
					'order_id' => $order->get_order_number(),
					'quantity' => $item['qty'],
					'currency' => get_woocommerce_currency()
				), $this->get_product($_product, $item['variation_id']));

				$quantity += $item['qty'];

				$items[] = $data;
			}
		}

		$checkout = array(
			'order_id' => $order->get_order_number(),
			'items'    => $quantity,
			'total'    => $order->get_total(),
			'shipping' => method_exists($order, 'get_total_shipping') ?
				$order->get_total_shipping() : $order->get_shipping(),
			'currency' => get_woocommerce_currency(),
			'channel'  => 'online'
		);

		$this->defer_event('addItems', false, array($items));
		$this->defer_event('checkout', false, array($checkout));
	}

	public function get_product($product, $variation_id = false, $variation = false) {
		$data = array(
			'item'     => $product->get_title(),
			'id'       => $product->id,
			'sku'      => $product->get_sku(),
			'amount'   => $product->get_price(),
			'variant'  => null,
		);
		if($variation_id) {
			$variant         = $this->get_variation($variation_id, $variation);
			$data['variant'] = '#' . $variant['variation_id'] . ' ' . $variant['name'];
			$data['amount']  = $variant['price'];
		}
		return $data;
	}

	public function get_category($category){
		$category_hash = array(
			'id'	=>	$category->term_id,
			'name'	=> 	$category->name
		);
		return $category_hash;
	}

	public function get_variation($variation_id, $variation = false){
		// prepare variation data array
		$variation_data = array('id' => $variation_id, 'name' => '', 'price' => '');
		// prepare variation name if $variation is provided as argument
		if($variation){
			$variation_attribute_count = 0;
			foreach($variation as $attr => $value){
				$variation_data['name'] = $variation_data['name'] . ($variation_attribute_count == 0 ? '' : ', ') . $value;
				$variation_attribute_count++;
			}
		}
		// get variation price from object
		$variation_obj = new WC_Product_Variation($variation_id);
		$variation_data['price'] = $variation_obj->price;
		// return
		return $variation_data;
	}

	public function add_to_cart($cart_item_key, $product_id, $quantity, $variation_id = false, $variation = false, $cart_item_data = false){
		$product = get_product($product_id);
		if(defined('DOING_AJAX') && DOING_AJAX) {
			$this->defer_event('custom', 'Product Added', array(
				array('item' => $this->get_product($product, $variation_id, $variation))
			));
		} else {
			$this->track_event('custom', 'Product Added', array(
				array('item' => $this->get_product($product, $variation_id, $variation))
			));
		}
	}

	public function remove_from_cart($key_id){
		if(!is_object($this->woo->cart)){
			return true;
		}
		$cart_items = $this->woo->cart->get_cart();
		$removed_cart_item = isset($cart_items[$key_id]) ? $cart_items[$key_id] : false;
		if($removed_cart_item){
			$product = get_product($removed_cart_item['product_id']);
			$variation_id = false;
			if(!empty($removed_cart_item['variation_id'])){
				$variation_id = $removed_cart_item['variation_id'];
			}
			if(defined('DOING_AJAX') && DOING_AJAX) {
				$this->defer_event('custom', 'Product Removed', array(
					array('item' => $this->get_product($product, $variation_id))
				));
			} else {
				$this->track_event('custom', 'Product Removed', array(
					array('item' => $this->get_product($product, $variation_id))
				));
			}
		}
	}

	// public function action_update_order_review($postdata) {
	// 	$data = array();
	// 	parse_str($postdata, $data);
	// 	$identify_data = array();
	// 	if(array_key_exists('billing_first_name', $data)) {
	// 		$identify_data['first_name'] = $data['billing_first_name'];
	// 	}
	// 	if(array_key_exists('billing_last_name', $data)) {
	// 		$identify_data['billing_last_name'] = $data['billing_last_name'];
	// 	}
	// 	if(array_key_exists('billing_email', $data)) {
	// 		$identify_data['billing_email'] = $data['billing_email'];
	// 	}
	//
	// 	if($identify_data) {
	// 		if($this->identify_data !== false) {
	// 			$this->identify_data['params'] = array_merge($this->identify_data['params'], $identify_data);
	// 		} else {
	// 			$this->identify_data = $identify_data;
	// 		}
	// 	}
	// }

	public function applied_coupon($code) {
		$this->track_event('custom', 'Applied Coupon', array(array('coupon' => $code)));
	}

	public function session_get($k){
		if(!is_object($this->woo->session)){
			return isset($_COOKIE[$k]) ? $_COOKIE[$k] : false;
		}
		return $this->woo->session->get($k);
	}

	public function session_set($k, $v){
		if(!is_object($this->woo->session)){
			setcookie($k, $v, time() + 43200, COOKIEPATH, COOKIE_DOMAIN);
			$_COOKIE[$k] = $v;
			return true;
		}
		return $this->woo->session->set($k, $v);
	}

	public function put_in_queue($data) {
		$items = $this->get_queue();
		if(empty($items)) $items = array();
		array_push($items, $data);
		$encoded_items = json_encode($items, true);
		$this->session_set($this->get_cookie_name(), $encoded_items);
	}

	public function get_queue() {
		$items = array();
		$data = $this->session_get($this->get_cookie_name());
		if(!empty($data)) $items = json_decode(stripslashes($data), true);
		return $items;
	}

	public function clear_queue() {
		$this->session_set($this->get_cookie_name(), json_encode(array(), true));
	}

	private function get_cookie_name() {
		return 'csqueue_' . COOKIEHASH;
	}

	private function process_queue() {
		if(!(defined('DOING_AJAX') && DOING_AJAX)) {
			$items = $this->get_queue();
			if(count($items) > 0) {
				$this->has_events_in_cookie = true;
				foreach($items as $item) {
					array_push($this->event_queue, $item);
				}
			}
		}
	}
}
