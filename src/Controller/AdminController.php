<?php
namespace Fingent\Mastercard\Controller;

use WC_Order;
use WP_Error;
use WC_Admin_Settings;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Controller\GatewayController;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Logger\ApiErrorPlugin;
use Fingent\Mastercard\Logger\ApiLoggerPlugin;
use Fingent\Mastercard\Logger\GatewayResponseException;

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
	 * Gateway service instance.
	 *
	 * @var GatewayController
	 */
	protected $service;

	/**
	 * AdminController
	 *
	 * @var AdminController
	*/

	protected $handling_exceeded = false;

	/**
	 * AdminController
	 *
	 * @var AdminController
	*/
	protected $surcharge_exceeded = false;

	/**
	 * AdminController
	 *
	 * @var AdminController
	 */
	protected static $fee_error_added = false;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_filter( 'plugin_action_links_' . MG_ENTERPRISE_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_filter( 'woocommerce_admin_order_should_render_refunds', array( $this, 'admin_order_should_render_refunds' ), 10, 3 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'transaction_mode_columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'transaction_mode_column_data' ), 10, 2 );
		add_action( 'woocommerce_order_action_mpgs_capture_payment', array( $this, 'process_capture' ) );
		add_action( 'woocommerce_order_action_mpgs_void_payment', array( $this, 'void_authorized_order' ) );
		add_action( 'wp_ajax_get_preview_config', array( $this, 'get_preview_config' ) );
		add_action( 'wp_ajax_save_preview_config', array( $this, 'save_preview_config' ) );
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
				$this->verify_fee_amount( $settings, HF_AMT_TYPE_TXT, HF_AMT_TXT , HF_ERROR_MSG );
				$this->verify_fee_amount( $settings, SUR_AMT_TYPE_TXT, SUR_AMT_TXT , SUR_ERROR_MSG );
				$this->show_fee_error();
				$this->capture_gateway_data( $settings );
			}
	
			$response = $gateway->init_service()->paymentOptionsInquiry();
			if ( $response['result'] === 'ERROR' ) {
				wp_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $this->id ) );
				throw new GatewayResponseException( $response['error']['explanation'] );
			}
		} catch ( \Fingent\Mastercard\Logger\GatewayResponseException $e ) {
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
			'percentage' === $settings[ $fee_type_text ] &&
			floatval( $settings[ $fee_amount_txt ] ) > 99.9
		) {
			// Update flags
			if ( $fee_type_text === HF_AMT_TYPE_TXT ) {
				$this->handling_exceeded = true;
			} elseif ( $fee_type_text === SUR_AMT_TYPE_TXT ) {
				$this->surcharge_exceeded = true;
			}

			// Reset value
			$this->gateway->update_option( $fee_amount_txt, 99.9 );
		}
	}

	public function show_fee_error() {
		if ( self::$fee_error_added ) {
			return; 
		}

		if ( $this->handling_exceeded && $this->surcharge_exceeded ) {
			WC_Admin_Settings::add_message( __( HF_SUR_ERROR_MSG , MG_ENTERPRISE_TEXTDOMAIN ) );
			self::$fee_error_added = true;
		} elseif ( $this->handling_exceeded ) {
			WC_Admin_Settings::add_message( __( HF_ERROR_MSG , MG_ENTERPRISE_TEXTDOMAIN ) );
			self::$fee_error_added = true;
		} elseif ( $this->surcharge_exceeded ) {
			WC_Admin_Settings::add_message( __( SUR_ERROR_MSG , MG_ENTERPRISE_TEXTDOMAIN ) );
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
			'authorize'		 => 'Authorize',
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
}