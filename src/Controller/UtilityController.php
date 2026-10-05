<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Shared plugin URL and REST route helpers.
 *
 * @package Fingent\Mastercard\Controller
 */

namespace Fingent\Mastercard\Controller;

use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Helper\RestAuthHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Utility helpers for REST URLs, scripts, and order context.
 */
class UtilityController {
	/**
	 * Singleton instance.
	 *
	 * @var UtilityController|null
	 */
	private static ?UtilityController $instance = null;

	/**
	 * Gateway Service
	 *
	 * @var MastercardGateway
	 */
	protected MastercardGateway $gateway;

	/**
	 * UtilityController Instance.
	 *
	 * @return UtilityController instance.
	 */
	public static function get_instance(): UtilityController {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * UtilityController constructor.
	 *
	 * @throws \Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() {
		$this->gateway = MastercardGateway::get_instance();
	}

	/**
	 * Get the plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', MG_ENTERPRISE_MAIN_FILE ) );
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
	 * Order-scoped REST token (safe for browser; not merchant API credentials).
	 *
	 * @param int $for_order_id Order ID.
	 * @return string
	 */
	public function get_order_rest_token( $for_order_id ) {
		return RestAuthHelper::create_order_rest_token( $for_order_id );
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
	 * Get the hosted checkout JavaScript code.
	 *
	 * @return string The JavaScript code for the hosted checkout.
	 */
	public function get_hosted_checkout_js() {

		return sprintf(
			'https://%s/static/checkout/checkout.min.js',
			$this->gateway->get_gateway_url()
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
			$this->gateway->get_gateway_url(),
			MG_ENTERPRISE_API_VERSION,
			$this->gateway->get_merchant_id()
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
			$this->gateway->get_gateway_url()
		);
	}

	/**
	 * Return WooCommerce order id.
	 *
	 * @return int|null Order id.
	 */
	public function get_order_id() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}

		if ( 'yes' !== $this->is_hpos() ) {
			$post = $GLOBALS['post'] ?? null;
			if ( $post instanceof \WP_Post && 'shop_order' === $post->post_type ) {
				return (int) $post->ID;
			}

			return null;
		}

		$order_id = filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT );

		return $order_id ? (int) $order_id : null;
	}

	/**
	 * Confirm whether HPOS has been enabled or not.
	 *
	 * @return string `yes` or `no`.
	 */
	public function is_hpos() {
		return OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';
	}
}
