<?php
/**
 * Test cases for WC_Gateway_Amazon_Payments_Advanced.
 *
 * @package WC_Gateway_Amazon_Pay/Tests
 */

declare(strict_types=1);

/**
 * WC_Gateway_Amazon_Payments_Advanced_Test tests functionalities in WC_Gateway_Amazon_Payments_Advanced.
 */
class WC_Gateway_Amazon_Payments_Advanced_Test extends WP_UnitTestCase {

	/**
	 * Store an instance of our Gateway being tested.
	 *
	 * @var ?WC_Gateway_Amazon_Payments_Advanced
	 */
	protected static $gateway;

	/**
	 * Set up our DB before tests.
	 *
	 * @return void
	 */
	public static function set_up_before_class() : void {
		parent::set_up_before_class();
		self::$gateway = new WC_Gateway_Amazon_Payments_Advanced();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_amazon_payments_new_install', WC_AMAZON_PAY_VERSION );
		update_option( 'amazon_api_version', 'V2' );
	}

	/**
	 * Restore the DB's state after tests.
	 *
	 * @return void
	 */
	public static function tear_down_after_class() : void {
		self::$gateway = null;
		delete_option( 'woocommerce_currency' );
		delete_option( 'woocommerce_amazon_payments_advanced_settings' );
		delete_option( 'woocommerce_amazon_payments_new_install' );
		delete_option( 'amazon_api_version' );
		delete_option( WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler::KEYS_OPTION_PRIVATE_KEY );
		parent::tear_down_after_class();
	}

	/**
	 * Test that gateway is not available.
	 *
	 * @return void
	 */
	public function test_is_not_available() : void {
		$this->assertFalse( self::$gateway->is_available() );
	}

	/**
	 * Test that gateway is available.
	 *
	 * @return void
	 */
	public function test_is_available() : void {
		// Update plugin settings to make the gateway available.
		$this->make_gateway_available();

		// Test gateway availability.
		$this->assertTrue( self::$gateway->is_available() );
	}

	/**
	 * Test happy path of the express gateway.
	 *
	 * @return void
	 */
	public function test_express_process_payment_succeeds() : void {
		$checkout_session_key = apply_filters( 'woocommerce_amazon_pa_checkout_session_key', 'amazon_checkout_session_id' );
		WC()->session->set( $checkout_session_key, 'TEST_CHECKOUT_SESSION_ID' );
		WC()->session->save_data();

		$order = WC_Helper_Order::create_order( self::$gateway->id );

		$mock_gateway = new WC_Mocker_Gateway_Amazon_Payments_Advanced();

		$this->assertEquals(
			array(
				'result'   => 'success',
				'redirect' => 'https://amazon.unit.tests/',
			),
			$mock_gateway->process_payment( $order->get_id() )
		);
	}

	/**
	 * Test happy path of classic gateway.
	 *
	 * @return void
	 */
	public function test_classic_process_payment_succeeds() : void {
		$checkout_session_key = apply_filters( 'woocommerce_amazon_pa_checkout_session_key', 'amazon_checkout_session_id' );
		WC()->session->set( $checkout_session_key, null );
		WC()->session->save_data();

		$order_total = 100;

		$order = WC_Helper_Order::create_order( self::$gateway->id, 1, $order_total );

		$mock_gateway = new WC_Mocker_Gateway_Amazon_Payments_Advanced( $order_total );

		$this->assertEquals(
			array(
				'result'                     => 'success',
				'redirect'                   => '#',
				'amazonCreateCheckoutParams' => wp_json_encode( WC_Mocker_Amazon_Payments_Advanced_API::get_create_checkout_classic_session_config( array( 'test' ) ) ),
				'amazonEstimatedOrderAmount' => wp_json_encode(
					array(
						'amount'       => WC_Amazon_Payments_Advanced::format_amount( $order_total, 2 ),
						'currencyCode' => get_woocommerce_currency(),
					)
				),
			),
			$mock_gateway->process_payment( $order->get_id() )
		);
	}

	/**
	 * Test happy path of classic gateway.
	 *
	 * @return void
	 */
	public function test_estimated_order_amount_format() : void {
		$mock_gateway = new WC_Mocker_Gateway_Amazon_Payments_Advanced( '100.2501' );

		$this->assertEquals(
			wp_json_encode(
				array(
					'amount'       => '100.25',
					'currencyCode' => get_woocommerce_currency(),
				)
			),
			$mock_gateway::get_estimated_order_amount()
		);
	}

	/**
	 * Test maybe_separator_and_checkout_button_single_product method.
	 *
	 * @return void
	 */
	public function test_maybe_separator_and_checkout_button_single_product() : void {
		ob_start();
		self::$gateway->maybe_separator_and_checkout_button_single_product();
		$should_fail_because_no_global_product = ob_get_clean();

		$this->assertEmpty( $should_fail_because_no_global_product );

		$test_product = WC_Helper_Product::create_and_optionally_save_simple_product( true );
		$test_product->set_stock_status( 'outofstock' );
		$test_product->save();

		$GLOBALS['post'] = get_post( $test_product->get_id() );

		ob_start();
		self::$gateway->maybe_separator_and_checkout_button_single_product();
		$should_fail_because_out_of_stock = ob_get_clean();

		$this->assertEmpty( $should_fail_because_out_of_stock );
		$test_product->set_stock_status( 'instock' );
		$test_product->save();

		$GLOBALS['post'] = get_post( $test_product->get_id() );

		ob_start();
		self::$gateway->maybe_separator_and_checkout_button_single_product();
		$should_fail_because_plugin_option_is_not_enabled = ob_get_clean();

		$this->assertEmpty( $should_fail_because_plugin_option_is_not_enabled );

		// Enable gateway and update single button settings.
		$this->make_gateway_available( array( 'product_button' => 'yes' ) );
		self::$gateway->init_settings();

		$expected_for_success = self::$gateway->checkout_button( false, 'div', 'pay_with_amazon_product' );

		ob_start();
		self::$gateway->maybe_separator_and_checkout_button_single_product();
		$actual_markup = ob_get_clean();

		$this->assertEquals( $expected_for_success, $actual_markup );
	}

	/**
	 * Test load_scripts_on_product_pages method.
	 *
	 * @return void
	 */
	public function test_load_scripts_on_product_pages() {
		// If given true it should always return true, no matter what.
		$this->assertTrue( self::$gateway->load_scripts_on_product_pages( true ) );

		// Should return false, since this is not a product page.
		$this->assertFalse( self::$gateway->load_scripts_on_product_pages( false ) );

		$test_product = WC_Helper_Product::create_and_optionally_save_simple_product( true );
		$test_product->set_stock_status( 'outofstock' );
		$test_product->save();

		// Force the global post to the newly created test product.
		$GLOBALS['post'] = get_post( $test_product->get_id() );

		// Force the global query to be a singular product query.
		$GLOBALS['wp_query']->is_singular    = true;
		$GLOBALS['wp_query']->queried_object = $GLOBALS['post'];

		// Scripts should not be loaded since product is out of stock.
		$this->assertFalse( self::$gateway->load_scripts_on_product_pages( false ) );
		$test_product->set_stock_status( 'instock' );
		$test_product->save();

		// Force the global post to the newly created test product.
		$GLOBALS['post'] = get_post( $test_product->get_id() );

		// Scripts should be loaded since product is in stock.
		$this->assertTrue( self::$gateway->load_scripts_on_product_pages( false ) );
	}

	/**
	 * Test the checkout banner and its fragment drop the button on a zero total cart.
	 *
	 * @return void
	 */
	public function test_checkout_message_hides_button_when_cart_needs_no_payment() : void {
		$this->make_gateway_available();
		self::$gateway->init_settings();
		$this->set_amazon_checkout_session( null );

		$this->fill_cart( false );
		$this->assertStringContainsString( 'id="pay_with_amazon"', $this->get_output( array( self::$gateway, 'checkout_message' ) ) );

		$this->fill_cart( true );
		$this->assertFalse( WC()->cart->needs_payment() );

		$message = $this->get_output( array( self::$gateway, 'checkout_message' ) );
		$this->assertStringNotContainsString( 'pay_with_amazon', $message );
		$this->assertStringNotContainsString( 'wc-amazon-payments-advanced-populated', $message );
		$this->assertArrayHasKey( '.wc-amazon-checkout-message.wc-amazon-payments-advanced-populated', self::$gateway->update_amazon_fragments( array() ) );
	}

	/**
	 * Test the checkout banner keeps the Amazon logout notice on a zero total cart.
	 *
	 * @return void
	 */
	public function test_checkout_message_keeps_logout_notice_when_cart_needs_no_payment() : void {
		$this->make_gateway_available();
		self::$gateway->init_settings();
		$this->fill_cart( true );

		$this->set_amazon_checkout_session( 'TEST_CHECKOUT_SESSION_ID' );
		$message = $this->get_output( array( self::$gateway, 'checkout_message' ) );
		$this->set_amazon_checkout_session( null );

		$this->assertStringContainsString( 'amazon-logout', $message );
		$this->assertStringContainsString( 'wc-amazon-payments-advanced-populated', $message );
	}

	/**
	 * Test the cart page and mini-cart buttons are hidden on a zero total cart.
	 *
	 * @return void
	 */
	public function test_cart_buttons_hidden_when_cart_needs_no_payment() : void {
		$this->make_gateway_available( array( 'mini_cart_button' => 'yes' ) );
		self::$gateway->init_settings();
		$this->use_generated_private_key();

		$this->fill_cart( false );
		$this->assertStringContainsString( 'id="pay_with_amazon"', $this->get_output( array( self::$gateway, 'maybe_render_button_and_separator' ) ) );
		$this->assertStringContainsString( 'id="pay_with_amazon_cart"', $this->get_output( array( self::$gateway, 'maybe_separator_and_checkout_button' ) ) );

		$this->fill_cart( true );
		$this->assertEmpty( $this->get_output( array( self::$gateway, 'maybe_render_button_and_separator' ) ) );
		$this->assertEmpty( $this->get_output( array( self::$gateway, 'maybe_separator_and_checkout_button' ) ) );
	}

	/**
	 * Test the buttons stay on a zero total cart that still needs payment.
	 *
	 * @return void
	 */
	public function test_buttons_shown_when_zero_total_cart_needs_payment() : void {
		$this->make_gateway_available();
		self::$gateway->init_settings();
		$this->set_amazon_checkout_session( null );
		$this->fill_cart( true );

		add_filter( 'woocommerce_cart_needs_payment', '__return_true' );
		$message   = $this->get_output( array( self::$gateway, 'checkout_message' ) );
		$cart_page = $this->get_output( array( self::$gateway, 'maybe_render_button_and_separator' ) );
		remove_filter( 'woocommerce_cart_needs_payment', '__return_true' );

		$this->assertStringContainsString( 'id="pay_with_amazon"', $message );
		$this->assertStringContainsString( 'id="pay_with_amazon"', $cart_page );
	}

	/**
	 * Test the order-pay banner ignores the cart.
	 *
	 * @return void
	 */
	public function test_checkout_message_shown_on_order_pay_page() : void {
		global $wp;

		$this->make_gateway_available();
		self::$gateway->init_settings();
		$this->set_amazon_checkout_session( null );
		wc_load_cart();
		WC()->cart->empty_cart();

		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$wp->query_vars['order-pay'] = 1;
		$message                     = $this->get_output( array( self::$gateway, 'checkout_message' ) );
		unset( $wp->query_vars['order-pay'] );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );

		$this->assertStringContainsString( 'id="pay_with_amazon"', $message );
	}

	/**
	 * Fill the cart with a virtual product, optionally discounted to zero.
	 *
	 * @param bool $zero_total Apply a 100% coupon.
	 * @return void
	 */
	protected function fill_cart( bool $zero_total ) : void {
		wc_load_cart();
		WC()->cart->empty_cart();

		$product = WC_Helper_Product::create_and_optionally_save_simple_product( false );
		$product->set_virtual( true );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id() );

		if ( $zero_total ) {
			$coupon = new WC_Coupon();
			$coupon->set_code( 'free' . $product->get_id() );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( 100 );
			$coupon->save();
			WC()->cart->apply_coupon( $coupon->get_code() );
		}

		WC()->cart->calculate_totals();
	}

	/**
	 * Set or clear the Amazon checkout session id.
	 *
	 * @param ?string $checkout_session_id Checkout session id, null to log out.
	 * @return void
	 */
	protected function set_amazon_checkout_session( ?string $checkout_session_id ) : void {
		$checkout_session_key = apply_filters( 'woocommerce_amazon_pa_checkout_session_key', 'amazon_checkout_session_id' );
		WC()->session->set( $checkout_session_key, $checkout_session_id );
	}

	/**
	 * Store a generated private key and drop the cached API client.
	 *
	 * @return void
	 */
	protected function use_generated_private_key() : void {
		openssl_pkey_export( openssl_pkey_new( array( 'private_key_bits' => 2048 ) ), $private_key );
		update_option( WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler::KEYS_OPTION_PRIVATE_KEY, $private_key );

		$client = new ReflectionProperty( 'WC_Amazon_Payments_Advanced_API', 'amazonpay_client' );
		$client->setAccessible( true );
		$client->setValue( null, null );
	}

	/**
	 * Capture what a callback prints.
	 *
	 * @param callable $callback Callback to run.
	 * @return string
	 */
	protected function get_output( callable $callback ) : string {
		ob_start();
		call_user_func( $callback );
		return (string) ob_get_clean();
	}

	/**
	 * Helper method to make the Gateway available.
	 *
	 * @param array $extras Extra settings to update the gateway with.
	 * @return void
	 */
	protected function make_gateway_available( array $extras = array() ) {
		$settings = WC_Amazon_Payments_Advanced_API::get_settings();
		$settings = array_merge(
			$settings,
			array(
				'enabled'        => 'yes',
				'merchant_id'    => 'test_merchant_id',
				'public_key_id'  => 'test_public_key_id',
				'store_id'       => 'test_store_id',
				'payment_region' => 'eu',
			),
			$extras
		);

		update_option( 'woocommerce_amazon_payments_advanced_settings', $settings );
		update_option( WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler::KEYS_OPTION_PRIVATE_KEY, 'TEST_PRIVATE_KEY' );
	}
}
