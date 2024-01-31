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
 * @version  GIT: @1.4.3@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

/**
 * WooCommerce template for Google Pay
 *
 * @var Mastercard_Gateway $gateway
 * @var WC_Abstract_Order $order
 * @var WC_Payment_Gateway_CC $cc_form
 */
?>
<div id="mgps-google-pay-container"></div>
<script type="text/javascript">
/**
* Define the version of the Google Pay API referenced when creating your configuration
*
* @see {@link https://developers.google.com/pay/api/web/reference/request-objects#PaymentDataRequest|apiVersion in PaymentDataRequest}
*/
const baseRequest = {
	apiVersion: <?php echo esc_attr( $gateway->get_api_version() ); ?>,
	apiVersionMinor: 0
};

/**
 * Card networks supported by your site and your gateway
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#CardParameters|CardParameters}
 * @todo confirm card networks supported by your site and gateway
 */
const allowedCardNetworks = <?php echo esc_attr( $gateway->get_card_auth_methods() ); ?>;

/**
 * Card authentication methods supported by your site and your gateway
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#CardParameters|CardParameters}
 * @todo confirm your processor supports Android device tokens for your
 * supported card networks
 */
const allowedCardAuthMethods = [<?php echo esc_attr( $gateway->get_card_networks() ); ?>];

/**
 * Identify your gateway and your site's gateway merchant identifier
 *
 * The Google Pay API response will return an encrypted payment method capable
 * of being charged by a supported gateway after payer authorization
 *
 * @todo check with your gateway on the parameters to pass
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#gateway|PaymentMethodTokenizationSpecification}
 */
const tokenizationSpecification = {
	type: 'PAYMENT_GATEWAY',
	parameters: {
		'gateway': '<?php echo esc_attr( $gateway->get_gateway_id() ); ?>',
		'gatewayMerchantId': '<?php echo esc_attr( $gateway->get_merchant_id() ); ?>'
	}
};

/**
 * Describe your site's support for the CARD payment method and its required
 * fields
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#CardParameters|CardParameters}
 */
const baseCardPaymentMethod = {
	type: 'CARD',
	parameters: {
		allowedAuthMethods: allowedCardAuthMethods,
		allowedCardNetworks: allowedCardNetworks
	}
};

/**
 * Describe your site's support for the CARD payment method including optional
 * fields
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#CardParameters|CardParameters}
 */
const cardPaymentMethod = Object.assign(
	{},
	baseCardPaymentMethod,
	{
		tokenizationSpecification: tokenizationSpecification
	}
);

/**
 * An initialized google.payments.api.PaymentsClient object or null if not yet set
 *
 * @see {@link getGooglePaymentsClient}
 */
let paymentsClient = null;

/**
 * Configure your site's support for payment methods supported by the Google Pay
 * API.
 *
 * Each member of allowedPaymentMethods should contain only the required fields,
 * allowing reuse of this base request when determining a viewer's ability
 * to pay and later requesting a supported payment method
 *
 * @returns {object} Google Pay API version, payment methods supported by the site
 */
function getGoogleIsReadyToPayRequest() {
  	return Object.assign(
      	{},
      	baseRequest,
      	{
        	allowedPaymentMethods: [baseCardPaymentMethod]
      	}
  	);
}

/**
 * Configure support for the Google Pay API
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#PaymentDataRequest|PaymentDataRequest}
 * @returns {object} PaymentDataRequest fields
 */
function getGooglePaymentDataRequest() {
	const paymentDataRequest = Object.assign({}, baseRequest);
	paymentDataRequest.allowedPaymentMethods = [cardPaymentMethod];
	paymentDataRequest.transactionInfo = getGoogleTransactionInfo();
	paymentDataRequest.merchantInfo = {
		merchantName: 'Example Merchant' 
	};

	return paymentDataRequest;
}

/**
 * Return an active PaymentsClient or initialize
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/client#PaymentsClient|PaymentsClient constructor}
 * @returns {google.payments.api.PaymentsClient} Google Pay API client
 */
function getGooglePaymentsClient() {
	if ( paymentsClient === null ) {
		paymentsClient = new google.payments.api.PaymentsClient({ environment: '<?php echo esc_attr( $gateway->get_environment() ); ?>' });
	}
	return paymentsClient;
}

/**
 * Initialize Google PaymentsClient after Google-hosted JavaScript has loaded
 *
 * Display a Google Pay payment button after confirmation of the viewer's
 * ability to pay.
 */
function onGooglePayLoaded() {
  	const paymentsClient = getGooglePaymentsClient();
  	paymentsClient.isReadyToPay(getGoogleIsReadyToPayRequest())
  	.then(function(response) {
        if (response.result) {
          	addGooglePayButton();
        }
  	})
  	.catch(function(err) {
        console.error(err);
  	});
}

/**
 * Add a Google Pay purchase button alongside an existing checkout button
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#ButtonOptions|Button options}
 * @see {@link https://developers.google.com/pay/api/web/guides/brand-guidelines|Google Pay brand guidelines}
 */
function addGooglePayButton() {
	const paymentsClient = getGooglePaymentsClient();
	const button = paymentsClient.createButton({onClick: onGooglePaymentButtonClicked});
  	document.getElementById('mgps-google-pay-container').appendChild(button);
}

/**
 * Provide Google Pay API with a payment amount, currency, and amount status
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#TransactionInfo|TransactionInfo}
 * @returns {object} transaction info, suitable for use as transactionInfo property of PaymentDataRequest
 */
function getGoogleTransactionInfo() {
	return {
		countryCode: 'US',
		currencyCode: 'USD',
		totalPriceStatus: 'FINAL',
		totalPrice: '1.00'
	};
}

/**
 * Prefetch payment data to improve performance
 *
 * @see {@link https://developers.google.com/pay/api/web/reference/client#prefetchPaymentData|prefetchPaymentData()}
 */
function prefetchGooglePaymentData() {
	const paymentDataRequest = getGooglePaymentDataRequest();
	paymentDataRequest.transactionInfo = {
		totalPriceStatus: 'NOT_CURRENTLY_KNOWN',
		currencyCode: 'USD'
	};
	const paymentsClient = getGooglePaymentsClient();
	paymentsClient.prefetchPaymentData(paymentDataRequest);
}

/**
 * Show Google Pay payment sheet when Google Pay payment button is clicked
 */
function onGooglePaymentButtonClicked() {
  	const paymentDataRequest = getGooglePaymentDataRequest();
  	paymentDataRequest.transactionInfo = getGoogleTransactionInfo();
  	const paymentsClient = getGooglePaymentsClient();
  	paymentsClient.loadPaymentData(paymentDataRequest)
      	.then(function(paymentData) {
        	processPayment(paymentData);
      	})
      	.catch(function(err) {
        	console.error(err);
      	});
}

/**
 * Process payment data returned by the Google Pay API
 *
 * @param {object} paymentData response from Google Pay API after user approves payment
 * @see {@link https://developers.google.com/pay/api/web/reference/response-objects#PaymentData|PaymentData object reference}
 */
function processPayment(paymentData) {
	paymentToken = paymentData.paymentMethodData.tokenizationData.token;
}
</script>