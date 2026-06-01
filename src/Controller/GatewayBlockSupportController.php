<?php
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
     * Instance of the gateway class.
     *
     * @var GatewayController
     */
    protected $gateway;

	/**
	 * Mastercard_Gateway_Blocks constructor.
	 *
	 * @throws Exception If there's a problem connecting to the gateway.
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
     * @return array New value but with defaults initially filled in for missing settings.
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
     * @return array New payment gateway data but with defaults initially filled in for missing settings.
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->gateway->title,
            'description' => $this->gateway->description,
        );
    }
}
