<?php
/**
 * Payment Request Email Template
 *
 * This template is used to send a "Pay by Link" request to the customer.
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
?>

<p>
<?php
echo esc_html(
	sprintf(
		/* translators: %s: customer first name */
		__( 'Hi %s,', 'mastercard-gateway' ),
		$order->get_billing_first_name()
	)
);
?>
</p>

<p><?php echo esc_html( __( "We hope you're doing well!", 'mastercard-gateway' ) ); ?></p>

<p><?php echo esc_html( __( 'You have an outstanding order with us. To complete your payment securely, please use the payment link provided below.', 'mastercard-gateway' ) ); ?></p>

<?php
// Display WooCommerce standard order details table.
do_action( 'woocommerce_email_order_details', $order, false, false, $email );

// Optional: Display customer details.
do_action( 'woocommerce_email_customer_details', $order, $email );
?>

<h2><?php echo esc_html( __( 'Payment Link Details', 'mastercard-gateway' ) ); ?></h2>

<?php if ( ! empty( $expiry_date_time ) ) : ?>
	<p><strong><?php echo esc_html( __( 'Expiry Date:', 'mastercard-gateway' ) ); ?></strong> <?php echo esc_html( $expiry_date_time ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $allowed_attempts ) ) : ?>
	<p><strong><?php echo esc_html( __( 'Allowed Attempts:', 'mastercard-gateway' ) ); ?></strong> <?php echo esc_html( $allowed_attempts ); ?></p>
<?php endif; ?>

<p><?php echo esc_html( __( 'Click the button below to complete your payment securely:', 'mastercard-gateway' ) ); ?></p>


<a href="<?php echo esc_url( $payment_link ); ?>"><?php echo esc_html( __( 'Pay Now', 'mastercard-gateway' ) ); ?></a>


<p><?php echo esc_html( __( "If the button doesn't work, you can copy and paste the following link into your browser:", 'mastercard-gateway' ) ); ?></p>
<p><a href="<?php echo esc_url( $payment_link ); ?>"><?php echo esc_url( $payment_link ); ?></a></p>

<p>
<?php
echo esc_html(
	sprintf(
		/* translators: %s: store contact email address */
		__( 'If you have questions about your order, feel free to contact us at %s', 'mastercard-gateway' ),
		get_option( 'woocommerce_email_from_address' )
	)
);
?>
</p>


<p><?php echo esc_html( __( 'Thank you for your business and prompt payment.', 'mastercard-gateway' ) ); ?></p>

<p><?php echo esc_html( __( 'Best regards,', 'mastercard-gateway' ) ); ?><br><?php echo esc_html( get_bloginfo( 'name' ) ); ?> <?php echo esc_html( __( 'Team', 'mastercard-gateway' ) ); ?></p>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
