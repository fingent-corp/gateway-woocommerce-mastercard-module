<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Plugin bootstrap loader.
 *
 * @package Fingent\Mastercard\Core
 */

namespace Fingent\Mastercard\Core;

use Fingent\Mastercard\Controller\GatewayController;
use Fingent\Mastercard\Helper\Constants;
use Fingent\Mastercard\Helper\PluginTelemetry;
use Fingent\Mastercard\Helper\PluginUpdateNotifier;
use Fingent\Mastercard\Helper\SettingsMigrator;

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
		Constants::get_instance();
		PluginUpdateNotifier::register();

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( self::class, 'woocommerce_missing_notice' ) );
			return;
		}

		SettingsMigrator::maybe_migrate();

		$controller = new GatewayController();
		$controller->register();

		PluginTelemetry::register();
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

	/**
	 * Displays an admin notice when WooCommerce is not installed or active.
	 *
	 * @return void
	 */
	public static function woocommerce_missing_notice() {
    	echo '<div class="error"><p>'
        . esc_html__( 'Mastercard Gateway requires WooCommerce to be installed and active.', 'mastercard-gateway' )
        . '</p></div>';
	}
}
