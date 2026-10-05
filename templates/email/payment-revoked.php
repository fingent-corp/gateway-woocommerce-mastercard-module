<?php
/**
 * Payment Link Revoked Email Template
 *
 * @package Fingent\Mastercard
 */

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
echo esc_html(
	sprintf(
		/* translators: %s: customer first name */
		__( 'Dear %s,', 'mastercard-gateway' ),
		$order->get_billing_first_name()
	)
);
?>
</p>

<p><?php echo esc_html( __( 'Your previously issued payment link has been revoked and is no longer valid.', 'mastercard-gateway' ) ); ?></p>

<p><?php echo esc_html( __( 'Here are the details of your order:', 'mastercard-gateway' ) ); ?></p>

<?php
// Display order details.
do_action( 'woocommerce_email_order_details', $order, false, false, $email );

// Display customer details.
do_action( 'woocommerce_email_customer_details', $order, $email );
?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
<p><?php echo esc_html( __( 'Thank you,', 'mastercard-gateway' ) ); ?><br><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
