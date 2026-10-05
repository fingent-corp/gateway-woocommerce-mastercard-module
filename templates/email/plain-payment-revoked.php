<?php
/**
 * WooCommerce Plain Email Template
 * Payment Link Revoked
 *
 * @package Fingent\Mastercard
 */

echo esc_html( strtoupper( $email_heading ) ) . "\n\n";

echo esc_html(
	sprintf(
		/* translators: %s: customer first name */
		__( 'Dear %s,', 'mastercard-gateway' ),
		$order->get_billing_first_name()
	)
) . "\n\n";

echo esc_html( __( 'Your previously issued payment link has been revoked and is no longer valid.', 'mastercard-gateway' ) ) . "\n\n";

echo esc_html( __( 'Order Details:', 'mastercard-gateway' ) ) . "\n";

// List order items.
foreach ( $order->get_items() as $item_id => $item ) {
	$product_name = $item->get_name();
	$quantity     = $item->get_quantity();
	$total        = $order->get_formatted_line_subtotal( $item );
	echo esc_html(
		sprintf(
			/* translators: 1: product name, 2: quantity, 3: line total */
			__( '- %1$s x %2$d = %3$s', 'mastercard-gateway' ),
			wp_strip_all_tags( $product_name ),
			(int) $quantity,
			wp_strip_all_tags( $total )
		)
	) . "\n";
}

// Order totals.
echo "\n" . esc_html( __( 'Order Total:', 'mastercard-gateway' ) ) . ' ' . esc_html( wp_strip_all_tags( $order->get_formatted_order_total() ) ) . "\n\n";

// Customer billing details.
echo esc_html( __( 'Billing Details:', 'mastercard-gateway' ) ) . "\n";
echo esc_html( $order->get_formatted_billing_full_name() ) . "\n";
echo esc_html( $order->get_billing_address_1() ) . "\n";
if ( $order->get_billing_address_2() ) {
	echo esc_html( $order->get_billing_address_2() ) . "\n";
}
echo esc_html( $order->get_billing_city() ) . ', ' . esc_html( $order->get_billing_state() ) . ' ' . esc_html( $order->get_billing_postcode() ) . "\n";
echo esc_html( $order->get_billing_country() ) . "\n";
echo esc_html( __( 'Email:', 'mastercard-gateway' ) ) . ' ' . esc_html( sanitize_email( $order->get_billing_email() ) ) . "\n";
echo esc_html( __( 'Phone:', 'mastercard-gateway' ) ) . ' ' . esc_html( $order->get_billing_phone() ) . "\n\n";

echo esc_html( __( 'Thank you,', 'mastercard-gateway' ) ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";
