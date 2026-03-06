<?php
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
	 * @var mastercard
	 * @since 1.5.2
	 */
	protected static $_instance = null;

	/**
	 * Main Constants Instance.
	 *
	 *
	 * @static
	 * @return Constants - Main instance.
	 */
	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 *
	 * @throws Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() {}

	/**
	 * Get settings or the Mastercard Gateway payment section.
	 *
	 * @return array
	 */
	public static function form_fields() {
		return array(
			'heading'            => array(
				'title'       => null,
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1. MPGS module version, 2. MPGS API version. */
					__( '<b>Plugin version:</b> %1$s<br /><b>API version:</b> %2$s', 'mastercard' ),
					MG_ENTERPRISE_MODULE_VERSION,
					MG_ENTERPRISE_API_VERSION_NUM
				),
			),
			'enabled'            => array(
				'title'       => __( 'Enable/Disable', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Enable', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'description' => __( 'Enable to activate the configuration needed for this payment option as well as enabling the same in the checkout page.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => 'no'
			),
			'title'              => array(
				'title'       => __( 'Title', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the name to be displayed to customers at checkout for this payment method.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => __( 'Mastercard Gateway', MG_ENTERPRISE_TEXTDOMAIN )
			),
			'description'        => array(
				'title'       => __( 'Description', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => __( 'Enter the description for this payment method as you want it to appear on the checkout page for customers.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => 'Pay with your card via Mastercard.'
			),
			'integration_section' => array(
				'title'       => __( 'Integration Settings', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'title',
				'description' => __( 'Configure core settings that control how the payment method integrates with your store.', MG_ENTERPRISE_TEXTDOMAIN ),
			),
			'method'             => array(
				'title'   => __( 'Integration Model', MG_ENTERPRISE_TEXTDOMAIN ),
				'description' => sprintf(
					__( 'Choose the Integration Model - Hosted Checkout or Hosted Session. Learn more about <a href="%s" target="_blank">Hosted Checkout</a> / <a href="%s" target="_blank">Hosted Session</a>.', MG_ENTERPRISE_TEXTDOMAIN ),
					MG_ENTERPRISE_HC_URL,
					MG_ENTERPRISE_HS_URL
				),
				'type'    => 'select',
				'options' => array(
					HOSTED_CHECKOUT => __( 'Hosted Checkout', MG_ENTERPRISE_TEXTDOMAIN ),
					HOSTED_SESSION  => __( 'Hosted Session', MG_ENTERPRISE_TEXTDOMAIN ),
				),
				'default' => HOSTED_CHECKOUT
			),
			'txn_mode'           => array(
				'title'       => __( 'Payment Action', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'select',
				'options'     => array(
					TXN_MODE_PURCHASE     => __( 'Purchase', MG_ENTERPRISE_TEXTDOMAIN ),
					TXN_MODE_AUTH_CAPTURE => __( 'Authorize', MG_ENTERPRISE_TEXTDOMAIN ),
				),
				'default'     => TXN_MODE_PURCHASE,
				'description' => __( 'In “Purchase”, the customer is charged immediately. In Authorize, the transaction is only reserved and the capturing of funds is a manual process that you do using the WooCommerce admin panel.', MG_ENTERPRISE_TEXTDOMAIN )
			),
			'threedsecure'       => array(
				'title'       => __( 'EMV 3-D Secure', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Use 3D-Secure', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'select',
				'options'     => array(
					THREED_DISABLED => __( 'Disabled' ),
					THREED_V1       => __( 'EMV 3-D Secure v1' ),
					THREED_V2       => __( 'EMV 3-D Secure v2' ),
				),
				'default'     => THREED_DISABLED,
				'description' => __( 'Select the security level for the user’s card during transactions.', MG_ENTERPRISE_TEXTDOMAIN )
			),
			'saved_cards' => array(
				'title'       => __( 'Save Cards', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Enable payment via saved tokenized cards', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, users can pay using saved cards during checkout. Payments are processed via tokenized cards, with card details securely stored in the payment gateway - not on your store.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => 'yes'
			),
			'hc_interaction'     => array(
				'title'   => __( 'Checkout Interaction', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'    => 'select',
				'options' => array(
					HC_TYPE_REDIRECT => __( 'Redirect to Payment Page', MG_ENTERPRISE_TEXTDOMAIN ),
					HC_TYPE_EMBEDDED => __( 'Embedded Form', MG_ENTERPRISE_TEXTDOMAIN ),
				),
				'default' => HC_TYPE_EMBEDDED,
				'description' => __( 'Selecting "Redirect to Payment Page" will also allow you to configure your business logo and related information in the Merchant Information section below.', MG_ENTERPRISE_TEXTDOMAIN )
			),
			'gateway_section' => array(
				'title'       => __( 'Gateway - API Credentials', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: Gateway API Credentials */
					__( 'Enter the API credentials required to connect with the Mastercard Gateway. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.', MG_ENTERPRISE_TEXTDOMAIN ),
					MG_ENTERPRISE_WIKI_CONFIG_URL
				)
			),
			'sandbox'            => array(
				'title'       => __( 'Test Mode', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Enable test sandbox mode', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'description' => __( ' Use this to enable Test mode with test credentials for testing purposes.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => 'yes'
			),
			'custom_gateway_url' => array(
				'title'       => __( 'Gateway URL', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the Gateway URL shared by your payment service provider. Enter the URL without https prefix. For example na.gateway.mastercard.com.', MG_ENTERPRISE_TEXTDOMAIN )
			),
			'username'           => array(
				'title'       => __( 'Merchant ID', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter your Merchant ID.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'sandbox_username'   => array(
				'title'       => __( 'Test Merchant ID', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter your Test Merchant ID.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'password'           => array(
				'title'       => __( 'API Password', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'password',
				'description' => sprintf(
					__( 'Enter the API Password obtained from your Mastercard Gateway account. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.', MG_ENTERPRISE_TEXTDOMAIN ),
					MG_ENTERPRISE_WIKI_CONFIG_URL
				),
				'default'     => '',
			),
			'sandbox_password' => array(
				'title'       => __( 'Test API Password', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'password',
				'description' => sprintf(
					__( 'Enter the Test API  Password obtained from your Mastercard Gateway account. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.', MG_ENTERPRISE_TEXTDOMAIN ),
					MG_ENTERPRISE_WIKI_CONFIG_URL
				),
				'default'     => ''
			),
			'webhook_secret'       => array(
				'title'       => __( 'Webhook Secret', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => sprintf(
					__( 'Enter the Webhook Secret from your Mastercard Gateway account. Learn how to access your <a href="%s" target="_blank">Webhook Secret</a>.', MG_ENTERPRISE_TEXTDOMAIN ),
					MG_ENTERPRISE_WIKI_WEBHOOK_URL
				),
				'default'     => ''
			),
			'test_webhook_secret'       => array(
				'title'       => __( 'Test Webhook Secret', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => sprintf(
					__( 'Enter the Test Webhook Secret from your Mastercard Gateway account. Learn how to access your <a href="%s" target="_blank">Webhook Secret</a>.', MG_ENTERPRISE_TEXTDOMAIN ),
					MG_ENTERPRISE_WIKI_WEBHOOK_URL
				),
				'default'     => ''
			),
			'adtnl_cnf_details'        => array(
				'title'       => __( 'Additional Configurations', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: Gateway API Credentials */
					__( 'Configure additional plugin parameters for customization.', MG_ENTERPRISE_TEXTDOMAIN ),
					MG_ENTERPRISE_WIKI_CONFIG_URL
				)
			),
			// 'locale' => array(
			// 	'title'       => __( 'Language', MG_ENTERPRISE_TEXTDOMAIN ),
			// 	'type'        => 'select',
			// 	'options'     => Countries::get_instance()->get_ietf_countries(),
			// 	'default'     => 'en',
			// 	'description' => __( 'By default, the Hosted Payment Page uses the payer’s browser language. To override this, specify a language. If the language is unsupported, the closest match will be used. This setting updates only the labels within the payment iframe. For other texts displayed in checkout, please enter the content in your preferred language directly into the respective input fields on this configuration page.', MG_ENTERPRISE_TEXTDOMAIN ),
			// 	'custom_attributes' => array(
			// 		'data-hc-langs' => wp_json_encode( Countries::get_instance()->get_ietf_countries() ),
			// 		'data-hs-langs' => wp_json_encode( Countries::get_instance()->get_hs_countries() )
			// 	)
			// ),
			'debug'              => array(
				'title'       => __( 'Debug Logging', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Enabled/Disabled', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'description' => __( 'Enable to log all communication with Mastercard Gateway to file ./wp-content/mastercard.log.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => 'no'
			),
			'order_prefix'       => array(
				'title'       => __( 'Order ID Prefix', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => __( 'Specify the order ID prefix.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'send_line_items'    => array(
				'title'       => __( 'Send Line Items', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Enable Send Line Items', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'description' => __( 'Enable to send detailed order information (line items) to the Mastercard Gateway. Disable this feature if your products are virtual or digital, as it is intended for physical goods only.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => 'no'
			),
			'handling_fee'      => array(
				'title'       => __( 'Handling Fee', MG_ENTERPRISE_TEXTDOMAIN ) ,
				'type'        => 'title',
				'description' => __( ' Enable to add the handling amount for the order, including taxes on the handling.', MG_ENTERPRISE_TEXTDOMAIN ),
			),
			'hf_enabled'            => array(
				'title'       => __( 'Enable/Disable', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Enable', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'handling_text'      => array(
				'title'       => __( 'Handling Fee Text', MG_ENTERPRISE_TEXTDOMAIN )  . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( ' Enter the text to be displayed in the front-end checkout page.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'hf_amount_type'     => array(
				'title'   => __( 'Applicable Amount Type', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'    => 'select',
				'description' => __( 'Select either “Fixed” or “Percentage” from the dropdown menu to determine how the handling fee will be calculated.', MG_ENTERPRISE_TEXTDOMAIN ),
				'options' => array(
					HF_FIXED      => __( 'Fixed', MG_ENTERPRISE_TEXTDOMAIN ),
					HF_PERCENTAGE => __( 'Percentage', MG_ENTERPRISE_TEXTDOMAIN ),
				),
				'default' => HF_FIXED
			),
			'handling_fee_amount' => array(
				'title'       => __( 'Amount', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the value to be applied to the subtotal.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'merchant_info'      => array(
				'title'       => __( 'Merchant Information', MG_ENTERPRISE_TEXTDOMAIN ) ,
				'type'        => 'title',
				'description' => __( 'This section appears only when "Redirect to Payment Page" is selected for Checkout Interaction. Configuring the details in this section allows them to be displayed on Mastercard’s redirected payment page.', MG_ENTERPRISE_TEXTDOMAIN )
			),
			'mif_enabled'            => array(
				'title'       => __( 'Enable/Disable', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Enable', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'merchant_name'   => array(
				'title'       => __( 'Merchant Name', MG_ENTERPRISE_TEXTDOMAIN )  . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Name of your business (up to 40 characters) to be shown to the payer during the payment interaction.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'merchant_address_line1'      => array(
				'title'       => __( 'Address Line 1', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => __( 'The first line of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'merchant_address_line2'      => array(
				'title'       => __( 'Address Line 2', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => __( 'The second line of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'merchant_address_line3'      => array(
				'title'       => __( 'Postcode / ZIP', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => __( 'The postal or ZIP code of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'merchant_address_line4'      => array(
				'title'       => __( 'Country / State', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => __( 'The country or state of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'merchant_email'      => array(
				'title'       => __( 'Email', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => __( 'The email address of your business to be shown to the payer during the payment interaction. (e.g. an email address for customer service).', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'merchant_phone'      => array(
				'title'       => __( 'Phone', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'tel',
				'description' => __( 'The phone number of your business (up to 20 characters) to be shown to the payer during the payment interaction.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'merchant_logo'      => array(
				'title'       => __( 'Logo', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => '',
				'description' => __( 'The URL of your business logo (JPEG, PNG, or SVG) to be shown to the payer during the payment interaction.<br />The logo should be 140x140 pixels, and the URL must be secure (e.g., https://). Size exceeding 140 pixels will be auto resized.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'surcharge'      => array(
				'title'       => __( 'Surcharge', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'title',
				'description' => __( 'Enable to add additional charges for Debit/Credit card transactions.', MG_ENTERPRISE_TEXTDOMAIN )
			),
			'surcharge_enabled'  => array(
				'title'       => __( 'Enable/Disable', MG_ENTERPRISE_TEXTDOMAIN ),
				'label'       => __( 'Enable', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'surcharge_card_type'     => array(
				'title'   => __( 'Applicable Card Type', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'    => 'select',
				'description' => __( 'Select the card type for which surcharge has to be added.', MG_ENTERPRISE_TEXTDOMAIN ),
				'options' => array(
					SUR_DEBIT  => __( 'Debit Card', MG_ENTERPRISE_TEXTDOMAIN ),
					SUR_CREDIT => __( 'Credit Card', MG_ENTERPRISE_TEXTDOMAIN ),
				),
				'default' => HF_FIXED
			),
			'surcharge_text'      => array(
				'title'       => __( 'Surcharge Text', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the text to display the surcharge breakdown on the \'Thank You\' page.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => 'Surcharge'
			),
			'surcharge_amount_type'     => array(
				'title'   => __( 'Applicable Amount Type', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'    => 'select',
				'description' => __( 'Select either “Fixed” or “Percentage” from the dropdown menu to determine how the Surcharge fee will be calculated.', MG_ENTERPRISE_TEXTDOMAIN ),
				'options' => array(
					HF_FIXED      => __( 'Fixed', MG_ENTERPRISE_TEXTDOMAIN ),
					HF_PERCENTAGE => __( 'Percentage', MG_ENTERPRISE_TEXTDOMAIN ),
				),
				'default' => HF_FIXED
			),
			'surcharge_amount' => array(
				'title'       => __( 'Amount', MG_ENTERPRISE_TEXTDOMAIN ) . ' <span class="req-input">*</span>',
				'type'        => 'text',
				'description' => __( 'Enter the value to be calculated for Surcharge.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => ''
			),
			'surcharge_message'      => array(
				'title'       => __( 'Surcharge Message', MG_ENTERPRISE_TEXTDOMAIN ),
				'type'        => 'textarea',
				'description' => __( 'Configure a message to display the surcharge on the \'Order Pay\' page. You can use the following variables for dynamic content:<br><br>
					<b>{{MG_CARD_TYPE}}</b> – Displays the card type (Credit Card/Debit Card).<br>
					<b>{{MG_SUR_AMT}}</b> – Shows the surcharge amount applied.<br>
					<b>{{MG_SUR_PCT}}</b> – Displays the surcharge percentage.<br>
					<b>{{MG_TOTAL_AMT}}</b> – Indicates the total amount payable by the customer, including the surcharge.<br><br>
					Example Message: When using a <b>{{MG_CARD_TYPE}}</b>, an additional surcharge of <b>{{MG_SUR_AMT}} ({{MG_SUR_PCT}})</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.', MG_ENTERPRISE_TEXTDOMAIN ),
				'default'     => SUR_DEFAULT_MSG
			),
		);
	}
}
