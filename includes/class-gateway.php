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
 * @version  GIT: @1.4.9@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'MPGS_TARGET_MODULE_VERSION', '1.4.9' );

require_once dirname( __DIR__ ) . '/includes/class-checkout-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-gateway-service.php';
require_once dirname( __DIR__ ) . '/includes/class-payment-gateway-cc.php';

/**
 * Main class of the Mastercard Payment Gateway Module
 *
 * Represents a gateway service for processing Mastercard transactions.
 */
class Mastercard_Gateway extends WC_Payment_Gateway {

	const ID            = 'mpgs_gateway';
	const GATEWAY_TITLE = 'Mastercard Payment Gateway Services';

	const MPGS_API_VERSION     = 'version/100';
	const MPGS_API_VERSION_NUM = '100';

	const HOSTED_SESSION  = 'hosted-session';
	const HOSTED_CHECKOUT = 'hosted-checkout';

	const HC_TYPE_REDIRECT = 'redirect';
	const HC_TYPE_MODAL    = 'modal';
	const HC_TYPE_EMBEDDED = 'embedded';

	const API_EU     = 'eu-gateway.mastercard.com';
	const API_AS     = 'ap-gateway.mastercard.com';
	const API_NA     = 'na-gateway.mastercard.com';
	const API_CUSTOM = 'custom';

	const TXN_MODE_PURCHASE     = 'capture';
	const TXN_MODE_AUTH_CAPTURE = 'authorize';

	const HF_FIXED      = 'fixed';
	const HF_PERCENTAGE = 'percentage';

	const THREED_DISABLED = 'no';
	const THREED_V1       = 'yes'; // Backward compatibility with checkbox value.
	const THREED_V2       = '2';

	/**
	 * Order prefix
	 *
	 * @var string
	 */
	protected $order_prefix = null;

	/**
	 * Sandbox
	 *
	 * @var bool
	 */
	protected $sandbox = null;

	/**
	 * Username
	 *
	 * @var string
	 */
	protected $username = null;

	/**
	 * Password
	 *
	 * @var string
	 */
	protected $password = null;

	/**
	 * Gateway URL
	 *
	 * @var string
	 */
	protected $gateway_url = null;

	/**
	 * Gateway Service
	 *
	 * @var Mastercard_GatewayService
	 */
	protected $service = null;

	/**
	 * Hosted checkout Interaction
	 *
	 * @var string
	 */
	protected $hc_interaction = null;

	/**
	 * Hosted checkout type
	 *
	 * @var string
	 *
	 * @todo Remove after removal of Legacy Hosted Checkout
	 */
	protected $hc_type = null;

	/**
	 * Capture method
	 *
	 * @var bool
	 */
	protected $capture = null;

	/**
	 * Method
	 *
	 * @var string
	 */
	protected $method = null;

	/**
	 * 3D Secure Version 1
	 *
	 * @var bool
	 */
	protected $threedsecure_v1 = null;

	/**
	 * 3D Secure Version 2 (with fallback to 3DS1)
	 *
	 * @var bool
	 */
	protected $threedsecure_v2 = null;

	/**
	 * Handling fees
	 *
	 * @var bool
	 */
	public $hf_enabled = null;

	/**
	 * Send Line Items
	 *
	 * @var bool
	 */
	public $send_line_items = null;

	/**
	 * Merchant Information
	 *
	 * @var bool
	 */
	public $mif_enabled = null;

	/**
	 * Saved Cards
	 *
	 * @var bool
	 */
	protected $saved_cards = null;

	/**
	 * Mastercard_Gateway constructor.
	 *
	 * @throws Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() { 
		$this->id                 = self::ID;
		$this->title              = __( self::GATEWAY_TITLE, 'mastercard' );
		$this->method_title       = __( self::GATEWAY_TITLE, 'mastercard' );
		$this->has_fields         = true;
		$this->method_description = __(
			'Accept payments on your WooCommerce store using Mastercard Payment Gateway Services.',
			'mastercard'
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->order_prefix    = $this->get_option( 'order_prefix' );
		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->enabled         = $this->get_option( 'enabled', false );
		$this->hc_type         = $this->get_option( 'hc_type', self::HC_TYPE_MODAL );
		$this->hc_interaction  = $this->get_option( 'hc_interaction', self::HC_TYPE_EMBEDDED );
		$this->capture         = $this->get_option( 'txn_mode', self::TXN_MODE_PURCHASE ) === self::TXN_MODE_PURCHASE;
		$this->threedsecure_v1 = $this->get_option( 'threedsecure', self::THREED_DISABLED ) === self::THREED_V1;
		$this->threedsecure_v2 = $this->get_option( 'threedsecure', self::THREED_DISABLED ) === self::THREED_V2;
		$this->method          = $this->get_option( 'method', self::HOSTED_CHECKOUT );
		$this->saved_cards     = $this->get_option( 'saved_cards', 'yes' ) === 'yes';
		$this->supports        = array(
			'products',
			'refunds',
			'tokenization',
		);
		$this->hf_enabled      = $this->get_option( 'hf_enabled', false );
		$this->send_line_items = $this->get_option( 'send_line_items', false );
		$this->mif_enabled     = $this->get_option( 'mif_enabled', false );
		$this->service         = $this->init_service();

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options' // phpcs:ignore
			)
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_gateway_scripts' ), 10 );
		add_action( 'woocommerce_order_action_mpgs_capture_payment', array( $this, 'process_capture' ) );
		add_action( 'woocommerce_order_action_mpgs_void_payment', array( $this, 'void_authorized_order' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_mastercard_gateway', array( $this, 'return_handler' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_js_extra_attribute' ), 10 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_handling_fee' ), 10, 1 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_mpgs_handling_fee_on_place_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_mpgs_handling_fee_api_on_place_order' ), 10, 1 );
		add_action( 'wp_footer', array( $this, 'refresh_handling_fees_on_checkout' ) );
		add_action( 'wp_ajax_update_selected_payment_method', array( $this, 'update_selected_payment_method' ) );
		add_action( 'wp_ajax_nopriv_update_selected_payment_method', array( $this, 'update_selected_payment_method' ) );
		add_action( 'template_redirect', array( $this, 'define_default_payment_gateway' ) );
	}

	/**
	 * Initializes Mastercard Gateway Service.
	 *
	 * @return Mastercard_GatewayService
	 *
	 * @throws Exception If there's a problem connecting to the Gateway Service.
	 */
	protected function init_service() {
		$this->sandbox  = $this->get_option( 'sandbox', false );
		$this->username = 'no' === $this->sandbox ? $this->get_option( 'username' ) : $this->get_option( 'sandbox_username' );
		$this->password = 'no' === $this->sandbox ? $this->get_option( 'password' ) : $this->get_option( 'sandbox_password' );

		$logging_level = $this->is_debug_logging_enabled()
			? \Monolog\Logger::DEBUG
			: \Monolog\Logger::ERROR;

		return new Mastercard_GatewayService(
			$this->get_gateway_url(),
			$this->get_api_version(),
			$this->username,
			$this->password,
			$this->get_webhook_url(),
			$logging_level
		);
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool True if debug logging is enabled, false otherwise.
	 */
	protected function is_debug_logging_enabled() {
		if ( 'yes' === $this->sandbox ) {
			return 'yes' === $this->get_option( 'debug', false );
		}

		return false;
	}

	/**
	 * Get the URL of the payment gateway.
	 *
	 * @return string The URL of the payment gateway.
	 */
	public function get_gateway_url() {
		$gateway_url = $this->get_option( 'gateway_url', self::API_EU );
		if ( self::API_CUSTOM === $gateway_url ) {
			$gateway_url = $this->get_option( 'custom_gateway_url' );
		}

		return $gateway_url;
	}

	/**
	 * This function processes the admin options.
	 *
	 * @return array $saved Admin Options.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		try {
			if( 'mpgs_gateway' === $this->id ) {
				static $error_added = false;
				if( isset( $this->settings['hf_amount_type'] ) && 'percentage' === $this->settings['hf_amount_type'] ) {
					if ( absint( $this->settings['handling_fee_amount'] ) > 100 ) {
						if ( ! $error_added ) {
							WC_Admin_Settings::add_error( __( 'The maximum allowable percentage is 100.', 'mastercard' ) );
							$error_added = true;
						}
						$this->update_option( 'handling_fee_amount', 100 );
					}
				}   
			}
			$service = $this->init_service();
			$service->paymentOptionsInquiry();
		} catch ( Exception $e ) {
			$this->add_error(
				/* translators: %s: error message */
				sprintf( __( 'Error communicating with payment gateway API: "%s"', 'mastercard' ), $e->getMessage() )
			);
		}

		return $saved;
	}

	/**
	 * This function is responsible for including the necessary admin scripts.
	 *
	 * @return void
	 */
	public function admin_scripts() {
		if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'woocommerce_mastercard_admin',
			plugins_url( 'assets/js/mastercard-admin.js', __FILE__ ),
			array(),
			MPGS_TARGET_MODULE_VERSION,
			true
		);
	}

	/**
	 * This function is responsible for including the necessary payment gateway scripts.
	 *
	 * @return void
	 */
	public function payment_gateway_scripts() {
		$order_id = get_query_var( 'order-pay' );
		$order    = new WC_Order( $order_id );
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		if ( self::HOSTED_CHECKOUT === $this->method ) {
			wp_enqueue_script(
				'woocommerce_mastercard_hosted_checkout',
				esc_attr( $this->get_hosted_checkout_js() ),
				array(),
				MPGS_TARGET_MODULE_VERSION,
				false
			);
		}

		if ( self::HOSTED_SESSION === $this->method ) {
			wp_enqueue_script(
				'woocommerce_mastercard_hosted_session',
				esc_url( $this->get_hosted_session_js() ),
				array(),
				MPGS_TARGET_MODULE_VERSION,
				false
			);

			if ( $this->use_3dsecure_v1() || $this->use_3dsecure_v2() ) {
				wp_enqueue_script(
					'woocommerce_mastercard_threeds',
					esc_url( $this->get_threeds_js() ),
					array(),
					MPGS_TARGET_MODULE_VERSION,
					false
				);
			}
		}
	}

	/**
	 * This function is responsible for including the necessary payment gateway scripts.
	 *
	 * @param string $tag Script link.
	 *
	 * @return string $tag Script link.
	 */
	public function add_js_extra_attribute( $tag ) {
		$scripts = array( $this->get_hosted_checkout_js() );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				if ( true === strpos( $tag, $script ) ) {
					return str_replace( ' src', ' async data-error="errorCallback" data-cancel="cancelCallback" src', $tag );
				}
			}
		}

		return $tag;
	}

	/**
	 * Process the capture.
	 *
	 * @return void
	 *
	 * @throws Exception If there's a problem for capturing the payment.
	 */
	public function process_capture() {

		if ( ! isset( $_REQUEST['post_ID'] ) ) { // phpcs:ignore
			return;
		}
		$order = new WC_Order( sanitize_text_field( wp_unslash( $_REQUEST['post_ID'] ) ) ); // phpcs:ignore
		if ( $order->get_payment_method() !== $this->id ) {
			throw new Exception( 'Wrong payment method' );
		}
		if ( $order->get_status() !== 'processing' ) {
			throw new Exception( 'Wrong order status, must be \'processing\'' );
		}

		if ( ! empty( $order->get_meta( '_mpgs_order_captured' ) ) ) {
			throw new Exception( 'The order is already captured.' );
		}

		$result = $this->service->captureTxn(
			$this->add_order_prefix( $order->get_id() ),
			time(),
			(float) $order->get_total(),
			$order->get_currency()
		);

		$txn = $result['transaction'];
		$order->add_order_note(
			sprintf(
				/* translators: 1. Capture id, 2. Authorization Code. */
				__( 'Mastercard payment CAPTURED (ID: %1$s, Auth Code: %1$s)', 'mastercard' ),
				$txn['id'],
				$txn['authorizationCode']
			)
		);

		$order->update_meta_data( '_mpgs_order_captured', true );
		$order->update_meta_data( '_mpgs_transaction_mode', 'captured' );
		$order->save_meta_data();

		if ( wp_get_referer() || 'yes' !== WC_Mastercard::is_hpos() ) {
			wp_safe_redirect( wp_get_referer() );
		} else {
			$return_url = add_query_arg( array(
			    'page'    => 'wc-orders',
			    'action'  => 'edit',
			    'id'      => $order->get_id(),
			    'message' => 1
			), admin_url( 'admin.php' ) );
			wp_safe_redirect( $return_url );
		}
		exit;
	}

	/**
	 * Reverse Authorization.
	 *
	 * @return void
	 *
	 * @throws Exception If there's a problem for capturing the payment.
	 */
    public function void_authorized_order() {
        try {
        	$order_id   = sanitize_text_field( wp_unslash( $_REQUEST['post_ID'] ) ); // phpcs:ignore
            $order      = new WC_Order( $order_id );
            $auth_txn   = $this->service->getAuthorizationTransaction( $this->add_order_prefix( $order_id ) );

            if ( $order->get_payment_method() != $this->id ) {
                throw new Exception( 'Wrong payment method' );
            }
            if ( $order->get_status() != 'processing' ) {
                throw new Exception( 'Wrong order status, must be \'processing\'' );
            }
            if ( $order->get_meta( '_mpgs_order_captured' ) ) {
                throw new Exception( 'Order already reversed' );
            }

            $transaction_id = $order->get_meta( '_mpgs_transaction_id' );  

            if( $transaction_id === $auth_txn['transaction']['id'] || $transaction_id === $auth_txn['authentication']['transactionId'] ) {
	            $result = $this->service->voidTxn(
					$this->add_order_prefix( $order->get_id() ),
					$auth_txn['transaction']['id']
				);		
	         	
	         	if( 'SUCCESS' === $result['result'] ) {
	         		$order->update_meta_data( '_mpgs_transaction_mode', 'void' );
		            $txn = $result['transaction'];
		            $order->update_status( 'cancelled', sprintf( __( 'Gateway reverse authorization (ID: %s)',
		                'mastercard' ),
		                $txn['id'] ) );
		        } else {
		        	throw new Exception( 'Gateway reverse authorization failure.' );
		        }

	            if ( wp_get_referer() || 'yes' !== WC_Mastercard::is_hpos() ) {
	                wp_safe_redirect( wp_get_referer() );
	            } else {
	                $return_url = add_query_arg( array(
	                    'page'    => 'wc-orders',
	                    'action'  => 'edit',
	                    'id'      => $order->get_id(),
	                    'message' => 1
	                ), admin_url( 'admin.php' ) );
	                wp_safe_redirect( $return_url );
	            }
	            exit;
	        }
        } catch ( Exception $e ) {
            wp_die( $e->getMessage(), __( 'Gateway reverse authorization failure.' ) );
        }
    }

	/**
	 * This function displays admin notices.
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( ! $this->enabled ) {
			return;
		}

		if ( ! $this->username || ! $this->password ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'API credentials are not valid. To activate the payment methods please your details to the forms below.' ) . '</p></div>';
		}

		$this->display_errors();
	}

	/**
	 * Process a refund for an order.
	 *
	 * @param int        $order_id The ID of the order being refunded.
	 * @param float|null $amount The amount to be refunded.
	 * @param string     $reason The reason for the refund.
	 *
	 * @return bool True if the refund was processed successfully, false otherwise.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order  = new WC_Order( $order_id );
		$result = $this->service->refund(
			$this->add_order_prefix( $order_id ),
			(string) time(),
			$amount,
			$order->get_currency()
		);
		$order->update_meta_data( '_mpgs_transaction_mode', 'refund' );
		$order->add_order_note(
			sprintf(
				/* translators: 1. Transaction amount, 2. Transaction currency, 3. Transaction id. */
				__( 'Mastercard registered refund %1$s (ID: %2$s)', 'mastercard' ),
				wc_price( $result['transaction']['amount'] ),
				$result['transaction']['id']
			)
		);

		return true;
	}

	/**
	 * A function that handles the return value.
	 *
	 * @return void.
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
							__( '3DS authorization was not provided. Payment declined.', 'mastercard' )
						);
						wc_add_notice(
							__( '3DS authorization was not provided. Payment declined.', 'mastercard' ),
							'error'
						);
					}
				}
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}
		}

		if ( self::HOSTED_SESSION === $this->method ) {
			$this->process_hosted_session_payment( $three_ds_txn_id );
		}

		/**
		 * Remove branching after Legacy Hosted Checkout removal
		 *
		 * @todo Remove branching after Legacy Hosted Checkout removal
		 */
		if ( in_array( $this->method, array( self::HOSTED_CHECKOUT ), true ) ) {
			$this->process_hosted_checkout_payment();
		}
	}

	/**
	 * Process the hosted checkout payment.
	 *
	 * This function is responsible for processing the payment made through a hosted checkout.
	 * It performs the necessary actions to complete the payment process.
	 *
	 * @throws Exception If the payment was declined.
	 */
	protected function process_hosted_checkout_payment() {
		if ( isset( $_REQUEST['order_id'] ) ) { // phpcs:ignore
			$order_id = $this->remove_order_prefix( sanitize_text_field( wp_unslash( $_REQUEST['order_id'] ) ) ); // phpcs:ignore
		}

		if ( isset( $_REQUEST['resultIndicator'] ) ) { // phpcs:ignore
			$result_indicator = sanitize_text_field( wp_unslash( $_REQUEST['resultIndicator'] ) ); // phpcs:ignore
		}

		$order             = new WC_Order( $order_id );
		$success_indicator = $order->get_meta( '_mpgs_success_indicator' );

		try {
			if ( $success_indicator !== $result_indicator ) {
				throw new Exception( 'Result indicator mismatch' );
			}

			$mpgs_order = $this->service->retrieveOrder( $this->add_order_prefix( $order_id ) );
			if ( 'SUCCESS' !== $mpgs_order['result'] ) {
				throw new Exception( 'Payment was declined' );
			}

			$txn = $mpgs_order['transaction'][0];
			$this->process_wc_order( $order, $mpgs_order, $txn );

			wp_safe_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit();
		}
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
		$tokens = $this->get_tokens();
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
		return 'wc-' . $this->id . '-payment-token';
	}

	/**
	 * Process the hosted session payment.
	 *
	 * @param string|null $three_ds_txn_id The 3DS transaction ID, if available.
	 *
	 * @return void
	 */
	protected function process_hosted_session_payment( $three_ds_txn_id = null ) { 

		$order_id        = isset( $_REQUEST['order_id'] ) ? $this->remove_order_prefix( sanitize_text_field( wp_unslash( $_REQUEST['order_id'] ) ) ) : null; // phpcs:ignore
		$session_id      = isset( $_REQUEST['session_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['session_id'] ) ) : null; // phpcs:ignore
		$session_version = isset( $_REQUEST['session_version'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['session_version'] ) ) : null; // phpcs:ignore

		$session = array(
			'id' => $session_id,
		);

		if ( null === $session_version ) {
			$session['version'] = $session_version;
		}

		$order              = new WC_Order( $order_id );
		$check_3ds          = isset( $_REQUEST['check_3ds_enrollment'] ) ? '1' === $_REQUEST['check_3ds_enrollment'] : false; // phpcs:ignore
		$process_acl_result = isset( $_REQUEST['process_acs_result'] ) ? '1' === $_REQUEST['process_acs_result'] : false; // phpcs:ignore
		$mgps_3ds_nonce 	= isset( $_REQUEST['mgps_3ds_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mgps_3ds_nonce'] ) ) : null; // phpcs:ignore
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
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard' ) );
				wc_add_notice( __( 'Payment was declined.', 'mastercard' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			if ( isset( $response['3DSecure']['authenticationRedirect'] ) ) {
				$tds_auth  = $response['3DSecure']['authenticationRedirect']['customized'];
				$token_key = $this->get_token_key();

				set_query_var( 'authenticationRedirect', $tds_auth );
				set_query_var(
					'returnUrl',
					$this->get_payment_return_url(
						$order_id,
						array(
							'3DSecureId'         => $response['3DSecureId'],
							'process_acs_result' => '1',
							'session_id'         => $session_id,
							'session_version'    => $session_version,
							'mgps_3ds_nonce'     => wp_create_nonce( 'mastercard_3ds_nonce' ),
							$token_key           => isset( $_REQUEST[ $token_key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $token_key ] ) ) : null, // phpcs:ignore
						)
					)
				);

				set_query_var( 'order', $order );
				set_query_var( 'gateway', $this );

				load_template( dirname( __DIR__ ) . '/templates/3dsecure/form.php' );
				exit();
			}

			$this->pay( $session, $order, null );
		}

		if ( $process_acl_result && wp_verify_nonce( $mgps_3ds_nonce, 'mastercard_3ds_nonce' ) ) {
			$pa_res   = isset( $_POST['PaRes'] ) ? sanitize_text_field( wp_unslash( $_POST['PaRes'] ) ) : null;
			$tds_id   = isset( $_REQUEST['3DSecureId'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['3DSecureId'] ) ) : null;
			$response = $this->service->process3dsResult( $tds_id, $pa_res );

			if ( 'PROCEED' !== $response['response']['gatewayRecommendation'] ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard' ) );
				wc_add_notice( __( 'Payment was declined.', 'mastercard' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			$this->pay( $session, $order, $tds_id );
		}

		if ( null !== $three_ds_txn_id ) {
			$this->pay( $session, $order, $three_ds_txn_id );
		}

		if ( ! $check_3ds && ! $process_acl_result && ! $this->threedsecure_v1 ) {
			$this->pay( $session, $order, null );
		}

		$order->update_status( 'failed', __( 'Unexpected payment condition error.', 'mastercard' ) );
		wc_add_notice( __( 'Unexpected payment condition error.', 'mastercard' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit();
	}

	/**
	 * Process the payment for a given session and order.
	 *
	 * @param string      $session The session ID.
	 * @param string      $order The order ID.
	 * @param string|null $tds_id The TDS ID, if available.
	 *
	 * @return void
	 * @throws Exception If the payment was declined.
	 */
	protected function pay( $session, $order, $tds_id = null ) {
		if ( $this->is_order_paid( $order ) ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit();
		}

		try {
			$txn_id = $this->generate_txn_id_for_order( $order );
			$auth   = null;
			if ( $this->threedsecure_v2 ) {
				$auth   = array(
					'transactionId' => $tds_id,
				);
				$tds_id = null;
			}

			$order_builder = new Mastercard_CheckoutBuilder( $order );
			if ( $this->capture ) {
				$mpgs_txn = $this->service->pay(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->getOrder(),
					$auth,
					$tds_id,
					$session,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);
			} else {
				$mpgs_txn = $this->service->authorize(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->getOrder(),
					$auth,
					$tds_id,
					$session,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);
			}

			if ( 'SUCCESS' !== $mpgs_txn['result'] ) {
				$gateway_code = $mpgs_txn['response']['gatewayCode']; 

				if ( 'DECLINED' === $gateway_code ) {
					throw new Exception( __( 'Payment unsuccessful; your card has been declined.', 'mastercard' ) );
				} elseif ( 'EXPIRED_CARD' === $gateway_code ) {
					throw new Exception( __( 'The card has expired. Please enter a new card for payment.', 'mastercard' ) );
				} elseif ( 'TIMED_OUT' === $gateway_code ) {
					throw new Exception( __( 'We couldn\'t process your card request within the allotted time, and it timed out.', 'mastercard' ) );
				} elseif ( 'ACQUIRER_SYSTEM_ERROR' === $gateway_code ) {
					throw new Exception( __( 'The transaction was disrupted due to an issue in the acquirer\'s system.', 'mastercard' ) );
				} elseif ( 'UNSPECIFIED_FAILURE' === $gateway_code ) {
					throw new Exception( __( 'An unspecified issue has occurred with your card. Please check the details and try again.', 'mastercard' ) );
				} elseif ( 'AUTHORIZATION_FAILED' === $gateway_code ) {
					throw new Exception( __( 'The card not authorized. Please enter a new card for payment.', 'mastercard' ) );
				} else {
					throw new Exception( __( 'Payment was declined.', 'mastercard' ) );
				}
			}

			$this->process_wc_order( $order, $mpgs_txn['order'], $mpgs_txn );

			if ( $this->saved_cards && $order->get_meta( '_save_card' ) ) {
				$this->process_saved_cards( $session, $order->get_user_id( 'system' ) );	
			}

			wp_safe_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit();
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
	 * @throws Exception If the session or user ID is empty.
	 */
	protected function process_saved_cards( $session, $user_id ) {
		$response = $this->service->createCardToken( $session['id'] );

		if ( ! isset( $response['token'] ) || empty( $response['token'] ) ) {
			throw new Exception( 'Token not present in reponse' );
		}

		$token = new WC_Payment_Token_CC();
		$token->set_token( $response['token'] );
		$token->set_gateway_id( $this->id );
		$token->set_card_type( $response['sourceOfFunds']['provided']['card']['brand'] );

		$last4 = substr(
			$response['sourceOfFunds']['provided']['card']['number'],
			- 4
		);
		$token->set_last4( $last4 );

		$m = array(); // phpcs:ignore
		preg_match( '/^(\d{2})(\d{2})$/', $response['sourceOfFunds']['provided']['card']['expiry'], $m );

		$token->set_expiry_month( $m[1] );
		$token->set_expiry_year( '20' . $m[2] );
		$token->set_user_id( $user_id );

		$token->save();
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
		$captured = 'CAPTURED' === $order_data['status'];
		$transaction_mode = ( $this->capture ) ? self::TXN_MODE_PURCHASE : self::TXN_MODE_AUTH_CAPTURE;
		$order->add_meta_data( '_mpgs_order_captured', $captured );
		$order->add_meta_data( '_mpgs_transaction_mode', $transaction_mode );
		$order->add_meta_data( '_mpgs_order_paid', 1 );
		$order->add_meta_data( '_mpgs_transaction_id', $txn_data['transaction']['id'] );
		$order->add_meta_data( '_mpgs_transaction_reference', $txn_data['transaction']['reference'] );
		$order->payment_complete( $txn_data['transaction']['id'] );

		if ( $captured ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1. Transaction id, 2. Authorization Code. */
					__( 'Mastercard payment CAPTURED (ID: %1$s, Auth Code: %2$s)', 'mastercard' ),
					$txn_data['transaction']['id'],
					$txn_data['transaction']['authorizationCode']
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1. Transaction id, 2. Authorization Code. */
					__( 'Mastercard payment AUTHORIZED (ID: %1$s, Auth Code: %2$s)', 'mastercard' ),
					$txn_data['transaction']['id'],
					$txn_data['transaction']['authorizationCode']
				)
			);
		}
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
	 * @throws Exception If the order or MPG order is not a valid array.
	 */
	protected function validate_order( $order, $mpgs_order ) {
		if ( $order->get_currency() !== $mpgs_order['currency'] ) {
			throw new Exception( 'Currency mismatch' );
		}

		if ( (float) $order->get_total() !== (float) $mpgs_order['amount'] ) {
			throw new Exception( 'Amount mismatch' );
		}

		return true;
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

		return add_query_arg( 'wc-api', self::class, home_url( '/' ) ) . '&' . http_build_query( $params );
	}

	/**
	 * This function demonstrates the usage of the Embedded Form.
	 *
	 * @return boolean
	 */
	public function use_embedded() {
		return self::HC_TYPE_EMBEDDED === $this->hc_interaction;
	}

	/**
	 * This function demonstrates the usage of the Modal Form.
	 *
	 * @return boolean
	 */
	public function use_modal() {
		return self::HC_TYPE_MODAL === $this->hc_type;
	}

	/**
	 * Use 3D Secure version 1 for payment processing.
	 *
	 * This function performs the necessary steps to use 3D Secure version 1 for payment processing.
	 * It may involve redirecting the user to a 3D Secure authentication page, collecting and validating
	 * the authentication response, and completing the payment process.
	 *
	 * @return bool True if the payment is successfully processed using 3D Secure version 1, false otherwise.
	 *
	 * @throws Exception If any error occurs during the payment process.
	 */
	public function use_3dsecure_v1() {
		return $this->threedsecure_v1;
	}

	/**
	 * Use 3D Secure version 2 for payment processing.
	 *
	 * This function performs the necessary steps to use 3D Secure version 2 for payment processing.
	 * It may involve redirecting the user to a 3D Secure authentication page, collecting and validating
	 * the authentication response, and completing the payment process.
	 *
	 * @return bool True if the payment is successfully processed using 3D Secure version 2, false otherwise.
	 *
	 * @throws Exception If any error occurs during the payment process.
	 */
	public function use_3dsecure_v2() {
		return $this->threedsecure_v2;
	}

	/**
	 * Get the merchant ID.
	 *
	 * This function retrieves the unique identifier for the merchant.
	 *
	 * @return string The merchant ID.
	 */
	public function get_merchant_id() {
		return $this->username;
	}

	/**
	 * Get the API version number.
	 *
	 * This function retrieves the API version number from a predefined source.
	 *
	 * @return string The API version number.
	 */
	public function get_api_version_num() {
		return (int) self::MPGS_API_VERSION_NUM;
	}

	/**
	 * Get the API version.
	 *
	 * This function returns the current version of the API.
	 *
	 * @return string The API version.
	 */
	public function get_api_version() {
		return self::MPGS_API_VERSION;
	}

	/**
	 * This function generates the URL for creating a checkout session for a given order ID.
	 *
	 * @param int $for_order_id The ID of the order for which the checkout session URL is generated.
	 *
	 * @return string The URL for creating a checkout session.
	 */
	public function get_create_checkout_session_url( $for_order_id ) {
		return rest_url( "mastercard/v1/checkoutSession/{$for_order_id}/" );
	}

	/**
	 * Generate a create session URL for a given order ID.
	 *
	 * This function takes an order ID as input and generates a create session URL
	 * that can be used to create a new session for the specified order.
	 *
	 * @param int $for_order_id The order ID for which the create session URL is generated.
	 *
	 * @return string The create session URL.
	 */
	public function get_create_session_url( $for_order_id ) {
		return rest_url( "mastercard/v1/session/{$for_order_id}/" );
	}

	/**
	 * Generate a save payment URL for a given order ID.
	 *
	 * This function generates a URL that can be used to save a payment for a specific order.
	 *
	 * @param int $for_order_id The ID of the order for which the payment URL is generated.
	 *
	 * @return string The generated save payment URL.
	 */
	public function get_save_payment_url( $for_order_id ) {
		return rest_url( "mastercard/v1/savePayment/{$for_order_id}/" );
	}

	/**
	 * Get the webhook URL.
	 *
	 * This function retrieves the webhook URL.
	 *
	 * @return string The webhook URL.
	 */
	public function get_webhook_url() {
		return rest_url( 'mastercard/v1/webhook/' );
	}

	/**
	 * This function processes a REST route and request.
	 *
	 * @param string $route The REST route to be processed.
	 * @param array  $request The request data associated with the route.
	 *
	 * @return mixed The processed result of the route and request.
	 *
	 * @throws Exception If the route or request is invalid or if an error occurs during processing.
	 */
	public function rest_route_processor( $route, $request ) {
		$result = null;
		switch ( $route ) {
			case ( (bool) preg_match( '~/mastercard/v1/checkoutSession/\d+~', $route ) ):
				$order         = new WC_Order( $request->get_param( 'id' ) );
				$return_url    = $this->get_payment_return_url( $order->get_id() );
				$order_builder = new Mastercard_CheckoutBuilder( $order );
				$result        = $this->service->initiateCheckout(
					$order_builder->getHostedCheckoutOrder(),
					$order_builder->getInteraction(
						$this->capture,
						$return_url
					),
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);

				if ( $result && $result['successIndicator'] ) {
					$order->update_meta_data( '_mpgs_success_indicator', $result['successIndicator'] );

					if ( ! $order->meta_exists( '_mpgs_success_indicator' ) ) {
						global $wpdb, $table_prefix;
						$wpdb->query( 
							$wpdb->prepare(
								"INSERT INTO " . $table_prefix . "wc_orders_meta 
								(order_id, meta_key, meta_value) 
								VALUES (%d, %s, %s)",
								$order->get_id(),
								'_mpgs_success_indicator',
								$result['successIndicator']
							)
						);
					}
				}
				$order->save_meta_data();
				break;

			case ( (bool) preg_match( '~/mastercard/v1/savePayment/\d+~', $route ) ):
				$order = new WC_Order( $request->get_param( 'id' ) );

				$save_new_card = 'true' === $request->get_param( 'save_new_card' );
				if ( $save_new_card ) {
					$order->update_meta_data( '_save_card', true );
					$order->save_meta_data();
				}

				$auth = array();
				if ( $this->threedsecure_v1 ) {
					$auth = array(
						'acceptVersions' => '3DS1',
					);
				}

				if ( $this->threedsecure_v2 ) {
					$auth = array(
						'channel' => 'PAYER_BROWSER',
						'purpose' => 'PAYMENT_TRANSACTION',
					);
				}

				$session_id    = $order->get_meta( '_mpgs_session_id' );
				$order_builder = new Mastercard_CheckoutBuilder( $order );
				$result        = $this->service->update_session(
					$session_id,
					$order_builder->getHostedCheckoutOrder(),
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping(),
					$auth,
					$this->get_token_from_request()
				);

				if ( $result && $result['successIndicator'] ) {
					if ( $order->meta_exists( '_mpgs_success_indicator' ) ) {
						$order->update_meta_data( '_mpgs_success_indicator', $result['successIndicator'] );
					} else {
						$order->add_meta_data( '_mpgs_success_indicator', $result['successIndicator'], true  );
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
				$this->mgps_webhook_handler( $request );
				break;
	
			default:
				break;	
		}

		return $result;
	}

	public function mgps_webhook_handler( $request ) {
		$body                = $request->get_body();
    	$headers             = $request->get_headers();
    	$secret              = $headers['x_notification_secret'][0];
    	$notification_secret = $this->get_option( 'webhook_secret' );
    	$response            = json_decode( $body, true );
    	$order_status        = array( 'cancelled', 'failed', 'on-hold' );

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
					break;

	    		default:
	    			break;
	    	}  	
    	} else {
    		return new WP_REST_Response( array( 'error' => 'Invalid Order' ), 400 );
    	}
	}

	/**
	 * Get the hosted checkout JavaScript code.
	 *
	 * @return string The JavaScript code for the hosted checkout.
	 */
	public function get_hosted_checkout_js() {

		return sprintf(
			'https://%s/static/checkout/checkout.min.js',
			$this->get_gateway_url()
		);
	}

	/**
	 * Generate the JavaScript code for a hosted session.
	 *
	 * @return string The generated JavaScript code.
	 */
	public function get_hosted_session_js() {
		return sprintf(
			'https://%s/form/%s/merchant/%s/session.js',
			$this->get_gateway_url(),
			self::MPGS_API_VERSION,
			$this->get_merchant_id()
		);
	}

	/**
	 * Generate the JavaScript code for a 3D scene.
	 *
	 * @return string The generated JavaScript code.
	 */
	public function get_threeds_js() {
		return sprintf(
			'https://%s/static/threeDS/1.3.0/three-ds.min.js',
			$this->get_gateway_url()
		);
	}

	/**
	 * Process the payment for the given order ID.
	 *
	 * @param int $order_id The ID of the order to process payment for.
	 *
	 * @return bool True if the payment was successfully processed, false otherwise.
	 */
	public function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );
		$order->update_status( 'pending', __( 'Pending payment', 'mastercard' ) );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Generate the receipt page for a given order ID.
	 *
	 * @param int $order_id The ID of the order for which the receipt page is generated.
	 *
	 * @return void
	 */
	public function receipt_page( $order_id ) {
		$order = new WC_Order( $order_id );

		set_query_var( 'order', $order );
		set_query_var( 'gateway', $this ); 

		if ( self::HOSTED_SESSION === $this->method ) {
			$display_tokenization = $this->supports( 'tokenization' ) && is_checkout() && $this->saved_cards;
			set_query_var( 'display_tokenization', $display_tokenization );

			$cc_form     = new Mastercard_Payment_Gateway_CC();
			$cc_form->id = $this->id;
			$support     = $this->supports;

			if ( false === $this->saved_cards ) {
				foreach ( array_keys( $support, 'tokenization', true ) as $key ) {
					unset( $support[ $key ] );
				}
			}
			$cc_form->supports = $support;
			set_query_var( 'cc_form', $cc_form );

			load_template( dirname( __DIR__ ) . '/templates/checkout/hosted-session.php' );
		} else {
			load_template( dirname( __DIR__ ) . '/templates/checkout/hosted-checkout.php' );
		}
	}

	/**
	 * Initialize form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = include dirname( __DIR__ ) . '/includes/settings-mastercard.php';
	}

	/**
	 * Check if a resource is available.
	 *
	 * @return bool Returns true if the resource is available, false otherwise.
	 */
	public function is_available() {
		$is_available = parent::is_available();

		if ( ! $this->username || ! $this->password ) {
			return false;
		}

		return $is_available;
	}

	/**
	 * Function to remove the order prefix from an order ID.
	 *
	 * @param string $order_id The order ID with the prefix.
	 *
	 * @return string The order ID without the prefix.
	 */
	public function remove_order_prefix( $order_id ) {
		if ( $this->order_prefix && strpos( $order_id, $this->order_prefix ) === 0 ) {
			$order_id = substr( $order_id, strlen( $this->order_prefix ) );
		}

		return $order_id;
	}

	/**
	 * This function adds a prefix to an order ID.
	 *
	 * @param string $order_id The original order ID.
	 *
	 * @return string The order ID with the prefix added.
	 */
	public function add_order_prefix( $order_id ) {
		if ( $this->order_prefix ) {
			$order_id = $this->order_prefix . $order_id;
		}

		return $order_id;
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
		if ( $this->order_prefix ) {
			$order_id = $this->order_prefix;
		}
		$order_id .= $order->get_id();

		return sprintf( '%s-%s', $order_id, $txn_id );
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
	 * Adds a handling fee to the WooCommerce cart calculation.
	 * 
	 * This ensures that the handling fee is added during the cart calculation process.
	 */
	public function add_handling_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $chosen_gateway = WC()->session->get( 'chosen_payment_method' );

        if ( ! empty( $chosen_gateway ) ) {
			if ( isset( $this->hf_enabled ) && 'yes' === $this->hf_enabled && self::ID === $chosen_gateway ){
				$handling_text = $this->get_option( 'handling_text' );
				$amount_type   = $this->get_option( 'hf_amount_type' );
				$handling_fee  = $this->get_option( 'handling_fee_amount' ) ? $this->get_option( 'handling_fee_amount' ) : 0;

				if ( self::HF_PERCENTAGE === $amount_type ) {
					$surcharge = (float)( WC()->cart->cart_contents_total ) * ( (float) $handling_fee / 100 );
				} else {
					$surcharge = $handling_fee;
				}

				if ( WC()->session ) {
					WC()->session->set( 'mpgs_handling_fee', $surcharge );
				}

			    WC()->cart->add_fee( $handling_text, $surcharge, true, '' );
			}
		}
	}

	/**
	 * Save MasterCard Payment Gateway Services (MPGS) handling fee to the order.
	 *
	 * This function is triggered during the WooCommerce checkout process, right after the 
	 * "Place Order" button is clicked and the order is being created. It adds the handling 
	 * fee related to MPGS to the order metadata, which can later be used for calculations, 
	 * displaying fees in the backend, or other custom logic.
	 *
	 * @param WC_Order $order_id The order id.
	 * @param array    $data  The posted data during checkout process (billing, shipping, etc.).
	 */
	public function save_mpgs_handling_fee_on_place_order( $order_id, $data ) {
		$chosen_gateway = WC()->session->get( 'chosen_payment_method' );

		if ( isset( $this->hf_enabled ) && 'yes' === $this->hf_enabled && self::ID === $chosen_gateway ) {
		    if ( $order_id ) {
		    	$order = wc_get_order( $order_id ); 
		        $handling_fee = WC()->session->get( 'mpgs_handling_fee' );
		        $order->update_meta_data( '_mpgs_handling_fee', $handling_fee );
		        $order->save();
		        WC()->session->__unset( '_mpgs_handling_fee' );
		    }
		} else {
			WC()->session->__unset( '_mpgs_handling_fee' );
		}
	}

	/**
	 * Save MasterCard Payment Gateway Services (MPGS) handling fee to the order through WooCommerce checkout block.
	 *
	 * This function is triggered during the WooCommerce checkout process, right after the 
	 * "Place Order" button is clicked and the order is being created. It adds the handling 
	 * fee related to MPGS to the order metadata, which can later be used for calculations, 
	 * displaying fees in the backend, or other custom logic.
	 *
	 * @param WC_Order $order_id The order id.
	 */
	public function save_mpgs_handling_fee_api_on_place_order( $order ) {
		$chosen_gateway = WC()->session->get( 'chosen_payment_method' );

		if ( isset( $this->hf_enabled ) && 'yes' === $this->hf_enabled && self::ID === $chosen_gateway ) {
			if ( $order ) {
		        $handling_fee = WC()->session->get( 'mpgs_handling_fee' );
		        $order->update_meta_data( '_mpgs_handling_fee', $handling_fee );
		        WC()->session->__unset( '_mpgs_handling_fee' );
		    }
	    } else {
			WC()->session->__unset( '_mpgs_handling_fee' );
		}
	}

	/**
	 * Refreshes the handling fees on the checkout page dynamically.
	 * 
	 * This function is typically hooked into WooCommerce's AJAX or checkout update events.
	 * It ensures that handling fees are recalculated whenever the checkout page is refreshed,
	 * preventing outdated fee calculations due to changes in cart contents or other conditions.
	 */
	public function refresh_handling_fees_on_checkout() {
		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			static $executed = false;

			if ( $executed ) {
				return;
			}

        	$executed      = true;
        	$amount_type   = $this->get_option( 'hf_amount_type' );
        	$handling_fee  = $this->get_option( 'handling_fee_amount' ) ? $this->get_option( 'handling_fee_amount' ) : 0;

			if ( self::HF_PERCENTAGE === $amount_type ) {
				$surcharge = (float)( WC()->cart->cart_contents_total ) * ( (float) $handling_fee / 100 );
			} else {
				$surcharge = $handling_fee;
			}
	        ?>
	        <script type="text/javascript">
	        	const handlingText = '<?php echo sanitize_title( $this->get_option( 'handling_text' ) ); ?>';
	        	const handlingFeeWrapper = '<div class="wc-block-components-totals-item wc-block-components-totals-fees wc-block-components-totals-fees__<?php echo sanitize_title( $this->get_option( 'handling_text' ) ); ?>"><span class="wc-block-components-totals-item__label"><?php echo $this->get_option( 'handling_text' ); ?></span><span class="wc-block-formatted-money-amount wc-block-components-formatted-money-amount wc-block-components-totals-item__value"><?php echo wc_price( $surcharge ); ?></span><div class="wc-block-components-totals-item__description"></div></div>';
		        jQuery(function($) {
		            // Detect when payment method is changed
		            $( document ).on( 'change', 'input[name="payment_method"]', function() { 
	                    $( document.body ).trigger( "update_checkout" );
		            });
		        });
	        </script>
	        <?php
		}
	}

	/**
	 * Updates the selected payment method for the current user or session.
	 * 
	 * This function is typically used in WooCommerce or similar payment processing 
	 * plugins to update the user's chosen payment method when they select a new option
	 * at checkout. It ensures that the selected method is stored and used for order processing.
	 * 
	 * Implementation details may include:
	 * - Retrieving the selected payment method from the request.
	 * - Updating the user session or meta data accordingly.
	 * - Validating the payment method before updating.
	 * - Returning a response (if used in an AJAX call).
	 *
	 * @return void 
	 */
	public function update_selected_payment_method() {
	    if ( isset( $_POST['payment_method'] ) ) {
	    	$payment_method = sanitize_text_field( $_POST['payment_method'] ); 
	        WC()->session->set( 'chosen_payment_method', $payment_method );
	        WC()->cart->calculate_totals();

	        wp_send_json_success();
	    } else {
	        wp_send_json_error();
	    }
	}

	/**
	 * Refreshes handling fees dynamically on the WooCommerce checkout block.
	 *
	 * This function ensures that handling fees are recalculated and updated when
	 * the checkout block is refreshed. It is typically used in cases where handling 
	 * fees depend on cart contents, shipping method, or other dynamic conditions.
	 *
	 * @return boolean
	 */
	public static function refresh_handling_fees_on_checkout_block() {
		return true;
	}

	/**
	 * Define the default payment gateway for the checkout process.
	 *
	 * This function sets the default payment method when a customer visits
	 * the checkout page. It ensures the preferred gateway (e.g., Simplify Payments)
	 * is pre-selected to streamline the checkout experience.
	 */
	public function define_default_payment_gateway() {
	    if( is_checkout() && ! is_wc_endpoint_url() ) {
	        $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
	        $first_gateway = reset( $payment_gateways );
	        WC()->session->set( 'chosen_payment_method', $first_gateway->id );
	    }
	}
}
