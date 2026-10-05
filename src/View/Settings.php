<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * WooCommerce admin settings field definitions for the gateway.
 *
 * @package Fingent\Mastercard\View
 */

namespace Fingent\Mastercard\View;

use Fingent\Mastercard\Helper\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * Main class of the Mastercard Gateway Settings Module
 */
class Settings {
	/**
	 * The single instance of the class.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Main Settings Instance.
	 *
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
	public function __construct() {}

	/**
	 * Get settings or the Mastercard Gateway payment section.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form_fields() {
		return array(
			'heading'                  => array(
				'title'       => null,
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1. MPGS module version, 2. MPGS API version. */
					__( '<b>Plugin version:</b> %1$s<br /><b>API version:</b> %2$s', 'mastercard-gateway' ),
					MG_ENTERPRISE_MODULE_VERSION,
					MG_ENTERPRISE_API_VERSION_NUM
				),
			),
			'enabled'                  => array(
				'title'       => __( 'Enable/Disable', 'mastercard-gateway' ),
				'label'       => __( 'Enable', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable to activate the configuration needed for this payment option as well as enabling the same in the checkout page.', 'mastercard-gateway' ),
				'default'     => 'no',
			),
			'title'                    => array(
				'title'       => __( 'Title', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the name to be displayed to customers at checkout for this payment method.', 'mastercard-gateway' ),
				'default'     => __( 'Mastercard Gateway', 'mastercard-gateway' ),
			),
			'description'              => array(
				'title'       => __( 'Description', 'mastercard-gateway' ),
				'type'        => 'text',
				'description' => __( 'Enter the description for this payment method as you want it to appear on the checkout page for customers.', 'mastercard-gateway' ),
				'default'     => 'Pay with your card via Mastercard.',
			),
			'integration_section'      => array(
				'title'       => __( 'Integration Settings', 'mastercard-gateway' ),
				'type'        => 'title',
				'description' => __( 'Configure core settings that control how the payment method integrates with your store.', 'mastercard-gateway' ),
			),
			'method'                   => array(
				'title'       => __( 'Integration Model', 'mastercard-gateway' ),
				'description' => sprintf(
					/* translators: 1: Hosted Checkout documentation URL, 2: Hosted Session documentation URL. */
					__( 'Choose the Integration Model - Hosted Checkout or Hosted Session. Learn more about <a href="%1$s" target="_blank">Hosted Checkout</a> / <a href="%2$s" target="_blank">Hosted Session</a>.', 'mastercard-gateway' ),
					MG_ENTERPRISE_HC_URL,
					MG_ENTERPRISE_HS_URL
				),
				'type'        => 'select',
				'options'     => array(
					HOSTED_CHECKOUT => __( 'Hosted Checkout', 'mastercard-gateway' ),
					HOSTED_SESSION  => __( 'Hosted Session', 'mastercard-gateway' ),
				),
				'default'     => HOSTED_CHECKOUT,
			),
			'txn_mode'                 => array(
				'title'       => __( 'Payment Action', 'mastercard-gateway' ),
				'type'        => 'select',
				'options'     => array(
					TXN_MODE_PURCHASE     => __( 'Purchase', 'mastercard-gateway' ),
					TXN_MODE_AUTH_CAPTURE => __( 'Authorize', 'mastercard-gateway' ),
				),
				'default'     => TXN_MODE_PURCHASE,
				'description' => __( 'In “Purchase”, the customer is charged immediately. In Authorize, the transaction is only reserved and the capturing of funds is a manual process that you do using the WooCommerce admin panel.', 'mastercard-gateway' ),
			),
			'threedsecure'             => array(
				'title'       => __( 'EMV 3-D Secure', 'mastercard-gateway' ),
				'label'       => __( 'Use 3D-Secure', 'mastercard-gateway' ),
				'type'        => 'select',
				'options'     => array(
					THREED_DISABLED => __( 'Disabled', 'mastercard-gateway' ),
					THREED_V1       => __( 'EMV 3-D Secure v1', 'mastercard-gateway' ),
					THREED_V2       => __( 'EMV 3-D Secure v2', 'mastercard-gateway' ),
				),
				'default'     => THREED_DISABLED,
				'description' => __( 'Select the security level for the user’s card during transactions.', 'mastercard-gateway' ),
			),
			'saved_cards'              => array(
				'title'       => __( 'Save Cards', 'mastercard-gateway' ),
				'label'       => __( 'Enable payment via saved tokenized cards', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, users can pay using saved cards during checkout. Payments are processed via tokenized cards, with card details securely stored in the payment gateway - not on your store.', 'mastercard-gateway' ),
				'default'     => 'yes',
			),
			'hc_interaction'           => array(
				'title'       => __( 'Checkout Interaction', 'mastercard-gateway' ),
				'type'        => 'select',
				'options'     => array(
					HC_TYPE_REDIRECT => __( 'Redirect to Payment Page', 'mastercard-gateway' ),
					HC_TYPE_EMBEDDED => __( 'Embedded Form', 'mastercard-gateway' ),
				),
				'default'     => HC_TYPE_EMBEDDED,
				'description' => __( 'Selecting "Redirect to Payment Page" will also allow you to configure your business logo and related information in the Merchant Information section below.', 'mastercard-gateway' ),
			),
			'gateway_section'          => array(
				'title'       => __( 'Gateway - API Credentials', 'mastercard-gateway' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: Gateway API credentials documentation URL. */
					__( 'Enter the API credentials required to connect with the Mastercard Gateway. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.', 'mastercard-gateway' ),
					MG_ENTERPRISE_WIKI_CONFIG_URL
				),
			),
			'sandbox'                  => array(
				'title'       => __( 'Test Mode', 'mastercard-gateway' ),
				'label'       => __( 'Enable test sandbox mode', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => __( ' Use this to enable Test mode with test credentials for testing purposes.', 'mastercard-gateway' ),
				'default'     => 'yes',
			),
			'custom_gateway_url'       => array(
				'title'       => __( 'Gateway URL', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the Gateway URL shared by your payment service provider. Enter the URL without https prefix. For example na.gateway.mastercard.com.', 'mastercard-gateway' ),
			),
			'username'                 => array(
				'title'       => __( 'Merchant ID', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter your Merchant ID.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'sandbox_username'         => array(
				'title'       => __( 'Test Merchant ID', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter your Test Merchant ID.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'password'                 => array(
				'title'       => __( 'API Password', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: Gateway API credentials documentation URL. */
					__( 'Enter the API Password obtained from your Mastercard Gateway account. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.', 'mastercard-gateway' ),
					MG_ENTERPRISE_WIKI_CONFIG_URL
				),
				'default'     => '',
			),
			'sandbox_password'         => array(
				'title'       => __( 'Test API Password', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: Gateway API credentials documentation URL. */
					__( 'Enter the Test API  Password obtained from your Mastercard Gateway account. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.', 'mastercard-gateway' ),
					MG_ENTERPRISE_WIKI_CONFIG_URL
				),
				'default'     => '',
			),
			'webhook_secret'           => array(
				'title'       => __( 'Webhook Secret', 'mastercard-gateway' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: Webhook secret documentation URL. */
					__( 'Enter the Webhook Secret from your Mastercard Gateway account. Learn how to access your <a href="%s" target="_blank">Webhook Secret</a>.', 'mastercard-gateway' ),
					MG_ENTERPRISE_WIKI_WEBHOOK_URL
				),
				'default'     => '',
			),
			'test_webhook_secret'      => array(
				'title'       => __( 'Test Webhook Secret', 'mastercard-gateway' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: Webhook secret documentation URL. */
					__( 'Enter the Test Webhook Secret from your Mastercard Gateway account. Learn how to access your <a href="%s" target="_blank">Webhook Secret</a>.', 'mastercard-gateway' ),
					MG_ENTERPRISE_WIKI_WEBHOOK_URL
				),
				'default'     => '',
			),
			'adtnl_cnf_details'        => array(
				'title'       => __( 'Additional Configurations', 'mastercard-gateway' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: Gateway API Credentials */
					__( 'Configure additional plugin parameters for customization.', 'mastercard-gateway' ),
					MG_ENTERPRISE_WIKI_CONFIG_URL
				),
			),
			'debug'                    => array(
				'title'       => __( 'Debug Logging', 'mastercard-gateway' ),
				'label'       => __( 'Enabled/Disabled', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable to log all communication with Mastercard Gateway to file ./wp-content/uploads/wc-logs/mastercard.log.', 'mastercard-gateway' ),
				'default'     => 'no',
			),
			'order_prefix'             => array(
				'title'       => __( 'Order ID Prefix', 'mastercard-gateway' ),
				'type'        => 'text',
				'description' => __( 'Specify the order ID prefix.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'send_line_items'          => array(
				'title'       => __( 'Send Line Items', 'mastercard-gateway' ),
				'label'       => __( 'Enable Send Line Items', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable to send detailed order information (line items) to the Mastercard Gateway. Disable this feature if your products are virtual or digital, as it is intended for physical goods only.', 'mastercard-gateway' ),
				'default'     => 'no',
			),
			'handling_fee'             => array(
				'title'       => __( 'Handling Fee', 'mastercard-gateway' ),
				'type'        => 'title',
				'description' => __( ' Enable to add the handling amount for the order, including taxes on the handling.', 'mastercard-gateway' ),
			),
			'hf_enabled'               => array(
				'title'       => __( 'Enable/Disable', 'mastercard-gateway' ),
				'label'       => __( 'Enable', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'handling_text'            => array(
				'title'       => __( 'Handling Fee Text', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( ' Enter the text to be displayed in the front-end checkout page.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'hf_amount_type'           => array(
				'title'       => __( 'Applicable Amount Type', 'mastercard-gateway' ),
				'type'        => 'select',
				'description' => __( 'Select either “Fixed” or “Percentage” from the dropdown menu to determine how the handling fee will be calculated.', 'mastercard-gateway' ),
				'options'     => array(
					HF_FIXED      => __( 'Fixed', 'mastercard-gateway' ),
					HF_PERCENTAGE => __( 'Percentage', 'mastercard-gateway' ),
				),
				'default'     => HF_FIXED,
			),
			'handling_fee_amount'      => array(
				'title'       => __( 'Amount', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the value to be applied to the subtotal.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'merchant_info'            => array(
				'title'       => __( 'Merchant Information', 'mastercard-gateway' ),
				'type'        => 'title',
				'description' => __( 'This section appears only when "Redirect to Payment Page" is selected for Checkout Interaction. Configuring the details in this section allows them to be displayed on Mastercard’s redirected payment page.', 'mastercard-gateway' ),
			),
			'mif_enabled'              => array(
				'title'       => __( 'Enable/Disable', 'mastercard-gateway' ),
				'label'       => __( 'Enable', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'merchant_name'            => array(
				'title'       => __( 'Merchant Name', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Name of your business (up to 40 characters) to be shown to the payer during the payment interaction.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'merchant_address_line1'   => array(
				'title'       => __( 'Address Line 1', 'mastercard-gateway' ),
				'type'        => 'text',
				'description' => __( 'The first line of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'merchant_address_line2'   => array(
				'title'       => __( 'Address Line 2', 'mastercard-gateway' ),
				'type'        => 'text',
				'description' => __( 'The second line of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'merchant_address_line3'   => array(
				'title'       => __( 'Postcode / ZIP', 'mastercard-gateway' ),
				'type'        => 'text',
				'description' => __( 'The postal or ZIP code of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'merchant_address_line4'   => array(
				'title'       => __( 'Country / State', 'mastercard-gateway' ),
				'type'        => 'text',
				'description' => __( 'The country or state of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'merchant_email'           => array(
				'title'       => __( 'Email', 'mastercard-gateway' ),
				'type'        => 'text',
				'description' => __( 'The email address of your business to be shown to the payer during the payment interaction. (e.g. an email address for customer service).', 'mastercard-gateway' ),
				'default'     => '',
			),
			'merchant_phone'           => array(
				'title'       => __( 'Phone', 'mastercard-gateway' ),
				'type'        => 'tel',
				'description' => __( 'The phone number of your business (up to 20 characters) to be shown to the payer during the payment interaction.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'merchant_logo'            => array(
				'title'       => __( 'Logo', 'mastercard-gateway' ),
				'type'        => '',
				'description' => __( 'The URL of your business logo (JPEG, PNG, or SVG) to be shown to the payer during the payment interaction.<br />The logo should be 140x140 pixels, and the URL must be secure (e.g., https://). Size exceeding 140 pixels will be auto resized.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'surcharge'                => array(
				'title'       => __( 'Surcharge', 'mastercard-gateway' ),
				'type'        => 'title',
				'description' => __( 'Enable to add additional charges for Debit/Credit card transactions.', 'mastercard-gateway' ),
			),
			'surcharge_enabled'        => array(
				'title'       => __( 'Enable/Disable', 'mastercard-gateway' ),
				'label'       => __( 'Enable', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'surcharge_card_type'      => array(
				'title'       => __( 'Applicable Card Type', 'mastercard-gateway' ),
				'type'        => 'select',
				'description' => __( 'Select the card type for which surcharge has to be added.', 'mastercard-gateway' ),
				'options'     => self::surcharge_card_type_options(),
				'default'     => SUR_CREDIT,
			),
			'surcharge_text'           => array(
				'title'       => __( 'Surcharge Text', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the text to display the surcharge breakdown on the \'Thank You\' page.', 'mastercard-gateway' ),
				'default'     => 'Surcharge',
			),
			'surcharge_amount_type'    => array(
				'title'       => __( 'Applicable Amount Type', 'mastercard-gateway' ),
				'type'        => 'select',
				'description' => __( 'Select either “Fixed” or “Percentage” from the dropdown menu to determine how the Surcharge fee will be calculated.', 'mastercard-gateway' ),
				'options'     => array(
					HF_FIXED      => __( 'Fixed', 'mastercard-gateway' ),
					HF_PERCENTAGE => __( 'Percentage', 'mastercard-gateway' ),
				),
				'default'     => HF_FIXED,
			),
			'surcharge_amount'         => array(
				'title'       => __( 'Amount', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the value to be calculated for Surcharge.', 'mastercard-gateway' ),
				'default'     => '',
			),
			'surcharge_message'        => array(
				'title'       => __( 'Surcharge Message', 'mastercard-gateway' ),
				'type'        => 'textarea',
				'description' => __(
					'Configure a message to display the surcharge on the \'Order Pay\' page. You can use the following variables for dynamic content:<br><br>
					<b>{{MG_CARD_TYPE}}</b> – Displays the card type (Credit Card/Debit Card).<br>
					<b>{{MG_SUR_AMT}}</b> – Shows the surcharge amount applied.<br>
					<b>{{MG_SUR_PCT}}</b> – Displays the surcharge percentage.<br>
					<b>{{MG_TOTAL_AMT}}</b> – Indicates the total amount payable by the customer, including the surcharge.<br><br>
					Example Message: When using a <b>{{MG_CARD_TYPE}}</b>, an additional surcharge of <b>{{MG_SUR_AMT}} ({{MG_SUR_PCT}})</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.',
					'mastercard-gateway'
				),
				'default'     => SUR_DEFAULT_MSG,
			),
			'paymentlink_info'         => array(
				'title'       => __( 'Pay By Link', 'mastercard-gateway' ),
				'type'        => 'title',
				'description' => __( 'Enable or disable the Pay by Link feature. Email templates can be customized at: <b>WooCommerce > Settings > Emails > Pay By Link (Request or Revoke Order).</b> The pay by link feature only works with redirect checkout interaction', 'mastercard-gateway' ),
			),
			'paymentlink_enabled'      => array(
				'title'       => __( 'Enable/Disable', 'mastercard-gateway' ),
				'label'       => __( 'Enable', 'mastercard-gateway' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'payment_expiry_unit'      => array(
				'title'       => __( 'Payment Expiry Unit', 'mastercard-gateway' ),
				'type'        => 'select',
				'description' => __( 'Determines whether the payment expires in hours, days, or months.', 'mastercard-gateway' ),
				'default'     => 'days',
				'options'     => array(
					'months' => __( 'Months', 'mastercard-gateway' ),
					'hours'  => __( 'Hours', 'mastercard-gateway' ),
					'days'   => __( 'Days', 'mastercard-gateway' ),
				),
			),
			'payment_expiry_value'     => array(
				'title'             => __( 'Payment Expiry Value', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'              => 'text',
				'description'       => __( 'Enter the amount of time allowed for the customer to complete the payment. Maximum allowed is 3 months.Please enter a whole number greater than zero; decimals, letters, and special characters are not permitted.', 'mastercard-gateway' ),
				'default'           => '90',
				'custom_attributes' => array(
					'min' => 1,
				),
			),

			'payment_allowed_attempts' => array(
				'title'       => __( 'Allowed Payment Attempts', 'mastercard-gateway' ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'After this limit is reached, the payment link will be disabled.Please enter a whole number between 1 and 25; decimals, letters, and special characters are not permitted.  ', 'mastercard-gateway' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Surcharge card type select options keyed by stored setting values.
	 *
	 * @return array<string, string>
	 */
	private static function surcharge_card_type_options() {
		$options               = array();
		$options[ SUR_DEBIT ]  = __( 'Debit Card', 'mastercard-gateway' );
		$options[ SUR_CREDIT ] = __( 'Credit Card', 'mastercard-gateway' );

		return $options;
	}
}
