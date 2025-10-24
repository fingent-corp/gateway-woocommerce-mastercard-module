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
 * @version  GIT: @1.5.0.1@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'MPGS_TARGET_MODULE_VERSION', '1.5.1' );
define( 'MPGS_INCLUDE_FILE', __FILE__ );
define( 'MPGS_CAPTURE_URL', 'https://mpgs.fingent.wiki/wp-json/mpgs/v2/update-repo-status' );

require_once dirname( __DIR__ ) . '/includes/class-checkout-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-gateway-service.php';
require_once dirname( __DIR__ ) . '/includes/class-payment-gateway-cc.php';
require_once dirname( __DIR__ ) . '/includes/class-payment-token-cc.php';


/**
 * Main class of the Mastercard Payment Gateway Module
 *
 * Represents a gateway service for processing Mastercard transactions.
 */
class Mastercard_Gateway extends WC_Payment_Gateway {

	const ID            = 'mpgs_gateway';
	const GATEWAY_TITLE = 'Mastercard Gateway';

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
	const HF_FEE_OPTION = 'mpgs_handling_fee';
	const HF_FEE_VAR    = '_mpgs_handling_fee';

	const SUR_ENABLED     = 'surcharge_enabled';
	const SUR_TEXT        = 'surcharge_text';
	const SUR_AMT_TYPE    = 'surcharge_amount_type';
	const SUR_AMT         = 'surcharge_amount';
	const SUR_CARD_TYPE   = 'surcharge_card_type';
	const SUR_MSG         = 'surcharge_message';
	const SUR_DEBIT       = 'Debit';
	const SUR_CREDIT      = 'Credit';
	const SUR_DEFAULT_MSG = 'When using a {{MG_CARD_TYPE}} an additional surcharge of <b>{{MG_SUR_AMT}} ({{MG_SUR_PCT}})</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.';


	const THREED_DISABLED = 'no';
	const THREED_V1       = 'yes'; // Backward compatibility with checkbox value.
	const THREED_V2       = '2';
	const STATUS_TOKEN    = '3958a5f32a0439ac8e09bbc44ca6d9d66bd8fb785f10145f4a446ec0b4f00639';

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
	 * Surcharge
	 *
	 * @var bool
	 */
	public $surcharge_enabled = null;

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
			'Accept payments on your WooCommerce store using Mastercard Gateway.',
			'mastercard'
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->order_prefix      = $this->get_option( 'order_prefix' );
		$this->title             = $this->get_option( 'title' );
		$this->description       = $this->get_option( 'description' );
		$this->enabled           = $this->get_option( 'enabled', false );
		$this->hc_type           = $this->get_option( 'hc_type', self::HC_TYPE_MODAL );
		$this->hc_interaction    = $this->get_option( 'hc_interaction', self::HC_TYPE_EMBEDDED );
		$this->capture           = $this->get_option( 'txn_mode', self::TXN_MODE_PURCHASE ) === self::TXN_MODE_PURCHASE;
		$this->threedsecure_v1   = $this->get_option( 'threedsecure', self::THREED_DISABLED ) === self::THREED_V1;
		$this->threedsecure_v2   = $this->get_option( 'threedsecure', self::THREED_DISABLED ) === self::THREED_V2;
		$this->method            = $this->get_option( 'method', self::HOSTED_CHECKOUT );
		$this->saved_cards       = $this->get_option( 'saved_cards', 'yes' ) === 'yes';
		$this->supports          = array(
			'products',
			'refunds',
			'tokenization',
		);
		$this->hf_enabled        = $this->get_option( 'hf_enabled', false );
		$this->send_line_items   = $this->get_option( 'send_line_items', false );
		$this->mif_enabled       = $this->get_option( 'mif_enabled', false );
		$this->surcharge_enabled = $this->get_option( self::SUR_ENABLED, false );
		$this->service           = $this->init_service();

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
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 99 );
		add_filter( 'script_loader_tag', array( $this, 'add_js_extra_attribute' ), 10 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_handling_fee' ), 10, 1 );
		add_action( 'wp_footer', array( $this, 'refresh_handling_fees_on_checkout' ) );
		add_action( 'wp_ajax_update_selected_payment_method', array( $this, 'update_selected_payment_method' ) );
		add_action( 'wp_ajax_nopriv_update_selected_payment_method', array( $this, 'update_selected_payment_method' ) );
		add_action( 'template_redirect', array( $this, 'define_default_payment_gateway' ) );
		add_action( 'wp_ajax_nopriv_get_surcharge_amount', array( $this, 'get_surcharge_amount' ), 99 );
		add_action( 'wp_ajax_get_surcharge_amount', array( $this, 'get_surcharge_amount' ), 99 );
		add_action( 'woocommerce_thankyou', array( $this, 'clear_session_storage' ), 10 );
		add_filter( 'woocommerce_saved_payment_methods_list', array( $this, 'remove_saved_mastercard_methods' ), 10, 2 );
		add_filter( 'woocommerce_payment_gateway_get_saved_payment_method_option_html', array( $this, 'mastercard_saved_payment_method_option_html' ), 10, 3 );
	}

	/**
	 * Removes saved Mastercard (MPGS) payment methods from the WooCommerce saved payment methods list.
	 *
	 * This function loops through the saved payment methods, filters out any method
	 * associated with the 'mpgs_gateway', and removes empty categories if no methods remain.
	 *
	 * @param array $saved_methods An array of saved payment methods categorized by type.
	 * @param int   $customer_id   The ID of the current customer.
	 *
	 * @return array The filtered list of saved payment methods without Mastercard (MPGS).
	 */
	public function remove_saved_mastercard_methods( $saved_methods, $customer_id ) {
		if ( empty( $saved_methods ) || ! is_array( $saved_methods ) ) {
			return $saved_methods;
		}
	
		foreach ( $saved_methods as $key => $methods ) {
			$saved_methods[$key] = array_filter( $methods, function ( $method ) {
				return empty( $method['method']['gateway'] ) || $method['method']['gateway'] !== 'mpgs_gateway';
			});
	
			if ( empty( $saved_methods[$key] ) ) {
				unset( $saved_methods[$key] );
			}
		}
	
		return $saved_methods;
	}

	/**
	 * This function clears the session storage after orders placed by mastercard payment gateway plugin.
	 *
	 * @return void
	 */

	public function clear_session_storage( $order_id ) {
		if ( ! $order_id ) {
			return;
		}
	
		$order = wc_get_order( $order_id );
	
		if ( $order instanceof WC_Order && $order->get_payment_method() === 'mpgs_gateway' ) {
			wp_enqueue_script(
				'clear-session-storage',
				plugins_url( 'assets/js/clear-session.js', __FILE__ ),
				array(),
				MPGS_TARGET_MODULE_VERSION,
				true
			);
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
			$class         = 'notice notice-error';
			$error_message = __( 'Mastercard Gateway payment methods cannot be activated without valid API credentials. Update them now via this <a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mpgs_gateway' ) . '">link</a>', 'mastercard' );
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $error_message );
		}

		$this->display_errors();
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
			if ( 'mpgs_gateway' === $this->id ) {
				static $error_added = false, $surcharge_error_added = false;
	
				if ( isset( $this->settings['hf_amount_type'] ) && 'percentage' === $this->settings['hf_amount_type'] ) {
					if ( absint( $this->settings['handling_fee_amount'] ) > 100 ) {
						if ( ! $error_added ) {
							WC_Admin_Settings::add_error( __( 'The handling fee percentage is restricted to a maximum of 100.', 'mastercard' ) );
							$error_added = true;
						}
						$this->update_option( 'handling_fee_amount', 100 );
					}
				}
	
				if ( isset( $this->settings[self::SUR_AMT_TYPE] ) && 'percentage' === $this->settings[self::SUR_AMT_TYPE] ) {
					if ( absint( $this->settings[self::SUR_AMT] ) > 99.9 ) {
						if ( ! $surcharge_error_added ) {
							WC_Admin_Settings::add_error( __( 'The surcharge fee percentage is restricted to a maximum of 99.9.', 'mastercard' ) );
							$surcharge_error_added = true;
						}
						$this->update_option( self::SUR_AMT, 99.9 );
					}
				}
				$current_version = get_option( 'mpgs_current_version', '' );

				if ( MPGS_TARGET_MODULE_VERSION !== $current_version ) { 
					if ( !empty( $this->settings['username'] ) && !empty( $this->settings['password'] ) ) {
						$repoName        = 'gateway-woocommerce-mastercard-module';
						$pluginType      = 'enterprise';
						$latestRelease   = '1';
						$default_country = get_option( 'woocommerce_default_country' );
						$country_code    = $default_country ? explode( ':', $default_country )[0] : '';
						$countries       = WC()->countries->get_countries();
						$country_name    = isset( $countries[$country_code] ) ? $countries[$country_code] : '';
						$shop_name       = get_bloginfo( 'name' );
						$shop_url        = get_home_url();
						$api_token       = self::STATUS_TOKEN;
						$service         = $this->init_service();

						$response = $service->sendCaptureRequest(
							$repoName, $pluginType, MPGS_TARGET_MODULE_VERSION, $latestRelease, $country_code,
							$country_name, $shop_name, $shop_url, $api_token, MPGS_CAPTURE_URL
						);

						if ( !empty( $response ) && isset( $response['status'] ) && $response['status'] === 'success' ) {
							update_option( 'mpgs_current_version', MPGS_TARGET_MODULE_VERSION ); 
						}
					}
				}
			}
	
			$service = $this->init_service();
			$service->paymentOptionsInquiry();
		} catch ( Exception $e ) {
			$this->add_error(
				sprintf( __( 'Error communicating with payment gateway API: "%s"', 'mastercard'), $e->getMessage() )
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

			wp_localize_script( 'woocommerce_mastercard_hosted_session', 'mpgsParams',
				array( 
					'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
					'isSurchargeEnabled' => $this->get_option( self::SUR_ENABLED ) === 'yes' ? true : false,
					'surchargeFee'       => (float) $this->get_option( self::SUR_AMT ),
					'cardType'           => strtoupper( $this->get_option( self::SUR_CARD_TYPE ) )
				)
			);
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
				if ( false !== strpos( $tag, $script ) ) {
					return str_replace( 
						' src', 
						' async data-error="errorCallback" data-beforeRedirect="befroreRedirctCallback" data-afterRedirect="afterRedirectCallback" data-complete="completeCallback" src', 
						$tag 
					);
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
	 * Process a refund for an order.
	 *
	 * @param int        $order_id The ID of the order being refunded.
	 * @param float|null $amount The amount to be refunded.
	 * @param string     $reason The reason for the refund.
	 *
	 * @return bool True if the refund was processed successfully, false otherwise.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = new WC_Order( $order_id );

		$result = $this->service->refund(
			$this->add_order_prefix( $order_id ),
			(string) time(),
			$amount,
			$order->get_currency()
		);

		$order->update_meta_data( '_mpgs_transaction_mode', 'refund' );

		if ( isset( $result['result'] ) && $result['result'] === 'ERROR' ) {
			$explanation = isset( $result['error']['explanation'] ) ? $result['error']['explanation'] : 'Unknown error';
			$order->add_order_note( 'Refund Failed: ' . $explanation );
			$order->save();
			return new WP_Error( 'refund_failed', __( 'Refund failed: ' . $explanation, 'mastercard' ) );
		}
		if ( isset( $result['transaction'] ) ) {
			$order->add_order_note(
				sprintf(
					__( 'Mastercard registered refund %1$s (ID: %2$s)', 'mastercard' ),
					wc_price( $result['transaction']['amount'] ),
					$result['transaction']['id']
				)
			);
			$order->save();
			return true;
		}
		$order->add_order_note( 'Refund Failed.' );
		return new WP_Error( 'refund_failed', __( 'Refund Failed.', 'mastercard' ) );
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
		$success_indicator = $order->get_meta( '_mpgs_success_indicator_initial' );
		
		try {

			$mpgs_order  	= $this->service->retrieveOrder( $this->add_order_prefix( $order_id ) );
		
			$txns	     	= $mpgs_order['transaction'] ?? [];
			$latest_txn  	= end( $txns );
			$auth_txn_id 	= $latest_txn['authentication']['transactionId'] ?? null;
			$result_status 	= strtoupper( $latest_txn['result'] ?? '' );
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
			$order->update_meta_data( '_type_of_payment', $type_of_payment );
			$this->process_wc_order( $order, $mpgs_order, $transaction );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit();
		}
	}

	protected function detect_payment_type ( array $mpgs_order ): string {
		
		if (!empty($mpgs_order['walletProvider'])) { 
			return strtoupper(sanitize_text_field($mpgs_order['walletProvider'])); 
		}

		$type = $mpgs_order['sourceOfFunds']['browserPayment']['type'] ?? $mpgs_order['sourceOfFunds']['type'] ?? 'OTHERS';
		$type = strtoupper( sanitize_text_field( $type ) );

		// If it's a card, use the scheme instead
		if ( $type === 'CARD' ) {
			$type = $mpgs_order['sourceOfFunds']['provided']['card']['brand'] 
					?? $mpgs_order['sourceOfFunds']['scheme'] 
					?? 'CARD';
			$type = strtoupper( sanitize_text_field( $type ) );
		}

		return $type;
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
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard' ) );
				wc_add_notice( __( 'Payment was declined.', 'mastercard' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			if ( isset( $response['3DSecure']['authenticationRedirect'] ) ) {
				$tds_auth  = $response['3DSecure']['authenticationRedirect']['customized'];
				$token_key = $this->get_token_key();
				$token_3ds = bin2hex( random_bytes( 16 ) );
				update_post_meta( $order_id, '_mastercard_3ds_token', $token_3ds );

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
							'mg_3ds_nonce'       => $token_3ds,
							'funding_method'     => $funding_method,
							$token_key           => isset( $_REQUEST[ $token_key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $token_key ] ) ) : null, // phpcs:ignore
						)
					)
				);

				set_query_var( 'order', $order );
				set_query_var( 'gateway', $this );

				load_template( dirname( __DIR__ ) . '/templates/3dsecure/form.php' );
				exit();
			}

			$this->pay( $session, $order, null, $funding_method );
		}

		if ( $process_acl_result && $mg_3ds_nonce === $mastercard_3ds_nonce ) {
			$pa_res   = isset( $_POST['PaRes'] ) ? sanitize_text_field( wp_unslash( $_POST['PaRes'] ) ) : null;
			$tds_id   = isset( $_REQUEST['3DSecureId'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['3DSecureId'] ) ) : null;
			$response = $this->service->process3dsResult( $tds_id, $pa_res );

			if ( 'PROCEED' !== $response['response']['gatewayRecommendation'] ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'mastercard' ) );
				wc_add_notice( __( 'Payment was declined.', 'mastercard' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit();
			}

			$this->pay( $session, $order, $tds_id, $funding_method );
		}

		if ( null !== $three_ds_txn_id ) {
			$this->pay( $session, $order, $three_ds_txn_id, $funding_method );
		}

		if ( ! $check_3ds && ! $process_acl_result && ! $this->threedsecure_v1 ) {
			$this->pay( $session, $order, null, $funding_method );
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
	 * @param string 	  $funding_method Used by the payer to provide the funds for the payment.
	 *
	 * @return void
	 * @throws Exception If the payment was declined.
	 */
	protected function pay( $session, $order, $tds_id = null, $funding_method ) {

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
			$surcharge     = ( isset( $this->surcharge_enabled ) && 'yes' === $this->surcharge_enabled ) ? $order_builder->getSurcharge() : array(
				'amount' => 0,
				'type'   => 'SURCHARGE'
			);

			if ( $this->capture ) {
				$mpgs_txn = $this->service->pay(
					$txn_id,
					$this->add_order_prefix( $order->get_id() ),
					$order_builder->getOrder(),
					$surcharge,
					$auth,
					$tds_id,
					$session,
					$funding_method,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping()
				);
			} else {
				$mpgs_txn = $this->service->authorize(
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
			throw new Exception( 'Token not present in response' );
		}
	
		$token = new Mastercard_Payment_Token_CC() ;
		$token->set_token( $response['token'] );
		$token->set_gateway_id( $this->id );
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

		$captured = ( isset( $order_data['status'] ) && 'CAPTURED' === strtoupper( $order_data['status'] ) );
		if ( $captured ) {
            $transaction_mode = self::TXN_MODE_PURCHASE; // Purchase mode
            $status = 'CAPTURED';
        } else {
            $transaction_mode = self::TXN_MODE_AUTH_CAPTURE; // Authorize mode
            $status = 'AUTHORIZED';
        }
		$transaction_id     = $txn_data['transaction']['id'];
		$auth_code          = isset( $txn_data['transaction']['authorizationCode'] ) ? $txn_data['transaction']['authorizationCode'] : null;
		if ( $order->get_payment_method() !== 'mpgs_gateway' ) {
			$order->set_payment_method( 'mpgs_gateway' );
			$order->set_payment_method_title( __(  self::GATEWAY_TITLE , 'mastercard' ) );
		}
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
				// Proceed if the result has a successIndicator
				if ( $result && isset( $result['successIndicator'] ) ) {
                    $order->update_meta_data( '_mpgs_success_indicator_initial', $result['successIndicator'] );
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
				$this->mgps_webhook_handler( $request );
				break;
	
			default:
				break;	
		}

		return $result;
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
	public function mgps_webhook_handler( $request ) {
		$body                = $request->get_body();
    	$headers             = $request->get_headers();
    	$secret              = $headers['x_notification_secret'][0];
    	$notification_secret = $this->get_option( 'webhook_secret' );
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
				$handling_text = !empty( $handling_text ) ? $handling_text : 'Handling Fee';
				$amount_type   = $this->get_option( 'hf_amount_type' );
				$handling_fee  = $this->get_option( 'handling_fee_amount' ) ? $this->get_option( 'handling_fee_amount' ) : 0;

				if ( self::HF_PERCENTAGE === $amount_type ) {
					$surcharge = (float)( WC()->cart->cart_contents_total ) * ( (float) $handling_fee / 100 );
				} else {
					$surcharge = $handling_fee;
				}

			    WC()->cart->add_fee( $handling_text, $surcharge, true, '' );
			}
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
				const handlingText = '<?php echo sanitize_title( !empty( $this->get_option( 'handling_text' ) ) ? $this->get_option( 'handling_text' ) : 'Handling Fee' ); ?>';
				const handlingFeeWrapper = '<div class="wc-block-components-totals-item wc-block-components-totals-fees wc-block-components-totals-fees__<?php echo sanitize_title( !empty( $this->get_option( 'handling_text' ) ) ? $this->get_option( 'handling_text' ) : 'Handling Fee' ); ?>"><span class="wc-block-components-totals-item__label"><?php echo !empty( $this->get_option( 'handling_text' ) ) ? $this->get_option( 'handling_text' ) : 'Handling Fee'; ?></span><span class="wc-block-formatted-money-amount wc-block-components-formatted-money-amount wc-block-components-totals-item__value"><?php echo wc_price( $surcharge ); ?></span><div class="wc-block-components-totals-item__description"></div></div>';

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

	/**
	 * Displays a surcharge message on the order summary or confirmation page.
	 *
	 * This function is typically used to notify users of any additional
	 * surcharge applied to their order. The surcharge amount can be retrieved
	 * from the `$order` object, which represents the current order details.
	 *
	 * @param WC_Order $order The WooCommerce order object containing order details.
	 */
	public function display_surcharge_message( $order ) {
		$message             = '';
		$surcharge_enabled   = $this->get_option( self::SUR_ENABLED );
		$surcharge_fee = (float) $this->get_option( self::SUR_AMT );

		if( 'yes' === $surcharge_enabled && $surcharge_fee > 0 ) {
			$message = sprintf(
				/* translators: 1. Surcharge message. */
				__( '<div class="wc-block-components-shipping-rates-control"><div class="wc-block-components-shipping-rates-control__package"><div class="wc-block-components-shipping-rates-control__no-results-notice wc-block-components-notice-banner is-warning"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg><div class="wc-block-components-notice-banner__content">%1$s</div></div></div></div>', 'mastercard' ),
				$this->get_surcharge_message( $order )
			);
		}

		return $message;
	}

	/**
	 * Generates and returns a surcharge message for the provided order.
	 *
	 * @param WC_Order $order The WooCommerce order object.
	 * @return string The surcharge message, typically displayed to inform
	 *                the customer about additional charges applied to their order.
	 *
	 * This method is commonly used to calculate and communicate surcharges
	 * (e.g., credit card fees, payment gateway fees) applied during checkout.
	 * Ensure proper handling of order data and formatting for customer clarity.
	 */
	public function get_surcharge_message( $order ) {
		$order_builder       = new Mastercard_CheckoutBuilder( $order );
		$amount_type         = $this->get_option( self::SUR_AMT_TYPE );
		$surcharge_fee       = $this->get_option( self::SUR_AMT ) ? $this->get_option( self::SUR_AMT ) : 0;
		$surcharge_card_type = $this->get_option( self::SUR_CARD_TYPE ) . " Card";
	
		if ( self::HF_FIXED === $amount_type ) {
			$default_msg = 'When using a {{MG_CARD_TYPE}} an additional surcharge of <b>{{MG_SUR_AMT}}</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.';
		} else {
			$default_msg = self::SUR_DEFAULT_MSG;
		}
	
		// Use saved message if set, otherwise use dynamic default
		$surcharge_message = $this->get_option( self::SUR_MSG ) ? $this->get_option( self::SUR_MSG ) : $default_msg;
	
		// Calculate surcharge
		if ( self::HF_PERCENTAGE === $amount_type ) {
			$surcharge = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / ( 100 - (float) $surcharge_fee ) );
		} else {
			$surcharge = $surcharge_fee;
		}
	
		$surcharge           = $order_builder->formattedPrice( $surcharge );
		$total_total         = (float) $order->get_total() + (float) $surcharge;
		$surcharge_fee_label = ( self::HF_PERCENTAGE === $amount_type ) ? $surcharge_fee . '%' : '';
	
		return str_replace(
			array( '{{MG_SUR_AMT}}', '{{MG_SUR_PCT}}', '{{MG_CARD_TYPE}}', '{{MG_TOTAL_AMT}}' ),
			array( wc_price( $surcharge ), $surcharge_fee_label, $surcharge_card_type, wc_price( $total_total ) ),
			$surcharge_message
		);
	}
	

	/**
	 * Calculates and returns the surcharge amount for a transaction.
	 *
	 * This method determines the surcharge based on predefined rules,
	 * such as a fixed percentage or flat fee. It is typically used
	 * to add additional costs to a payment transaction.
	 *
	 * @return float The calculated surcharge amount.
	 */
	public function get_surcharge_amount() {
		$order_id          = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : null;
		$order             = wc_get_order( $order_id );
		$order_builder     = new Mastercard_CheckoutBuilder( $order );
		$surcharge_enabled = $this->get_option( self::SUR_ENABLED );
		$card_type 		   = strtoupper( $this->get_option( self::SUR_CARD_TYPE ) );
		$funding_method    = isset( $_POST['funding_method'] ) ? sanitize_text_field( wp_unslash( $_POST['funding_method'] ) ) : null;
		$token             = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : null;
		$source_type       = isset( $_POST['source_type'] ) ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : null;

		if ( ! $order_id || $card_type !== $funding_method ) {
			if ( empty( $token ) && empty( $source_type ) ) {
				$return = array(
					'message' => 'Unfortunately, we couldn’t update the total order at this time.',
					'code'    => 400
				);
				wp_send_json( $return );
			}
		}
		
		$order = wc_get_order( $order_id );

		if( $order && 'yes' === $surcharge_enabled ) {

			$amount_type    = $this->get_option( self::SUR_AMT_TYPE );
			$surcharge_fee  = $this->get_option( self::SUR_AMT ) ? $this->get_option( self::SUR_AMT ) : 0;
			$surcharge_text = $this->get_option( self::SUR_TEXT );
			$surcharge_text = !empty( $surcharge_text ) ? $surcharge_text : 'Surcharge';

			if ( self::HF_PERCENTAGE === $amount_type ) {
				$surcharge = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / ( 100 - (float) $surcharge_fee) );
			} else {
				$surcharge = (float) $surcharge_fee;
			}

			$surcharge = $order_builder->formattedPrice( $surcharge );
	        $fee       = new WC_Order_Item_Fee();
		    $fee->set_name( $surcharge_text );
		    $fee->set_amount( $surcharge );
		    $fee->set_total( $surcharge );

		    // Add the fee to the order
		    $order->add_item( $fee );

		    // Save the order
		    $order->calculate_totals( false );
		    $order->update_meta_data( '_mpgs_surcharge_fee', $surcharge );
		    $order->save();

		    $return = array(
			    'code'        => 200,
			    'order_total' => wc_price( $order->get_total() )
			);

			wp_send_json( $return );
		}
	}

	/**
	 * Displays a surcharge confirmation box in the order details page.
	 *
	 * This method outputs a confirmation box related to any surcharges applied 
	 * to the order. It is typically used in the admin area or order review screens 
	 * to inform the user/admin of additional charges and potentially allow 
	 * confirmation or review of these charges.
	 *
	 * @param WC_Order $order The WooCommerce order object containing the order details.
	 * @return string The surcharge confirmation html, typically displayed to inform
	 *                the customer about additional charges applied to their order.
	 */
	public function display_surcharge_confirmation_box( $order ) {
		$surcharge_text      = $this->get_option( self::SUR_TEXT );
		$surcharge_text      = !empty( $surcharge_text ) ? $surcharge_text : 'Surcharge';
		$amount_type         = $this->get_option( self::SUR_AMT_TYPE );
		$surcharge_fee       = $this->get_option( self::SUR_AMT ) ? $this->get_option( self::SUR_AMT ) : 0;
		$surcharge_card_type = $this->get_option( self::SUR_CARD_TYPE ) . " Card";

		if ( self::HF_FIXED === $amount_type ) {
			$default_msg = 'When using a {{MG_CARD_TYPE}} an additional surcharge of <b>{{MG_SUR_AMT}}</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.';
		} else {
			$default_msg = self::SUR_DEFAULT_MSG;
		}

		$surcharge_message = $this->get_option( self::SUR_MSG ) ? $this->get_option( self::SUR_MSG ) : $default_msg;

		if ( self::HF_PERCENTAGE === $amount_type ) {
			$surcharge = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / (100 - (float) $surcharge_fee) );
		} else {
			$surcharge = $surcharge_fee;
		}

		$total_total         = (float) $order->get_total() + (float) $surcharge;
		$surcharge_fee_label = ( self::HF_PERCENTAGE === $amount_type ) ? $surcharge_fee . '%' : '';
		$message             =  str_replace(
			array( '{{MG_SUR_AMT}}', '{{MG_SUR_PCT}}', '{{MG_CARD_TYPE}}', '{{MG_TOTAL_AMT}}' ),
			array( wc_price( $surcharge ), $surcharge_fee_label, $surcharge_card_type, wc_price( $total_total ) ),
			$surcharge_message
		);

		$order_html = sprintf(
			/* translators: 1. Order total text, 2. Order total amount, 3. Surcharge text, 4. Surcharge amount, 5. Grand total text, 6. Grant total. */
			__( '<ul><li><label>%1$s:</label> %2$s</li><li><label>%3$s:</label> %4$s</li><li><label>%5$s:</label> %6$s</li></ul>', 'mastercard' ),
			apply_filters( 'mastercard_order_pay_order_total_text', __( 'Order Total', 'mastercard' ) ),
			wc_price( $order->get_total() ),
			$surcharge_text,
			wc_price( $surcharge ),
			apply_filters( 'mastercard_order_pay_grand_total_text', __( 'Grand Total', 'mastercard' ) ),
			wc_price( $total_total )
		);	

		return sprintf(
			/* translators: 1. Surcharge message, 2. Order total text, 3. Order total amount, 4. Surcharge text, 5. Surcharge amount, 6. Grand total text, 7. Grant total, 8. Confirm button text, 9. Cancel button text. */
			__( '<p>%1$s</p>%2$s<div class="mpgs_button_wrapper"><button type="button"class="wp-element-button wp-element-confirm-button">%3$s</button><a type="button"class="wp-element-button wp-element-cancel-button" href="%4$s">%5$s</a></div>', 'mastercard' ),
			$message,
			$order_html,
			apply_filters( 'mastercard_order_pay_confirm_button_text', __( 'Confirm', 'mastercard' ) ),
			esc_url( wc_get_checkout_url() ),
			apply_filters( 'mastercard_order_pay_cancel_button_text', __( 'Cancel', 'mastercard' ) )
		);
	}

	/**
	 * Customize the HTML output for a saved Mastercard payment method.
	 *
	 * This function modifies how each saved Mastercard payment method is displayed
	 * on the WooCommerce checkout page. It builds a radio input and label for each
	 * saved token, allowing users to select one of their stored payment methods.
	 *
	 * @param string                        $html    The original HTML output.
	 * @param WC_Payment_Token              $token   The saved payment method token.
	 * @param WC_Payment_Gateway            $gateway The payment gateway instance.
	 *
	 * @return string Modified HTML output for the saved payment method option.
	 */
	public function mastercard_saved_payment_method_option_html( $html, $token, $gateway ) {
		$card_type = $token->get_meta( 'funding_method' ); 
		$html = sprintf(
			'<li class="woocommerce-SavedPaymentMethods-token">
				<input
					id="wc-%1$s-payment-token-%2$s"
					type="radio"
					name="wc-%1$s-payment-token"
					value="%2$s"
					style="width:auto;"
					class="woocommerce-SavedPaymentMethods-tokenInput"
					data-card="%5$s"
					%4$s />
				<label for="wc-%1$s-payment-token-%2$s">%3$s</label>
			</li>',
			esc_attr( $this->id ),
			esc_attr( $token->get_id() ),
			esc_html( $token->get_display_name() ),
			checked( $token->is_default(), true, false ),
			esc_attr( $card_type ) 
		);
	
		return $html;
	}	
}
