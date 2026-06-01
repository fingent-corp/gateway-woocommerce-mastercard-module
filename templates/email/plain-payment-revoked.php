<?php
/**
 * WooCommerce Plain Email Template
 * Payment Link Revoked
 */

echo strtoupper( $email_heading ) . "\n\n";

echo esc_html( sprintf( __( 'Dear %s,', MG_ENTERPRISE_TEXTDOMAIN ), $order->get_billing_first_name() ) ) . "\n\n";

echo esc_html( __( 'Your previously issued payment link has been revoked and is no longer valid.', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n\n";

echo esc_html( __( 'Order Details:', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n";

// List order items
foreach ( $order->get_items() as $item_id => $item ) {
    $product_name = $item->get_name();
    $quantity     = $item->get_quantity();
    $total        = $order->get_formatted_line_subtotal( $item );
    echo sprintf(
        /* translators: 1: product name, 2: quantity, 3: line total */
        esc_html__( '- %1$s x %2$d = %3$s', MG_ENTERPRISE_TEXTDOMAIN ),
        wp_strip_all_tags( $product_name ),
        (int) $quantity,
        wp_strip_all_tags( $total )
    ) . "\n";
}

// Order totals
echo "\n" . esc_html( __( 'Order Total:', MG_ENTERPRISE_TEXTDOMAIN ) ) . ' ' . wp_strip_all_tags( $order->get_formatted_order_total() ) . "\n\n";

// Customer billing details
echo esc_html( __( 'Billing Details:', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n";
echo esc_html( $order->get_formatted_billing_full_name() ) . "\n";
echo esc_html( $order->get_billing_address_1() ) . "\n";
if ( $order->get_billing_address_2() ) {
    echo esc_html( $order->get_billing_address_2() ) . "\n";
}
echo esc_html( $order->get_billing_city() ) . ', ' . esc_html( $order->get_billing_state() ) . ' ' . esc_html( $order->get_billing_postcode() ) . "\n";
echo esc_html( $order->get_billing_country() ) . "\n";
echo esc_html( __( 'Email:', MG_ENTERPRISE_TEXTDOMAIN ) ) . ' ' . sanitize_email( $order->get_billing_email() ) . "\n";
echo esc_html( __( 'Phone:', MG_ENTERPRISE_TEXTDOMAIN ) ) . ' ' . esc_html( $order->get_billing_phone() ) . "\n\n";

echo esc_html( __( 'Thank you,', MG_ENTERPRISE_TEXTDOMAIN ) ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";
