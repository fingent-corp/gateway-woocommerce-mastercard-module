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
jQuery(function ($) {
    'use strict';
    var wc_mastercard_admin = {
        init: function () {
            var sandbox_username = $('#woocommerce_mpgs_gateway_sandbox_username').parents('tr').eq(0),
                sandbox_password = $('#woocommerce_mpgs_gateway_sandbox_password').parents('tr').eq(0),
                username         = $('#woocommerce_mpgs_gateway_username').parents('tr').eq(0),
                password         = $('#woocommerce_mpgs_gateway_password').parents('tr').eq(0),
                threedsecure     = $('#woocommerce_mpgs_gateway_threedsecure').parents('tr').eq(0),
                gateway_url      = $('#woocommerce_mpgs_gateway_custom_gateway_url').parents('tr').eq(0),
                hc_interaction   = $('#woocommerce_mpgs_gateway_hc_interaction').parents('tr').eq(0),
                hc_type          = $('#woocommerce_mpgs_gateway_hc_type').parents('tr').eq(0),
                saved_cards      = $('#woocommerce_mpgs_gateway_saved_cards').parents('tr').eq(0),
                merchant_info    = $('#woocommerce_mpgs_gateway_merchant_info');

            $('#woocommerce_mpgs_gateway_sandbox').on('change', function () {
                if ($(this).is(':checked')) {
                    sandbox_username.show();
                    sandbox_password.show();
                    username.hide();
                    password.hide();
                } else {
                    sandbox_username.hide();
                    sandbox_password.hide();
                    username.show();
                    password.show();
                }
            }).change();

            $('#woocommerce_mpgs_gateway_method').on('change', function () {
                if ($(this).val() === 'hosted-checkout') {
                    // Hosted Checkout
                    threedsecure.hide();
                    hc_interaction.show();
                    hc_type.hide();
                    saved_cards.hide();
                } else {
                    // Hosted Session
                    threedsecure.show();
                    hc_interaction.hide();
                    hc_type.hide();
                    saved_cards.show();
                }
            }).change();

            $('#woocommerce_mpgs_gateway_gateway_url').on('change', function () {
                if ($(this).val() === 'custom') {
                    gateway_url.show();
                } else {
                    gateway_url.hide();
                }
            }).change();

            $('#woocommerce_mpgs_gateway_method').on('change', function () {
                if ($(this).val() === 'hosted-checkout' && $('#woocommerce_mpgs_gateway_hc_interaction').val() === 'redirect') {
                    merchant_info.show();
                    merchant_info.next().show();
                    merchant_info.next().next().show();
                } else {
                    merchant_info.hide();
                    merchant_info.next().hide();
                    merchant_info.next().next().hide();
                }
            }).change();

            $('#woocommerce_mpgs_gateway_hc_interaction').on('change', function () {
                if ($(this).val() === 'redirect') {
                    merchant_info.show();
                    merchant_info.next().show();
                    merchant_info.next().next().show();
                } else {
                    merchant_info.hide();
                    merchant_info.next().hide();
                    merchant_info.next().next().hide();
                }
            }).change();

            $( '#woocommerce_mpgs_gateway_handling_fee_amount' ).before( '<span id="handling_fee_amount_label"></span>' );
            $( '#handling_fee_amount_label' ).css({ "width": "35px", "height": "31px", "line-height": "30px", "background-color": "#eaeaea", "text-align": "center", "position": "absolute", "left": "1px", "top": "1px", "border-radius": "3px 0 0 3px" }).parent().css( "position", "relative" );
            $( '#woocommerce_mpgs_gateway_handling_fee_amount' ).css( "padding-left", "45px" );
            if( $( '#woocommerce_mpgs_gateway_hf_amount_type' ).val() === 'fixed' ) {
                $( '#handling_fee_amount_label' ).html( wcSettings.currency.symbol );
            } else {
                $( '#handling_fee_amount_label' ).html( '%' );
            }

            $('#woocommerce_mpgs_gateway_hf_amount_type').on('change', function () {
                if( $( this ).val() === 'fixed' ) {
                    $( '#handling_fee_amount_label' ).html( wcSettings.currency.symbol );
                } else {
                    $( '#handling_fee_amount_label' ).html( '%' );
                }
            }).change(); 
            $( '#woocommerce_mpgs_gateway_handling_fee_amount' ).on( 'keypress', function(e) {
                var charCode = ( e.which ) ? e.which : e.keyCode;
                if ( charCode === 46 || charCode === 8 || charCode === 9 || charCode === 27 || charCode === 13 ||
                    ( charCode === 65 && ( e.ctrlKey === true || e.metaKey === true ) ) ||
                    ( charCode === 67 && ( e.ctrlKey === true || e.metaKey === true ) ) ||
                    ( charCode === 86 && ( e.ctrlKey === true || e.metaKey === true ) ) ||
                    ( charCode === 88 && ( e.ctrlKey === true || e.metaKey === true ) ) ||
                    // Allow: home, end, left, right
                    ( charCode >= 35 && charCode <= 39 ) ) {
                    return;
                }

                if ( ( charCode < 48 || charCode > 57 ) && charCode !== 46 ) {
                    e.preventDefault();
                }

                if ( charCode === 46 && $( this ).val().indexOf( '.' ) !== -1 ) {
                    e.preventDefault();
                }
            });
            $( '#woocommerce_mpgs_gateway_handling_fee_amount' ).on( 'input', function() {
                var value = this.value;
                if ( !/^\d*\.?\d*$/.test( value ) ) {
                    this.value = value.substring( 0, value.length - 1 );
                }
            });   

            $( '#woocommerce_mpgs_gateway_merchant_logo' ).after( '&nbsp;&nbsp;&nbsp;<a href="javascript:;" id="mpgs_gateway_merchant_logo" class="button">Upload</a>' );

            var customUploader = '';
            $( '#mpgs_gateway_merchant_logo' ).on( 'click', function(e) {
                e.preventDefault();
                if ( customUploader) {
                    customUploader.open();
                    return;
                }
                customUploader = wp.media.frames.file_frame = wp.media({
                    title: 'Upload logo',
                    button: {
                        text: 'Choose logo'
                    },
                    multiple: false,
                    library: {
                        type: [ 'image/jpeg', 'image/png', 'image/svg+xml' ]
                    },
                });
                customUploader.on('select', function() {                    
                    var attachment = customUploader.state().get('selection').first().toJSON();
                    var previousElement = e.target.previousElementSibling;
                    
                    if( previousElement ) {
                        $( previousElement ).val( attachment.url );
                        if( $( previousElement ).parents( '#mainform' ).find( '.woocommerce-save-button' ).attr( 'disabled' ) === 'disabled' ) {
                            $( previousElement ).parents( '#mainform' ).find( '.woocommerce-save-button' ).removeAttr( 'disabled' );
                        }
                    }
                });
                customUploader.open();
            }); 
        }
    };
    wc_mastercard_admin.init();
});
