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
 *
 * @package  Mastercard
 * @version  GIT: @1.4.5@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
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
	<input type="button" id="mpgs_pay" value="<?php esc_html_e( 'Pay', 'mastercard' ); ?>" onclick="Checkout.showPaymentPage();" />
<?php } ?><script type="text/javascript">
	function errorCallback( error ) {
		var err = JSON.stringify( error ),
			errorWrapper = jQuery( '.woocommerce-notices-wrapper' );
		if( errorWrapper.length > 0 ) {
			errorWrapper.html( error.responseText );
		}
	}
	function cancelCallback() {
		window.location.href = '<?php echo esc_attr( $order->get_cancel_order_url() ); ?>';
	}
	( function ( $ ) {
		var sessionKeysToClear = [];
		function cleanupBrowserSession() {
			var sessionKey, i;
			for ( i = 0; i < sessionKeysToClear.length; i++ ) {
				sessionKey = sessionKeysToClear[i];
				if ( sessionStorage.key( sessionKey ) ) {
					sessionStorage.removeItem( sessionKey );
				}
			}
		}
		<?php if ( $gateway->use_embedded() ) { ?>
			sessionKeysToClear.push( 'HostedCheckout_sessionId' );
		<?php } else { ?>
			sessionKeysToClear.push( 'HostedCheckout_embedContainer' );
		function togglePay() {
			$( '#mpgs_pay' ).prop( 'disabled', function ( i, v ) {
				return !v;
			});
		}
		<?php } ?>
		function waitFor( name, callback ) {
			if ( typeof window[name] === "undefined" ) {
				setTimeout(function () {
					waitFor( name, callback );
				}, 200 );
			} else {
				callback();
			}
		}
		function configureHostedCheckout( sessionData ) {
			var config = {
				session: {
					id: sessionData.session.id,
				}
			};
			waitFor( 'Checkout', function () {
				cleanupBrowserSession();
				Checkout.configure( config );
				<?php if ( $gateway->use_embedded() ) { ?>
					Checkout.showEmbeddedPage( '#embed-target' );
				<?php } else { ?>
					togglePay();
				<?php } ?>
			});
		}
		var xhr = $.ajax({
			method: 'GET',
			url: '<?php echo esc_attr( $gateway->get_create_checkout_session_url( $order->get_id() ) ); ?>',
			dataType: 'json'
		});
		<?php if ( ! $gateway->use_embedded() ) { ?>
			togglePay();
		<?php } ?>
		$.when( xhr )
			.done( $.proxy( configureHostedCheckout, this ) )
			.fail( $.proxy( errorCallback, this ) );

	})( jQuery );
</script>