<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Payment gateway registration, REST routes, and HTTP client wiring.
 *
 * @package Fingent\Mastercard\Controller
 */

namespace Fingent\Mastercard\Controller;

use Http\Client\Common\Exception\ClientErrorException;
use Http\Client\Common\Exception\ServerErrorException;
use Http\Client\Common\HttpClientRouter;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\ContentLengthPlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception;
use Http\Discovery\HttpClientDiscovery;
use Http\Message\Authentication\BasicAuth;
use Http\Message\Formatter;
use Http\Message\Formatter\SimpleFormatter;
use Http\Message\RequestMatcher\RequestMatcher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Http\Promise\Promise;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Fingent\Mastercard\Logger\ApiErrorPlugin;
use Fingent\Mastercard\Logger\ApiLoggerPlugin;
use Fingent\Mastercard\Logger\GatewayResponseException;
use Fingent\Mastercard\Helper\Constants;
use Fingent\Mastercard\Helper\RestAuthHelper;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Controller\AdminController;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Controller\FrontendController;
use Fingent\Mastercard\Controller\PaymentController;
use Fingent\Mastercard\Controller\GatewayBlockSupportController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the gateway, REST API, blocks support, and gateway HTTP service.
 */
class GatewayController {
	/**
	 * The single instance of the class.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Monolog logger for gateway HTTP traffic.
	 *
	 * @var LoggerInterface|null
	 */
	private $logger;

	/**
	 * GatewayController Instance.
	 *
	 * @return GatewayController instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Gateway HTTP logger instance.
	 *
	 * @return LoggerInterface|null
	 */
	public function get_logger() {
		return $this->logger;
	}

	/**
	 * GatewayController constructor.
	 *
	 * @throws \Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() {
		Constants::get_instance();
		AdminController::get_instance();
		FrontendController::get_instance();
		PaymentController::get_instance();
	}

	/**
	 * Register the payment gateway and declare compatibility with WooCommerce blocks.
	 */
	public function register(): void {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_cart_checkout_blocks_compatibility' ) );
		if ( ! $this->is_order_pay_page() ) {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'mastercard_woocommerce_block_support' ), 99 );
		}

		add_action(
			'rest_api_init',
			function () {
				$order_route_args = array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				);

				register_rest_route(
					'mastercard/v1',
					'/checkoutSession/(?P<id>\d+)',
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => $order_route_args,
					)
				);
				register_rest_route(
					'mastercard/v1',
					'/session/(?P<id>\d+)',
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => $order_route_args,
					)
				);
				register_rest_route(
					'mastercard/v1',
					'/savePayment/(?P<id>\d+)',
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => $order_route_args,
					)
				);
				register_rest_route(
					'mastercard/v1',
					'/webhook',
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'permission_callback' => array( $this, 'webhook_permissions_check' ),
						'callback'            => array( $this, 'rest_route_forward' ),
					)
				);
			}
		);
	}

	/**
	 * Add MastercardGateway to WooCommerce
	 *
	 * @param array<int, string> $methods Gateway class names.
	 *
	 * @return array<int, string>
	 */
	public function add_gateway( $methods ) {
		$methods[] = MastercardGateway::class;

		return $methods;
	}

	/**
	 * Function to declare compatibility with cart_checkout_blocks feature.
	 */
	public function declare_cart_checkout_blocks_compatibility(): void { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MG_ENTERPRISE_MAIN_FILE, true );
			FeaturesUtil::declare_compatibility( 'custom_order_tables', MG_ENTERPRISE_MAIN_FILE, true );
		}
	}

	/**
	 * Function to register the Mastercard payment method type.
	 */
	public function mastercard_woocommerce_block_support(): void {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new GatewayBlockSupportController() );
			}
		);

		\woocommerce_store_api_register_update_callback(
			array(
				'namespace'           => 'mastercard_gateway_handling_fee',
				'callback'            => function () {
					return self::refresh_handling_fees_on_checkout_block();
				},
				'permission_callback' => array( $this, 'store_api_callback_permissions_check' ),
			)
		);
	}

	/**
	 * Permission check for Store API checkout fee refresh callback.
	 *
	 * @return bool
	 */
	public function store_api_callback_permissions_check() {
		return function_exists( 'WC' ) && WC()->session;
	}

	/**
	 * Whether the current request is the order-pay endpoint.
	 *
	 * @return bool
	 */
	public function is_order_pay_page() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return true;
		}

		$raw_key = isset( $_GET['key'] ) ? wp_unslash( $_GET['key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return is_string( $raw_key ) && '' !== sanitize_text_field( $raw_key );
	}

	/**
	 * Initializes Mastercard Gateway Service.
	 *
	 * @return GatewayServiceController
	 */
	public function init_service() {
		$gateway       = MastercardGateway::get_instance();
		$logging_level = $gateway->is_debug_logging_enabled()
			? \Monolog\Logger::DEBUG
			: \Monolog\Logger::ERROR;
		$uploads       = wp_upload_dir();
		$log_dir       = trailingslashit( $uploads['basedir'] ) . 'wc-logs';

		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$log_file    = is_dir( $log_dir )
			? trailingslashit( $log_dir ) . 'mastercard.log'
			: $content_dir . '/mastercard.log';

		$this->logger = new Logger( 'mastercard' );
		$this->logger->pushHandler(
			new StreamHandler(
				$log_file,
				$logging_level
			)
		);

		$message_factory = new Psr17Factory();
		$client          = new PluginClient(
			HttpClientDiscovery::find(),
			array(
				new ContentLengthPlugin(),
				new HeaderSetPlugin( array( 'Content-Type' => 'application/json;charset=UTF-8' ) ),
				new AuthenticationPlugin( new BasicAuth( 'merchant.' . ( $gateway->username ?? '' ), $gateway->password ?? '' ) ),
				new ApiErrorPlugin( $this->logger ),
				new ApiLoggerPlugin( $this->logger, null, $gateway->is_debug_logging_enabled() ),
			)
		);
		$request_matcher = new RequestMatcher( null, $gateway->get_gateway_url() );
		$http_client     = new HttpClientRouter();

		return new GatewayServiceController(
			$gateway->get_gateway_url(),
			$gateway->get_api_version(),
			$gateway->username ?? '',
			UtilityController::get_instance()->get_webhook_url(),
			$message_factory,
			$client,
			$request_matcher,
			$http_client
		);
	}

	/**
	 * Refreshes handling fees dynamically on the WooCommerce checkout block.
	 *
	 * @return boolean
	 */
	public static function refresh_handling_fees_on_checkout_block() {
		return true;
	}

	/**
	 * Forward REST routes to the payment processor.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function rest_route_forward( $request ) {
		try {
			return PaymentController::get_instance()->rest_route_processor( $request->get_route(), $request );
		} catch ( ClientErrorException $e ) {
			return $this->rest_api_error_response( $e, 400 );
		} catch ( ServerErrorException $e ) {
			return $this->rest_api_error_response( $e, 502 );
		} catch ( GatewayResponseException $e ) {
			return $this->rest_api_error_response( $e, 400 );
		} catch ( \Exception $e ) {
			return $this->rest_api_error_response( $e, 500 );
		}
	}

	/**
	 * Permission check for order-scoped REST routes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$order_id = absint( $request->get_param( 'id' ) );

		if ( ! $order_id ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Order ID is required.', 'mastercard' ),
				array( 'status' => 400 )
			);
		}

		$token = RestAuthHelper::get_token_from_request( $request );

		if ( ! RestAuthHelper::verify_order_rest_token( $order_id, $token ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Invalid or expired payment token.', 'mastercard' ),
				array( 'status' => 403 )
			);
		}

		if ( false !== strpos( $request->get_route(), '/savePayment/' ) ) {
			$nonce = $request->get_param( '_wpnonce' );

			if ( empty( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'wp_rest' ) ) {
				return new \WP_Error(
					'rest_forbidden',
					__( 'Invalid security token.', 'mastercard' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Permission check for Mastercard notification webhooks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function webhook_permissions_check( $request ) {
		$headers = $request->get_headers();
		$secret  = '';

		if ( isset( $headers['x_notification_secret'][0] ) ) {
			$secret = $headers['x_notification_secret'][0];
		}

		$gateway             = MastercardGateway::get_instance();
		$sandbox_mode        = $gateway->get_option( 'sandbox' );
		$notification_secret = ( 'yes' === $sandbox_mode )
			? $gateway->get_option( 'test_webhook_secret' )
			: $gateway->get_option( 'webhook_secret' );

		if ( empty( $notification_secret ) || empty( $secret ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Webhook authentication failed.', 'mastercard' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( $notification_secret, (string) $secret ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Webhook authentication failed.', 'mastercard' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Log exception details and return a safe REST error for clients.
	 *
	 * @param \Throwable $e       Exception.
	 * @param int        $status HTTP status.
	 * @return \WP_Error
	 */
	protected function rest_api_error_response( $e, $status = 400 ) {
		if ( $this->logger ) {
			$this->logger->error(
				$e->getMessage(),
				array(
					'exception' => get_class( $e ),
					'status'    => $status,
				)
			);
		}

		return new \WP_Error(
			'mastercard_api_error',
			__( 'Payment could not be processed. Please try again or contact the store.', 'mastercard' ),
			array( 'status' => $status )
		);
	}
}
