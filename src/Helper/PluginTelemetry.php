<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Anonymous plugin usage telemetry (activation / version updates).
 *
 * @package Fingent\Mastercard\Helper
 */

namespace Fingent\Mastercard\Helper;

use Fingent\Mastercard\Controller\GatewayController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends anonymous activation and configuration telemetry to Mastercard.
 *
 * Install hash is HMAC of session token + shop name + shop URL. The same hash
 * is used as Bearer for both install and configured events.
 */
class PluginTelemetry {
	const OPTION_PENDING_ACTIVATION   = 'mpgs_pending_activation_ping';
	const OPTION_CURRENT_VERSION      = 'mpgs_current_version';
	const OPTION_ACTIVATION_HASH      = 'mpgs_activation_cookie_hash';
	const OPTION_ACTIVATION_SENT      = 'mpgs_activation_telemetry_sent';
	const OPTION_LAST_CONFIGURED_HASH = 'mpgs_last_configured_hash';

	/**
	 * Queue an activation ping for the next request (WC may not be loaded on activation).
	 *
	 * @return void
	 */
	public static function on_activation() {
		if ( ! get_option( self::OPTION_ACTIVATION_HASH ) ) {
			$cookie_hash = self::get_activation_cookie_hash();

			if ( '' !== $cookie_hash ) {
				update_option( self::OPTION_ACTIVATION_HASH, $cookie_hash, false );
			}
		}

		if ( get_option( self::OPTION_ACTIVATION_SENT ) ) {
			return;
		}

		update_option( self::OPTION_PENDING_ACTIVATION, '1', false );
	}

	/**
	 * Send a queued activation ping once WooCommerce is available.
	 *
	 * @return void
	 */
	public static function maybe_send_pending_activation() {
		if ( ! get_option( self::OPTION_PENDING_ACTIVATION ) ) {
			return;
		}

		if ( ! get_option( self::OPTION_ACTIVATION_HASH ) ) {
			$cookie_hash = self::get_activation_cookie_hash();

			if ( '' !== $cookie_hash ) {
				update_option( self::OPTION_ACTIVATION_HASH, $cookie_hash, false );
			}
		}

		if ( ! get_option( self::OPTION_ACTIVATION_HASH ) ) {
			return;
		}

		if ( self::send( 'activation' ) ) {
			update_option( self::OPTION_ACTIVATION_SENT, '1', false );
			update_option( self::OPTION_CURRENT_VERSION, MG_ENTERPRISE_MODULE_VERSION, false );
			delete_option( self::OPTION_PENDING_ACTIVATION );
		}
	}

	/**
	 * Report a plugin version change once per installed version.
	 *
	 * @return void
	 */
	public static function maybe_send_version_update() {
		if ( get_option( self::OPTION_PENDING_ACTIVATION ) && ! get_option( self::OPTION_ACTIVATION_SENT ) ) {
			return;
		}

		if ( defined( 'MG_ENTERPRISE_MODULE_VERSION' )
			&& MG_ENTERPRISE_MODULE_VERSION === get_option( self::OPTION_CURRENT_VERSION, '' ) ) {
			return;
		}

		$configured_hash = self::get_configured_hash();

		if ( '' !== $configured_hash && self::send( 'configured', array( 'configured_hash' => $configured_hash ) ) ) {
			update_option( self::OPTION_CURRENT_VERSION, MG_ENTERPRISE_MODULE_VERSION, false );
			update_option( self::OPTION_LAST_CONFIGURED_HASH, $configured_hash, false );
		}
	}

	/**
	 * Send a merchant-configured event once for each derived shop identity hash.
	 *
	 * @param array<string, mixed> $settings Gateway settings after save.
	 * @return void
	 */
	public static function maybe_send_gateway_configured( array $settings ) {
		$sandbox = isset( $settings['sandbox'] ) ? (string) $settings['sandbox'] : 'no';

		if ( 'yes' === $sandbox ) {
			$username = isset( $settings['sandbox_username'] ) ? trim( (string) $settings['sandbox_username'] ) : '';
			$password = isset( $settings['sandbox_password'] ) ? trim( (string) $settings['sandbox_password'] ) : '';
		} else {
			$username = isset( $settings['username'] ) ? trim( (string) $settings['username'] ) : '';
			$password = isset( $settings['password'] ) ? trim( (string) $settings['password'] ) : '';
		}

		if ( '' === $username || '' === $password ) {
			return;
		}

		$configured_hash = self::get_configured_hash();

		if ( '' === $configured_hash || $configured_hash === get_option( self::OPTION_LAST_CONFIGURED_HASH, '' ) ) {
			return;
		}

		if ( self::send( 'configured', array( 'configured_hash' => $configured_hash ) ) ) {
			update_option( self::OPTION_LAST_CONFIGURED_HASH, $configured_hash, false );
		}
	}

	/**
	 * After a plugin update, report the new version when this plugin was updated.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array<string, mixed> $extra Hook extra data.
	 *
	 * @return void
	 */
	public static function on_plugin_updated( $upgrader, $extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( empty( $extra['action'] ) || 'update' !== $extra['action'] ) {
			return;
		}

		if ( empty( $extra['type'] ) || 'plugin' !== $extra['type'] ) {
			return;
		}

		$updated = array();

		if ( ! empty( $extra['plugins'] ) && is_array( $extra['plugins'] ) ) {
			$updated = $extra['plugins'];
		} elseif ( ! empty( $extra['plugin'] ) ) {
			$updated = array( $extra['plugin'] );
		}

		if ( ! in_array( plugin_basename( MG_ENTERPRISE_MAIN_FILE ), $updated, true ) ) {
			return;
		}

		self::maybe_send_version_update();
	}

	/**
	 * Whether telemetry is enabled for this site.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Disable anonymous plugin telemetry when set to false.
		 *
		 * @param bool $enabled Whether telemetry may be sent.
		 */
		return (bool) apply_filters( 'mastercard_enterprise_telemetry_enabled', true );
	}

	/**
	 * Send a telemetry event to the status endpoint.
	 *
	 * @param string               $event_type    Event name (activation|version_update|configured).
	 * @param array<string, mixed> $extra_payload Additional telemetry fields.
	 *
	 * @return bool True when the remote endpoint accepted the event.
	 */
	public static function send( $event_type, array $extra_payload = array() ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( ! defined( 'MG_ENTERPRISE_CAPTURE_URL' ) ) {
			return false;
		}

		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Countries' ) ) {
			return false;
		}

		$default_country = get_option( 'woocommerce_default_country' );
		$country_code    = $default_country ? explode( ':', (string) $default_country )[0] : '';
		$countries       = WC()->countries->get_countries();
		$country_name    = $countries[ $country_code ] ?? '';

		$wiki_event = self::map_wiki_event( $event_type );
		if ( '' === $wiki_event ) {
			return false;
		}

		$extra_payload['event'] = $wiki_event;

		if ( 'install' === $wiki_event ) {
			$activation_hash = get_option( self::OPTION_ACTIVATION_HASH, '' );
			if ( is_string( $activation_hash ) && '' !== $activation_hash ) {
				$extra_payload['activation_hash'] = $activation_hash;
			}
		}

		$bearer_token = self::resolve_bearer_token( $event_type, $extra_payload );
		if ( '' === $bearer_token ) {
			return false;
		}

		$request_body = $extra_payload;
		unset( $request_body['configured_hash'] );

		$response = GatewayController::get_instance()->init_service()->send_capture_request(
			'gateway-woocommerce-mastercard-module',
			'enterprise',
			MG_ENTERPRISE_MODULE_VERSION,
			MG_ENTERPRISE_MODULE_VERSION,
			$country_code,
			$country_name,
			get_bloginfo( 'name' ),
			get_home_url(),
			$bearer_token,
			MG_ENTERPRISE_CAPTURE_URL,
			$request_body
		);

		return ! empty( $response['status'] ) && 'success' === $response['status'];
	}

	/**
	 * Build the install hash from session token, shop name, and shop URL.
	 *
	 * @return string
	 */
	private static function get_activation_cookie_hash() {
		$cookie = self::get_activation_cookie_value();

		if ( '' === $cookie ) {
			return '';
		}

		$payload = $cookie . get_bloginfo( 'name' ) . get_home_url();
		$hmac    = hash_hmac( 'sha256', $payload, wp_salt( 'mastercard_telemetry_activation' ) );

		return is_string( $hmac ) ? $hmac : '';
	}

	/**
	 * Read the current WordPress session token for plugin activation.
	 *
	 * @return string
	 */
	private static function get_activation_cookie_value() {
		if ( function_exists( 'wp_get_session_token' ) ) {
			$token = wp_get_session_token();

			if ( is_string( $token ) && '' !== $token ) {
				return $token;
			}
		}

		return '';
	}

	/**
	 * Install hash used as Bearer for install and configured (same value).
	 *
	 * Also used locally to dedupe successful configured pings.
	 *
	 * @return string
	 */
	private static function get_configured_hash() {
		$activation_hash = get_option( self::OPTION_ACTIVATION_HASH, '' );

		if ( ! is_string( $activation_hash ) || '' === $activation_hash ) {
			$activation_hash = self::get_activation_cookie_hash();
			if ( '' !== $activation_hash ) {
				update_option( self::OPTION_ACTIVATION_HASH, $activation_hash, false );
			}
		}

		return is_string( $activation_hash ) ? $activation_hash : '';
	}

	/**
	 * Map internal telemetry event names to dev-wiki event values.
	 *
	 * @param string $event_type Internal event name.
	 * @return string Wiki event value, or empty when unsupported.
	 */
	private static function map_wiki_event( $event_type ) {
		switch ( $event_type ) {
			case 'activation':
				return 'install';
			case 'configured':
				return 'configured';
			default:
				return '';
		}
	}

	/**
	 * Resolve the Bearer token for a wiki telemetry event.
	 *
	 * Both install and configured use the same activation hash.
	 *
	 * @param string               $event_type    Internal event name.
	 * @param array<string, mixed> $extra_payload Payload fields for the request.
	 * @return string Bearer token, or empty when unavailable.
	 */
	private static function resolve_bearer_token( $event_type, array $extra_payload ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$wiki_event = self::map_wiki_event( $event_type );

		if ( 'install' !== $wiki_event && 'configured' !== $wiki_event ) {
			return '';
		}

		$token = get_option( self::OPTION_ACTIVATION_HASH, '' );
		if ( ! is_string( $token ) || '' === $token ) {
			$token = self::get_configured_hash();
		}

		if ( ! is_string( $token ) || '' === $token ) {
			return '';
		}

		/**
		 * Filter the telemetry Bearer token before it is sent to the wiki API.
		 *
		 * @param string               $token         Hash used as Bearer token.
		 * @param string               $event_type    Internal event name.
		 * @param array<string, mixed> $extra_payload Request payload fields.
		 */
		return (string) apply_filters( 'mastercard_enterprise_telemetry_bearer', $token, $event_type, $extra_payload );
	}
}
