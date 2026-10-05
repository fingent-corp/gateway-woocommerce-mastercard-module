<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Builds Mastercard gateway checkout and payment-link payloads from WooCommerce orders.
 *
 * @package Fingent\Mastercard\Helper
 */

namespace Fingent\Mastercard\Helper;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Helper\Countries;
use Fingent\Mastercard\Controller\PaymentController;
use Fingent\Mastercard\Exception\PluginException;

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
	 * WooCommerce order for checkout payload building.
	 *
	 * @var \WC_Order|null
	 */
	protected $order = null;

	/**
	 * Mastercard Gateway object
	 *
	 * @var MastercardGateway
	 */
	protected $gateway;

	/**
	 * Gateway URL.
	 *
	 * @var string|null
	 */
	protected $api_url = null;

	/**
	 * CheckoutBuilder constructor.
	 *
	 * @param \WC_Order|int|string $order WooCommerce order or order ID.
	 */
	public function __construct( $order ) {
		if ( $order instanceof WC_Order ) {
			$this->order = $order;
		} elseif ( is_numeric( $order ) ) {
			$this->order = self::resolve_order( (int) $order );
		}

		$this->gateway = MastercardGateway::get_instance();
		$this->api_url = 'https://' . $this->gateway->get_gateway_url();
	}

	/**
	 * Load a WooCommerce order by ID (refunds excluded).
	 *
	 * @param int $order_id Order ID.
	 * @return \WC_Order|null
	 */
	private static function resolve_order( $order_id ) {
		$order = wc_get_order( $order_id );

		return ( $order instanceof WC_Order ) ? $order : null;
	}

	/**
	 * Order instance required for payload methods.
	 *
	 * @return \WC_Order
	 * @throws PluginException When no valid order is set.
	 */
	private function require_order() {
		if ( ! $this->order instanceof WC_Order ) {
			throw new PluginException( 'CheckoutBuilder requires a valid WC_Order.' );
		}

		return $this->order;
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
	public function iso2_to_iso3( $iso2_country ) { // phpcs:ignore
		$countries = Countries::get_instance()->get_iso2_to_iso3();

		return $countries[ $iso2_country ];
	}

	/**
	 * A function that checks if a value is safe and within a specified limit.
	 *
	 * @param mixed $value - The value to be checked.
	 * @param int   $limited - The limit to compare the value against.
	 *
	 * @return string|null Sanitized value, or null when empty.
	 */
	public static function is_safe( $value, $limited = 0 ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			$value = (string) $value;
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		if ( $limited > 0 && strlen( $value ) > $limited ) {
			$truncated = substr( $value, 0, (int) $limited );

			return false === $truncated ? null : $truncated;
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
	public static function filter_empty_strings( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$filtered = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::filter_empty_strings( $value );
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
	 * @return array<string, mixed> The billing information.
	 */
	public function get_billing() { // phpcs:ignore
		$order   = $this->require_order();
		$billing = array();

		$fields = array(
			'street'        => array(
				'method' => 'get_billing_address_1',
				'length' => 100,
			),
			'street2'       => array(
				'method' => 'get_billing_address_2',
				'length' => 100,
			),
			'city'          => array(
				'method' => 'get_billing_city',
				'length' => 100,
			),
			'postcodeZip'   => array(
				'method' => 'get_billing_postcode',
				'length' => 10,
			),
			'stateProvince' => array(
				'method' => 'get_billing_state',
				'length' => 20,
			),
		);

		foreach ( $fields as $key => $field ) {
			$value = $order->{ $field['method'] }();

			if ( $value ) {
				$billing['address'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		$country = $order->get_billing_country();

		if ( $country ) {
			$billing['address']['country'] = $this->iso2_to_iso3( $country );
		}

		return $billing;
	}

	/**
	 * Determines if an order is virtual.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 *
	 * @return bool
	 */
	public function order_is_virtual( WC_Order $order ) { // phpcs:ignore
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
	 * @return array<string, mixed>|null
	 */
	public function get_shipping() { // phpcs:ignore
		$order = $this->require_order();

		if ( $this->order_is_virtual( $order ) ) {
			return null;
		}

		$shipping = array();

		$address_fields = array(
			'street'        => array(
				'method' => 'get_shipping_address_1',
				'length' => 100,
			),
			'street2'       => array(
				'method' => 'get_shipping_address_2',
				'length' => 100,
			),
			'city'          => array(
				'method' => 'get_shipping_city',
				'length' => 100,
			),
			'postcodeZip'   => array(
				'method' => 'get_shipping_postcode',
				'length' => 10,
			),
			'stateProvince' => array(
				'method' => 'get_shipping_state',
				'length' => 20,
			),
		);

		foreach ( $address_fields as $key => $field ) {
			$value = $order->{ $field['method'] }();

			if ( $value ) {
				$shipping['address'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		$country = $order->get_shipping_country();

		if ( $country ) {
			$shipping['address']['country'] = $this->iso2_to_iso3( $country );
		}

		$contact_fields = array(
			'firstName' => array(
				'method' => 'get_shipping_first_name',
				'length' => 50,
			),
			'lastName'  => array(
				'method' => 'get_shipping_last_name',
				'length' => 50,
			),
		);

		foreach ( $contact_fields as $key => $field ) {
			$value = $order->{ $field['method'] }();

			if ( $value ) {
				$shipping['contact'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		return $shipping;
	}

	/**
	 * Retrieves the customer information.
	 *
	 * @return array<string, mixed>
	 */
	public function get_customer() { // phpcs:ignore
		$order = $this->require_order();

		return self::filter_empty_strings(
			array(
				'email'     => self::is_safe( $order->get_billing_email(), 255 ),
				'firstName' => self::is_safe( $order->get_billing_first_name(), 50 ),
				'lastName'  => self::is_safe( $order->get_billing_last_name(), 50 ),
			)
		);
	}

	/**
	 * Retrieves the hosted checkout order information.
	 *
	 * @return array<string, mixed>
	 */
	public function get_hosted_checkout_order() { // phpcs:ignore
		$order         = $this->require_order();
		$handling_fee  = 0.0;
		$order_summary = array();
		$fees          = $order->get_fees();
		$locale        = $this->gateway->get_option( 'locale' );
		$description   = Countries::get_instance()->get_order_summary_text( $locale );

		if ( ! empty( $fees ) ) {
			foreach ( $fees as $fee ) {
				$handling_fee += (float) $fee->get_total();
			}
		}

		$shipping_fee = $handling_fee + (float) $order->get_shipping_total();

		if ( 'yes' === $this->gateway->send_line_items ) {
			$line_items = $this->buildOrderLineItems( $order->get_items(), true );

			if ( ! empty( $line_items ) ) {
				$order_summary = array(
					'id'          => (string) PaymentController::get_instance()->add_order_prefix( (string) $order->get_id() ),
					'description' => $description,
					'item'        => $line_items,
				);
			} else {
				$order_summary = array(
					'id'          => (string) PaymentController::get_instance()->add_order_prefix( (string) $order->get_id() ),
					'description' => $description,
				);
			}

			$order_summary = $this->appendOrderBreakdownAmounts( $order_summary, $shipping_fee );

			return $this->finalizeHostedOrderPayload( $order_summary );
		}

		$order_summary = array(
			'id'          => (string) PaymentController::get_instance()->add_order_prefix( (string) $order->get_id() ),
			'description' => $description,
		);

		$order_summary = $this->appendOrderBreakdownAmounts( $order_summary, $shipping_fee );

		return $this->finalizeHostedOrderPayload( $order_summary );
	}

	/**
	 * Append tax, shipping/handling, and discount fields for MPGS order breakdown.
	 *
	 * @param array<string, mixed> $order_summary Order summary payload.
	 * @param float                $shipping_fee  Combined shipping and fee total.
	 *
	 * @return array<string, mixed>
	 */
	private function appendOrderBreakdownAmounts( array $order_summary, $shipping_fee ) {
		$order = $this->require_order();

		if ( ! isset( $order_summary['itemAmount'] ) ) {
			$order_summary['itemAmount'] = $this->get_order_item_amount();
		}

		if ( $shipping_fee ) {
			$order_summary['shippingAndHandlingAmount'] = $this->formatted_price( $shipping_fee );
		}

		if ( $order->get_total_tax() ) {
			$order_summary['taxAmount'] = $this->get_order_tax();
		}

		if ( $order->get_total_discount() ) {
			$order_summary['discount']['amount'] = $this->formatted_price( $order->get_total_discount() );
		}

		return $order_summary;
	}

	/**
	 * Normalize, merge order total, and reconcile breakdown amounts for MPGS.
	 *
	 * @param array<string, mixed> $order_summary Order summary payload.
	 *
	 * @return array<string, mixed>
	 */
	private function finalizeHostedOrderPayload( array $order_summary ) {
		$payload = self::normalize_monetary_fields(
			array_merge(
				$order_summary,
				$this->get_order()
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
	 * @param array<int, \WC_Order_Item> $items       WooCommerce order items.
	 * @param bool                              $use_excerpt Whether to truncate product names.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildOrderLineItems( array $items, $use_excerpt = true ) {
		$line_items = array();

		if ( empty( $items ) ) {
			return $line_items;
		}

		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$qty           = max( 1, (int) $item->get_quantity() );
			$line_subtotal = (float) $item->get_subtotal();
			$unit_price    = $line_subtotal / (float) $qty;
			$product       = $item->get_product();

			$line_item = array(
				'name'      => $use_excerpt ? $this->get_excerpt( $item->get_name(), 127 ) : $item->get_name(),
				'quantity'  => $qty,
				'unitPrice' => $this->formatted_price( $unit_price ),
			);

			$sku = ( $product instanceof WC_Product ) ? self::is_safe( $product->get_sku(), 127 ) : null;
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
	 * @param array<int, array<string, mixed>> $line_items Normalized line items.
	 * @return string
	 */
	public static function sumLineItemsAmount( array $line_items ) {
		$decimals    = max( 0, (int) wc_get_price_decimals() );
		$multiplier  = (float) ( 10 ** $decimals );
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

		return self::format_amount_string( (float) $total_minor / $multiplier );
	}

	/**
	 * Retrieves the order information.
	 *
	 * @return array<string, string>
	 */
	public function get_order() { // phpcs:ignore
		$order = $this->require_order();

		return array(
			'amount'   => $this->formatted_price( $order->get_total() ),
			'currency' => $order->get_currency(),
		);
	}

	/**
	 * Get order tax amount formatted for the gateway.
	 *
	 * @return string
	 */
	public function get_order_tax() { // phpcs:ignore
		$order = $this->require_order();
		$tax   = $order->get_total_tax();

		if ( 'yes' !== get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
			$tax = wc_round_tax_total( $tax );
		}

		return $this->formatted_price( $tax );
	}

	/**
	 * Get order item amount formatted for the gateway.
	 *
	 * @return string
	 */
	public function get_order_item_amount() { // phpcs:ignore
		$order = $this->require_order();

		if ( wc_prices_include_tax() ) {
			$item_amount = (float) wc_round_tax_total( $order->get_subtotal() );
		} else {
			$item_amount = (float) $order->get_subtotal();
		}

		return $this->formatted_price( $item_amount );
	}

	/**
	 * Retrieves the surcharge information.
	 *
	 * @return array<string, string>
	 */
	public function get_surcharge() { // phpcs:ignore
		$surcharge_fee = $this->require_order()->get_meta( '_mpgs_surcharge_fee' );

		if ( $surcharge_fee > 0 ) {
			return array(
				'amount' => $this->formatted_price( $surcharge_fee ),
				'type'   => 'SURCHARGE',
			);
		} else {
			return array(
				'amount' => self::format_amount_string( 0 ),
				'type'   => 'SURCHARGE',
			);
		}
	}

	/**
	 * Format a monetary value for MPGS (max 2 decimal places, no float artifacts).
	 *
	 * @param float|string $price Unformatted price.
	 * @return string
	 */
	public function formatted_price( $price ) { // phpcs:ignore
		return self::format_amount_string( $price );
	}

	/**
	 * Format amount as a decimal string suitable for gateway API fields.
	 *
	 * @param float|int|string $amount Amount value.
	 * @return string
	 */
	public static function format_amount_string( $amount ) {
		$decimals = max( 0, (int) wc_get_price_decimals() );

		if ( function_exists( 'wc_string_to_num' ) ) {
			$amount = wc_string_to_num( $amount );
		} elseif ( is_numeric( $amount ) ) {
			$amount = (float) $amount;
		} else {
			$amount = 0.0;
		}

		// Round via integer minor-units to avoid float artifacts (e.g. 43.899999999999999).
		$multiplier = (float) ( 10 ** $decimals );
		$minor      = (int) round( $amount * $multiplier );

		return number_format( (float) $minor / $multiplier, $decimals, '.', '' );
	}

	/**
	 * Prepare a gateway API request payload (normalize amounts, remove empty fields).
	 *
	 * @param array<string, mixed> $request_data Request body array.
	 * @return array<string, mixed>
	 */
	public static function prepare_gateway_request_data( array $request_data ) {
		$request_data = self::normalize_monetary_fields( $request_data );

		if ( isset( $request_data['order'] ) && is_array( $request_data['order'] ) ) {
			$request_data['order'] = self::reconcileOrderAmounts( $request_data['order'] );
		}

		return self::filter_empty_strings( $request_data );
	}

	/**
	 * Normalize monetary fields in an order payload before sending to MPGS.
	 *
	 * @param mixed $data Order or nested order data.
	 * @return mixed
	 */
	public static function normalize_monetary_fields( $data ) {
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
				$data[ $key ]['amount'] = self::format_amount_string( $value['amount'] );
			} elseif ( 'item' === $key && is_array( $value ) ) {
				foreach ( $value as $index => $line_item ) {
					$data[ $key ][ $index ] = self::normalize_monetary_fields( $line_item );
				}
			} elseif ( in_array( $key, $amount_keys, true ) ) {
				$data[ $key ] = self::format_amount_string( $value );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::normalize_monetary_fields( $value );
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
	 * @param array<string, mixed> $order Normalized order payload.
	 * @return array<string, mixed>
	 */
	public static function reconcileOrderAmounts( array $order ) {
		if ( empty( $order['amount'] ) || ! isset( $order['itemAmount'] ) ) {
			return $order;
		}

		$decimals   = max( 0, (int) wc_get_price_decimals() );
		$multiplier = (int) ( 10 ** $decimals );

		$to_minor = static function ( $value ) use ( $multiplier ) {
			if ( function_exists( 'wc_string_to_num' ) ) {
				$value = wc_string_to_num( $value );
			}

			return (int) round( (float) $value * (float) $multiplier );
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
			$order['taxAmount'] = self::format_amount_string( (float) ( $tax_minor + $diff ) / (float) $multiplier );
		} elseif ( $has_line_items && isset( $order['shippingAndHandlingAmount'] ) ) {
			$order['shippingAndHandlingAmount'] = self::format_amount_string( (float) ( $shipping_minor + $diff ) / (float) $multiplier );
		} elseif ( ! $has_line_items ) {
			$order['itemAmount'] = self::format_amount_string( (float) ( $item_minor + $diff ) / (float) $multiplier );
		} else {
			$order = self::absorbAmountDiffOnLastLineItem( $order, $diff, $multiplier );
			$order['itemAmount'] = self::sumLineItemsAmount( $order['item'] );
		}

		return $order;
	}

	/**
	 * Shift a minor-unit order total difference onto the last line item unit price.
	 *
	 * @param array<string, mixed> $order      Order payload.
	 * @param int                  $diff_minor Difference in minor currency units.
	 * @param int                  $multiplier Minor units per major unit.
	 *
	 * @return array<string, mixed>
	 */
	private static function absorbAmountDiffOnLastLineItem( array $order, $diff_minor, $multiplier ) {
		$items       = $order['item'];
		$last_index  = count( $items ) - 1;
		$last        = $items[ $last_index ];
		$qty         = max( 1, (int) ( $last['quantity'] ?? 1 ) );
		$unit        = function_exists( 'wc_string_to_num' ) ? wc_string_to_num( $last['unitPrice'] ?? 0 ) : (float) ( $last['unitPrice'] ?? 0 );
		$unit_minor  = (int) round( (float) $unit * (float) $multiplier );
		$unit_minor += (int) round( $diff_minor / $qty );
		$items[ $last_index ]['unitPrice'] = self::format_amount_string( (float) $unit_minor / (float) $multiplier );
		$order['item']                     = $items;

		return $order;
	}

	/**
	 * Retrieves the interaction data.
	 *
	 * @param bool        $capture Capture status.
	 * @param string|null $return_url Return URL.
	 *
	 * @return array<string, mixed>
	 */
	public function get_interaction( $capture = true, $return_url = null ) { // phpcs:ignore
		$merchant_interaction = array();
		$locale               = $this->gateway->get_option( 'locale' );
		$locale               = ! empty( $locale ) ? $locale : 'en_US';

		if ( 'yes' === $this->gateway->mif_enabled ) {
			$merchant_name = $this->gateway->get_option( 'merchant_name' );
			$sitename      = get_bloginfo( 'name', 'display' );
			$merchant_name = $merchant_name ? preg_replace( "/['\"]/", '', $merchant_name ) : $sitename;
			$merchant_name = is_string( $merchant_name ) ? $merchant_name : (string) $sitename;
			$merchant_name = $this->get_excerpt( $merchant_name, 39 );

			$merchant_address = self::filter_empty_strings(
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

			if ( $this->gateway->get_option( 'merchant_email' ) ) {
				$merchant['email'] = $this->gateway->get_option( 'merchant_email' );
			}

			if ( $this->gateway->get_option( 'merchant_logo' ) ) {
				$merchant['logo'] = self::force_https_url( $this->gateway->get_option( 'merchant_logo' ) );
			}

			if ( $this->gateway->get_option( 'merchant_phone' ) ) {
				$merchant['phone'] = $this->get_excerpt( $this->gateway->get_option( 'merchant_phone' ), 20 );
			}

			$merchant_interaction['merchant'] = $merchant;
		} else {
			$sitename                                 = $this->get_excerpt( get_bloginfo( 'name', 'display' ), 39 );
			$merchant_interaction['merchant']['name'] = $sitename;
			$merchant_interaction['merchant']['url']  = $this->get_merchant_site_url();
		}

		return array_merge(
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
	}

	/**
	 * Retrieves the interaction data.
	 *
	 * @param bool        $capture Capture status.
	 * @param string|null $return_url Return URL.
	 *
	 * @return array<string, mixed>
	 * @deprecated
	 */
	public function get_legacy_interaction( $capture = true, $return_url = null ) { // phpcs:ignore
		$merchant_name = $this->gateway->get_option( 'merchant_name' );
		$sitename      = get_bloginfo( 'name', 'display' );
		$merchant_name = $merchant_name ? $merchant_name : $sitename;
		$merchant_name = $this->get_excerpt( $merchant_name, 39 );

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
	 * @param int    $length The length of the excerpt (number of words).
	 * @return string The excerpt.
	 */
	public function get_excerpt( $text, $length = 50 ) {
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
	 * @param mixed $url URL to normalize.
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
		$field  = (string) $field;
		$encode = mb_detect_encoding( $field );
		if ( ! is_string( $encode ) ) {
			$encode = 'UTF-8';
		}

		if ( 'ASCII' !== $encode ) {
			if ( function_exists( 'transliterator_transliterate' ) ) {
				$transliterated = transliterator_transliterate( 'Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $field );
				$field          = is_string( $transliterated ) ? $transliterated : $field;
			} else {
				// fall back to iconv if intl module not available.
				$field     = remove_accents( $field );
				$converted = iconv( $encode, 'ASCII//TRANSLIT//IGNORE', $field );
				$field     = is_string( $converted ) ? $converted : $field;
				$field     = str_ireplace( '?', '', $field );
				$field     = trim( $field );
			}
		}

		return $field;
	}

	/**
	 * Retrieves the payment link order information.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<string, mixed>
	 */
	public function get_payment_link_order( $order_id ) { // phpcs:ignore
		$order = self::resolve_order( (int) $order_id );
		if ( ! $order ) {
			return array();
		}

		$previous_order = $this->order;
		$this->order    = $order;

		$handling_fee  = 0.0;
		$order_summary = array();
		$fees          = $order->get_fees();

		if ( ! empty( $fees ) ) {
			foreach ( $fees as $fee ) {
				$handling_fee += (float) $fee->get_total();
			}
		}

		$shipping_fee = $handling_fee + (float) $order->get_shipping_total();

		if ( 'yes' === $this->gateway->send_line_items ) {
			$line_items = $this->buildOrderLineItems( $order->get_items(), false );

			$order_summary = array(
				'id'          => (string) PaymentController::get_instance()->add_order_prefix( (string) $order->get_id() ),
				'description' => 'Payment Link Order',
				'item'        => $line_items,
			);

		} else {
			$order_summary = array(
				'id'          => (string) PaymentController::get_instance()->add_order_prefix( (string) $order->get_id() ),
				'description' => 'Payment Link Order',
			);
		}

		$order_summary = $this->appendOrderBreakdownAmounts( $order_summary, $shipping_fee );
		$order_summary['amount']   = $this->formatted_price( $order->get_total() );
		$order_summary['currency'] = $order->get_currency();

		$payload = self::normalize_monetary_fields( $order_summary );

		if ( ! empty( $payload['item'] ) && is_array( $payload['item'] ) ) {
			$payload['itemAmount'] = self::sumLineItemsAmount( $payload['item'] );
		}

		$result = self::reconcileOrderAmounts( $payload );

		$this->order = $previous_order;

		return $result;
	}

	/**
	 * Payment link expiry and attempt limits for MPGS.
	 *
	 * @return array<string, int|string>
	 */
	public function get_payment_link_settings() {

		$value    = $this->gateway->get_option( 'payment_expiry_value' );
		$unit     = $this->gateway->get_option( 'payment_expiry_unit' );
		$attempts = $this->gateway->get_option( 'payment_allowed_attempts' );

		// Default expiry: 3 months.
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
					$days             = intval( $value ) * 30;
					$expiry_timestamp = strtotime( '+' . $days . ' days' );
					break;

				default:
					$expiry_timestamp = strtotime( '+3 months' );
			}
		}

		// Format with milliseconds.
		if ( false === $expiry_timestamp ) {
			$expiry_timestamp = strtotime( '+3 months' );
		}
		$expiry = gmdate( 'Y-m-d\TH:i:s', (int) $expiry_timestamp ) . '.000Z';

		if ( empty( $attempts ) ) {
			$attempts = 25;
		}

		return array(
			'expiryDateTime'          => $expiry,
			'numberOfAllowedAttempts' => absint( $attempts ),
		);
	}

	/**
	 * Build billing address payload for a specific order ID.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<string, mixed>
	 */
	public function get_billing_from_order( $order_id ) {
		$order   = self::resolve_order( (int) $order_id );
		$billing = array();

		if ( ! $order ) {
			return $billing;
		}

		// Safest: get billing address array directly.
		$billing_data = $order->get_address( 'billing' );
		$fields       = array(
			'street'        => array(
				'key'    => 'address_1',
				'length' => 100,
			),
			'street2'       => array(
				'key'    => 'address_2',
				'length' => 100,
			),
			'city'          => array(
				'key'    => 'city',
				'length' => 100,
			),
			'postcodeZip'   => array(
				'key'    => 'postcode',
				'length' => 10,
			),
			'stateProvince' => array(
				'key'    => 'state',
				'length' => 20,
			),
		);

		foreach ( $fields as $key => $field ) {
			$value = $billing_data[ $field['key'] ] ?? '';
			if ( $value ) {
				$billing['address'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		$country = $order->get_billing_country();

		if ( $country ) {
			$billing['address']['country'] = $this->iso2_to_iso3( $country );
		}

		return $billing;
	}


	/**
	 * Build shipping address payload for a specific order ID.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<string, mixed>|null
	 */
	public function get_shipping_from_order( $order_id ) {
		$order    = self::resolve_order( (int) $order_id );
		$shipping = array();

		if ( ! $order ) {
			return $shipping;
		}

		// Skip if order is virtual (same as your current check).
		if ( (float) $order->get_shipping_total() === 0.0 && ! $order->get_shipping_first_name() && ! $order->get_shipping_address_1() ) {
			return null;
		}

		$address_fields = array(
			'street'        => array(
				'method' => 'get_shipping_address_1',
				'length' => 100,
			),
			'street2'       => array(
				'method' => 'get_shipping_address_2',
				'length' => 100,
			),
			'city'          => array(
				'method' => 'get_shipping_city',
				'length' => 100,
			),
			'postcodeZip'   => array(
				'method' => 'get_shipping_postcode',
				'length' => 10,
			),
			'stateProvince' => array(
				'method' => 'get_shipping_state',
				'length' => 20,
			),
		);

		foreach ( $address_fields as $key => $field ) {
			$value = $order->{ $field['method'] }();
			if ( $value ) {
				$shipping['address'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		$country = $order->get_shipping_country();
		if ( $country ) {

			$shipping['address']['country'] = $this->iso2_to_iso3( $country );
		}

		$contact_fields = array(
			'firstName' => array(
				'method' => 'get_shipping_first_name',
				'length' => 50,
			),
			'lastName'  => array(
				'method' => 'get_shipping_last_name',
				'length' => 50,
			),
		);

		foreach ( $contact_fields as $key => $field ) {
			$value = $order->{ $field['method'] }();
			if ( $value ) {
				$shipping['contact'][ $key ] = self::is_safe( $value, $field['length'] );
			}
		}

		return $shipping;
	}
}
