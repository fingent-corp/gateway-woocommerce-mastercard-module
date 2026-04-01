<?php
/**
 * WooCommerce template for Hosted Session.
 *
 * @var MastercardGateway $gateway Gateway array values
 * @var WC_Abstract_Order $order Order array
 */
?>
<?php $rtl_class = ( $gateway->get_option( 'locale' ) === 'ar' ) ? ' rtl-text' : '';?>
<div class="lang-wrapper<?php echo $rtl_class; ?>">
	<style id="antiClickjack">body{ display:none !important; }</style>
	<?php echo wp_kses_post( $frontend->display_surcharge_message( $order ) ); ?>
	<div id="3DSUI"></div>
	<form class="mg_hostedsession wc-payment-form" action="<?php echo esc_url( $payment->get_payment_return_url( $order->get_id() ) ); ?>" method="post">
		<div class="payment_box">
			<?php $cc_form->payment_fields(); ?>
			<input type="hidden" name="session_id" value="" />
			<input type="hidden" name="session_version" value="" />
			<input type="hidden" name="check_3ds_enrollment" value="" />
			<input type="hidden" name="funding_method" id="funding_method" value="" />
			<div id="hostedsession_errors" style="display: none;" class="errors"></div>
			<p class="form-row form-row-wide">
				<button type="button" id="mg_pay" class="wp-element-button" onclick="mgPayWithSelectedInstrument()"><?php esc_html_e( 'Pay', MG_ENTERPRISE_TEXTDOMAIN ); ?></button>
			</p>
		</div>
		<div class="surcharge_wrapper">
			<?php echo $frontend->display_surcharge_confirmation_box( $order ); ?>
		</div>
	</form>
</div>
<?php
$params = array( 
	'merchantId'    => esc_attr( $gateway->get_merchant_id() ),
	'apiVersion'    => esc_attr( $gateway->get_api_version_num() ),
	'is3dsV1'       => $gateway->use_3dsecure_v1(),
	'is3dsV2'       => $gateway->use_3dsecure_v2(),
	'orderPrefixId' => esc_attr( $payment->add_order_prefix( $order->get_id() ) ),
	'sessionNonce'  => esc_attr( wp_create_nonce( 'wp_rest' ) ),
	'orderId'       => esc_attr( $order->get_id() ),
	'paymentUrl'    => esc_url( $utility->get_save_payment_url( $order->get_id() ) ),
	'sessionUrl'    => esc_url( $utility->get_create_session_url( $order->get_id() ) ),
	'authorization' => 'Basic ' . base64_encode( 'merchant.' . $gateway->username . ':' . $gateway->password ),

	'errorMessages' => array(
		'cardNumber'   => esc_html__( 'Please enter a valid card number.', MG_ENTERPRISE_TEXTDOMAIN ),
		'securityCode' => esc_html__( 'The security code entered is invalid.', MG_ENTERPRISE_TEXTDOMAIN ),
		'expiryMonth'  => esc_html__( 'Please enter a valid expiry month.', MG_ENTERPRISE_TEXTDOMAIN ),
		'expiryYear'   => esc_html__( 'Please enter a valid expiry year.', MG_ENTERPRISE_TEXTDOMAIN ),
	)
);

$custom_css = $frontend->get_hosted_checkout_styles();
$frontend->load_google_font_families();

wp_enqueue_style( 'mg-hosted-session', $utility::plugin_url() . '/assets/css/hosted-session.css', array(), MG_ENTERPRISE_MODULE_VERSION );
wp_add_inline_style( 'mg-hosted-session', $custom_css );

wp_enqueue_script( 'mg-hosted-session', $utility::plugin_url() . '/assets/js/hosted-session.js', array(), MG_ENTERPRISE_MODULE_VERSION, true );
wp_localize_script( 'mg-hosted-session', 'mgSessionParams', $params );
?>