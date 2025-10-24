function errorCallback( error ) { 
    var err = JSON.stringify( error ),
        errorWrapper = jQuery( '.woocommerce-notices-wrapper' );
    if( errorWrapper.length > 0 ) {
        errorWrapper.html( error.responseText );
    }
}

function cancelCallback() {
    window.location.href = mpgsHCParams.orderCancelUrl;
}

( function ( $ ) {
    var sessionKeysToClear = [];
    function cleanupBrowserSession() {  
        // Remove sessionId only if merchantState is null and sessionId exists
        if (sessionStorage.getItem('HostedCheckout_merchantState') === null &&
            sessionStorage.getItem('HostedCheckout_sessionId') !== null) {
            sessionStorage.removeItem('HostedCheckout_sessionId');
        }

        // Remove embedContainer if it exists
        if (sessionStorage.getItem('HostedCheckout_embedContainer') !== null) {
            sessionStorage.removeItem('HostedCheckout_embedContainer');
        }

    }
    if ( ! mpgsHCParams.isEmbedded ) {            
        function togglePay() {
            $( '#mpgs_pay' ).prop( 'disabled', function ( i, v ) {
                return !v;
            });
            var url = window.location.href,
                hash = url.split( '#' )[1]; 
    
            if ( hash === '__hc-action-cancel' ) {
                window.location.href = mpgsHCParams.checkoutUrl;
            } 
            $('#mpgs_pay').trigger( 'click' );
        }
    }
    
    function waitFor( name, callback ) {
        if ( typeof window[name] === "undefined" ) {
            setTimeout(function () {
                waitFor( name, callback );
            }, 200 );
        } else {
            callback();
        }
    }
    function setCookie(name, value, minutesToExpire) {
        const date = new Date();
        date.setTime( date.getTime() + ( minutesToExpire * 60 * 1000 ) ); // Set expiration time in milliseconds
        const expires = "expires=" + date.toUTCString();
        document.cookie = name + "=" + value + ";" + expires + ";path=/";
    }

    // Modify the AJAX call
    var xhr = $.ajax({
        method: 'GET',
        url: mpgsHCParams.checkoutSessionUrl,
        dataType: 'json'
    });

    // When the AJAX call is successful, then call the configureHostedCheckout function
    $.when( xhr )
        .done( $.proxy( configureHostedCheckout, this ) )
        .fail( $.proxy( errorCallback, this ) );

    // Define the configureHostedCheckout function
    function configureHostedCheckout( sessionData ) {
        setCookie( 'mgps-woo-hc-ch', sessionData.successIndicator, 5 );
        var config = {
            session: {
                id: sessionData.session.id,
            }
        };
        waitFor( 'Checkout', function () {
            cleanupBrowserSession();
            Checkout.configure( config );
            if ( mpgsHCParams.isEmbedded ) {
                Checkout.showEmbeddedPage('#embed-target');
            } else { 
                togglePay();
            }
        });
    }

    if ( ! mpgsHCParams.isEmbedded ) {
        togglePay();            
    }
})( jQuery );