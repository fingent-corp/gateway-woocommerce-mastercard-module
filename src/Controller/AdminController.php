<?php
namespace Fingent\Mastercard\Controller;

use WC_Order;
use WP_Error;
use WC_Admin_Settings;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\View\CheckoutView;
use Fingent\Mastercard\Controller\GatewayController;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Logger\ApiErrorPlugin;
use Fingent\Mastercard\Logger\ApiLoggerPlugin;
use Fingent\Mastercard\Logger\GatewayResponseException;
use Fingent\Mastercard\Emails\PaymentRequestEmail;
use Fingent\Mastercard\Emails\PaymentRevokedEmail;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AdminController {
	const META_ORDER_CAPTURED   = '_mpgs_order_captured';
	const META_TRANSACTION_MODE = '_mpgs_transaction_mode';

	/**
	 * Singleton instance.
	 *
	 * @var AdminController|null
	 */
	private static ?AdminController $instance = null;

	/**
	 * AdminController
	 *
	 * @var AdminController
	 */
	protected $gateway;

	/**
	 * UtilityController.
	 *
	 * @var bool
	 */
	public $utility;

	/**
	 * Whether handling fee percentage exceeded the maximum.
	 *
	 * @var bool
	 */
	private $handling_exceeded = false;

	/**
	 * Whether surcharge fee percentage exceeded the maximum.
	 *
	 * @var bool
	 */
	private $surcharge_exceeded = false;

	/**
	 * Whether a fee validation message has already been added this request.
	 *
	 * @var bool
	 */
	private static $fee_error_added = false;

	/**
	 * Gateway service instance.
	 *
	 * @var GatewayController
	 */
	protected $service;

	/**
	 * AdminController Instance.
	 *
	 * @return AdminController instance.
	 */
	public static function get_instance(): AdminController {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * AdminController constructor.
	 */
	public function __construct() {
		$this->gateway = MastercardGateway::get_instance();
		$this->utility = UtilityController::get_instance();

		$this->init_hooks();
	}

	/**
	 * Initializes all WordPress and WooCommerce action/filter hooks used by the plugin.
	 *
	 * Hooks included:
	 * - admin_init: Calls the 'stop' method during the admin initialization phase.
	 * - plugins_loaded: Calls 'admin_init' when all plugins are loaded.
	 * - admin_notices: Displays custom admin notices.
	 * - admin_enqueue_scripts: Enqueues admin-side JavaScript and CSS files.
	 * - plugin_row_meta: Adds custom metadata (e.g., documentation/support links) in the plugin list row.
	 * - plugin_action_links_{plugin_basename}: Adds custom links (e.g., settings) in the plugin row actions.
	 * - woocommerce_admin_order_should_render_refunds: Controls whether refunds should be rendered in order admin screen.
	 * - manage_woocommerce_page_wc-orders_columns: Adds custom columns to the WooCommerce Orders list table.
	 * - manage_woocommerce_page_wc-orders_custom_column: Renders data for the custom columns in WooCommerce Orders list.
	 * - woocommerce_order_action_mpgs_capture_payment: Adds a custom order action to capture authorized payments.
	 * - woocommerce_order_action_mpgs_void_payment: Adds a custom order action to void authorized payments.
	 */	
	private function init_hooks() {
		add_action( 'admin_init', array( $this, 'stop' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 99 );
		add_action( 'add_meta_boxes', array( $this, 'unpaid_order_payment_link_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_action( 'admin_footer', array( $this, 'insert_custom_admin_footer_html' ) );
		add_filter( 'woocommerce_email_classes', array( $this, 'request_pay_by_link_email' ),20 );
		add_filter( 'woocommerce_email_classes', array( $this, 'register_payment_revoked_email' ), 20 );
		add_filter( 'plugin_action_links_' . MG_ENTERPRISE_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_filter( 'woocommerce_admin_order_should_render_refunds', array( $this, 'admin_order_should_render_refunds' ), 10, 3 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'transaction_mode_columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'transaction_mode_column_data' ), 10, 2 );
		add_action( 'woocommerce_order_action_mpgs_capture_payment', array( $this, 'process_capture' ) );
		add_action( 'woocommerce_order_action_mpgs_void_payment', array( $this, 'void_authorized_order' ) );
		add_action( 'wp_ajax_get_preview_config', array( $this, 'get_preview_config' ) );
		add_action( 'wp_ajax_save_preview_config', array( $this, 'save_preview_config' ) );
		add_action( 'wp_ajax_mpgs_generate_payment_url', array( $this, 'process_generate_payment_url' ) );
		add_action( 'wp_ajax_mpgs_revoke_payment_link', array( $this, 'process_revoke_payment_link' ) );
		add_action( 'wp_ajax_mpgs_resend_payment_link_email', array( $this, 'process_resend_payment_link_email' ) );
		add_filter( 'wc_order_is_editable', array( $this, 'maybe_lock_order_editing' ), 10, 2 );
		add_action( 'woocommerce_before_save_order_items', array( $this, 'auto_add_shipping_to_manual_order' ), 10, 2 );
		add_action( 'woocommerce_before_save_order_items', array( $this, 'add_handling_fee_to_admin_order' ), 20, 2 );
		add_action( 'woocommerce_new_order', array( $this, 'maybe_add_charges_on_order_create' ), 20, 1 );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'maybe_add_charges_after_order_save' ), 20, 2 );
		add_action( 'woocommerce_order_action_after_payment', array( $this, 'maybe_add_fees_after_payment' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'maybe_revoke_pay_by_link_on_cancel' ), 10, 2 );
		add_action( 'woocommerce_before_trash_order', array( $this, 'maybe_revoke_pay_by_link_on_trash_or_delete' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'maybe_revoke_pay_by_link_on_trash_or_delete' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'maybe_revoke_pay_by_link_on_trash_or_delete' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'maybe_revoke_pay_by_link_on_trash_or_delete' ), 10, 1 );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'maybe_revoke_pay_by_link_on_payment_method_change' ), 10, 1 );
		add_action( 'wp_ajax_regenerate_payment', array( $this, 'regenerate_payment_callback' ));
	}

	/**
	 * Hook into actions and filters.
	 *
	 * @return void
	 */
	public function admin_init(): void { 
		$this->service = GatewayController::get_instance();
		add_filter( 'woocommerce_order_actions', array( $this, 'filter_order_actions' ) );
	}

	/**
	 * Filters the available order actions for WooCommerce admin.
	 *
	 * Adds custom payment actions ("Capture Authorized Amount" and "Void") 
	 * for orders paid using the MG Enterprise payment method, under specific conditions:
	 * - The order must exist.
	 * - The payment method must match MG_ENTERPRISE_ID.
	 * - The order must not have already been captured (checked via self::META_ORDER_CAPTURED meta).
	 * - The order status must be 'processing'.
	 *
	 * @return array $actions The modified list of order actions.
	 */
	public function filter_order_actions( $actions ): array { 
		$order_id = $this->utility->get_order_id();

		if ( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order && $order->get_payment_method() === MG_ENTERPRISE_ID &&
				$order->get_meta( self::META_TRANSACTION_MODE ) === 'authorize' &&
				$order->get_status() === 'processing' ) {

				$actions['mpgs_capture_payment'] = __( 'Capture Authorized Amount', MG_ENTERPRISE_TEXTDOMAIN );
				$actions['mpgs_void_payment']    = __( 'Void', MG_ENTERPRISE_TEXTDOMAIN );
			}
		}

		return $actions;
	}

	/**
	 * Included the plugin's helper links.
	 *
	 * @param array $links Plugin action links.
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ): array {
		array_unshift( $links, '<a href="' . MG_ENTERPRISE_SUPPORT_URL . '">' . __( 'Support', MG_ENTERPRISE_TEXTDOMAIN ) . '</a>' );
		array_unshift( $links, '<a href="' . MG_ENTERPRISE_WIKI_URL . '">' . __( 'Docs', MG_ENTERPRISE_TEXTDOMAIN ) . '</a>' );
		array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . MG_ENTERPRISE_ID ) . '">' .
				__( 'Settings', MG_ENTERPRISE_TEXTDOMAIN ) . '</a>' );

		return $links;
	}

	/**
	 * Show row meta on the plugin screen.
	 *
	 * @param mixed $links Plugin Row Meta.
	 * @param mixed $file  Plugin Base file.
	 *
	 * @return array
	 */
	public static function plugin_row_meta( $links, $file ): array {
		if ( MG_ENTERPRISE_PLUGIN_BASENAME !== $file ) {
			return $links;
		}

		/**
		 * The MPGS documentation URL.
		 *
		 * @since 1.4.0
		 */
		$docs_url = apply_filters( 'mastercard_docs_url', MG_ENTERPRISE_WIKI_URL );

		/**
		 * The Mastercard Support URL.
		 *
		 * @since 1.4.0
		 */
		$support_url = apply_filters( 'mastercard_support_url', MG_ENTERPRISE_SUPPORT_URL );

		$row_meta = array(
			'docs'    => '<a href="' . esc_url( $docs_url ) . '" aria-label="' .
							esc_attr__( 'View mastercard documentation', MG_ENTERPRISE_TEXTDOMAIN ) . '">' .
								esc_html__( 'Docs', MG_ENTERPRISE_TEXTDOMAIN ) .
						'</a>',
			'support' => '<a href="' . esc_url( $support_url ) . '" aria-label="' .
							esc_attr__( 'Visit mastercard support', MG_ENTERPRISE_TEXTDOMAIN ) . '">' .
								esc_html__( 'Support', MG_ENTERPRISE_TEXTDOMAIN ) .
						'</a>',
		);

		return array_merge( $links, $row_meta );
	}

	/**
	 * This function is responsible for including the necessary admin scripts.
	 *
	 * @return void
	 */
	public function admin_scripts() {
		wp_enqueue_style(
			'woocommerce-mastercard-admin', 
			plugins_url( 'assets/css/mastercard-admin.css', MG_ENTERPRISE_MAIN_FILE ),
			array(), 
			MG_ENTERPRISE_MODULE_VERSION, 
			false
		);

		if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'woocommerce-mastercard-admin',
			plugins_url( 'assets/js/mastercard-admin.js', MG_ENTERPRISE_MAIN_FILE ),
			array(),
			MG_ENTERPRISE_MODULE_VERSION,
			true
		);
	}

	/**
	 * This function displays admin notices.
	 *
	 * @return void
	 */
	public function admin_notices() {
		$this->render_payment_link_action_notices();

		if ( ! $this->gateway->enabled ) {
			return;
		}

		if ( ! $this->gateway->username || ! $this->gateway->password ) {
			$class         = 'notice notice-error';
			$error_message = __( 'Mastercard Gateway payment methods cannot be activated without valid API credentials. Update them now via this <a href="' . 
				admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' ) . MG_ENTERPRISE_ID . '">link</a>', MG_ENTERPRISE_TEXTDOMAIN );
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $error_message );
		}

		$this->gateway->display_errors();
	}

	/**
	 * Render WordPress-style notices for payment-link actions.
	 *
	 * Reads redirect query params from payment-link actions and prints
	 * dismissible admin notices on the order edit screen.
	 *
	 * @return void
	 */
	private function render_payment_link_action_notices() {
		// Allow admin AJAX save flows as well (used during initial order creation in some UIs).
		if ( ! is_admin() ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		$message = '';
		$class   = 'notice-success';

		$mail_status = isset( $_GET['mpgs_mail_sent'] ) ? sanitize_text_field( wp_unslash( $_GET['mpgs_mail_sent'] ) ) : null;
		if ( null !== $mail_status ) {
			if ( '1' === $mail_status ) {
				$message = __( 'Payment link resent successfully.', MG_ENTERPRISE_TEXTDOMAIN );
			} else {
				$message = __( 'Payment link could not be resent. Please try again.', MG_ENTERPRISE_TEXTDOMAIN );
				$class   = 'notice-error';
			}
		}

		$revoke_status      = isset( $_GET['mpgs_link_revoked'] ) ? sanitize_text_field( wp_unslash( $_GET['mpgs_link_revoked'] ) ) : null;
		$revoke_mail_status = isset( $_GET['mpgs_revoke_mail_sent'] ) ? sanitize_text_field( wp_unslash( $_GET['mpgs_revoke_mail_sent'] ) ) : null;
		if ( null !== $revoke_status ) {
			if ( '1' === $revoke_status && '1' === $revoke_mail_status ) {
				$message = __( 'Payment link revoked and email sent to customer.', MG_ENTERPRISE_TEXTDOMAIN );
				$class   = 'notice-success';
			} elseif ( '1' === $revoke_status ) {
				$message = __( 'Payment link revoked, but the email could not be sent.', MG_ENTERPRISE_TEXTDOMAIN );
				$class   = 'notice-error';
			} else {
				$message = __( 'Payment link could not be revoked. Please try again.', MG_ENTERPRISE_TEXTDOMAIN );
				$class   = 'notice-error';
			}
		}

		$regenerate_status = isset( $_GET['mpgs_link_regenerated'] ) ? sanitize_text_field( wp_unslash( $_GET['mpgs_link_regenerated'] ) ) : null;
		if ( null !== $regenerate_status ) {
			if ( '1' === $regenerate_status ) {
				$message = __( 'Payment link regenerated and email sent to customer successfully.', MG_ENTERPRISE_TEXTDOMAIN );
				$class   = 'notice-success';
			} else {
				$message = __( 'Payment link regenerated, but the email could not be sent.', MG_ENTERPRISE_TEXTDOMAIN );
				$class   = 'notice-error';
			}
		}

		$generated_status = isset( $_GET['mpgs_link_generated'] ) ? sanitize_text_field( wp_unslash( $_GET['mpgs_link_generated'] ) ) : null;
		if ( null !== $generated_status ) {
			if ( '1' === $generated_status ) {
				$message = __( 'Payment link sent successfully.', MG_ENTERPRISE_TEXTDOMAIN );
				$class   = 'notice-success';
			} else {
				$message = __( 'Payment link generated, but the email could not be sent.', MG_ENTERPRISE_TEXTDOMAIN );
				$class   = 'notice-error';
			}
		}

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Fallback charge injector after order object save.
	 *
	 * On initial "Create" in admin (legacy/HPOS), payment method and destination
	 * can be finalized only after object save. This hook ensures shipping and handling
	 * fee are applied in the same save cycle (shipping first, then handling fee).
	 *
	 * @param mixed $order      Order object from Woo save hook.
	 * @param mixed $data_store Woo data store instance (unused).
	 *
	 * @return void
	 */
	public function maybe_add_charges_after_order_save( $order, $data_store ) {

		if ( ! ( $order instanceof \WC_Order ) || ! $this->should_run_admin_order_fee_automation() ) {
			return;
		}

		// Prevent recursive save loops when this method (or called methods)
		// performs an additional order save in the same request.
		static $processing_orders = array();
		$order_id = (int) $order->get_id();
		if ( isset( $processing_orders[ $order_id ] ) ) {
			return;
		}
		$processing_orders[ $order_id ] = true;

		if ( $this->get_order_payment_method( $order ) !== MG_ENTERPRISE_ID ) {
			unset( $processing_orders[ $order_id ] );
			return;
		}

		$this->auto_add_shipping_to_manual_order( $order->get_id() );
		// Re-load after potential shipping save before fee calculation.
		$order = wc_get_order( $order->get_id() );
		$this->add_handling_fee_to_admin_order( $order->get_id(), $order->get_items() );
		unset( $processing_orders[ $order_id ] );
	}

	/**
	 * Apply shipping and handling when a manual admin order is first created.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return void
	 */
	public function maybe_add_charges_on_order_create( $order_id ) {
		if ( ! $this->is_admin_order_context() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $this->get_order_payment_method( $order ) !== MG_ENTERPRISE_ID ) {
			return;
		}

		$this->auto_add_shipping_to_manual_order( $order_id );
		$order = wc_get_order( $order_id );
		$this->add_handling_fee_to_admin_order( $order_id, $order ? $order->get_items() : array() );
	}

	/**
	 * Resolve order payment method during admin save/create cycles.
	 *
	 * HPOS/legacy save flows may temporarily expose payment method via order meta
	 * before the canonical getter is finalized in the same request.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 *
	 * @return string
	 */
	private function get_order_payment_method( $order ) {
		$payment_method = (string) $order->get_payment_method();
		if ( '' !== $payment_method ) {
			return $payment_method;
		}

		$meta_payment_method = (string) $order->get_meta( '_payment_method' );
		if ( '' !== $meta_payment_method ) {
			return $meta_payment_method;
		}

		// During initial admin create, payment method may exist only in request payload.
		if ( isset( $_POST['payment_method'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
		}

		return '';
	}

	/**
	 * If WooCommerce is not active return error messgae.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function stop() {
		if ( ! $this->woocommerce_is_active() ) {
			deactivate_plugins( MG_ENTERPRISE_PLUGIN_BASENAME );
			unset( $_GET['activate'] );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		} else {
			$this->admin_init();
		}
	}

	/**
	 * Check if WooCommerce is active or not.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private function woocommerce_is_active() {
		return is_plugin_active( 'woocommerce/woocommerce.php' );
	}

	/**
	 * Checks available payment options by performing a Payment Options Inquiry via the gateway service.
	 *
	 * - Validates specific fee amount settings (e.g., handling fee, surcharge).
	 * - Captures relevant gateway data for later use.
	 * - Initiates a payment options inquiry request through the Mastercard gateway service.
	 * - Catches and logs any exceptions encountered during the API call.
	 *
	 * @param array $settings Array of gateway configuration settings to validate.
	 */
	public function check_payment_options_inquiry( $settings ) {
		$gateway = GatewayController::get_instance();

		try {
			if ( 'mastercard_gateway' === $this->gateway->id ) {
				$this->handling_exceeded  = false;
				$this->surcharge_exceeded = false;
				$this->verify_fee_amount( $settings, HF_AMT_TYPE_TXT, HF_AMT_TXT );
				$this->verify_fee_amount( $settings, SUR_AMT_TYPE_TXT, SUR_AMT_TXT );
				$this->show_fee_error();
				$this->capture_gateway_data( $settings );
			}
	
			$response = $gateway->init_service()->paymentOptionsInquiry();
			if ( $response['result'] === 'ERROR' ) {
				wp_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $this->id ) );
				throw new GatewayResponseException( $response['error']['explanation'] );
			}
		} catch ( \Fingent\Mastercard\Logger\GatewayResponseException | \Exception $e ) {
			$this->gateway->add_error(
				wp_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout') ),
				sprintf( __( 'Error communicating with payment gateway API: "%s"', MG_ENTERPRISE_TEXTDOMAIN ), $e->getMessage() )
			);
		}
	}

	/**
	 * Validates and enforces a maximum limit on the surcharge fee percentage.
	 *
	 * This function checks if the specified fee type is set to 'percentage' in the settings.
	 * If so, it ensures that the associated fee amount does not exceed 99.9%.
	 * If the value exceeds 99.9, it:
	 *   - Displays an admin error message (only once per request).
	 *   - Resets the fee amount to 99.9 using the gateway's update_option method.
	 *
	 * @param array  $settings       The gateway settings array.
	 * @param string $fee_type_text  The settings key for the fee type (e.g., 'fee_type').
	 * @param string $fee_amount_txt The settings key for the fee amount (e.g., 'fee_amount').
	 */
	public function verify_fee_amount( $settings, $fee_type_text, $fee_amount_txt ) {
		if (
			isset( $settings[ $fee_type_text ] ) &&
			HF_PERCENTAGE === $settings[ $fee_type_text ] &&
			floatval( $settings[ $fee_amount_txt ] ) > 99.9
		) {
			if ( HF_AMT_TYPE_TXT === $fee_type_text ) {
				$this->handling_exceeded = true;
			} elseif ( SUR_AMT_TYPE_TXT === $fee_type_text ) {
				$this->surcharge_exceeded = true;
			}

			$this->gateway->update_option( $fee_amount_txt, 99.9 );
		}
	}

	/**
	 * Displays the appropriate admin notice when a fee percentage exceeds 99.9%.
	 *
	 * @return void
	 */
	public function show_fee_error() {
		if ( self::$fee_error_added ) {
			return;
		}

		if ( $this->handling_exceeded && $this->surcharge_exceeded ) {
			WC_Admin_Settings::add_error( __( HF_SUR_ERROR_MSG, MG_ENTERPRISE_TEXTDOMAIN ) );
			self::$fee_error_added = true;
		} elseif ( $this->handling_exceeded ) {
			WC_Admin_Settings::add_error( __( HF_ERROR_MSG, MG_ENTERPRISE_TEXTDOMAIN ) );
			self::$fee_error_added = true;
		} elseif ( $this->surcharge_exceeded ) {
			WC_Admin_Settings::add_error( __( SUR_ERROR_MSG, MG_ENTERPRISE_TEXTDOMAIN ) );
			self::$fee_error_added = true;
		}
	}

	/**
	 * Captures and sends plugin environment data to Mastercard for tracking.
	 *
	 * This function checks if the currently stored plugin version differs from the
	 * constant `MG_ENTERPRISE_MODULE_VERSION`. If so, and if sandbox credentials exist,
	 * it gathers relevant shop and plugin data (e.g. shop name, country, URL, version)
	 * and sends a capture request to Mastercard via the service's API.
	 *
	 * Upon a successful response, it updates the stored plugin version to prevent
	 * repeated requests.
	 *
	 * Purpose: Helps Mastercard keep track of plugin usage and environment context.
	 */
	public function capture_gateway_data( $settings ) {
		$gateway         = GatewayController::get_instance();
		$current_version = get_option( 'mpgs_current_version', '' );

		if ( MG_ENTERPRISE_MODULE_VERSION === $current_version ) {
			return;
		}

		if ( empty( $settings['sandbox_username'] ) || empty( $settings['sandbox_password'] ) ) {
			return;
		}

		$default_country = get_option( 'woocommerce_default_country' );
		$country_code    = $default_country ? explode( ':', $default_country )[0] : '';
		$countries       = WC()->countries->get_countries();
		$country_name    = $countries[ $country_code ] ?? '';

		$response        = $gateway->init_service()->sendCaptureRequest(
			'gateway-woocommerce-mastercard-module',
			'enterprise',
			MG_ENTERPRISE_MODULE_VERSION,
			'1',
			$country_code,
			$country_name,
			get_bloginfo( 'name' ),
			get_home_url(),
			MG_ENTERPRISE_STATUS_TOKEN,
			MG_ENTERPRISE_CAPTURE_URL
		);

		if ( ! empty( $response['status'] ) && 'success' === $response['status'] ) {
			update_option( 'mpgs_current_version', MG_ENTERPRISE_MODULE_VERSION );
		}
	}

	/**
	 * Determines whether the admin order page should render refunds.
	 *
	 * This function is used to check if refunds should be displayed on the admin order page. 
	 * It can be used to add custom logic or conditions for rendering refunds in the order details view.
	 *
	 * @param bool $render_refunds Indicates whether refunds should be rendered.
	 * @param int $order_id The ID of the order being viewed.
	 * @param WC_Order $order The order object for which the refunds are being checked.
	 * @return bool Updated value of $render_refunds indicating whether refunds should be rendered.
	 */
	public function admin_order_should_render_refunds( $render_refunds, $order_id, $order ):bool {
		if ( MG_ENTERPRISE_ID === $order->get_payment_method() ) {
			if ( 'refunded' === $order->get_status() || 'cancelled' === $order->get_status() || empty( $order->get_meta( self::META_ORDER_CAPTURED ) ) ) {
				return false;
			}
		}

		return $render_refunds;
	}

	/**
	 * Adds transaction mode column to the existing columns in the list table.
	 *
	 * This function modifies the list of columns by adding or altering
	 * columns, typically for displaying additional information like
	 * transaction modes or other custom data.
	 *
	 * @param array $columns Existing columns in the list table.
	 * @return array Modified array of columns including the transaction mode column.
	 */
	public function transaction_mode_columns( $columns ) {
	    $columns['mg_transaction'] = 'Transaction Mode';
	    return $columns;
	}

	/**
	 * Callback function to display data in the custom 'transaction_mode' column in the WooCommerce Orders List Table.
	 *
	 * @param string $column The name of the column currently being processed.
	 * @param WC_Order $order The order object for the current row being displayed.
	 */
	public function transaction_mode_column_data( $column, $order ) {	
	    if ( 'mg_transaction' !== $column ) {
			return;
		}

		$mode   = $order->get_meta( self::META_TRANSACTION_MODE );
		$labels = [
			'capture'   		 => 'Purchase',
			'authorize'		 	 => 'Authorize',
			'captured'  		 => 'Captured',
			'void'      		 => 'Void',
			'partially_refunded' => 'Partially Refunded',
			'refunded'   		 => 'Refunded',
		];

		$label  = $labels[ $mode ] ?? 'N/A';
		$class  = 'mg-' . sanitize_html_class( $mode ?: 'na' );

		if( 'pending' === $order->get_status() ) {
			$label = 'N/A';
			$class = 'mg-na';
		}

		echo '<mark class="mg-transaction-mode ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span></mark>';
	}

	/**
	 * Process the capture.
	 *
	 * @return void
	 *
	 * @throws Exception If there's a problem for capturing the payment.
	 */
	public function process_capture() {
		$gateway = GatewayController::get_instance();

		if ( ! isset( $_REQUEST['post_ID'] ) ) { // phpcs:ignore
			return;
		}

		$order_id = sanitize_key( wp_unslash( $_REQUEST['post_ID'] ) );
		$order    = wc_get_order( $order_id );

		if ( $order->get_payment_method() !== MG_ENTERPRISE_ID ) {
			throw new \Exception( 'Wrong payment method' );
		}
		if ( $order->get_status() !== 'processing' ) {
			throw new \Exception( 'Wrong order status, must be \'processing\'' );
		}

		if ( ! empty( $order->get_meta( self::META_ORDER_CAPTURED ) ) ) {
			throw new \Exception( 'The order is already captured.' );
		}

		$result = $gateway->init_service()->captureTxn(
			$this->add_order_prefix( $order->get_id() ),
			time(),
			(float) $order->get_total(),
			$order->get_currency()
		);

		$txn       = $result['transaction'];
		$auth_code = isset( $txn['authorizationCode'] ) ? $txn['authorizationCode'] : null;

		if ( $auth_code ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1. Capture id, 2. Authorization Code. */
					__( 'Mastercard payment CAPTURED (ID: %1$s, Auth Code: %2$s)', 'mastercard' ),
					$txn['id'],
					$auth_code
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1. Capture id, 2. Authorization Code. */
					__( 'Mastercard payment CAPTURED (ID: %1$s)', 'mastercard' ),
					$txn['id'],
				)
			);
		}

		$order->update_meta_data( self::META_ORDER_CAPTURED, true );
		$order->update_meta_data( self::META_TRANSACTION_MODE, 'captured' );
		$order->save_meta_data();

		if ( wp_get_referer() || 'yes' !== $this->utility->is_hpos() ) {
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
	 * Reverse Authorization.
	 *
	 * @return void
	 *
	 * @throws Exception If there's a problem for capturing the payment.
	 */
    public function void_authorized_order() {
        try {
        	$order_id   = sanitize_key( wp_unslash( $_REQUEST['post_ID'] ) ); // phpcs:ignore
            $order      = new WC_Order( $order_id );
            $gateway    = GatewayController::get_instance();
            $auth_txn   = $gateway->init_service()->getAuthorizationTransaction( $this->add_order_prefix( $order_id ) );

            if ( $order->get_payment_method() != MG_ENTERPRISE_ID ) {
                throw new Exception( 'Wrong payment method' );
            }
            if ( $order->get_status() != 'processing' ) {
                throw new Exception( 'Wrong order status, must be \'processing\'' );
            }
            if ( $order->get_meta( self::META_ORDER_CAPTURED ) ) {
                throw new Exception( 'Order already reversed' );
            }

            $transaction_id = $order->get_meta( '_mpgs_transaction_id' );  

            if( $transaction_id === $auth_txn['transaction']['id'] || $transaction_id === $auth_txn['authentication']['transactionId'] ) {
	            $result = $gateway->init_service()->voidTxn(
					$this->add_order_prefix( $order->get_id() ),
					$auth_txn['transaction']['id']
				);		
	         	
	         	if( 'SUCCESS' === $result['result'] ) {
		            $txn = $result['transaction'];	         		
	         		$order->update_meta_data( self::META_TRANSACTION_MODE, 'void' );
		            $order->update_status( 'cancelled', sprintf( __( 'Gateway reverse authorization (ID: %s)',
		                MG_ENTERPRISE_TEXTDOMAIN ),
		                $txn['id'] ) );
		        } else {
		        	throw new \Exception( 'Gateway reverse authorization failure.' );
		        }

	            if ( wp_get_referer() || 'yes' !== $this->utility->is_hpos() ) {
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
	 * Process a refund for an order if supported.
	 *
	 * @param int        $order_id The ID of the order being refunded.
	 * @param float|null $amount The amount to be refunded.
	 * @param string     $reason The reason for the refund.
	 *
	 * @return bool True if the refund was processed successfully, false otherwise.
	 */
	public function process_refund( $order_id, $amount, $reason ) {
		try {
			$gateway = GatewayController::get_instance();
			$order   = new WC_Order( $order_id );

			if ( is_wp_error( $order ) || ! $this->can_refund_order( $order ) || empty( $amount ) ) {
				return new WP_Error( 'error', __( 'Refund failed.', 'woocommerce' ) );
			}

			$result  = $gateway->init_service()->refund(
				$this->add_order_prefix( $order_id ),
				(string) time(),
				$amount,
				$order->get_currency()
			);

			if( 'SUCCESS' === $result[ 'result'] ) {
				$order->update_meta_data( self::META_TRANSACTION_MODE, strtolower( $result[ 'order' ]['status'] ) );
				$order->add_order_note(
					sprintf(
						/* translators: 1. Transaction amount, 2. Transaction currency, 3. Transaction id. */
						__( 'Mastercard registered refund %1$s (ID: %2$s)', MG_ENTERPRISE_TEXTDOMAIN ),
						wc_price( $result['transaction']['amount'] ),
						$result['transaction']['id']
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: 1. Transaction amount. */
						__( '%1$s', MG_ENTERPRISE_TEXTDOMAIN ),
						$result[ 'error' ][ 'explanation' ]
					)
				);
			}
			$order->save();
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage() );
		}
	}

	/**
	 * Can the order be refunded via Mastercard Gateway?
	 *
	 * @param  WC_Order $order Order object.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		$has_api_creds = false;

		if ( $this->gateway->sandbox ) {
			$has_api_creds = $this->gateway->username && $this->gateway->password;
		} 

		return $order && $order->get_transaction_id() && $has_api_creds;
	}

	/**
	 * Inserts custom HTML and JavaScript into the WordPress admin footer.
	 *
	 * This function is designed to run specifically when a particular admin section
	 * (identified by `MG_ENTERPRISE_ID`) is being viewed. It conditionally renders
	 * a `CheckoutView` and then injects configuration data into a global JavaScript
	 * object, `window.mgReactPreview`.
	 *
	 * This setup suggests that a React-based front-end component or application
	 * within the admin area relies on these JavaScript variables for its initial
	 * configuration, likely for displaying preview information related to payment
	 * gateways or checkout processes.
	 *
	 * @return void
	 */
	public function insert_custom_admin_footer_html() {
		if ( ! isset( $_GET['section'] ) || $_GET['section'] !== MG_ENTERPRISE_ID ) {
	        return;
	    }

		$view = new CheckoutView( null, array( 'method' => 'admin' ) ); 
		$view->render(); ?>
		<script type="text/javascript">
	        let ajaxUrl = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
	        let mgPreviewNonce = '<?php echo wp_create_nonce( 'mg_preview_config_nonce' ); ?>';
	    </script>
	    <?php
	}

	/**
	 * Returns the configuration data required for the React payment form preview UI.
	 *
	 * This includes accordion section headings, style defaults for form elements,
	 * font family options, and surcharge feature toggle.
	 *
	 * The returned data is JSON-encoded and structured for use within the admin
	 * preview modal.
	 *
	 * @return void Outputs a JSON response via wp_send_json_success
	 */
	public function get_preview_config() {
	    // Prepare accordion headings for UI sections
	    $accordion = array(
	        'surchargeHeading'           => esc_attr( SURCHARGE_HEADING ),
	        'paymentFormHeading'         => esc_attr( PAYMENT_FORM_HEADING ),
	        'paymentInputHeading'        => esc_attr( PAYMENT_INPUT_HEADING ),
	        'paymentButtonHeading'       => esc_attr( PAY_BUTTON_HEADING ),
	        'surchargeConsent'           => esc_attr( SURCHARGE_CONSENT_HEADING ),
	        'surchargeConfirmBtnHeading' => esc_attr( SURCHARGE_CONFIRM_HEADING ),
	        'surchargeCancelBtnHeading'  => esc_attr( SURCHARGE_CANCEL_HEADING ),
	    );

	    $options = get_option( 'woocommerce_' . MG_ENTERPRISE_ID . '_style_defaults' ); 

	    // Send JSON success response with all necessary preview config data
	    wp_send_json_success(
	    	array(
		        'method'             => esc_attr( $this->gateway->method ),
		        'gatewayInteraction' => esc_attr( $this->gateway->hc_interaction ),

		        // Available Google Font families
		        'fontFamilies'       => json_encode( $this->utility->get_font_families() ),

		        // Whether surcharge feature is enabled
		        'isSurchargeEnabled' => (
		            isset( $this->gateway->surcharge_enabled ) && $this->gateway->surcharge_enabled === 'yes'
		        ) ? 'yes' : 'no',

		        // Accordion section headings for admin design panel
		        'accordionHeadings'  => json_encode( $accordion ),

		        // Default styles for UI sections
		        'surchargeFeeStyle'   => $options ? $options['surcharge_fee_style'] : json_encode( SURCHARGE_STYLES ),
		        'paymentFormStyle'    => $options ? $options['payment_form_style'] : json_encode( FORM_STYLES ),
		        'paymentInputStyle'   => $options ? $options['payment_input_style'] : json_encode( PAYMENT_INPUT_STYLES ),
		        'payButtonStyle'      => $options ? $options['pay_button_style'] : json_encode( PAY_BUTTON_STYLES ),
		        'consentStyle'        => $options ? $options['consent_style'] : json_encode( CONSENT_STYLES ),
		        'confirmButtonStyle'  => $options ? $options['confirm_button_style'] : json_encode( CONFIRM_BUTTON_STYLES ),
		        'cancelButtonStyle'   => $options ? $options['cancel_button_style'] : json_encode( CANCEL_BUTTON_STYLES ),
		    )
	    );
	}


	/**
	 * Handles AJAX request to save the Payment Form Preview configuration.
	 *
	 * This method verifies the nonce and user capabilities, parses the submitted
	 * style configuration data, and persists it to the WordPress options table.
	 *
	 * @return void Sends a JSON response (success or error).
	 */
	public function save_preview_config() {
	    // Verify nonce for security
	    check_ajax_referer( 'mg_preview_config_nonce', 'security' );

	    // Check if the current user has appropriate permissions
	    if ( ! current_user_can( 'manage_options' ) ) {
	        wp_send_json_error( array(  'message' => 'Unauthorized access.' ) );
	    }

	    // Decode the incoming JSON data
	    $raw_data = isset( $_POST['data'] ) ? json_decode( stripslashes( $_POST['data'] ), true ) : null;

	    // Validate and prepare formatted data
	    if ( ! is_array( $raw_data ) ) {
	        wp_send_json_error( array(  'message' => 'Invalid data received.' ) );
	    }

	    $formatted_data = array(
	        'surcharge_fee_style'   => json_encode( $raw_data['surchargeFeeStyle'] ?? array() ),
	        'payment_form_style'    => json_encode( $raw_data['paymentFormStyle'] ?? array() ),
	        'payment_input_style'   => json_encode( $raw_data['paymentInputStyle'] ?? array() ),
	        'pay_button_style'      => json_encode( $raw_data['payButtonStyle'] ?? array() ),
	        'consent_style'         => json_encode( $raw_data['consentStyle'] ?? array() ),
	        'confirm_button_style'  => json_encode( $raw_data['confirmButtonStyle'] ?? array() ),
	        'cancel_button_style'   => json_encode( $raw_data['cancelButtonStyle'] ?? array() ),
	    );

	    $option_key = 'woocommerce_' . MG_ENTERPRISE_ID . '_style_defaults';

	    if ( delete_option( $option_key ) ) {
	    	update_option( $option_key, $formatted_data );
	        wp_send_json_success( array(  'message' => 'Preview settings have been saved.' ) );
	    } else {
	    	wp_send_json_error( array(  'message' => 'Failed to save preview settings 1.' ) );
	    }
	}
	
	/**
	 * Display "Generate Payment URL" or "Copy/Revoke Payment Link" button 
	 * in the WooCommerce admin order edit page.
	 *
	 * This function displays buttons depending on whether a Pay By Link URL
	 * already exists for the order or needs to be generated. It also shows
	 * expiry information and handles copy-to-clipboard functionality.
	 *
	 * Steps:
	 * 1. Ensure the current user has permission to manage WooCommerce orders.
	 * 2. Confirm that the context is the admin order page.
	 * 3. Load gateway settings to check if payment link feature is enabled.
	 * 4. Ensure the payment method is MG_ENTERPRISE_ID, order status is 'pending',
	 *    payment link feature is enabled, and integration method is HOSTED_CHECKOUT.
	 * 5. Check if a payment link already exists for the order:
	 *    - If yes:
	 *       a. Display "Copy Payment Link" button.
	 *       b. Show expiry date/time if available, with red warning if expired.
	 *       c. Display "Revoke Payment Link" button with proper nonce URL.
	 *       d. Include JavaScript to copy the URL to clipboard and show a temporary message.
	 *    - If no:
	 *       a. Display "Generate Payment URL" button linking to the AJAX action with proper nonce.
	 *
	 * @param WC_Order $order The WooCommerce order object.
	 * @return void Outputs HTML and JS directly on the admin order edit page.
	 */
	public function unpaid_order_payment_link_meta_box_content( $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Legacy order screen passes WP_Post to meta box callbacks.
		if ( $order instanceof \WP_Post ) {
			$order = wc_get_order( $order->ID );
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! is_admin() ) {
			return;
		}
		
		$settings            = get_option( 'woocommerce_' . MG_ENTERPRISE_ID . '_settings', [] );
		$paymentlink_enabled = isset( $settings['paymentlink_enabled'] ) && $settings['paymentlink_enabled'] === 'yes';
		$integration_method  = isset( $settings['method'] ) ? $settings['method'] : HOSTED_CHECKOUT; 

		if ( $order->get_payment_method() !== MG_ENTERPRISE_ID || $order->get_status() !== 'pending' || ! $paymentlink_enabled || $integration_method !== HOSTED_CHECKOUT  ) {
			return;
		}

		$order_id         = $order->get_id();
		$pay_link         = $order->get_meta( '_pay_by_link_url' );
		$expiry_date      = $order->get_meta( '_pay_by_link_expiry_date_time' );
		$is_expired       = false;
		$expiry_formatted = '';

		if ( ! empty( $expiry_date ) ) {
			try {
				$expiry_dt        = new \DateTime( $expiry_date, new \DateTimeZone('UTC') );
				$now              = new \DateTime( 'now', new \DateTimeZone('UTC') );
				$is_expired       = $now > $expiry_dt;
				$expiry_formatted = $expiry_dt->setTimezone( wp_timezone() )->format( 'd/m/Y H:i:s' );
			} catch ( \Exception $e ) {
				$is_expired       = false;
				$expiry_formatted = '';
			}
		}

		if ( $pay_link ) {
			echo '<div class="form-field form-field-wide wc-customer-user payment-link-container">';

			if ( $expiry_date ) {
				echo '<div class="payment-link-details">';

				if ( $is_expired ) {
					$already_marked = $order->get_meta( '_pay_by_link_is_expired' );
					echo '<p><strong style="color:red;">Your payment link has expired.</strong></p>';
					if ( $already_marked !== 'yes' ) {
						$order->update_meta_data( '_pay_by_link_is_expired', 'yes' );
						$order->add_order_note(
							sprintf(
								__( 'The previously generated payment link has expired. Kindly regenerate a new one to complete the payment.', MG_ENTERPRISE_TEXTDOMAIN )
							)
						);
						$order->save();
					}
				} else {
					echo '<p><strong>Expiry Date:</strong> ' . esc_html( $expiry_formatted ) . '</p>';
				}

				echo '</div></div>';
			}

			if ( $expiry_date && ! $is_expired ) {
				echo '<div class="payment-link-url-box">';
				echo '<textarea class="payment-link-url-input" rows="3" readonly>' . esc_textarea( $pay_link ) . '</textarea>';
				echo '</div>';
			}

			$revoke_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'mpgs_revoke_payment_link',
						'order_id' => $order_id,
					),
					admin_url( 'admin-ajax.php' )
				),
				'mpgs_revoke_payment_link_' . $order_id
			);

			$regenerate_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'regenerate_payment',
						'order_id' => $order_id,
					),
					admin_url( 'admin-ajax.php' )
				),
				'regenerate_payment_' . $order_id
			);

			$resend_email_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'mpgs_resend_payment_link_email',
						'order_id' => $order_id,
					),
					admin_url( 'admin-ajax.php' )
				),
				'mpgs_resend_payment_link_email_' . $order_id
			);
			?>

			<?php if ( $expiry_date && ! $is_expired ) { ?>
				<p class="payment-link-actions">
					<button type="button" class="button button-primary copy-payment-link" style="width: 91px;" data-link="<?php echo esc_url( $pay_link ); ?>">
						<?php echo esc_html__( 'Copy Link', MG_ENTERPRISE_TEXTDOMAIN ); ?>
					</button>
					<a class="button button-secondary" style="width: 117px; text-align: center;" href="<?php echo esc_url( $resend_email_url ); ?>">
						<?php echo esc_html__( 'Resend Mail', MG_ENTERPRISE_TEXTDOMAIN ); ?>
					</a>
				</p>
			<?php } ?>

			<div class="mc-order-data-row">
				<div class="mc-order-data-inner">
					<div>
						<a class="button button-secondary" href="<?php echo esc_url( $revoke_url ); ?>">
							<?php echo esc_html__( 'Revoke Link', MG_ENTERPRISE_TEXTDOMAIN ); ?>
						</a>
					</div>
					<div>
						<a class="button button-primary" href="<?php echo esc_url( $regenerate_url ); ?>">
							<?php echo esc_html__( 'Regenerate Link', MG_ENTERPRISE_TEXTDOMAIN ); ?>
						</a>
					</div>
				</div>
			</div>
			<style type="text/css">
				.postbox#mastercard-payment-link .inside {
					padding: 0;
				}
				.postbox#mastercard-payment-link .inside .payment-link-container {
					padding: 0 12px 0px;
				}
				.postbox#mastercard-payment-link .inside .payment-link-container .payment-link-details {
					margin-top:10px;
					margin-bottom: 10px;
					padding:8px 12px;
					background:#d7cad2;
					display: flex;
					gap: 15px;
					flex-wrap: wrap;
				}
				.postbox#mastercard-payment-link .inside .payment-link-container .payment-link-details p {
					margin: 0;
				}
				.postbox#mastercard-payment-link .inside .payment-link-url-box {
					margin-top: 10px;
					padding: 0px 12px 0px;
				}
				.postbox#mastercard-payment-link .inside .payment-link-url-input {
					width: 100%;
					min-height: 60px;
					background: #fff;
					box-sizing: border-box;
					overflow-wrap: anywhere;
					resize: none;
					padding: 8px;
				}
				.postbox#mastercard-payment-link .inside .mc-order-data-row {
					background: #f8f8f8;
					border-top: 1px solid #dfdfdf;
					padding:12px;
				}
				.postbox#mastercard-payment-link .inside .mc-order-data-inner {	
					display: flex;
					flex-wrap: wrap;
					justify-content: space-between;
				}
				.postbox#mastercard-payment-link .inside .payment-link-actions {
					display: flex;
					justify-content: space-between;
					align-items: center;
					padding: 0 12px;
				}
				.postbox#mastercard-payment-link .inside .mc-order-copy-actions {
					padding-right: 12px;
				}
				.postbox#mastercard-payment-link .inside .mc-order-data-row p {
					margin: 5px 0 0 0;
					padding: 0;
				}
			</style>
			<script>
				jQuery(document).ready(function ($) {
					function showWpNotice(message, type) {
						var noticeClass = type === 'error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
						var dismissText = '<?php echo esc_js( __( 'Dismiss this notice.', MG_ENTERPRISE_TEXTDOMAIN ) ); ?>';
						var $notice = $(
							'<div class="' + noticeClass + ' mpgs-copy-notice"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button></div>'
						);
						var $container = $('#wpbody-content .wrap').first();
						if (! $container.length) {
							$container = $('#wpbody-content');
						}
						$container.find('.mpgs-copy-notice').remove();
						$container.prepend($notice);
						$notice.on('click', '.notice-dismiss', function () {
							$notice.remove();
						});
					}

				    $( '.copy-payment-link' ).on( 'click', function (e) {
				        e.preventDefault();

				        var link  = $( this ).data( 'link' );
				        var $temp = $( '<textarea>' );

				        $( 'body' ).append( $temp );
				        $temp.val( link ).select();
				        document.execCommand( 'copy' );
				        $temp.remove();
				        $( 'html, body' ).animate( { scrollTop: 0 }, 200 );
				        showWpNotice( '<?php echo esc_js( __( 'Payment link copied to clipboard.', MG_ENTERPRISE_TEXTDOMAIN ) ); ?>', 'success' );
				    });
				});
			</script>
		<?php
		} else {
			$url = wp_nonce_url(
				add_query_arg(
					[
						'action'   => 'mpgs_generate_payment_url',
						'order_id' => $order_id,
					],
					admin_url( 'admin-ajax.php' )
				),
				'mpgs_generate_payment_url_' . $order_id
			);

			echo '<div class="form-field form-field-wide wc-customer-user payment-link-container"><p><a class="button button-secondary mastercard_gateway-button" href="' . esc_url( $url ) . '">'
				. esc_html__( 'Generate Payment URL', MG_ENTERPRISE_TEXTDOMAIN ) . '</a></p></div>';
		}
	}

	/**
	 * Process generation of a secure "Pay By Link" payment URL for a WooCommerce order.
	 *
	 * This method is triggered from the admin (via a GET request) to generate
	 * a secure payment link for a specific order. It performs security checks,
	 * validates the order and payment method, calls the payment gateway to 
	 * generate the payment URL, and adds an order note containing the URL.
	 *
	 * Steps:
	 * 1. Retrieve and validate the order ID from the request.
	 * 2. Verify the nonce for security.
	 * 3. Ensure the current user has permission to manage WooCommerce.
	 * 4. Validate the order object and confirm the payment method is MG_ENTERPRISE_ID.
	 * 5. Call the payment gateway's `GenerateSecureURL` method with order ID, 
	 *    currency, and amount.
	 * 6. If a payment link is returned, add an order note with the secure URL.
	 * 7. Redirect back to the WooCommerce order edit page.
	 *
	 * @return void Exits after redirect.
	 */
	public function process_generate_payment_url() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Missing or invalid order ID.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		check_admin_referer( 'mpgs_generate_payment_url_' . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== MG_ENTERPRISE_ID ) {
			wp_die( esc_html__( 'Invalid order or payment method.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$this->recalculate_order_before_payment_link_generation( $order );

		$gateway = GatewayController::get_instance();
		
		$response = $gateway->init_service()->GenerateSecureURL(
			[
				'id'       => $order->get_id(),
				'currency' => $order->get_currency(),
				'amount'   => (float) $order->get_total(),
			]
		);

		if ( ! empty( $response['paymentLink']['url'] ) ) {
			$order->add_order_note(
				sprintf(
					__( 'A secure payment link has been generated.', MG_ENTERPRISE_TEXTDOMAIN ),
					esc_url( $response['paymentLink']['url'] ),
					esc_url( $response['paymentLink']['url'] )
				)
			);
		}

		$redirect_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		if ( ! empty( $response['paymentLink']['url'] ) ) {
			$redirect_url = add_query_arg(
				'mpgs_link_generated',
				! empty( $response['mail_sent'] ) ? '1' : '0',
				$redirect_url
			);
		}
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Process revocation of a "Pay By Link" payment for a WooCommerce order.
	 *
	 * This method is triggered from the admin (via a GET request) to revoke
	 * an existing payment link associated with a specific order. It performs
	 * security checks, validates the order and payment method, calls the 
	 * payment gateway to revoke the link, removes related order meta, and 
	 * adds an order note upon success.
	 *
	 * Steps:
	 * 1. Retrieve and validate the order ID from the request.
	 * 2. Verify the nonce for security.
	 * 3. Ensure the current user has permission to manage WooCommerce.
	 * 4. Validate the order object and confirm the payment method is MG_ENTERPRISE_ID.
	 * 5. Check if a payment link ID exists for the order.
	 * 6. Call the payment gateway's `RevokePaymentLink` method.
	 * 7. If revocation is successful:
	 *    - Delete all relevant Pay By Link metadata from the order.
	 *    - Add an order note indicating success.
	 *    - Save the order.
	 * 8. Redirect back to the WooCommerce order edit page.
	 *
	 * @return void Exits after redirect.
	 */
	public function process_revoke_payment_link() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Missing or invalid order ID.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		check_admin_referer( 'mpgs_revoke_payment_link_' . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== MG_ENTERPRISE_ID ) {
			wp_die( esc_html__( 'Invalid order or payment method.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$link_id = $order->get_meta( '_pay_by_link_id' );
		if ( ! $link_id ) {
			wp_die( esc_html__( 'No payment link found to revoke.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$gateway  = GatewayController::get_instance();
		$response = $gateway->init_service()->RevokePaymentLink( 
			
			[
				'id'       => $order->get_id(),
				'paylink' => $link_id,
			]);

		$is_revoked = isset( $response['result'] ) && strtoupper( $response['result'] ) === 'SUCCESS';
		$mail_sent  = $is_revoked && ! empty( $response['mail_sent'] );

		// Remove meta if successful
		if ( $is_revoked ) {
			$order->delete_meta_data( '_pay_by_link_url' );
			$order->delete_meta_data( '_pay_by_link_id' );
			$order->delete_meta_data( '_pay_by_link_indicator' );
			$order->delete_meta_data( '_pay_by_link_expiry_date_time' );
			$order->delete_meta_data( '_pay_by_link_number_of_attempts' );
			$order->add_order_note(
					sprintf(
						__( 'The payment link has been revoked.', MG_ENTERPRISE_TEXTDOMAIN )
					)
			);
			$order->save();
		}

		$redirect_url = add_query_arg(
			array(
				'mpgs_link_revoked'     => $is_revoked ? '1' : '0',
				'mpgs_revoke_mail_sent' => $mail_sent ? '1' : '0',
			),
			admin_url( 'post.php?post=' . $order_id . '&action=edit' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Resend the "Pay By Link" request email for a WooCommerce order.
	 *
	 * @return void Exits after redirect.
	 */
	public function process_resend_payment_link_email() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Missing or invalid order ID.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		check_admin_referer( 'mpgs_resend_payment_link_email_' . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== MG_ENTERPRISE_ID ) {
			wp_die( esc_html__( 'Invalid order or payment method.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$pay_link_url = $order->get_meta( '_pay_by_link_url' );
		if ( empty( $pay_link_url ) ) {
			wp_die( esc_html__( 'No payment link found for this order.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$mail_sent = false;
		$mailer    = WC()->mailer();
		$emails    = $mailer->get_emails();

		if ( ! empty( $emails['WC_Email_Pay_By_Link'] ) ) {
			$custom_email = $emails['WC_Email_Pay_By_Link'];
			$custom_email->payment_link     = $pay_link_url;
			$custom_email->expiry_date_time = $order->get_meta( '_pay_by_link_expiry_date_time' );
			$custom_email->allowed_attempts = $order->get_meta( '_pay_by_link_number_of_attempts' );
			$mail_sent = (bool) $custom_email->trigger( $order->get_id() );
		}

		if ( $mail_sent ) {
			$order->add_order_note(
				__( 'Payment link email resent to customer.', MG_ENTERPRISE_TEXTDOMAIN )
			);
			$order->save();
		}

		$redirect_url = add_query_arg(
			'mpgs_mail_sent',
			$mail_sent ? '1' : '0',
			admin_url( 'post.php?post=' . $order_id . '&action=edit' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Conditionally lock editing of an order in the admin based on payment link status.
	 *
	 * This method checks if an order should be editable in the WooCommerce admin dashboard.
	 * Specifically, it prevents editing of orders that have an associated "Pay By Link" URL,
	 * except under certain conditions (e.g., pending orders without a creation date).
	 *
	 * Logic:
	 * 1. If not in the admin order context, return the original editable status.
	 * 2. Check if $order is a valid WC_Order object.
	 * 3. If the order has a "Pay By Link" URL:
	 *    - Allow editing if the order is pending and has no creation date.
	 *    - Otherwise, lock the order (return false).
	 * 4. If no "Pay By Link" URL exists, return the original editable status.
	 *
	 * @param bool     $is_editable Current editable status of the order.
	 * @param WC_Order $order       The order object being checked.
	 *
	 * @return bool Updated editable status (true = editable, false = locked).
	 */
	public function maybe_lock_order_editing( $is_editable, $order ) {
		if ( ! $this->is_admin_order_context() ) {
			return $is_editable;
		}

		if ( $order instanceof \WC_Order ) {
			if ( $order->get_id() && $order->get_meta( '_pay_by_link_url' ) ) {
				
				if ( $order->get_status() === 'pending' && empty( $order->get_date_created() ) ) {
					return $is_editable;
				}

				return false;
			}
		}

		return $is_editable;
	}

	/**
	 * Automatically add shipping to a manual admin order if none exists.
	 *
	 * This method calculates and applies the appropriate shipping method to an order
	 * created or edited from the admin dashboard, but only if the order does not 
	 * already have a shipping total. It uses WooCommerce shipping zones and methods
	 * to determine the correct shipping rate.
	 *
	 * Steps:
	 * 1. Ensure the order exists and does not already have shipping.
	 * 2. Confirm the context is admin (not front-end or AJAX).
	 * 3. Respect WooCommerce global shipping setting (wc_shipping_enabled); if shipping is
	 *    disabled under Settings → General → Shipping location(s), do nothing.
	 * 4. Skip paid/settled orders ({@see should_lock_paid_order_auto_adjustments()}) so enabling
	 *    shipping later does not add charges to already-completed payments.
	 * 5. Prepare a package array representing the order's shipping destination and user.
	 * 6. Determine the matching shipping zone for the order.
	 * 7. Get all enabled shipping methods for the zone.
	 * 8. Take the first enabled shipping method, create a shipping rate, and add it as
	 *    a shipping item to the order.
	 * 9. Recalculate totals and save the order.
	 *
	 * @param int $order_id The ID of the order to which shipping should be added.
	 */
	public function auto_add_shipping_to_manual_order( $order_id, $items = array() ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_shipping_total() > 0 ) {
			return; 
		}

		if ( ! $this->is_admin_order_context() ) {
			return;
		}

		if ( ! function_exists( 'wc_shipping_enabled' ) || ! wc_shipping_enabled() ) {
			return;
		}

		// Paid/completed orders must not get automatic shipping when settings change
		if ( $this->should_lock_paid_order_auto_adjustments( $order ) ) {
			return;
		}

		$country  = $order->get_shipping_country();
		$state    = $order->get_shipping_state();
		$postcode = $order->get_shipping_postcode();
		$city     = $order->get_shipping_city();
		$address1 = $order->get_shipping_address_1();
		$address2 = $order->get_shipping_address_2();

		// Backend-created orders frequently have only billing destination.
		if ( empty( $country ) ) {
			$country  = $order->get_billing_country();
			$state    = $order->get_billing_state();
			$postcode = $order->get_billing_postcode();
			$city     = $order->get_billing_city();
			$address1 = $order->get_billing_address_1();
			$address2 = $order->get_billing_address_2();
		}
		// Determine shipping zone for this order
		$package = array(
			'contents'        => array(),
			'contents_cost'   => 0,
			'applied_coupons' => array(),
			'user'            => array( 'ID' => $order->get_user_id() ),
			'destination'     => array(
			'country'   => $country,
			'state'     => $state,
			'postcode'  => $postcode,
			'city'      => $city,
			'address'   => $address1,
			'address_2' => $address2,
			),
		);

		// Get the shipping zone
		$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );
		if ( ! $zone ) {
			return;
		}

		// Get available shipping methods
		$shipping_methods = $zone->get_shipping_methods( true );
		if ( empty( $shipping_methods ) ) {
			return;
		}

		// Pick the first enabled method
		foreach ( $shipping_methods as $method ) {
			if ( $method->is_enabled() ) {
				$rate = new \WC_Shipping_Rate(
					$method->id,
					$method->get_title(),
					$method->cost,
					array(),
					$method->id
				);

				// Add shipping item to order
				$item = new \WC_Order_Item_Shipping();
				$item->set_method_title( $rate->get_label() );
				$item->set_method_id( $rate->get_id() );
				$item->set_total( $rate->get_cost() );

				$order->add_item( $item );
				$order->calculate_totals();
				$order->save();
				break;
			}
		}
	}

	/**
	 * Add a handling fee to an admin order if it doesn't already exist.
	 *
	 * This method calculates the handling fee based on the gateway settings and adds it
	 * as a fee line item to the order. It only applies in the admin context (i.e., when
	 * managing orders in the backend) and for orders that do not already have a handling fee.
	 *
	 * Steps:
	 * 1. Ensure the order exists.
	 * 2. Confirm the context is an admin order (not front-end or AJAX).
	 * 3. If {@see should_lock_paid_order_auto_adjustments()} applies, exit — fee lines stay as charged (not live gateway settings).
	 * 4. Remove prior plugin handling fee lines and recompute from current gateway settings (unpaid orders only).
	 * 5. Determine the handling fee amount and type (fixed or percentage).
	 * 6. Calculate the surcharge if applicable.
	 * 7. Add the fee to the order, recalculate totals, and save the order.
	 *
	 * @param int   $order_id The ID of the order to add the handling fee to.
	 * @param array $items    The order items (currently not directly used in fee calculation).
	 */
	public function add_handling_fee_to_admin_order( $order_id, $items ) {
		$order = wc_get_order( $order_id );
		if ( ! $order  ) {
        return;
    	}

		if ( ! $this->should_run_admin_order_fee_automation() ) {
			return;
		}

		// Apply handling fee only for Mastercard gateway orders.
		if ( $this->get_order_payment_method( $order ) !== MG_ENTERPRISE_ID ) {
			return;
		}

		// Respect handling fee enable/disable setting from gateway configuration.
		if ( empty( $this->gateway->hf_enabled ) || 'yes' !== $this->gateway->hf_enabled ) {
			return;
		}

		// Lock handling fee lines once payment is done.
		if ( $this->should_lock_paid_order_auto_adjustments( $order ) ) {
			return;
		}

		// Recalculate on every save: remove previous handling fee entries first.
		$removed_existing_handling_fee = $this->remove_handling_fee_from_order( $order );

		$amount_type  = $this->gateway->get_option( HF_AMT_TYPE_TXT );
		$handling_fee = $this->gateway->get_option( HF_AMT_TXT ) ? $this->gateway->get_option( HF_AMT_TXT ) : 0;

		if ( HF_PERCENTAGE === $amount_type ) {
			// Match frontend basis by following WooCommerce tax display mode.
			$cart_total = 0;
			foreach ( $order->get_items( 'line_item' ) as $line_item ) {
				$line_total = (float) $line_item->get_total();
				$cart_total += $line_total;
			}
			$surcharge = (float) $cart_total * ( (float) $handling_fee / 100 );
		} else {
			$surcharge = (float) $handling_fee;
		}

		if ( $surcharge > 0 ) {
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name( $this->get_handling_fee_label() ); // label
			$fee->set_amount( $surcharge );
			$fee->set_total( $surcharge );
			$fee->add_meta_data( HF_FEE_VAR, 'yes', true );

			$order->add_item( $fee );
			$order->calculate_totals();
			$order->save();
			return;
		}

		// If old fee was removed and new surcharge resolves to zero, persist updated totals.
		if ( $removed_existing_handling_fee ) {
			$order->calculate_totals();
			$order->save();
		}

	}

	/**
	 * Whether automatic admin adjustments (handling fee rebuild, auto shipping) must not run.
	 *
	 * Used so paid/settled orders are not rewritten when gateway or WooCommerce shipping settings change.
	 * Do not use {@see WC_Order::is_paid()} alone — it is only true for processing/completed.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool True when automatic fee/shipping logic should stay off.
	 */
	private function should_lock_paid_order_auto_adjustments( WC_Order $order ) {
		if ( $order->get_date_paid( 'edit' ) ) {
			return true;
		}
		if ( (float) $order->get_total_refunded() > 0 ) {
			return true;
		}
		if ( $order->has_status( array( 'refunded', 'partially_refunded' ) ) ) {
			return true;
		}
		if ( $order->has_status( 'on-hold' ) && $order->get_transaction_id() ) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieve the label used for the handling fee line item.
	 *
	 * This method checks whether a custom handling fee label has been configured
	 * in the gateway settings. If a custom label exists, it is returned. Otherwise,
	 * the default handling fee label is used.
	 *
	 * @return string The handling fee label to display on the order.
	 */
	private function get_handling_fee_label() {
		return ! empty( $this->gateway->get_option( HF_TEXT ) )
			? $this->gateway->get_option( HF_TEXT )
			: HF_DEFAULT_TEXT;
	}

	/**
	 * Remove handling fee line items from an order.
	 *
	 * Primarily used when payment method changes away from Mastercard, so
	 * handling fees are not retained for other gateways.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool True when at least one handling fee line was removed.
	 */
	private function remove_handling_fee_from_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$removed = false;
		$allowed_labels = array_unique(
			array_filter(
				array(
					(string) $this->get_handling_fee_label(),
					(string) HF_DEFAULT_TEXT,
				)
			)
		);

		foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
			$is_flagged_handling_fee = 'yes' === (string) $item->get_meta( HF_FEE_VAR, true );
			$is_known_handling_label = in_array( (string) $item->get_name(), $allowed_labels, true );

			if ( $is_flagged_handling_fee || $is_known_handling_label ) {
				$order->remove_item( $item_id );
				$removed = true;
			}
		}

		return $removed;
	}

	/**
	 * Ensure shipping and handling fees are added to a manual order *after* payment details
	 * are assigned but before final totals are calculated.
	 *
	 * This method runs during the admin order workflow and is used to automatically apply
	 * shipping charges and handling fees once the order has a valid payment method.
	 *
	 * Workflow:
	 * 1. Retrieve the order object using the provided order ID.
	 * 2. Validate that we are operating in a non-AJAX WooCommerce admin context.
	 * 3. Ensure the order exists and is using the MG Enterprise payment gateway.
	 * 4. Automatically add shipping charges for the order.
	 * 5. Automatically add handling fees based on the order items.
	 *
	 * Note: Totals are not recalculated here because this hook is intended to run before
	 * WooCommerce performs its own order total calculation during the save/update cycle.
	 *
	 * @param int $order_id The ID of the WooCommerce order being updated.
	 *
	 * @return void
	 */
	public function maybe_add_fees_after_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $this->is_admin_order_context() ) {
			return;
		}

		if ( ! $order || $order->get_payment_method() !== MG_ENTERPRISE_ID ) {
			return;
		}

		// Now safely add shipping/fees
		$this->auto_add_shipping_to_manual_order( $order_id );
		$this->add_handling_fee_to_admin_order( $order_id, $order->get_items() );
	}

	/**
	 * Determine if the current request is within the WooCommerce admin order screen.
	 *
	 * This helper ensures that certain UI elements or actions are only executed
	 * when inside the WordPress admin and not during AJAX requests.
	 *
	 * Why this check is important:
	 * - `is_admin()` alone is not enough because AJAX calls also run in "admin" context.
	 * - `wp_doing_ajax()` prevents unintended output or logic from running during
	 *   background WooCommerce or admin-related AJAX requests.
	 *
	 * @return bool True when in WP Admin and NOT processing an AJAX request.
	 */
	private function is_admin_order_context() {
		return is_admin() && ! wp_doing_ajax();
	}

	/**
	 * Whether automatic handling-fee / shipping helpers may touch the order.
	 * admin-ajax.php sets {@see is_admin()} true for storefront AJAX — exclude that and nopriv saves.
	 *
	 * @return bool
	 */
	private function should_run_admin_order_fee_automation() {
		if ( ! is_admin() ) {
			return false;
		}
		if ( ! wp_doing_ajax() ) {
			return true;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( in_array( $action, array( 'get_surcharge_amount', 'update_selected_payment_method' ), true ) ) {
			return false;
		}
		return current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Register the "Payment Request" email class with WooCommerce.
	 *
	 * This adds our custom email (sent when a payment link is generated)
	 * into WooCommerce’s email system, so it appears under
	 * WooCommerce → Settings → Emails and can be triggered programmatically.
	 *
	 * @param array $email_classes Existing WooCommerce email classes.
	 * @return array Modified email classes including our custom class.
	 */
	public function request_pay_by_link_email( $email_classes ) {
		$email_classes['WC_Email_Pay_By_Link'] = new PaymentRequestEmail();
		return $email_classes;
	}

	/**
	 * Register the "Payment Link Revoked" email class with WooCommerce.
	 *
	 * This email is sent when the merchant revokes the previously issued
	 * payment link. Registering it ensures WooCommerce recognizes this email
	 * and allows us to trigger it when required.
	 *
	 * @param array $email_classes Existing WooCommerce email classes.
	 * @return array Modified email classes including our revoke email class.
	 */
	public function register_payment_revoked_email( $email_classes ) {
		$email_classes['WC_Email_Pay_By_Link_Revoked'] = new PaymentRevokedEmail();
		return $email_classes;
	}

	/**
	 * Revoke payment link automatically when an order is cancelled.
	 *
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public function maybe_revoke_pay_by_link_on_cancel( $order_id, $order ) {

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$paylink_id = $order->get_meta( '_pay_by_link_id' );

		if ( empty( $paylink_id ) ) {
			return; 
		}

		try {
			$gateway = GatewayController::get_instance();
			$gateway->init_service()->RevokePaymentLink(
				[
					'id'      => $order_id,
					'paylink' => $paylink_id,
				]
			);
			$order->add_order_note( sprintf( 'Payment link generated has been revoked.', $paylink_id ) );

		} catch ( \Exception $e ) {
			$order->add_order_note( sprintf( 'Payment link (%s) is not revoked automatically during order cancellation.', $paylink_id ) );
		}
	}

	/**
	 * Revoke pay-by-link when an order is trashed or deleted.
	 *
	 * This reuses the same cancellation revoke flow, so trash/delete actions
	 * behave consistently with manually setting order status to cancelled.
	 *
	 * @param int $order_id Order ID from WordPress/WooCommerce lifecycle hooks.
	 * @return void
	 */
	public function maybe_revoke_pay_by_link_on_trash_or_delete( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		// Avoid duplicate revoke calls when both WordPress and WooCommerce hooks fire in the same request.
		static $processed_order_ids = array();
		$order_id = (int) $order_id;
		if ( in_array( $order_id, $processed_order_ids, true ) ) {
			return;
		}
		$processed_order_ids[] = $order_id;

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->maybe_revoke_pay_by_link_on_cancel( $order->get_id(), $order );
	}

	/**
	 * Revoke pay-by-link automatically when admin changes payment method away from Mastercard.
	 *
	 * @param WC_Order $order Order object being saved.
	 * @return void
	 */
	public function maybe_revoke_pay_by_link_on_payment_method_change( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
			return;
		}

		// Only run in admin context — not on frontend order-pay page.
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$changes = $order->get_changes();
		if ( ! array_key_exists( 'payment_method', $changes ) ) {
			return;
		}

		$new_payment_method = (string) $changes['payment_method'];
		if ( MG_ENTERPRISE_ID === $new_payment_method ) {
			return;
		}

		// Active Mastercard session check.
		// Only continue if the order has an active Mastercard session 
		$has_active_mastercard_session = $order->get_meta( '_pay_by_link_id' )
			|| $order->get_meta( '_mpgs_session_id' );
		if ( ! $has_active_mastercard_session ) {
			return;
		}

		if ( $this->remove_handling_fee_from_order( $order ) ) {
			$order->calculate_totals( false );
			$order->save();
		}

		$this->maybe_revoke_pay_by_link_on_cancel( $order->get_id(), $order );
	}

	/**
	 * Registers the "Payment Link" meta box on the WooCommerce order edit screen.
	 *
	 * This meta box is displayed only when the order has a "pending" status.
	 * It allows administrators to generate or manage a payment link associated
	 * with the specific WooCommerce order.
	 *
	 * @return void
	 */
	public function unpaid_order_payment_link_meta_box() {
		$order_id = 0;
		if ( isset( $_GET['id'] ) ) {
			$order_id = absint( wc_clean( wp_unslash( $_GET['id'] ) ) );
		} elseif ( isset( $_GET['post'] ) ) {
			$order_id = absint( wc_clean( wp_unslash( $_GET['post'] ) ) );
		}
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}  

	    if ( $order && $order->has_status( 'pending' ) ) {
			add_meta_box(
				'mastercard-payment-link',
				/* Translators: %s order type name. */
				sprintf( __( 'Payment link', MG_ENTERPRISE_TEXTDOMAIN ) ),
				array( $this, 'unpaid_order_payment_link_meta_box_content' ),
				'woocommerce_page_wc-orders',
				'side',
				'high'
			);

			// Legacy WooCommerce order edit screen support.
			add_meta_box(
				'mastercard-payment-link',
				/* Translators: %s order type name. */
				sprintf( __( 'Payment link', MG_ENTERPRISE_TEXTDOMAIN ) ),
				array( $this, 'unpaid_order_payment_link_meta_box_content' ),
				'shop_order',
				'side',
				'high'
			);
		}
	}

	/**
	 * Callback to revoke an existing payment link and generate a fresh one.
	 *
	 * This method:
	 * 1. Validates the request and permissions.
	 * 2. Revokes the existing pay-by-link (if available).
	 * 3. Generates a new payment link.
	 * 4. Adds relevant order notes.
	 * 5. Redirects back to the order page.
	 *
	 * @return void
	 */
	public function regenerate_payment_callback() {
		if ( empty( $_GET['order_id'] ) ) {
			wp_die( esc_html__( 'Order ID missing.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$order_id = absint( $_GET['order_id'] );

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Invalid Order ID.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', MG_ENTERPRISE_TEXTDOMAIN ) );
		}

		$paylink_id = $order->get_meta( '_pay_by_link_id', true );
		$gateway = GatewayController::get_instance();

		if ( ! empty( $paylink_id ) ) {
			try {
				$gateway->init_service()->RevokePaymentLink(
					[
						'id'      => $order_id,
						'paylink' => $paylink_id,
					]
				);

				$order->delete_meta_data( '_pay_by_link_id' );

				$order->add_order_note(
					__( 'Previous payment link revoked successfully.', MG_ENTERPRISE_TEXTDOMAIN )
				);

			} catch ( Exception $e ) {
				$order->add_order_note(
					sprintf(
						__( 'Failed to revoke old payment link: %s', MG_ENTERPRISE_TEXTDOMAIN ),
						$e->getMessage()
					)
				);
			}
		} else {
			$order->add_order_note(
				__( 'No previous payment link found. Generating a new one.', MG_ENTERPRISE_TEXTDOMAIN )
			);
		}

		try {
			$response = $gateway->init_service()->GenerateSecureURL(
				[
					'id' => $order_id,
					'currency' => $order->get_currency(),
					'amount'   => (float) $order->get_total(),
				]
			);
			if ( empty( $response['paymentLink']['url'] ) ) {
				throw new Exception( 'Payment link URL missing in API response.' );
			}
			if ( ! empty( $response['paymentLink']['id'] ) ) {
				$order->update_meta_data( '_pay_by_link_id', $response['paymentLink']['id'] );
			}
			$order->save();
			$order->add_order_note(
				sprintf(
					__( 'A new secure payment link has been generated', MG_ENTERPRISE_TEXTDOMAIN ),
					esc_url( $response['paymentLink']['url'] ),
					esc_url( $response['paymentLink']['url'] )
				)
			);
			$order->add_order_note(
				sprintf(
					__( 'The previous payment link was revoked and a new one has been generated.', MG_ENTERPRISE_TEXTDOMAIN ),
					esc_url( $response['paymentLink']['url'] ),
					esc_url( $response['paymentLink']['url'] )
				)
			);

		} catch ( Exception $e ) {
			$order->add_order_note(
				sprintf(
					__( 'Failed to generate new payment link: %s', MG_ENTERPRISE_TEXTDOMAIN ),
					$e->getMessage()
				)
			);
			wp_die(
				esc_html__(
					'An error occurred while generating the new payment link. Please check the order notes for details.',
					MG_ENTERPRISE_TEXTDOMAIN
				)
			);
		}

		$redirect_url = add_query_arg(
			'mpgs_link_regenerated',
			! empty( $response['mail_sent'] ) ? '1' : '0',
			admin_url( "post.php?post={$order_id}&action=edit" )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Recalculate and persist order totals before generating a payment link.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	private function recalculate_order_before_payment_link_generation( WC_Order $order ) {
		$order->calculate_totals();
		$order->save();
		$order->add_order_note(
			__( 'Order recalculated.', MG_ENTERPRISE_TEXTDOMAIN )
		);
	}
}
