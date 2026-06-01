<?php
namespace Fingent\Mastercard\Model;

use WC_Order;
use WC_Payment_Gateway;
use Fingent\Mastercard\View\Settings;
use Fingent\Mastercard\View\CheckoutView;
use Fingent\Mastercard\Controller\AdminController;
use Fingent\Mastercard\Controller\PaymentController;
use Fingent\Mastercard\Controller\UtilityController;

class MastercardGateway extends WC_Payment_Gateway {
	/**
	 * Singleton instance.
	 *
	 * @var MastercardGateway|null
	 */
	private static ?MastercardGateway $instance = null;

	/**
	 * Order prefix
	 *
	 * @var string
	 */
	public $order_prefix = null;

	/**
	 * Gateway enabled or not.
	 *
	 * @var bool
	 */
	public $enabled = null;

	/**
	 * Sandbox
	 *
	 * @var bool
	 */
	public $sandbox = null;

	/**
	 * Username
	 *
	 * @var string
	 */
	public $username = null;

	/**
	 * Password
	 *
	 * @var string
	 */
	public $password = null;

	/**
	 * Gateway URL
	 *
	 * @var string
	 */
	protected $gateway_url = null;

	/**
	 * Hosted checkout Interaction
	 *
	 * @var string
	 */
	public $hc_interaction = null;

	/**
	 * Hosted checkout type
	 *
	 * @var string
	 *
	 * @todo Remove after removal of Legacy Hosted Checkout
	 */
	public $hc_type = null;

	/**
	 * Capture method
	 *
	 * @var bool
	 */
	public $capture = null;

	/**
	 * Method
	 *
	 * @var string
	 */
	public $method = null;

	/**
	 * 3D Secure Version 1
	 *
	 * @var bool
	 */
	public $threedsecure_v1 = null;

	/**
	 * 3D Secure Version 2 (with fallback to 3DS1)
	 *
	 * @var bool
	 */
	public $threedsecure_v2 = null;

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
	public $saved_cards = null;

	/**
	 * MastercardGateway Instance.
	 *
	 * @return MastercardGateway instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MastercardGateway constructor.
	 *
	 * @throws Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() { 
		$this->id                 = MG_ENTERPRISE_ID;
		$this->title              = MG_ENTERPRISE_GATEWAY_TITLE;
		$this->method_title       = MG_ENTERPRISE_GATEWAY_TITLE;
		$this->has_fields         = true;
		$this->method_description = __(
			'Accept payments on your WooCommerce store using Mastercard Gateway.',
			MG_ENTERPRISE_TEXTDOMAIN
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->order_prefix      = $this->get_option( 'order_prefix' );
		$this->title             = $this->get_option( 'title' );
		$this->description       = $this->get_option( 'description' );
		$this->enabled           = $this->get_option( 'enabled', false );
		$this->hc_interaction    = $this->get_option( 'hc_interaction', HC_TYPE_EMBEDDED );
		$this->capture           = $this->get_option( 'txn_mode', TXN_MODE_PURCHASE ) === TXN_MODE_PURCHASE;
		$this->threedsecure_v1   = $this->get_option( 'threedsecure', THREED_DISABLED ) === THREED_V1;
		$this->threedsecure_v2   = $this->get_option( 'threedsecure', THREED_DISABLED ) === THREED_V2;
		$this->method            = $this->get_option( 'method', HOSTED_CHECKOUT );
		$this->saved_cards       = $this->get_option( 'saved_cards', 'yes' ) === 'yes';
		$this->supports          = array(
			'products',
			'refunds',
			'tokenization',
		);
		$this->hf_enabled        = $this->get_option( 'hf_enabled', false );
		$this->send_line_items   = $this->get_option( 'send_line_items', false );
		$this->mif_enabled       = $this->get_option( 'mif_enabled', false );
		$this->surcharge_enabled = $this->get_option( SUR_ENABLED, false );
		$this->sandbox           = $this->get_option( 'sandbox', false );
		$this->username          = 'no' === $this->sandbox ? $this->get_option( 'username' ) : $this->get_option( 'sandbox_username' );
		$this->password          = 'no' === $this->sandbox ? $this->get_option( 'password' ) : $this->get_option( 'sandbox_password' );
		$this->icon              = esc_url( UtilityController::plugin_url() ) . '/assets/images/mastercard.gif';	

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );	
		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id,   array( $this, 'sanitize_gateway_settings' ) );		
	}

	/**
	 * Initialize form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = Settings::form_fields();
	}

	/**
	 * This function processes the admin options.
	 *
	 * @return array $saved Admin Options.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options(); 
		AdminController::get_instance()->check_payment_options_inquiry( $this->settings );
		
		return $saved;
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
	 * Check if debug logging is enabled.
	 *
	 * @return bool True if debug logging is enabled, false otherwise.
	 */
	public function is_debug_logging_enabled() {
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
		$gateway_url = $this->get_option( 'custom_gateway_url' );
	
		if ( empty( $gateway_url ) ) {
			$gateway_url = $this->get_option( 'gateway_url', API_EU );
		}
	
		return $this->format_gateway_url($gateway_url);
	}

	/**
	 * Sanitize and normalize a gateway URL by:
	 * - Trimming whitespace
	 * - Removing protocol (http/https)
	 * - Removing leading/trailing slashes
	 *
	 * @param string $url Raw URL input.
	 * @return string Cleaned URL.
	 */
	private function format_gateway_url($url) {
		$url = trim($url);
		$url = str_replace('\\', '/', $url);
		if (preg_match('#^[a-z0-9.-]+$#i', $url)) {
			return $url;
		}
		$url = preg_replace('#^[^a-z0-9.-]+#i', '', $url);
		$url = preg_replace('#^[a-z]+[;:/]+#i', '', $url);
		if (strpos($url, '/') !== false) {
			$parts = explode('/', $url);
			$url = $parts[0];
		}
		$url = rtrim($url, ':');

		return $url;
	}

	/**
	 * This function demonstrates the usage of the Embedded Form.
	 *
	 * @return boolean
	 */
	public function use_embedded() {
		return HC_TYPE_EMBEDDED === $this->hc_interaction;
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
		return (int) MG_ENTERPRISE_API_VERSION_NUM;
	}

	/**
	 * Get the API version.
	 *
	 * This function returns the current version of the API.
	 *
	 * @return string The API version.
	 */
	public function get_api_version() {
		return MG_ENTERPRISE_API_VERSION;
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
		$order->update_status( 'pending', __( 'Pending payment', MG_ENTERPRISE_TEXTDOMAIN ) );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
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
		AdminController::get_instance()->process_refund( $order_id, $amount, $reason );
		
		return true;
	}

	/**
	 * Sanitize all gateway settings before saving them in the DB.
	 * 
	 * - Specifically checks if a custom gateway URL is provided,
	 *   and passes it through `sanitize_gateway_url()` for normalization.
	 * - Ensures consistent formatting so later requests do not fail due to malformed URLs.
	 *
	 * @param array $settings The gateway settings being saved.
	 * @return array Sanitized settings.
	 */
	public function sanitize_gateway_settings( $settings ) {
		if ( ! empty( $settings['custom_gateway_url'] ) ) {
			$settings['custom_gateway_url'] = $this->sanitize_gateway_url( $settings['custom_gateway_url'] );
		}
		return $settings;
	}

	/**
	 * Sanitize and normalize a gateway URL.
	 *
	 * Steps performed:
	 * 1. Trim spaces around the URL.
	 * 2. Replace backslashes "\" with forward slashes "/".
	 * 3. Ensure URL starts with "https://". If "http://" is found, replace with "https://".
	 * 4. Remove extra slashes after "https://".
	 * 5. Always ensure the URL ends with a single trailing slash.
	 *
	 * This guarantees that no matter how the merchant enters the URL,
	 * the final saved format will be consistent and valid.
	 *
	 * Example:
	 *   Input:  " https:////test.gateway.spring.citi.com "
	 *   Output: "https://test.gateway.spring.citi.com/"
	 *
	 * @param string $url The raw URL input from the merchant.
	 * @return string Normalized and safe URL.
	 */
	
	public function sanitize_gateway_url( $url ) {
		$url = trim( $url );
		$url = str_replace( '\\', '/', $url );
		$url = preg_replace( '#^[a-z]+[:;/]+#i', '', $url );
		$url = 'https://' . ltrim( $url, '/');
		$url = preg_replace( '#^http://#i', 'https://', $url );
		$parts = @parse_url( $url );
		$host = '';
		if ( ! empty( $parts['host'] ) ) {
			$host = $parts['host'];
		} else {
			$path = isset($parts['path']) ? $parts['path'] : '';
			if ( $path ) {
				$segments = explode('/', $path);
				$host = $segments[0];
			}
		}
		$port = ! empty( $parts['port'] ) ? ':' . $parts['port'] : '';
		$url = 'https://' . strtolower( $host ) . $port . '/';
		return $url;
	}

}
