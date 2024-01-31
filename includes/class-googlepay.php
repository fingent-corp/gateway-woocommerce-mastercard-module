<?php
/**
 * Copyright (c) 2019-2023 Mastercard
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
 * @version  GIT: @1.4.2@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

require_once dirname( __DIR__ ) . '/includes/class-gateway-service.php';

/**
 * Main class of the Mastercard Payment Gateway Module
 *
 * @package  Mastercard
 * @version  Release: @1.4.2@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */
class Mastercard_Gateway_GooglePay extends WC_Payment_Gateway {

	const ID = 'mpgs_googlepay';

	const MPGS_GPAY_API_VERSION = '2';
	const MPGS_GATEWAY 			= 'mpgs';

	const API_EU     = 'eu-gateway.mastercard.com';
	const API_AS     = 'ap-gateway.mastercard.com';
	const API_NA     = 'na-gateway.mastercard.com';
	const API_CUSTOM = 'custom';

	/**
	 * Order prefix
	 *
	 * @var string
	 */
	protected $order_prefix;

	/**
	 * Sandbox
	 *
	 * @var bool
	 */
	protected $sandbox;

	/**
	 * Username
	 *
	 * @var string
	 */
	protected $username;

	/**
	 * Password
	 *
	 * @var string
	 */
	protected $password;

	/**
	 * Gateway URL
	 *
	 * @var string
	 */
	protected $gateway_url;

	/**
	 * Mastercard_Gateway constructor.
	 *
	 * @throws Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->title              = __( 'Google Pay by Mastercard', 'mastercard' );
		$this->method_title       = __( 'Google Pay by Mastercard', 'mastercard' );
		$this->has_fields         = true;
		$this->method_description = __(
			'Accept payments on your WooCommerce store using Google Pay via Direct Payment.',
			'mastercard'
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->order_prefix    = $this->get_option( 'order_prefix' );
		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->enabled         = $this->get_option( 'enabled', false );
		$this->supports        = array(
			'PAN_ONLY',
		);
		$this->supported_cards = array(
			'AMEX', 
			'DISCOVER', 
			'INTERAC', 
			'JCB', 
			'MASTERCARD', 
			'VISA',
		);

		$this->service = $this->init_service(); 

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options'
			)
		);

		add_action( 'wp_enqueue_scripts', array( $this, 'payment_gateway_scripts' ), 10 );
		add_filter( 'script_loader_tag', array( $this, 'add_js_extra_attribute' ), 10 );
		// add_action( 'woocommerce_order_action_mpgs_capture_order', array( $this, 'process_capture' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		// add_action( 'woocommerce_api_mastercard_gateway', array( $this, 'return_handler' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
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
		$this->environment = 'no' === $this->sandbox ? 'PRODUCTION' : 'TEST';
		$this->username = 'no' === $this->sandbox ? $this->get_option( 'username' ) : $this->get_option( 'sandbox_username' );
		$this->password = 'no' === $this->sandbox ? $this->get_option( 'password' ) : $this->get_option( 'sandbox_password' );
		$this->merchant_name = 'no' === $this->sandbox ? $this->get_option( 'merchant_name' ) : $this->get_option( 'sandbox_merchant_name' );
		$this->merchant_id = 'no' === $this->sandbox ? $this->get_option( 'merchant_id' ) : $this->get_option( 'sandbox_merchant_id' );

		$logging_level = $this->get_debug_logging_enabled()
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
	 * This function is responsible for including the necessary payment gateway scripts.
	 *
	 * @return void
	 */
	public function payment_gateway_scripts() {
		global $wp;
		$order_id = get_query_var( 'order-pay' );
		$order    = new WC_Order( $order_id );
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		wp_enqueue_script(
			'woocommerce_mastercard_google_pay',
			esc_attr( $this->get_google_pay_js() ),
			array(),
			MPGS_MODULE_VERSION,
			false
		);
	}

	/**
	 * This function is responsible for including the necessary payment gateway scripts.
	 *
	 * @param string $tag Script link
	 *
	 * @return string $tag Script link
	 */
	public function add_js_extra_attribute( $tag ) {
		$scripts = array( $this->get_google_pay_js() );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				if ( true == strpos( $tag, $script ) ) {
					return str_replace( ' src', ' async onload="onGooglePayLoaded()" src', $tag );	
				}
			}
		}

		return $tag;
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool True if debug logging is enabled, false otherwise.
	 */
	protected function get_debug_logging_enabled() {
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
	protected function get_gateway_url() {
		$gateway_url = $this->get_option( 'gateway_url', self::API_EU );
		if ( self::API_CUSTOM === $gateway_url ) {
			$gateway_url = $this->get_option( 'custom_gateway_url' );
		}

		return $gateway_url;
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
	 * Get the Google Pay Merchant name.
	 *
	 * @return string Merchant name.
	 */
	public function get_gpay_merchant_name() {
		return $this->merchant_name;
	}

	/**
	 * Get the Google Pay Merchant ID.
	 *
	 * @return string Merchant ID.
	 */
	public function get_gpay_merchant_id() {
		return $this->merchant_id;
	}

	/**
	 * Get the environment type.
	 *
	 * @return string Environment type.
	 */
	public function get_environment() {
		return $this->environment;
	}

	/**
	 * This function processes the admin options.
	 *
	 * @return array $saved Admin Options.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		try {
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

		load_template( dirname( __DIR__ ) . '/templates/checkout/googlepay.php' );
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
			echo '<div class="error"><p>' . esc_html__( 'API credentials are not valid. To activate the payment methods please your details to the forms below.' ) . '</p></div>';
		}

		$this->display_errors();
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
	 * Get the API version.
	 *
	 * This function returns the current version of the API.
	 *
	 * @return string The API version.
	 */
	public function get_api_version() {
		return self::MPGS_GPAY_API_VERSION;
	}

	/**
	 * Get the Gateway ID.
	 *
	 * This function returns the current version of the API.
	 *
	 * @return string The API version.
	 */
	public function get_gateway_id() {
		return self::MPGS_GATEWAY;
	}
		
	/**
	 * Get the Google Pay JavaScript code.
	 *
	 * @return string The JavaScript code for the hosted checkout.
	 */
	public function get_google_pay_js() {
		return 'https://pay.google.com/gp/p/js/pay.js';
	}

	/**
	 * Get the hosted checkout JavaScript code.
	 *
	 * @return string The JavaScript code for the hosted checkout.
	 */
	public function get_card_auth_methods() {
		return wp_json_encode( $this->supports );
	}

	/**
	 * Get the hosted checkout JavaScript code.
	 *
	 * @return string The JavaScript code for the hosted checkout.
	 */
	public function get_card_networks() {
		return wp_json_encode( $this->supported_cards );
	}

	/**
	 * Initialize form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'heading'            => array(
				'title'       => null,
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1. MPGS module vesion, 2. MPGS API version. */
					__( 'Plugin version: %1$s<br />API version: %2$s', 'mastercard' ),
					MPGS_MODULE_VERSION,
					Mastercard_Gateway::MPGS_API_VERSION_NUM
				)
			),
			'enabled'            => array(
				'title'       => __( 'Enable/Disable', 'mastercard' ),
				'label'       => __( 'Enable', 'mastercard' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'              => array(
				'title'       => __( 'Title', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'mastercard' ),
				'default'     => __( 'Google Pay by Mastercard', 'mastercard' ),
			),
			'description'        => array(
				'title'       => __( 'Description', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'The description displayed when this payment method is selected.', 'mastercard' ),
				'default'     => 'Google Pay via Direct Payment.',
			),
			'gateway_url'        => array(
				'title'   => __( 'Gateway', 'mastercard' ),
				'type'    => 'select',
				'options' => array(
					self::API_AS     => __( 'Asia Pacific', 'mastercard' ),
					self::API_EU     => __( 'Europe', 'mastercard' ),
					self::API_NA     => __( 'North America', 'mastercard' ),
					self::API_CUSTOM => __( 'Custom URL', 'mastercard' ),
				),
				'default' => self::API_EU
			),
			'custom_gateway_url' => array(
				'title'       => __( 'Custom Gateway Host', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'Enter only the hostname without https prefix. For example na.gateway.mastercard.com.', 'mastercard' ),
			),
			'debug'              => array(
				'title'       => __( 'Debug Logging', 'mastercard' ),
				'label'       => __( 'Enable Debug Logging', 'mastercard' ),
				'type'        => 'checkbox',
				'description' => __( 'Logs all communication with Mastercard gateway to file ./wp-content/mastercard.log. Debug logging only works in Sandbox mode.', 'mastercard' ),
				'default'     => 'no',
			),
			'order_prefix'       => array(
				'title'       => __( 'Order ID prefix', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'Should be specified in case multiple integrations use the same Merchant ID', 'mastercard' ),
				'default'     => '',
			),
			'api_details'        => array(
				'title'       => __( 'API credentials', 'mastercard' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: Gateway API Credentials */
					__( 'Enter your API credentials to process payments via Google Pay. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.', 'mastercard' ),
					'https://test-gateway.mastercard.com/api/documentation/integrationGuidelines/supportedFeatures/pickSecurityModel/secureYourIntegration.html?locale=en_US'
				),
			),
			'sandbox'            => array(
				'title'       => __( 'Test Sandbox', 'mastercard' ),
				'label'       => __( 'Enable test sandbox mode', 'mastercard' ),
				'type'        => 'checkbox',
				'description' => __( 'Place the payment gateway in test mode using test API credentials (real payments will not be taken).', 'mastercard' ),
				'default'     => 'yes',
			),
			'sandbox_username'   => array(
				'title'       => __( 'Test Merchant ID', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'This is your test merchant profile ID prefixed with TEST', 'mastercard' ),
				'default'     => '',
			),
			'sandbox_password'   => array(
				'title'   => __( 'Test API Password', 'mastercard' ),
				'type'    => 'password',
				'description' => __( 'This is your test API password', 'mastercard' ),
				'default'     => '',
			),
			'username'           => array(
				'title'   => __( 'Merchant ID', 'mastercard' ),
				'type'    => 'text',
				'description' => __( 'This is your merchant profile ID', 'mastercard' ),
				'default'     => '',
			),
			'password'           => array(
				'title'   => __( 'API Password', 'mastercard' ),
				'type'    => 'password',
				'description' => __( 'This is your API password', 'mastercard' ),
				'default'     => '',
			),
			'gpay_sandbox_merchant_name'   => array(
				'title'       => __( 'Test Google Merchant Name', 'mastercard' ),
				'type'        => 'text',
				'description' => __( 'This is your sandbox Google Pay merchant name.', 'mastercard' ),
				'default'     => '',
			),
			'gpay_sandbox_merchant_id'   => array(
				'title'   => __( 'Test Google Merchant ID', 'mastercard' ),
				'type'    => 'text',
				'description' => __( 'This is your sandbox Google Pay merchant id.', 'mastercard' ),
				'default'     => '',
			),
			'gpay_merchant_name'           => array(
				'title'   => __( 'Googlev Merchant Name', 'mastercard' ),
				'type'    => 'text',
				'description' => __( 'This is your Google Pay merchant name.', 'mastercard' ),
				'default'     => '',
			),
			'gpay_merchant_id'           => array(
				'title'   => __( 'Merchant ID', 'mastercard' ),
				'type'    => 'text',
				'description' => __( 'This is your Google Pay merchant id.', 'mastercard' ),
				'default'     => '',
			),
		);
	}
}