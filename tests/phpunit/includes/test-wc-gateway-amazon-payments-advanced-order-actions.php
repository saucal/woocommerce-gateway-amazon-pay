<?php
/**
 * Test cases for the manual Amazon Pay order actions.
 *
 * @package WC_Gateway_Amazon_Pay/Tests
 */

declare(strict_types=1);

/**
 * Covers reconciliation of the mutating order actions against the live Amazon state.
 */
class WC_Gateway_Amazon_Payments_Advanced_Order_Actions_Test extends WP_UnitTestCase {

	const CHARGE_ID            = 'TEST_CHARGE_PERMISSION_ID-C001';
	const CHARGE_PERMISSION_ID = 'TEST_CHARGE_PERMISSION_ID';

	/**
	 * Gateway under test.
	 *
	 * @var ?WC_Gateway_Amazon_Payments_Advanced
	 */
	protected static $gateway;

	/**
	 * Gateway the plugin held before the tests.
	 *
	 * @var mixed
	 */
	protected static $original_gateway;

	/**
	 * Installed SDK client mocker.
	 *
	 * @var ?WC_Mocker_Amazon_Pay_Sdk_Client
	 */
	protected $client;

	/**
	 * Set up the plugin's expectations about the install.
	 *
	 * @return void
	 */
	public static function set_up_before_class() : void {
		parent::set_up_before_class();
		self::$gateway                              = new WC_Gateway_Amazon_Payments_Advanced();
		self::$gateway->settings['payment_capture'] = '';
		self::$original_gateway                     = self::swap_plugin_gateway( self::$gateway );
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
		self::swap_plugin_gateway( self::$original_gateway );
		self::$gateway          = null;
		self::$original_gateway = null;
		delete_option( 'woocommerce_currency' );
		delete_option( 'woocommerce_amazon_payments_new_install' );
		delete_option( 'amazon_api_version' );
		parent::tear_down_after_class();
	}

	/**
	 * Gateway settings before each test.
	 *
	 * @var array
	 */
	protected $settings_backup = array();

	/**
	 * Install the SDK client mocker.
	 *
	 * @return void
	 */
	public function set_up() : void {
		parent::set_up();
		$this->client          = WC_Mocker_Amazon_Pay_Sdk_Client::install();
		$this->settings_backup = self::$gateway->settings;
	}

	/**
	 * Remove the SDK client mocker.
	 *
	 * @return void
	 */
	public function tear_down() : void {
		WC_Mocker_Amazon_Pay_Sdk_Client::uninstall();
		$this->client            = null;
		self::$gateway->settings = $this->settings_backup;
		parent::tear_down();
	}

	/**
	 * An asynchronous authorization is captured once Amazon confirms it.
	 *
	 * @return void
	 */
	public function test_automatic_capture_captures_async_authorization() : void {
		$this->set_capture_settings( 'authorize', 'async', 'yes' );
		$order = $this->create_authorized_order( 'AuthorizationInitiated' );

		$this->client->charge_state = 'Authorized';
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 1, $this->client->call_count( 'captureCharge' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'Captured', $this->cached_charge_state( $order ) );
		$this->assertFalse( $this->is_flagged( $order ) );
	}

	/**
	 * Settings saved before the option existed leave automatic capture off.
	 *
	 * @return void
	 */
	public function test_automatic_capture_is_off_by_default() : void {
		$this->set_capture_settings( 'authorize', 'async', null );
		$order = $this->create_authorized_order( 'AuthorizationInitiated' );

		$this->client->charge_state = 'Authorized';
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 0, $this->client->call_count( 'captureCharge' ) );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Capture combinations the checkbox is hidden for ignore a stale saved value.
	 *
	 * @return array
	 */
	public function non_async_authorize_settings() : array {
		return array(
			'authorize sync' => array( 'authorize', 'sync' ),
			'manual async'   => array( 'manual', 'async' ),
		);
	}

	/**
	 * Automatic capture only applies to Authorize with Asynchronous processing.
	 *
	 * @dataProvider non_async_authorize_settings
	 *
	 * @param  string $payment_capture Payment capture setting.
	 * @param  string $authorization_mode Authorization mode setting.
	 * @return void
	 */
	public function test_automatic_capture_needs_authorize_async( string $payment_capture, string $authorization_mode ) : void {
		$this->set_capture_settings( $payment_capture, $authorization_mode, 'yes' );
		$order = $this->create_authorized_order( 'AuthorizationInitiated' );

		$this->client->charge_state = 'Authorized';
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 0, $this->client->call_count( 'captureCharge' ) );
	}

	/**
	 * Enabling the setting does not capture orders that were already authorized.
	 *
	 * @return void
	 */
	public function test_automatic_capture_skips_orders_already_authorized() : void {
		$order = $this->create_authorized_order();
		$this->set_capture_settings( 'authorize', 'async', 'yes' );

		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 0, $this->client->call_count( 'captureCharge' ) );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * A failed automatic capture is noted, flagged for the admin notice and polled within the hour.
	 *
	 * @return void
	 */
	public function test_failed_automatic_capture_is_flagged() : void {
		$order = $this->fail_automatic_capture();

		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'Authorized', $this->cached_charge_state( $order ) );
		$this->assertTrue( $this->has_note( $order, 'Automatic capture of charge ' . self::CHARGE_ID . ' failed' ) );
		$this->assertTrue( $this->is_flagged( $order ) );

		$next = as_next_scheduled_action( 'wc_amazon_async_polling', array( self::CHARGE_ID, 'CHARGE' ), 'wc_amazon_async_polling' );
		$this->assertIsInt( $next );
		$this->assertLessThanOrEqual( strtotime( '+1 hour' ), $next );
	}

	/**
	 * A capture that throws on a network error is noted, flagged and polled within the hour.
	 *
	 * @return void
	 */
	public function test_automatic_capture_network_error_is_flagged() : void {
		$this->set_capture_settings( 'authorize', 'async', 'yes' );
		$order = $this->create_authorized_order( 'AuthorizationInitiated' );
		wc_apa()->ipn_handler->unschedule_hook( self::CHARGE_ID, 'CHARGE' );

		$this->client->charge_state      = 'Authorized';
		$this->client->capture_exception = 'Unable to send request, underlying exception of Could not resolve host';
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 1, $this->client->call_count( 'captureCharge' ) );
		$this->assertTrue( $this->is_flagged( $order ) );
		$this->assertTrue( $this->has_note( wc_get_order( $order->get_id() ), 'Could not resolve host' ) );
		$next = as_next_scheduled_action( 'wc_amazon_async_polling', array( self::CHARGE_ID, 'CHARGE' ), 'wc_amazon_async_polling' );
		$this->assertIsInt( $next );
		$this->assertLessThanOrEqual( strtotime( '+1 hour' ), $next );
	}

	/**
	 * The next poll retries a failed automatic capture and clears the flag once it succeeds.
	 *
	 * @return void
	 */
	public function test_failed_automatic_capture_is_retried_on_next_poll() : void {
		$order = $this->fail_automatic_capture();

		$this->client->calls           = array();
		$this->client->mutation_status = 200;
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 1, $this->client->call_count( 'captureCharge' ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertFalse( $this->is_flagged( $order ) );
	}

	/**
	 * A retry that fails again tries once per poll and keeps the order flagged.
	 *
	 * @return void
	 */
	public function test_failed_retry_tries_once_and_stays_flagged() : void {
		$order = $this->fail_automatic_capture();

		$this->client->calls = array();
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 1, $this->client->call_count( 'captureCharge' ) );
		$this->assertTrue( $this->is_flagged( $order ) );
		$this->assertSame( 1, $this->count_notes( $order, 'Automatic capture of charge' ) );
	}

	/**
	 * The admin notice links flagged orders and disappears once the capture succeeds.
	 *
	 * @return void
	 */
	public function test_admin_notice_lists_failed_automatic_captures() : void {
		$order = $this->fail_automatic_capture();
		$other = WC_Helper_Order::create_order( self::$gateway->id );

		$this->view_admin_screen( 'edit-shop_order', $order->get_id() );

		$this->assertStringContainsString( '#' . $order->get_order_number() . '</a>', $this->capture_failure_notice() );
		$this->assertStringNotContainsString( '#' . $other->get_order_number() . '</a>', $this->capture_failure_notice() );

		$this->client->mutation_status = 200;
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( '', $this->capture_failure_notice() );
	}

	/**
	 * Opening the order dismisses its notice while the poll keeps retrying the capture.
	 *
	 * @return void
	 */
	public function test_viewing_order_dismisses_notice_and_keeps_retrying() : void {
		$order = $this->fail_automatic_capture();

		$this->view_admin_screen( 'shop_order', $order->get_id() );

		$this->assertSame( '', $this->capture_failure_notice() );

		$this->client->calls = array();
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 1, $this->client->call_count( 'captureCharge' ) );
		$this->assertTrue( $this->is_flagged( $order ) );
		$this->assertSame( '', $this->capture_failure_notice() );

		$this->client->mutation_status = 200;
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertFalse( $this->is_flagged( $order ) );
	}

	/**
	 * Opening the order editor without permission to edit orders keeps the notice.
	 *
	 * @return void
	 */
	public function test_viewing_order_without_order_permission_keeps_notice() : void {
		$order = $this->fail_automatic_capture();

		$this->view_admin_screen( 'shop_order', $order->get_id(), false );

		$this->assertSame( 'yes', wc_get_order( $order->get_id() )->get_meta( WC_Gateway_Amazon_Payments_Advanced::AUTOMATIC_CAPTURE_FAILED ) );
	}

	/**
	 * A manual capture by the merchant clears the flag even with the setting turned off.
	 *
	 * @return void
	 */
	public function test_manual_capture_clears_automatic_capture_failure() : void {
		$order = $this->fail_automatic_capture();
		$this->set_capture_settings( 'authorize', 'async', 'no' );

		$this->client->mutation_status = 200;
		self::$gateway->perform_capture( $order, self::CHARGE_ID );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertFalse( $this->is_flagged( $order ) );
	}

	/**
	 * Turning the setting off keeps a failed order flagged until someone captures it.
	 *
	 * @return void
	 */
	public function test_disabling_setting_keeps_failure_flagged() : void {
		$order = $this->fail_automatic_capture();
		$this->set_capture_settings( 'authorize', 'async', 'no' );

		$this->client->calls = array();
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 0, $this->client->call_count( 'captureCharge' ) );
		$this->assertTrue( $this->is_flagged( $order ) );
	}

	/**
	 * A failed admin action on a flagged order does not retry the automatic capture.
	 *
	 * @return void
	 */
	public function test_failed_admin_action_does_not_retry_automatic_capture() : void {
		$order = $this->fail_automatic_capture();

		$this->client->calls = array();
		$result              = self::$gateway->perform_cancel_auth( $order, self::CHARGE_ID );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 0, $this->client->call_count( 'captureCharge' ) );
		$this->assertTrue( $this->is_flagged( $order ) );
	}

	/**
	 * Order statuses outside pending and on-hold.
	 *
	 * @return array
	 */
	public function not_awaiting_payment_statuses() : array {
		return array(
			'cancelled' => array( 'cancelled' ),
			'trash'     => array( 'trash' ),
			'refunded'  => array( 'refunded' ),
			'failed'    => array( 'failed' ),
		);
	}

	/**
	 * An order moved out of pending or on-hold before Amazon authorizes it is not captured automatically.
	 *
	 * @dataProvider not_awaiting_payment_statuses
	 *
	 * @param  string $status Order status set by the merchant.
	 * @return void
	 */
	public function test_order_not_awaiting_payment_is_not_captured_automatically( string $status ) : void {
		$this->set_capture_settings( 'authorize', 'async', 'yes' );
		$order = $this->create_authorized_order( 'AuthorizationInitiated' );
		$order->update_status( $status );

		$this->client->charge_state = 'Authorized';
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 0, $this->client->call_count( 'captureCharge' ) );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * Moving a flagged order out of pending or on-hold stops its automatic capture retries.
	 *
	 * @dataProvider not_awaiting_payment_statuses
	 *
	 * @param  string $status Order status set by the merchant.
	 * @return void
	 */
	public function test_order_not_awaiting_payment_stops_retries( string $status ) : void {
		$order = $this->fail_automatic_capture();
		$order->update_status( $status );

		$this->client->calls           = array();
		$this->client->mutation_status = 200;
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 0, $this->client->call_count( 'captureCharge' ) );
		$this->assertFalse( $this->is_flagged( $order ) );
	}

	/**
	 * A flagged order whose authorization is closed on Amazon drops out of the notice.
	 *
	 * @return void
	 */
	public function test_closed_authorization_clears_automatic_capture_failure() : void {
		$order = $this->fail_automatic_capture();

		$this->client->charge_state = 'Canceled';
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertFalse( $this->is_flagged( $order ) );
	}

	/**
	 * Capture on a charge Amazon already captured completes the order without capturing again.
	 *
	 * @return void
	 */
	public function test_capture_reconciles_when_amazon_already_captured() : void {
		$order = $this->create_authorized_order();

		$this->client->charge_state    = 'Captured';
		$this->client->mutation_status = 422;

		$result = self::$gateway->perform_capture( $order, self::CHARGE_ID );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 0, $this->client->call_count( 'captureCharge' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'Captured', $this->cached_charge_state( $order ) );
		$this->assertTrue( $this->has_note( $order, 'was already Captured on Amazon' ) );
	}

	/**
	 * Close Authorization on a charge Amazon already captured syncs instead of erroring.
	 *
	 * @return void
	 */
	public function test_cancel_auth_reconciles_when_amazon_already_captured() : void {
		$order = $this->create_authorized_order();

		$this->client->charge_state    = 'Captured';
		$this->client->mutation_status = 422;

		$result = self::$gateway->perform_cancel_auth( $order, self::CHARGE_ID );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 0, $this->client->call_count( 'cancelCharge' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'Captured', $this->cached_charge_state( $order ) );
		$this->assertTrue( $this->has_note( $order, 'was already Captured on Amazon' ) );
	}

	/**
	 * A genuine capture failure still returns a WP_Error and adds no reconcile note.
	 *
	 * @return void
	 */
	public function test_capture_still_errors_on_genuine_failure() : void {
		$order = $this->create_authorized_order();

		$this->client->charge_state    = 'Authorized';
		$this->client->mutation_status = 503;
		$this->client->mutation_reason = 'InternalServerError';

		$result = self::$gateway->perform_capture( $order, self::CHARGE_ID );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 1, $this->client->call_count( 'captureCharge' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $this->has_note( $order, 'was already' ) );
	}

	/**
	 * A capture Amazon accepts is still sent and still completes the order.
	 *
	 * @return void
	 */
	public function test_capture_still_captures_when_amazon_is_authorized() : void {
		$order = $this->create_authorized_order();

		$this->client->charge_state    = 'Authorized';
		$this->client->mutation_status = 200;

		$result = self::$gateway->perform_capture( $order, self::CHARGE_ID );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, $this->client->call_count( 'captureCharge' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertFalse( $this->has_note( $order, 'was already' ) );
	}

	/**
	 * An unreadable charge leaves the order untouched instead of corrupting its status.
	 *
	 * @return void
	 */
	public function test_sync_is_skipped_when_charge_cannot_be_read() : void {
		$order  = $this->create_authorized_order();
		$before = $this->cached_charge_state( $order );

		$this->client->read_status = 404;

		$this->assertNull( self::$gateway->log_charge_status_change( $order ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( $before, $this->cached_charge_state( $order ) );
	}

	/**
	 * Charge states Close Authorization treats as already closed on Amazon.
	 *
	 * @return array
	 */
	public function closed_charge_states() : array {
		return array(
			'canceled' => array( 'Canceled' ),
			'declined' => array( 'Declined' ),
		);
	}

	/**
	 * Close Authorization on a charge Amazon already closed syncs without cancelling again.
	 *
	 * @dataProvider closed_charge_states
	 *
	 * @param  string $state Charge state on Amazon.
	 * @return void
	 */
	public function test_cancel_auth_reconciles_when_amazon_already_closed( string $state ) : void {
		$order = $this->create_authorized_order();

		$this->client->charge_state    = $state;
		$this->client->mutation_status = 422;

		$result = self::$gateway->perform_cancel_auth( $order, self::CHARGE_ID );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 0, $this->client->call_count( 'cancelCharge' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( $state, $this->cached_charge_state( $order ) );
		$this->assertTrue( $this->has_note( $order, "was already $state on Amazon" ) );
	}

	/**
	 * A reconciled capture leaves no charge check scheduled.
	 *
	 * @return void
	 */
	public function test_capture_reconcile_leaves_no_charge_check_pending() : void {
		$order = $this->create_authorized_order();
		$args  = array( self::CHARGE_ID, 'CHARGE' );
		$this->assertNotFalse( as_next_scheduled_action( 'wc_amazon_async_polling', $args, 'wc_amazon_async_polling' ) );

		$this->client->charge_state = 'Captured';
		self::$gateway->perform_capture( $order, self::CHARGE_ID );

		$this->assertFalse( as_next_scheduled_action( 'wc_amazon_async_polling', $args, 'wc_amazon_async_polling' ) );
	}

	/**
	 * A failed order action leaves an order note with Amazon's error.
	 *
	 * @return void
	 */
	public function test_failed_order_action_adds_order_note() : void {
		require_once wc_apa()->path . '/includes/admin/class-wc-amazon-payments-advanced-order-admin.php';
		$order = $this->create_authorized_order();

		$this->client->mutation_status = 503;
		$this->client->mutation_reason = 'InternalServerError';

		( new WC_Amazon_Payments_Advanced_Order_Admin() )->do_order_action( $order, self::CHARGE_ID, 'capture', 'v2' );

		$this->assertTrue( $this->has_note( wc_get_order( $order->get_id() ), 'Amazon Pay action "capture" failed' ) );
	}

	/**
	 * Replace the gateway returned by wc_apa()->get_gateway().
	 *
	 * @param  mixed $gateway Gateway to install.
	 * @return mixed The gateway it replaced.
	 */
	protected static function swap_plugin_gateway( $gateway ) {
		$property = ( new ReflectionClass( wc_apa() ) )->getProperty( 'gateway' );
		$property->setAccessible( true );
		$previous = $property->getValue( wc_apa() );
		$property->setValue( wc_apa(), $gateway );

		return $previous;
	}

	/**
	 * Build an order in the state a deferred authorization leaves behind.
	 *
	 * @param  string $state Charge state on Amazon.
	 * @return WC_Order
	 */
	protected function create_authorized_order( string $state = 'Authorized' ) : WC_Order {
		$order = WC_Helper_Order::create_order( self::$gateway->id );
		$order->update_meta_data( 'amazon_charge_permission_id', self::CHARGE_PERMISSION_ID );
		$order->update_meta_data( 'woocommerce_amazon_payments_advanced_version', WC_AMAZON_PAY_VERSION );
		$order->save();

		$this->client->charge_state = $state;

		self::$gateway->log_charge_permission_status_change( $order, self::CHARGE_PERMISSION_ID );
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( $state, $this->cached_charge_state( $order ) );

		$this->client->calls = array();

		return $order;
	}

	/**
	 * Read the charge state cached on an order.
	 *
	 * @param  WC_Order $order Order object.
	 * @return ?string
	 */
	protected function cached_charge_state( WC_Order $order ) : ?string {
		$order->read_meta_data( true );

		return self::$gateway->get_cached_charge_status( $order, true )->status;
	}

	/**
	 * Check whether any order note contains the given text.
	 *
	 * @param  WC_Order $order Order object.
	 * @param  string   $needle Text to look for.
	 * @return bool
	 */
	protected function has_note( WC_Order $order, string $needle ) : bool {
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( false !== strpos( $note->content, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Set the capture-related gateway settings.
	 *
	 * @param  string      $payment_capture Payment capture setting.
	 * @param  string      $authorization_mode Authorization mode setting.
	 * @param  string|null $automatic_capture Automatic capture setting, null to leave it unsaved.
	 * @return void
	 */
	protected function set_capture_settings( string $payment_capture, string $authorization_mode, ?string $automatic_capture ) : void {
		self::$gateway->settings['payment_capture']    = $payment_capture;
		self::$gateway->settings['authorization_mode'] = $authorization_mode;
		self::$gateway->settings['automatic_capture']  = $automatic_capture;
	}

	/**
	 * Resolve an asynchronous authorization while Amazon rejects the capture.
	 *
	 * @return WC_Order
	 */
	protected function fail_automatic_capture() : WC_Order {
		$this->set_capture_settings( 'authorize', 'async', 'yes' );
		$order = $this->create_authorized_order( 'AuthorizationInitiated' );
		wc_apa()->ipn_handler->unschedule_hook( self::CHARGE_ID, 'CHARGE' );

		$this->client->charge_state    = 'Authorized';
		$this->client->mutation_status = 503;
		$this->client->mutation_reason = 'InternalServerError';
		self::$gateway->log_charge_status_change( $order, self::CHARGE_ID );

		$this->assertSame( 1, $this->client->call_count( 'captureCharge' ) );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Load an admin screen with an order ID in the query string.
	 *
	 * @param  string $screen_id Screen ID.
	 * @param  int    $order_id Order ID.
	 * @param  bool   $can_edit_orders Whether the user can edit orders.
	 * @return void
	 */
	protected function view_admin_screen( string $screen_id, int $order_id, bool $can_edit_orders = true ) : void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once wc_apa()->path . '/includes/admin/class-wc-amazon-payments-advanced-admin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( $can_edit_orders ) {
			wp_get_current_user()->add_cap( 'edit_shop_orders' );
		}

		$_GET['post'] = (string) $order_id;
		set_current_screen( $screen_id );
		( new WC_Amazon_Payments_Advanced_Admin() )->maybe_dismiss_automatic_capture_notice();
		unset( $_GET['post'] );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Get the automatic capture failure notice text, if any.
	 *
	 * @return string
	 */
	protected function capture_failure_notice() : string {
		$method = new ReflectionMethod( 'WC_Amazon_Payments_Advanced_Admin', 'get_admin_notices' );
		$method->setAccessible( true );

		foreach ( $method->invoke( new WC_Amazon_Payments_Advanced_Admin() ) as $notice ) {
			if ( 'amazon_pay_dismiss_automatic_capture_failures' === $notice['dismiss_action'] ) {
				return $notice['text'];
			}
		}

		return '';
	}

	/**
	 * Check whether an order is flagged as a failed automatic capture.
	 *
	 * @param  WC_Order $order Order object.
	 * @return bool
	 */
	protected function is_flagged( WC_Order $order ) : bool {
		return (bool) wc_get_order( $order->get_id() )->get_meta( WC_Gateway_Amazon_Payments_Advanced::AUTOMATIC_CAPTURE_FAILED );
	}

	/**
	 * Count the order notes containing the given text.
	 *
	 * @param  WC_Order $order Order object.
	 * @param  string   $needle Text to look for.
	 * @return int
	 */
	protected function count_notes( WC_Order $order, string $needle ) : int {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		return count(
			array_filter(
				$notes,
				function ( $note ) use ( $needle ) {
					return false !== strpos( $note->content, $needle );
				}
			)
		);
	}
}
