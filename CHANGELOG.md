# Changelog
All notable changes to this project will be documented in this file.

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