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