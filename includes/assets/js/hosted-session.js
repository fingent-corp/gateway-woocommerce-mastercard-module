if ( self === top ) {
    var antiClickjack = document.getElementById( "antiClickjack" );
    antiClickjack.parentNode.removeChild( antiClickjack );
} else {
    top.location = self.location;
}

function hsFieldMap() {
    return {
        cardNumber: "#mpgs_gateway-card-number",
        number: "#mpgs_gateway-card-number",
        securityCode: "#mpgs_gateway-card-cvc",
        expiryMonth: "#mpgs_gateway-card-expiry-month",
        expiryYear: "#mpgs_gateway-card-expiry-year"
    };
}

function hsErrorsMap() {
    return {
        cardNumber: "Invalid Card Number",
        securityCode: "Invalid Security Code",
        expiryMonth: "Invalid Expiry Month",
        expiryYear: "Invalid Expiry Year"
    };
}

function mpgsPayWithSelectedInstrument() {
    var selected = document.querySelectorAll( '[name=wc-mpgs_gateway-payment-token]:checked' )[0];
    if ( selected === undefined ) {
        // Options not displayed at all
        PaymentSession.updateSessionFromForm( 'card', undefined, 'new' );
    } else if ( selected.value === 'new' ) {
        // New card options was selected
        PaymentSession.updateSessionFromForm( 'card', undefined, 'new' );
    } else {
        // Token
        PaymentSession.updateSessionFromForm( 'card', undefined, selected.value );
    }
}

(function ($) {
    var paymentSessionLoaded = {};
    
    $( ':input.woocommerce-SavedPaymentMethods-tokenInput' ).on('change', function () {
        $( '.token-cvc' ).hide();
        $( '#token-cvc-' + $( this ).val() ).show();
    });

    $.when( createSession() ).done( function ( response ) {
        if ( is3DsV2Enabled() ) {
            ThreeDS.configure({
                merchantId: mpgsSessionParams.merchantId,
                sessionId: response.session.id,
                containerId: "3DSUI",
                callback: function () {
                },
                configuration: {
                    wsVersion: mpgsSessionParams.apiVersion
                }
            });
        }

        var tokenChoices = $( '[name=wc-mpgs_gateway-payment-token]' );
        if ( tokenChoices.length > 1 ) {
            tokenChoices.on( 'change', function() {
                initSelectedPaymentMethod( response );
            });
            initSelectedPaymentMethod( response );
        } else {
            initializeNewPaymentSession( response.session.id );
        }   
    })
    .fail( console.error );

    function initSelectedPaymentMethod( response ) {
        var errorsContainer = document.getElementById( 'hostedsession_errors' );
        errorsContainer.style.display = 'none';

        var selectedPayment = $( '[name=wc-mpgs_gateway-payment-token]:checked' ).val();
        if ( 'new' === selectedPayment ) {
            initializeNewPaymentSession( response.session.id );
        } else {
            initializeTokenPaymentSession( response.session.id, selectedPayment );
        }
    }

    function is3DsV1Enabled() {
        if ( mpgsSessionParams.is3dsV1 ) {
            return true;
        } else {
            return false;
        }
    }

    function is3DsV2Enabled() {
        if ( mpgsSessionParams.is3dsV2 ) {
            return true;
        } else {
            return false;
        }
    }

    function initiateAuthentication( response ) {
        var txnId = '3DS-' + new Date().getTime().toString();

        if( response ) {
            getSurchargeAmount( response );
        } else {
            ThreeDS.initiateAuthentication(
                mpgsSessionParams.orderPrefixId,
                txnId,
                function ( data ) {
                    authenticatePayer( txnId, data );
                }
            );
        }
    }

    function displayChallengeAuth( data ) {
        if ( ! data.error ) {
            document.body.innerHTML = data.htmlRedirectCode;
        } else {
            placeOrderFail( data.error );
        }
    }

    function authenticatePayer( txnId, data ) {
        if ( data && data.error ) {
            var error = data.error;
            console.error( "error.code : ", error.code );
            console.error( "error.msg : ", error.msg );
            console.error( "error.result : ", error.result );
            console.error( "error.status : ", error.status );
            placeOrderFail( error );
        } else {
            switch ( data.gatewayRecommendation ) {
                case "PROCEED":
                    ThreeDS.authenticatePayer(
                        mpgsSessionParams.orderPrefixId,
                        txnId,
                        displayChallengeAuth,
                        {
                            fullScreenRedirect: true
                        }
                    );
                    break;
                case "DO_NOT_PROCEED":
                    console.log( "Payment was declined, please try again later." );
                    break;
                default:
                    break;   
            }
        }
    }

    function placeOrderFail ( error ) {
        console.log( "Payment was declined, please try again later." );
    }

    function getPaymentData() {
        return {
            '_wpnonce': mpgsSessionParams.sessionNonce,
            'save_new_card': $( '[name=wc-mpgs_gateway-new-payment-method]' ).is( ':checked' ),
            'wc-mpgs_gateway-payment-token': $( '[name=wc-mpgs_gateway-payment-token]:checked' ).val()
        }
    }

    function savePayment( data ) {
        return $.ajax({
            url: mpgsSessionParams.paymentUrl,
            method: 'post',
            data: data,
            dataType: 'json'
        });
    }

    function getSurchargeAmount( response ) {
        $.post( mpgsParams.ajaxUrl, { 
            order_id: mpgsSessionParams.orderId, 
            action: 'get_surcharge_amount', 
            funding_method: response?.sourceOfFunds?.provided?.card?.fundingMethod || "" ,
            token: response?.sourceOfFunds?.token || "",
            source_type: response?.sourceOfFunds?.type || ""
        }, function( response ) {
            if ( response.code === 200 ) {
                $('.woocommerce .order_details .woocommerce-Price-amount').html( response.order_total );
                setTimeout(function() { 
                    document.querySelector( '.mpgs_hostedsession' ).classList.remove( 'mpgs_processing' );
                    document.getElementById( 'mpgs_pay' ).style.display = 'none';
                    document.querySelector( '.mpgs_hostedsession .surcharge_wrapper' ).style.display = 'block';
                }, 1000);
            }               
        });
    }

    function placeOrder(response) {
        document.querySelector('.mpgs_hostedsession').classList.add('mpgs_processing');
    
        $.when(savePayment(getPaymentData())).done(function (response) {
            let fundingMethod = response?.sourceOfFunds?.provided?.card?.fundingMethod;

            if (!fundingMethod) {
                const selectedRadio = document.querySelector('input[name="wc-mpgs_gateway-payment-token"]:checked');
                fundingMethod = selectedRadio ? selectedRadio.getAttribute('data-card') : '';
            }
    
            document.querySelector('form.mpgs_hostedsession > input[name=funding_method]').value = fundingMethod;
    
            if (
                mpgsParams.isSurchargeEnabled &&
                mpgsParams.surchargeFee > 0 &&
                fundingMethod === mpgsParams.cardType
            ) {
                if (is3DsV2Enabled()) {
                    initiateAuthentication(response);
                } else {
                    document.querySelector('form.mpgs_hostedsession > input[name=session_id]').value = response.session.id;
                    document.querySelector('form.mpgs_hostedsession > input[name=session_version]').value = response.session.version;
                    console.log(getSurchargeAmount(response));
                    return;

                }
            } else {
                if (is3DsV2Enabled()) {
                    initiateAuthentication(false);
                } else {
                    document.querySelector('form.mpgs_hostedsession > input[name=session_id]').value = response.session.id;
                    document.querySelector('form.mpgs_hostedsession > input[name=session_version]').value = response.session.version;
                    document.querySelector('form.mpgs_hostedsession').submit();
                }
            }
        }).fail(console.error);
    }
    

    function initializeTokenPaymentSession( session_id, id ) {
        if ( paymentSessionLoaded[id] === true ) {
            return;
        }

        var config = {
            session: session_id,
            fields: {
                card: {
                    securityCode: '#mpgs_gateway-saved-card-cvc-' + id
                }
            },
            frameEmbeddingMitigation: ["javascript"],
            callbacks: {
                formSessionUpdate: function ( response ) {
                    var errorsContainer = document.getElementById( 'hostedsession_errors' );
                    errorsContainer.innerText = '';
                    errorsContainer.style.display = 'none';

                    if ( ! response.status ) {
                        errorsContainer.innerText = hsLoadingFailedMsg + ' (invalid response)';
                        errorsContainer.style.display = 'block';
                        return;
                    }
                    if ( response.status === "ok" ) {
                        if ( is3DsV1Enabled() ) {
                            document.querySelector( 'form.mpgs_hostedsession > input[name=check_3ds_enrollment]' ).value = '1';
                        }
                        console.log(response);
                        placeOrder( response );
                    } else {
                        errorsContainer.innerText = hsLoadingFailedMsg + ' (unexpected status: ' + response.status + ')';
                        errorsContainer.style.display = 'block';
                    }
                }
            },
            interaction: {
                displayControl: {
                    invalidFieldCharacters: 'REJECT',
                    formatCard: 'EMBOSSED'
                }
            }
        };

        PaymentSession.configure( config, id );
        paymentSessionLoaded[id] = true;
    }

    function createSession() {
        return $.ajax({
            url: mpgsSessionParams.sessionUrl,
            method: 'get',
            dataType: 'json'
        });
    }

    function initializeNewPaymentSession( session_id ) {
        if ( paymentSessionLoaded['new'] === true ) {
            return;
        }

        var config = {
            session: session_id,
            fields: {
                card: hsFieldMap()
            },
            frameEmbeddingMitigation: ["javascript"],
            callbacks: {
                formSessionUpdate: function ( response ) {
                    var fields = hsFieldMap();
                    for ( var field in fields ) {
                        var input = document.getElementById( fields[field].substr(1) );
                        input.style['border-color'] = 'inherit';
                    }

                    var errorsContainer = document.getElementById( 'hostedsession_errors' );
                    errorsContainer.innerText = '';
                    errorsContainer.style.display = 'none';

                    if ( ! response.status ) {
                        errorsContainer.innerText = hsLoadingFailedMsg + ' (invalid response)';
                        errorsContainer.style.display = 'block';
                        return;
                    }

                    if ( response.status === "fields_in_error" ) {
                        if ( response.errors ) {
                            var errors = hsErrorsMap(),
                                message = "";
                            for ( var field in response.errors ) {
                                if ( response.errors.hasOwnProperty( field ) ) {
                                    var input = document.getElementById( fields[field].substr(1) );
                                    input.style['border-color'] = 'red';

                                    message += errors[field] + "\n";
                                }
                            }
                            errorsContainer.innerText = message;
                            errorsContainer.style.display = 'block';
                        }
                    } else if ( response.status === "ok" ) {
                        if ( is3DsV1Enabled() ) {
                            document.querySelector( 'form.mpgs_hostedsession > input[name=check_3ds_enrollment]' ).value = '1';
                        }
                        placeOrder( response );
                    } else {
                        errorsContainer.innerText = hsLoadingFailedMsg + ' (unexpected status: ' + response.status + ')';
                        errorsContainer.style.display = 'block';
                    }
                }
            },
            interaction: {
                displayControl: {
                    invalidFieldCharacters: 'REJECT',
                    formatCard: 'EMBOSSED'
                }
            }
        };

        PaymentSession.configure( config, 'new' );
        paymentSessionLoaded['new'] = true;
    }

    document.querySelector( '.wp-element-confirm-button' ).addEventListener('click', function( event ) {
        if ( is3DsV2Enabled() ) {
            initiateAuthentication( false );
        } else {
            document.querySelector( 'form.mpgs_hostedsession' ).submit();
        }
    });
})(jQuery);

jQuery(document).ready(function($) {
    // On page load, set the initial value
    var selectedCard = $('input[name="wc-mpgs_gateway-payment-token"]:checked').data('card');
    $('#funding_method').val(selectedCard);
  
    // Update value when radio changes
    $('input[name="wc-mpgs_gateway-payment-token"]').on('change', function() {
      var cardType = $(this).data('card');
      $('#funding_method').val(cardType);
    });
  });
