# Mastercard Gateway Module for WooCommerce 

<p align="center" style="margin-top: 25px;">
<a href="https://www.fingent.com/"><img alt="Fingent logo" height="50px" src="https://www.fingent.com/wp-content/uploads/Fingent-Logo-01.png"/></a>&nbsp;&nbsp;<img alt="MC logo" height="50px" src="https://mpgs.fingent.wiki/wp-content/uploads/2025/04/mastercard-logo.png"/>
</p>

## Overview Section

[Mastercard Inc.](https://www.mastercard.co.in/en-in.html) is the [world’s second-largest payment processing corporation](https://www.investopedia.com/terms/m/mastercard-card.asp), providing a variety of payment solutions and services. We connect people, businesses, and organizations across over 210 countries and territories, creating opportunities for more people in more places, now and for the future. This module lets you add multiple payment options to your checkout, enabling secure credit, debit and account payments on your WooCommerce-powered website.

Payments made through this module are processed using the trusted Mastercard Gateway. MasterCard Gateway securely handles card/account details, following strict legal and regulatory requirements, ensuring a safe experience for both businesses and customers.

We carefully monitor every transaction to catch and stop fraud, making sure your payments are safe and secure. Your sensitive payment details, like card/account information, are handled and stored on servers with the highest level of security certification, that is Payment Card Industry (PCI) Level 1.

With this gateway, you don’t have to handle or store customer card/account details yourself. This makes meeting PCI compliance easier for your business. You can focus on running your store while the gateway securely processes payments for you.


## Compatibility

- WooCommerce 8.5 or greater
- PHP version 7.4 or greater
- cURL

## Mastercard Payment Module Features

The Mastercard Payment Module is packed with tools to make payment processing easier and safer for your business. Here's a quick look at its main features:


**1. Payment Methods -** Defines the types of payment options supported, which are:

   - **Card Payments**<br/>
Easily and securely accept both credit and debit card payments. This feature works with major card brands, making it simple and reliable for your customers to pay.

   - **Google Pay (Supported in Hosted Checkout Only)**<br/>
With Google Pay, customers can quickly and easily pay on the hosted checkout page. To enable this option, ensure your Merchant Identification Number (MID) is configured for Google Pay. This makes payments smooth and hassle-free, allowing customers to complete transactions with just a few taps.

**2. Checkout and Payment Integration -** This feature focuses on the method of collecting payment details from customers:

   - **Hosted Checkout**<br/>
This feature lets your customers enter their payment details on a ready-made secure checkout page provided directly by Mastercard. It keeps sensitive information safe while giving your customers a smooth and hassle-free payment experience.

   - **Hosted Session**<br/>
This feature lets you customize the layout and design of your payment page to match your brand, while still meeting strict PCI security standards. It makes managing security easier without compromising the user experience.

**3. Fraud Prevention and Security -** This feature enhances security and protects against fraud:

   - **Address Verification Service (AVS)**<br/>
AVS helps prevent fraud by checking the billing address provided during a payment to make sure it matches the one on file with the cardholder's bank. This helps confirm that the person making the payment is the actual cardholder. To use AVS, it must be activated on your MID.

   - **EMV 3-D Secure v1**<br/>
EMV 3D Secure (3DS1) adds an extra step to verify the cardholder during online transactions. This helps prevent unauthorized payments by asking the cardholder to confirm their identity. Before using this feature, make sure it's enabled on your MID.

   - **EMV 3-D Secure v2**<br/>
EMV 3DS2 in the Mastercard Gateway, is the latest version of the security protocol, designed to enhance security in online purchases while providing frictionless checkouts to payers who are considered low risk by the Access Control Server (ACS). The ACS determines the risk using information provided by the merchant, browser fingerprinting, and previous interactions with the payer. Please note that this needs to be activated on your MID before you can use it.

   - **Tokenization**<br/>
Tokenization improves security by replacing sensitive card or account details (like your 16-digit Card number or Bank Account Number or Routing Number) with a unique, encrypted token which is created by Mastercard Gateway and sent to the merchant. This token can be used for future transactions, keeping your card information safe and private. To use Tokenization, it must be activated on your MID.

**4. Transaction Management -** These features support the processing and management of transactions:

   - **Handling Fees**<br/>
This feature allows you to add extra fees on the checkout page, with options for applying either a fixed amount or a percentage of the Subtotal amount.

   - **Capture Payments**<br/>
This feature lets you manually process payments for authorized orders directly from your system. It gives you more control over how payments are handled.

   - **Void Transaction**<br/>
The void transaction feature lets you cancel an order before it's invoiced or completed. This option is usually available for 'Authorize' transactions, where the funds are reserved but not yet charged or billed.

   - **Full Refunds**<br/>
You can refund the entire amount of the transaction back to the customer's account. This is helpful when a complete order needs to be cancelled or returned.

   - **Partial Refunds**<br/>
This feature lets you refund only part of an order, giving the customer the specific amount they are entitled to.

## Documentation
The official documentation for this module is available on the [Wiki site](https://mpgs.fingent.wiki/enterprise/woocommerce-mastercard-gateway/overview-and-feature-support).

## Installation of Module
For more information, please refer to the [Wiki documentation](https://mpgs.fingent.wiki/enterprise/woocommerce-mastercard-gateway/installation).

## Support
For additional support, please visit the [Support Portal](https://mpgsfgs.atlassian.net/servicedesk/customer/user/login?destination=portals).
