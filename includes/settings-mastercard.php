<?php
/**
 * Settings for Mastercard Gateway.
 *
 * @package Mastercard
 */

defined( 'ABSPATH' ) || exit;
$gateway = '';

return array(
	'heading'            => array(
		'title'       => null,
		'type'        => 'title',
		'description' => sprintf(
			/* translators: 1. MPGS module vesion, 2. MPGS API version. */
			__( 'Plugin version: %1$s<br />API version: %2$s', 'mastercard' ),
			MPGS_TARGET_MODULE_VERSION,
			self::MPGS_API_VERSION_NUM
		),
	),
	'enabled'            => array(
		'title'       => __( 'Enable/Disable', 'mastercard' ),
		'label'       => __( 'Enable', 'mastercard' ),
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no',
	),
	'title'              => array(
		'title'       => __( 'Title', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'This controls the title which the user sees during checkout.', 'mastercard' ),
		'default'     => __( 'Mastercard Gateway', 'mastercard' ),
		'css'         => 'min-height: 33px;'
	),
	'description'        => array(
		'title'       => __( 'Description', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'The description displayed when this payment method is selected.', 'mastercard' ),
		'default'     => 'Pay with your card via Mastercard.',
		'css'         => 'min-height: 33px;'
	),
	'gateway_url'        => array(
		'title'   => __( 'Gateway', 'mastercard' ),
		'type'    => 'select',
		'options' => array(
			self::API_AS     => __( 'Asia Pacific', 'mastercard' ),
			self::API_EU     => __( 'Europe', 'mastercard' ),
			self::API_NA     => __( 'North America', 'mastercard' ),
			self::API_CUSTOM => __( 'Custom URL', 'mastercard' ),
		),
		'default' => self::API_EU,
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'custom_gateway_url' => array(
		'title'       => __( 'Custom Gateway Host', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'Enter only the hostname without https prefix. For example na.gateway.mastercard.com.', 'mastercard' ),
		'css'         => 'min-height: 33px;'
	),
	'txn_mode'           => array(
		'title'       => __( 'Transaction Mode', 'mastercard' ),
		'type'        => 'select',
		'options'     => array(
			self::TXN_MODE_PURCHASE     => __( 'Purchase', 'mastercard' ),
			self::TXN_MODE_AUTH_CAPTURE => __( 'Authorize', 'mastercard' ),
		),
		'default'     => self::TXN_MODE_PURCHASE,
		'description' => __( 'In “Purchase” mode, the customer is charged immediately. In Authorize mode, the transaction is only authorized and the capturing of funds is a manual process that you do using the Woocommerce admin panel.', 'mastercard' ),
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'method'             => array(
		'title'   => __( 'Integration Model', 'mastercard' ),
		'type'    => 'select',
		'options' => array(
			self::HOSTED_CHECKOUT => __( 'Hosted Checkout', 'mastercard' ),
			self::HOSTED_SESSION  => __( 'Hosted Session', 'mastercard' ),
		),
		'default' => self::HOSTED_CHECKOUT,
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'threedsecure'       => array(
		'title'       => __( '3D-Secure', 'mastercard' ),
		'label'       => __( 'Use 3D-Secure', 'mastercard' ),
		'type'        => 'select',
		'options'     => array(
			self::THREED_DISABLED => __( 'Disabled' ),
			self::THREED_V1       => __( '3DS1' ),
			self::THREED_V2       => __( '3DS2 (with fallback to 3DS1)' ),
		),
		'default'     => self::THREED_DISABLED,
		'description' => __( 'For more information please contact your payment service provider.', 'mastercard' ),
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'hc_interaction'     => array(
		'title'   => __( 'Checkout Interaction', 'mastercard' ),
		'type'    => 'select',
		'options' => array(
			self::HC_TYPE_REDIRECT => __( 'Redirect to Payment Page', 'mastercard' ),
			self::HC_TYPE_EMBEDDED => __( 'Embedded', 'mastercard' ),
		),
		'default' => self::HC_TYPE_EMBEDDED,
		'description' => __( 'Selecting "Redirect to Payment Page" will also allow you to configure your business logo and related information in the Merchant Information section below.', 'mastercard' ),
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'hc_type'            => array(
		'title'   => __( 'Checkout Interaction', 'mastercard' ),
		'type'    => 'select',
		'options' => array(
			self::HC_TYPE_REDIRECT => __( 'Redirect to Payment Page', 'mastercard' ),
			self::HC_TYPE_MODAL    => __( 'Lightbox', 'mastercard' ),
		),
		'default' => self::HC_TYPE_MODAL,
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'saved_cards'        => array(
		'title'       => __( 'Saved Cards', 'mastercard' ),
		'label'       => __( 'Enable payment via saved tokenized cards', 'mastercard' ),
		'type'        => 'checkbox',
		'description' => __( 'If enabled, users will be able to pay with a saved card during checkout. Card details are saved in the payment gateway, not on your store.', 'mastercard' ),
		'default'     => 'yes',
	),
	'debug'              => array(
		'title'       => __( 'Debug Logging', 'mastercard' ),
		'label'       => __( 'Enable Debug Logging', 'mastercard' ),
		'type'        => 'checkbox',
		'description' => __( 'Logs all communication with Mastercard Gateway to file ./wp-content/mastercard.log. Debug logging only works in Sandbox mode.', 'mastercard' ),
		'default'     => 'no',
	),
	'send_line_items'    => array(
		'title'       => __( 'Send Line Items', 'mastercard' ),
		'label'       => __( 'Enable Send Line Items', 'mastercard' ),
		'type'        => 'checkbox',
		'description' => __( 'Enable the transmission of additional data to the Mastercard Gateway.', 'mastercard' ),
		'default'     => 'no',
	),
	'api_details'        => array(
		'title'       => __( 'API Credentials', 'mastercard' ),
		'type'        => 'title',
		'description' => sprintf(
			/* translators: Gateway API Credentials */
			__( 'Enter your API credentials to process payments via this payment gateway. Learn how to access your <a href="%s" target="_blank">Gateway API Credentials</a>.', 'mastercard' ),
			'https://mpgs.fingent.wiki/enterprise/woocommerce-mastercard-gateway/configuration/api-configuration'
		),
	),
	'sandbox'            => array(
		'title'       => __( 'Test Sandbox', 'mastercard' ),
		'label'       => __( 'Enable test sandbox mode', 'mastercard' ),
		'type'        => 'checkbox',
		'description' => __( 'Place the payment gateway in test mode using test API credentials (real payments will not be taken).', 'mastercard' ),
		'default'     => 'yes',
	),	
	'sandbox_username'   => array(
		'title'       => __( 'Test Merchant ID', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'This is your test merchant profile ID prefixed with TEST.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'sandbox_password'   => array(
		'title'       => __( 'Test API Password', 'mastercard' ),
		'type'        => 'password',
		'description' => __( 'This is your test API password.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'username'           => array(
		'title'       => __( 'Merchant ID', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'This is your merchant profile ID.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'password'           => array(
		'title'       => __( 'API Password', 'mastercard' ),
		'type'        => 'password',
		'description' => __( 'This is your API password.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'order_prefix'       => array(
		'title'       => __( 'Order ID Prefix', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'Should be specified in case multiple integrations use the same Merchant ID.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'webhook_details'        => array(
		'title'       => __( 'Webhook Notifications', 'mastercard' ),
		'type'        => 'title',
		'description' => sprintf(
			/* translators: Gateway API Credentials */
			__( '<p>Manage webhook notifications sent from the gateway to your system to track when a transaction for an order is created or updated. Learn how to access your <a href="%s" target="_blank">Webhook Secret</a>.</p>', 'mastercard' ),
			'https://mpgs.fingent.wiki/enterprise/woocommerce-mastercard-payment-gateway-services/configuration/api-configuration'
		),
	),
	'webhook_secret'       => array(
		'title'       => __( 'Webhook Secret', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'Use the secret to authenticate and verify that the webhook notification is sent by the gateway.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	
	'handling_fee'      => array(
		'title'       => __( 'Handling Fee', 'mastercard' ),
		'type'        => 'title',
		'description' => __( 'The handling amount for the order, including taxes on the handling.', 'mastercard' ),
	),
	'hf_enabled'            => array(
		'title'       => __( 'Enable/Disable', 'mastercard' ),
		'label'       => __( 'Enable', 'mastercard' ),
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no',
	),
	'handling_text'      => array(
		'title'       => __( 'Handling Fee Text', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'Display text for handling fee.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'hf_amount_type'     => array(
		'title'   => __( 'Applicable Amount Type', 'mastercard' ),
		'type'    => 'select',
		'options' => array(
			self::HF_FIXED      => __( 'Fixed', 'mastercard' ),
			self::HF_PERCENTAGE => __( 'Percentage', 'mastercard' ),
		),
		'default' => self::HF_FIXED,
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'handling_fee_amount' => array(
		'title'       => __( 'Amount', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'The total amount for handling fee; Eg: 10.00 or 10%.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;',
	),
	'surcharge'      => array(
		'title'       => __( 'Surcharge', 'mastercard' ),
		'type'        => 'title',
		'description' => __( 'Additional charges applied for Debit/Credit card transactions.', 'mastercard' ),
	),
	'surcharge_enabled'  => array(
		'title'       => __( 'Enable/Disable', 'mastercard' ),
		'label'       => __( 'Enable', 'mastercard' ),
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no',
	),
	'surcharge_card_type'     => array(
		'title'   => __( 'Applicable Card Type', 'mastercard' ),
		'type'    => 'select',
		'options' => array(
			self::SUR_DEBIT  => __( 'Debit Card', 'mastercard' ),
			self::SUR_CREDIT => __( 'Credit Card', 'mastercard' ),
		),
		'default' => self::HF_FIXED,
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'surcharge_text'      => array(
		'title'       => __( 'Surcharge Text', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'Set the text to display the surcharge breakdown on the \'Thank You\' page.', 'mastercard' ),
		'default'     => 'Surcharge',
		'css'         => 'min-height: 33px;'
	),
	'surcharge_amount_type'     => array(
		'title'   => __( 'Applicable Amount Type', 'mastercard' ),
		'type'    => 'select',
		'options' => array(
			self::HF_FIXED      => __( 'Fixed', 'mastercard' ),
			self::HF_PERCENTAGE => __( 'Percentage', 'mastercard' ),
		),
		'default' => self::HF_FIXED,
		'css'         => 'max-width: 500px; width: 500px;'
	),
	'surcharge_amount' => array(
		'title'       => __( 'Amount', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'The total amount for surcharge; Eg: 10.00 or 10%.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;',
	),
	'surcharge_message'      => array(
		'title'       => __( 'Surcharge Message', 'mastercard' ),
		'type'        => 'textarea',
		'description' => __( 'Configure a message to display the surcharge on the \'Order Pay\' page. You can use the following variables for dynamic content:<br><br>
			<b>{{MG_CARD_TYPE}}</b> – Displays the card type (Credit Card/Debit Card).<br>
			<b>{{MG_SUR_AMT}}</b> – Shows the surcharge amount applied.<br>
			<b>{{MG_SUR_PCT}}</b> – Displays the surcharge percentage.<br>
			<b>{{MG_TOTAL_AMT}}</b> – Indicates the total amount payable by the customer, including the surcharge.<br><br>
			Example Message: When using a <b>{{MG_CARD_TYPE}}</b>, an additional surcharge of <b>{{MG_SUR_AMT}} ({{MG_SUR_PCT}})</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.', 'mastercard' ),
		'default'     => self::SUR_DEFAULT_MSG,
		'css'         => 'max-width: 500px; height: 120px;'
	),

	'merchant_info'      => array(
		'title'       => __( 'Merchant Information', 'mastercard' ),
		'type'        => 'title',
		'description' => __( 'This section appears only when "Redirect to Payment Page" is selected for Checkout Interaction. Configuring the details below allows them to be displayed on Mastercard’s redirected payment page.', 'mastercard' ),
	),
	'mif_enabled'            => array(
		'title'       => __( 'Enable/Disable', 'mastercard' ),
		'label'       => __( 'Enable', 'mastercard' ),
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no',
	),
	'merchant_name'   => array(
		'title'       => __( 'Merchant Name', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'Name of your business (up to 40 characters) to be shown to the payer during the payment interaction.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'merchant_address_line1'      => array(
		'title'       => __( 'Address line 1', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'The first line of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'merchant_address_line2'      => array(
		'title'       => __( 'Address line 2', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'The second line of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'merchant_address_line3'      => array(
		'title'       => __( 'Postcode / ZIP', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'The postal or ZIP code of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'merchant_address_line4'      => array(
		'title'       => __( 'Country / State', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'The country or state of your business address (up to 100 characters) to be shown to the payer during the payment interaction.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'merchant_email'      => array(
		'title'       => __( 'Email', 'mastercard' ),
		'type'        => 'email',
		'description' => __( 'The email address of your business to be shown to the payer during the payment interaction. (e.g. an email address for customer service).', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px;'
	),
	'merchant_phone'      => array(
		'title'       => __( 'Phone', 'mastercard' ),
		'type'        => 'tel',
		'description' => __( 'The phone number of your business (up to 20 characters) to be shown to the payer during the payment interaction.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px; width: 400px;'
	),
	'merchant_logo'      => array(
		'title'       => __( 'Logo', 'mastercard' ),
		'type'        => 'text',
		'description' => __( 'The URL of your business logo (JPEG, PNG, or SVG) to be shown to the payer during the payment interaction.<br />The logo should be 140x140 pixels, and the URL must be secure (e.g., https://). Size exceeding 140 pixels will be auto resized.', 'mastercard' ),
		'default'     => '',
		'css'         => 'min-height: 33px; width: 400px;'
	),
);
