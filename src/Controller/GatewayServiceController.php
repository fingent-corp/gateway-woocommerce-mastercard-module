<?php
namespace Fingent\Mastercard\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Fingent\Mastercard\Logger\ApiErrorPlugin;
use Fingent\Mastercard\Logger\ApiLoggerPlugin;
use Fingent\Mastercard\Logger\GatewayResponseException;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Controller\GatewayController;
use Fingent\Mastercard\Controller\PaymentController;
use Fingent\Mastercard\Helper\CheckoutBuilder;


/**
 * Class Mastercard_GatewayService
 *
 * Represents a gateway service for processing Mastercard transactions.
 */
class GatewayServiceController {
	/**
	 * Singleton instance.
	 *
	 * @var GatewayServiceController|null
	 */
	private static ?GatewayServiceController $instance = null;

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
	 * Webhook endpoint variable
	 *
	 * @var string|null
	 */
	protected $username;

	/**
	 * Webhook endpoint variable
	 *
	 * @var string|null
	 */
	protected $password;
	
	/**
	 * GatewayServiceController Instance.
	 *
	 * @return GatewayServiceController instance.
	 */

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * GatewayServiceController constructor.
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
		$webhook_url,
		$logger,
		$message_factory,
		$client,
		$request_matcher,
		$http_client
	) {
		$this->webhook_url     = $webhook_url;
		$this->message_factory = $message_factory;
		$this->api_url         = 'https://' . $base_url . '/api/rest/' . $api_version . '/merchant/' . $merchant_id . '/';
		$this->username        = 'merchant.' . $merchant_id;
		$this->client          = $http_client;
		
		$this->client->addClient(
			$client,
			$request_matcher
		);	
	}

	/**
	 * Get the solutions id.
	 *
	 * @return string
	 */
	protected function getSolutionId() { // phpcs:ignore
		return 'WC_' . WC()->version . '_FINGENT_' . MG_ENTERPRISE_MODULE_VERSION;
	}

	/**
	 * Safely handles a value by applying optional limitations.
	 *
	 * @param mixed $value The value to be handled.
	 * @param int   $limited The optional limitation to be applied.
	 *
	 * @return mixed The safely handled value.
	 */
	public static function is_safe( $value, $limited = 0 ) {
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
	 * Validates the checkout session response.
	 *
	 * @param mixed $data The response data to be validated.
	 *
	 * @return void
	 * @throws GatewayResponseException It throws an exception if a missing or invalid session result.
	 */
	public function validateCheckoutSessionResponse( $data ) { // phpcs:ignore
		if ( ! isset( $data['result'] ) || 'SUCCESS' !== $data['result'] ) {
			if( isset( $data['error']['explanation'] ) ) {
				throw new GatewayResponseException( $data['error']['explanation'] );
			} else {
				throw new GatewayResponseException( 'Missing or invalid session result.' );
			}
		}

		if ( ! isset( $data['session']['id'] ) ) {
			throw new GatewayResponseException( 'Missing session or ID.' );
		}
	}

	/**
	 * This function validates the session response data.
	 *
	 * @param mixed $data The session response data to be validated.
	 *
	 * @return void.
	 * @throws GatewayResponseException It throws an exception if a missing session or ID.
	 */
	public function validateSessionResponse( $data ) { // phpcs:ignore
		if ( ! isset( $data['session']['id'] ) ) {
			throw new GatewayResponseException( 'Missing session or ID.' );
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
		return $data;
	}

	/**
	 * This function validates the order response data.
	 *
	 * @param mixed $data The order response data to be validated.
	 *
	 * @return void
	 */
	public function validateOrderResponse( $data ) { // phpcs:ignore
		return $data;
	}

	/**
	 * Validate a void response.
	 *
	 * @param mixed $data The data to be validated.
	 *
	 * @return void
	 */
	public function validateVoidResponse( $data ) { // phpcs:ignore
		return $data;
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
	 * @throws GatewayResponseException It throws a GatewayResponseException if the checkout initiation is failed.
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
	 * @throws GatewayResponseException An exception is thrown when null is returned.
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
	 * @throws GatewayResponseException It throws an exception if checkout session is not updated.
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
		$uri     = $this->api_url . 'session/' . $session_id;

		if ( ! empty( $authentication ) && ! isset( $authentication['acceptVersions'] ) ) {
			$authentication['redirectResponseUrl'] = add_query_arg(
				array(
				    'wc-api'     => MG_ENTERPRISE_ID,
				    'order_id'   => PaymentController::get_instance()->remove_order_prefix( $order['id'] ),
				    'session_id' => $session_id
				), home_url( '/' )
			);
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
	 * @param array       $surcharge WC_Order Surcharge items.
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
		$surcharge,
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

		if( $surcharge[ 'amount' ] > 0 ) {
			$request_data['order']['merchantCharge'] = $surcharge;
		}

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
	 * @param array       $surcharge WC_Order Surcharge items.
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
		$surcharge,
		$authentication,
		$tds_id = null,
		$session = array(),
		$customer = array(),
		$billing = array(),
		$shipping = array()
	) {
		$uri          = $this->api_url . 'order/' . $order_id . '/transaction/' . $txn_id;
		
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

		if( $surcharge[ 'amount' ] > 0 ) {
			$request_data['order']['merchantCharge'] = $surcharge;
		}

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
	 * Request to capture the status of the installed plugin from client server.
	 * https://dev-wiki.fingent.net/wp-json/mpgs/v2/update-repo-status.
	 *
	 * @return array $response Shop Details.
	 * @throws Exception An exception is thrown when null is returned.
	 */
	public function sendCaptureRequest(
	    string $repoName,
	    string $pluginType,
	    string $tagName,
	    string $latestRelease,
	    string $countryCode,
	    string $countryName,
	    string $shopName,
	    string $shopUrl,
	    string $apiToken,
	    string $apiUrl
	): array {
	    $payload = [
	        'repo_name'      => $repoName,
	        'plugin_type'    => $pluginType,
	        'tag_name'       => $tagName,
	        'latest_release' => $latestRelease,
	        'country_code'   => $countryCode,
	        'country'        => $countryName,
	        'shop_name'      => $shopName,
	        'shop_url'       => $shopUrl,
	    ];

	    $headers = [
	        'Authorization' => 'Bearer ' . $apiToken,
	        'Content-Type'  => 'application/json',
	    ];

	    try {
	        $response = wp_remote_post($apiUrl, [
	            'body'    => json_encode($payload),
	            'headers' => $headers,
	            'timeout' => 15,
	        ]);

	        if (is_wp_error($response)) {
	            return ['error' => 'Request failed: ' . $response->get_error_message()];
	        }

	        $body = wp_remote_retrieve_body($response);
	        $data = json_decode($body, true);

	        return is_array($data) ? $data : ['error' => 'Invalid response format'];

	    } catch (Exception $e) {
	        return ['error' => 'Exception: ' . $e->getMessage()];
	    }
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

	/**
	 * Retrieves card type information associated with a given token ID
	 * by making a GET request to the Simplify API.
	 *
	 * @param string $token_id The unique identifier for the payment token.
	 * @return array|null The decoded JSON response containing card details,
	 *                    or null if the response is empty or invalid.
	 */
	public function getCardType( $token_id ) { // phpcs:ignore
		$uri     = $this->api_url . 'token/' . $token_id ;
		$request = $this->message_factory->createRequest(
			'GET',
			$uri
		);
		$response     = $this->client->sendRequest( $request );
		$response     = json_decode( $response->getBody(), true );

		return $response;
	}

	/**
	 * Generate a secure Pay By Link URL for a WooCommerce order.
	 *
	 * This method communicates with the Mastercard API to initiate a checkout session
	 * in "PAYMENT_LINK" mode. It builds all necessary order, billing, shipping,
	 * and interaction details, sends a request to the API, saves the returned
	 * payment link details to the order meta, and optionally triggers the
	 * "Payment Request" WooCommerce email.
	 *
	 * @param array $order       Array containing order details, must include 'id'.
	 * @param array $interaction Optional interaction data (overridden by CheckoutBuilder).
	 * @param array $customer    Optional customer data (overridden by CheckoutBuilder).
	 * @param array $billing     Optional billing data (overridden by CheckoutBuilder).
	 * @param array $shipping    Optional shipping data (overridden by CheckoutBuilder).
	 * @return array The API response body decoded as an associative array.
	 */
	public function GenerateSecureURL(
		$order		 = array(),
		$interaction = array(),
		$customer    = array(),
		$billing     = array(),
		$shipping    = array()
	) {
		$gateway = MastercardGateway::get_instance();
		$orderdet 	= wc_get_order( $order['id'] );
		if ( $orderdet->meta_exists( '_pay_by_link_is_expired' ) ) {
			$orderdet->delete_meta_data( '_pay_by_link_is_expired' );
		}
		$return_url = add_query_arg(
			[
				'wc-api'   => MG_ENTERPRISE_ID,
				'order_id' => $orderdet->get_id(),
			],
			home_url( '/' )
		);
		$order_builder = new CheckoutBuilder( $order['id'] );
		$capture = ($gateway->settings['txn_mode'] === 'capture');
		$interaction   = $order_builder->getInteraction( $capture, $return_url );
		$billing       = $order_builder->getBillingFromOrder( $order['id'] );
		$shipping      = $order_builder->getShippingFromOrder( $order['id'] );
		$payeer_link_order_details = $order_builder->getPaymentLinkOrder( $order['id'] );
		$uri        	= $this->api_url . 'session';
		$request_data = [
			'apiOperation'      => 'INITIATE_CHECKOUT',
			'partnerSolutionId' =>  $this->getSolutionId(),
			'checkoutMode'      => 'PAYMENT_LINK',
			'billing'           => $billing,
			'shipping'          => $shipping,
			'interaction'       => $interaction,
			'order'             => $payeer_link_order_details,
			'paymentLink'       => $order_builder->getPaymentLinkSettings(),
		];
		
		$request 				= $this->message_factory->createRequest(
			'POST',
			$uri,
			array()
		);

		$stream       = $this->message_factory->createStream( wp_json_encode( $request_data ) );
		$request_body = $request->withBody( $stream );

		$response = $this->client->sendRequest( $request_body );
		$response = json_decode( $response->getBody(), true );

		$mail_sent = false;
		if ( isset( $response['result'] ) && strtoupper( $response['result'] ) === 'SUCCESS' ) {
			$expiry_raw = $response['paymentLink']['expiryDateTime'];
			if ( ! empty( $response['paymentLink']['url'] ) ) {
				$orderdet->update_meta_data( '_pay_by_link_url', esc_url_raw( $response['paymentLink']['url'] ) );
			}
			if ( ! empty( $response['paymentLink']['expiryDateTime'] ) ) {
				$orderdet->update_meta_data( '_pay_by_link_expiry_date_time', $expiry_raw );
			}
			if ( ! empty( $response['paymentLink']['id'] ) ) {
				$orderdet->update_meta_data( '_pay_by_link_id', sanitize_text_field( $response['paymentLink']['id'] ) );
			}
			if ( ! empty( $response['paymentLink']['numberOfAllowedAttempts'] ) ) {
				$orderdet->update_meta_data( '_pay_by_link_number_of_attempts', sanitize_text_field( $response['paymentLink']['numberOfAllowedAttempts'] ) );
			}
			if ( ! empty( $response['successIndicator'] ) ) {
				$orderdet->update_meta_data( '_pay_by_link_indicator', sanitize_text_field( $response['successIndicator'] ) );
			}
			$orderdet->save();
			$mailer = WC()->mailer();
			$emails = $mailer->get_emails();
			if ( ! empty( $emails['WC_Email_Pay_By_Link'] ) ) {
				$custom_email = $emails['WC_Email_Pay_By_Link'];
				$pay_link_url      = $orderdet->get_meta('_pay_by_link_url');
				$expiry_date_time  = $orderdet->get_meta('_pay_by_link_expiry_date_time');
				$allowed_attempts  = $orderdet->get_meta('_pay_by_link_number_of_attempts');
				$custom_email->payment_link     = $pay_link_url;
				$custom_email->expiry_date_time = $expiry_date_time;
				$custom_email->allowed_attempts = $allowed_attempts;
				$mail_sent = (bool) $custom_email->trigger( $orderdet->get_id() );
			}
			$response['mail_sent'] = $mail_sent;
		}

		return $response;
	}

	/**
	 * Revoke a Pay By Link payment for a specific WooCommerce order.
	 *
	 * This method sends a DELETE request to the payment gateway API to revoke
	 * the previously generated payment link. Upon successful revocation, it
	 * cleans up order meta and optionally triggers the "Payment Revoked" email.
	 *
	 * @param array $order Array containing order details, must include 'id'.
	 * @return array The API response body decoded as an associative array.
	 */
	public function RevokePaymentLink( $order = array() ) {
		$orderdet = wc_get_order( $order['id'] );
		$payment_link_id = $orderdet->get_meta( '_pay_by_link_id' );
		
		if ( empty( $payment_link_id ) ) {
			return [
				'result' => 'ERROR',
				'message' => 'No payment link found for this order.'
			];
		}

		$uri = $this->api_url . 'link/' . $payment_link_id;

		$request = $this->message_factory->createRequest(
			'DELETE',
			$uri,
			array()
		);

		$response = $this->client->sendRequest( $request );
		$response_body = json_decode( $response->getBody(), true );

		$mail_sent = false;
		if ( isset( $response_body['result'] ) && strtoupper( $response_body['result'] ) === 'SUCCESS' ) {
			$orderdet->delete_meta_data( '_pay_by_link_url' );
			$orderdet->delete_meta_data( '_pay_by_link_id' );
			$orderdet->delete_meta_data( '_pay_by_link_indicator' );
			$orderdet->save();
			$mailer = WC()->mailer();
			$emails = $mailer->get_emails();
			if ( ! empty( $emails['WC_Email_Pay_By_Link_Revoked'] ) ) {
				$custom_email = $emails['WC_Email_Pay_By_Link_Revoked'];
				$mail_sent = (bool) $custom_email->trigger( $orderdet->get_id() );
			}
			$response_body['mail_sent'] = $mail_sent;
		}

		return $response_body;
	}
}
