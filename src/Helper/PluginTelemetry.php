<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Anonymous plugin usage telemetry (activation / configuration).
 *
 * Events are queued locally and sent on admin shutdown (after the response is
 * flushed when fastcgi_finish_request is available) or via WP-Cron. Storefront,
 * REST, AJAX, and WP-CLI never wait on the wiki. Wiki event names remain
 * install and configured.
 *
 * @package Fingent\Mastercard\Helper
 */

namespace Fingent\Mastercard\Helper;

use Fingent\Mastercard\Controller\GatewayController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends install and configured telemetry to the Mastercard wiki.
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
	const OPTION_PENDING_CONFIGURED   = 'mpgs_pending_configured_ping';
	const OPTION_NEXT_ATTEMPT         = 'mpgs_telemetry_next_attempt';

	const CRON_HOOK       = 'mpgs_telemetry_daily';
	const RETRY_HOOK      = 'mpgs_telemetry_retry';
	const DISPATCH_ACTION = 'mpgs_telemetry';

	const TRANSIENT_LOCK  = 'mpgs_telemetry_dispatch_lock';
	const TRANSIENT_TOKEN = 'mpgs_telemetry_dispatch_token';

	const BACKOFF_SECONDS = 900;
	const LOCK_SECONDS    = 30;
	const TOKEN_SECONDS   = 120;

	/**
	 * True while shutdown_send() is performing the wiki call.
	 *
	 * @var bool
	 */
	private static $allow_shutdown_request = false;

	/**
	 * Register admin, optional loopback, and cron handlers.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( self::class, 'maybe_dispatch' ), 20 );
		add_action( 'admin_post_' . self::DISPATCH_ACTION, array( self::class, 'handle_dispatch' ) );
		add_action( 'admin_post_nopriv_' . self::DISPATCH_ACTION, array( self::class, 'handle_dispatch' ) );
		add_action( self::CRON_HOOK, array( self::class, 'cron_send' ) );
		add_action( self::RETRY_HOOK, array( self::class, 'cron_send' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'on_plugin_updated' ), 10, 2 );
		self::ensure_cron();
	}

	/**
	 * Queue an activation ping. WooCommerce may not be loaded during activation.
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
	 * From wp-admin, start one background send when an event is waiting.
	 *
	 * @return void
	 */
	public static function maybe_dispatch() {
		if ( self::is_dispatch_request() || ! self::is_admin_screen() ) {
			return;
		}

		self::ensure_activation_hash();

		if ( ! self::is_enabled() || ! self::is_due() ) {
			return;
		}

		self::spawn_dispatch();
	}

	/**
	 * Compatibility alias used by admin save flows.
	 *
	 * @return void
	 */
	public static function maybe_send_version_update() {
		self::maybe_dispatch();
	}

	/**
	 * Compatibility alias for queued activation sends.
	 *
	 * @return void
	 */
	public static function maybe_send_pending_activation() {
		self::maybe_dispatch();
	}

	/**
	 * Send the oldest pending event from the loopback admin request.
	 *
	 * @return never
	 */
	public static function handle_dispatch() {
		if ( ! self::token_is_valid() ) {
			wp_die(
				esc_html__( 'Invalid telemetry request.', 'mastercard-gateway' ),
				'',
				array( 'response' => 403 )
			);
		}

		try {
			self::send_next_pending();
		} finally {
			delete_transient( self::TRANSIENT_LOCK );
		}

		wp_die();
	}

	/**
	 * Send one pending event from WP-Cron.
	 *
	 * @return void
	 */
	public static function cron_send() {
		if ( get_transient( self::TRANSIENT_LOCK ) ) {
			return;
		}

		if ( ! self::is_enabled() || ! self::is_due() ) {
			return;
		}

		set_transient( self::TRANSIENT_LOCK, '1', self::LOCK_SECONDS );
		try {
			self::send_next_pending();
		} finally {
			delete_transient( self::TRANSIENT_LOCK );
		}
	}

	/**
	 * Queue a configured event when live credentials are saved.
	 *
	 * @param array<string, mixed> $settings Gateway settings after save.
	 * @return void
	 */
	public static function maybe_send_gateway_configured( array $settings ) {
		$username = isset( $settings['username'] ) ? trim( (string) $settings['username'] ) : '';
		$password = isset( $settings['password'] ) ? trim( (string) $settings['password'] ) : '';

		if ( '' === $username || '' === $password ) {
			return;
		}

		$configured_hash = self::get_configured_hash();

		if ( '' === $configured_hash || $configured_hash === (string) get_option( self::OPTION_LAST_CONFIGURED_HASH, '' ) ) {
			return;
		}

		update_option( self::OPTION_PENDING_CONFIGURED, '1', false );
		delete_option( self::OPTION_NEXT_ATTEMPT );
		self::spawn_dispatch();
	}

	/**
	 * Queue a send after this plugin is updated through the WP upgrader.
	 *
	 * @param \WP_Upgrader         $upgrader Upgrader instance.
	 * @param array<string, mixed> $extra    Hook extra data.
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

		delete_option( self::OPTION_NEXT_ATTEMPT );
		self::spawn_dispatch();
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
	 * Schedule the daily safety-net event once.
	 *
	 * @return void
	 */
	public static function ensure_cron() {
		if ( wp_installing() || wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + 86400, 'daily', self::CRON_HOOK );
	}

	/**
	 * Send the oldest pending event and keep it queued when the wiki rejects it.
	 *
	 * @return void
	 */
	private static function send_next_pending() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$event = self::next_event();
		if ( '' === $event ) {
			return;
		}

		$extra = array();
		if ( 'configured' === $event ) {
			$hash = self::get_configured_hash();
			if ( '' !== $hash ) {
				$extra['configured_hash'] = $hash;
			}
		}

		if ( self::send( $event, $extra ) ) {
			self::mark_sent( $event );
			delete_option( self::OPTION_NEXT_ATTEMPT );
			if ( '' !== self::next_event() ) {
				self::schedule_retry( 60 );
			}
			return;
		}

		update_option( self::OPTION_NEXT_ATTEMPT, time() + self::BACKOFF_SECONDS, false );
		self::schedule_retry( self::BACKOFF_SECONDS );
	}

	/**
	 * Oldest event that still needs a successful wiki response.
	 *
	 * @return string install|configured or empty.
	 */
	private static function next_event() {
		$install_pending = get_option( self::OPTION_PENDING_ACTIVATION ) && ! get_option( self::OPTION_ACTIVATION_SENT );

		if ( $install_pending ) {
			return '' === (string) get_option( self::OPTION_ACTIVATION_HASH, '' ) ? '' : 'install';
		}

		$hash = self::get_configured_hash();
		if ( '' === $hash ) {
			return '';
		}

		$stored_version = (string) get_option( self::OPTION_CURRENT_VERSION, '' );
		if ( defined( 'MG_ENTERPRISE_MODULE_VERSION' )
			&& MG_ENTERPRISE_MODULE_VERSION !== $stored_version ) {
			return 'configured';
		}

		if ( get_option( self::OPTION_PENDING_CONFIGURED ) ) {
			return 'configured';
		}

		return '';
	}

	/**
	 * Record a wiki acceptance.
	 *
	 * @param string $event Event that was accepted.
	 * @return void
	 */
	private static function mark_sent( $event ) {
		if ( 'install' === $event ) {
			update_option( self::OPTION_ACTIVATION_SENT, '1', false );
			update_option( self::OPTION_CURRENT_VERSION, MG_ENTERPRISE_MODULE_VERSION, false );
			delete_option( self::OPTION_PENDING_ACTIVATION );
			return;
		}

		if ( 'configured' === $event ) {
			if ( defined( 'MG_ENTERPRISE_MODULE_VERSION' )
				&& MG_ENTERPRISE_MODULE_VERSION !== (string) get_option( self::OPTION_CURRENT_VERSION, '' ) ) {
				update_option( self::OPTION_CURRENT_VERSION, MG_ENTERPRISE_MODULE_VERSION, false );
			}

			$hash = self::get_configured_hash();
			if ( '' !== $hash ) {
				update_option( self::OPTION_LAST_CONFIGURED_HASH, $hash, false );
			}
			delete_option( self::OPTION_PENDING_CONFIGURED );
		}
	}

	/**
	 * Queue a send for the end of this admin request, with WP-Cron as backup.
	 *
	 * Admin-post loopback is unreliable behind proxies/Docker (site host often
	 * does not resolve inside the app). Shutdown runs in-process after the
	 * response is flushed when FastCGI is available, so the UI stays responsive.
	 *
	 * @return void
	 */
	private static function spawn_dispatch() {
		if ( ! self::is_enabled() || ! self::is_due() || get_transient( self::TRANSIENT_LOCK ) ) {
			return;
		}

		set_transient( self::TRANSIENT_LOCK, '1', self::LOCK_SECONDS );
		self::schedule_retry( 60 );

		if ( ! has_action( 'shutdown', array( self::class, 'shutdown_send' ) ) ) {
			add_action( 'shutdown', array( self::class, 'shutdown_send' ), 0 );
		}
	}

	/**
	 * Send pending wiki events after the admin response has been sent.
	 *
	 * @return void
	 */
	public static function shutdown_send() {
		if ( ! get_transient( self::TRANSIENT_LOCK ) ) {
			return;
		}

		if ( function_exists( 'session_write_close' ) ) {
			session_write_close();
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		self::$allow_shutdown_request = true;

		try {
			if ( ! self::is_enabled() || ! self::is_due() ) {
				return;
			}

			self::send_next_pending();

			// One event per call — flush a second pending event in the same request when present.
			if ( self::is_due() ) {
				self::send_next_pending();
			}
		} finally {
			self::$allow_shutdown_request = false;
			delete_transient( self::TRANSIENT_LOCK );
			delete_transient( self::TRANSIENT_TOKEN );
		}
	}

	/**
	 * Whether the single-use loopback token matches.
	 *
	 * @return bool
	 */
	private static function token_is_valid() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- single-use loopback token validated below.
		$raw_token = isset( $_REQUEST['token'] ) ? wp_unslash( $_REQUEST['token'] ) : '';
		$provided  = is_string( $raw_token ) ? sanitize_text_field( $raw_token ) : '';
		$stored    = get_transient( self::TRANSIENT_TOKEN );
		delete_transient( self::TRANSIENT_TOKEN );

		return is_string( $stored ) && '' !== $stored && '' !== $provided && hash_equals( $stored, $provided );
	}

	/**
	 * Whether a pending event may be attempted now.
	 *
	 * @return bool
	 */
	private static function is_due() {
		if ( '' === self::next_event() ) {
			return false;
		}

		$next = (int) get_option( self::OPTION_NEXT_ATTEMPT, 0 );
		return $next <= time();
	}

	/**
	 * Schedule a one-off retry that does not depend on another wp-admin page view.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	private static function schedule_retry( $delay ) {
		wp_clear_scheduled_hook( self::RETRY_HOOK );
		wp_schedule_single_event( time() + (int) $delay, self::RETRY_HOOK );
	}

	/**
	 * True on an ordinary wp-admin screen loaded by a shop manager.
	 *
	 * @return bool
	 */
	private static function is_admin_screen() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * True when this request is the telemetry loopback.
	 *
	 * @return bool
	 */
	private static function is_dispatch_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- identifies telemetry loopback only.
		if ( ! isset( $_REQUEST['action'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- identifies telemetry loopback only.
		$raw_action = wp_unslash( $_REQUEST['action'] );
		$action     = is_string( $raw_action ) ? sanitize_text_field( $raw_action ) : '';

		return self::DISPATCH_ACTION === $action;
	}

	/**
	 * Store the auth key once an admin session can produce it.
	 *
	 * @return void
	 */
	private static function ensure_activation_hash() {
		if ( get_option( self::OPTION_ACTIVATION_HASH ) ) {
			return;
		}

		$cookie_hash = self::get_activation_cookie_hash();
		if ( '' !== $cookie_hash ) {
			update_option( self::OPTION_ACTIVATION_HASH, $cookie_hash, false );
		}
	}

	/**
	 * Send one telemetry event to the status endpoint.
	 *
	 * @param string               $event_type    install or configured.
	 * @param array<string, mixed> $extra_payload Additional telemetry fields.
	 * @return bool True when the remote endpoint accepted the event.
	 */
	private static function send( $event_type, array $extra_payload = array() ) {
		if ( ! self::may_perform_request() || ! self::is_enabled() ) {
			return false;
		}

		if ( ! defined( 'MG_ENTERPRISE_CAPTURE_URL' ) || ! defined( 'MG_ENTERPRISE_MODULE_VERSION' ) ) {
			return false;
		}

		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Countries' ) || ! WC()->countries ) {
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

		unset( $extra_payload['configured_hash'] );

		try {
			$service = GatewayController::get_instance()->init_service();
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}

		$response = $service->send_capture_request(
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
			$extra_payload
		);

		return ! empty( $response['status'] ) && 'success' === $response['status'];
	}

	/**
	 * Wiki calls are allowed on shutdown dispatch, loopback, and WP-Cron.
	 *
	 * @return bool
	 */
	private static function may_perform_request() {
		return self::$allow_shutdown_request || wp_doing_cron() || self::is_dispatch_request();
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
	 * Install hash used as Bearer for install and configured.
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
	 * Map internal telemetry event names to wiki event values.
	 *
	 * @param string $event_type Internal event name.
	 * @return string Wiki event value, or empty when unsupported.
	 */
	private static function map_wiki_event( $event_type ) {
		switch ( $event_type ) {
			case 'activation':
			case 'install':
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
	 * @param string               $event_type    Internal event name.
	 * @param array<string, mixed> $extra_payload Request payload fields.
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
