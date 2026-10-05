<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Gateway response exception for Mastercard API errors.
 *
 * @package Fingent\Mastercard\Logger
 */

namespace Fingent\Mastercard\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Exception thrown when the Mastercard gateway returns an error response.
 */
class GatewayResponseException extends \Exception {
}
