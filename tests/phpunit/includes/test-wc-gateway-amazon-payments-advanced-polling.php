<?php
/**
 * Test cases for async polling scheduling in WC_Gateway_Amazon_Payments_Advanced.
 *
 * @package WC_Gateway_Amazon_Pay/Tests
 */

declare(strict_types=1);

/**
 * WC_Gateway_Amazon_Payments_Advanced_Polling_Test tests when charge polling is scheduled.
 */
class WC_Gateway_Amazon_Payments_Advanced_Polling_Test extends WP_UnitTestCase {

	/**
	 * Polling hook name.
	 *
	 * @var string
	 */
	const HOOK = 'wc_amazon_async_polling';

	/**
	 * Gateway under test.
	 *
	 * @var ?WC_Gateway_Amazon_Payments_Advanced
	 */
	protected $gateway;

	/**
	 * Counter for unique charge ids.
	 *
	 * @var int
	 */
	protected static $counter = 0;

	/**
	 * Set up the gateway instance.
	 *
	 * @return void
	 */
	public function set_up() : void {
		parent::set_up();
		$this->gateway = new WC_Gateway_Amazon_Payments_Advanced();
	}

	/**
	 * Build a stub charge object.
	 *
	 * @param string $charge_id Charge id.
	 * @param string $state     Charge state.
	 *
	 * @return object
	 */
	protected function make_charge( $charge_id, $state ) {
		return (object) array(
			'chargeId'           => $charge_id,
			'chargePermissionId' => 'S01-' . $charge_id,
			'creationTimestamp'  => gmdate( 'Ymd\THis\Z' ),
			'statusDetails'      => (object) array(
				'state'   => $state,
				'reasons' => array(),
			),
		);
	}

	/**
	 * Count pending polls for a charge.
	 *
	 * @param string $charge_id Charge id.
	 *
	 * @return int
	 */
	protected function count_polls( $charge_id ) {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'   => self::HOOK,
					'args'   => array( $charge_id, 'CHARGE' ),
					'status' => ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
	}

	/**
	 * Run log_charge_status_change and return the pending poll count.
	 *
	 * @param string $mode      Capture mode setting.
	 * @param string $state     Charge state.
	 * @param bool   $unchanged Whether the cached status already equals the state.
	 * @param bool   $prequeue  Whether to pre-queue a poll.
	 *
	 * @return array Order and pending poll count.
	 */
	protected function run_case( $mode, $state, $unchanged, $prequeue = false ) {
		self::$counter++;
		$charge_id = 'P01-337-' . self::$counter . '-' . wp_generate_password( 6, false );

		$this->gateway->settings['payment_capture'] = $mode;

		$order = WC_Helper_Order::create_order( 'amazon_payments_advanced' );
		$order->update_meta_data( 'amazon_charge_id', $charge_id );
		if ( $unchanged ) {
			$order->update_meta_data(
				'amazon_charge_status',
				wp_json_encode(
					array(
						'status'  => $state,
						'reasons' => array(),
					)
				)
			);
		}
		$order->save();

		if ( $prequeue ) {
			as_schedule_single_action( time() + 600, self::HOOK, array( $charge_id, 'CHARGE' ), self::HOOK );
		}

		$this->gateway->log_charge_status_change( $order, $this->make_charge( $charge_id, $state ) );

		return array( $order, $this->count_polls( $charge_id ) );
	}

	/**
	 * Authorize mode, Authorized, unchanged does not poll.
	 *
	 * @return void
	 */
	public function test_authorize_authorized_unchanged_does_not_poll() : void {
		list( , $count ) = $this->run_case( 'authorize', 'Authorized', true );
		$this->assertSame( 0, $count );
	}

	/**
	 * Authorize mode, Authorized, changed does not poll and sets on-hold.
	 *
	 * @return void
	 */
	public function test_authorize_authorized_changed_does_not_poll() : void {
		list( $order, $count ) = $this->run_case( 'authorize', 'Authorized', false );
		$this->assertSame( 0, $count );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Authorize mode, Authorized, unchanged removes a pre-queued poll.
	 *
	 * @return void
	 */
	public function test_authorize_authorized_unchanged_unschedules_prequeued() : void {
		list( , $count ) = $this->run_case( 'authorize', 'Authorized', true, true );
		$this->assertSame( 0, $count );
	}

	/**
	 * Manual mode, Authorized, changed does not poll.
	 *
	 * @return void
	 */
	public function test_manual_authorized_changed_does_not_poll() : void {
		list( , $count ) = $this->run_case( 'manual', 'Authorized', false );
		$this->assertSame( 0, $count );
	}

	/**
	 * Default mode, Authorized, unchanged keeps polling.
	 *
	 * @return void
	 */
	public function test_default_authorized_unchanged_polls() : void {
		list( , $count ) = $this->run_case( '', 'Authorized', true );
		$this->assertSame( 1, $count );
	}

	/**
	 * Stored 'no' capture value, Authorized, unchanged keeps polling.
	 *
	 * @return void
	 */
	public function test_no_value_authorized_unchanged_polls() : void {
		list( , $count ) = $this->run_case( 'no', 'Authorized', true );
		$this->assertSame( 1, $count );
	}

	/**
	 * Default mode, Authorized, changed polls.
	 *
	 * @return void
	 */
	public function test_default_authorized_changed_polls() : void {
		list( , $count ) = $this->run_case( '', 'Authorized', false );
		$this->assertSame( 1, $count );
	}

	/**
	 * Authorize mode, AuthorizationInitiated, unchanged keeps polling.
	 *
	 * @return void
	 */
	public function test_authorize_authorization_initiated_unchanged_polls() : void {
		list( , $count ) = $this->run_case( 'authorize', 'AuthorizationInitiated', true );
		$this->assertSame( 1, $count );
	}

	/**
	 * Authorize mode, CaptureInitiated, unchanged keeps polling.
	 *
	 * @return void
	 */
	public function test_authorize_capture_initiated_unchanged_polls() : void {
		list( , $count ) = $this->run_case( 'authorize', 'CaptureInitiated', true );
		$this->assertSame( 1, $count );
	}

	/**
	 * Terminal states remove a pre-queued poll.
	 *
	 * @dataProvider terminal_states
	 *
	 * @param string $state Charge state.
	 *
	 * @return void
	 */
	public function test_terminal_state_unschedules_prequeued( $state ) : void {
		list( , $count ) = $this->run_case( '', $state, true, true );
		$this->assertSame( 0, $count );
	}

	/**
	 * Terminal charge states.
	 *
	 * @return array
	 */
	public function terminal_states() {
		return array(
			'Captured' => array( 'Captured' ),
			'Declined' => array( 'Declined' ),
			'Canceled' => array( 'Canceled' ),
		);
	}
}
