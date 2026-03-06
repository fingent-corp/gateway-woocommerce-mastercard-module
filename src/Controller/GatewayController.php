<?php
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
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Controller\AdminController;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Controller\FrontendController;
use Fingent\Mastercard\Controller\PaymentController;
use Fingent\Mastercard\Controller\GatewayBlockSupportController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class GatewayController {
	/**
	 * The single instance of the class.
	 *
	 * @var Mastercard
	 */
	private static $instance = null;
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

	public function get_logger() {
        return $this->logger;
    }

	/**
	 * GatewayController constructor.
	 * 
	 * @throws Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() {
		Constants::get_instance();
		AdminController::get_instance();
		FrontendController::get_instance();
		PaymentController::get_instance();
	}

	/**
	 * Register the payment gateway and declare compatibility with WooCommerce blocks.
	 *
	 * - Checks if WooCommerce's WC_Payment_Gateway class exists before proceeding.
	 * - Adds the custom gateway to the list of available WooCommerce payment gateways.
	 * - Declares compatibility with WooCommerce cart and checkout blocks (for block-based themes).
	 * - Adds support for WooCommerce blocks only if not on the order pay page,
	 *   to avoid conflicts or unnecessary loading.
	 */
    public function register() {
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
				register_rest_route(
					'mastercard/v1',
					'/checkoutSession/(?P<id>\d+)',
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => array(
							'id' => array(
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_numeric( $param );
								},
							),
						),
					)
				);
				register_rest_route(
					'mastercard/v1',
					'/session/(?P<id>\d+)',
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => array(
							'id' => array(
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_numeric( $param );
								},
							),
						),
					)
				);
				register_rest_route(
					'mastercard/v1',
					'/savePayment/(?P<id>\d+)',
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'rest_route_forward' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => array(
							'id' => array(
								'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
									return is_numeric( $param );
								},
							),
						),
					)
				);			
				register_rest_route(
					'mastercard/v1',
					'/webhook',
					array(
						'methods'             => 'POST',
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'callback'            => array( $this, 'rest_route_forward' ),
					)
				);
			}
		);
    }

    /**
	 * Add MastercardGateway to WooCommerce
	 *
	 * @param array $methods Getway method array.
	 *
	 * @return array
	 */
    public function add_gateway( $methods ) {
        $methods[] = MastercardGateway::class;
        
        return $methods;
    }

    /**
	 * Function to declare compatibility with cart_checkout_blocks feature.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public function declare_cart_checkout_blocks_compatibility() { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MG_ENTERPRISE_MAIN_FILE, true );
			FeaturesUtil::declare_compatibility( 'custom_order_tables', MG_ENTERPRISE_MAIN_FILE, true );
		}
	}

	/**
	 * Function to register the Mastercard payment method type.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public function mastercard_woocommerce_block_support() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new GatewayBlockSupportController() );
			}
		);

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'mastercard_gateway_handling_fee',
				'callback'  => function( $data ) {
					self::refresh_handling_fees_on_checkout_block();
				},
			)
		);
	}

	/**
	 * Is_order_pay_page - Returns true when viewing the order received page.
	 *
	 * @return bool
	 */
	public function is_order_pay_page() {
		return isset( $_GET['key'] ) && isset( $_SERVER['REQUEST_URI'] ) && strpos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'order-pay' ) !== false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Initializes Mastercard Gateway Service.
	 *
	 * @return GatewayServiceController
	 *
	 * @throws Exception If there's a problem connecting to the Gateway Service.
	 */
	public function init_service() {
		$gateway       = MastercardGateway::get_instance();
		$logging_level = $gateway->is_debug_logging_enabled()
			? \Monolog\Logger::DEBUG
			: \Monolog\Logger::ERROR;
		$this->logger        = new Logger( 'mastercard' );
		$this->logger->pushHandler(
			new StreamHandler(
				WP_CONTENT_DIR . '/mastercard.log',
				$logging_level
			)
		);
		
		$message_factory = new Psr17Factory();
		$client          = new PluginClient(
			HttpClientDiscovery::find(),
			array(
				new ContentLengthPlugin(),
				new HeaderSetPlugin( array( 'Content-Type' => 'application/json;charset=UTF-8' ) ),
				new AuthenticationPlugin( new BasicAuth(  'merchant.' . $gateway->username, $gateway->password ) ),
				new ApiErrorPlugin( $this->logger ),
				new ApiLoggerPlugin( $this->logger ),
			)
		);
		$request_matcher = new RequestMatcher( null, $gateway->get_gateway_url() );
		$http_client     = new HttpClientRouter();

		return new GatewayServiceController(
			$gateway->get_gateway_url(),
			$gateway->get_api_version(),
			$gateway->username,
			UtilityController::get_instance()->get_webhook_url(),
			$this->logger,
			$message_factory,
			$client,
			$request_matcher,
			$http_client
		);
	}

	/**
	 * Refreshes handling fees dynamically on the WooCommerce checkout block.
	 *
	 * This function ensures that handling fees are recalculated and updated when
	 * the checkout block is refreshed. It is typically used in cases where handling 
	 * fees depend on cart contents, shipping method, or other dynamic conditions.
	 *
	 * @return boolean
	 */
	public static function refresh_handling_fees_on_checkout_block() {
		return true;
	}

	/**
	 * Check the rest_route_forward.
	 *
	 * @param array $request WP_REST_Request.
	 *
	 * @return array Rest route processor.
	 * @throws Mastercard_GatewayResponseException It triggers a Mastercard_GatewayResponseException in the absence of a REST API route.
	 * @throws \Http\Client\Exception It triggers a Exception in the absence of a REST API route.
	 */
	public function rest_route_forward( $request ) {
		return PaymentController::get_instance()->rest_route_processor( $request->get_route(), $request );
	}

	/**
	 * Permission check.
	 *
	 * @param boolean $request Request.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore
		return true;
	}
}
