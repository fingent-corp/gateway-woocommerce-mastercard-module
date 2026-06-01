<?php
/**
 * Payment Link Revoked Email Template
 */

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo esc_html( sprintf( __( 'Dear %s,', MG_ENTERPRISE_TEXTDOMAIN ), $order->get_billing_first_name() ) ); ?></p>

<p><?php echo esc_html( __( 'Your previously issued payment link has been revoked and is no longer valid.', MG_ENTERPRISE_TEXTDOMAIN ) ); ?></p>

<p><?php echo esc_html( __( 'Here are the details of your order:', MG_ENTERPRISE_TEXTDOMAIN ) ); ?></p>

<?php
// Display order details
do_action( 'woocommerce_email_order_details', $order, false, false, $email );

// Display customer details
do_action( 'woocommerce_email_customer_details', $order, $email );
?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
<p><?php echo esc_html( __( 'Thank you,', MG_ENTERPRISE_TEXTDOMAIN ) ); ?><br><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>