<?php
namespace Fingent\Mastercard\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class View {
    /**
     * Renders a PHP template file with provided data.
     *
     * @param string $template The template file name (without .php extension).
     * @param array  $data     An associative array of data to extract into the template scope.
     *
     * @return void
     */
    public static function render( string $template, array $data = [] ) {
        try {
            // Extract the associative array to variables for use in the template.
            extract( $data );

            // Build the full path to the template file.
            $template_path = MG_ENTERPRISE_DIR_PATH . 'templates/' . $template . '.php';

            // Check if the template file exists before including.
            if ( ! file_exists( $template_path ) ) {
                throw new \Exception( "Template not found: {$template_path}" );
            }

            include $template_path;
        } catch ( \Exception $e ) {
            echo '<div class="error">An error occurred while rendering the template.</div>';
        }
    }
}