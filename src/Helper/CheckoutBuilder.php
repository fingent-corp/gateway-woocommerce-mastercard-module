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
        $handling_fee  = 0;
        $order_summary = array();
        $fees          = $this->order->get_fees(); 
		$locale        = $this->gateway->get_option( 'locale' );
		$description   = Countries::get_instance()->get_order_summary_text($locale );

        if ( ! empty( $fees ) ) {
            foreach ( $fees as $fee ) {
                $handling_fee += $fee->get_total();
            }
        }

        $shipping_fee = (float)( $handling_fee ) + (float) $this->order->get_shipping_total();

        if( 'yes' === $this->gateway->send_line_items ) {
            $line_items = $line_items = array(); 
            $items = $this->order->get_items();

            if ( $items ) {
                foreach ( $items as $item ) {
                    $product = $item->get_product();
                    $line_item = array(
                        'name'      => $this->getExcerpt( $item->get_name(), 127 ),
                        'quantity'  => $item->get_quantity(),
                        'sku'       => $product->get_sku(),
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
					'id'          => (string) PaymentController::get_instance()->add_order_prefix( $this->order->get_id() ),
					'description' => $description,
					'item'        => $line_items,
					'itemAmount'  => $this->formattedPrice( $this->order->get_subtotal() )
				);
			} else {
				$order_summary = array(
					'id'          => (string) PaymentController::get_instance()->add_order_prefix( $this->order->get_id() ),
					'description' => $description,
					'itemAmount'  => $this->formattedPrice( $this->order->get_subtotal() )
				);
			}

            if( $shipping_fee ) {
                $order_summary['shippingAndHandlingAmount'] = $this->formattedPrice( $shipping_fee );
            }

            if( $this->order->get_total_tax() ) {
                $order_summary['taxAmount'] = $this->formattedPrice( $this->order->get_total_tax() );
            }

            if( $this->order->get_total_discount() ) {
                $order_summary['discount']['amount'] = $this->formattedPrice( $this->order->get_total_discount() );
            }
        
            return array_merge(
                $order_summary,
                $this->getOrder()
            );
        } else {
            $order_summary = array(
                'id'          => (string) PaymentController::get_instance()->add_order_prefix( $this->order->get_id() ),
                'description' => $description,
                'itemAmount'  => $this->formattedPrice( $this->order->get_subtotal() ),
            );

            if( $shipping_fee ) {
                $order_summary['shippingAndHandlingAmount'] = $this->formattedPrice( $shipping_fee );
            }

            if( $this->order->get_total_tax() ) {
                $order_summary['taxAmount'] = $this->formattedPrice( $this->order->get_total_tax() );
            }

            if( $this->order->get_total_discount() ) {
                $order_summary['discount']['amount'] = $this->formattedPrice( $this->order->get_total_discount() );
            }

            return array_merge(
                $order_summary,
                $this->getOrder()
            );
        }
    }

	/**
	 * Retrieves the order information.
	 *
	 * @return array
	 */
	public function getOrder() { // phpcs:ignore
		// Safe numeric getters with fallback to 0
		$itemAmount  = (float) ( $this->getOrderItemAmount() ?: 0 );
		$taxAmount   = (float) ( $this->getOrderTax() ?: 0 );
		$discount    = (float) ( $this->formattedPrice( $this->order->get_total_discount() ) ?: 0 );

		$handlingFee = 0;

		// Fees safe loop
		$fees = $this->order->get_fees();
		if ( ! empty( $fees ) ) {
			foreach ( $fees as $fee ) {
				$handlingFee += (float) ( $fee->get_total() ?: 0 );
			}
		}

		// Safe shipping total
		$shippingTotal = (float) ( $this->order->get_shipping_total() ?: 0 ) + $handlingFee;

		// Final total
		$orderTotal = ( $itemAmount + $taxAmount + $shippingTotal ) - $discount;

		return array(
			'amount'   => $this->formattedPrice( $orderTotal ),
			'currency' => get_woocommerce_currency(),
		);
	}

	/**
	 * Get order item amount.
	 *
	 * @return array
	 */
	public function getOrderTax() { // phpcs:ignore
		$tax = $this->order->get_total_tax(); 

		if ( 'yes' !== get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
			$tax = wc_round_tax_total( $tax );
		}

		return $this->formattedPrice( $tax );
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

		return $this->formattedPrice( $item_amount );
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
	 * Formatted price.
	 *
	 * @param float $price Unformatted price.
	 * @return string
	 */
	public function formattedPrice( $price ) { // phpcs:ignore
		return number_format( NumberUtil::round( $price, wc_get_price_decimals() ), wc_get_price_decimals(), '.' , '' );
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
			$merchant_name  = $this->getExcerpt( $merchant_name, 39 );

			$merchant       = array(
				'name'    => esc_html( $merchant_name ),
				'url'     => $this->api_url,
				'address' => array( 
					'line1'	=> $this->getExcerpt( $this->gateway->get_option( 'merchant_address_line1' ), 100 ),
					'line2'	=> $this->getExcerpt( $this->gateway->get_option( 'merchant_address_line2' ), 100 ),
					'line3'	=> $this->getExcerpt( $this->gateway->get_option( 'merchant_address_line3' ), 100 ),
					'line4'	=> $this->getExcerpt( $this->gateway->get_option( 'merchant_address_line4' ), 100 )
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
				'locale'         => $this->gateway->get_option( 'locale' ),
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

	/**
	 * Retrieves the payment link order information.
	 *
	 * @return array
	 */
	public function getPaymentLinkOrder( $order_id ) { // phpcs:ignore
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [];
		}

		$handling_fee  = 0;
		$order_summary = array();
		$fees          = $order->get_fees();

		if ( ! empty( $fees ) ) {
			foreach ( $fees as $fee ) {
				$handling_fee += $fee->get_total();
			}
		}

		$shipping_fee = (float) $handling_fee + (float) $order->get_shipping_total();

		

		if ( 'yes' === $this->gateway->send_line_items ) {
			$line_items = array();
			foreach ( $order->get_items() as $item ) {
				$product   = $item->get_product();
				$sku       = $product ? $product->get_sku() : '';
				$unitPrice = $product ? $this->formattedPrice( $product->get_price_excluding_tax() ) : $this->formattedPrice( $item->get_total() );

				$line_items[] = array(
					'name'      => $item->get_name(),
					'quantity'  => $item->get_quantity(),
					'sku'       => $sku,
					'unitPrice' => $unitPrice,
				);
			}

			$order_summary = array(
				'id'          => (string) PaymentController::get_instance()->add_order_prefix( $order->get_id() ),
				'description' => 'Payment Link Order',
				'item'        => $line_items,
				'itemAmount'  => $this->formattedPrice( $order->get_subtotal() ),
			);

		} else {
			$order_summary = array(
				'id'          => (string) PaymentController::get_instance()->add_order_prefix( $order->get_id() ),
				'description' => 'Payment Link Order',
				'itemAmount'  => $this->formattedPrice( $order->get_subtotal() ),
			);
		}

		if ( $shipping_fee ) {
			$order_summary['shippingAndHandlingAmount'] = $this->formattedPrice( $shipping_fee );
		}

		if ( $order->get_total_tax() ) {
			$order_summary['taxAmount'] = $this->formattedPrice( $order->get_total_tax() );
		}

		if ( $order->get_total_discount() ) {
			$order_summary['discount']['amount'] = $this->formattedPrice( $order->get_total_discount() );
		}

		
		$order_summary['amount']   = $this->formattedPrice( $order->get_total() );
		$order_summary['currency'] = $order->get_currency();

		return $order_summary;
	}

	public function getPaymentLinkSettings() {
		
		$value    = $this->gateway->get_option( 'payment_expiry_value' );
		$unit     = $this->gateway->get_option( 'payment_expiry_unit' );
		$attempts = $this->gateway->get_option( 'payment_allowed_attempts' );

		// Default expiry: 3 months
		if ( empty( $value ) || empty( $unit ) ) {
			$expiry_timestamp = strtotime( '+3 months' );
		} else {
			switch ( $unit ) {
				case 'hours':
					$expiry_timestamp = strtotime( '+' . intval( $value ) . ' hours' );
					break;

				case 'days':
					$expiry_timestamp = strtotime( '+' . intval( $value ) . ' days' );
					break;

				case 'months':
					$days            = intval( $value ) * 30;
					$expiry_timestamp = strtotime( '+' . $days . ' days' );
					break;
				
				default:
					$expiry_timestamp = strtotime( '+3 months' );
			}
		}

		// Format with milliseconds
		$expiry = gmdate( 'Y-m-d\TH:i:s', $expiry_timestamp ) . '.000Z';

		if ( empty( $attempts ) ) {
			$attempts = 25;
		}

		return array(
			'expiryDateTime'          => $expiry,
			'numberOfAllowedAttempts' => absint( $attempts ),
		);
	}

	public function getBillingFromOrder( $order_id ) {
		$order   = wc_get_order( $order_id );
		$billing = array();

		if ( ! $order ) {
			return $billing;
		}

		// Safest: get billing address array directly
		$billing_data = $order->get_address( 'billing' );
		$fields = array( 
			'street'        => array( 'key' => 'address_1', 'length' => 100 ),
			'street2'       => array( 'key' => 'address_2', 'length' => 100 ),
			'city'          => array( 'key' => 'city',      'length' => 100 ),
			'postcodeZip'   => array( 'key' => 'postcode',  'length' => 10 ),
			'stateProvince' => array( 'key' => 'state',     'length' => 20 ),
		);

		foreach ( $fields as $key => $field ) {
			$value = $billing_data[ $field['key'] ] ?? '';
			if ( $value ) {
				$billing['address'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		$country = $order->get_billing_country();

		if ( $country ) {
			$billing['address']['country'] = $this->iso2ToIso3( $country );
		}
		

		return $billing;
	}


	public function getShippingFromOrder( $order_id ) {
		$order    = wc_get_order( $order_id );
		$shipping = array();

		if ( ! $order ) {
			return $shipping;
		}

		// Skip if order is virtual (same as your current check)
		if ( $order->get_shipping_total() == 0 && ! $order->get_shipping_first_name() && ! $order->get_shipping_address_1() ) {
			return null;
		}

		$addressFields = array(
			'street'        => array( 'method' => 'get_shipping_address_1', 'length' => 100 ),
			'street2'       => array( 'method' => 'get_shipping_address_2', 'length' => 100 ),
			'city'          => array( 'method' => 'get_shipping_city',      'length' => 100 ),
			'postcodeZip'   => array( 'method' => 'get_shipping_postcode',  'length' => 10 ),
			'stateProvince' => array( 'method' => 'get_shipping_state',     'length' => 20 ),
		);

		foreach ( $addressFields as $key => $field ) {
			$value = $order->{ $field['method'] }();
			if ( $value ) {
				$shipping['address'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		$country = $order->get_shipping_country();
		if ( $country ) {

			$shipping['address']['country'] =  $this->iso2ToIso3( $country );
		}

		$contactFields = array( 
			'firstName' => array( 'method' => 'get_shipping_first_name', 'length' => 50 ),
			'lastName'  => array( 'method' => 'get_shipping_last_name',  'length' => 50 ),
		);

		foreach ( $contactFields as $key => $field ) {
			$value = $order->{ $field['method'] }();
			if ( $value ) {
				$shipping['contact'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		return $shipping;
	}

}
