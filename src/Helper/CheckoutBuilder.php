<?php
namespace Fingent\Mastercard\Helper;

use Automattic\WooCommerce\Utilities\NumberUtil;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Helper\Countries;
use Fingent\Mastercard\Controller\PaymentController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main class of the Mastercard Checkout Builder
 *
 * Represents a gateway service for processing Mastercard transactions.
 */
class CheckoutBuilder {
	/**
	 * WooCommerce Order
	 *
	 * @var WC_Order
	 */
	protected $order = null;

	/**
	 * Mastercard Gateway object
	 *
	 * @var WC_Order
	 */
	protected $gateway = null;

	/**
	 * Gateway URL.
	 *
	 * @var WC_Order
	 */
	protected $api_url = null;

	/**
	 * Mastercard_Model_AbstractBuilder constructor.
	 *
	 * @param array $order WC_Order.
	 */
	public function __construct( $order ) {
		$this->order   = $order;
		$this->gateway = MastercardGateway::get_instance();
		$this->api_url = 'https://' . $this->gateway->get_gateway_url();
	}

	/**
	 * Converts a two-letter ISO country code to a three-letter ISO country code.
	 *
	 * @param string $iso2_country - The two-letter ISO country code.
	 *
	 * @return string The three-letter ISO country code.
	 */
	public function iso2ToIso3( $iso2_country ) { // phpcs:ignore
		$countries = Countries::get_instance()->get_iso2_to_iso3();
		
		return $countries[ $iso2_country ];
	}

	/**
	 * A function that checks if a value is safe and within a specified limit.
	 *
	 * @param string $value - The value to be checked.
	 * @param number $limited - The limit to compare the value against.
	 *
	 * @return boolean Returns true if the value is safe and within the limit, otherwise returns false.
	 */
	public static function is_safe( $value, $limited = 0 ) {
		if ( '' === $value ) {
			return null;
		}

		if ( $limited > 0 && strlen( $value ) > $limited ) {
			return substr( $value, 0, $limited );
		}

		return $value;
	}

	/**
	 * Retrieves the billing information.
	 *
	 * @return array The billing information.
	 */
	public function getBilling() { // phpcs:ignore
		$billing = array();

		$fields = array( 
			'street'        => array( 'method' => 'get_billing_address_1', 'length' => 100 ),
			'street2'       => array( 'method' => 'get_billing_address_2', 'length' => 100 ),
			'city'          => array( 'method' => 'get_billing_city',      'length' => 100 ),
			'postcodeZip'   => array( 'method' => 'get_billing_postcode',  'length' => 10 ),
			'stateProvince' => array( 'method' => 'get_billing_state',     'length' => 20 ),
		);

		foreach ( $fields as $key => $field ) {
			$value = $this->order->{ $field['method'] }();

			if ( $value ) {
				$billing['address'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		$country = $this->order->get_billing_country();

		if ( $country ) {
			$billing['address']['country'] = $this->iso2ToIso3( $country );
		}

		return $billing;
	}

	/**
	 * Determines if an order is virtual.
	 *
	 * @param array $order WC_Order.
	 *
	 * @return bool
	 */
	public function orderIsVirtual( $order ) { // phpcs:ignore
		if ( empty( $order->get_shipping_address_1() ) ) {
			return true;
		}

		if ( empty( $order->get_shipping_first_name() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieves the shipping information.
	 *
	 * @return array|null
	 */
	public function getShipping() { // phpcs:ignore
		if ( $this->orderIsVirtual( $this->order ) ) {
			return null;
		}

		$shipping = array();

		$addressFields = array(
			'street'        => array( 'method' => 'get_shipping_address_1', 'length' => 100 ),
			'street2'       => array( 'method' => 'get_shipping_address_2', 'length' => 100 ),
			'city'          => array( 'method' => 'get_shipping_city',      'length' => 100 ),
			'postcodeZip'   => array( 'method' => 'get_shipping_postcode',  'length' => 10 ),
			'stateProvince' => array( 'method' => 'get_shipping_state',     'length' => 20 ),
		);

		foreach ( $addressFields as $key => $field ) {
			$value = $this->order->{ $field['method'] }();

			if ( $value ) {
				$shipping['address'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		$country = $this->order->get_shipping_country();

		if ( $country ) {
			$shipping['address']['country'] = $this->iso2ToIso3( $country );
		}

		$contactFields = array( 
			'firstName' => array( 'method' => 'get_shipping_first_name', 'length' => 50 ),
			'lastName'  => array( 'method' => 'get_shipping_last_name',  'length' => 50 ),
		);

		foreach ( $contactFields as $key => $field ) {
			$value = $this->order->{ $field['method'] }();
			
			if ( $value ) {
				$shipping['contact'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		return $shipping;
	}

	/**
	 * Retrieves the customer information.
	 *
	 * @return array
	 */
	public function getCustomer() { // phpcs:ignore
		return array(
			'email'     => $this->order->get_billing_email(),
			'firstName' => self::is_safe( $this->order->get_billing_first_name(), 50 ),
			'lastName'  => self::is_safe( $this->order->get_billing_last_name(), 50 ),
		);
	}

	/**
	 * Retrieves the hosted checkout order information.
	 *
	 * @return array
	 */
	public function getHostedCheckoutOrder() { // phpcs:ignore       
        $order_summary = array(); 
		$locale        = $this->gateway->get_option( 'locale' );
		$description   = Countries::get_instance()->get_order_summary_text( $locale );
		$order_prefix  = (string) PaymentController::get_instance()->add_order_prefix( $this->order->get_id() );
        $shipping_fee  = $this->getOrderShippingAndHandling();

        if( 'yes' === $this->gateway->send_line_items ) {
            $line_items = $line_items = array(); 
            $items = $this->order->get_items();

            if ( $items ) {
                foreach ( $items as $item ) {
                    $product = $item->get_product();

                    $line_item = array(
                        'name'      => $item->get_name(),
                        'unitPrice' => $this->formattedPrice( $product->get_price_excluding_tax() ),
                    );

                    if( $item->get_quantity() ) {
                        $line_item['quantity'] = $item->get_quantity();
                    }

                    if( $product->get_sku() ) {
                        $line_item['sku'] = $product->get_sku();
                    }

                    $line_items[] = $line_item;
                }
            }

            if ( ! empty( $line_items ) ) {
				$order_summary = array(
					'id'          => $order_prefix,
					'description' => $description,
					'item'        => $line_items,
					'itemAmount'  => (float) ( $this->getOrderItemAmount() ?: 0 ),
					'currency'    => get_woocommerce_currency(),
				);
			} else {
				$order_summary = array(
					'id'          => $order_prefix,
					'description' => $description,
					'itemAmount'  => (float) ( $this->getOrderItemAmount() ?: 0 )
				);
			}

            if( $shipping_fee > 0 ) {
                $order_summary['shippingAndHandlingAmount'] = $this->formattedPrice( $shipping_fee );
            }

            if( $this->order->get_total_tax() > 0 ) {
                $order_summary['taxAmount'] = $this->formattedPrice( $this->getOrderTax() );
            }

            if( $this->order->get_total_discount() ) {
                $order_summary['discount']['amount'] = $this->formattedPrice( $this->order->get_total_discount() );
            }
        } elseif( 'yes' === $this->gateway->hf_enabled ) {
			$order_summary = array(
				'id'          => $order_prefix,
				'description' => $description,
				'itemAmount'  => $this->formattedPrice( $this->getOrderItemAmount() ),
			);

			if( $shipping_fee ) {
				$order_summary['shippingAndHandlingAmount'] = $this->formattedPrice( $shipping_fee );
			}

			if( $this->order->get_total_tax() ) {
				$order_summary['taxAmount'] = $this->formattedPrice( $this->getOrderTax() );
			}

			if( $this->order->get_total_discount() ) {
				$order_summary['discount']['amount'] = $this->formattedPrice( $this->order->get_total_discount() );
			}
		} else {
			$order_summary = array(
				'id'          => $order_prefix,
				'description' => $description
			);
		}

        return array_merge(
            $order_summary,
            $this->getOrder()
        );
    }

	/**
	 * Retrieves the order information.
	 *
	 * @return array
	 */
	public function getOrder() { // phpcs:ignore
		return array(
			'amount'   => $this->formattedPrice( $this->order->get_total() ),
			'currency' => get_woocommerce_currency(),
		);
	}

	/**
	 * Get order item amount.
	 *
	 * @return array
	 */
	public function getOrderTax() { // phpcs:ignore
		$order_total = (float) $this->order->get_total();
		$tax         = (float) $this->order->get_total_tax(); 
		$itemAmount  = (float) $this->getOrderItemAmount();
		$shipping    = (float) $this->getOrderShippingAndHandling();
		$discount    = (float) $this->order->get_total_discount();
		$calculated  = $itemAmount + $shipping + $tax - $discount;
		$delta       = wc_format_decimal( $order_total - $calculated, 2 );

		if ( abs( $delta ) > 0 ) {
			$tax += $delta;
		}

		if ( 'yes' !== get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
			$tax = wc_round_tax_total( $tax );
		}

		return (float) $tax;
	}

	/**
	 * Get order item amount.
	 *
	 * @return array
	 */
	public function getOrderItemAmount() { // phpcs:ignore
		if ( wc_prices_include_tax() ) {
			$item_amount = (float) wc_round_tax_total( $this->order->get_subtotal() );
		} else {
			$item_amount = (float) $this->order->get_subtotal();
		}

		return (float) $item_amount;
	}

	/**
	 * Get order shipping and handling.
	 *
	 * @return array
	 */
	public function getOrderShippingAndHandling() { // phpcs:ignore
		$handling_fee  = 0;
		$fees          = $this->order->get_fees();
		$shipping      = (float) $this->order->get_shipping_total();
		
		if ( ! empty( $fees ) ) {
            foreach ( $fees as $fee ) {
                $handling_fee += (float) ( $fee->get_total() ?: 0 );
            }
        }

		return (float) ( $shipping + $handling_fee );
	}

	/**
	 * Retrieves the surcharge information.
	 *
	 * @return array
	 */
	public function getSurcharge() { // phpcs:ignore
		$surcharge_fee = $this->order->get_meta( '_mpgs_surcharge_fee' );

		if( $surcharge_fee > 0 ) {
			return array(
				'amount' => $this->formattedPrice( $surcharge_fee ),
				'type'   => 'SURCHARGE'
			);
		} else {
			return array(
				'amount' => 0,
				'type'   => 'SURCHARGE'
			);
		}
	}

	/**
	 * Formatted price with proper precision handling.
	 *
	 * @param float $price Unformatted price.
	 * @return float
	 */
	public function formattedPrice( $price ) { // phpcs:ignore
		// Ensure proper rounding to avoid precision issues.
		return (float) number_format( NumberUtil::round( $price, wc_get_price_decimals() ), wc_get_price_decimals(), '.', '' );
	}

	/**
	 * Retrieves the interaction data.
	 *
	 * @param bool        $capture Capture status.
	 * @param string|null $return_url Return URL.
	 *
	 * @return array
	 */
	public function getInteraction( $capture = true, $return_url = null ) { // phpcs:ignore
		$merchant_interaction = array();

		if( 'yes' === $this->gateway->mif_enabled ) {
			$merchant_name  = $this->gateway->get_option( 'merchant_name' );
			$sitename       = get_bloginfo( 'name', 'display' );
			$merchant_name  = $merchant_name ? preg_replace( "/['\"]/", '', $merchant_name ) : $sitename;
			$merchant_name  = mb_substr( $merchant_name, 0, 39 );

			$merchant       = array(
				'name'    => esc_html( $merchant_name ),
				'url'     => $this->api_url,
				'address' => array( 
					'line1' => mb_substr( $this->gateway->get_option( 'merchant_address_line1' ), 0, 100 ),
					'line2' => mb_substr( $this->gateway->get_option( 'merchant_address_line2' ), 0, 100 ),
					'line3' => mb_substr( $this->gateway->get_option( 'merchant_address_line3' ), 0, 100 ),
					'line4' => mb_substr( $this->gateway->get_option( 'merchant_address_line4' ), 0, 100 ),
				)
			);

			if( $this->gateway->get_option( 'merchant_email' ) ) {
				$merchant['email'] = $this->gateway->get_option( 'merchant_email' );
			}

			if( $this->gateway->get_option( 'merchant_logo' ) ) {
				$merchant['logo'] = self::force_https_url( $this->gateway->get_option( 'merchant_logo' ) );
			}

			if( $this->gateway->get_option( 'merchant_phone' ) ) {
				$merchant['phone'] = $this->getExcerpt( $this->gateway->get_option( 'merchant_phone' ), 20 );
			}

			$merchant_interaction['merchant'] = $merchant;
		} else {
			$sitename = $this->getExcerpt( get_bloginfo( 'name', 'display' ), 39 );
			$merchant_interaction['merchant']['name'] = $sitename;
			$merchant_interaction['merchant']['url']  = $this->api_url;
		}

		$interaction = array_merge(
			$merchant_interaction,
			array(
				'returnUrl'      => $return_url,
				'displayControl' => array(
					'customerEmail'  => 'HIDE',
					'billingAddress' => 'HIDE',
					'paymentTerms'   => 'HIDE',
					'shipping'       => 'HIDE',
				),
				'operation'      => $capture ? 'PURCHASE' : 'AUTHORIZE',
				//'locale'         => $this->gateway->get_option( 'locale' )
			)
		);

		return $interaction; 
	}

	/**
	 * Retrieves the interaction data.
	 *
	 * @param bool        $capture Capture status.
	 * @param string|null $return_url Return URL.
	 *
	 * @return array
	 * @deprecated
	 */
	public function getLegacyInteraction( $capture = true, $return_url = null ) { // phpcs:ignore
		$merchant_name = $this->gateway->get_option( 'merchant_name' );
		$sitename      = get_bloginfo( 'name', 'display' );
		$merchant_name = $merchant_name ? $merchant_name : $sitename;
		$merchant_name = $this->getExcerpt( $merchant_name, 39 );

		return array(
			'operation'      => $capture ? 'PURCHASE' : 'AUTHORIZE',
			'merchant'       => array(
				'name' => esc_html( $merchant_name ),
			),
			'returnUrl'      => $return_url,
			'displayControl' => array(
				'shipping'            => 'HIDE',
				'billingAddress'      => 'HIDE',
				'orderSummary'        => 'HIDE',
				'paymentConfirmation' => 'HIDE',
				'customerEmail'       => 'HIDE',
			),
		);
	}

	/**
	 * Create an excerpt from a given text.
	 *
	 * @param string $text The text to create an excerpt from.
	 * @param int $length The length of the excerpt (number of words).
	 * @return string The excerpt.
	 */
	public function getExcerpt( $text, $length = 50 ) {
	    if ( strlen( $text ) > $length ) {
	        $excerpt = substr( $text, 0, $length );
	    } else {
	        $excerpt = $text;
	    }
	    
	    return $this->attempt_transliteration( $excerpt );
	}

	/**
	 * Force https for urls.
	 *
	 * @param mixed $content
	 * @return string
	 */
	public static function force_https_url( $url ) {
		return str_replace( 'http:', 'https:', (string) $url );
	}

	/**
	 * Attempts to transliterate the given field into a standard ASCII format.
	 *
	 * This function is typically used to ensure that text fields are free of 
	 * special characters or non-ASCII characters, which may cause issues in 
	 * processing, storage, or compatibility with external systems.
	 *
	 * @param mixed $field The field to be transliterated. This can be a string 
	 *                     or another data type that needs to be processed.
	 * 
	 * @return mixed The transliterated value if the operation is successful, 
	 *               or the original field if transliteration is not applicable.
	 */
	public function attempt_transliteration( $field ) {
        $encode = mb_detect_encoding( $field );
        if ( $encode !== 'ASCII' ) {
            if ( function_exists( 'transliterator_transliterate' ) ) {
                $field = transliterator_transliterate( 'Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $field );
            } else {
                // fall back to iconv if intl module not available
                $field = remove_accents( $field );
                $field = iconv( $encode, 'ASCII//TRANSLIT//IGNORE', $field );
                $field = str_ireplace( '?', '', $field );
                $field = trim( $field );
            }
        }

        return $field;
    }
}
