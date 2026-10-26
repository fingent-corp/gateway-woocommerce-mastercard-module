<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * One-time Gateway Compatibility migration from mpgs_gateway to mastercard_gateway.
 *
 * @package Fingent\Mastercard\Helper
 */

namespace Fingent\Mastercard\Helper;

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * One-time migration from the 1.5.1 gateway ID (mpgs_gateway) to mastercard_gateway.
 */
class SettingsMigrator {
	/**
	 * Option that records a completed gateway-ID migration.
	 *
	 * @var string
	 */
	const MIGRATED_OPTION = 'mg_enterprise_migrated_gateway_id';

	/**
	 * Option that records copied 1.5.1 saved cards.
	 *
	 * @var string
	 */
	const TOKENS_DUPLICATED_OPTION = 'mg_enterprise_legacy_tokens_duplicated';

	/**
	 * Copy 1.5.1 settings and remap related data if the legacy gateway ID is still in use.
	 *
	 * Must run before MastercardGateway is constructed so init_settings() reads the new option.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( ! defined( 'MG_ENTERPRISE_ID' ) ) {
			return;
		}

		if ( 'yes' !== get_option( self::MIGRATED_OPTION ) ) {
			self::copy_gateway_settings();
			self::copy_style_defaults();
			self::remap_gateway_order();
			self::remap_orders();

			update_option( self::MIGRATED_OPTION, 'yes' );
		}

		if ( 'yes' !== get_option( self::TOKENS_DUPLICATED_OPTION ) ) {
			self::duplicate_payment_tokens();
			update_option( self::TOKENS_DUPLICATED_OPTION, 'yes' );
		}
	}

	/**
	 * Copy woocommerce_mpgs_gateway_settings when the new option is unconfigured.
	 *
	 * @return void
	 */
	private static function copy_gateway_settings() {
		$old_settings = get_option( self::option_key( Constants::LEGACY_GATEWAY_ID ), false );
		if ( ! is_array( $old_settings ) || empty( $old_settings ) ) {
			return;
		}

		$new_settings = get_option( self::option_key( MG_ENTERPRISE_ID ), false );
		if ( ! self::is_unconfigured( $new_settings ) ) {
			return;
		}

		$old_settings = self::map_gateway_url( $old_settings );
		update_option( self::option_key( MG_ENTERPRISE_ID ), $old_settings );
	}

	/**
	 * Copy Hosted Session style defaults if present and the new option is empty.
	 *
	 * @return void
	 */
	private static function copy_style_defaults() {
		$old_key = 'woocommerce_' . Constants::LEGACY_GATEWAY_ID . '_style_defaults';
		$new_key = 'woocommerce_' . MG_ENTERPRISE_ID . '_style_defaults';

		$old_styles = get_option( $old_key, false );
		if ( empty( $old_styles ) ) {
			return;
		}

		$new_styles = get_option( $new_key, false );
		if ( ! empty( $new_styles ) ) {
			return;
		}

		update_option( $new_key, $old_styles );
	}

	/**
	 * Rename mpgs_gateway to mastercard_gateway in the WooCommerce gateway sort order.
	 *
	 * @return void
	 */
	private static function remap_gateway_order() {
		$gateway_order = get_option( 'woocommerce_gateway_order', array() );
		if ( ! is_array( $gateway_order ) || ! isset( $gateway_order[ Constants::LEGACY_GATEWAY_ID ] ) ) {
			return;
		}

		if ( ! isset( $gateway_order[ MG_ENTERPRISE_ID ] ) ) {
			$gateway_order[ MG_ENTERPRISE_ID ] = $gateway_order[ Constants::LEGACY_GATEWAY_ID ];
		}
		unset( $gateway_order[ Constants::LEGACY_GATEWAY_ID ] );

		update_option( 'woocommerce_gateway_order', $gateway_order );
	}

	/**
	 * Copy each 1.5.1 saved card into a mastercard_gateway token.
	 *
	 * The original mpgs_gateway row and its meta stay in place. Checkout on 1.5.4
	 * loads the copy, so the saved-card radio and CVV field share one token ID.
	 *
	 * @return void
	 */
	private static function duplicate_payment_tokens() {
		global $wpdb;

		$tokens_table = $wpdb->prefix . 'woocommerce_payment_tokens';
		$meta_table   = $wpdb->prefix . 'woocommerce_payment_tokenmeta';
		if ( ! self::table_exists( $tokens_table ) ) {
			return;
		}

		$tokens_table_sql = esc_sql( $tokens_table );
		$meta_table_sql   = esc_sql( $meta_table );
		if ( ! is_string( $tokens_table_sql ) || ! is_string( $meta_table_sql ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.SlowDBQuery -- One-time Gateway Compatibility migration.
		$legacy_tokens = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT token_id, token, user_id, type, is_default FROM `' . $tokens_table_sql . '` WHERE gateway_id = %s',
				Constants::LEGACY_GATEWAY_ID
			)
		);

		if ( is_array( $legacy_tokens ) ) {
			foreach ( $legacy_tokens as $legacy ) {
				$new_token_id = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT token_id FROM `' . $tokens_table_sql . '` WHERE gateway_id = %s AND user_id = %d AND token = %s LIMIT 1',
						MG_ENTERPRISE_ID,
						(int) $legacy->user_id,
						(string) $legacy->token
					)
				);

				if ( ! $new_token_id ) {
					$inserted = $wpdb->insert(
						$tokens_table,
						array(
							'gateway_id' => MG_ENTERPRISE_ID,
							'token'      => (string) $legacy->token,
							'user_id'    => (int) $legacy->user_id,
							'type'       => (string) $legacy->type,
							'is_default' => (int) $legacy->is_default,
						),
						array( '%s', '%s', '%d', '%s', '%d' )
					);

					if ( ! $inserted ) {
						continue;
					}

					$new_token_id = (int) $wpdb->insert_id;

					if ( (int) $legacy->is_default ) {
						$wpdb->update(
							$tokens_table,
							array( 'is_default' => 0 ),
							array( 'token_id' => (int) $legacy->token_id ),
							array( '%d' ),
							array( '%d' )
						);
					}
				}

				if ( ! self::table_exists( $meta_table ) ) {
					continue;
				}

				$meta_rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT meta_key, meta_value FROM `' . $meta_table_sql . '` WHERE payment_token_id = %d',
						(int) $legacy->token_id
					)
				);

				if ( ! is_array( $meta_rows ) ) {
					continue;
				}

				foreach ( $meta_rows as $meta ) {
					$meta_key = (string) $meta->meta_key;
					$exists   = $wpdb->get_var(
						$wpdb->prepare(
							'SELECT meta_id FROM `' . $meta_table_sql . '` WHERE payment_token_id = %d AND meta_key = %s LIMIT 1',
							(int) $new_token_id,
							$meta_key
						)
					);

					if ( $exists ) {
						continue;
					}

					$wpdb->insert(
						$meta_table,
						array(
							'payment_token_id' => (int) $new_token_id,
							'meta_key'         => $meta_key,
							'meta_value'       => $meta->meta_value,
						),
						array( '%d', '%s', '%s' )
					);
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.SlowDBQuery
	}

	/**
	 * Remap existing order payment methods so refunds and voids still match the gateway.
	 *
	 * @return void
	 */
	private static function remap_orders() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.SlowDBQuery -- One-time Gateway Compatibility order remap.
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => MG_ENTERPRISE_ID ),
			array(
				'meta_key'   => '_payment_method',
				'meta_value' => Constants::LEGACY_GATEWAY_ID,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);

		$orders_table = $wpdb->prefix . 'wc_orders';
		$hpos_enabled = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		$orders_exist = self::table_exists( $orders_table );

		if ( $orders_exist && $hpos_enabled ) {
			$wpdb->update(
				$orders_table,
				array( 'payment_method' => MG_ENTERPRISE_ID ),
				array( 'payment_method' => Constants::LEGACY_GATEWAY_ID ),
				array( '%s' ),
				array( '%s' )
			);

			$orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
			if ( self::table_exists( $orders_meta_table ) ) {
				$wpdb->update(
					$orders_meta_table,
					array( 'meta_value' => MG_ENTERPRISE_ID ),
					array(
						'meta_key'   => '_payment_method',
						'meta_value' => Constants::LEGACY_GATEWAY_ID,
					),
					array( '%s' ),
					array( '%s', '%s' )
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.SlowDBQuery
	}

	/**
	 * WooCommerce settings option key for a gateway ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	private static function option_key( $gateway_id ) {
		return 'woocommerce_' . $gateway_id . '_settings';
	}

	/**
	 * Whether the new gateway settings lack API credentials (safe to overwrite).
	 *
	 * @param mixed $settings Settings array or false.
	 * @return bool
	 */
	private static function is_unconfigured( $settings ) {
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return true;
		}

		$credentials = array_filter(
			array(
				isset( $settings['username'] ) ? $settings['username'] : '',
				isset( $settings['password'] ) ? $settings['password'] : '',
				isset( $settings['sandbox_username'] ) ? $settings['sandbox_username'] : '',
				isset( $settings['sandbox_password'] ) ? $settings['sandbox_password'] : '',
			)
		);

		return empty( $credentials );
	}

	/**
	 * Copy a 1.5.1 region hostname into custom_gateway_url when that field is empty.
	 *
	 * @param array<string, mixed> $settings Gateway settings.
	 * @return array<string, mixed>
	 */
	private static function map_gateway_url( array $settings ) {
		$custom      = isset( $settings['custom_gateway_url'] ) ? trim( (string) $settings['custom_gateway_url'] ) : '';
		$gateway_url = isset( $settings['gateway_url'] ) ? (string) $settings['gateway_url'] : '';

		if ( '' === $custom && '' !== $gateway_url && API_CUSTOM !== $gateway_url ) {
			$settings['custom_gateway_url'] = $gateway_url;
		}

		return $settings;
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check before one-time migration queries.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}
}
