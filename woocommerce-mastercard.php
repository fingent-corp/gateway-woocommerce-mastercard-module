<?php
/**
 * Plugin Name: Mastercard Gateway
 * Description: Accept payments on your WooCommerce store using the Mastercard Gateway. Requires PHP 8.1+ & WooCommerce 8.7+
 * Plugin URI: https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 * Author: Fingent Global Solutions Pvt. Ltd.
 * Author URI: https://www.fingent.com/
 * Tags: payment, payment-gateway, mastercard, mastercard-payements, mastercard-gateway, woocommerce-plugin, woocommerce-payment, woocommerce-extension, woocommerce-shop, mastercard, woocommerce-api, woocommerce-blocks
 * Version: 1.5.0.1
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Tested up to: 6.7.1
 * Requires PHP: 7.4
 * php version 7.4
 *
 * WC requires at least: 8.7
 * WC tested up to: 9.7.1
 *
 * @package  Mastercard
 * @version  GIT: @1.5.0.1@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Internal\Features\FeaturesController;

/**
 * Main class of the Mastercard Payment Gateway Services Module
 *
 * @package  Mastercard
 * @version  Release: @1.5.0.1@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */
class WC_Mastercard {

	/**
	 * The single instance of the class.
	 *
	 * @var WC_Mastercard
	 */
	private static $instance = null;

	/**
	 * WC_Mastercard constructor.
	 */
	public function __construct() { 
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'clean_output_buffer' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_cart_checkout_blocks_compatibility' ) );
		if ( ! $this->is_order_pay_page() ) {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'woocommerce_gateway_mastercard_woocommerce_block_support' ), 99 );
		}
	}

	/**
	 * Clear output bufffer.
	 *
	 * @version 1.0
	 * @package Helpfie
	 */
	public function clean_output_buffer() {
		ob_start();
		load_plugin_textdomain( 'mastercard', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) . 'i18n/' );
	}

	/**
	 * Hook into actions and filters.
	 *
	 * @return void
	 */
	public function init() {
		define( 'MPGS_TARGET_PLUGIN_FILE', __FILE__ );
		define( 'MPGS_TARGET_PLUGIN_BASENAME', plugin_basename( MPGS_TARGET_PLUGIN_FILE ) );
		add_action( 'admin_init', array( $this, 'stop' ) );

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		define( 'MPGS_ISO3_COUNTRIES', include plugin_basename( '/iso3.php' ) );
		require_once plugin_basename( '/vendor/autoload.php' );
		require_once plugin_basename( '/includes/class-gateway.php' );
		require_once plugin_basename( '/includes/class-gateway-notification.php' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended
		add_filter(
			'woocommerce_order_actions',
			function ( $actions ) {
				$order_id     = self::get_order_id();
				if ( $order_id ) {
					$order = new WC_Order( $order_id );

					if ( $order->get_payment_method() === Mastercard_Gateway::ID
						 && ! $order->get_meta( '_mpgs_order_captured' )
						 && $order->get_status() == 'processing'
					) {
						$actions[ 'mpgs_capture_payment' ] = __(
							'Capture Authorized Amount',
							'mastercard'
						);
						$actions[ 'mpgs_void_payment' ] = __(
							'Void',
							'mastercard'
						);
					}
				}
				return $actions;
			}
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'mastercard/v1',
					'/checkoutSession/(?P<id>\d+)',
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => array(
							'id' => array(
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_numeric( $param );
								},
							),
						),
					)
				);
				register_rest_route(
					'mastercard/v1',
					'/session/(?P<id>\d+)',
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => array(
							'id' => array(
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_numeric( $param );
								},
							),
						),
					)
				);
				register_rest_route(
					'mastercard/v1',
					'/savePayment/(?P<id>\d+)',
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => array(
							'id' => array(
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_numeric( $param );
								},
							),
						),
					)
				);			
				register_rest_route(
					'mastercard/v1',
					'/webhook',
					array(
						'methods'             => 'POST',
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'callback'            => array( $this, 'rest_route_forward' ),
					)
				);
			}
		);
		add_action( 'admin_head', array( $this, 'mpgs_admin_styles' ) );
		add_filter( 'woocommerce_admin_order_should_render_refunds', array( $this, 'admin_order_should_render_refunds' ), 10, 3 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'transaction_mode_columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'transaction_mode_column_data' ), 10, 2 );
	}

	/**
	 * Get the plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', MPGS_TARGET_PLUGIN_FILE ) );
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
	 * If WooCommerce is not active return error messgae.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function stop() {
		if ( ! $this->woocommerce_is_active() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			unset( $_GET['activate'] );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		}
	}

	/**
	 * Apply Custom CSS to Admin Area.
	 *
	 * @since 1.4.5
	 *
	 * @return void
	 */
	public function admin_notices() {
		$class   = 'notice notice-error';
		$message = __( 'Kindly ensure the WooCommerce plugin is active before activating the Mastercard Payment Gateway Services plugin.', 'mastercard' );
		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
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
	public function admin_order_should_render_refunds( $render_refunds, $order_id, $order ) { 
		if( 
			( 'mpgs_gateway' === $order->get_payment_method() && 'refunded' === $order->get_status() ) || 
			( 'mpgs_gateway' === $order->get_payment_method() && empty( get_post_meta( $order_id, '_mpgs_order_captured', true ) ) ) 
		) {
			return false;
		}

		return $render_refunds;
	}

	/**
	 * Permission check.
	 *
	 * @param boolean $request Request.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore
		return true;
	}

	/**
	 * Check the rest_route_forward.
	 *
	 * @param array $request WP_REST_Request.
	 *
	 * @return array Rest route processor.
	 * @throws Mastercard_GatewayResponseException It triggers a Mastercard_GatewayResponseException in the absence of a REST API route.
	 * @throws \Http\Client\Exception It triggers a Exception in the absence of a REST API route.
	 */
	public function rest_route_forward( $request ) {
		$gateway = new Mastercard_Gateway();
		return $gateway->rest_route_processor( $request->get_route(), $request );
	}

	/**
	 * Add Mastercard_Gateway to WooCommerce
	 *
	 * @param array $methods Getway method array.
	 *
	 * @return array
	 */
	public function add_gateways( $methods ) {
		$methods[] = 'Mastercard_Gateway';

		return $methods;
	}

	/**
	 * Main Mastercard Instance.
	 *
	 * @return WC_Mastercard - Main instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin activation hook
	 *
	 * @return void
	 */
	public static function activation_hook() {
		$environment_warning = self::get_env_warning();
		if ( $environment_warning ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_attr( $environment_warning ) );
		}
	}

	/**
	 * Get get_env_warning.
	 *
	 * @return bool
	 */
	public static function get_env_warning() {
		// @todo: Add some php version and php library checks here
		return false;
	}

	/**
	 * Included the plugin's helper links.
	 *
	 * @param array $links Plugin action links.
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="https://mpgsfgs.atlassian.net/servicedesk/customer/portals/">' . __( 'Support', 'mastercard-payment-gateway-services' ) . '</a>' );
		array_unshift( $links, '<a href="https://mpgs.fingent.wiki/target/woocommerce-mastercard-payment-gateway-services/installation/">' . __( 'Docs', 'mastercard-payment-gateway-services' ) . '</a>' );
		array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mpgs_gateway' ) . '">' . __( 'Settings', 'mastercard-payment-gateway-services' ) . '</a>' );

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
	public static function plugin_row_meta( $links, $file ) {
		if ( MPGS_TARGET_PLUGIN_BASENAME !== $file ) {
			return $links;
		}

		/**
		 * The MPGS documentation URL.
		 *
		 * @since 1.4.0
		 */
		$docs_url = apply_filters( 'mastercard_docs_url', 'https://mpgs.fingent.wiki/target/woocommerce-mastercard-payment-gateway-services/installation/' );

		/**
		 * The Mastercard Support URL.
		 *
		 * @since 1.4.0
		 */
		$support_url = apply_filters( 'mastercard_support_url', 'https://mpgsfgs.atlassian.net/servicedesk/customer/portals/' );

		$row_meta = array(
			'docs'    => '<a href="' . esc_url( $docs_url ) . '" aria-label="' . esc_attr__( 'View mastercard documentation', 'mastercard-payment-gateway-services' ) . '">' . esc_html__( 'Docs', 'mastercard-payment-gateway-services' ) . '</a>',
			'support' => '<a href="' . esc_url( $support_url ) . '" aria-label="' . esc_attr__( 'Visit mastercard support', 'mastercard-payment-gateway-services' ) . '">' . esc_html__( 'Support', 'mastercard-payment-gateway-services' ) . '</a>',
		);

		return array_merge( $links, $row_meta );
	}

	/**
	 * Return WooCommerce order id.
	 *
	 * @return int Order id.
	 */
	public static function get_order_id() {
		if ( 'yes' !== self::is_hpos() ) {
			$order_id = isset( $_REQUEST['post'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['post'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			$order_id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return $order_id;
	}

	/**
	 * Confirm whether HPOS has been enabled or not.
	 *
	 * @return bool HPOS.
	 */
	public static function is_hpos() {
		return OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';
	}

	/**
	 * Enqueue custom styles for the admin dashboard related to the Mastercard Payment Gateway Services (MPGS).
	 *
	 * This function is used to add custom CSS styles to the WordPress admin area, specifically for pages
	 * that handle Mastercard Payment Gateway Services (MPGS) settings or features. It ensures that the 
	 * admin UI for these pages is styled according to the requirements of the plugin.
	 *
	 * @since 1.4.5
	 */
	public function mpgs_admin_styles() {
		echo '<style>
		    .mgps-transaction-mode {
		    	display: inline-flex;
				line-height: 2.5em;
				color: #454545;
				background: #e5e5e5;
				border-radius: 4px;
				border-bottom: 1px solid rgba(0,0,0,.05);
				margin: -.25em 0;
				cursor: inherit !important;
				white-space: nowrap;
				max-width: 100%;
		    }
		    .mgps-transaction-mode > span {
				margin: 0 1em;
				overflow: hidden;
				text-overflow: ellipsis;
			}
			.mgps-transaction-mode.mpgs-purchase {
				background: #c6e1c6;
			  	color: #2c4700;
			}
			.mgps-transaction-mode.mpgs-authorize {
				background: #d1ecf1;
			  	color: #0c5460;
			}
			.mgps-transaction-mode.mpgs-captured {
				background: #fff3cd;
			  	color: #856404;
			}
			.mgps-transaction-mode.mpgs-void {
				color: #721c24;
  				background-color: #f8d7da;
			}
			.woocommerce table.form-table input[type="text"], .woocommerce table.form-table input[type="password"] {
				width: 500px;
			}
			.woocommerce table.form-table .webhook_wrap {
				position: relative;
				width: max-content;
			}
			.woocommerce table.form-table .webhook_wrap .dashicons-admin-page {
				position: absolute;
				top: 7px;
				right: 6px;
				color: #666;
				cursor: pointer;
			}
	  	</style>';
	}
	/**
	 * Is_order_pay_page - Returns true when viewing the order received page.
	 *
	 * @return bool
	 */
	public function is_order_pay_page() {
		return isset( $_GET['key'] ) && isset( $_SERVER['REQUEST_URI'] ) && strpos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'order-pay' ) !== false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Function to declare compatibility with cart_checkout_blocks feature.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public function declare_cart_checkout_blocks_compatibility() { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	/**
	 * Function to register the Mastercard payment method type.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public function woocommerce_gateway_mastercard_woocommerce_block_support() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'includes/class-gateway-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new Mastercard_Gateway_Blocks_Support() );
			}
		);

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'mpgs_gateway_handling_fee',
				'callback'  => function( $data ) {
					Mastercard_Gateway::refresh_handling_fees_on_checkout_block();
				},
			)
		);
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
	    $columns['mgps_transaction'] = 'Transaction Mode';
	    return $columns;
	}

	/**
	 * Callback function to display data in the custom 'transaction_mode' column in the WooCommerce Orders List Table.
	 *
	 * @param string $column The name of the column currently being processed.
	 * @param WC_Order $order The order object for the current row being displayed.
	 */
	public function transaction_mode_column_data( $column, $order ) {	
	    if ( 'mgps_transaction' === $column ) {
	        switch ( $order->get_meta( '_mpgs_transaction_mode' ) ) {
	        	case 'capture':
	        		$transaction_mode = '<mark class="mgps-transaction-mode mpgs-purchase"><span>Purchase</span></mark>';
	        		break;
	        	
	        	case 'authorize':
	        		$transaction_mode = '<mark class="mgps-transaction-mode mpgs-authorize"><span>Authorize</span></mark>';
	        		break;

	        	case 'captured':
	        		$transaction_mode = '<mark class="mgps-transaction-mode mpgs-captured"><span>Captured</span></mark>';
	        		break;	

	        	case 'void':
	        		$transaction_mode = '<mark class="mgps-transaction-mode mpgs-void"><span>Void</span></mark>';	
	        		break;	

	        	case 'refund':
	        		$transaction_mode = '<mark class="mgps-transaction-mode mpgs-void"><span>Refunded</span></mark>';	
	        		break;
	        			
	        	default:
	        		$transaction_mode = '<mark class="mgps-transaction-mode mpgs-na"><span>N/A</span></mark>';
	        		break;
	        }

	        echo $transaction_mode;
	    }
	}
}

WC_Mastercard::get_instance();
register_activation_hook( __FILE__, array( 'WC_Mastercard', 'activation_hook' ) );
