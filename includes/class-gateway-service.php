<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package  Mastercard
 * @version  GIT: @1.4.6@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Http\Client\Common\Exception\ClientErrorException;
use Http\Client\Common\Exception\ServerErrorException;
use Http\Client\Common\HttpClientRouter;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\ContentLengthPlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception;
use Http\Discovery\HttpClientDiscovery;
use Http\Message\Authentication\BasicAuth;
use Http\Message\Formatter;
use Http\Message\Formatter\SimpleFormatter;
use Http\Message\RequestMatcher\RequestMatcher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Http\Promise\Promise;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/logger/class-api-error-plugin.php';
require_once dirname( __DIR__ ) . '/includes/logger/class-api-logger-plugin.php';
require_once dirname( __DIR__ ) . '/includes/logger/class-gateway-response-exception.php';

/**
 * Class Mastercard_GatewayService
 *
 * Represents a gateway service for processing Mastercard transactions.
 */
class Mastercard_GatewayService {
	/**
	 * Message factory variable
	 *
	 * @var MessageFactoryInterface
	 */
	protected $message_factory = null;

	/**
	 * Stream factory variable
	 *
	 * @var StreamFactoryInterface
	 */
	protected $stream_factory = null;

	/**
	 * API endpoint variable
	 *
	 * @var string
	 */
	protected $api_url = null;

	/**
	 * Http client variable
	 *
	 * @var HttpClientRouter
	 */
	protected $client = null;

	/**
	 * Webhook endpoint variable
	 *
	 * @var string|null
	 */
	protected $webhook_url = null;

	/**
	 * GatewayService constructor.
	 *
	 * @param string $base_url Mastercard API Base URL.
	 * @param string $api_version Mastercard API version.
	 * @param string $merchant_id Mastercard merchant ID.
	 * @param string $password Mastercard API password.
	 * @param string $webhook_url Webhook URL.
	 * @param int    $logging_level Logging Level.
	 *
	 * @throws \Exception Throws an exception with the response.
	 */
	public function __construct(
		$base_url,
		$api_version,
		$merchant_id,
		$password,
		$webhook_url,
		$logging_level = \Monolog\Logger::DEBUG
	) {
		$this->webhook_url = $webhook_url;

		$logger = new Logger( 'mastercard' );
		$logger->pushHandler(
			new StreamHandler(
				WP_CONTENT_DIR . '/mastercard.log',
				$logging_level
			)
		);

		$this->message_factory = new Psr17Factory();
		$this->api_url         = 'https://' . $base_url . '/api/rest/' . $api_version . '/merchant/' . $merchant_id . '/';
		$username              = 'merchant.' . $merchant_id;

		$client = new PluginClient(
			HttpClientDiscovery::find(),
			array(
				new ContentLengthPlugin(),
				new HeaderSetPlugin( array( 'Content-Type' => 'application/json;charset=UTF-8' ) ),
				new AuthenticationPlugin( new BasicAuth( $username, $password ) ),
				new Mastercard_ApiErrorPlugin( $logger ),
				new Mastercard_ApiLoggerPlugin( $logger ),
			)
		);

		$request_matcher = new RequestMatcher( null, $base_url );
		$this->client    = new HttpClientRouter();
		$this->client->addClient(
			$client,
			$request_matcher
		);

		if( ! is_admin() ) {
			set_exception_handler( array( $this, 'exception_handler' ) );
		}
	}

	/**
	 * Get the solutions id.
	 *
	 * @return string
	 */
	protected function getSolutionId() { // phpcs:ignore
		return 'WC_' . WC()->version . '_FINGENT_' . MPGS_TARGET_MODULE_VERSION;
	}

	/**
	 * Safely handles a value by applying optional limitations.
	 *
	 * @param mixed $value The value to be handled.
	 * @param int   $limited The optional limitation to be applied.
	 *
	 * @return mixed The safely handled value.
	 */
	public static function safe( $value, $limited = 0 ) {
		if ( '' === $value ) {
			return null;
		}

		if ( $limited > 0 && Tools::strlen( $value ) > $limited ) {
			return Tools::substr( $value, 0, $limited );
		}

		return $value;
	}

	/**
	 * Check if a value is numeric.
	 *
	 * @param mixed $value The value to be checked.
	 *
	 * @return bool True if the value is numeric, false otherwise.
	 */
	public static function numeric( $value ) {
		return number_format( $value, 2, '.', '' );
	}

	/**
	 * Exception handler function.
	 *
	 * @param array $exception The exception data.
	 *
	 * @return void
	 */
	public function exception_handler( $exception ) {
		$message  = '<div class="wc-block-components-notice-banner is-error"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg><div class="wc-block-components-notice-banner__content"><ul><li>';
		$message .= sprintf(
			/* translators: %s: error message */
			__( 'Error: "%s"', 'mastercard' ),
			$exception->getMessage()
		);
		$message .= '</li></ul></div></div>';

		echo wp_kses_post( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Validates the checkout session response.
	 *
	 * @param mixed $data The response data to be validated.
	 *
	 * @return void
	 * @throws Mastercard_GatewayResponseException It throws an exception if a missing or invalid session result.
	 */
	public function validateCheckoutSessionResponse( $data ) { // phpcs:ignore
		if ( ! isset( $data['result'] ) || 'SUCCESS' !== $data['result'] ) {
			if( isset( $data['error']['explanation'] ) ) {
				throw new Mastercard_GatewayResponseException( $data['error']['explanation'] );
			} else {
				throw new Mastercard_GatewayResponseException( 'Missing or invalid session result.' );
			}
		}

		if ( ! isset( $data['session']['id'] ) ) {
			throw new Mastercard_GatewayResponseException( 'Missing session or ID.' );
		}
	}

	/**
	 * This function validates the session response data.
	 *
	 * @param mixed $data The session response data to be validated.
	 *
	 * @return void.
	 * @throws Mastercard_GatewayResponseException It throws an exception if a missing session or ID.
	 */
	public function validateSessionResponse( $data ) { // phpcs:ignore
		if ( ! isset( $data['session']['id'] ) ) {
			throw new Mastercard_GatewayResponseException( 'Missing session or ID.' );
		}
	}

	/**
	 * This function validates the transaction response data.
	 *
	 * @param array $data The transaction response data.
	 *
	 * @return void
	 */
	public function validateTxnResponse( $data ) { // phpcs:ignore
		// @todo
	}

	/**
	 * This function validates the order response data.
	 *
	 * @param mixed $data The order response data to be validated.
	 *
	 * @return void
	 */
	public function validateOrderResponse( $data ) { // phpcs:ignore
		// @todo
	}

	/**
	 * Validate a void response.
	 *
	 * @param mixed $data The data to be validated.
	 *
	 * @return void
	 */
	public function validateVoidResponse( $data ) { // phpcs:ignore
		// @todo
	}

	/**
	 * Check if a response is approved.
	 *
	 * @param mixed $response The response to be checked.
	 *
	 * @return bool True if the response is approved, false otherwise.
	 */
	public function isApproved( $response ) { // phpcs:ignore
		$gateway_code = $response['response']['gatewayCode'];

		if ( ! in_array( $gateway_code, array( 'APPROVED', 'APPROVED_AUTO' ) ) ) { // phpcs:ignore
			return false;
		}

		return true;
	}

	/**
	 * Interprets the authentication response returned from the card Issuer's Access Control Server (ACS)
	 * after the cardholder completes the authentication process. The response indicates the success
	 * or otherwise of the authentication.
	 * The 3DS AuthId is required so that merchants can submit payloads multiple times
	 * without producing duplicates in the database.
	 * POST https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/3DSecureId/{3DSecureId}
	 *
	 * @param string $tds_id Transaction ID.
	 * @param string $pa_res Process Result.
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception It throws an exception if a request is not processed.
	 */
	public function process3dsResult( $tds_id, $pa_res ) { // phpcs:ignore
		$uri     = $this->api_url . '3DSecureId/' . $tds_id;
		$request = $this->message_factory->createRequest(
			'POST',
			$uri
		);
		$stream  = $this->message_factory->createStream(
			wp_json_encode(
				array(
					'apiOperation' => 'PROCESS_ACS_RESULT',
					'3DSecure'     => array(
						'paRes' => $pa_res,
					),
				)
			)
		);

		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);

		return $response;
	}

	/**
	 * Request to check a cardholder's enrollment in the 3DSecure scheme.
	 * PUT https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/3DSecureId/{3DSecureId}
	 *
	 * @param array      $data 3DS array of data.
	 * @param array      $order Order array.
	 * @param array|null $session Session data.
	 * @param array|null $source_of_funds Fund source.
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception It throws an exception if a request is not processed.
	 */
	public function check3dsEnrollment( $data, $order, $session = null, $source_of_funds = array() ) { // phpcs:ignore
		$tds_id  = uniqid(
			sprintf( '3DS-' ),
			true
		);
		$uri     = $this->api_url . '3DSecureId/' . $tds_id;
		$request = $this->message_factory->createRequest(
			'PUT',
			$uri
		);
		$stream  = $this->message_factory->createStream(
			wp_json_encode(
				array(
					'apiOperation'  => 'CHECK_3DS_ENROLLMENT',
					'3DSecure'      => $data,
					'order'         => $order,
					'session'       => $session,
					'sourceOfFunds' => $source_of_funds ? $source_of_funds : null,
				)
			)
		);

		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);

		return $response;
	}


	/**
	 * Initiate Checkout
	 * Request to create a session identifier for the checkout interaction.
	 * The session identifier, when included in the Checkout.configure() function,
	 * allows you to return the payer to the merchant's website after completing the payment attempt.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/session
	 *
	 * @param array $order Order array.
	 * @param array $interaction Customer interaction.
	 * @param array $customer Customer details.
	 * @param array $billing Billing details.
	 * @param array $shipping Shipping details.
	 *
	 * @return array
	 * @throws Exception It throws an exception if a request is not processed.
	 * @throws Mastercard_GatewayResponseException It throws a GatewayResponseException if the checkout initiation is failed.
	 */
	public function initiateCheckout( // phpcs:ignore
		$order = array(),
		$interaction = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$txn_id       = uniqid( sprintf( '%s-', $order['id'] ) );
		$uri          = $this->api_url . 'session';
		$request_data = array(
			'apiOperation'      => 'INITIATE_CHECKOUT',
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge(
				$order,
				array(
					'notificationUrl' => $this->webhook_url,
					'reference'       => $order['id'],
				),
			),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'interaction'       => $interaction,
			'customer'          => $customer,
			'transaction'       => array(
				'reference' => $txn_id,
				'source'    => 'INTERNET',				
			),
		);
		$request      = $this->message_factory->createRequest(
			'POST',
			$uri,
			array()
		);
		$stream       = $this->message_factory->createStream(
			wp_json_encode(
				$request_data
			)
		);
		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);
		$this->validateCheckoutSessionResponse( $response );

		return $response;
	}

	/**
	 * Create Checkout Session
	 * Request to create a session identifier for the checkout interaction.
	 * The session identifier, when included in the Checkout.configure() function,
	 * allows you to return the payer to the merchant's website after completing the payment attempt.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/session
	 *
	 * @param array $order Order array.
	 * @param array $interaction Customer interaction.
	 * @param array $customer Customer details.
	 * @param array $billing Billing details.
	 * @param array $shipping Shipping details.
	 *
	 * @return array Response array.
	 * @throws Exception It throws an exception if checkout session is not created.
	 * @throws Mastercard_GatewayResponseException An exception is thrown when null is returned.
	 *
	 * @todo Remove with Legacy Hosted Checkout
	 */
	public function createCheckoutSession( // phpcs:ignore
		$order = array(),
		$interaction = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$txn_id       = uniqid( sprintf( '%s-', $order['id'] ) );
		$uri          = $this->api_url . 'session';
		$request_data = array(
			'apiOperation'      => 'CREATE_CHECKOUT_SESSION',
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge(
				$order,
				array(
					'notificationUrl' => $this->webhook_url,
					'reference'       => $order['id'],
				)
			),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'interaction'       => $interaction,
			'customer'          => $customer,
			'transaction'       => array(
				'reference' => $txn_id,
				'source'    => 'INTERNET',
			),
		);
		$request      = $this->message_factory->createRequest(
			'POST',
			$uri
		);
		$stream       = $this->message_factory->createStream(
			wp_json_encode(
				$request_data
			)
		);
		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);
		$this->validateCheckoutSessionResponse( $response );

		return $response;
	}

	/**
	 * Request to add or update request fields contained in the session.
	 * PUT    https://test-gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/session/{sessionId}
	 *
	 * @param int   $session_id Session ID.
	 * @param array $order Customer WC_Order details.
	 * @param array $customer Customer details.
	 * @param array $billing Customer billing details.
	 * @param array $shipping Customer shipping details.
	 * @param array $authentication User authentication array.
	 * @param array $token Gateway token array.
	 *
	 * @return mixed
	 * @throws Exception It throws an exception if checkout session is not updated.
	 * @throws Mastercard_GatewayResponseException It throws an exception if checkout session is not updated.
	 */
	public function update_session(
		$session_id,
		$order = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array(),
		$authentication = array(),
		$token = array()
	) {
		$gateway = new Mastercard_Gateway();
		$uri     = $this->api_url . 'session/' . $session_id;
		$params  = array(
			'order_id'   => $gateway->remove_order_prefix( $order['id'] ),
			'session_id' => $session_id,
		);

		if ( ! empty( $authentication ) && ! isset( $authentication['acceptVersions'] ) ) {
			$authentication['redirectResponseUrl'] = add_query_arg(
				'wc-api',
				Mastercard_Gateway::class,
				home_url( '/' )
			) . '&' . http_build_query( $params );
		}

		$request_data = array(
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge(
				$order,
				array(
					'notificationUrl' => $this->webhook_url,
				)
			),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'customer'          => $customer,
			'sourceOfFunds'     => array_merge(
				$token,
				array(
					'type' => 'CARD',
				)
			),
		);

		if ( ! empty( $authentication ) ) {
			$request_data['authentication'] = $authentication;
		}

		$request      = $this->message_factory->createRequest(
			'PUT',
			$uri
		);
		$stream       = $this->message_factory->createStream(
			wp_json_encode(
				$request_data
			)
		);
		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);
		$this->validateSessionResponse( $response );

		return $response;
	}

	/**
	 * Request to create a payment session. A payment session can be used to temporarily store any of the request
	 * fields of operations that allow a session identifier as a request field.
	 * The request fields stored in the session may then be used in these operations by providing the session
	 * identifier. They may be updated and obtained using the Update Session and
	 * Retrieve Session operation respectively.
	 *
	 * POST https://test-gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/session
	 *
	 * @return array Session response array.
	 * @throws Exception It throws an exception if checkout session is not created.
	 */
	public function create_session() {
		$uri      = $this->api_url . 'session';
		$request  = $this->message_factory->createRequest(
			'POST',
			$uri
		);
		$response = $this->client->sendRequest( $request );

		return json_decode(
			$response->getBody(),
			true
		);
	}

	/**
	 * Request to obtain an authorization for a proposed funds transfer.
	 * An authorization is a response from a financial institution indicating that payment information
	 * is valid and funds are available in the payers account.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/order/{order_id}/transaction/{transactionid}
	 *
	 * @param string      $txn_id Transaction ID.
	 * @param string      $order_id WC_Order ID.
	 * @param array       $order WC_Order items.
	 * @param array       $authentication Authentication params.
	 * @param string|null $tds_id 3D Secure Id.
	 * @param array       $session Transaction session details.
	 * @param array       $customer Customer details.
	 * @param array       $billing Customer billing details.
	 * @param array       $shipping Customer shipping details.
	 *
	 * @return mixed|ResponseInterface Response array.
	 * @throws Exception It throws an exception if the transaction is not authorized.
	 */
	public function authorize(
		$txn_id,
		$order_id,
		$order,
		$authentication,
		$tds_id = null,
		$session = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$uri = $this->api_url . 'order/' . $order_id . '/transaction/' . $txn_id;

		$request_data = array(
			'apiOperation'      => 'AUTHORIZE',
			'3DSecureId'        => $tds_id,
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge(
				$order,
				array(
					'notificationUrl' => $this->webhook_url,
					'reference'       => $order_id,
				)
			),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'customer'          => $customer,
			'session'           => $session,
			'transaction'       => array(
				'reference' => $txn_id,
				'source'    => 'INTERNET',
			),
		);

		if ( ! empty( $authentication ) ) {
			$request_data['authentication'] = $authentication;
		}

		$request      = $this->message_factory->createRequest(
			'PUT',
			$uri
		);
		$stream       = $this->message_factory->createStream(
			wp_json_encode(
				$request_data
			)
		);
		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);

		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * A single transaction to authorise the payment and transfer funds from the payer's account to your account.
	 *
	 * For card payments, Pay is a mode where the Authorize and Capture operations are completed at the same time.
	 * Pay is the most common type of payment model used by merchants to accept card payments.
	 * The Pay model is used when the merchant is allowed to bill the cardholder's account immediately,
	 * for example when providing services or goods on the spot.
	 * PUT https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/order/{order_id}/transaction/{transactionid}
	 *
	 * @param string      $txn_id Transaction ID.
	 * @param string      $order_id WC_Order ID.
	 * @param array       $order WC_Order items.
	 * @param array       $authentication Authentication params.
	 * @param string|null $tds_id 3D Secure Id.
	 * @param array       $session Transaction session details.
	 * @param array       $customer Customer details.
	 * @param array       $billing Customer billing details.
	 * @param array       $shipping Customer shipping details.
	 *
	 * @return mixed|ResponseInterface Response array.
	 * @throws Exception It throws an exception if the payment is not completed.
	 */
	public function pay(
		$txn_id,
		$order_id,
		$order,
		$authentication,
		$tds_id = null,
		$session = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$uri = $this->api_url . 'order/' . $order_id . '/transaction/' . $txn_id;

		$request_data = array(
			'apiOperation'      => 'PAY',
			'3DSecureId'        => $tds_id,
			'partnerSolutionId' => $this->getSolutionId(),
			'order'             => array_merge(
				$order,
				array(
					'notificationUrl' => $this->webhook_url,
					'reference'       => $order_id,
				)
			),
			'billing'           => $billing,
			'shipping'          => $shipping,
			'customer'          => $customer,
			'session'           => $session,
			'transaction'       => array(
				'reference' => $txn_id,
				'source'    => 'INTERNET',
			),
		);

		if ( ! empty( $authentication ) ) {
			$request_data['authentication'] = $authentication;
		}

		$request      = $this->message_factory->createRequest(
			'PUT',
			$uri
		);
		$stream       = $this->message_factory->createStream(
			wp_json_encode(
				$request_data
			)
		);
		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);
		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * Retrieve order.
	 * Request to retrieve the details of an order and all transactions associated with this order.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/order/{order_id}
	 *
	 * @param string $order_id Order ID.
	 *
	 * @return array Order details.
	 * @throws \Http\Client\Exception It throws an exception if is not found.
	 */
	public function retrieveOrder( $order_id ) { // phpcs:ignore
		$uri      = $this->api_url . 'order/' . $order_id;
		$request  = $this->message_factory->createRequest(
			'GET',
			$uri
		);
		$response = $this->client->sendRequest( $request );
		$response = json_decode(
			$response->getBody(),
			true
		);
		$this->validateOrderResponse( $response );

		return $response;
	}

	/**
	 * Helper method to find the authorisation transaction.
	 *
	 * @param string $order_id Order ID.
	 * @param array  $response Order details.
	 *
	 * @return null|array
	 * @throws Exception It throws an exception if the authorized transaction is not found.
	 */
	public function getAuthorizationTransaction( $order_id, $response = array() ) { // phpcs:ignore
		if ( empty( $response ) ) {
			$response = $this->retrieveOrder( $order_id );
		}

		// @todo: Find only the first one
		foreach ( $response['transaction'] as $txn ) {
			if ( 'AUTHORIZATION' === $txn['transaction']['type'] && 'SUCCESS' === $txn['result'] ) {
				return $txn;
			}
		}

		return null;
	}

	/**
	 * Helper method to find the capture/pay transaction
	 *
	 * @param string $order_id Order ID.
	 * @param array  $response Order details.
	 *
	 * @return null|array
	 * @throws Exception It throws an exception if the capture transaction is not found.
	 */
	public function getCaptureTransaction( $order_id, $response = array() ) { // phpcs:ignore
		if ( empty( $response ) ) {
			$response = $this->retrieveOrder( $order_id );
		}

		// @todo: Find only the first one
		foreach ( $response['transaction'] as $txn ) {
			if ( ( 'CAPTURE' === $txn['transaction']['type'] || 'PAYMENT' === $txn['transaction']['type'] ) && 'SUCCESS' === $txn['result'] ) {
				return $txn;
			}
		}

		return null;
	}

	/**
	 * Request to retrieve the details of a transaction. For example you can retrieve the details of an authorization that you previously executed.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/order/{order_id}/transaction/{transactionid}
	 *
	 * @param string $order_id Order ID.
	 * @param string $txn_id Transaction ID.
	 *
	 * @return array Response array.
	 * @throws Exception It throws an exception if the transaction is not found.
	 */
	public function retrieveTransaction( $order_id, $txn_id ) { // phpcs:ignore
		$uri      = $this->api_url . 'order/' . $order_id . '/transaction/' . $txn_id;
		$request  = $this->message_factory->createRequest(
			'GET',
			$uri
		);
		$response = $this->client->sendRequest( $request );
		$response = json_decode(
			$response->getBody(),
			true
		);
		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * Request to void a previous transaction. A void will reverse a previous transaction.
	 * Typically voids will only be successful when processed not long after the original transaction.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/order/{order_id}/transaction/{transactionid}
	 *
	 * @param string $order_id Order ID.
	 * @param string $txn_id Transaction ID.
	 *
	 * @return mixed|\Psr\Http\Message\ResponseInterface Transaction response.
	 * @throws Exception It throws an exception if void a previous transaction.
	 */
	public function voidTxn( $order_id, $txn_id ) { // phpcs:ignore
		$new_txn_id = 'void-' . $txn_id;
		$uri        = $this->api_url . 'order/' . $order_id . '/transaction/' . $new_txn_id;
		$request    = $this->message_factory->createRequest(
			'PUT',
			$uri
		);
		$stream     = $this->message_factory->createStream(
			wp_json_encode(
				array(
					'apiOperation'      => 'VOID',
					'partnerSolutionId' => $this->getSolutionId(),
					'transaction'       => array(
						'targetTransactionId' => $txn_id,
						'reference'           => $txn_id,
					),
				)
			)
		);

		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);
		$this->validateVoidResponse( $response );

		return $response;
	}

	/**
	 * Request to capture funds previously reserved by an authorization.
	 * A Capture transaction triggers the movement of funds from the payer's account to the merchant's account.
	 * Typically, a Capture is linked to the authorization through the order_id - you provide the original order_id,
	 * a new transactionId, and the amount you wish to capture.
	 * You may provide other fields (such as shipping address) if you want to update their values; however,
	 * you must NOT provide sourceOfFunds.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/order/{order_id}/transaction/{transactionid}
	 *
	 * @param string $order_id WC_Order ID.
	 * @param string $txn_id Transaction ID.
	 * @param float  $amount Order amount.
	 * @param string $currency Order currency.
	 *
	 * @return mixed|ResponseInterface Capture transaction response.
	 * @throws Exception It throws an exception if capture transaction is failed.
	 */
	public function captureTxn( $order_id, $txn_id, $amount, $currency ) { // phpcs:ignore
		$new_txn_id = 'capture-' . $txn_id;
		$uri        = $this->api_url . 'order/' . $order_id . '/transaction/' . $new_txn_id;
		$request    = $this->message_factory->createRequest(
			'PUT',
			$uri
		);
		$stream     = $this->message_factory->createStream(
			wp_json_encode(
				array(
					'apiOperation'      => 'CAPTURE',
					'partnerSolutionId' => $this->getSolutionId(),
					'transaction'       => array(
						'amount'    => $amount,
						'currency'  => $currency,
						'reference' => $new_txn_id,
					),
					'order'             => array(
						'notificationUrl' => $this->webhook_url,
						'reference'       => $order_id,
					),
				)
			)
		);

		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);
		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * Request to refund previously captured funds to the payer.
	 * Typically, a Refund is linked to the Capture or Pay through the order_id - you provide the original order_id,
	 * a new transactionId, and the amount you wish to refund. You may provide other fields if you want to update their values;
	 * however, you must NOT provide sourceOfFunds.
	 * In rare situations, you may want to refund the payer without associating the credit to a previous transaction (see Standalone Refund).
	 * In this case, you need to provide the sourceOfFunds and a new order_id.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/order/{order_id}/transaction/{transactionid}
	 *
	 * @param string $order_id WC_Order ID.
	 * @param string $txn_id Transaction ID.
	 * @param float  $amount Order amount.
	 * @param string $currency Order currency.
	 *
	 * @return mixed|ResponseInterface Refund transaction response.
	 * @throws Exception It throws an exception if capture transaction is failed.
	 */
	public function refund( $order_id, $txn_id, $amount, $currency ) {
		$new_txn_id = 'refund-' . $txn_id;
		$uri        = $this->api_url . 'order/' . $order_id . '/transaction/' . $new_txn_id;
		$request    = $this->message_factory->createRequest(
			'PUT',
			$uri
		);
		$stream     = $this->message_factory->createStream(
			wp_json_encode(
				array(
					'apiOperation'      => 'REFUND',
					'partnerSolutionId' => $this->getSolutionId(),
					'transaction'       => array(
						'amount'    => $amount,
						'currency'  => $currency,
						'reference' => $new_txn_id,
					),
					'order'             => array(
						'notificationUrl' => $this->webhook_url,
						'reference'       => $order_id,
					),
				)
			)
		);

		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode(
			$response->getBody(),
			true
		);
		$this->validateTxnResponse( $response );

		return $response;
	}

	/**
	 * Request to retrieve the options available for processing a payment, for example, the credit cards and currencies.
	 * https://mtf.gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/paymentOptionsInquiry.
	 *
	 * @return array $response Payment options response.
	 * @throws Exception An exception is thrown when null is returned.
	 */
	public function paymentOptionsInquiry() { // phpcs:ignore
		$uri      = $this->api_url . 'paymentOptionsInquiry';
		$request  = $this->message_factory->createRequest(
			'POST',
			$uri
		);
		$response = $this->client->sendRequest( $request );
		$response = json_decode(
			$response->getBody(),
			true
		);

		return $response;
	}

	/**
	 * Request for the gateway to store payment instrument (e.g. credit or debit cards, gift cards,
	 * ACH bank account details) against a token, where the system generates the token id.
	 * https://eu-gateway.mastercard.com/api/rest/version/73/merchant/{merchantId}/token
	 *
	 * @param string $session_id Session ID.
	 *
	 * @return mixed|ResponseInterface Token details.
	 * @throws Exception An exception is thrown when create card token is failed.
	 */
	public function createCardToken( $session_id ) { // phpcs:ignore
		$uri     = $this->api_url . 'token';
		$request = $this->message_factory->createRequest(
			'POST',
			$uri
		);
		$stream  = $this->message_factory->createStream(
			wp_json_encode(
				array(
					'session'       => array(
						'id' => $session_id,
					),
					'sourceOfFunds' => array(
						'type' => 'CARD',
					),
				)
			)
		);

		$request_body = $request->withBody( $stream );
		$response     = $this->client->sendRequest( $request_body );
		$response     = json_decode( $response->getBody(), true );

		return $response;
	}
}
