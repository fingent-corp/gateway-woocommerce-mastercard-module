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

		$gateway_url = preg_replace( '#^https?://#', '', rtrim( $gateway_url, '/' ) );
	
		return $gateway_url;
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
}
