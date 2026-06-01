<?php
namespace Fingent\Mastercard\Core;

use Fingent\Mastercard\Controller\GatewayController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Loader {
	/**
	 * Mastercard constructor.
	 */
	public function __construct() {}

    public static function init() {
        $controller = new GatewayController();
        $controller->register();
    }

    /**
	 * Plugin activation hook
	 *
	 * @return void
	 */
	public static function activation_hook() {
		$environment_warning = self::get_env_warning();
		if ( $environment_warning ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_attr( $environment_warning ) );
		}
	}

	/**
	 * Get get_env_warning.
	 *
	 * @return bool
	 */
	public static function get_env_warning() {
		// @todo: Add some php version and php library checks here
		return false;
	}
}
