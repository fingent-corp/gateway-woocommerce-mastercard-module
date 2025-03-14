/*
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
 */
const settings = window.wc.wcSettings.getSetting( 'mpgs_gateway_data', {} ),
    label = window.wp.htmlEntities.decodeEntities( settings.title ) || window.wp.i18n.__( 'Mastercard Gateway', 'mpgs_gateway' ),
    Content = () => {
        return window.wp.htmlEntities.decodeEntities( settings.description || '' );
    },
    Mastercard_Block_Gateway = {
        name: 'mpgs_gateway',
        label: label,
        content: Object( window.wp.element.createElement )( Content, null ),
        edit: Object( window.wp.element.createElement )( Content, null ),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings.supports,
        },
    };
window.wc.wcBlocksRegistry.registerPaymentMethod( Mastercard_Block_Gateway );

document.addEventListener( 'DOMContentLoaded', function () {
    if ( typeof wp !== 'undefined' && wp.data && wp.data.subscribe ) {

        let previousPaymentMethod = null;

        // Subscribe to WooCommerce checkout store changes
        wp.data.subscribe(function () {
            const selectedPaymentMethod = wp.data.select( 'wc/store/payment' ).getActivePaymentMethod() || null;

            if ( selectedPaymentMethod && selectedPaymentMethod !== previousPaymentMethod ) {
                previousPaymentMethod = selectedPaymentMethod;

                jQuery.ajax({
                    type: 'POST',
                    url: woocommerce_params.ajax_url,
                    data: {
                        action: 'update_selected_payment_method',
                        payment_method: selectedPaymentMethod,
                    },
                    success: function ( response ) {}
                });
            }
        });
    } else {
        console.warn( "WooCommerce Blocks not detected." );
    }
});

jQuery( function( $ ) {
    if ( 'undefined' !== typeof wc &&  wc?.blocksCheckout ) {
        const { extensionCartUpdate } = wc.blocksCheckout;
        const element = `.wc-block-components-totals-fees__${handlingText}`;

        $( document ).on( 'click', 'input[name="radio-control-wc-payment-method-options"]', function() {
            selectPaymentMethod();
        });

        function selectPaymentMethod() { 
            const selectedPaymentMethod = wp.data.select( 'wc/store/payment' ).getActivePaymentMethod() || null; 
            if( 'mpgs_gateway' === selectedPaymentMethod ) {
                if( $( element ).length < 0 ) {
                    $( '.wp-block-woocommerce-checkout-order-summary-fee-block' ).html( handlingFeeWrapper );
                }
                $( element ).show();
            } else {
                $( element ).hide();
            }

            $( '.wc-block-components-order-summary__content' ).block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });

            extensionCartUpdate( {
                namespace: 'mpgs_gateway_handling_fee',
            } )
            .then( () => {
            } )
            .finally(() => {
                $( '.wc-block-components-order-summary__content' ).unblock();
            });
        }

        // Run on page load
        selectPaymentMethod();

        // Run after WooCommerce updates checkout (e.g., shipping method changes)
        $( document.body ).on( 'updated_checkout', function() {           
            selectPaymentMethod();
        });

        return;
    }
});