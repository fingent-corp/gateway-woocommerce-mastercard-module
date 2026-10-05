<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Template rendering helper.
 *
 * @package Fingent\Mastercard\Core
 */

namespace Fingent\Mastercard\Core;

use Fingent\Mastercard\Exception\PluginException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Renders PHP templates with extracted view data.
 */
class View {
	/**
	 * Renders a PHP template file with provided data.
	 *
	 * @param string               $template The template file name (without .php extension).
	 * @param array<string, mixed> $data An associative array of data to extract into the template scope.
	 *
	 * @return void
	 *
	 * @throws PluginException When the template file is missing.
	 */
	public static function render( string $template, array $data = array() ) {
		try {
			// Extract the associative array to variables for use in the template.
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables are provided via $data.
			extract( $data );

			// Build the full path to the template file.
			$template_path = MG_ENTERPRISE_DIR_PATH . 'templates/' . $template . '.php';

			// Check if the template file exists before including.
			if ( ! file_exists( $template_path ) ) {
				throw new PluginException( "Template not found: {$template_path}" );
			}

			include_once $template_path;
		} catch ( PluginException $e ) {
			echo '<div class="error">An error occurred while rendering the template.</div>';
		} catch ( \Exception $e ) {
			echo '<div class="error">An error occurred while rendering the template.</div>';
		}
	}
}
