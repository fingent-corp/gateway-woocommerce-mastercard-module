<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Redacts sensitive values from gateway log context before persistence.
 *
 * @package Fingent\Mastercard\Helper
 */

namespace Fingent\Mastercard\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redact sensitive fields before writing gateway logs.
 */
class LogRedactor {
	/**
	 * Keys to redact anywhere in a nested array.
	 *
	 * @var string[]
	 */
	private static $sensitive_keys = array(
		'password',
		'secret',
		'pan',
		'number',
		'card',
		'cardNumber',
		'securityCode',
		'cvv',
		'cvc',
		'sourceOfFunds',
		'token',
		'session',
		'authorization',
	);

	/**
	 * Recursively redact sensitive keys in log context arrays.
	 *
	 * @param mixed $data Log context.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$redacted = array();

		foreach ( $data as $key => $value ) {
			$key_lower = strtolower( (string) $key );

			if ( self::is_sensitive_key( $key_lower ) ) {
				$redacted[ $key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact( $value );
			} else {
				$redacted[ $key ] = $value;
			}
		}

		return $redacted;
	}

	/**
	 * Whether a lowercase field name matches a sensitive key pattern.
	 *
	 * @param string $key_lower Lowercase key name.
	 * @return bool
	 */
	private static function is_sensitive_key( $key_lower ) {
		foreach ( self::$sensitive_keys as $needle ) {
			if ( false !== strpos( $key_lower, strtolower( $needle ) ) ) {
				return true;
			}
		}

		return false;
	}
}
