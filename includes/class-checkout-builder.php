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
 * @version  GIT: @1.4.5@
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
	 * Mastercard_Model_AbstractBuilder constructor.
	 *
	 * @param array $order WC_Order.
	 */
	public function __construct( $order ) {
		$this->order = $order;
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
	public static function safe( $value, $limited = 0 ) {
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
				'street'        => self::safe( $this->order->get_billing_address_1(), 100 ),
				'street2'       => self::safe( $this->order->get_billing_address_2(), 100 ),
				'city'          => self::safe( $this->order->get_billing_city(), 100 ),
				'postcodeZip'   => self::safe( $this->order->get_billing_postcode(), 10 ),
				'country'       => $this->iso2ToIso3( $this->order->get_billing_country() ),
				'stateProvince' => self::safe( $this->order->get_billing_state(), 20 ),
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
		if ( empty( $this->order->get_shipping_address_1() ) ) {
			return true;
		}

		if ( empty( $this->order->get_shipping_first_name() ) ) {
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
				'street'        => self::safe( $this->order->get_shipping_address_1(), 100 ),
				'street2'       => self::safe( $this->order->get_shipping_address_2(), 100 ),
				'city'          => self::safe( $this->order->get_shipping_city(), 100 ),
				'postcodeZip'   => self::safe( $this->order->get_shipping_postcode(), 10 ),
				'country'       => $this->iso2ToIso3( $this->order->get_shipping_country() ),
				'stateProvince' => self::safe( $this->order->get_shipping_state(), 20 ),
			),
			'contact' => array(
				'firstName' => self::safe( $this->order->get_shipping_first_name(), 50 ),
				'lastName'  => self::safe( $this->order->get_shipping_last_name(), 50 ),
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
			'firstName' => self::safe( $this->order->get_billing_first_name(), 50 ),
			'lastName'  => self::safe( $this->order->get_billing_last_name(), 50 ),
		);
	}

	/**
	 * Retrieves the hosted checkout order information.
	 *
	 * @return array
	 */
	public function getHostedCheckoutOrder() { // phpcs:ignore
		$gateway      = new Mastercard_Gateway();
		return array_merge(
			array(
				'id'          => (string) $gateway->add_order_prefix( $this->order->get_id() ),
				'description' => 'Ordered goods',
			),
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
		return array(
			'merchant'       => array(
				'name' => esc_html( get_bloginfo( 'name', 'display' ) ),
			),
			'returnUrl'      => $return_url,
			'displayControl' => array(
				'customerEmail'  => 'HIDE',
				'billingAddress' => 'HIDE',
				'paymentTerms'   => 'HIDE',
				'shipping'       => 'HIDE',
			),
			'operation'      => $capture ? 'PURCHASE' : 'AUTHORIZE',
		);
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
		return array(
			'operation'      => $capture ? 'PURCHASE' : 'AUTHORIZE',
			'merchant'       => array(
				'name' => esc_html( get_bloginfo( 'name', 'display' ) ),
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
}
