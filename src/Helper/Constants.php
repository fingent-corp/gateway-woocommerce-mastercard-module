<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Plugin-wide constant definitions for the Mastercard gateway.
 *
 * @package Fingent\Mastercard\Helper
 */

namespace Fingent\Mastercard\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Defines Mastercard gateway constants used across the plugin.
 */
class Constants {
	/**
	 * The single instance of the class.
	 *
	 * @var self|null
	 */
	protected static ?Constants $instance = null;

	/**
	 * Main Constants Instance.
	 *
	 * @static
	 * @return self
	 */
	public static function get_instance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @throws \Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() {
		$this->define_constants();
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param string      $name  Constant name.
	 * @param string|bool $value Constant value.
	 * @return void
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Define Mastercard gateway constants.
	 *
	 * @return void
	 */
	private function define_constants() {
		$wiki_domain = 'https://uat-wiki.fingent.net';
		$wiki_base_url = $wiki_domain . '/enterprise/woocommerce-mastercard-gateway/';

		$this->define( 'MG_ENTERPRISE_ID', 'mastercard_gateway' );
		$this->define( 'MG_ENTERPRISE_GATEWAY_TITLE', 'Mastercard Gateway' );
		$this->define( 'MG_ENTERPRISE_MODULE_VERSION', '1.5.4' );
		$this->define( 'MG_ENTERPRISE_CAPTURE_URL', $wiki_domain . '/wp-json/mpgs/v2/update-repo-status/' );
		$this->define( 'MG_ENTERPRISE_SUPPORT_URL', 'https://mpgsfgs.atlassian.net/servicedesk/customer/portals/' );
		$this->define( 'MG_ENTERPRISE_WIKI_URL', $wiki_base_url . 'installation/' );
		$this->define( 'MG_ENTERPRISE_WIKI_CONFIG_URL', $wiki_base_url . 'configuration/api-configuration/' );
		$this->define( 'MG_ENTERPRISE_WIKI_WEBHOOK_URL', $wiki_base_url . 'configuration/api-configuration/#webhooksecret' );
		$this->define( 'MG_ENTERPRISE_API_VERSION', 'version/100' );
		$this->define( 'MG_ENTERPRISE_API_VERSION_NUM', '100' );
		$this->define( 'MG_ENTERPRISE_HC_URL', $wiki_base_url . 'integration-model/hosted-checkout-integration/' );
		$this->define( 'MG_ENTERPRISE_HS_URL', $wiki_base_url . 'integration-model/hosted-session-integration/' );

		/**
		 * These Constants are utilized for the Integration model.
		 */
		$this->define( 'HOSTED_SESSION', 'hosted-session' );
		$this->define( 'HOSTED_CHECKOUT', 'hosted-checkout' );

		/**
		 * These Constants are utilized for the Pay By Link.
		 */
		$this->define( 'PAY_BY_LINK_TEMPLATE', 'email/payment-request.php' );
		$this->define( 'PAY_BY_LINK_REVOKED_TEMPLATE', 'email/payment-revoked.php' );
		$this->define( 'PAY_BY_LINK_PLAIN_TEMPLATE', 'email/plain-payment-request.php' );
		$this->define( 'PAY_BY_LINK_REVOKED_PLAIN_TEMPLATE', 'email/plain-payment-revoked.php' );
		$this->define( 'PAY_BY_LINK_BASE', MG_ENTERPRISE_DIR_PATH . 'templates/' );
		$this->define( 'PAY_BY_LINK_EMAIL_HEADING', __( 'Pay By Link request order', 'mastercard-gateway' ) );
		$this->define( 'PAY_BY_LINK_EMAIL_DESCRIPTION', __( 'This email sends the Pay By Link request to the customer.', 'mastercard-gateway' ) );
		$this->define( 'PAY_BY_LINK_EMAIL_SUBJECT', __( 'Pay By Link request order', 'mastercard-gateway' ) );
		$this->define( 'PAY_BY_LINK_REVOKED_EMAIL_HEADING', __( 'Pay By Link revoked order', 'mastercard-gateway' ) );
		$this->define( 'PAY_BY_LINK_REVOKED_EMAIL_DESCRIPTION', __( 'This email sends the Pay By Link is revoked.', 'mastercard-gateway' ) );
		$this->define( 'PAY_BY_LINK_REVOKED_EMAIL_SUBJECT', __( 'Pay By Link revoked order', 'mastercard-gateway' ) );

		/**
		 * These Constants are utilized for the Checkout Interaction.
		 */
		$this->define( 'HC_TYPE_REDIRECT', 'redirect' );
		$this->define( 'HC_TYPE_EMBEDDED', 'embedded' );

		/**
		 * These Constants are utilized for the Gateway type.
		 */
		$this->define( 'API_EU', 'eu-gateway.mastercard.com' );
		$this->define( 'API_AS', 'ap-gateway.mastercard.com' );
		$this->define( 'API_NA', 'na-gateway.mastercard.com' );
		$this->define( 'API_CUSTOM', 'custom' );

		/**
		 * These Constants are utilized for the Transaction Mode.
		 */
		$this->define( 'TXN_MODE_PURCHASE', 'capture' );
		$this->define( 'TXN_MODE_AUTH_CAPTURE', 'authorize' );

		/**
		 * These Constants are utilized for the Handling Fee.
		 */
		$this->define( 'HF_FIXED', 'fixed' );
		$this->define( 'HF_PERCENTAGE', 'percentage' );
		$this->define( 'HF_FEE_OPTION', 'mpgs_handling_fee' );
		$this->define( 'HF_FEE_VAR', '_mpgs_handling_fee' );
		$this->define( 'HF_AMT_TYPE_TXT', 'hf_amount_type' );
		$this->define( 'HF_AMT_TXT', 'handling_fee_amount' );
		$this->define( 'HF_TEXT', 'handling_text' );
		$this->define( 'HF_DEFAULT_TEXT', __( 'Handling Fee', 'mastercard-gateway' ) );
		$this->define( 'HF_ERROR_MSG', 'Handling fee percentage is restricted to a maximum of 99.9' );

		/**
		 * These Constants are utilized for the Surcharge.
		 */
		$this->define( 'SUR_ENABLED', 'surcharge_enabled' );
		$this->define( 'SUR_TEXT', 'surcharge_text' );
		$this->define( 'SUR_AMT_TYPE_TXT', 'surcharge_amount_type' );
		$this->define( 'SUR_AMT_TXT', 'surcharge_amount' );
		$this->define( 'SUR_CARD_TYPE', 'surcharge_card_type' );
		$this->define( 'SUR_MSG', 'surcharge_message' );
		$this->define( 'SUR_DEBIT', __( 'Debit', 'mastercard-gateway' ) );
		$this->define( 'SUR_CREDIT', __( 'Credit', 'mastercard-gateway' ) );
		$this->define( 'SUR_ERROR_MSG', 'Surcharge fee percentage  is restricted to a maximum of 99.9' );
		$this->define( 'HF_SUR_ERROR_MSG', 'Surcharge and Handling fee percentage  is restricted to a maximum of 99.9' );

		$this->define( 'SUR_DEFAULT_MSG', __( 'When using a {{MG_CARD_TYPE}} an additional surcharge of <b>{{MG_SUR_AMT}} ({{MG_SUR_PCT}})</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.', 'mastercard-gateway' ) );

		/**
		 * These Constants are utilized for the 3D-Secure.
		 */
		$this->define( 'THREED_DISABLED', 'no' );
		$this->define( 'THREED_V1', 'yes' );
		$this->define( 'THREED_V2', '2' );
	}
}
