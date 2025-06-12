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
 * WooCommerce template for new hosted checkout page.
 *
 * @var Mastercard_Gateway $gateway Gateway array values
 * @var WC_Abstract_Order $order Order array
 */

if ( $gateway->use_embedded() ) { ?>
	<div id="embed-target"></div>
<?php } else { ?>
	<input type="button" id="mpgs_pay" style="display: none;" value="<?php esc_html_e( 'Pay', 'mastercard' ); ?>" onclick="Checkout.showPaymentPage();" />
<?php } 

$params = array( 
	'orderCancelUrl'     => esc_url( $order->get_cancel_order_url() ),
	'isEmbedded'         => $gateway->use_embedded(),
	'checkoutUrl'        => esc_url( wc_get_checkout_url() ),
	'checkoutSessionUrl' => esc_url( $gateway->get_create_checkout_session_url( $order->get_id() ) )
);
wp_enqueue_script( 'mpgs-hosted-checkout', plugins_url( 'assets/js/hosted-checkout.js', MPGS_INCLUDE_FILE ), array(), MPGS_TARGET_MODULE_VERSION, true );
wp_localize_script( 'mpgs-hosted-checkout', 'mpgsHCParams', $params );
?>