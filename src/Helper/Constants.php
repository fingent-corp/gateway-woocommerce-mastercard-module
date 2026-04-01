<?php
namespace Fingent\Mastercard\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Constants {
	/**
	 * The single instance of the class.
	 *
	 * @var Constants|null
	 */
	protected static ?Constants $_instance = null;

	/**
	 * Main Constants Instance.
	 *
	 *
	 * @static
	 * @return Constants - Main instance.
	 */
	public static function get_instance():Constants {
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
		$wiki_base_url = 'https://mpgs.fingent.wiki/enterprise/woocommerce-mastercard-gateway/';

		$this->define( 'MG_ENTERPRISE_ID',                        'mastercard_gateway' );
		$this->define( 'MG_ENTERPRISE_GATEWAY_TITLE',             'Mastercard Gateway' );		
		$this->define( 'MG_ENTERPRISE_MODULE_VERSION',            '1.5.2.1' );
		$this->define( 'MG_ENTERPRISE_CAPTURE_URL',               'https://mpgs.fingent.wiki/wp-json/mpgs/v2/update-repo-status/' );
		$this->define( 'MG_ENTERPRISE_SUPPORT_URL',               'https://mpgsfgs.atlassian.net/servicedesk/customer/portals/' );
		$this->define( 'MG_ENTERPRISE_WIKI_URL',                  $wiki_base_url . 'installation/' );
		$this->define( 'MG_ENTERPRISE_WIKI_CONFIG_URL',           $wiki_base_url . 'configuration/api-configuration/' );
		$this->define( 'MG_ENTERPRISE_WIKI_WEBHOOK_URL',          $wiki_base_url . 'configuration/api-configuration/#webhooksecret' );
		$this->define( 'MG_ENTERPRISE_API_VERSION',               'version/100' );
		$this->define( 'MG_ENTERPRISE_API_VERSION_NUM',           '100' );
		$this->define( 'MG_ENTERPRISE_STATUS_TOKEN',              '3958a5f32a0439ac8e09bbc44ca6d9d66bd8fb785f10145f4a446ec0b4f00639' );
		$this->define( 'MG_ENTERPRISE_HC_URL',                    $wiki_base_url . 'integration-model/hosted-checkout-integration/' );
		$this->define( 'MG_ENTERPRISE_HS_URL',                    $wiki_base_url . 'integration-model/hosted-session-integration/' );

		/**
		 * These Constants are utilized for the Integration model.
		 */
		$this->define( 'HOSTED_SESSION',                          'hosted-session' );
		$this->define( 'HOSTED_CHECKOUT',                         'hosted-checkout' );

		/**
		 * These Constants are utilized for the Checkout Interaction.
		 */
		$this->define( 'HC_TYPE_REDIRECT',                        'redirect' );
		$this->define( 'HC_TYPE_EMBEDDED',                        'embedded' );

		/**
		 * These Constants are utilized for the Gateway type.
		 */
		$this->define( 'API_EU',                                  'eu-gateway.mastercard.com' );
		$this->define( 'API_AS',                                  'ap-gateway.mastercard.com' );
		$this->define( 'API_NA',                                  'na-gateway.mastercard.com' );
		$this->define( 'API_CUSTOM',                              'custom' );

		/**
		 * These Constants are utilized for the Transaction Mode.
		 */
		$this->define( 'TXN_MODE_PURCHASE',                       'capture' );
		$this->define( 'TXN_MODE_AUTH_CAPTURE',                   'authorize' );

		/**
		 * These Constants are utilized for the Handling Fee.
		 */
		$this->define( 'HF_FIXED',                                'fixed' );
		$this->define( 'HF_PERCENTAGE',                           'percentage' );
		$this->define( 'HF_FEE_OPTION',                           'mpgs_handling_fee' );
		$this->define( 'HF_FEE_VAR',                              '_mpgs_handling_fee' );
		$this->define( 'HF_AMT_TYPE_TXT',                         'hf_amount_type' );
		$this->define( 'HF_AMT_TXT',                              'handling_fee_amount' );
		$this->define( 'HF_TEXT',                                 'handling_text' );
		$this->define( 'HF_DEFAULT_TEXT',                         'Handling Fee' );
		$this->define( 'HF_ERROR_MSG',                            'Handling fee percentage is restricted to a maximum of 99.9' );

		/**
		 * These Constants are utilized for the Surcharge.
		 */
		$this->define( 'SUR_ENABLED',                             'surcharge_enabled' );
		$this->define( 'SUR_TEXT',                                'surcharge_text' );
		$this->define( 'SUR_AMT_TYPE_TXT',                        'surcharge_amount_type' );
		$this->define( 'SUR_AMT_TXT',                             'surcharge_amount' );
		$this->define( 'SUR_CARD_TYPE',                           'surcharge_card_type' );
		$this->define( 'SUR_MSG',                                 'surcharge_message' );
		$this->define( 'SUR_DEBIT',                               __( 'Debit', MG_ENTERPRISE_TEXTDOMAIN ) );
		$this->define( 'SUR_CREDIT',                              __( 'Credit', MG_ENTERPRISE_TEXTDOMAIN ) );
		$this->define( 'SUR_ERROR_MSG',                            'Surcharge fee percentage  is restricted to a maximum of 99.9' );
		$this->define( 'HF_SUR_ERROR_MSG',                         'Surcharge and Handling fee percentage  is restricted to a maximum of 99.9' );

		$this->define( 'SUR_DEFAULT_MSG',                         'When using a {{MG_CARD_TYPE}} an additional surcharge of <b>{{MG_SUR_AMT}} ({{MG_SUR_PCT}})</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.' );

		/**
		 * These Constants are utilized for the 3D-Secure.
		 */
		$this->define( 'THREED_DISABLED',                         'no' );
		$this->define( 'THREED_V1',                               'yes' );
		$this->define( 'THREED_V2',                               '2' );

		/**
		 * These Constants used for React Preview.
		 */
		$this->define( 'SURCHARGE_HEADING',                       'Surcharge Fee Notice' );
		$this->define( 'PAYMENT_FORM_HEADING',                    'Payment Form' );
		$this->define( 'PAYMENT_INPUT_HEADING',                   'Payment Input Fields' );
		$this->define( 'PAY_BUTTON_HEADING',                      'Pay Button' );
		$this->define( 'SURCHARGE_CONSENT_HEADING',               'Surcharge Consent' );
		$this->define( 'SURCHARGE_CONFIRM_HEADING',               'Confirm Surcharge Button' );
		$this->define( 'SURCHARGE_CANCEL_HEADING',                'Cancel Surcharge Button' );

		/**
		 * Default styles for surchage fee notice.
		 */
		$this->define( 'SURCHARGE_STYLES', array(
			    'bgColor'       => '#fffbf4',
			    'borderColor'   => '#f0b849',
			    'borderRadius'  => '5px',
			    'fontFamily'    => 'inherit',
			    'fontColor'     => '#2f2f2f',
			    'fontSize'      => '15px',
			    'fontWeight'    => '400',
			    'lineHeight'    => '17px',
			)
		);

		/**
		 * Default styles for payment form.
		 */
		$this->define( 'FORM_STYLES', array(
			    'bgColor'      => '#ffffff',
			    'borderColor'  => '#9d9d9d',
			    'borderRadius' => '5px',
			    'fontFamily'   => 'inherit',
			    'fontColor'    => '#3c434a',
			    'fontSize'     => '15px',
			    'fontWeight'   => '400',
			    'lineHeight'   => '17px',
			)
		);

		/**
		 * Default styles for payment input fields.
		 */
		$this->define('PAYMENT_INPUT_STYLES', array(
			    'borderColor'   => '#9d9d9d',
			    'borderRadius'  => '5px',
			    'fontFamily'    => 'inherit',
			    'fontColor'     => '#666666',
			    'fontSize'      => '15px',
			    'fontWeight'    => '400',
			    'lineHeight'    => '17px',
			)
		);

		/**
		 * Default styles for payment form.
		 */
		define('PAY_BUTTON_STYLES', array(
			    'bgColor'          => '#111111',
			    'borderColor'      => '#111111',
			    'borderRadius'     => '5px',
			    'fontFamily'       => 'inherit',
			    'fontColor'        => '#f9f9f9',
			    'fontSize'         => '15px',
			    'fontWeight'       => '500',
			    'lineHeight'       => '17px',
			    'hoverBgColor'     => '#333333',
			    'hoverBorderColor' => '#333333',
			    'hoverFontColor'   => '#ffffff',
			)
		);

		/**
		 * Default styles for payment form.
		 */
		define( 'CONSENT_STYLES', array(
			    'bgColor'       => '#f4f8ff',
			    'borderColor'   => '#007cba',
			    'borderRadius'  => '5px',
			    'fontFamily'    => 'inherit',
			    'fontColor'     => '#2f2f2f',
			    'fontSize'      => '18px',
			    'fontWeight'    => '600',
			    'lineHeight'    => '23px',
			)
		);

		/**
		 * Default styles for payment form.
		 */
		define( 'CONFIRM_BUTTON_STYLES', array(
			    'bgColor'          => '#111111',
			    'borderColor'      => '#111111',
			    'borderRadius'     => '5px',
			    'fontFamily'       => 'inherit',
			    'fontColor'   	   => '#f9f9f9',
			    'fontSize'  	   => '15px',
			    'fontWeight'       => '500',
			    'lineHeight'       => '30px',
			    'hoverBgColor'     => '#333333',
			    'hoverBorderColor' => '#333333',
			    'hoverFontColor'   => '#ffffff',
			)
		);

		/**
		 * Default styles for payment form.
		 */
		define( 'CANCEL_BUTTON_STYLES', array(
			    'bgColor'          => '#ffffff',
			    'borderColor'  	   => '#cccccc',
			    'borderRadius'     => '5px',
			    'fontFamily'       => 'inherit',
			    'fontColor'    	   => '#111111',
			    'fontSize'         => '15px',
			    'fontWeight' 	   => '500',
			    'lineHeight' 	   => '30px',
			    'hoverBgColor'     => '#333333',
			    'hoverBorderColor' => '#333333',
			    'hoverFontColor'   => '#ffffff',
			)
		);
	}
}
