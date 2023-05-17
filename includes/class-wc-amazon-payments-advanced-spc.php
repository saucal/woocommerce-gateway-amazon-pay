<?php
/**
 * Amazon API SPC class.
 *
 * @package WC_Gateway_Amazon_Pay
 */

/**
 * Amazon Pay Single Page Checkout class.
 */
class WC_Amazon_Payments_Advanced_SPC {

	public static function hooks() {
		add_filter( 'woocommerce_amazon_pa_create_checkout_session_params', array( __CLASS__, 'alter_checkout_params_for_single_page_checkout' ) );
		// add_action( 'wp_footer', array( __CLASS__, 'onboard_auth_credentials' ) );
	}


	public static function alter_checkout_params_for_single_page_checkout( $payload ) {
		$payload['scopes'] = array( 'name', 'email', 'phoneNumber', 'billingAddress' );

		$payload['merchantMetadata']['merchantStoreReferenceId'] = home_url();

		$payload['paymentDetails'] = array(
			'paymentIntent'                 => 'AuthorizeWithCapture',
			'canHandlePendingAuthorization' => false,
		);

		$payload['cartDetails']['cartId'] = WC()->cart->get_cart_hash();

		return $payload;
	}

	/**
	 * Onboard auth Credentials
	 *
	 * @var string
	 */
	public static function onboard_auth_credentials() {
		// $url = 'https://pay-api.amazon.eu/' . $this->env . '/' . self::VERSION . '/singlePageCheckoutDetails';
		$url = 'https://pay-api.amazon.eu/' . 'sandbox' . '/' . 'v2' . '/singlePageCheckoutDetails';

		$payload = array(
			'authDetails' => array(
				'merchantStoreReferenceId' => home_url(),
				'authTimestamp'            => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'authVersion'              => 'OAuth1A',
				'authInformation'          => array(
					array(
						'type'  => 'CONSUMER_KEY',
						'value' => 'ck_0c67cc3d3a29e4d2298eaefc080bedf21e718f86',
					),
					array(
						'type'  => 'CONSUMER_SECRET',
						'value' => 'cs_600ae853d9169b5c9e18cd7e254005ad1024f987',
					),
					array(
						'type'  => 'ACCESS_TOKEN',
						'value' => '',
					),
					array(
						'type'  => 'ACCESS_TOKEN_SECRET',
						'value' => '',
					),
				),
			),
			// 'spiEndpoint' => get_rest_url( null, '/' . $this->namespace . '/' . self::VERSION . '/' ),
			'spiEndpoint' => get_rest_url( null, '/' . 'amazon-payments-advanced' . '/' . 'v1' . '/' ),
		);

		$signed_headers = WC_Amazon_Payments_Advanced_API::get_post_signed_headers( 'POST', $url, '', $payload );

		$headers = array();

		foreach ( $signed_headers as $signed_header ) {
			$signed_header_parts = explode( ':', $signed_header );

			if ( 'authorization' === strtolower( $signed_header_parts[0] ) ) {
				$signature = '';
				$authorization_header_parts = explode( ',', $signed_header_parts[1] );
				foreach ( $authorization_header_parts as $hp ) {
					if ( strstr( $hp, 'Signature=' ) ) {
						$signature = trim( str_replace( 'Signature=', '', $hp ) );
					}
				}

				if ( $signature ) {
					$signed_header_parts[1] = $signature;
				}
			}

			$headers[ $signed_header_parts[0] ] = $signed_header_parts[1];
		}

		echo '<pre>';
		var_dump( $headers );
		echo '</pre>';
		die();

		$args = array(
			'headers' => $headers,
			'body'    => wp_json_encode( $payload ),
		);

		wc_apa()->log(
			'Onboard Auth Credentials Request',
			array(
				'headers' => $headers,
				'payload' => $payload,
			)
		);

		$response = wp_remote_post( $url, $args );

		wc_apa()->log(
			'Onboard Auth Credentials Response',
			$response
		);
	}
}
