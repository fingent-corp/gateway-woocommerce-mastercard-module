<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Payment receipt, return URL, REST, and 3DS checkout processing.
 *
 * @package Fingent\Mastercard\Controller
 */

namespace Fingent\Mastercard\Controller;

use WC_Order;
use Fingent\Mastercard\Logger\ApiErrorPlugin;
use Fingent\Mastercard\Logger\ApiLoggerPlugin;
use Fingent\Mastercard\Logger\GatewayResponseException;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\View\CheckoutView;
use Fingent\Mastercard\Controller\FrontendController;
use Fingent\Mastercard\Controller\GatewayController;
use Fingent\Mastercard\Controller\GatewayServiceController;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Core\PaymentTokenCC;
use Fingent\Mastercard\Helper\CheckoutBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles checkout payment flows, callbacks, and gateway REST routes.
 */
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
	 * Gateway API service.
	 *
	 * @var GatewayServiceController
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
	 * Generate the receipt page for a given order ID.
	 *
	 * @param int $order_id The ID of the order for which the receipt page is generated.
	 *
	 * @return void
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
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
	 * @param int                  $order_id The ID of the order for which the payment return URL is generated.
	 * @param array<string, mixed> $params   Additional parameters to be included in the return URL (optional).
	 *
	 * @return string The generated payment return URL.
	 */
	public function get_payment_return_url( $order_id, $params = array() ) {
		$order = wc_get_order( $order_id );

		$params = array_merge(
			array(
				'order_id'  => $order_id,
				'order_key' => $order instanceof WC_Order ? $order->get_order_key() : '',
			),
			$params
		);

		return add_query_arg( 'wc-api', MG_ENTERPRISE_ID, home_url( '/' ) ) . '&' . http_build_query( $params );
	}

	/**
	 * Validate callback request against the expected WooCommerce order key.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 *
	 * @return bool
	 */
	protected function is_valid_return_order_request( WC_Order $order ) {
		$order_key = $this->sanitize_request_field( 'order_key' ) ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $order_key && hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			return true;
		}

		if ( $this->is_valid_pay_by_link_return( $order ) ) {
			return true;
		}

		// 3DS v2 MPGS redirects may only include mg_3ds_nonce + transaction_id (legacy sessions).
		if ( $this->gateway->threedsecure_v2 ) {
			return $this->matches_3ds_v2_return_credentials( $order );
		}

		return false;
	}

	/**
	 * Validate pay-by-link return when MPGS redirects with resultIndicator.
	 *
	 * Legacy payment links may omit order_key in the return URL; the success
	 * indicator issued at link creation is sufficient to bind the callback.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return bool
	 */
	protected function is_valid_pay_by_link_return( WC_Order $order ) {
		$pay_link_url = $order->get_meta( '_pay_by_link_url' );

		if ( empty( $pay_link_url ) ) {
			return false;
		}

		$result_indicator = $this->sanitize_request_field( 'resultIndicator' );

		if ( null === $result_indicator || '' === $result_indicator ) {
			return false;
		}

		$stored_indicator = (string) $order->get_meta( '_pay_by_link_indicator' );

		if ( '' === $stored_indicator ) {
			return false;
		}

		return hash_equals( $stored_indicator, $result_indicator );
	}

	/**
	 * Sanitize a scalar string value from $_REQUEST.
	 *
	 * @param string $key Request parameter name.
	 *
	 * @return string|null Sanitized value, or null when missing or not a string.
	 */
	private function sanitize_request_field( string $key ): ?string {
		if ( ! isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}

		$raw = wp_unslash( $_REQUEST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return is_string( $raw ) ? sanitize_text_field( $raw ) : null;
	}

	/**
	 * This function adds a prefix to an order ID.
	 *
	 * @param int|string $order_id The original order ID.
	 *
	 * @return string The order ID with the prefix added.
	 */
	public function add_order_prefix( $order_id ) {
		$order_id = (string) $order_id;
		if ( $this->gateway->order_prefix ) {
			$order_id = $this->gateway->order_prefix . $order_id;
		}

		return $order_id;
	}

	/**
	 * This function processes a REST route and request.
	 *
	 * @param string           $route   The REST route to be processed.
	 * @param \WP_REST_Request $request The REST request associated with the route.
	 *
	 * @return mixed The processed result of the route and request.
	 *
	 * @throws GatewayResponseException If the route, request, or parameters are invalid or processing fails.
	 */
	public function rest_route_processor( $route, $request ) {
		$result        = null;
		$this->service = GatewayController::get_instance()->init_service();
		try {
			if ( preg_match( '~/mastercard/v1/checkoutSession/\d+~', $route ) ) {
					$wc_order_id = $request->get_param( 'id' );
				if ( ! is_numeric( $wc_order_id ) ) {
					throw new GatewayResponseException( 'Invalid order ID.' );
				}
					$order         = new WC_Order( (int) $wc_order_id );
					$return_url    = $this->get_payment_return_url( $order->get_id() );
					$order_builder = new CheckoutBuilder( $order );
					$result        = $this->service->initiate_checkout(
						$order_builder->get_hosted_checkout_order(),
						$order_builder->get_interaction(
							$this->gateway->capture ?? false,
							$return_url
						),
						$order_builder->get_customer(),
						$order_builder->get_billing(),
						$order_builder->get_shipping() ?? array()
					);

					// Proceed if the result has a successIndicator.
				if ( $result && isset( $result['successIndicator'] ) ) {
					$order->update_meta_data( '_mpgs_success_indicator_initial', $result['successIndicator'] );
				}

					$order->save_meta_data();
			} elseif ( preg_match( '~/mastercard/v1/savePayment/\d+~', $route ) ) {
					$wc_order_id = $request->get_param( 'id' );
				if ( ! is_numeric( $wc_order_id ) ) {
					throw new GatewayResponseException( 'Invalid order ID.' );
				}
					$order         = new WC_Order( (int) $wc_order_id );
					$save_new_card = ( 'true' === $request->get_param( 'save_new_card' ) );

				if ( $save_new_card ) {
					$order->update_meta_data( '_save_card', 'yes' );
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

					$redirect_query_args = array();
					$three_ds_txn_id     = null;

				if ( $this->gateway->threedsecure_v2 ) {
					$three_ds_txn_id = $this->generate_txn_id_for_order( $order );
					$return_token    = bin2hex( random_bytes( 16 ) );
					$order->update_meta_data( '_mpgs_3ds_v2_transaction_id', $three_ds_txn_id );
					$order->update_meta_data( '_mpgs_3ds_v2_return_token', $return_token );
					$redirect_query_args['mg_3ds_nonce'] = $return_token;
					$redirect_query_args['order_key']    = $order->get_order_key();
				}

					$session_id    = $order->get_meta( '_mpgs_session_id' );
					$order_builder = new CheckoutBuilder( $order );
					$result        = $this->service->update_session(
						$session_id,
						$order_builder->get_hosted_checkout_order(),
						$order_builder->get_customer(),
						$order_builder->get_billing(),
						$order_builder->get_shipping() ?? array(),
						$auth,
						$this->get_token_from_request(),
						$redirect_query_args
					);

				if ( $three_ds_txn_id ) {
					$result['threeDsTransactionId'] = $three_ds_txn_id;
				}

				if ( $result && isset( $result['successIndicator'] ) ) {
					if ( $order->meta_exists( '_mpgs_success_indicator' ) ) {
						$order->update_meta_data( '_mpgs_success_indicator', $result['successIndicator'] );
					} else {
						$order->add_meta_data( '_mpgs_success_indicator', $result['successIndicator'], true );
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
			} elseif ( preg_match( '~/mastercard/v1/session/\d+~', $route ) ) {
					$wc_order_id = $request->get_param( 'id' );
				if ( ! is_numeric( $wc_order_id ) ) {
					throw new GatewayResponseException( 'Invalid order ID.' );
				}
					$order  = new WC_Order( (int) $wc_order_id );
					$result = $this->service->create_session();

				if ( $order->meta_exists( '_mpgs_session_id' ) ) {
					$order->update_meta_data( '_mpgs_session_id', $result['session']['id'] );
				} else {
					$order->add_meta_data( '_mpgs_session_id', $result['session']['id'], true );
				}
					$order->save_meta_data();
			} elseif ( '/mastercard/v1/webhook' === $route ) {
				$this->webhook_handler( $request );
			}
		} catch ( \Throwable $e ) {
			$logger = $this->get_logger();
			if ( $logger ) {
				$logger->error(
					'REST route processor error',
					array(
						'route'   => $route,
						'error'   => $e->getMessage(),
						'request' => method_exists( $request, 'get_params' ) ? $request->get_params() : array(),
					)
				);
			}

			return new \WP_Error(
				'mastercard_rest_error',
				__( 'Payment could not be processed. Please try again or contact the store.', 'mastercard' ),
				array( 'status' => 400 )
			);
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
		$gateway_recommendation = $this->sanitize_request_field( 'response_gatewayRecommendation' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $gateway_recommendation ) {
			if ( 'PROCEED' === $gateway_recommendation ) {
				$three_ds_txn_id = $this->sanitize_request_field( 'transaction_id' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} else {
				$order_id = $this->sanitize_request_field( 'order_id' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $order_id ) {

					$order = wc_get_order( (int) $this->remove_order_prefix( $order_id ) );
					if ( ! $order instanceof WC_Order ) {
						wp_safe_redirect( wc_get_checkout_url() );
						exit();
					}
					if ( ! $this->is_valid_return_order_request( $order ) ) {
						wp_safe_redirect( wc_get_checkout_url() );
						exit();
					}
					$order->update_status(
						'failed',
						__( '3DS authorization was not provided. Payment declined.', 'mastercard-gateway' )
					);
					wc_add_notice(
						__( '3DS authorization was not provided. Payment declined.', 'mastercard-gateway' ),
						'error'
					);
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
		if ( $this->gateway->order_prefix && strpos( $order_id, $this->gateway->order_prefix ) === 0 ) {
			$stripped = substr( $order_id, strlen( $this->gateway->order_prefix ) );
			$order_id = false !== $stripped ? $stripped : $order_id;
		}

		return $order_id;
	}

	/**
	 * Process the hosted session payment.
	 *
	 * @param string|null $three_ds_txn_id The 3DS transaction ID, if available.
	 *
	 * @return never
	 */
	protected function process_hosted_session_payment( $three_ds_txn_id = null ) {
		$this->service   = GatewayController::get_instance()->init_service();
		$order_id_raw    = $this->sanitize_request_field( 'order_id' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id        = null !== $order_id_raw ? $this->remove_order_prefix( $order_id_raw ) : null;
		$session_id      = $this->sanitize_request_field( 'session_id' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session_version = $this->sanitize_request_field( 'session_version' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$session = array(
			'id' => $session_id,
		);

		if ( null !== $session_version ) {
			$session['version'] = $session_version;
		}

		if ( ! is_numeric( $order_id ) ) {
			wc_add_notice( __( 'Invalid order callback.', 'mastercard-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit();
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || ! $this->is_valid_return_order_request( $order ) ) {
			wc_add_notice( __( 'Invalid order callback.', 'mastercard-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit();
		}
		$check_3ds          = isset( $_REQUEST['check_3ds_enrollment'] ) ? '1' === $_REQUEST['check_3ds_enrollment'] : false; // phpcs:ignore
		$process_acl_result = isset( $_REQUEST['process_acs_result'] ) ? '1' === $_REQUEST['process_acs_result'] : false; // phpcs:ignore
		$mg_3ds_nonce       = $this->sanitize_request_field( 'mg_3ds_nonce' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$funding_method     = $this->sanitize_request_field( 'funding_method' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tds_id             = null;

		if ( $check_3ds ) {
			$data            = array(
				'authenticationRedirect' => array(
					'pageGenerationMode' => 'CUSTOMIZED',
					'responseUrl'        => $this->get_payment_return_url(
						(int) $order_id,
						array(
							'status' => '3ds_done',
						)
					),
				),
			);
			$order_builder   = new CheckoutBuilder( $order );
			$order_data      = array(
				'amount'   => $order_builder->formatted_price( $order->get_total() ),
				'currency' => $order->get_currency(),
			);
			$source_of_funds = $this->get_token_from_request();
			$response        = $this->service->check_3ds_enrollment( $data, $order_data, $session, $source_of_funds );

			if ( 'PROCEED' !== $response['response']['gatewayRecommendation'] ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard-gateway' ) );
				wc_add_notice( __( 'Payment was declined 1.', 'mastercard-gateway' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			if ( isset( $response['3DSecure']['authenticationRedirect'] ) ) {
				$args      = array();
				$tds_auth  = $response['3DSecure']['authenticationRedirect']['customized'];
				$token_key = $this->get_token_key();
				$token_3ds = bin2hex( random_bytes( 16 ) );

				update_post_meta( (int) $order_id, '_mastercard_3ds_token', $token_3ds );

				$args['authenticationRedirect'] = $tds_auth;
				$args['returnUrl']              = $this->get_payment_return_url(
					(int) $order_id,
					array(
						'3DSecureId'         => $response['3DSecureId'],
						'process_acs_result' => '1',
						'session_id'         => $session_id,
						'session_version'    => $session_version,
						'mg_3ds_nonce'       => $token_3ds,
						'funding_method'     => $funding_method,
						$token_key           => $this->sanitize_request_field( $token_key ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				);
				$args['method']                 = '3dsecure';

				$view = new CheckoutView( $order, $args );
				$view->render();
				exit();
			}

			$this->pay( $session, $order, $funding_method, null );
		}

		$mastercard_3ds_nonce = get_post_meta( (int) $order_id, '_mastercard_3ds_token', true );

		if ( $process_acl_result && $mg_3ds_nonce === $mastercard_3ds_nonce ) {
			$pa_res = filter_input( INPUT_POST, 'PaRes', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$pa_res = is_string( $pa_res ) ? $pa_res : null;
			$tds_id = filter_input( INPUT_GET, '3DSecureId', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$tds_id = is_string( $tds_id ) ? $tds_id : null;
			if ( null === $tds_id || null === $pa_res ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard-gateway' ) );
				wc_add_notice( __( 'Payment was declined 2.', 'mastercard-gateway' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}
			$response = $this->service->process_3ds_result( $tds_id, $pa_res );

			if ( 'PROCEED' !== $response['response']['gatewayRecommendation'] ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard-gateway' ) );
				wc_add_notice( __( 'Payment was declined 2.', 'mastercard-gateway' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			$this->pay( $session, $order, $funding_method, $tds_id );
		}

		if ( null !== $three_ds_txn_id ) {
			if ( $this->gateway->threedsecure_v2 && ! $this->validate_3ds_v2_return( $order, $three_ds_txn_id ) ) {
				wc_add_notice( __( '3DS verification could not be validated. Payment declined.', 'mastercard-gateway' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			$this->pay( $session, $order, $funding_method, $three_ds_txn_id );
		}

		if ( ! $process_acl_result && ! $this->gateway->threedsecure_v1 ) {
			$this->pay( $session, $order, $funding_method, null );
		}

		$order->update_status( 'failed', __( 'Unexpected payment condition error.', 'mastercard-gateway' ) );
		wc_add_notice( __( 'Unexpected payment condition error.', 'mastercard-gateway' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit();
	}

	/**
	 * Handle hosted checkout payment response from MPGS.
	 *
	 * @return never
	 * @throws GatewayResponseException If the payment was declined.
	 */
	protected function process_hosted_checkout_payment() {

		$service = GatewayController::get_instance()->init_service();

		$order_id_raw = filter_input( INPUT_GET, 'order_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$order_id     = is_string( $order_id_raw ) ? $this->remove_order_prefix( $order_id_raw ) : null;

		$result_indicator_raw = filter_input( INPUT_GET, 'resultIndicator', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$result_indicator     = is_string( $result_indicator_raw ) ? $result_indicator_raw : null;

		if ( empty( $order_id ) || ! is_numeric( $order_id ) ) {
			wc_add_notice( __( 'Invalid order reference received.', 'mastercard-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order ) {
			throw new GatewayResponseException( 'Order Id not found' );
		}

		if ( ! $this->is_valid_return_order_request( $order ) ) {
			wc_add_notice( __( 'Invalid order callback.', 'mastercard-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$success_indicator = $order->get_meta( '_mpgs_success_indicator_initial' );
		if ( empty( $success_indicator ) ) {
			$success_indicator = $order->get_meta( '_pay_by_link_indicator' );
		}

		try {
			$mpgs_order = $service->retrieve_order( $this->add_order_prefix( $order_id ) );

			$txns = $mpgs_order['transaction'] ?? array();

			if ( empty( $txns ) || ! is_array( $txns ) ) {
				throw new GatewayResponseException( 'No transaction data returned from gateway.' );
			}

			$latest_txn    = end( $txns );
			$auth_txn_id   = $latest_txn['authentication']['transactionId'] ?? null;
			$result_status = strtoupper( $latest_txn['result'] ?? '' );

			if ( isset( $latest_txn['gatewayEntryPoint'] )
				&& 'CHECKOUT_VIA_PAYMENT_LINK' === $latest_txn['gatewayEntryPoint']
				&& isset( $latest_txn['order']['custom']['paymentLink'] )
				&& true === $latest_txn['order']['custom']['paymentLink']
				&& 'SUCCESS' !== $result_status ) {
				throw new GatewayResponseException( 'Transaction failed.' );
			} elseif ( isset( $latest_txn['browserPayment'] ) ) {

				if ( 'SUCCESS' !== $result_status ) {
					throw new GatewayResponseException( 'Transaction failed.' );
				}
			} else {
				if ( 'SUCCESS' !== strtoupper( $mpgs_order['result'] ?? '' ) ) {
					throw new GatewayResponseException( 'Payment was declined by issuer.' );
				}

				if ( $success_indicator !== $result_indicator ) {
					if ( ! is_string( $auth_txn_id ) ) {
						throw new GatewayResponseException( 'Missing authentication transaction ID.' );
					}
					$txn_response = $service->retrieve_transaction( $this->add_order_prefix( $order_id ), $auth_txn_id );
					if ( empty( $txn_response['result'] ) || strtoupper( $txn_response['result'] ) !== 'SUCCESS' ) {
						throw new GatewayResponseException( 'Result indicator mismatch.' );
					}
				}
			}

			$transaction = array();
			foreach ( $txns as $txn ) {
				if ( isset( $txn['transaction']['authorizationCode'] ) ) {
					$transaction['transaction']['authorizationCode'] = sanitize_text_field( $txn['transaction']['authorizationCode'] );
				}
				$transaction['transaction']['id']        = sanitize_text_field( $txn['transaction']['id'] ?? '' );
				$transaction['transaction']['reference'] = sanitize_text_field( $txn['transaction']['reference'] ?? '' );
			}

			$this->process_wc_order( $order, $mpgs_order, $transaction );
			wp_safe_redirect( $this->gateway->get_return_url( $order ) );
			exit;

		} catch ( GatewayResponseException $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;

		} catch ( \Exception $e ) {
			$order->update_status( 'failed', 'Unexpected error: ' . $e->getMessage() );
			wc_add_notice( __( 'An unexpected error occurred during payment. Please try again.', 'mastercard-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Get token from request.
	 *
	 * This function retrieves the token from the request.
	 *
	 * @return array<string, mixed> Token payload for gateway sourceOfFunds.
	 */
	protected function get_token_from_request() {
		$token_key = $this->get_token_key();
		$token_id  = null;

		$token_id = $this->sanitize_request_field( $token_key ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
	 * @param array<string, mixed> $session        Hosted session payload.
	 * @param \WC_Order            $order          WooCommerce order.
	 * @param string|null          $funding_method Card funding method from the payer.
	 * @param string|null          $tds_id         3-D Secure transaction ID, if available.
	 *
	 * @return never
	 * @throws GatewayResponseException If the payment was declined.
	 */
	protected function pay( $session, $order, $funding_method, $tds_id = null ) {
		$this->service = GatewayController::get_instance()->init_service();

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
			$surcharge     = ( 'yes' === $this->gateway->get_option( 'surcharge_enabled' ) ) ?
				$order_builder->get_surcharge() :
				array(
					'amount' => 0,
					'type'   => 'SURCHARGE',
				);

			if ( null !== $funding_method ) {
				$order->update_meta_data( '_mg_funding_method', $funding_method );
			}

			if ( $this->gateway->capture ) {
				$mcg_txn = $this->service->pay(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->get_order(),
					$surcharge,
					$auth ?? array(),
					$tds_id,
					$session,
					$order_builder->get_customer(),
					$order_builder->get_billing(),
					$order_builder->get_shipping() ?? array()
				);
			} else {
				$mcg_txn = $this->service->authorize(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->get_order(),
					$surcharge,
					$auth ?? array(),
					$tds_id,
					$session,
					$order_builder->get_customer(),
					$order_builder->get_billing(),
					$order_builder->get_shipping() ?? array()
				);
			}

			if ( 'SUCCESS' !== $mcg_txn['result'] ) {
				$gateway_code = $mcg_txn['response']['gatewayCode'];

				if ( 'DECLINED' === $gateway_code ) {
					throw new GatewayResponseException( __( 'Payment unsuccessful; your card has been declined.', 'mastercard-gateway' ) );
				} elseif ( 'EXPIRED_CARD' === $gateway_code ) {
					throw new GatewayResponseException( __( 'The card has expired. Please enter a new card for payment.', 'mastercard-gateway' ) );
				} elseif ( 'TIMED_OUT' === $gateway_code ) {
					throw new GatewayResponseException( __( 'We couldn\'t process your card request within the allotted time, and it timed out.', 'mastercard-gateway' ) );
				} elseif ( 'ACQUIRER_SYSTEM_ERROR' === $gateway_code ) {
					throw new GatewayResponseException( __( 'The transaction was disrupted due to an issue in the acquirer\'s system.', 'mastercard-gateway' ) );
				} elseif ( 'UNSPECIFIED_FAILURE' === $gateway_code ) {
					throw new GatewayResponseException( __( 'An unspecified issue has occurred with your card. Please check the details and try again.', 'mastercard-gateway' ) );
				} elseif ( 'AUTHORIZATION_FAILED' === $gateway_code ) {
					throw new GatewayResponseException( __( 'The card not authorized. Please enter a new card for payment.', 'mastercard-gateway' ) );
				} else {
					throw new GatewayResponseException( __( 'Payment was declined.', 'mastercard-gateway' ) );
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
		} catch ( \Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit();
		}
	}

	/**
	 * Check if an order is paid.
	 *
	 * @param \WC_Order $order The order object to check.
	 *
	 * @return bool True if the order is paid, false otherwise.
	 */
	protected function is_order_paid( WC_Order $order ) {
		return (bool) $order->get_meta( '_mpgs_order_paid', true );
	}

	/**
	 * This function processes a WooCommerce order.
	 *
	 * @param \WC_Order            $order      The WooCommerce order object.
	 * @param array<string, mixed> $order_data Additional order data.
	 * @param array<string, mixed> $txn_data   Transaction data.
	 *
	 * @return void
	 */
	protected function process_wc_order( $order, $order_data, $txn_data ) {
		$this->validate_order( $order, $order_data );

		$captured         = 'CAPTURED' === $order_data['status'];
		$transaction_mode = ( $this->gateway->capture ) ? TXN_MODE_PURCHASE : TXN_MODE_AUTH_CAPTURE;
		$status           = $captured ? 'CAPTURED' : 'AUTHORIZED';
		$transaction_id   = $txn_data['transaction']['id'];
		$auth_code        = isset( $txn_data['transaction']['authorizationCode'] ) ? $txn_data['transaction']['authorizationCode'] : null;
		$meta_data        = array(
			'_mpgs_order_captured'        => $captured,
			'_mpgs_transaction_mode'      => $transaction_mode,
			'_mpgs_order_paid'            => 1,
			'_mpgs_transaction_id'        => $txn_data['transaction']['id'] ? $txn_data['transaction']['id'] : '',
			'_mpgs_transaction_reference' => $txn_data['transaction']['reference'] ? $txn_data['transaction']['reference'] : '',
		);

		if ( $order->get_payment_method() !== MG_ENTERPRISE_ID ) {
			$order->set_payment_method( MG_ENTERPRISE_ID );
			$order->set_payment_method_title( __( 'Mastercard Gateway', 'mastercard-gateway' ) );
		}

		foreach ( $meta_data as $key => $value ) {
			$order->add_meta_data( $key, is_scalar( $value ) ? (string) $value : '' );
		}

		$order->payment_complete( $txn_data['transaction']['id'] );

		if ( $auth_code ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1. Transaction ID, 2. Authorization Code. */
					__( 'Mastercard payment %1$s (ID: %2$s, Auth Code: %3$s)', 'mastercard-gateway' ),
					$status,
					$transaction_id,
					$auth_code
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1. Transaction ID. */
					__( 'Mastercard payment %1$s (ID: %2$s)', 'mastercard-gateway' ),
					$status,
					$transaction_id
				)
			);
		}
	}

	/**
	 * This function processes the saved cards for a given session and user ID.
	 *
	 * @param array<string, mixed> $session Hosted session payload.
	 * @param int                  $user_id Customer user ID.
	 *
	 * @return void
	 *
	 * @throws GatewayResponseException If the session or user ID is empty.
	 */
	protected function process_saved_cards( $session, $user_id ) {
		$response = $this->service->create_card_token( $session['id'] );

		if ( ! isset( $response['token'] ) || empty( $response['token'] ) ) {
			throw new GatewayResponseException( 'Token not present in response' );
		}

		$token = new PaymentTokenCC();
		$token->set_token( $response['token'] );
		$token->set_gateway_id( MG_ENTERPRISE_ID );
		$token->set_card_type( $response['sourceOfFunds']['provided']['card']['brand'] );

		$last4 = substr( $response['sourceOfFunds']['provided']['card']['number'], -4 );
		$token->set_last4( false !== $last4 ? $last4 : '' );

		$m = array(); // phpcs:ignore
		preg_match( '/^(\d{2})(\d{2})$/', $response['sourceOfFunds']['provided']['card']['expiry'], $m );

		if ( isset( $m[1], $m[2] ) ) {
			$token->set_expiry_month( $m[1] );
			$token->set_expiry_year( '20' . $m[2] );
		}
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
	 * @param \WC_Order            $order      The order to be validated.
	 * @param array<string, mixed> $mpgs_order The MPG order to compare against.
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
	 * @param \WP_REST_Request $request The request object containing webhook data.
	 * @return \WP_REST_Response A response object indicating the status of the webhook processing.
	 */
	public function webhook_handler( $request ) {
		$body         = $request->get_body();
		$response     = json_decode( $body, true );
		$order_status = array( 'cancelled', 'failed', 'on-hold', 'pending' );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid JSON' ), 400 );
		}

		$order_id = absint( $this->remove_order_prefix( $response['order']['id'] ) );

		if ( $order_id ) {
			$order = new WC_Order( $order_id );

			switch ( $response['gatewayEntryPoint'] ) {
				case 'CHECKOUT_VIA_WEBSITE':
					$is_iris = isset( $response['sourceOfFunds']['browserPayment']['type'] )
						&& strtoupper( $response['sourceOfFunds']['browserPayment']['type'] ) === 'IRIS_PAY';

					if ( $is_iris ) {
						$order_status_mpgs = strtoupper( $response['status'] ?? '' );

						if ( 'CAPTURED' === $order_status_mpgs ) {
							if ( in_array( $order->get_status(), $order_status, true ) ) {
								$this->process_wc_order( $order, $response['order'], $response );
							}
						} elseif ( in_array( $order_status_mpgs, array( 'FAILED', 'CANCELLED' ), true ) ) {
							if ( 'failed' !== $order->get_status() ) {
								$order->update_status( 'failed', __( 'IRIS payment failed or was cancelled.', 'mastercard-gateway' ) );
							}
						}
					} elseif ( 'SUCCESS' === $response['result'] ) {
						if ( in_array( $order->get_status(), $order_status, true ) ) {
							$this->process_wc_order( $order, $response['order'], $response );
						}
					}
					break;

				default:
					break;
			}
		} else {
			return new \WP_REST_Response( array( 'error' => 'Invalid Order' ), 400 );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Calculate the payment amount for an order.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 *
	 * @return float The calculated payment amount.
	 */
	protected function get_payment_amount( WC_Order $order ) {
		return round(
			$order->get_total(),
			wc_get_price_decimals()
		);
	}

	/**
	 * This function generates a transaction ID for an order.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 *
	 * @return string The generated transaction ID.
	 */
	protected function generate_txn_id_for_order( WC_Order $order ) {

		if ( ! $order->meta_exists( '_txn_id' ) ) {
			$txn_id = $this->compose_new_transaction_id( 1, $order );
			$order->add_meta_data( '_txn_id', $txn_id );
		} else {
			$old_txn_id     = (string) $order->get_meta( '_txn_id' );
			$txn_id_pattern = '/(?<order_id>.*\-)?(?<txn_id>\d+)$/';
			preg_match( $txn_id_pattern, $old_txn_id, $matches );

			$txn_id_num = isset( $matches['txn_id'] ) ? (int) $matches['txn_id'] : 1;
			$txn_id     = $this->compose_new_transaction_id( $txn_id_num + 1, $order );
			$order->update_meta_data( '_txn_id', $txn_id );
		}

		$order->save_meta_data();

		return $txn_id;
	}

	/**
	 * Compose a new transaction ID based on the given transaction ID and order.
	 *
	 * @param int       $txn_id Sequential transaction number for the order.
	 * @param \WC_Order $order  WooCommerce order.
	 *
	 * @return string The composed new transaction ID.
	 */
	protected function compose_new_transaction_id( $txn_id, WC_Order $order ) {
		$order_id  = $this->gateway->order_prefix ? $this->gateway->order_prefix : '';
		$order_id .= (string) $order->get_id();

		return sprintf( '%s-%s', $order_id, $txn_id );
	}

	/**
	 * Validate 3DS v2 return parameters against order-bound authentication state.
	 *
	 * @param \WC_Order $order             WooCommerce order.
	 * @param string    $three_ds_txn_id   Transaction ID from the gateway redirect.
	 *
	 * @return bool
	 */
	protected function matches_3ds_v2_return_credentials( $order, $three_ds_txn_id = null ) {
		if ( null === $three_ds_txn_id ) {
			$three_ds_txn_id = $this->sanitize_request_field( 'transaction_id' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$stored_txn_id = (string) $order->get_meta( '_mpgs_3ds_v2_transaction_id' );
		$stored_nonce  = (string) $order->get_meta( '_mpgs_3ds_v2_return_token' );
		$return_nonce  = $this->sanitize_request_field( 'mg_3ds_nonce' ) ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $stored_txn_id || '' === $stored_nonce || '' === $return_nonce || null === $three_ds_txn_id ) {
			return false;
		}

		return hash_equals( $stored_txn_id, $three_ds_txn_id )
			&& hash_equals( $stored_nonce, $return_nonce );
	}

	/**
	 * Validate 3DS v2 return parameters against order-bound authentication state.
	 *
	 * @param \WC_Order $order             WooCommerce order.
	 * @param string    $three_ds_txn_id   Transaction ID from the gateway redirect.
	 *
	 * @return bool
	 */
	protected function validate_3ds_v2_return( $order, $three_ds_txn_id ) {
		if ( ! $this->matches_3ds_v2_return_credentials( $order, $three_ds_txn_id ) ) {
			return false;
		}

		$order->delete_meta_data( '_mpgs_3ds_v2_return_token' );
		$order->save_meta_data();

		return true;
	}
}
