<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Plugin bootstrap loader.
 *
 * @package Fingent\Mastercard\Core
 */

namespace Fingent\Mastercard\Core;

use Fingent\Mastercard\Controller\GatewayController;
use Fingent\Mastercard\Helper\PluginTelemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Boots plugin controllers and activation hooks.
 */
class Loader {
	/**
	 * Mastercard constructor.
	 */
	public function __construct() {}

	/**
	 * Initialize plugin controllers.
	 *
	 * @return void
	 */
	public static function init() {
		$controller = new GatewayController();
		$controller->register();

		add_action( 'init', array( PluginTelemetry::class, 'maybe_send_pending_activation' ), 20 );
		add_action( 'init', array( PluginTelemetry::class, 'maybe_send_version_update' ), 30 );
		add_action( 'upgrader_process_complete', array( PluginTelemetry::class, 'on_plugin_updated' ), 10, 2 );
	}

	/**
	 * Plugin activation hook
	 *
	 * @return void
	 */
	public static function activation_hook() {
		$environment_warning = self::get_env_warning();
		if ( is_string( $environment_warning ) && '' !== $environment_warning ) {
			deactivate_plugins( plugin_basename( MG_ENTERPRISE_MAIN_FILE ) );
			wp_die( esc_html( $environment_warning ) );
		}

		PluginTelemetry::on_activation();
	}

	/**
	 * Get get_env_warning.
	 *
	 * @return string Empty string when the environment is valid.
	 */
	public static function get_env_warning() {
		// @todo: Add some php version and php library checks here.
		return '';
	}
}
