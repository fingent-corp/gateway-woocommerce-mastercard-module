<?php
namespace Fingent\Mastercard\Controller;

use Fingent\Mastercard\Model\MastercardGateway;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

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
	 * @throws Exception If there's a problem connecting to the gateway.
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
	 * @return int Order id.
	 */
	public function get_order_id() {
		if ( 'yes' !== $this->is_hpos() ) {
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
	public function is_hpos() {
		return OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';
	}

	/**
	 * Retrieves a comprehensive list of font families, including widely used system fonts and Google Fonts.
	 *
	 * This function compiles a pre-defined list of common web-safe system fonts
	 * and merges them with a list of popular Google Fonts obtained from the
	 * `google_fonts()` method. The combined list is then sorted alphabetically.
	 *
	 * This consolidated list can be used for various purposes, such as populating
	 * font selection dropdowns in a theme or plugin's administration settings,
	 * allowing users to choose from a diverse range of fonts for their website content.
	 *
	 * @return array An alphabetically sorted array of font family names (strings).
	 */
	public function get_font_families() {
	    $fonts = array(
		    array( 'value' => 'inherit',            'label' => 'Global' ),
		    array( 'value' => 'Arial',              'label' => 'Arial' ),
		    array( 'value' => 'Verdana',            'label' => 'Verdana' ),
		    array( 'value' => 'Helvetica',          'label' => 'Helvetica' ),
		    array( 'value' => 'Tahoma',             'label' => 'Tahoma' ),
		    array( 'value' => 'Trebuchet MS',       'label' => 'Trebuchet MS' ),
		    array( 'value' => 'Georgia',            'label' => 'Georgia' ),
		    array( 'value' => 'Times New Roman',    'label' => 'Times New Roman' ),
		    array( 'value' => 'Courier New',        'label' => 'Courier New' ),
		    array( 'value' => 'Impact',             'label' => 'Impact' ),
		    array( 'value' => 'Lucida Console',     'label' => 'Lucida Console' ),
		    array( 'value' => 'Palatino Linotype',  'label' => 'Palatino Linotype' ),
		    array( 'value' => 'Garamond',           'label' => 'Garamond' ),
		    array( 'value' => 'Book Antiqua',       'label' => 'Book Antiqua' ),
		    array( 'value' => 'Comic Sans MS',      'label' => 'Comic Sans MS' ),
		    array( 'value' => 'Arial Black',        'label' => 'Arial Black' ),
		);

	    $google_fonts = $this->google_fonts();
	    $fonts = array_merge( $fonts, $google_fonts );
	    sort( $fonts );

	    return $fonts;
	}

	/**
	 * Retrieves a predefined list of popular Google Fonts.
	 *
	 * This function returns an array containing the names of widely used
	 * Google Fonts. This list can be utilized by themes or plugins to offer
	 * a selection of web fonts to users, ensuring good typography and
	 * broad browser compatibility when linked correctly from the Google Fonts API.
	 *
	 * @return array A list of Google Font family names as strings.
	 */
	public function google_fonts() {
		return array(
		    array( 'value' => 'Roboto',            'label' => 'Roboto' ),
		    array( 'value' => 'Open Sans',         'label' => 'Open Sans' ),
		    array( 'value' => 'Lato',              'label' => 'Lato' ),
		    array( 'value' => 'Montserrat',        'label' => 'Montserrat' ),
		    array( 'value' => 'Oswald',            'label' => 'Oswald' ),
		    array( 'value' => 'Poppins',           'label' => 'Poppins' ),
		    array( 'value' => 'Merriweather',      'label' => 'Merriweather' ),
		    array( 'value' => 'Playfair Display',  'label' => 'Playfair Display' ),
		    array( 'value' => 'Noto Sans',         'label' => 'Noto Sans' ),
		    array( 'value' => 'Source Sans Pro',   'label' => 'Source Sans Pro' ),
		    array( 'value' => 'Raleway',           'label' => 'Raleway' ),
		    array( 'value' => 'Ubuntu',            'label' => 'Ubuntu' ),
		    array( 'value' => 'Inter',             'label' => 'Inter' ),
		    array( 'value' => 'Fira Sans',         'label' => 'Fira Sans' ),
		    array( 'value' => 'PT Sans',           'label' => 'PT Sans' ),
		    array( 'value' => 'Quicksand',         'label' => 'Quicksand' ),
		    array( 'value' => 'Mukta',             'label' => 'Mukta' ),
		    array( 'value' => 'Lora',              'label' => 'Lora' ),
		    array( 'value' => 'Nunito',            'label' => 'Nunito' ),
		    array( 'value' => 'Titillium Web',     'label' => 'Titillium Web' )
		);
	}

	/**
	 * Checks if a given font family name is recognized as a Google Font.
	 *
	 * This function determines whether the provided `$font_family` string exists
	 * within the list of Google Fonts retrieved by the `google_fonts()` method
	 * of the current class. It's a utility function commonly used to validate
	 * font selections or apply specific styling/loading logic for Google Fonts.
	 *
	 * @param string $font_family The name of the font family to check.
	 * @return bool True if the font family is found in the list of Google Fonts, false otherwise.
	 */
	public function is_google_font_family( $font_family ) {
		$availableFonts = array_column( $this->google_fonts(), 'value' );
    	return in_array( $font_family, $availableFonts, true );
	}

	/**
	 * Extracts a unique list of fontFamily values from a style configuration array.
	 *
	 * @param array $styles Associative array of JSON-encoded style sections.
	 * @return array Unique fontFamily values.
	 */
	public function extract_unique_font_families( $options ) {
	    $font_families = [];

	    if( $options ) {
			foreach ( $options as $sectionStyle ) {
			    $decoded = json_decode( $sectionStyle, true );

			    if ( is_array( $decoded ) && isset( $decoded['fontFamily'] ) ) {
			        $font = trim( $decoded['fontFamily'] );

			        if ( $this->is_google_font_family( $font ) && !in_array( $font, $font_families ) ) {
			            $font_families[] = $font;
			        }
			    }
			}
		}

	    return $font_families ? array_values( array_unique( $font_families ) ) : '';
	}
}