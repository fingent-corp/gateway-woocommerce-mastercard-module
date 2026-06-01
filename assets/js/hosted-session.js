// Global message for hosted session failure
var hsLoadingFailedMsg = '';

// Anti-clickjacking protection
if ( self === top ) {
    var antiClickjack = document.getElementById( "antiClickjack" );
    if ( antiClickjack ) 
        antiClickjack.parentNode.removeChild( antiClickjack );
} else {
    top.location = self.location;
}

// Map form field selectors to Hosted Session field names
function hsFieldMap() {
    return {
        cardNumber   : "#" + mgParams.gatewayId + "-card-number",
        number       : "#" + mgParams.gatewayId + "-card-number",
        securityCode : "#" + mgParams.gatewayId + "-card-cvc",
        expiryMonth  : "#" + mgParams.gatewayId + "-card-expiry-month",
        expiryYear   : "#" + mgParams.gatewayId + "-card-expiry-year"
    };
}

// Map Hosted Session field names to error messages
function hsErrorsMap() {
    if (typeof mgSessionParams !== 'undefined' && mgSessionParams.errorMessages) {
        return mgSessionParams.errorMessages;
    }

    return {
        cardNumber   : "Please enter a valid card number.",
        securityCode : "The security code entered is invalid.",
        expiryMonth  : "Please enter a valid expiry month.",
        expiryYear   : "Please enter a valid expiry year."
    };
}


// Update payment session based on selected token (new card or saved)
function mgPayWithSelectedInstrument() {
    var selected = document.querySelector( '[name=wc-' + mgParams.gatewayId + '-payment-token]:checked' );

    if ( ! selected || selected.value === 'new' ) {
        PaymentSession.updateSessionFromForm( 'card', undefined, 'new' );
    } else {
        PaymentSession.updateSessionFromForm( 'card', undefined, selected.value );
    }
}

(function ( $ ) {
    var paymentSessionLoaded = {};

    // Show correct CVC field for saved cards
    $( ':input.woocommerce-SavedPaymentMethods-tokenInput' ).on( 'change', function () {
        $( '.token-cvc' ).hide();
        $( '#token-cvc-' + $( this ).val() ).show();
    });

    // Create Hosted Session and initialize payment logic
    $.when( createSession() ).done( function ( response ) {
        // Setup 3DSv2 if enabled
        if ( is3DsV2Enabled() ) {
            ThreeDS.configure({
                merchantId: mgSessionParams.merchantId,
                sessionId: response.session.id,
                containerId: "3DSUI",
                callback: function () { },
                configuration: {
                    wsVersion: mgSessionParams.apiVersion
                }
            });
        }

        // Initialize based on saved card or new
        var tokenChoices = $( '[name=wc-' + mgParams.gatewayId + '-payment-token]' );
        
        if ( tokenChoices.length > 1 ) {
            tokenChoices.on( 'change', function () {
                initSelectedPaymentMethod( response );
            });
            initSelectedPaymentMethod( response );
        } else {
            initializeNewPaymentSession( response.session.id );
        }
    }).fail( console.error );

    // Handle token vs new payment selection
    function initSelectedPaymentMethod( response ) {
        $( '#hostedsession_errors' ).hide();
        var selectedPayment = $( '[name=wc-' + mgParams.gatewayId + '-payment-token]:checked' ).val();

        if ( selectedPayment === 'new' ) {
            initializeNewPaymentSession( response.session.id );
        } else {
            initializeTokenPaymentSession( response.session.id, selectedPayment );
        }
    }

    // Check if 3DSv1 or v2 is enabled
    function is3DsV1Enabled() {
        return !!mgSessionParams.is3dsV1;
    }

    function is3DsV2Enabled() {
        return !!mgSessionParams.is3dsV2;
    }

    // Entry point to trigger authentication
    function initiateAuthentication( response ) {
        var txnId = '3DS-' + new Date().getTime().toString();

        if ( response ) {
            getSurchargeAmount( response );
        } else {
            ThreeDS.initiateAuthentication( mgSessionParams.orderPrefixId, txnId, function ( data ) {
                authenticatePayer( txnId, data );
            });
        }
    }

    // Handle 3DS challenge screen
    function displayChallengeAuth( data ) {
        if ( ! data.error ) {
            document.body.innerHTML = data.htmlRedirectCode;
        } else {
            placeOrderFail( data.error );
        }
    }

    // Process result of payer authentication
    function authenticatePayer( txnId, data ) { 
        if ( data?.error ) {
            placeOrderFail( data.error );
        } else {
            switch ( data.gatewayRecommendation ) {
                case "PROCEED":
                    ThreeDS.authenticatePayer(
                        mgSessionParams.orderPrefixId,
                        txnId,
                        displayChallengeAuth,
                        { fullScreenRedirect: true }
                    );
                    break;
                case "DO_NOT_PROCEED":
                    console.log( "Payment was declined, please try again later 1." );
                    break;
                default:
                    break;
            }
        }
    }

    // Handle general payment failure
    function placeOrderFail( error ) { 
        const message = ( error.cause ? error.cause + ': ' : '' ) + 'Payment was declined, please try again later.';
        $( '.mg_hostedsession' ).removeClass( 'mg_processing' );
        $( '#hostedsession_errors' ).html( message ).show();
    }

    // Collect data for payment submission
    function getPaymentData() {
        return {
            '_wpnonce': mgSessionParams.sessionNonce,
            'save_new_card': $( '[name=wc-' + mgParams.gatewayId + '-new-payment-method]' ).is( ':checked' ),
            'wc-mastercard_gateway-payment-token': $( '[name=wc-' + mgParams.gatewayId + '-payment-token]:checked' ).val()
        };
    }

    // Save payment data to backend
    function savePayment( data ) { 
        return $.ajax({
            url: mgSessionParams.paymentUrl,
            method: 'post',
            data: data,
            dataType: 'json',
            beforeSend: function (xhr) {
                if ( mgSessionParams.authorization ) {
                    xhr.setRequestHeader( 'Authorization', mgSessionParams.authorization );
                    xhr.setRequestHeader( 'X-MG-Access-Token', mgSessionParams.authorization );
                }
            }
        });
    }

    // Retrieve and apply surcharge information
    function getSurchargeAmount( response ) {
        $.post( mgParams.ajaxUrl, {
            order_id       : mgSessionParams.orderId,
            action         : 'get_surcharge_amount',
            funding_method : response?.sourceOfFunds?.provided?.card?.fundingMethod || "",
            token          : response?.sourceOfFunds?.token || "",
            source_type    : response?.sourceOfFunds?.type || ""
        }, function ( response ) {
            if ( response.code === 200 ) {
                $( '.woocommerce .order_details .woocommerce-Price-amount' ).html( response.order_total );
                setTimeout( function () {
                    $( '.mg_hostedsession' ).removeClass( 'mg_processing' );
                    $( '#mg_pay' ).hide();
                    $( '.mg_hostedsession .surcharge_wrapper' ).show();
                }, 1000 );
            }
        });
    }

    // Main function to handle payment submission
    function placeOrder( response ) {
        $( '.mg_hostedsession' ).addClass( 'mg_processing' );

        $.when( savePayment( getPaymentData() ) ).done( function ( response ) { 
            let fundingMethod = response?.sourceOfFunds?.provided?.card?.fundingMethod
                || $( 'input[name="wc-' + mgParams.gatewayId + '-payment-token"]:checked' ).data( 'card' );

            $( 'form.mg_hostedsession input[name=funding_method]' ).val( fundingMethod );

            if ( mgParams.isSurchargeEnabled && mgParams.surchargeFee > 0 && fundingMethod === mgParams.cardType ) {
                if ( is3DsV2Enabled() ) {
                    initiateAuthentication( response );
                } else {
                    $( 'form.mg_hostedsession input[name=session_id]' ).val( response.session.id );
                    $( 'form.mg_hostedsession input[name=session_version]' ).val( response.session.version );
                    getSurchargeAmount( response );
                }
            } else {
                if ( is3DsV2Enabled() ) {
                    initiateAuthentication( false );
                } else {
                    $( 'form.mg_hostedsession input[name=session_id]' ).val( response.session.id );
                    $( 'form.mg_hostedsession input[name=session_version]' ).val( response.session.version );
                    $( 'form.mg_hostedsession' ).submit();
                }
            }
        }).fail( console.error );
    }

    // Configure session for token-based payment
    function initializeTokenPaymentSession( session_id, id ) {
        if ( paymentSessionLoaded[id] ) return;

        PaymentSession.configure({
            session: session_id,
            fields: {
                card: {
                    securityCode: '#' + mgParams.gatewayId + '-saved-card-cvc-' + id
                }
            },
            frameEmbeddingMitigation: ["javascript"],
            callbacks: {
                formSessionUpdate: function ( response ) {
                    var errorsContainer = $( '#hostedsession_errors' ).hide().text( '' );
                    if ( !response.status ) {
                        errorsContainer.text( hsLoadingFailedMsg + ' (invalid response)' ).show();
                        return;
                    }

                    if ( response.status === "ok" ) {
                        if ( is3DsV1Enabled() ) {
                            $( 'form.mg_hostedsession input[name=check_3ds_enrollment]' ).val( '1' );
                        }
                        placeOrder( response );
                    } else {
                        errorsContainer.text( hsLoadingFailedMsg + ' (unexpected status: ' + response.status + ')' ).show();
                    }
                }
            },
            interaction: {
                displayControl: {
                    invalidFieldCharacters: 'REJECT',
                    formatCard: 'EMBOSSED'
                }
            }
        }, id );

        paymentSessionLoaded[id] = true;
    }

    // Ajax call to create a new session
    function createSession() {
        return $.ajax({
            url: mgSessionParams.sessionUrl,
            method: 'get',
            dataType: 'json',
            beforeSend: function (xhr) {
                if ( mgSessionParams.authorization ) {
                    xhr.setRequestHeader( 'Authorization', mgSessionParams.authorization );
                    xhr.setRequestHeader( 'X-MG-Access-Token', mgSessionParams.authorization );
                }
            }
        });
    }

    // Configure session for new card entry
    function initializeNewPaymentSession( session_id ) {
        if ( paymentSessionLoaded['new'] ) return;

        PaymentSession.configure({
            session: session_id,
            fields: {
                card: hsFieldMap()
            },
            frameEmbeddingMitigation: ["javascript"],
            callbacks: {
                formSessionUpdate: function ( response ) {
                    var fields = hsFieldMap();
                    for ( var field in fields ) {
                        $( fields[field] ).css( 'border-color', '' );
                    }

                    var errorsContainer = $( '#hostedsession_errors' ).hide().text( '' );

                    if ( !response.status ) {
                        errorsContainer.text( hsLoadingFailedMsg + ' (invalid response)' ).show();
                        return;
                    }

                    if ( response.status === "fields_in_error" && response.errors ) {
                        var errors = hsErrorsMap(), message = "";
                        for ( var field in response.errors ) {
                            $( fields[field] ).css( 'border-color', '#f8d7da' );
                            message += '<p>' + errors[field] + "</p>";
                        }
                        errorsContainer.html( message ).show();
                    } else if ( response.status === "ok" ) {
                        if ( is3DsV1Enabled() ) {
                            $( 'form.mg_hostedsession input[name=check_3ds_enrollment]' ).val( '1' );
                        }

                        placeOrder( response );
                    } else {
                        errorsContainer.text( hsLoadingFailedMsg + ' (unexpected status: ' + response.status + ')' ).show();
                    }
                }
            },
            interaction: {
                displayControl: {
                    invalidFieldCharacters: 'REJECT',
                    formatCard: 'EMBOSSED'
                }
            }
        }, 'new' );

        paymentSessionLoaded['new'] = true;
    }

    // Handle confirmation button click
    $( '.wp-element-confirm-button' ).on( 'click', function () {
        if ( is3DsV2Enabled() ) {
            initiateAuthentication( false );
        } else {
            $( 'form.mg_hostedsession' ).submit();
        }
    });
})( jQuery );

// Handle setting the funding method value on page load and selection change
jQuery( function ( $ ) {
    var selectedCard = $( 'input[name="wc-' + mgParams.gatewayId + '-payment-token"]:checked' ).data( 'card' );
    $( '#funding_method' ).val( selectedCard );

    $( 'input[name="wc-' + mgParams.gatewayId + '-payment-token"]' ).on( 'change', function () {
        var cardType = $( this ).data( 'card' );
        $( '#funding_method' ).val( cardType );
    });
});
