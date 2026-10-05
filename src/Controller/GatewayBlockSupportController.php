<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * WooCommerce Blocks payment method integration.
 *
 * @package Fingent\Mastercard\Controller
 */

namespace Fingent\Mastercard\Controller;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Controller\UtilityController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Mastercard_Gateway_Blocks.
 *
 * @since 1.4.5
 */
final class GatewayBlockSupportController extends AbstractPaymentMethodType {
	/**
	 * Gateway settings from the database.
	 *
	 * @var array<string, mixed>
	 */
	protected $settings = array();

	/**
	 * Instance of the gateway class.
	 *
	 * @var MastercardGateway
	 */
	protected $gateway;

	/**
	 * Mastercard_Gateway_Blocks constructor.
	 */
	public function __construct() {}

	/**
	 * Initialize Mastercard_Gateway_Blocks.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . MG_ENTERPRISE_ID . '_settings', array() );
		$this->gateway  = new MastercardGateway();
	}

	/**
	 * Confirm whether the Mastercard Gateway is currently active.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}
	/**
	 * Confirm whether the Mastercard Gateway is currently active.
	 *
	 * @return array<int, string> Registered script handles.
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			MG_ENTERPRISE_ID . '-blocks-integration',
			UtilityController::plugin_url() . '/assets/js/checkout.js',
			array(
				'wp-hooks',
				'wc-blocks-checkout',
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			MG_ENTERPRISE_MODULE_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( MG_ENTERPRISE_ID . '-blocks-integration' );
		}

		return array( MG_ENTERPRISE_ID . '-blocks-integration' );
	}
	/**
	 * Prepare the setup for the Mastercard payment gateway data.
	 *
	 * @return array<string, mixed> Payment method data for WooCommerce Blocks.
	 */
	public function get_payment_method_data() {
		return array(
			'title'             => $this->gateway->title,
			'description'       => $this->gateway->description,
			'checkoutAjaxNonce' => wp_create_nonce( 'mastercard_checkout_ajax' ),
		);
	}
}
