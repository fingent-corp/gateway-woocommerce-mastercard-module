<?php
namespace Fingent\Mastercard\Helper;

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
	 * Public store URL sent to MPGS as interaction.merchant.url (not the API gateway host).
	 *
	 * @return string
	 */
	protected function get_merchant_site_url() {
		$url = home_url( '/' );

		// MPGS requires a secure merchant URL for hosted / embedded checkout.
		if ( is_ssl() || 'yes' === $this->gateway->get_option( 'sandbox' ) ) {
			$url = self::force_https_url( $url );
		}

		return esc_url_raw( $url );
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
		if ( ! is_string( $value ) ) {
			$value = (string) $value;
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		if ( $limited > 0 && strlen( $value ) > $limited ) {
			return substr( $value, 0, $limited );
		}

		return $value;
	}

	/**
	 * Remove null values and blank strings from API payloads.
	 *
	 * MPGS rejects empty strings for several fields (minimum length 2).
	 *
	 * @param mixed $data Request payload or nested array.
	 * @return mixed Sanitized payload.
	 */
	public static function filterEmptyStrings( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$filtered = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::filterEmptyStrings( $value );
				if ( empty( $value ) ) {
					continue;
				}
			} elseif ( null === $value ) {
				continue;
			} elseif ( is_string( $value ) && '' === trim( $value ) ) {
				continue;
			}

			$filtered[ $key ] = $value;
		}

		return $filtered;
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
		return self::filterEmptyStrings(
			array(
				'email'     => self::is_safe( $this->order->get_billing_email(), 255 ),
				'firstName' => self::is_safe( $this->order->get_billing_first_name(), 50 ),
				'lastName'  => self::is_safe( $this->order->get_billing_last_name(), 50 ),
			)
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
			$line_items = $this->buildOrderLineItems( $this->order->get_items(), true );

			if ( ! empty( $line_items ) ) {
				$order_summary = array(
					'id'          => (string) PaymentController::get_instance()->add_order_prefix( $this->order->get_id() ),
					'description' => $description,
					'item'        => $line_items,
				);
			} else {
				$order_summary = array(
					'id'          => (string) PaymentController::get_instance()->add_order_prefix( $this->order->get_id() ),
					'description' => $description,
				);
			}

			$order_summary = $this->appendOrderBreakdownAmounts( $order_summary, $shipping_fee );

			return $this->finalizeHostedOrderPayload( $order_summary );
        } else {
            $order_summary = array(
                'id'          => (string) PaymentController::get_instance()->add_order_prefix( $this->order->get_id() ),
                'description' => $description,
            );

			$order_summary = $this->appendOrderBreakdownAmounts( $order_summary, $shipping_fee );

			return $this->finalizeHostedOrderPayload( $order_summary );
        }
    }

	/**
	 * Append tax, shipping/handling, and discount fields for MPGS order breakdown.
	 *
	 * @param array $order_summary Order summary payload.
	 * @param float $shipping_fee  Combined shipping and fee total.
	 *
	 * @return array
	 */
	private function appendOrderBreakdownAmounts( array $order_summary, $shipping_fee ) {
		if ( ! isset( $order_summary['itemAmount'] ) ) {
			$order_summary['itemAmount'] = $this->getOrderItemAmount();
		}

		if ( $shipping_fee ) {
			$order_summary['shippingAndHandlingAmount'] = $this->formattedPrice( $shipping_fee );
		}

		if ( $this->order->get_total_tax() ) {
			$order_summary['taxAmount'] = $this->getOrderTax();
		}

		if ( $this->order->get_total_discount() ) {
			$order_summary['discount']['amount'] = $this->formattedPrice( $this->order->get_total_discount() );
		}

		return $order_summary;
	}

	/**
	 * Normalize, merge order total, and reconcile breakdown amounts for MPGS.
	 *
	 * @param array $order_summary Order summary payload.
	 *
	 * @return array
	 */
	private function finalizeHostedOrderPayload( array $order_summary ) {
		$payload = self::normalizeMonetaryFields(
			array_merge(
				$order_summary,
				$this->getOrder()
			)
		);

		if ( ! empty( $payload['item'] ) && is_array( $payload['item'] ) ) {
			$payload['itemAmount'] = self::sumLineItemsAmount( $payload['item'] );
		}

		return self::reconcileOrderAmounts( $payload );
	}

	/**
	 * Build MPGS line items from WooCommerce order rows (unitPrice * quantity = line subtotal).
	 *
	 * @param array $items       WooCommerce order items.
	 * @param bool  $use_excerpt Whether to truncate product names.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildOrderLineItems( $items, $use_excerpt = true ) {
		$line_items = array();

		if ( empty( $items ) ) {
			return $line_items;
		}

		foreach ( $items as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$qty           = max( 1, (int) $item->get_quantity() );
			$line_subtotal = (float) $item->get_subtotal();
			$unit_price    = $line_subtotal / $qty;
			$product       = $item->get_product();

			$line_item = array(
				'name'      => $use_excerpt ? $this->getExcerpt( $item->get_name(), 127 ) : $item->get_name(),
				'quantity'  => $qty,
				'unitPrice' => $this->formattedPrice( $unit_price ),
			);

			$sku = $product ? self::is_safe( $product->get_sku(), 127 ) : null;
			if ( $sku ) {
				$line_item['sku'] = $sku;
			}

			$line_items[] = $line_item;
		}

		return $line_items;
	}

	/**
	 * Sum quantity * unitPrice for all MPGS line items (matches gateway validation).
	 *
	 * @param array $line_items Normalized line items.
	 * @return string
	 */
	public static function sumLineItemsAmount( array $line_items ) {
		$decimals   = max( 0, (int) wc_get_price_decimals() );
		$multiplier = 10 ** $decimals;
		$total_minor = 0;

		foreach ( $line_items as $line_item ) {
			$qty = max( 1, (int) ( $line_item['quantity'] ?? 1 ) );

			if ( function_exists( 'wc_string_to_num' ) ) {
				$unit = wc_string_to_num( $line_item['unitPrice'] ?? 0 );
			} else {
				$unit = (float) ( $line_item['unitPrice'] ?? 0 );
			}

			$unit_minor   = (int) round( (float) $unit * $multiplier );
			$total_minor += $unit_minor * $qty;
		}

		return self::formatAmountString( $total_minor / $multiplier );
	}

	/**
	 * Retrieves the order information.
	 *
	 * @return array
	 */
	public function getOrder() { // phpcs:ignore
		return array(
			'amount'   => $this->formattedPrice( $this->order->get_total() ),
			'currency' => $this->order->get_currency(),
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
				'amount' => self::formatAmountString( 0 ),
				'type'   => 'SURCHARGE'
			);
		}
	}

	/**
	 * Format a monetary value for MPGS (max 2 decimal places, no float artifacts).
	 *
	 * @param float|string $price Unformatted price.
	 * @return string
	 */
	public function formattedPrice( $price ) { // phpcs:ignore
		return self::formatAmountString( $price );
	}

	/**
	 * Format amount as a decimal string suitable for gateway API fields.
	 *
	 * @param float|int|string $amount Amount value.
	 * @return string
	 */
	public static function formatAmountString( $amount ) {
		$decimals = max( 0, (int) wc_get_price_decimals() );

		if ( function_exists( 'wc_string_to_num' ) ) {
			$amount = wc_string_to_num( $amount );
		} elseif ( is_numeric( $amount ) ) {
			$amount = (float) $amount;
		} else {
			$amount = 0.0;
		}

		// Round via integer minor-units to avoid float artifacts (e.g. 43.899999999999999).
		$multiplier = 10 ** $decimals;
		$minor      = (int) round( $amount * $multiplier );

		return number_format( $minor / $multiplier, $decimals, '.', '' );
	}

	/**
	 * Prepare a gateway API request payload (normalize amounts, remove empty fields).
	 *
	 * @param array $request_data Request body array.
	 * @return array
	 */
	public static function prepareGatewayRequestData( array $request_data ) {
		$request_data = self::normalizeMonetaryFields( $request_data );

		if ( isset( $request_data['order'] ) && is_array( $request_data['order'] ) ) {
			$request_data['order'] = self::reconcileOrderAmounts( $request_data['order'] );
		}

		return self::filterEmptyStrings( $request_data );
	}

	/**
	 * Normalize monetary fields in an order payload before sending to MPGS.
	 *
	 * @param array $data Order or nested order data.
	 * @return array
	 */
	public static function normalizeMonetaryFields( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$amount_keys = array(
			'amount',
			'itemAmount',
			'unitPrice',
			'taxAmount',
			'shippingAndHandlingAmount',
		);

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'discount', 'merchantCharge' ), true ) && is_array( $value ) && array_key_exists( 'amount', $value ) ) {
				$data[ $key ]['amount'] = self::formatAmountString( $value['amount'] );
			} elseif ( 'item' === $key && is_array( $value ) ) {
				foreach ( $value as $index => $line_item ) {
					$data[ $key ][ $index ] = self::normalizeMonetaryFields( $line_item );
				}
			} elseif ( in_array( $key, $amount_keys, true ) ) {
				$data[ $key ] = self::formatAmountString( $value );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::normalizeMonetaryFields( $value );
			}
		}

		return $data;
	}

	/**
	 * Ensure MPGS breakdown fields sum to order.amount (WooCommerce total).
	 *
	 * MPGS validates: itemAmount + taxAmount + shippingAndHandlingAmount - discount = amount.
	 * Per-field rounding can drift by one cent from the WooCommerce order total.
	 *
	 * @param array $order Normalized order payload.
	 * @return array
	 */
	public static function reconcileOrderAmounts( array $order ) {
		if ( empty( $order['amount'] ) || ! isset( $order['itemAmount'] ) ) {
			return $order;
		}

		$decimals   = max( 0, (int) wc_get_price_decimals() );
		$multiplier = 10 ** $decimals;

		$to_minor = static function ( $value ) use ( $multiplier ) {
			if ( function_exists( 'wc_string_to_num' ) ) {
				$value = wc_string_to_num( $value );
			}

			return (int) round( (float) $value * $multiplier );
		};

		$amount_minor    = $to_minor( $order['amount'] );
		$item_minor      = $to_minor( $order['itemAmount'] );
		$tax_minor       = $to_minor( $order['taxAmount'] ?? 0 );
		$shipping_minor  = $to_minor( $order['shippingAndHandlingAmount'] ?? 0 );
		$discount_minor  = 0;

		if ( isset( $order['discount']['amount'] ) ) {
			$discount_minor = $to_minor( $order['discount']['amount'] );
		}

		$computed_minor = $item_minor + $tax_minor + $shipping_minor - $discount_minor;
		$diff           = $amount_minor - $computed_minor;

		if ( 0 === $diff ) {
			return $order;
		}

		$has_line_items = ! empty( $order['item'] ) && is_array( $order['item'] );

		// When line items are sent, itemAmount must equal sum(qty * unitPrice); adjust tax/shipping instead.
		if ( $has_line_items && isset( $order['taxAmount'] ) ) {
			$order['taxAmount'] = self::formatAmountString( ( $tax_minor + $diff ) / $multiplier );
		} elseif ( $has_line_items && isset( $order['shippingAndHandlingAmount'] ) ) {
			$order['shippingAndHandlingAmount'] = self::formatAmountString( ( $shipping_minor + $diff ) / $multiplier );
		} elseif ( ! $has_line_items ) {
			$order['itemAmount'] = self::formatAmountString( ( $item_minor + $diff ) / $multiplier );
		} else {
			$order = self::absorbAmountDiffOnLastLineItem( $order, $diff, $multiplier );
			$order['itemAmount'] = self::sumLineItemsAmount( $order['item'] );
		}

		return $order;
	}

	/**
	 * Shift a minor-unit order total difference onto the last line item unit price.
	 *
	 * @param array $order      Order payload.
	 * @param int   $diff_minor Difference in minor currency units.
	 * @param int   $multiplier Minor units per major unit.
	 *
	 * @return array
	 */
	private static function absorbAmountDiffOnLastLineItem( array $order, $diff_minor, $multiplier ) {
		$items       = $order['item'];
		$last_index  = count( $items ) - 1;
		$last        = $items[ $last_index ];
		$qty         = max( 1, (int) ( $last['quantity'] ?? 1 ) );
		$unit        = function_exists( 'wc_string_to_num' ) ? wc_string_to_num( $last['unitPrice'] ?? 0 ) : (float) ( $last['unitPrice'] ?? 0 );
		$unit_minor  = (int) round( (float) $unit * $multiplier );
		$unit_minor += (int) round( $diff_minor / $qty );
		$items[ $last_index ]['unitPrice'] = self::formatAmountString( $unit_minor / $multiplier );
		$order['item']                     = $items;

		return $order;
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
		$locale               = $this->gateway->get_option( 'locale' );
		$locale               = ! empty( $locale ) ? $locale : 'en_US';

		if( 'yes' === $this->gateway->mif_enabled ) {
			$merchant_name  = $this->gateway->get_option( 'merchant_name' );
			$sitename       = get_bloginfo( 'name', 'display' );
			$merchant_name  = $merchant_name ? preg_replace( "/['\"]/", '', $merchant_name ) : $sitename;
			$merchant_name  = $this->getExcerpt( $merchant_name, 39 );

			$merchant_address = self::filterEmptyStrings(
				array(
					'line1' => self::is_safe( $this->gateway->get_option( 'merchant_address_line1' ), 100 ),
					'line2' => self::is_safe( $this->gateway->get_option( 'merchant_address_line2' ), 100 ),
					'line3' => self::is_safe( $this->gateway->get_option( 'merchant_address_line3' ), 100 ),
					'line4' => self::is_safe( $this->gateway->get_option( 'merchant_address_line4' ), 100 ),
				)
			);

			$merchant = array(
				'name' => esc_html( $merchant_name ),
				'url'  => $this->get_merchant_site_url(),
			);

			if ( ! empty( $merchant_address ) ) {
				$merchant['address'] = $merchant_address;
			}

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
			$merchant_interaction['merchant']['url']  = $this->get_merchant_site_url();
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

		$previous_order = $this->order;
		$this->order    = $order;

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
			$line_items = $this->buildOrderLineItems( $order->get_items(), false );

			$order_summary = array(
				'id'          => (string) PaymentController::get_instance()->add_order_prefix( $order->get_id() ),
				'description' => 'Payment Link Order',
				'item'        => $line_items,
			);

		} else {
			$order_summary = array(
				'id'          => (string) PaymentController::get_instance()->add_order_prefix( $order->get_id() ),
				'description' => 'Payment Link Order',
			);
		}

		$order_summary = $this->appendOrderBreakdownAmounts( $order_summary, $shipping_fee );
		$order_summary['amount']   = $this->formattedPrice( $order->get_total() );
		$order_summary['currency'] = $order->get_currency();

		$payload = self::normalizeMonetaryFields( $order_summary );

		if ( ! empty( $payload['item'] ) && is_array( $payload['item'] ) ) {
			$payload['itemAmount'] = self::sumLineItemsAmount( $payload['item'] );
		}

		$result = self::reconcileOrderAmounts( $payload );

		$this->order = $previous_order;

		return $result;
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
