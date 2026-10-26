<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * GitHub release check for the Plugins screen.
 *
 * @package Fingent\Mastercard\Helper
 */

namespace Fingent\Mastercard\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows a Plugins-screen notice when a newer GitHub release exists.
 *
 * The check runs every 3 hours. The notice is display-only: WordPress is not
 * given a package URL, so the release is not installed automatically.
 */
class PluginUpdateNotifier {
	const CRON_HOOK             = 'mgps_target_module_new_version_check';
	const CRON_SCHEDULE         = 'mg_three_hours';
	const OPTION_LATEST_VERSION = 'mg_github_latest_version';
	const OPTION_CHANGELOG      = 'mg_github_latest_changelog';
	const CHECK_LOCK            = 'mg_github_check_lock';
	const RELEASES_API          = 'https://api.github.com/repos/fingent-corp/gateway-woocommerce-mastercard-module/releases/latest';
	const RELEASES_URL          = 'https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/releases/latest';

	/**
	 * Register the update check and the Plugins-screen update row.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( self::class, 'add_three_hour_schedule' ) );
		add_action( 'init', array( self::class, 'schedule_update_check' ) );
		add_action( self::CRON_HOOK, array( self::class, 'check_for_update' ) );
		add_filter( 'site_transient_update_plugins', array( self::class, 'inject_update' ) );
		add_filter( 'plugins_api', array( self::class, 'plugin_information' ), 10, 3 );
	}

	/**
	 * Add a 3-hour recurrence. WordPress does not provide one.
	 *
	 * @param array<string, array<string, int|string>> $schedules Existing schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public static function add_three_hour_schedule( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 3 * 3600,
			'display'  => __( 'Every 3 hours', 'mastercard-gateway' ),
		);

		return $schedules;
	}

	/**
	 * Clear the update check when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Schedule the GitHub check every 3 hours.
	 *
	 * @return void
	 */
	public static function schedule_update_check() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next && self::CRON_SCHEDULE !== wp_get_schedule( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$next = false;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + ( 3 * 3600 ), self::CRON_SCHEDULE, self::CRON_HOOK );
			return;
		}

		// wp-cron.php is a loopback request and often never runs in local Docker.
		// When the event is due, run it during wp-admin so the Plugins screen can update.
		if ( $next > time() || ! is_admin() || get_transient( self::CHECK_LOCK ) ) {
			return;
		}

		set_transient( self::CHECK_LOCK, 1, 15 * 60 );
		if ( ! self::check_for_update() ) {
			return;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( time() + ( 3 * 3600 ), self::CRON_SCHEDULE, self::CRON_HOOK );
	}

	/**
	 * Fetch the latest GitHub release and store its version and notes.
	 *
	 * @return bool True when a release version was saved.
	 */
	public static function check_for_update() {
		if ( ! defined( 'MG_ENTERPRISE_MODULE_VERSION' ) ) {
			return false;
		}

		$response = wp_remote_get(
			self::RELEASES_API,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'Mastercard-Gateway/' . MG_ENTERPRISE_MODULE_VERSION . '; ' . home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return false;
		}

		$latest = self::normalize_version( isset( $body['tag_name'] ) ? $body['tag_name'] : '' );
		if ( '' === $latest ) {
			return false;
		}

		update_option( self::OPTION_LATEST_VERSION, $latest, false );
		update_option( self::OPTION_CHANGELOG, self::format_changelog( isset( $body['body'] ) ? $body['body'] : '' ), false );

		return true;
	}

	/**
	 * Add or remove this plugin's update row from the Plugins screen data.
	 *
	 * @param mixed $transient Update-plugins transient value.
	 * @return mixed
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) || ! defined( 'MG_ENTERPRISE_PLUGIN_BASENAME' ) || ! defined( 'MG_ENTERPRISE_MODULE_VERSION' ) ) {
			return $transient;
		}

		/** @var \stdClass $transient */
		$plugin_file = MG_ENTERPRISE_PLUGIN_BASENAME;
		$latest      = self::normalize_version( get_option( self::OPTION_LATEST_VERSION, '' ) );

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( '' === $latest || ! version_compare( $latest, MG_ENTERPRISE_MODULE_VERSION, '>' ) ) {
			unset( $transient->response[ $plugin_file ] );
			return $transient;
		}

		$info               = new \stdClass();
		$info->id           = 'fingent-corp/woocommerce-mastercard';
		$info->slug         = self::plugin_slug();
		$info->plugin       = $plugin_file;
		$info->new_version  = $latest;
		$info->url          = self::RELEASES_URL;
		$info->package      = '';
		$info->icons        = array();
		$info->banners      = array();
		$info->banners_rtl  = array();
		$info->requires     = '6.0';
		$info->tested       = '7.0';
		$info->requires_php = '7.4';

		$transient->response[ $plugin_file ] = $info;

		return $transient;
	}

	/**
	 * Supply release notes for this plugin's details modal.
	 *
	 * @param mixed  $result Existing API result.
	 * @param string $action Requested plugins_api action.
	 * @param mixed  $args   Request arguments.
	 * @return mixed
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! defined( 'MG_ENTERPRISE_MODULE_VERSION' ) ) {
			return $result;
		}

		$slug = '';
		if ( is_object( $args ) && isset( $args->slug ) ) {
			$slug = (string) $args->slug;
		} elseif ( is_array( $args ) && isset( $args['slug'] ) ) {
			$slug = (string) $args['slug'];
		}

		if ( $slug !== self::plugin_slug() ) {
			return $result;
		}

		$latest    = self::normalize_version( get_option( self::OPTION_LATEST_VERSION, '' ) );
		$changelog = (string) get_option( self::OPTION_CHANGELOG, '' );
		if ( '' === $changelog ) {
			$changelog = '<p>' . esc_html__( 'No release notes are available for this version.', 'mastercard-gateway' ) . '</p>';
		}

		$info                = new \stdClass();
		$info->name          = 'Mastercard Gateway';
		$info->slug          = $slug;
		$info->version       = '' !== $latest ? $latest : MG_ENTERPRISE_MODULE_VERSION;
		$info->author        = '<a href="https://www.fingent.com/">Fingent Global Solutions Pvt. Ltd.</a>';
		$info->homepage      = self::RELEASES_URL;
		$info->requires      = '6.0';
		$info->tested        = '7.0';
		$info->requires_php  = '7.4';
		$info->download_link = '';
		$info->sections      = array(
			'changelog' => $changelog,
		);

		return $info;
	}

	/**
	 * Plugin directory name used as the details-modal slug.
	 *
	 * @return string
	 */
	private static function plugin_slug() {
		$basename = defined( 'MG_ENTERPRISE_PLUGIN_BASENAME' ) ? MG_ENTERPRISE_PLUGIN_BASENAME : '';
		$slug     = '' !== $basename ? dirname( $basename ) : '';

		if ( '' === $slug || '.' === $slug ) {
			return 'woocommerce-mastercard';
		}

		return $slug;
	}

	/**
	 * Reduce a GitHub tag to a numeric version string.
	 *
	 * @param mixed $version Raw tag or stored version.
	 * @return string Empty when the value is not a version.
	 */
	private static function normalize_version( $version ) {
		$version = strtolower( trim( (string) $version ) );
		if ( 0 === strpos( $version, 'v' ) ) {
			$stripped = substr( $version, 1 );
			$version  = false === $stripped ? '' : $stripped;
		}

		if ( ! preg_match( '/^\d+(\.\d+){0,3}$/', $version ) ) {
			return '';
		}

		return $version;
	}

	/**
	 * Turn a GitHub release body into a short HTML changelog.
	 *
	 * @param mixed $body Release body from the GitHub API.
	 * @return string
	 */
	private static function format_changelog( $body ) {
		$text = preg_replace( '/\*\*(.*?)\*\*/', '<strong>$1</strong>', (string) $body );
		if ( ! is_string( $text ) ) {
			return '';
		}

		$html  = '';
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$html .= '<p>' . $line . '</p>';
			}
		}

		return wp_kses(
			$html,
			array(
				'p'      => array(),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'br'     => array(),
			)
		);
	}
}
