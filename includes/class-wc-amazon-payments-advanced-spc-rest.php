<?php
/**
 * Amazon API SPC class.
 *
 * @package WC_Gateway_Amazon_Pay
 */

/**
 * Amazon Pay Single Page Checkout class.
 */
class WC_Amazon_Payments_Advanced_SPC_Rest extends WC_Amazon_Payments_Advanced_REST_API_Controller {

	const VERSION = 'v1';

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'amazon-payments-advanced';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'cart/(?P<cart_id>[\w]+)/';

	protected $env = 'sandbox';

	/**
	 * Register the routes for order notes.
	 */
	public function register_routes() {

		register_rest_route(
			$this->namespace . '/' . self::VERSION,
			'/' . $this->rest_base . '/address',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_customers_address' ),
					'permission_callback' => array( $this, 'get_edit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace . '/' . self::VERSION,
			'/' . $this->rest_base . '/shipping-method',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_shipping_method' ),
					'permission_callback' => array( $this, 'get_edit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace . '/' . self::VERSION,
			'/' . $this->rest_base . '/coupon',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_coupons' ),
					'permission_callback' => array( $this, 'get_edit_permissions_check' ),
				),
			)
		);
	}

	public function update_customers_address( $request ) {
		$cart_id = $request['cart_id'];

		wc_apa()->log(
			'Update Customers Address Request',
			array(
				'headers' => $request->get_headers(),
				'body'    => $request->get_body(),
				'request' => $request,
				'cart_id' => $cart_id,
			)
		);

		return new WP_REST_Response();
	}

	public function update_shipping_method( $request ) {
		$cart_id = $request['cart_id'];

		wc_apa()->log(
			'Update Customers Shipping Method Request',
			array(
				'headers' => $request->get_headers(),
				'body'    => $request->get_body(),
				'request' => $request,
				'cart_id' => $cart_id,
			)
		);

		return new WP_REST_Response();
	}

	public function update_coupons( $request ) {
		$cart_id = $request['cart_id'];

		wc_apa()->log(
			'Update Customers Coupons Request',
			array(
				'headers' => $request->get_headers(),
				'body'    => $request->get_body(),
				'request' => $request,
				'cart_id' => $cart_id,
			)
		);

		return new WP_REST_Response();
	}
}
