<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Order-scoped REST authentication helpers.
 *
 * @package Fingent\Mastercard\Helper
 */

namespace Fingent\Mastercard\Helper;

use WC_Order;
use Fingent\Mastercard\Exception\PluginException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order-scoped REST authentication (no merchant credentials in the browser).
 */
class RestAuthHelper {
	/**
	 * Default token lifetime in seconds.
	 */
	const DEFAULT_TOKEN_TTL = 7200;

	/**
	 * Create a signed token for REST access to a single order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string
	 */
	public static function create_order_rest_token( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$order_id = (int) $order->get_id();
		$expiry   = time() + (int) apply_filters( 'mastercard_order_rest_token_ttl', self::DEFAULT_TOKEN_TTL, $order );
		$payload  = $order_id . '.' . $expiry;
		$sig      = hash_hmac( 'sha256', $payload, self::signing_key( $order ) );
		if ( ! is_string( $sig ) ) {
			return '';
		}

		return base64_encode( $payload . '.' . $sig ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Verify an order-scoped REST token.
	 *
	 * @param int    $order_id Expected order ID.
	 * @param string $token    Token from X-MG-Order-Token header.
	 * @return bool
	 */
	public static function verify_order_rest_token( $order_id, $token ) {
		$order_id = absint( $order_id );
		$token    = is_string( $token ) ? trim( $token ) : '';

		if ( ! $order_id || '' === $token ) {
			return false;
		}

		$decoded = base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $decoded ) {
			return false;
		}

		$parts = explode( '.', $decoded );

		if ( 3 !== count( $parts ) ) {
			return false;
		}

		list( $token_order_id, $expiry, $sig ) = $parts;

		if ( (int) $token_order_id !== $order_id ) {
			return false;
		}

		if ( time() > (int) $expiry ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $token_order_id . '.' . $expiry, self::signing_key( $order ) );
		if ( ! is_string( $expected ) ) {
			return false;
		}

		return hash_equals( $expected, $sig );
	}

	/**
	 * Extract order token from a REST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	public static function get_token_from_request( $request ) {
		$token = $request->get_header( 'x_mg_order_token' );

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Resolve the gateway ID stored on an order (getter with legacy meta fallback).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function resolve_payment_method( WC_Order $order ) {
		$payment_method = (string) $order->get_payment_method();
		if ( '' === $payment_method ) {
			$payment_method = (string) $order->get_meta( '_payment_method' );
		}

		return $payment_method;
	}

	/**
	 * Load and validate an order for REST payment routes.
	 *
	 * @param int $order_id Order ID.
	 * @return \WC_Order
	 * @throws PluginException When the order is invalid or not payable.
	 */
	public static function get_payable_order( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof WC_Order ) {
			throw new PluginException( 'Invalid order.' );
		}

		if ( defined( 'MG_ENTERPRISE_ID' ) && MG_ENTERPRISE_ID !== self::resolve_payment_method( $order ) ) {
			throw new PluginException( 'Invalid payment method for order.' );
		}

		$allowed_statuses = apply_filters(
			'mastercard_rest_allowed_order_statuses',
			array( 'pending', 'failed', 'on-hold' )
		);

		if ( ! $order->needs_payment() && ! $order->has_status( $allowed_statuses ) ) {
			throw new PluginException( 'Order is not available for payment.' );
		}

		return $order;
	}

	/**
	 * HMAC signing key bound to the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private static function signing_key( WC_Order $order ) {
		$payload = $order->get_id() . '|' . $order->get_order_key();

		$key = hash_hmac( 'md5', $payload, wp_salt( 'mastercard_order_rest' ) );

		return is_string( $key ) ? $key : '';
	}
}
