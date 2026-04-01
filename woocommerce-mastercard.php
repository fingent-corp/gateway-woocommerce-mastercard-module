<?php
/**
 * Plugin Name: Mastercard Gateway
 * Description: Accept payments on your WooCommerce store using the Mastercard Gateway. Requires PHP 7.4+ & WooCommerce 8.7+
 * Plugin URI: https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 * Author: Fingent Global Solutions Pvt. Ltd.
 * Author URI: https://www.fingent.com/
 * Tags: payment, payment-gateway, mastercard, mastercard-payements, mastercard-gateway, woocommerce-plugin, woocommerce-payment, woocommerce-extension, woocommerce-shop, mastercard, woocommerce-api, woocommerce-blocks
 * Version: 1.5.2.1
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * php version 7.4
 * Text Domain: mastercard-gateway
 * Domain Path: /languages
 *
 * WC requires at least: 8.7
 * WC tested up to: 10.4.3
 *
 * @package  Mastercard
 * @version  GIT: @1.5.2@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

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
 */

// If this file is called directly, abort.
defined( 'ABSPATH' ) || exit;

define( 'MG_ENTERPRISE_MAIN_FILE',                 __FILE__ );
define( 'MG_ENTERPRISE_PLUGIN_BASENAME',           plugin_basename( MG_ENTERPRISE_MAIN_FILE ) );
define( 'MG_ENTERPRISE_DIR_PATH',                  trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'MG_ENTERPRISE_TEXTDOMAIN', 			   'mastercard-gateway' );


//require autoload
if ( file_exists( MG_ENTERPRISE_DIR_PATH . 'vendor/autoload.php' ) ) {
	require MG_ENTERPRISE_DIR_PATH . 'vendor/autoload.php';
}

use Fingent\Mastercard\Core\Loader;

add_action( 'plugins_loaded', array( Loader::class, 'init' ) );
register_activation_hook( __FILE__, 'Fingent\Mastercard\Core\Loader::activation_hook' );
