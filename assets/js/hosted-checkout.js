/**
 * Hosted payment flow for Mastercard Gateway.
 *
 * @package Mastercard_Gateway
 */

// Hosted checkout script bootstrap.
let hostedCheckoutReady = true;

function getGatewayErrorMessage( payload ) {
	if ( ! payload ) {
		return '';
	}
	if ( payload.errors && typeof payload.errors.message === 'string' && payload.errors.message ) {
		return payload.errors.message;
	}
	if ( typeof payload.message === 'string' && payload.message ) {
		return payload.message;
	}
	return '';
}

function errorCallback( error ) {
	let message      = 'Payment could not be loaded. Please refresh and try again.',
		errorWrapper = jQuery( '.woocommerce-notices-wrapper' );

	if ( error && error.responseJSON ) {
		message = getGatewayErrorMessage( error.responseJSON ) || message;
	} else if ( error && error.responseText ) {
		try {
			message = getGatewayErrorMessage( JSON.parse( error.responseText ) ) || error.responseText;
		} catch {
			message = error.responseText;
		}
	} else if ( error && error.message ) {
		message = error.message;
	}

	if ( errorWrapper.length > 0 ) {
		errorWrapper.empty().append(
			jQuery( '<div>' ).addClass( 'woocommerce-error' ).text( message )
		);
	}

	jQuery( '#embed-target' ).empty().append(
		jQuery( '<p>' ).addClass( 'woocommerce-error' ).text( message )
	);
}

function cancelCallback() {
	globalThis.location.href = mgHCParams.orderCancelUrl;
}

( function ( $ ) {
	function cleanupBrowserSession() {
		// Remove sessionId only if merchantState is null and sessionId exists.
		if (sessionStorage.getItem( 'HostedCheckout_merchantState' ) === null &&
			sessionStorage.getItem( 'HostedCheckout_sessionId' ) !== null) {
			sessionStorage.removeItem( 'HostedCheckout_sessionId' );
		}

		// Remove embedContainer if it exists.
		if (sessionStorage.getItem( 'HostedCheckout_embedContainer' ) !== null) {
			sessionStorage.removeItem( 'HostedCheckout_embedContainer' );
		}

	}

	function togglePay() {
		$( '#mpgs_pay' ).prop(
			'disabled',
			function ( i, v ) {
				return ! v;
			}
		);
		let url  = globalThis.location.href,
			hash = url.split( '#' )[1];

		if ( hash === '__hc-action-cancel' ) {
			globalThis.location.href = mgHCParams.checkoutUrl;
		}
		$( '#mpgs_pay' ).trigger( 'click' );
	}

	function waitFor( name, callback ) {
		if ( globalThis[name] === undefined ) {
			setTimeout(
				function () {
					waitFor( name, callback );
				},
				200
			);
		} else {
			callback();
		}
	}
	function setCookie(name, value, minutesToExpire) {
		const date = new Date();
		date.setTime( date.getTime() + ( minutesToExpire * 60 * 1000 ) ); // Set expiration time in milliseconds.
		const expires   = "expires=" + date.toUTCString();
		document.cookie = name + "=" + value + ";" + expires + ";path=/";
	}

	// Modify the AJAX call.
	let xhr = $.ajax(
		{
			method: 'POST',
			url: mgHCParams.checkoutSessionUrl,
			dataType: 'json',
			beforeSend: function (xhr) {
				if ( mgHCParams.orderToken ) {
					xhr.setRequestHeader( 'X-MG-Order-Token', mgHCParams.orderToken );
				}
			}
		}
	);

	// When the AJAX call is successful, then call the configureHostedCheckout function.
	$.when( xhr )
		.done( $.proxy( configureHostedCheckout, this ) )
		.fail( $.proxy( errorCallback, this ) );

	// Define the configureHostedCheckout function.
	function configureHostedCheckout( sessionData ) {
		if ( ! sessionData || ! sessionData.session || ! sessionData.session.id ) {
			errorCallback( { message: 'Invalid checkout session response from server.' } );
			return;
		}

		setCookie( 'mgps-woo-hc-ch', sessionData.successIndicator, 5 );
		let config = {
			session: {
				id: sessionData.session.id,
			},
		};
		waitFor(
			'Checkout',
			function () {
				cleanupBrowserSession();
				try {
					Checkout.configure( config );
					if ( mgHCParams.isEmbedded ) {
						Checkout.showEmbeddedPage( '#embed-target' );
					} else {
						togglePay();
					}
				} catch ( configureError ) {
					errorCallback( configureError );
				}
			}
		);
	}

	if ( ! mgHCParams.isEmbedded ) {
		togglePay();
	}
})( jQuery );