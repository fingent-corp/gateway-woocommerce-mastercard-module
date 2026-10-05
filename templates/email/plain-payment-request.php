<?php
/**
 * Payment Request Email - Plain Text
 *
 * This template is used to send a "Pay by Link" request to the customer in plain text.
 *
 * @package Fingent\Mastercard
 *
 * @var \WC_Order $order
 * @var \Fingent\Mastercard\Emails\PaymentRequestEmail $email
 * @var string $payment_link
 * @var string $expiry_date_time
 * @var int $allowed_attempts
 */

do_action( 'woocommerce_email_header', $email_heading, $email );

echo esc_html(
	sprintf(
		/* translators: %s: customer first name */
		__( 'Hi %s,', 'mastercard-gateway' ),
		$order->get_billing_first_name()
	)
) . "\n\n";
echo esc_html( __( "We hope you're doing well!", 'mastercard-gateway' ) ) . "\n\n";
echo esc_html( __( 'You have an outstanding order with us. To complete your payment securely, please use the payment link provided below.', 'mastercard-gateway' ) ) . "\n\n";

echo "====================\n";
echo esc_html( __( 'ORDER SUMMARY', 'mastercard-gateway' ) ) . "\n";
echo "====================\n";

// Output order details in plain text.
foreach ( $order->get_items() as $item_id => $item ) {
	$product_name = $item->get_name();
	$quantity     = $item->get_quantity();
	$total        = $item->get_total();

	printf(
		"%s x %d - %s\n",
		esc_html( wp_strip_all_tags( $product_name ) ),
		(int) $quantity,
		esc_html( wp_strip_all_tags( wc_price( $total ) ) )
	);
}

echo esc_html( '--------------------' ) . "\n";
echo esc_html( __( 'Order Total:', 'mastercard-gateway' ) ) . ' ' . esc_html( wp_strip_all_tags( wc_price( $order->get_total() ) ) ) . "\n\n";

// Optional: Display customer details.
echo esc_html( __( 'Billing Email:', 'mastercard-gateway' ) ) . ' ' . esc_html( sanitize_email( $order->get_billing_email() ) ) . "\n";
echo esc_html( __( 'Billing Address:', 'mastercard-gateway' ) ) . ' ' . esc_html( wp_strip_all_tags( $order->get_formatted_billing_address() ) ) . "\n\n";

echo "====================\n";
echo esc_html( __( 'PAYMENT LINK DETAILS', 'mastercard-gateway' ) ) . "\n";
echo "====================\n";

if ( ! empty( $expiry_date_time ) ) {
	echo esc_html( __( 'Expiry Date:', 'mastercard-gateway' ) ) . ' ' . esc_html( $expiry_date_time ) . "\n";
}

if ( ! empty( $allowed_attempts ) ) {
	echo esc_html( __( 'Allowed Attempts:', 'mastercard-gateway' ) ) . ' ' . esc_html( $allowed_attempts ) . "\n";
}

echo "\n" . esc_html( __( 'Complete your payment using the following link:', 'mastercard-gateway' ) ) . "\n";
echo esc_url( $payment_link ) . "\n\n";

echo esc_html( __( 'Thank you for your business and prompt payment.', 'mastercard-gateway' ) ) . "\n";
echo esc_html( __( 'Best regards,', 'mastercard-gateway' ) ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . " Team\n";

do_action( 'woocommerce_email_footer', $email );
