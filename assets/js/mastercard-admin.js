jQuery(function ($) {
    'use strict';

    const wc_mastercard_admin = {
        customUploader: '',
        init: function () {
            this.cacheDOM();
            this.bindEvents();
            this.initializeUI();
        },

        cacheDOM: function () {
            this.$sandboxToggle    = $( '#woocommerce_mastercard_gateway_sandbox' );
            this.$sandboxUsername  = $( '#woocommerce_mastercard_gateway_sandbox_username' ).closest( 'tr' );
            this.$sandboxPassword  = $( '#woocommerce_mastercard_gateway_sandbox_password' ).closest( 'tr' );
            this.$username         = $( '#woocommerce_mastercard_gateway_username' ).closest( 'tr' );
            this.$password         = $( '#woocommerce_mastercard_gateway_password' ).closest( 'tr' );
            this.$webhook          = $( '#woocommerce_mastercard_gateway_webhook_secret' ).closest( 'tr' );
            this.$testwebhook      = $( '#woocommerce_mastercard_gateway_test_webhook_secret' ).closest( 'tr' );

            this.$method           = $( '#woocommerce_mastercard_gateway_method' );
            this.$threedsecure     = $( '#woocommerce_mastercard_gateway_threedsecure' ).closest( 'tr' );
            this.$hcInteraction    = $( '#woocommerce_mastercard_gateway_hc_interaction' ).closest( 'tr' );
            this.$Interaction      = $( '#woocommerce_mastercard_gateway_hc_interaction' );
            this.$hcType           = $( '#woocommerce_mastercard_gateway_hc_type' ).closest( 'tr' );
            this.$savedCards       = $( '#woocommerce_mastercard_gateway_saved_cards' ).closest( 'tr' );
            this.$customGatewayUrl = $('#woocommerce_mastercard_gateway_custom_gateway_url' ).closest( 'tr' );

            this.$hfAmount         = $('#woocommerce_mastercard_gateway_handling_fee_amount' );
            this.$hfType           = $('#woocommerce_mastercard_gateway_hf_amount_type' );
            this.$surchargeAmount  = $('#woocommerce_mastercard_gateway_surcharge_amount' );
            this.$surchargeType    = $('#woocommerce_mastercard_gateway_surcharge_amount_type' );

            this.$surchargeInfo    = $('#woocommerce_mastercard_gateway_surcharge' );
            this.$merchantInfo     = $('#woocommerce_mastercard_gateway_merchant_info' );
            this.$surChargeTab     = $( 'a.nav-tab[href="#surcharge"]' );
            
            this.$mifCheckbox      = $('#woocommerce_mastercard_gateway_mif_enabled');
            this.$hfEnabled        = $('#woocommerce_mastercard_gateway_hf_enabled');
            this.$scEnabled        = $('#woocommerce_mastercard_gateway_surcharge_enabled');
            this.$merchantInfoTab  = $( 'a.nav-tab[href="#merchant-information"]' );
            this.$mainForm         = $('#mainform');

            this.$logoField        = $('#woocommerce_mastercard_gateway_merchant_logo');
            this.$uploadLogoBtn    = $('<a href="javascript:;" id="mastercard_gateway_merchant_logo" class="button">Upload</a>');
            this.$clearLogoBtn     = $('<a href="javascript:;" id="clear_mastercard_gateway_merchant_logo" class="button">Clear</a>');
            
            this.$openPreview      = $( '#payment-form-preview-root' );
            this.$closePanel       = $( '#close-wp-admin-drawer' );
            this.$previewPanel     = $( '#wp-admin-drawer' );
            this.$overlayPanel     = $( '#wp-admin-drawer-overlay' );

            this.$localeSelect     = $( '#woocommerce_mastercard_gateway_locale' );
            this.hcLangs           = this.$localeSelect.data( 'hc-langs' );
            this.hsLangs           = this.$localeSelect.data( 'hs-langs' );
            this.$stylePreviewRow  = $('#woocommerce_mastercard_gateway_payment_form_styles');
            this.$stylePreviewDes  = $('.hosted-session-preview');
            this.$saveBtn          = this.$mainForm.find('.woocommerce-save-button');

            this.$logoField.after('&nbsp;').after(this.$clearLogoBtn).after('&nbsp;').after(this.$uploadLogoBtn).after('&nbsp;');
        },

        bindEvents: function () {
            this.$sandboxToggle.on( 'change', this.toggleSandboxFields.bind( this ) );
            this.$method.on( 'change', this.toggleMethodFields.bind( this ) );
            this.$method.add( this.$hcInteraction ).on( 'change', this.toggleMerchantInfo.bind( this ) );

            this.$hfType.on( 'change', () => this.updateFeeLabel( 'handling' ) );
            this.$surchargeType.on( 'change', () => this.updateFeeLabel( 'surcharge' ) );

            this.$hfAmount.add( this.$surchargeAmount )
                .on( 'keypress', this.restrictInputToNumber )
                .on( 'input', this.validateDecimalInput );

            this.$mifCheckbox.on('change', () => this.toggleFieldGroup(this.$mifCheckbox, 10));
            this.$hfEnabled.on('change', () => this.toggleFieldGroup(this.$hfEnabled, 5));
            this.$scEnabled.on('change', () => this.toggleFieldGroup( this.$scEnabled , 7)); 
            this.$uploadLogoBtn.on('click', this.uploadLogo.bind(this)); 
            this.$mainForm.on( 'submit', this.validateBeforeSubmit.bind(this) );
            $( '#mainform' ).on( 'submit', this.validateBeforeSubmit.bind(this) );

            this.$openPreview.on( 'click', () => {
                this.$previewPanel.addClass( 'active' );
                this.$overlayPanel.addClass( 'active' );
            });

            this.$closePanel.on( 'click', () => {
                this.$previewPanel.removeClass( 'active' );
                this.$overlayPanel.removeClass( 'active' );
            });

            this.$clearLogoBtn.on('click', (e) => {
                e.preventDefault();
                this.$logoField.val('');
                const $saveBtn = this.$mainForm.find('.woocommerce-save-button');
                $saveBtn.removeAttr('disabled');
            });
            
        },

        initializeUI: function () {
            this.toggleSandboxFields();
            this.toggleMethodFields();
            this.toggleMerchantInfo();
            this.insertAmountLabels();
            this.initializeCheckboxStatusLabels();
            this.toggleFieldGroup(this.$mifCheckbox, 10);
            this.toggleFieldGroup(this.$hfEnabled, 5);
            this.toggleFieldGroup(this.$scEnabled, 6);
            this.updateLocaleOptions();
        },

        toggleSandboxFields: function () {
            const isSandbox = this.$sandboxToggle.is( ':checked' );
            this.$sandboxUsername.toggle( isSandbox );
            this.$sandboxPassword.toggle( isSandbox );
            this.$testwebhook.toggle(isSandbox);
            this.$username.toggle( !isSandbox );
            this.$password.toggle( !isSandbox );
            this.$webhook.toggle(!isSandbox);
        },

        toggleMethodFields: function () {
            const method           = this.$method.val();
            const isHostedCheckout = method === 'hosted-checkout';

            this.$threedsecure.toggle( !isHostedCheckout );
            this.$hcInteraction.toggle( isHostedCheckout );
            this.$hcType.hide();
            this.$savedCards.toggle( !isHostedCheckout );

            // Surcharge rows
            this.$surchargeInfo.toggle( !isHostedCheckout );
            this.$surchargeInfo.next().toggle( !isHostedCheckout );
            this.$surchargeInfo.next().next().toggle( !isHostedCheckout );
            this.$surChargeTab.toggle( !isHostedCheckout );
            this.$stylePreviewRow.toggle( !isHostedCheckout );
            this.$stylePreviewDes.toggle( !isHostedCheckout );

            this.updateLocaleOptions();
        },
        toggleMerchantInfo: function () {
            const method      = this.$method.val();
            const interaction = this.$Interaction.val();
            const show        = method === 'hosted-checkout' && interaction === 'redirect';

            this.$merchantInfo.toggle( show );
            this.$merchantInfo.next().toggle( show );
            this.$merchantInfo.next().next().toggle( show ); 
            this.$merchantInfoTab.toggle( show );
        },

        insertAmountLabels: function () {
            const labelStyle = {
                width: '35px', height: '31px', lineHeight: '30px',
                backgroundColor: '#eaeaea', textAlign: 'center',
                position: 'absolute', left: '1px', top: '1px',
                borderRadius: '3px 0 0 3px'
            };

            const wrapWithLabel = ( input, id ) => {
                const $label = $( `<span id="${id}"></span>` ).css( labelStyle );
                input.before( $label ).parent().css( 'position', 'relative' );
                input.css( 'padding-left', '45px' );
            };

            wrapWithLabel( this.$hfAmount, 'handling_fee_amount_label' );
            wrapWithLabel( this.$surchargeAmount, 'surcharge_fee_amount_label' );

            this.updateFeeLabel( 'handling' );
            this.updateFeeLabel( 'surcharge' );
        },

        updateFeeLabel: function ( type ) {
            const isFixed = ( type === 'handling'
                ? this.$hfType.val()
                : this.$surchargeType.val() ) === 'fixed';
            const labelId = type === 'handling'
                ? '#handling_fee_amount_label'
                : '#surcharge_fee_amount_label';
            $( labelId ).html( isFixed ? wcSettings.currency.symbol : '%' );
        },

        restrictInputToNumber: function (e) {
            const allowed =
                [8, 9, 13, 27, 46].includes( e.which ) || // backspace, tab, enter, esc, dot
                ( e.which >= 35 && e.which <= 39 ) ||     // arrows, home, end
                ( e.ctrlKey || e.metaKey );               // cmd/ctrl + a/c/v/x

            const isNumber = e.which >= 48 && e.which <= 57;
            const isDot = e.which === 46 && !$( this ).val().includes( '.' );

            if ( !allowed && !isNumber && !isDot ) {
                e.preventDefault();
            }
        },

        validateDecimalInput: function () {
            const valid = /^\d*\.?\d*$/.test( this.value );
            if ( !valid ) {
                this.value = this.value.slice( 0, -1 );
            }
        },

        uploadLogo: function (e) {
            e.preventDefault();

            if ( this.customUploader ) {
                this.customUploader.open();
                return;
            }

            this.customUploader = wp.media({
                title: 'Upload logo',
                button: { text: 'Choose logo' },
                multiple: false,
                library: { type: ['image/jpeg', 'image/png', 'image/svg+xml'] },
            });

            this.customUploader.on('select', () => {
                const attachment = this.customUploader.state().get( 'selection' ).first().toJSON();
                const $input     = this.$logoField;
                $input.val(attachment.url);

                const $saveBtn = $input.closest( '#mainform' ).find( '.woocommerce-save-button' );
                if ( $saveBtn.prop( 'disabled' ) ) {
                    $saveBtn.removeAttr( 'disabled' );
                }
            });

            this.customUploader.open();
        },

        toggleFieldGroup: function (checkboxSelector, rowCount) {
            const $checkbox = $(checkboxSelector);
            const $row = $checkbox.closest('tr');
            const $nextRows = $row.nextAll('tr').slice(0, rowCount);
            $nextRows.toggle($checkbox.is(':checked'));
        },

        initializeCheckboxStatusLabels: function () {
            const $checkboxes = $('input[type="checkbox"][id^="woocommerce_mastercard_gateway_"]');
            $checkboxes.each((_, checkbox) => {
                const $checkbox = $(checkbox);
                const selector = '#' + $checkbox.attr('id');
                this.updateCheckboxStatusLabel(selector);
                $checkbox.on('change', () => {
                    this.updateCheckboxStatusLabel(selector);
                });
            });
        },

        updateCheckboxStatusLabel: function (selector) {
            const $checkbox = $(selector);
            const $label = $checkbox.closest('label');
        
            if ($label.length) {
                const isChecked = $checkbox.is(':checked');
                $label.contents().filter(function () {
                    return this.nodeType === 3; 
                }).remove();
                $label.prepend($checkbox);
                $label.append(isChecked ? ' Enabled' : ' Disabled');
            }
        },

        validateBeforeSubmit: function (e) {
            const $ = jQuery;  
            const getField = id => $(`#${id}`);
            const fields = {
                title: getField('woocommerce_mastercard_gateway_title'),
                customGatewayUrl: getField('woocommerce_mastercard_gateway_custom_gateway_url'),
                sandboxToggle: getField('woocommerce_mastercard_gateway_sandbox'),
                sandboxUsername: getField('woocommerce_mastercard_gateway_sandbox_username'),
                sandboxPassword: getField('woocommerce_mastercard_gateway_sandbox_password'),
                productionUsername: getField('woocommerce_mastercard_gateway_username'),
                productionPassword: getField('woocommerce_mastercard_gateway_password'),
                hfEnabled: getField('woocommerce_mastercard_gateway_hf_enabled'),
                hfText: getField('woocommerce_mastercard_gateway_handling_text'),
                hfAmount: getField('woocommerce_mastercard_gateway_handling_fee_amount'),
                mifEnabled: getField('woocommerce_mastercard_gateway_mif_enabled'),
                mifText: getField('woocommerce_mastercard_gateway_merchant_name'),
                surchargeEnabled: getField('woocommerce_mastercard_gateway_surcharge_enabled'),
                surchargeText: getField('woocommerce_mastercard_gateway_surcharge_text'),
                surchargeAmount: getField('woocommerce_mastercard_gateway_surcharge_amount'),
                merchantEmail: getField('woocommerce_mastercard_gateway_merchant_email'),
            };
            
            // Clear all existing error messages and styles
            const clearErrors = () => {
                $('.wc-mastercard-error-notice').remove();
                Object.values(fields).forEach($field => {
                    this.clearFieldError($field);
                    $field.css('border-color', '');
                });
            };
        
            // Show error message and style field
            const markError = ($field, message) => {
                this.showFieldError($field, message);
                $field.css('border-color', 'red');
            };
        
            // Validate if field is non-empty (trimmed)
            const requireNonEmpty = ($field, message) => {
                if (!$field.val().trim()) {
                    markError($field, message);
                    return false;
                }
                return true;
            };

            const isValidInternationalEmail = (email) => {
                if (!email) return false;
                const parts = email.split('@');
                if (parts.length !== 2) return false;

                const [local, domain] = parts;
                const localPattern = /^[^\s@]+$/u; 
                if (!localPattern.test(local)) return false;
                const domainPattern = /^[^\s@]+\.[^\s@]+$/u;
                return domainPattern.test(domain);
            };

            const requireValidEmail = ($field, message) => {
                const value = $field.val().trim();
                if (value && !isValidInternationalEmail(value)) {
                    markError($field, message);
                    return false;
                }
                return true;
            };


            clearErrors();
        
            let isValid = true;
        
            if (!requireNonEmpty(fields.title, 'Please enter the Title for this payment method.')) isValid = false;
            if (!requireNonEmpty(fields.customGatewayUrl, 'Please enter the Gateway URL. ')) isValid = false;
            if (fields.sandboxToggle.is(':checked')) {
                if (!requireNonEmpty(fields.sandboxUsername, 'Test Merchant ID is required.')) isValid = false;
                if (!requireNonEmpty(fields.sandboxPassword, 'Test API Password is required.')) isValid = false;
            } else {
                if (!requireNonEmpty(fields.productionUsername, 'Merchant ID is required.')) isValid = false;
                if (!requireNonEmpty(fields.productionPassword, 'API Password is required.')) isValid = false;
            }
            
            if (fields.hfEnabled.is(':checked') && !requireNonEmpty(fields.hfText, 'Please provide the Handling Fee text.')) isValid = false;
            if (fields.hfEnabled.is(':checked') && !requireNonEmpty(fields.hfAmount, 'Please enter the Handling Fee amount.')) isValid = false;
            if (fields.mifEnabled.is(':checked') && !requireNonEmpty(fields.mifText, 'Please enter the Merchant Name.')) isValid = false;
            if (fields.surchargeEnabled.is(':checked') && !requireNonEmpty(fields.surchargeText, 'Please provide the Surcharge text.')) isValid = false;
            if (fields.surchargeEnabled.is(':checked') && !requireNonEmpty(fields.surchargeAmount, 'Please enter the Surcharge Fee amount.')) isValid = false;
            if (!requireValidEmail(fields.merchantEmail, 'Please enter a valid email address.')) isValid = false;
           
            // If invalid, prevent submission, show admin notice, and scroll to notice
            if (!isValid) {
                e.preventDefault();
                $('html, body').animate({
                    scrollTop: 0 
                }, 500);
            }
        },

        showFieldError: function ($field, message) {
            this.clearFieldError($field);
            const $error = $('<div class="error-message">' + message + '</div>');
            $field.after($error);
            $field.css('border', '1px solid red');
        },
        
        clearFieldError: function ($field) {
            $field.next('.error-message').remove();
            $field.css('border', '');
        },

        updateLocaleOptions: function () {
            const method       = this.$method.val();
            const langs        = method === 'hosted-checkout' ? this.hcLangs : this.hsLangs;
            const selectedLang = this.$localeSelect.val();
        
            if ( langs ) {
                this.$localeSelect.empty();
        
                $.each( langs, function ( value, label ) {
                    const $option = $('<option>', {
                        value: value,
                        text:  label
                    });
        
                    if ( value === selectedLang ) {
                        $option.prop( 'selected', true );
                    }
        
                    this.$localeSelect.append( $option );
                }.bind( this ));
            }
        },
        
    };

    wc_mastercard_admin.init();
});
