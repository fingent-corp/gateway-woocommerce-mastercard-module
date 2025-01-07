<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package  Mastercard
 * @version  GIT: @1.4.7@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main class of the Mastercard Checkout Builder
 *
 * Represents a gateway service for processing Mastercard transactions.
 */
class Mastercard_CheckoutBuilder {
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
		$this->gateway = new Mastercard_Gateway();
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
		return MPGS_ISO3_COUNTRIES[ $iso2_country ];
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
		return array(
			'address' => array(
				'street'        => self::is_safe( $this->order->get_billing_address_1(), 100 ),
				'street2'       => self::is_safe( $this->order->get_billing_address_2(), 100 ),
				'city'          => self::is_safe( $this->order->get_billing_city(), 100 ),
				'postcodeZip'   => self::is_safe( $this->order->get_billing_postcode(), 10 ),
				'country'       => $this->iso2ToIso3( $this->order->get_billing_country() ),
				'stateProvince' => self::is_safe( $this->order->get_billing_state(), 20 ),
			),
		);
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

		return array(
			'address' => array(
				'street'        => self::is_safe( $this->order->get_shipping_address_1(), 100 ),
				'street2'       => self::is_safe( $this->order->get_shipping_address_2(), 100 ),
				'city'          => self::is_safe( $this->order->get_shipping_city(), 100 ),
				'postcodeZip'   => self::is_safe( $this->order->get_shipping_postcode(), 10 ),
				'country'       => $this->iso2ToIso3( $this->order->get_shipping_country() ),
				'stateProvince' => self::is_safe( $this->order->get_shipping_state(), 20 ),
			),
			'contact' => array(
				'firstName' => self::is_safe( $this->order->get_shipping_first_name(), 50 ),
				'lastName'  => self::is_safe( $this->order->get_shipping_last_name(), 50 ),
			),
			
		);
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
		if ( isset( $this->gateway->hf_enabled ) && 'yes' === $this->gateway->hf_enabled ) {
			$handling_fee = $this->order->get_meta( '_mpgs_handling_fee' );
		} else {
			$handling_fee = 0;
		}
		$shipping_fee = (float)( $handling_fee ) + (float) $this->order->get_shipping_total();

		if( 'yes' === $this->gateway->send_line_items ) {
			$line_items = array(); 
			$items = $this->order->get_items();

			if ( $items ) {
				foreach ( $items as $item ) {
					$product = $item->get_product();
					$line_items[] = array(
						'name'      => $item->get_name(),
						'quantity'  => $item->get_quantity(),
						'sku'       => $product->get_sku(),
						'unitPrice' => $this->formattedPrice( $product->get_price() ),
					);
				}
			}

			$order_summary = array(
				'id'          => (string) $this->gateway->add_order_prefix( $this->order->get_id() ),
				'description' => 'Customer Order Summary',
				'item'        => $line_items,
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
		} else {
			$order_summary = array(
				'id'          => (string) $this->gateway->add_order_prefix( $this->order->get_id() ),
				'description' => 'Customer Order Summary',
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
		$order_total = $this->order->get_total();
		return array(
			'amount'   => $this->formattedPrice( $order_total ),
			'currency' => get_woocommerce_currency(),
		);
	}

	/**
	 * Formatted price.
	 *
	 * @param float $price Unformatted price.
	 * @return string
	 */
	public function formattedPrice( $price ) { // phpcs:ignore
		$original_price = $price;
		$args           = array(
			'currency'          => '',
			'decimal_separator' => wc_get_price_decimal_separator(),
			'decimals'          => wc_get_price_decimals(),
			'price_format'      => get_woocommerce_price_format(),
		);
		$price          = apply_filters( 'formatted_mastercard_price', number_format( $price, $args['decimals'], $args['decimal_separator'], '' ), $price, $args['decimals'], $args['decimal_separator'], '', $original_price );

		return $price;
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
