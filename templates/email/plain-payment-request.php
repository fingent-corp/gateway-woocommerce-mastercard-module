<?php
/**
 * Payment Request Email - Plain Text
 *
 * This template is used to send a "Pay by Link" request to the customer in plain text.
 *
 * @var \WC_Order $order
 * @var \Fingent\Mastercard\Emails\PaymentRequestEmail $email
 * @var string $payment_link
 * @var string $expiry_date_time
 * @var int $allowed_attempts
 */

do_action( 'woocommerce_email_header', $email_heading, $email );

echo esc_html( sprintf( __( 'Hi %s,', MG_ENTERPRISE_TEXTDOMAIN ), $order->get_billing_first_name() ) ) . "\n\n";
echo esc_html( __( "We hope you're doing well!", MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n\n";
echo esc_html( __( 'You have an outstanding order with us. To complete your payment securely, please use the payment link provided below.', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n\n";

echo "====================\n";
echo esc_html( __( 'ORDER SUMMARY', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n";
echo "====================\n";

// Output order details in plain text
foreach ( $order->get_items() as $item_id => $item ) {
    $product_name = $item->get_name();
    $quantity     = $item->get_quantity();
    $total        = $item->get_total();

    echo sprintf( "%s x %d - %s\n", $product_name, $quantity, wc_price( $total ) );
}

echo "--------------------\n";
echo esc_html( __( 'Order Total:', MG_ENTERPRISE_TEXTDOMAIN ) ) . ' ' . wc_price( $order->get_total() ) . "\n\n";

// Optional: Display customer details
echo esc_html( __( 'Billing Email:', MG_ENTERPRISE_TEXTDOMAIN ) ) . ' ' . sanitize_email( $order->get_billing_email() ) . "\n";
echo esc_html( __( 'Billing Address:', MG_ENTERPRISE_TEXTDOMAIN ) ) . ' ' . wp_strip_all_tags( $order->get_formatted_billing_address() ) . "\n\n";

echo "====================\n";
echo esc_html( __( 'PAYMENT LINK DETAILS', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n";
echo "====================\n";

if ( ! empty( $expiry_date_time ) ) {
    echo esc_html( __( 'Expiry Date:', MG_ENTERPRISE_TEXTDOMAIN ) ) . ' ' . esc_html( $expiry_date_time ) . "\n";
}

if ( ! empty( $allowed_attempts ) ) {
    echo esc_html( __( 'Allowed Attempts:', MG_ENTERPRISE_TEXTDOMAIN ) ) . ' ' . esc_html( $allowed_attempts ) . "\n";
}

echo "\n" . esc_html( __( 'Complete your payment using the following link:', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n";
echo esc_url( $payment_link ) . "\n\n";

echo esc_html( __( 'Thank you for your business and prompt payment.', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n";
echo esc_html( __( 'Best regards,', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . " Team\n";

do_action( 'woocommerce_email_footer', $email );
