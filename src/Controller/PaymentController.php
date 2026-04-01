<?php
namespace Fingent\Mastercard\Controller;

use WC_Order;
use Fingent\Mastercard\Logger\ApiErrorPlugin;
use Fingent\Mastercard\Logger\ApiLoggerPlugin;
use Fingent\Mastercard\Logger\GatewayResponseException;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\View\CheckoutView;
use Fingent\Mastercard\Controller\FrontendController;
use Fingent\Mastercard\Controller\GatewayController;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Core\PaymentTokenCC;
use Fingent\Mastercard\Helper\CheckoutBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class PaymentController {
	/**
	 * Singleton instance.
	 *
	 * @var PaymentController|null
	 */
	private static ?PaymentController $instance = null;

	/**
	 * MastercardGateway
	 *
	 * @var MastercardGateway
	 */
	protected MastercardGateway $gateway;

	/**
	 * FrontendController
	 *
	 * @var FrontendController
	 */
	protected FrontendController $frontend;

	/**
	 * UtilityController
	 *
	 * @var UtilityController
	 */
	protected UtilityController $utility;

	/**
	 * GatewayController
	 *
	 * @var GatewayController
	 */
	protected $service;

	/**
	 * PaymentController Instance.
	 *
	 * @return PaymentController instance.
	 */
	public static function get_instance(): PaymentController {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * PaymentController constructor.
	 */
	public function __construct() {
		$this->gateway  = MastercardGateway::get_instance();
		$this->frontend = FrontendController::get_instance();
		$this->utility  = UtilityController::get_instance();

		add_action( 'woocommerce_receipt_' . MG_ENTERPRISE_ID, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_mastercard_gateway', array( $this, 'return_handler' ) );
	}

	/**
	 * Shortcut to get logger instance.
	 *
	 * @return \Psr\Log\LoggerInterface|null
	 */
	protected function get_logger() {
		return GatewayController::get_instance()->get_logger();
	}

	/**
	 * Logs a message with context in a consistent format.
	 *
	 * @param string $message
	 * @param array  $context
	 * @param string $level
	 */
	protected function log( string $message, array $context = [], string $level = 'info' ): void {
		$logger = $this->get_logger();

		if ( $logger ) {
			// Always include class name for traceability
			$context = array_merge(
				[ 'source' => __CLASS__ ],
				$context
			);

			$logger->{$level}( $message, $context );
		}
	}

	/**
	 * Generate the receipt page for a given order ID.
	 *
	 * @param int $order_id The ID of the order for which the receipt page is generated.
	 *
	 * @return void
	 */
	public function receipt_page( $order_id ) {

		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return;
		}

		if ( HOSTED_SESSION === $this->gateway->method ) {
        	$view = new CheckoutView( $order, array( 'method' => 'session' ) );
        } else {
        	$view = new CheckoutView( $order, array( 'method' => 'checkout' ) );
        }

        $view->render();
		
	}

	/**
	 * This function generates the payment return URL for a given order ID and parameters.
	 *
	 * @param int   $order_id The ID of the order for which the payment return URL is generated.
	 * @param array $params Additional parameters to be included in the return URL (optional).
	 *
	 * @return string The generated payment return URL.
	 */
	public function get_payment_return_url( $order_id, $params = array() ) {
		$params = array_merge(
			array(
				'order_id' => $order_id,
			),
			$params
		);
		
		return add_query_arg( 'wc-api', MG_ENTERPRISE_ID, home_url( '/' ) ) . '&' . http_build_query( $params );
	}

	/**
	 * This function adds a prefix to an order ID.
	 *
	 * @param string $order_id The original order ID.
	 *
	 * @return string The order ID with the prefix added.
	 */
	public function add_order_prefix( $order_id ) {
		if ( $this->gateway->order_prefix ) {
			$order_id = $this->gateway->order_prefix . $order_id;
		}

		return $order_id;
	}

	/**
	 * This function processes a REST route and request.
	 *
	 * @param string $route The REST route to be processed.
	 * @param array  $request The request data associated with the route.
	 *
	 * @return mixed The processed result of the route and request.
	 *
	 * @throws GatewayResponseException If the route or request is invalid or if an error occurs during processing.
	 */
	public function rest_route_processor( $route, $request ) { 
			
		$result = null;
		$this->service  = GatewayController::get_instance()->init_service();
		
		switch ( $route ) {
			case ( (bool) preg_match( '~/mastercard/v1/checkoutSession/\d+~', $route ) ):
				$order         = new WC_Order( $request->get_param( 'id' ) );
				$return_url    = $this->get_payment_return_url( $order->get_id() );
				$order_builder = new CheckoutBuilder( $order );
				$result        = $this->service->initiateCheckout(
					$order_builder->getHostedCheckoutOrder(),
					$order_builder->getInteraction(
						$this->gateway->capture,
						$return_url
					),
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);

				// Proceed if the result has a successIndicator
				if ( $result && isset( $result['successIndicator'] ) ) {
					$this->log(
						'Hosted checkout payment request data',
						[
							'order_id'           => $order->get_id(),
							'session_id'         => $result['session']['id'],
							'success_indicator'  => $result['successIndicator'],
						]
					);
					
					$order->update_meta_data( '_mpgs_success_indicator_initial', $result['successIndicator'] );
				}
				
				$order->save_meta_data();
				break;

			case ( (bool) preg_match( '~/mastercard/v1/savePayment/\d+~', $route ) ):
				$order         = new WC_Order( $request->get_param( 'id' ) );
				$save_new_card = ( 'true' === $request->get_param( 'save_new_card' ) );

				if ( $save_new_card ) {
					$order->update_meta_data( '_save_card', true );
					$order->save_meta_data();
				}

				$auth = array();

				if ( $this->gateway->threedsecure_v1 ) {
					$auth = array(
						'acceptVersions' => '3DS1',
					);
				}

				if ( $this->gateway->threedsecure_v2 ) {
					$auth = array(
						'channel' => 'PAYER_BROWSER',
						'purpose' => 'PAYMENT_TRANSACTION',
					);
				}
				$session_id    = $order->get_meta( '_mpgs_session_id' ); 
				$order_builder = new CheckoutBuilder( $order );
				$result        = $this->service->update_session(
					$session_id,
					$order_builder->getHostedCheckoutOrder(),
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping(),
					$auth,
					$this->get_token_from_request()
				);

				if ( $result && isset( $result['successIndicator'] ) ) {
					$logger = GatewayController::get_instance()->get_logger();
					$logger->info('Session ID: ' . ($result['session']['id'] ?? 'N/A'));
    				$logger->info('Success Indicator: ' . $result['successIndicator']);
					
					if ( $order->meta_exists( '_mpgs_success_indicator' ) ) {
						$order->update_meta_data( '_mpgs_success_indicator', $result['successIndicator'] );
					} else {
						$order->add_meta_data( '_mpgs_success_indicator', $result['successIndicator'], true  );
					}	
				}

				if ( isset( $result['sourceOfFunds']['token'] ) ) {
					$token = $result['sourceOfFunds']['token'];					
				
					if ( $order->meta_exists( '_mpgs_current_token' ) ) {
						$order->update_meta_data( '_mpgs_current_token', $token );
					} else {
						$order->add_meta_data( '_mpgs_current_token', $token, true );
					}
				}
				
				$order->save_meta_data();
				break;

			case ( (bool) preg_match( '~/mastercard/v1/session/\d+~', $route ) ):
				$order  = new WC_Order( $request->get_param( 'id' ) );
				$result = $this->service->create_session();

				if ( $order->meta_exists( '_mpgs_session_id' ) ) {
					$order->update_meta_data( '_mpgs_session_id', $result['session']['id'] );
				} else {
					$order->add_meta_data( '_mpgs_session_id', $result['session']['id'], true );
				}
				$order->save_meta_data();
				break;

			case '/mastercard/v1/webhook':
				$this->webhook_handler( $request );
				break;
	
			default:
				break;	
		}

		return $result;
	}

	/**
	 * Handles the return response from the payment gateway after 3DS (Three-Domain Secure) authentication.
	 *
	 * This method is triggered when the customer is redirected back from the payment gateway.
	 * It handles the result of the 3DS verification, determines if the payment should proceed,
	 * and processes the order accordingly for hosted session or hosted checkout flows.
	 *
	 * Flow:
	 * 1. Clean output buffer and send 200 OK header.
	 * 2. Retrieve and sanitize the `gatewayRecommendation` response.
	 *    - If the recommendation is 'PROCEED':
	 *        a. Extract the 3DS transaction ID from the request.
	 *    - If not:
	 *        a. Mark the order as failed and show an error notice.
	 *        b. Redirect the customer back to the checkout page.
	 * 3. Based on the current payment method:
	 *    - If using HOSTED_SESSION: process the hosted session payment using the 3DS transaction ID.
	 *    - If using HOSTED_CHECKOUT (legacy): process the hosted checkout payment.
	 *
	 * @return void
	 */
	public function return_handler() {
		ob_clean();
		header( 'HTTP/1.1 200 OK' );

		$three_ds_txn_id        = null;
		$gateway_recommendation = isset( $_REQUEST['response_gatewayRecommendation'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['response_gatewayRecommendation'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $gateway_recommendation ) {
			if ( 'PROCEED' === $gateway_recommendation ) {
				if ( isset( $_REQUEST['transaction_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$three_ds_txn_id = sanitize_text_field( wp_unslash( $_REQUEST['transaction_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			} else {
				if ( isset( $_REQUEST['order_id'] ) ) { // phpcs:ignore
					$order_id = sanitize_text_field( wp_unslash( $_REQUEST['order_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

					if ( $order_id ) {
						$order = new WC_Order( $this->remove_order_prefix( $order_id ) ); // phpcs:ignore
						$order->update_status(
							'failed',
							__( '3DS authorization was not provided. Payment declined.', MG_ENTERPRISE_TEXTDOMAIN )
						);
						wc_add_notice(
							__( '3DS authorization was not provided. Payment declined.', MG_ENTERPRISE_TEXTDOMAIN ),
							'error'
						);
					}
				}
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}
		}

		if ( HOSTED_SESSION === $this->gateway->method ) {
			$this->process_hosted_session_payment( $three_ds_txn_id );
		}

		/**
		 * Remove branching after Legacy Hosted Checkout removal
		 *
		 * @todo Remove branching after Legacy Hosted Checkout removal
		 */
		if ( in_array( $this->gateway->method, array( HOSTED_CHECKOUT ), true ) ) {
			$this->process_hosted_checkout_payment();
		}
	}

	/**
	 * Function to remove the order prefix from an order ID.
	 *
	 * @param string $order_id The order ID with the prefix.
	 *
	 * @return string The order ID without the prefix.
	 */
	public function remove_order_prefix( $order_id ) {
		if (  $this->gateway->order_prefix && strpos( $order_id,  $this->gateway->order_prefix ) === 0 ) {
			$order_id = substr( $order_id, strlen(  $this->gateway->order_prefix ) );
		}

		return $order_id;
	}

	/**
	 * Process the hosted session payment.
	 *
	 * @param string|null $three_ds_txn_id The 3DS transaction ID, if available.
	 *
	 * @return void
	 */
	protected function process_hosted_session_payment( $three_ds_txn_id = null ) { 
		$this->service   = GatewayController::get_instance()->init_service(); 	
		$order_id        = isset( $_REQUEST['order_id'] ) ? $this->remove_order_prefix( sanitize_text_field( wp_unslash( $_REQUEST['order_id'] ) ) ) : null; // phpcs:ignore
		$session_id      = isset( $_REQUEST['session_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['session_id'] ) ) : null; // phpcs:ignore
		$session_version = isset( $_REQUEST['session_version'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['session_version'] ) ) : null; // phpcs:ignore

		$session         = array(
			'id' => $session_id,
		);

		if ( null === $session_version ) {
			$session['version'] = $session_version;
		}

		$order              = new WC_Order( $order_id );
		$check_3ds          = isset( $_REQUEST['check_3ds_enrollment'] ) ? '1' === $_REQUEST['check_3ds_enrollment'] : false; // phpcs:ignore
		$process_acl_result = isset( $_REQUEST['process_acs_result'] ) ? '1' === $_REQUEST['process_acs_result'] : false; // phpcs:ignore
		$mg_3ds_nonce 	    = isset( $_REQUEST['mg_3ds_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mg_3ds_nonce'] ) ) : null; // phpcs:ignore
		$funding_method 	= isset( $_REQUEST['funding_method'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['funding_method'] ) ) : null; // phpcs:ignore
		$tds_id             = null;

		if ( $check_3ds ) {
			$data            = array(
				'authenticationRedirect' => array(
					'pageGenerationMode' => 'CUSTOMIZED',
					'responseUrl'        => $this->get_payment_return_url(
						$order_id,
						array(
							'status' => '3ds_done',
						)
					),
				),
			);
			$session         = array(
				'id' => $session_id,
			);
			$order_data      = array(
				'amount'   => (float) $order->get_total(),
				'currency' => $order->get_currency(),
			);
			$source_of_funds = $this->get_token_from_request();
			$response        = $this->service->check3dsEnrollment( $data, $order_data, $session, $source_of_funds );

			if ( 'PROCEED' !== $response['response']['gatewayRecommendation'] ) {
				$order->update_status( 'failed', __( 'Payment was declined.', MG_ENTERPRISE_TEXTDOMAIN ) );
				wc_add_notice( __( 'Payment was declined 1.', MG_ENTERPRISE_TEXTDOMAIN ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			if ( isset( $response['3DSecure']['authenticationRedirect'] ) ) {
				$args      = array();
				$tds_auth  = $response['3DSecure']['authenticationRedirect']['customized'];
				$token_key = $this->get_token_key();
				$token_3ds = bin2hex( random_bytes( 16 ) );

				update_post_meta( $order_id, '_mastercard_3ds_token', $token_3ds );

				$args[ 'authenticationRedirect' ] = $tds_auth;
				$args[ 'returnUrl' ]              =  $this->get_payment_return_url(
					$order_id,
					array(
						'3DSecureId'         => $response['3DSecureId'],
						'process_acs_result' => '1',
						'session_id'         => $session_id,
						'session_version'    => $session_version,
						'mg_3ds_nonce'       => $token_3ds,
						'funding_method'     => $funding_method,
						$token_key           => isset( $_REQUEST[ $token_key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $token_key ] ) ) : null, // phpcs:ignore
					)
				);
				$args[ 'method' ]                 = '3dsecure';

				$view = new CheckoutView( $order, $args );
        		$view->render();
        		exit();
			}

			$this->pay( $session, $order, $funding_method, null );
		}

		$mastercard_3ds_nonce = get_post_meta( $order_id, '_mastercard_3ds_token', true );

		if ( $process_acl_result && $mg_3ds_nonce === $mastercard_3ds_nonce ) {
			$pa_res   = isset( $_POST['PaRes'] ) ? sanitize_text_field( wp_unslash( $_POST['PaRes'] ) ) : null;
			$tds_id   = isset( $_REQUEST['3DSecureId'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['3DSecureId'] ) ) : null;
			$response = $this->service->process3dsResult( $tds_id, $pa_res );

			if ( 'PROCEED' !== $response['response']['gatewayRecommendation'] ) {
				$order->update_status( 'failed', __( 'Payment was declined.', MG_ENTERPRISE_TEXTDOMAIN ) );
				wc_add_notice( __( 'Payment was declined 2.', MG_ENTERPRISE_TEXTDOMAIN ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			$this->pay( $session, $order, $funding_method, $tds_id );
		}

		if ( null !== $three_ds_txn_id ) {					

			$this->pay( $session, $order, $funding_method, $three_ds_txn_id );
		}

		if ( ! $check_3ds && ! $process_acl_result && ! $this->gateway->threedsecure_v1 ) {
			$this->pay( $session, $order, $funding_method, null );
		}

		$order->update_status( 'failed', __( 'Unexpected payment condition error.', MG_ENTERPRISE_TEXTDOMAIN ) );
		wc_add_notice( __( 'Unexpected payment condition error.', MG_ENTERPRISE_TEXTDOMAIN ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit();
	}

	/**
	 * Process the hosted checkout payment.
	 *
	 * This function is responsible for processing the payment made through a hosted checkout.
	 * It performs the necessary actions to complete the payment process.
	 *
	 * @throws GatewayResponseException If the payment was declined.
	 */
	protected function process_hosted_checkout_payment() {
		$service = GatewayController::get_instance()->init_service();

		$order_id        = isset($_REQUEST['order_id']) 
			? $this->remove_order_prefix( sanitize_text_field( wp_unslash( $_REQUEST['order_id'] ) ) )
			: null;

		$result_indicator = isset($_REQUEST['resultIndicator'])
			? sanitize_text_field( wp_unslash( $_REQUEST['resultIndicator'] ) )
			: null;

		if ( empty( $order_id ) || ! is_numeric( $order_id ) ) {
			wc_add_notice( __( 'Invalid order reference received.', MG_ENTERPRISE_TEXTDOMAIN ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order ) {
			throw new GatewayResponseException( 'Order Id not found' );
		}

		$success_indicator = $order->get_meta( '_mpgs_success_indicator_initial' );

		try {
			$mpgs_order  = $service->retrieveOrder( $this->add_order_prefix( $order_id ) );
			
			$txns        = $mpgs_order['transaction'] ?? [];

			if ( empty( $txns ) || ! is_array( $txns ) ) {
				throw new GatewayResponseException( 'No transaction data returned from gateway.' );
			}

			$latest_txn        = end( $txns );
			$auth_txn_id       = $latest_txn['authentication']['transactionId'] ?? null;
			$result_status     = strtoupper( $latest_txn['result'] ?? '' );

			if ( isset( $latest_txn['browserPayment'] ) ) {
				if ( 'SUCCESS' !== $result_status ) {
					throw new GatewayResponseException( 'Transaction failed.' );
				}
			} else {
				
				if ( 'SUCCESS' !== strtoupper( $mpgs_order['result'] ?? '' ) ) {
					throw new GatewayResponseException( 'Payment was declined by issuer.' );
				}

				if ( $success_indicator !== $result_indicator ) {
					$txn_response = $service->retrieveTransaction( $this->add_order_prefix( $order_id ), $auth_txn_id );
					if ( empty( $txn_response['result'] ) || strtoupper( $txn_response['result'] ) !== 'SUCCESS' ) {
						throw new GatewayResponseException( 'Result indicator mismatch.' );
					}
				}
			}

			$transaction = [];
			foreach ( $txns as $txn ) {
				if ( isset( $txn['transaction']['authorizationCode'] ) ) {
					$transaction['transaction']['authorizationCode'] = sanitize_text_field( $txn['transaction']['authorizationCode'] );
				}
				$transaction['transaction']['id']        = sanitize_text_field( $txn['transaction']['id'] ?? '' );
				$transaction['transaction']['reference'] = sanitize_text_field( $txn['transaction']['reference'] ?? '' );
			}
			$type_of_payment = $this->detect_payment_type( $mpgs_order );
			if ( $type_of_payment === 'CARD' ) {
				$card_scheme = $this->get_card_scheme( $mpgs_order );
				if ( $card_scheme ) {
					$type_of_payment = $card_scheme;
				}
			}

			$this->process_wc_order( $order, $mpgs_order, $transaction );
			$order->update_meta_data( '_type_of_payment', $type_of_payment );
			wp_safe_redirect( $this->gateway->get_return_url( $order ) );
			exit;

		} catch ( GatewayResponseException $e ) {
			$this->log( 'GatewayResponseException', [ 'order_id' => $order_id, 'error' => $e->getMessage() ] );
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;

		} catch ( Exception $e ) {
			$this->log( 'General Exception', [ 'order_id' => $order_id, 'error' => $e->getMessage() ] );
			$order->update_status( 'failed', 'Unexpected error: ' . $e->getMessage() );
			wc_add_notice( __( 'An unexpected error occurred during payment. Please try again.', 'your-textdomain' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	protected function detect_payment_type( $mpgs_order ) {
		$type_of_payment = 'OTHERS';

		if ( isset( $mpgs_order['sourceOfFunds']['browserPayment']['type'] ) ) {
			$type_of_payment = strtoupper( sanitize_text_field( $mpgs_order['sourceOfFunds']['browserPayment']['type'] ) );
		} elseif ( isset( $mpgs_order['sourceOfFunds']['type'] ) ) {
			$fund_type = strtoupper( sanitize_text_field( $mpgs_order['sourceOfFunds']['type'] ) );
			if ( in_array( $fund_type, [ 'CARD', 'PAYPAL', 'BROWSER_PAYMENT' ], true ) ) {
				$type_of_payment = $fund_type;
			} else {
				$type_of_payment = $fund_type;
			}
		}

		return $type_of_payment;
	}

	protected function get_card_scheme( $mpgs_order ) {
		if ( isset( $mpgs_order['sourceOfFunds']['provided']['card']['brand'] ) ) {
			return strtoupper( sanitize_text_field( $mpgs_order['sourceOfFunds']['provided']['card']['brand'] ) );
		}

		if ( isset( $mpgs_order['sourceOfFunds']['scheme'] ) ) {
			return strtoupper( sanitize_text_field( $mpgs_order['sourceOfFunds']['scheme'] ) );
		}

		return null;
	}

	/**
	 * Get token from request.
	 *
	 * This function retrieves the token from the request.
	 *
	 * @return string The token extracted from the request.
	 */
	protected function get_token_from_request() {
		$token_key = $this->get_token_key();
		$token_id  = null;

		if ( isset( $_REQUEST[ $token_key ] ) ) { // phpcs:ignore
			$token_id = sanitize_text_field( wp_unslash( $_REQUEST[ $token_key ] ) ); // phpcs:ignore
		}

		$tokens = $this->gateway->get_tokens();

		if ( $token_id && isset( $tokens[ $token_id ] ) ) {
			return array(
				'token' => $tokens[ $token_id ]->get_token(),
			);
		}

		return array();
	}

	/**
	 * Generate a token key.
	 *
	 * @return string The generated token key.
	 */
	protected function get_token_key() {
		return 'wc-' . MG_ENTERPRISE_ID . '-payment-token';
	}

	/**
	 * Process the payment for a given session and order.
	 *
	 * @param string      $session The session ID.
	 * @param string      $order The order ID.
	 * @param string|null $tds_id The TDS ID, if available.
	 * @param string 	  $funding_method Used by the payer to provide the funds for the payment. 
	 *
	 * @return void
	 * @throws GatewayResponseException If the payment was declined.
	 */
	protected function pay( $session, $order, $funding_method, $tds_id = null ) {
		$this->service  = GatewayController::get_instance()->init_service();

		if ( $this->is_order_paid( $order ) ) {
			wp_safe_redirect( $this->gateway->get_return_url( $order ) );
			exit();
		}

		try {
			$txn_id = $this->generate_txn_id_for_order( $order );
			$auth   = null;

			if ( $this->gateway->threedsecure_v2 ) {
				$auth   = array(
					'transactionId' => $tds_id,
				);
				$tds_id = null;
			}

			$order_builder = new CheckoutBuilder( $order );
			$surcharge     = ( isset( $this->gateway->surcharge_enabled ) && 'yes' === $this->gateway->surcharge_enabled ) ?
				$order_builder->getSurcharge() :
				array(
					'amount' => 0,
					'type'   => 'SURCHARGE'
				);

			$order->update_meta_data( '_mg_funding_method', $funding_method );

			if ( $this->gateway->capture ) {
				$mcg_txn = $this->service->pay(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->getOrder(),
					$surcharge,				
					$auth,
					$tds_id,
					$session,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);
			} else {
				$mcg_txn = $this->service->authorize(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->getOrder(),
					$surcharge,
					$auth,
					$tds_id,
					$session,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);
			}

			if ( 'SUCCESS' !== $mcg_txn['result'] ) {
				$gateway_code = $mcg_txn['response']['gatewayCode']; 

				if ( 'DECLINED' === $gateway_code ) {
					throw new GatewayResponseException( __( 'Payment unsuccessful; your card has been declined.', MG_ENTERPRISE_TEXTDOMAIN ) );
				} elseif ( 'EXPIRED_CARD' === $gateway_code ) {
					throw new GatewayResponseException( __( 'The card has expired. Please enter a new card for payment.', MG_ENTERPRISE_TEXTDOMAIN ) );
				} elseif ( 'TIMED_OUT' === $gateway_code ) {
					throw new GatewayResponseException( __( 'We couldn\'t process your card request within the allotted time, and it timed out.', MG_ENTERPRISE_TEXTDOMAIN ) );
				} elseif ( 'ACQUIRER_SYSTEM_ERROR' === $gateway_code ) {
					throw new GatewayResponseException( __( 'The transaction was disrupted due to an issue in the acquirer\'s system.', MG_ENTERPRISE_TEXTDOMAIN ) );
				} elseif ( 'UNSPECIFIED_FAILURE' === $gateway_code ) {
					throw new GatewayResponseException( __( 'An unspecified issue has occurred with your card. Please check the details and try again.', MG_ENTERPRISE_TEXTDOMAIN ) );
				} elseif ( 'AUTHORIZATION_FAILED' === $gateway_code ) {
					throw new GatewayResponseException( __( 'The card not authorized. Please enter a new card for payment.', MG_ENTERPRISE_TEXTDOMAIN ) );
				} else {
					throw new GatewayResponseException( __( 'Payment was declined.', MG_ENTERPRISE_TEXTDOMAIN ) );
				}
			}

			$this->process_wc_order( $order, $mcg_txn['order'], $mcg_txn );

			if ( $this->gateway->saved_cards && $order->get_meta( '_save_card' ) ) {
				$this->process_saved_cards( $session, $order->get_user_id( 'system' ) );	
			}

			wp_safe_redirect( $this->gateway->get_return_url( $order ) );
			exit();
		} catch ( \Fingent\Mastercard\Logger\GatewayResponseException $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit();	
		} catch ( Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit();
		}
	}

	/**
	 * Check if an order is paid.
	 *
	 * @param WC_Order $order The order object to check.
	 *
	 * @return bool True if the order is paid, false otherwise.
	 */
	protected function is_order_paid( WC_Order $order ) {
		return (bool) $order->get_meta( '_mpgs_order_paid', 0 );
	}

	/**
	 * This function processes a WooCommerce order.
	 *
	 * @param object $order The WooCommerce order object.
	 * @param array  $order_data Additional order data.
	 * @param array  $txn_data Transaction data.
	 *
	 * @return void
	 */
	protected function process_wc_order( $order, $order_data, $txn_data ) {
		$this->validate_order( $order, $order_data );

		$captured           = 'CAPTURED' === $order_data['status'];
		$transaction_mode   = ( $this->gateway->capture ) ? TXN_MODE_PURCHASE : TXN_MODE_AUTH_CAPTURE;
		$status             = $captured ? 'CAPTURED' : 'AUTHORIZED';
		$transaction_id     = $txn_data['transaction']['id'];
		$auth_code          = isset( $txn_data['transaction']['authorizationCode'] ) ? $txn_data['transaction']['authorizationCode'] : null;
		$meta_data          = array(
		    '_mpgs_order_captured'       => $captured,
		    '_mpgs_transaction_mode'     => $transaction_mode,
		    '_mpgs_order_paid'           => 1,
		    '_mpgs_transaction_id'       => $txn_data['transaction']['id'] ? $txn_data['transaction']['id'] : '',
		    '_mpgs_transaction_reference'=> $txn_data['transaction']['reference'] ? $txn_data['transaction']['reference'] : '',
		);

		foreach ( $meta_data as $key => $value ) {
		    $order->add_meta_data( $key, $value );
		}

		if ( $order->get_payment_method() !== MG_ENTERPRISE_ID ) {
			$order->set_payment_method( MG_ENTERPRISE_ID );
			$order->set_payment_method_title( __( MG_ENTERPRISE_GATEWAY_TITLE, 'mastercard' ) );
		}
		
		$order->payment_complete( $txn_data['transaction']['id'] );

		if ( $auth_code ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1. Transaction ID, 2. Authorization Code. */
					__( 'Mastercard payment %1$s (ID: %2$s, Auth Code: %3$s)', 'mastercard' ),
					$status,
					$transaction_id,
					$auth_code
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1. Transaction ID. */
					__( 'Mastercard payment %1$s (ID: %2$s)', 'mastercard' ),
					$status,
					$transaction_id
				)
			);
		}
	}

	/**
	 * This function processes the saved cards for a given session and user ID.
	 *
	 * @param string $session The session ID.
	 * @param int    $user_id The user ID.
	 *
	 * @return void
	 *
	 * @throws GatewayResponseException If the session or user ID is empty.
	 */
	protected function process_saved_cards( $session, $user_id ) {
		$response = $this->service->createCardToken( $session['id'] );

		if ( ! isset( $response['token'] ) || empty( $response['token'] ) ) {
			throw new GatewayResponseException( 'Token not present in response' );
		}
	
		$token = new PaymentTokenCC() ;
		$token->set_token( $response['token'] );
		$token->set_gateway_id( MG_ENTERPRISE_ID );
		$token->set_card_type( $response['sourceOfFunds']['provided']['card']['brand'] );
	
		$last4 = substr( $response['sourceOfFunds']['provided']['card']['number'], -4 );
		$token->set_last4( $last4 );
	
		$m = array(); // phpcs:ignore
		preg_match( '/^(\d{2})(\d{2})$/', $response['sourceOfFunds']['provided']['card']['expiry'], $m );
	
		$token->set_expiry_month( $m[1] );
		$token->set_expiry_year( '20' . $m[2] );
		$token->set_user_id( $user_id );
	
		if ( isset( $response['sourceOfFunds']['provided']['card']['fundingMethod'] ) ) {
			$token->set_funding_method( $response['sourceOfFunds']['provided']['card']['fundingMethod'] );
		}
	
		$token->save();
	}

	/**
	 * Validate an order against an MPG order.
	 *
	 * This function compares the given order with an MPG order and checks if they match.
	 *
	 * @param array $order The order to be validated.
	 * @param array $mpgs_order The MPG order to compare against.
	 *
	 * @return bool True if the order matches the MPG order, false otherwise.
	 *
	 * @throws GatewayResponseException If the order or MPG order is not a valid array.
	 */
	protected function validate_order( $order, $mpgs_order ) {
		if ( $order->get_currency() !== $mpgs_order['currency'] ) {
			throw new GatewayResponseException( 'Currency mismatch' );
		}

		if ( (float) $order->get_total() !== (float) $mpgs_order['amount'] ) {
			throw new GatewayResponseException( 'Amount mismatch' );
		}

		return true;
	}

	/**
	 * Handles incoming webhook requests for MGPS.
	 *
	 * This function processes the webhook payload received via HTTP POST
	 * and performs the necessary actions based on the request data.
	 * 
	 * @param WP_REST_Request $request The request object containing webhook data.
	 * @return WP_REST_Response A response object indicating the status of the webhook processing.
	 */
	public function webhook_handler( $request ) {
		$body                = $request->get_body();
    	$headers             = $request->get_headers();
    	$secret              = $headers['x_notification_secret'][0];
		$sandbox_mode 		 = $this->gateway->get_option( 'sandbox' );
		$notification_secret = ($sandbox_mode === 'yes') ? $this->gateway->get_option( 'test_webhook_secret' ) : $this->gateway->get_option( 'webhook_secret' );
    	$response            = json_decode( $body, true );
    	$order_status        = array( 'cancelled', 'failed', 'on-hold' ,'pending' );

    	if ( $secret !== $notification_secret ) {
	        return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
	    }

    	if ( json_last_error() !== JSON_ERROR_NONE ) {
	        return new WP_REST_Response( array( 'error' => 'Invalid JSON' ), 400 );
	    }

	    $order_id = absint( $this->remove_order_prefix( $response['order']['id'] ) );

	    if( $order_id ) {
	    	$order = new WC_Order( $order_id );

	    	switch ( $response['gatewayEntryPoint'] ) {
	    		case 'CHECKOUT_VIA_WEBSITE':
	    			if( 'SUCCESS' === $response['result'] ) {
						if ( in_array( $order->get_status(), $order_status ) ) {
							$this->process_wc_order( $order, $response['order'], $response );
						}
					}
					elseif ( in_array( strtoupper( $response['result'] ), [ 'FAILED', 'DECLINED' ] ) ) {
                        if ( 'failed' !== $order->get_status() ) {
                            $order->update_status( 'failed', __( 'Payment status updated via webhook.', 'mastercard' ) );
                        }
                    }
					break;

	    		default:
	    			break;
	    	}  	
    	} else {
    		return new WP_REST_Response( array( 'error' => 'Invalid Order' ), 400 );
    	}
	}

	/**
	 * Calculate the payment amount for an order.
	 *
	 * @param array $order An array representing the order details.
	 *
	 * @return float The calculated payment amount.
	 */
	protected function get_payment_amount( $order ) {
		return round(
			$order->get_total(),
			wc_get_price_decimals()
		);
	}

	/**
	 * This function generates a transaction ID for an order.
	 *
	 * @param array $order The order details.
	 *
	 * @return string The generated transaction ID.
	 */
	protected function generate_txn_id_for_order( $order ) {

		if ( ! $order->meta_exists( '_txn_id' ) ) {
			$txn_id = $this->compose_new_transaction_id( 1, $order );
			$order->add_meta_data( '_txn_id', $txn_id );
		} else {
			$old_txn_id     = $order->get_meta( '_txn_id' );
			$txn_id_pattern = '/(?<order_id>.*\-)?(?<txn_id>\d+)$/';
			preg_match( $txn_id_pattern, $old_txn_id, $matches );

			$txn_id_num = (int) $matches['txn_id'] ?? 1;
			$txn_id     = $this->compose_new_transaction_id( $txn_id_num + 1, $order );
			$order->update_meta_data( '_txn_id', $txn_id );
		}

		$order->save_meta_data();

		return $txn_id;
	}

	/**
	 * Compose a new transaction ID based on the given transaction ID and order.
	 *
	 * @param string $txn_id The original transaction ID.
	 * @param int    $order The order number.
	 *
	 * @return string The composed new transaction ID.
	 */
	protected function compose_new_transaction_id( $txn_id, $order ) {
		if ( $this->gateway->order_prefix ) {
			$order_id = $this->gateway->order_prefix;
		}
		$order_id .= $order->get_id();

		return sprintf( '%s-%s', $order_id, $txn_id );
	}
}
