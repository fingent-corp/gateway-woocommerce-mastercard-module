<?php
/**
 * WooCommerce template for Hosted Checkout.
 *
 * @var MastercardGateway $gateway Gateway array values
 * @var WC_Abstract_Order $order Order array
 */

 if ( $gateway->use_embedded() ) { ?>
	<div id="embed-target"></div>
<?php } else { ?>
	<input type="button" id="mpgs_pay" style="display: none;" value="<?php esc_html_e( 'Pay', MG_ENTERPRISE_TEXTDOMAIN ); ?>" onclick="Checkout.showPaymentPage();" />
<?php }

$params = array( 
	'orderCancelUrl'     => esc_url( $order->get_cancel_order_url() ),
	'isEmbedded'         => $gateway->use_embedded(),
	'checkoutUrl'        => esc_url( wc_get_checkout_url() ),
	'checkoutSessionUrl' => esc_url( $utility->get_create_checkout_session_url( $order->get_id() ) )
);
wp_enqueue_script( 'mg-hosted-checkout', $utility::plugin_url() . '/assets/js/hosted-checkout.js', array(), MG_ENTERPRISE_MODULE_VERSION, true );
wp_localize_script( 'mg-hosted-checkout', 'mgHCParams', $params );
?>