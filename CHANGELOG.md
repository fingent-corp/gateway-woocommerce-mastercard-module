# Changelog
All notable changes to this project will be documented in this file.
## [1.4.9] - 2025-03-13
### Fixed
- Rectified PSR Log version inconsistencies for PHP 7.4 compatibility.
- Addressed an issue where Handling Fees were incorrectly applied to other payment modules.

## [1.4.8] - 2024-12-18
### Added
- Introduced a webhook feature to notify your system about updates to order transactions. This ensures that orders marked as “Failed” in the platform Admin orders section are updated when the payment is successfully processed in the Merchant portal.

### Enhancement
- Compatibility with WordPress 6.7 and WooCommerce 9.4.

### Changed
- Updated the API version to 100.

## [1.4.7] - 2024-11-26
### Added
- Introduced a new 'Merchant Information' section in the plugin settings for admins to easily update merchant information.
- Eliminated the intermediate page in the 'Redirect to Payment Page' process for Hosted Checkout.

### Enhancement
- Compatibility with WooCommerce 9.3.

### Changed
- Updated the API version to 84.

### Fixed
- Resolved an issue where line items were not correctly transmitted to the payment gateway.

## [1.4.6] - 2024-08-30
### Added
- Implemented the ability to process void transactions.
- Implemented the ability to add mandatory or optional extra fees on the checkout page.

## [1.4.5] - 2024-07-09
### Enhancement
- Compatibility with WordPress 6.6 and WooCommerce 9.1.

### Changed
- Updated the API version to 81.

## [1.4.4] - 2024-04-12
### Feature
- Implemented a notification feature to alert the WordPress administrator whenever a new version is launched on GitHub.
- Enabled Gutenberg block compatibility for the WooCommerce checkout page.
- MGPS plugin compatibility with WooCommerce High-Performance Order Storage (HPOS).

### Enhancement
- Compatibility with WordPress 6.5 and WooCommerce 8.5.

### Changed
- Updated the API version to 78.

## [1.4.3] - 2024-01-31
### Enhancement
- Compatibility with WordPress 6.4 and WooCommerce 8.2.

### Changed 
- Replaced the obsolete php-http/message-factory package with the actively maintained psr/http-factory package.
- Added nyholm/psr7 package.

## [1.4.2] - 2023-12-12
### Fixed
- Rectified the price rounding issue.

## [1.4.1] - 2023-11-05
### Improvements
- Adhering to both PHPCS and WPCS coding standards completely.

### Changed
- Updated the API version to 73.

## [1.4.0] - 2023-05-11
### Improvements
- PHP 8.1 compatibility.
- Compatibility with WordPress 6.3 and WooCommerce 8.0.

### Changed
- Updated the API version to 70.

## [1.3.0] - 2022-06-17
### Changed
- New Hosted Checkout integration is introduced.

### Fixed
- The version of the plugin is displayed incorrectly in the Admin Panel.

## [1.2.1] - 2021-12-01
### Fixed
- Fixed an error "invalid request" for an Order if the Customer clicks the Browser Back button from the Order Success Page.

## [1.2.0] - 2021-10-19
### Changed
- Add support for the "Enforce Unique Order Reference" and "Enforce Unique Merchant Transaction Reference" gateway features.
- Add 3DS2 support

### Fixed
- Issue with required permission_callback param for REST API (compatibility with newer versions of WooCommerce).

## [1.1.0] - 2020-04-7
### Enhancement
- PHP 7.4 compatibility.
- Compatibility with Wordpress 5.6 and WooCommerce 4.0.

## [1.0.0] - 2019-12-17
### Feature
- Card payments
- Hosted Session
- Hosted Checkout
- Full refunds
- Partial refunds
- AVS
- 3DS1
- Tokenisation