<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Plugin-specific exception for admin and gateway validation errors.
 *
 * @package Fingent\Mastercard\Exception
 */

namespace Fingent\Mastercard\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin-specific exception for admin and gateway validation errors.
 */
class PluginException extends \Exception {}
