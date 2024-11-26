<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package  Mastercard
 * @version  GIT: @1.4.7@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Mastercard_Gateway Notification.
 */
class Mastercard_Gateway_Notification {
	/**
	 * API endpoint variable.
	 *
	 * @var string
	 */
	protected $id = null;
	/**
	 * API endpoint variable.
	 *
	 * @var string
	 */
	protected $api_url = null;

	/**
	 * API endpoint
	 *
	 * @var string|null
	 */
	protected $base_url = null;

	/**
	 * Repo ownername.
	 *
	 * @var string|null
	 */
	protected $owner = null;

	/**
	 * Repo name.
	 *
	 * @var string|null
	 */
	protected $repo = null;

	/**
	 * MPGS module required WP version.
	 *
	 * @var string|null
	 */
	protected $wp_requires = null;

	/**
	 * MPGS module is tested upto.
	 *
	 * @var string|null
	 */
	protected $wp_tested = null;

	/**
	 * Min PHP version required.
	 *
	 * @var string|null
	 */
	protected $min_php_required = null;

	/**
	 * Current MGPS plugin version.
	 *
	 * @var string|null
	 */
	protected $current_version = null;

	/**
	 * Documentation URL.
	 *
	 * @var string|null
	 */
	protected $doc_url = null;

	/**
	 * Plugin changelog.
	 *
	 * @var string|null
	 */
	protected $changelog = null;

	/**
	 * Plugin slug variable.
	 *
	 * @var string|null
	 */
	protected $plugin_slug;

	/**
	 * GatewayService constructor.
	 *
	 * @throws \Exception Throws an exception with the response.
	 */
	public function __construct() {

		$this->id               = 'mpgs_gateway';
		$this->wp_requires      = '6.0';
		$this->wp_tested        = '6.4.3';
		$this->min_php_required = '7.4';
		$this->plugin_slug      = 'woocommerce-mastercard';
		$this->base_url         = 'github.com';
		$this->owner            = 'fingent-corp';
		$this->doc_url          = 'https://uat-wiki.fingent.net/';
		$this->current_version  = get_option( '_mgps_target_module_version' );
		$this->changelog        = get_option( '_mgps_target_current_version_changelog' );
		$this->repo             = 'gateway-woocommerce-mastercard-module';
		$this->api_url          = 'https://api.' . $this->base_url . '/repos/' . $this->owner . '/' . $this->repo . '/releases/latest';

		register_deactivation_hook( __FILE__, array( $this, 'clear_mgps_target_module_new_version_check' ) );
		add_action( 'init', array( $this, 'create_cron_mpgs_version_check' ) );
		add_action( 'mgps_target_module_new_version_check', array( $this, 'mgps_new_version_check' ) );

		// Remove default plugin information action.
        remove_all_actions( 'install_plugins_pre_plugin-information' );	
		add_action( 'install_plugins_pre_plugin-information', array( $this, 'display_changelog' ), 9 );
	}

	/**
	 * Scheduler Callback function to check if new version is available or not.
	 *
	 * @return mixed|ResponseInterface
	 * @throws Exception It throws an exception if a request is not processed.
	 */
	public function mgps_new_version_check() {
		try {
			$response = wp_remote_get(
				esc_url_raw( $this->api_url ),
				array(
					'headers' => array(
						'Accept'               => 'application/vnd.github+json',
						'X-GitHub-Api-Version' => '2022-11-28',
					),
				)
			);
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$plugin_file   = 'woocommerce-mastercard/woocommerce-mastercard.php';
				$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( json_last_error() === JSON_ERROR_NONE || $response_body && is_array( $response_body ) ) {
					$latest_version = $response_body['tag_name'];
					$this->save_changelog( $response_body['body'] );

					if ( version_compare( $latest_version, $this->current_version, '>' ) ) {
						$mgps_plugin_info               = new stdClass();
						$mgps_plugin_info->id           = $this->owner . '/woocommerce-mastercard';
						$mgps_plugin_info->slug         = 'woocommerce-mastercard';
						$mgps_plugin_info->plugin       = $plugin_file;
						$mgps_plugin_info->new_version  = $latest_version;
						$mgps_plugin_info->url          = 'https://api.' . $this->base_url . '/repos/' . $this->owner . '/' . $this->repo;
						$mgps_plugin_info->package      = "";
						$mgps_plugin_info->icons        = array();
						$mgps_plugin_info->banners      = array();
						$mgps_plugin_info->banners_rtl  = array();
						$mgps_plugin_info->requires     = $this->wp_requires;
						$mgps_plugin_info->tested       = $this->wp_tested;
						$mgps_plugin_info->requires_php = $this->min_php_required;

						$current = get_site_transient( 'update_plugins' );

						if ( ! is_wp_error( $current ) && is_object( $current ) && $current->response ) {
							$current->last_checked             = time();
							$current->response[ $plugin_file ] = $mgps_plugin_info;

							set_site_transient( 'update_plugins', $current );
						} else {
							$updates               = new stdClass();
							$updates->last_checked = time();
							$updates->response     = array();
							$updates->translations = array();
							$updates->no_update    = array();

							$updates->response[ $plugin_file ] = $mgps_plugin_info;

							set_site_transient( 'update_plugins', $updates );
						}
					}
				}
			} else {
				return null;
			}
		} catch ( Exception $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * Add a custom WP cron for checking the plugin update.
	 *
	 * @return void
	 */
	public function create_cron_mpgs_version_check() {
		$this->update_database();
		if ( ! wp_next_scheduled( 'mgps_target_module_new_version_check' ) ) {
			wp_schedule_event( time(), 'every_minute', 'mgps_target_module_new_version_check' );
		}
	}

	/**
	 * Clear the scheduler when the MGPS plugin is deactivated.
	 *
	 * @return void
	 */
	public function clear_mgps_target_module_new_version_check() {
		wp_clear_scheduled_hook( 'mgps_target_module_new_version_check' );
	}

	/**
	 * This function is used to update the database if required.
	 *
	 * @return void
	 */
	public function update_database() {
		update_option( '_mgps_target_module_version', MPGS_TARGET_MODULE_VERSION );
	}

	/**
	 * Callback function for saving the features of the latest release.
	 *
	 * @param string $changelog Features of the latest release.
	 * @return void
	 */
	public function save_changelog( $changelog ) {
		$changelog = preg_replace( '/\*\*(.*?)\*\*/', '<b>$1</b>', $changelog );
		preg_match_all( '/[^\n]+/', $changelog, $matches );

		$changelog_with_tags = '';

		if ( $matches && is_array( $matches ) ) {
			foreach ( $matches[0] as $p_tag ) {
				if ( '' !== $p_tag ) {
					$changelog_with_tags .= '<p>' . $p_tag . '</p>';
				}
			}
		}
		update_option( '_mgps_target_current_version_changelog', $changelog_with_tags );
	}

	/**
	 * This function is used to render the changlog html.
	 *
	 * @return string $changelog_with_tags Features of the latest release.
	 */
	public function render_changelog_html() {
		if ( $this->changelog ) {
			$changelog_with_tags  = '<style>p { color: #585857; font-family: "Lucida Console", Sans-serif; font-size: 14px; margin-top: 0; } p b { color: #2F2F2E; }</style>';
			$changelog_with_tags .= '<div style="margin:30px 20px; padding: 20px; background:#F6F6F6; border-radius: 7px; border:1px solid #f1f1f1;">';
			$changelog_with_tags .= $this->changelog;
			$changelog_with_tags .= '</div>';
		}

		return $changelog_with_tags;
	}

	/**
	 * Displays current version details on plugins page and updates page.
	 *
	 * @return void
	 */
	public function display_changelog() {
		$plugin = isset( $_REQUEST['plugin'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) ) : null; // phpcs:disable WordPress.Security.NonceVerification.Recommended

		if ( $plugin !== $this->plugin_slug ) {
			return;
		}

		printf( $this->render_changelog_html() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}

return new Mastercard_Gateway_Notification();
