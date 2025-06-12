<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * WooCommerce template for hosted session page
 *
 * @var Mastercard_Gateway $gateway
 * @var WC_Abstract_Order $order
 * @var WC_Payment_Gateway_CC $cc_form
 */
?>
<style id="antiClickjack">body{ display:none !important; }</style>
<?php echo $gateway->display_surcharge_message( $order ); ?>
<div id="3DSUI"></div>
<form class="mpgs_hostedsession wc-payment-form" action="<?php echo esc_url( $gateway->get_payment_return_url( $order->get_id() ) ); ?>" method="post">

	<div class="payment_box">
		<?php $cc_form->payment_fields(); ?>
	</div>
	<input type="hidden" name="session_id" value="" />
	<input type="hidden" name="session_version" value="" />
	<input type="hidden" name="check_3ds_enrollment" value="" />
	<input type="hidden" name="funding_method" id="funding_method" value="" />
	<div id="hostedsession_errors" style="color: red; display: none;" class="errors"></div>
	<p class="form-row form-row-wide">
		<button type="button" id="mpgs_pay" class="wp-element-button" onclick="mpgsPayWithSelectedInstrument()"><?php esc_html_e( 'Pay', 'mastercard' ); ?></button>
	</p>
	<div class="surcharge_wrapper">
		<?php echo $gateway->display_surcharge_confirmation_box( $order ); ?>
	</div>
</form>
<?php
$params = array( 
	'merchantId'    => esc_attr( $gateway->get_merchant_id() ),
	'apiVersion'    => esc_attr( $gateway->get_api_version_num() ),
	'is3dsV1'       => $gateway->use_3dsecure_v1(),
	'is3dsV2'       => $gateway->use_3dsecure_v2(),
	'orderPrefixId' => esc_attr( $gateway->add_order_prefix( $order->get_id() ) ),
	'sessionNonce'  => esc_attr( wp_create_nonce( 'wp_rest' ) ),
	'paymentUrl'    => esc_url( $gateway->get_save_payment_url( $order->get_id() ) ),
	'orderId'       => esc_attr( $order->get_id() ),
	'sessionUrl'    => esc_url( $gateway->get_create_session_url( $order->get_id() ) )
);
wp_enqueue_style( 'mpgs-hosted-session', plugins_url( 'assets/css/hosted-session.css', MPGS_INCLUDE_FILE ), array(), MPGS_TARGET_MODULE_VERSION );
wp_enqueue_script( 'mpgs-hosted-session', plugins_url( 'assets/js/hosted-session.js', MPGS_INCLUDE_FILE ), array(), MPGS_TARGET_MODULE_VERSION, true );
wp_localize_script( 'mpgs-hosted-session', 'mpgsSessionParams', $params );
?>