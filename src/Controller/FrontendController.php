<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * Checkout scripts, fees, surcharge UI, and payment method display hooks.
 *
 * @package Fingent\Mastercard\Controller
 */

namespace Fingent\Mastercard\Controller;

use WC_Order;
use WC_Order_Item_Fee;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Controller\PaymentController;
use Fingent\Mastercard\Helper\CheckoutBuilder;
use Fingent\Mastercard\Helper\RestAuthHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Frontend checkout integration for the Mastercard payment gateway.
 */
class FrontendController {
	/**
	 * Singleton instance.
	 *
	 * @var FrontendController|null
	 */
	private static ?FrontendController $instance = null;

	/**
	 * Shared plugin utility helpers.
	 *
	 * @var UtilityController
	 */
	public UtilityController $utility;

	/**
	 * Gateway Service
	 *
	 * @var MastercardGateway
	 */
	protected MastercardGateway $gateway;

	/**
	 * FrontendController Instance.
	 *
	 * @return FrontendController instance.
	 */
	public static function get_instance(): FrontendController {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * FrontendController constructor.
	 *
	 * @throws \Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() {
		$this->gateway = MastercardGateway::get_instance();
		$this->utility = UtilityController::get_instance();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_footer', array( $this, 'refresh_handling_fees_on_checkout' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_js_extra_attribute' ), 10 );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_gateway_scripts' ), 10 );
		add_action( 'template_redirect', array( $this, 'define_default_payment_gateway' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'clear_session_storage' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_handling_fee' ), 10, 1 );	
		add_filter( 'woocommerce_saved_payment_methods_list', array( $this, 'remove_saved_mastercard_methods' ), 10, 2 );
		add_filter( 'woocommerce_payment_gateway_get_saved_payment_method_option_html', array( $this, 'mastercard_saved_payment_method_option_html' ), 10, 3 );
		add_filter( 'woocommerce_payment_gateway_save_new_payment_method_option_html', array( $this, 'mastercard_saved_new_payment_method_option_html' ), 10, 3 );
		add_filter( 'woocommerce_payment_gateway_get_new_payment_method_option_html', array( $this, 'mg_get_new_payment_method_option_html' ), 10, 2 );
		add_filter( 'woocommerce_payment_token_class', array( $this, 'override_mg_token_class' ), 10, 2 );
		add_filter( 'woocommerce_credit_card_type_labels', array( $this, 'mg_get_credit_card_type_label' ), 10, 2 );

		$ajax = array(
			'get_surcharge_amount'           => 'get_surcharge_amount',
			'update_selected_payment_method' => 'update_selected_payment_method',
		);

		foreach ( $ajax as $handler => $function_name ) {
			add_action( 'wp_ajax_' . $handler, array( $this, $function_name . '_handler' ) );
			add_action( 'wp_ajax_nopriv_' . $handler, array( $this, $function_name . '_handler' ) );
		}

		if ( ! is_admin() ) {
			set_exception_handler( array( $this, 'exception_handler' ) );
		}
	}

	/**
	 * Load plugin translation files.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'mastercard-gateway',
			false,
			trailingslashit( dirname( plugin_basename( MG_ENTERPRISE_MAIN_FILE ) ) ) . 'i18n/'
		);
	}

	/**
	 * This function is responsible for including the necessary payment gateway scripts.
	 *
	 * @return void
	 */
	public function payment_gateway_scripts() {
		$order_id = get_query_var( 'order-pay' );
		$order    = new WC_Order( $order_id );

		if ( $this->gateway->id !== $order->get_payment_method() ) {
			return;
		}

		if ( HOSTED_CHECKOUT === $this->gateway->method ) {
			wp_enqueue_script(
				'woocommerce_mastercard_hosted_checkout',
				esc_attr( $this->utility->get_hosted_checkout_js() ),
				array(),
				MG_ENTERPRISE_MODULE_VERSION,
				false
			);
		}

		if ( HOSTED_SESSION === $this->gateway->method ) {
			wp_enqueue_script(
				'woocommerce_mastercard_hosted_session',
				esc_url( $this->utility->get_hosted_session_js() ),
				array(),
				MG_ENTERPRISE_MODULE_VERSION,
				false
			);

			if ( $this->gateway->use_3dsecure_v1() || $this->gateway->use_3dsecure_v2() ) {
				wp_enqueue_script(
					'woocommerce_mastercard_threeds',
					esc_url( $this->utility->get_threeds_js() ),
					array(),
					MG_ENTERPRISE_MODULE_VERSION,
					false
				);
			}

			wp_localize_script(
				'woocommerce_mastercard_hosted_session',
				'mgParams',
				array(
					'gatewayId'          => MG_ENTERPRISE_ID,
					'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
					'checkoutAjaxNonce'  => wp_create_nonce( 'mastercard_checkout_ajax' ),
					'isSurchargeEnabled' => $this->gateway->get_option( SUR_ENABLED ) === 'yes' ? true : false,
					'surchargeFee'       => (float) $this->gateway->get_option( SUR_AMT_TXT ),
					'cardType'           => strtoupper( $this->gateway->get_option( SUR_CARD_TYPE ) ),
				)
			);
		}
	}

	/**
	 * This function is responsible for including the necessary payment gateway scripts.
	 *
	 * @param string $tag Script link.
	 *
	 * @return string $tag Script link.
	 */
	public function add_js_extra_attribute( $tag ) {
		$script = $this->utility->get_hosted_checkout_js();
		if ( false !== strpos( $tag, $script ) ) {
			return str_replace(
				' src',
				' async data-error="errorCallback" data-beforeRedirect="befroreRedirctCallback" data-afterRedirect="afterRedirectCallback" data-complete="completeCallback" src',
				$tag
			);
		}
		return $tag;
	}

	/**
	 * Refreshes the handling fees on the checkout page dynamically.
	 *
	 * @return void
	 */
	public function refresh_handling_fees_on_checkout() {
		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			static $executed = false;

			if ( $executed ) {
				return;
			}

			$executed     = true;
			$amount_type  = $this->gateway->get_option( HF_AMT_TYPE_TXT );
			$handling_fee = $this->gateway->get_option( HF_AMT_TXT ) ? $this->gateway->get_option( HF_AMT_TXT ) : 0;

			if ( HF_PERCENTAGE === $amount_type ) {
				$surcharge = (float) WC()->cart->get_cart_contents_total() * ( (float) $handling_fee / 100.0 );
			} else {
				$surcharge = (float) $handling_fee;
			}
			$hf_text              = ! empty( $this->gateway->get_option( HF_TEXT ) ) ? $this->gateway->get_option( HF_TEXT ) : __( 'Handling Fee', 'mastercard-gateway' );
			$hf_slug              = sanitize_title( $hf_text );
			$handling_fee_wrapper = '<div class="wc-block-components-totals-item wc-block-components-totals-fees wc-block-components-totals-fees__' . esc_attr( $hf_slug ) . '"><span class="wc-block-components-totals-item__label">' . esc_html( $hf_text ) . '</span><span class="wc-block-formatted-money-amount wc-block-components-formatted-money-amount wc-block-components-totals-item__value">' . wp_kses_post( wc_price( $surcharge ) ) . '</span><div class="wc-block-components-totals-item__description"></div></div>';
			?>
			<script type="text/javascript">
				const handlingText = <?php echo wp_json_encode( $hf_slug ); ?>;
				const handlingFeeWrapper = <?php echo wp_json_encode( $handling_fee_wrapper ); ?>;

				jQuery(function($) {
					// Detect when payment method is changed
					$( document ).on( 'change', 'input[name="payment_method"]', function() {
						$( document.body ).trigger( "update_checkout" );
					});
				});
			</script>
			<style type="text/css">.woocommerce-checkout #payment ul.payment_methods li.payment_method_mastercard_gateway img { height: 24px; }</style>
			<?php
		}
	}

	/**
	 * Define the default payment gateway for the checkout process.
	 *
	 * This function sets the default payment method when a customer visits
	 * the checkout page. It ensures the preferred gateway
	 * is pre-selected to streamline the checkout experience.
	 *
	 * @return void
	 */
	public function define_default_payment_gateway() {
        if ( is_checkout() && ! is_wc_endpoint_url() ) {
            if ( WC()->session->get( 'chosen_payment_method' ) ) {
                return;
            }

            $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
            $first_gateway    = reset( $payment_gateways );

            if ( $first_gateway ) {
                WC()->session->set( 'chosen_payment_method', $first_gateway->id );
            }

            return;
        }

        if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
            return;
        }

        $order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );

        if ( ! $order ) {
            return;
        }

        $order_payment_method = $order->get_payment_method();

        if ( $order_payment_method && $order_payment_method !== MG_ENTERPRISE_ID ) {
            return;
        }

        if ( $order_payment_method === MG_ENTERPRISE_ID ) {
            WC()->session->set( 'chosen_payment_method', MG_ENTERPRISE_ID );
        }
    }

	/**
	 * Clear browser session storage after a successful Mastercard order.
	 *
	 * @return void
	 */
	public function clear_session_storage() {
		if ( ! is_order_received_page() ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || MG_ENTERPRISE_ID !== $order->get_payment_method() ) {
			return;
		}
		
		wp_enqueue_script(
			'clear-session-storage',
			UtilityController::plugin_url() . '/assets/js/clear-session.js', 
			array(),
			MG_ENTERPRISE_MODULE_VERSION,
			true
		);
	}

	/**
	 * Removes saved Mastercard payment methods from the WooCommerce saved payment methods list.
	 *
	 * This function loops through the saved payment methods, filters out any method
	 * associated with the MG_ENTERPRISE_ID, and removes empty categories if no methods remain.
	 *
	 * @param array<int|string, array<int, array<string, mixed>>> $saved_methods Saved payment methods by type.
	 * @param int                                                 $customer_id   The ID of the current customer.
	 *
	 * @return array<int|string, array<int, array<string, mixed>>> Filtered saved payment methods.
	 */
	public function remove_saved_mastercard_methods( $saved_methods, $customer_id ) {
		if ( ! $customer_id ) {
			return $saved_methods;
		}

		foreach ( $saved_methods as $key => $methods ) {
			$saved_methods[ $key ] = array_filter(
				$methods,
				function ( $method ) {
					return empty( $method['method']['gateway'] ) || MG_ENTERPRISE_ID !== $method['method']['gateway'];
				}
			);

			if ( empty( $saved_methods[ $key ] ) ) {
				unset( $saved_methods[ $key ] );
			}
		}

		return $saved_methods;
	}

	/**
	 * Updates the selected payment method for the current user or session.
	 *
	 * This function is typically used in WooCommerce or similar payment processing
	 * plugins to update the user's chosen payment method when they select a new option
	 * at checkout. It ensures that the selected method is stored and used for order processing.
	 *
	 * Implementation details may include:
	 * - Retrieving the selected payment method from the request.
	 * - Updating the user session or meta data accordingly.
	 * - Validating the payment method before updating.
	 * - Returning a response (if used in an AJAX call).
	 *
	 * @return never
	 */
	public function update_selected_payment_method_handler() {
		check_ajax_referer( 'mastercard_checkout_ajax', 'security' );

		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => 'Checkout session unavailable.' ), 403 );
		}

		if ( ! isset( $_POST['payment_method'] ) ) {
			wp_send_json_error();
		}

		$payment_method_raw = wp_unslash( $_POST['payment_method'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$payment_method     = is_string( $payment_method_raw ) ? sanitize_text_field( $payment_method_raw ) : '';
		$gateways           = WC()->payment_gateways()->payment_gateways();

		if ( ! isset( $gateways[ $payment_method ] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid payment method.' ), 400 );
		}

		WC()->session->set( 'chosen_payment_method', $payment_method );
		WC()->cart->calculate_totals();
		wp_send_json_success();
	}

	/**
	 * Calculates and returns the surcharge amount for a transaction.
	 *
	 * This method determines the surcharge based on predefined rules,
	 * such as a fixed percentage or flat fee. It is typically used
	 * to add additional costs to a payment transaction.
	 *
	 * @return void
	 */
	public function get_surcharge_amount_handler() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json(
				array(
					'message' => __( 'Order ID is required.', 'mastercard-gateway' ),
					'code'    => 400,
				),
				400
			);
		}

		$order_token = $this->get_surcharge_request_order_token();

		$order = $this->verify_surcharge_ajax_access( $order_id, $order_token );

		$order_builder      = new CheckoutBuilder( $order );
		$surcharge_enabled  = $this->gateway->get_option( SUR_ENABLED );
		$card_type          = strtoupper( $this->gateway->get_option( SUR_CARD_TYPE ) );
		$funding_method_raw = isset( $_POST['funding_method'] ) ? wp_unslash( $_POST['funding_method'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$funding_method     = is_string( $funding_method_raw ) ? sanitize_text_field( $funding_method_raw ) : null;
		$token_raw          = isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token              = is_string( $token_raw ) ? sanitize_text_field( $token_raw ) : null;
		$source_type_raw    = isset( $_POST['source_type'] ) ? wp_unslash( $_POST['source_type'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$source_type        = is_string( $source_type_raw ) ? sanitize_text_field( $source_type_raw ) : null;

		if ( $funding_method !== $card_type && empty( $token ) && empty( $source_type ) ) {
			wp_send_json(
				array(
					'message' => __( 'Unfortunately, we couldn’t update the order total at this time.', 'mastercard-gateway' ),
					'code'    => 400,
				),
				400
			);
		}

		if ( 'yes' === $surcharge_enabled ) {
			$existing_surcharge = $order->get_meta( '_mpgs_surcharge_fee' );

			if ( ! empty( $existing_surcharge ) && (float) $existing_surcharge > 0 ) {
				wp_send_json(
					array(
						'code'        => 200,
						'order_total' => wc_price( $order->get_total() ),
					)
				);
			}

			$amount_type    = $this->gateway->get_option( SUR_AMT_TYPE_TXT );
			$surcharge_fee  = $this->gateway->get_option( SUR_AMT_TXT ) ? $this->gateway->get_option( SUR_AMT_TXT ) : 0;
			$surcharge_text = $this->gateway->get_option( SUR_TEXT );
			$surcharge_text = ! empty( $surcharge_text ) ? $surcharge_text : __( 'Surcharge', 'mastercard-gateway' );

			if ( HF_PERCENTAGE === $amount_type ) {
				$surcharge = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / ( 100.0 - (float) $surcharge_fee ) );
			} else {
				$surcharge = (float) $surcharge_fee;
			}

			$surcharge = $order_builder->formatted_price( $surcharge );
			$fee       = new WC_Order_Item_Fee();
			$fee->set_name( $surcharge_text );
			$fee->set_amount( $surcharge );
			$fee->set_total( $surcharge );

			// Add the fee to the order.
			$order->add_item( $fee );

			// Save the order.
			$order->calculate_totals( false );
			$order->update_meta_data( '_mpgs_surcharge_fee', $surcharge );
			$order->save();

			$return = array(
				'code'        => 200,
				'order_total' => wc_price( $order->get_total() ),
			);

			wp_send_json( $return );
		}

		wp_send_json(
			array(
				'message' => __( 'Surcharge is not enabled.', 'mastercard-gateway' ),
				'code'    => 400,
			),
			400
		);
	}

	/**
	 * Verify order-scoped token and payable status for surcharge AJAX.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $token    Order-scoped REST token.
	 * @return \WC_Order
	 */
	protected function verify_surcharge_ajax_access( $order_id, $token ) {
		if ( ! RestAuthHelper::verify_order_rest_token( $order_id, $token ) ) {
			check_ajax_referer( 'mastercard_checkout_ajax', 'security' );
		}

		$this->maybe_assign_mastercard_payment_method( $order_id );

		try {
			return RestAuthHelper::get_payable_order( $order_id );
		} catch ( \Exception $e ) {
			wp_send_json(
				array(
					'message' => __( 'This order cannot be modified.', 'mastercard-gateway' ),
					'code'    => 403,
				),
				403
			);
			exit;
		}
	}

	/**
	 * Ensure legacy block-checkout orders have the gateway ID persisted before surcharge updates.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	protected function maybe_assign_mastercard_payment_method( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( MG_ENTERPRISE_ID === RestAuthHelper::resolve_payment_method( $order ) ) {
			return;
		}

		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
		if ( ! isset( $available_gateways[ MG_ENTERPRISE_ID ] ) ) {
			return;
		}

		$order->set_payment_method( MG_ENTERPRISE_ID );
		$order->set_payment_method_title( $available_gateways[ MG_ENTERPRISE_ID ]->get_title() );
		$order->save();
	}

	/**
	 * Read order token for surcharge AJAX (header preferred over POST body).
	 *
	 * @return string
	 */
	protected function get_surcharge_request_order_token() {
		$header_token = isset( $_SERVER['HTTP_X_MG_ORDER_TOKEN'] ) ? wp_unslash( $_SERVER['HTTP_X_MG_ORDER_TOKEN'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( is_string( $header_token ) && '' !== trim( $header_token ) ) {
			return trim( $header_token );
		}

		$post_token = isset( $_POST['mg_order_token'] ) ? wp_unslash( $_POST['mg_order_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $post_token ) || '' === $post_token ) {
			return '';
		}

		return str_replace( ' ', '+', trim( $post_token ) );
	}

	/**
	 * Adds a handling fee to the WooCommerce cart calculation.
	 *
	 * @param \WC_Cart $cart WooCommerce cart instance.
	 * @return void
	 */
	public function add_handling_fee( $cart ) {
		if ( ! $cart || ( is_admin() && ! defined( 'DOING_AJAX' ) ) ) {
			return;
		}

		$chosen_gateway = WC()->session->get( 'chosen_payment_method' );

		if ( ! empty( $chosen_gateway )
			&& null !== $this->gateway->hf_enabled
			&& 'yes' === $this->gateway->hf_enabled
			&& MG_ENTERPRISE_ID === $chosen_gateway ) {
			$handling_text = $this->gateway->get_option( HF_TEXT );
			$handling_text = ! empty( $handling_text ) ? $handling_text : __( 'Handling Fee', 'mastercard-gateway' );
			$amount_type   = $this->gateway->get_option( HF_AMT_TYPE_TXT );
			$handling_fee  = $this->gateway->get_option( HF_AMT_TXT ) ? $this->gateway->get_option( HF_AMT_TXT ) : 0;

			if ( HF_PERCENTAGE === $amount_type ) {
				$surcharge = (float) WC()->cart->get_cart_contents_total() * ( (float) $handling_fee / 100.0 );
			} else {
				$surcharge = (float) $handling_fee;
			}

			WC()->cart->add_fee( $handling_text, $surcharge, true, '' );
		}
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
	 * Displays a surcharge message on the order summary or confirmation page.
	 *
	 * This function is typically used to notify users of any additional
	 * surcharge applied to their order. The surcharge amount can be retrieved
	 * from the `$order` object, which represents the current order details.
	 *
	 * @param \WC_Order $order The WooCommerce order object containing order details.
	 * @return string
	 */
	public function display_surcharge_message( $order ) {
		$message           = '';
		$surcharge_enabled = $this->gateway->get_option( SUR_ENABLED );
		$surcharge_fee     = (float) $this->gateway->get_option( SUR_AMT_TXT );
		if ( 'yes' === $surcharge_enabled && $surcharge_fee > 0 ) {
			$notice_content = wp_kses_post( $this->get_surcharge_message( $order ) );
			$message        = '<div class="mg-surcharge-notice-banner"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg><div class="mg-surcharge-notice-content">' . $notice_content . '</div></div>';
		}

		return $message;
	}

	/**
	 * Generates and returns a surcharge message for the provided order.
	 *
	 * @param \WC_Order $order The WooCommerce order object.
	 * @return string The surcharge message, typically displayed to inform
	 *                the customer about additional charges applied to their order.
	 *
	 * This method is commonly used to calculate and communicate surcharges
	 * (e.g., credit card fees, payment gateway fees) applied during checkout.
	 * Ensure proper handling of order data and formatting for customer clarity.
	 */
	public function get_surcharge_message( $order ) {
		$amount_type     = $this->gateway->get_option( SUR_AMT_TYPE_TXT );
		$surcharge_fee   = $this->gateway->get_option( SUR_AMT_TXT ) ? $this->gateway->get_option( SUR_AMT_TXT ) : 0;
		$mg_card_type    = $this->gateway->get_option( SUR_CARD_TYPE );
		$translated_card = ( SUR_DEBIT === $mg_card_type )
			? __( 'Debit', 'mastercard-gateway' )
			: __( 'Credit', 'mastercard-gateway' );
		$translated_card = is_string( $translated_card ) ? $translated_card : '';
		/* translators: %s: card funding type (Credit or Debit). */
		$card_type_label     = __( '%s Card', 'mastercard-gateway' );
		$surcharge_card_type = (string) ( is_string( $card_type_label )
			? sprintf( $card_type_label, $translated_card )
			: $translated_card . ' Card' );

		if ( HF_FIXED === $amount_type ) {
			$default_msg = __(
				'When using a {{MG_CARD_TYPE}} an additional surcharge of {{MG_SUR_AMT}} will be applied, bringing the total payable amount to {{MG_TOTAL_AMT}}.',
				'mastercard-gateway'
			);
		} else {
			$default_msg = SUR_DEFAULT_MSG;
		}

		// Use saved message if set, otherwise use dynamic default.
		$option_msg = $this->gateway->get_option( SUR_MSG );
		if ( is_string( $option_msg ) && '' !== $option_msg ) {
			$surcharge_message = $option_msg;
		} elseif ( is_string( $default_msg ) ) {
			$surcharge_message = $default_msg;
		} else {
			$surcharge_message = '';
		}

		// Calculate surcharge.
		if ( HF_PERCENTAGE === $amount_type ) {
			$surcharge_amount = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / ( 100.0 - (float) $surcharge_fee ) );
		} else {
			$surcharge_amount = (float) $surcharge_fee;
		}

		$total_total = (float) $order->get_total() + $surcharge_amount;
		if ( HF_PERCENTAGE === $amount_type ) {
			$surcharge_fee_label = (string) $surcharge_fee . '%';
		} else {
			$surcharge_fee_label = '';
		}

		$surcharge_price = wc_price( $surcharge_amount );
		$total_price     = wc_price( $total_total );

		return (string) str_replace(
			array( '{{MG_SUR_AMT}}', '{{MG_SUR_PCT}}', '{{MG_CARD_TYPE}}', '{{MG_TOTAL_AMT}}' ),
			array(
				'<b>' . ( is_string( $surcharge_price ) ? $surcharge_price : '' ) . '</b>',
				$surcharge_fee_label,
				$surcharge_card_type,
				'<b>' . ( is_string( $total_price ) ? $total_price : '' ) . '</b>',
			),
			$surcharge_message
		);
	}

	/**
	 * Displays a surcharge confirmation box in the order details page.
	 *
	 * This method outputs a confirmation box related to any surcharges applied
	 * to the order. It is typically used in the admin area or order review screens
	 * to inform the user/admin of additional charges and potentially allow
	 * confirmation or review of these charges.
	 *
	 * @param \WC_Order $order The WooCommerce order object containing the order details.
	 * @return string The surcharge confirmation html, typically displayed to inform
	 *                the customer about additional charges applied to their order.
	 */
	public function display_surcharge_confirmation_box( $order ) {
		$surcharge_text      = $this->gateway->get_option( SUR_TEXT );
		$surcharge_text      = ! empty( $surcharge_text ) ? $surcharge_text : __( 'Surcharge', 'mastercard-gateway' );
		$amount_type         = $this->gateway->get_option( SUR_AMT_TYPE_TXT );
		$surcharge_fee       = $this->gateway->get_option( SUR_AMT_TXT ) ? $this->gateway->get_option( SUR_AMT_TXT ) : 0;
		$mg_card_type        = $this->gateway->get_option( SUR_CARD_TYPE );
		$card_label          = ( SUR_DEBIT === $mg_card_type )
			? __( 'Debit', 'mastercard-gateway' )
			: __( 'Credit', 'mastercard-gateway' );
		$card_label          = is_string( $card_label ) ? $card_label : '';
		$card_word           = __( 'Card', 'mastercard-gateway' );
		$surcharge_card_type = $card_label . ' ' . ( is_string( $card_word ) ? $card_word : 'Card' );

		if ( HF_FIXED === $amount_type ) {
			$default_msg = __(
				'When using a {{MG_CARD_TYPE}} an additional surcharge of {{MG_SUR_AMT}} will be applied, bringing the total payable amount to {{MG_TOTAL_AMT}}.',
				'mastercard-gateway'
			);
		} else {
			$default_msg = SUR_DEFAULT_MSG;
		}

		$option_msg = $this->gateway->get_option( SUR_MSG );
		if ( is_string( $option_msg ) && '' !== $option_msg ) {
			$surcharge_message = $option_msg;
		} elseif ( is_string( $default_msg ) ) {
			$surcharge_message = $default_msg;
		} else {
			$surcharge_message = '';
		}

		if ( HF_PERCENTAGE === $amount_type ) {
			$surcharge = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / ( 100.0 - (float) $surcharge_fee ) );
		} else {
			$surcharge = (float) $surcharge_fee;
		}

		$total_total = (float) $order->get_total() + $surcharge;
		if ( HF_PERCENTAGE === $amount_type ) {
			$surcharge_fee_label = (string) $surcharge_fee . '%';
		} else {
			$surcharge_fee_label = '';
		}
		$message = (string) str_replace(
			array( '{{MG_SUR_AMT}}', '{{MG_SUR_PCT}}', '{{MG_CARD_TYPE}}', '{{MG_TOTAL_AMT}}' ),
			array(
				'<b>' . wc_price( $surcharge ) . '</b>',
				$surcharge_fee_label,
				$surcharge_card_type,
				'<b>' . wc_price( $total_total ) . '</b>',
			),
			$surcharge_message
		);

		$order_total_label = apply_filters( 'mastercard_order_pay_order_total_text', __( 'Order Total', 'mastercard-gateway' ) );
		$grand_total_label = apply_filters( 'mastercard_order_pay_grand_total_text', __( 'Grand Total', 'mastercard-gateway' ) );
		$confirm_label     = apply_filters( 'mastercard_order_pay_confirm_button_text', __( 'Confirm', 'mastercard-gateway' ) );
		$cancel_label      = apply_filters( 'mastercard_order_pay_cancel_button_text', __( 'Cancel', 'mastercard-gateway' ) );

		$order_html  = '<ul>';
		$order_html .= '<li><label>' . esc_html( $order_total_label ) . ':</label> ' . wp_kses_post( wc_price( $order->get_total() ) ) . '</li>';
		$order_html .= '<li><label>' . esc_html( $surcharge_text ) . ':</label> ' . wp_kses_post( wc_price( $surcharge ) ) . '</li>';
		$order_html .= '<li><label>' . esc_html( $grand_total_label ) . ':</label> ' . wp_kses_post( wc_price( $total_total ) ) . '</li>';
		$order_html .= '</ul>';

		return '<p>' . wp_kses_post( $message ) . '</p>'
			. $order_html
			. '<div class="mg_button_wrapper">'
			. '<button type="button" class="wp-element-button wp-element-confirm-button">' . esc_html( $confirm_label ) . '</button>'
			. '<a type="button" class="wp-element-button wp-element-cancel-button" href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html( $cancel_label ) . '</a>'
			. '</div>';
	}

	/**
	 * Customize the HTML output for a saved Mastercard payment method.
	 *
	 * This function modifies how each saved Mastercard payment method is displayed
	 * on the WooCommerce checkout page. It builds a radio input and label for each
	 * saved token, allowing users to select one of their stored payment methods.
	 *
	 * @param string              $html    The original HTML output.
	 * @param \WC_Payment_Token   $token   The saved payment method token.
	 * @param \WC_Payment_Gateway $gateway The payment gateway instance.
	 *
	 * @return string Modified HTML output for the saved payment method option.
	 */
	public function mastercard_saved_payment_method_option_html( $html, $token, $gateway ) {
		if ( MG_ENTERPRISE_ID !== $gateway->id ) {
			return $html;
		}

		$card_type = $token->get_meta( 'funding_method' );

		return sprintf(
			'<li class="woocommerce-SavedPaymentMethods-token">
				<input
					id="wc-%1$s-payment-token-%2$s"
					type="radio"
					name="wc-%1$s-payment-token"
					value="%2$s"
					style="width:auto;"
					class="woocommerce-SavedPaymentMethods-tokenInput"
					data-card="%5$s"
					%4$s />
				<label for="wc-%1$s-payment-token-%2$s">%3$s</label>
			</li>',
			esc_attr( MG_ENTERPRISE_ID ),
			esc_attr( (string) $token->get_id() ),
			esc_html( $token->get_display_name() ),
			checked( $token->is_default(), true, false ),
			esc_attr( $card_type )
		);
	}

	/**
	 * Exception handler function.
	 *
	 * @param \Throwable $exception Uncaught exception.
	 * @return void
	 */
	public function exception_handler( $exception ) {
		$message = '<div class="wc-block-components-notice-banner is-error"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg><div class="wc-block-components-notice-banner__content"><ul><li>';
		/* translators: %s: exception message. */
		$error_label = __( 'Error: "%s"', 'mastercard-gateway' );
		$message    .= (string) sprintf(
			is_string( $error_label ) ? $error_label : 'Error: "%s"',
			$exception->getMessage()
		);
		$message    .= '</li></ul></div></div>';

		echo wp_kses_post( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Outputs a checkbox for saving a new payment method to the database.
	 *
	 * @param string              $html    Original HTML output.
	 * @param \WC_Payment_Gateway $gateway Payment gateway instance.
	 * @return string
	 * @since 2.6.0
	 */
	public function mastercard_saved_new_payment_method_option_html( $html, $gateway ) {
		if ( MG_ENTERPRISE_ID !== $gateway->id ) {
			return $html;
		}

		return sprintf(
			'<p class="form-row woocommerce-SavedPaymentMethods-saveNew custom-class">
				<input id="wc-%1$s-new-payment-method" name="wc-%1$s-new-payment-method" type="checkbox" value="true" style="width:auto;" />
				<label for="wc-%1$s-new-payment-method" style="display:inline;">%2$s</label>
			</p>',
			esc_attr( $gateway->id ),
			esc_html__( 'Save to account', 'mastercard-gateway' )
		);
	}

	/**
	 * Displays a radio button for entering a new payment method (new CC details) instead of using a saved method.
	 *
	 * @param string              $html    Original HTML output.
	 * @param \WC_Payment_Gateway $gateway Payment gateway instance.
	 * @return string
	 */
	public function mg_get_new_payment_method_option_html( $html, $gateway ) {
		if ( MG_ENTERPRISE_ID !== $gateway->id ) {
			return $html;
		}

		$label = apply_filters(
			'woocommerce_payment_gateway_get_new_payment_method_option_html_label',
			$gateway->new_method_label ? $gateway->new_method_label : __( 'Use a new payment method', 'mastercard-gateway' ),
			$gateway
		);

		return sprintf(
			'<li class="woocommerce-SavedPaymentMethods-new custom-radio-option">
				<input id="wc-%1$s-payment-token-new" type="radio" name="wc-%1$s-payment-token" value="new" style="width:auto;" class="woocommerce-SavedPaymentMethods-tokenInput" />
				<label for="wc-%1$s-payment-token-new">%2$s</label>
			</li>',
			esc_attr( $gateway->id ),
			esc_html( $label )
		);
	}

	/**
	 * Override the default WC credit card token class with our custom class.
	 *
	 * @param string $token_class The class name WC wants to use.
	 * @param string $type        The token type (e.g., 'cc').
	 * @return string The class to use for the given token type.
	 */
	public static function override_mg_token_class( $token_class, $type ) {
		if ( 'cc' === strtolower( $type ) ) {
			return \Fingent\Mastercard\Core\PaymentTokenCC::class;
		}
		return $token_class;
	}

	/**
	 * Get a nice name for credit card providers.
	 *
	 * @since  2.6.0
	 * @param  array<string, string> $labels Credit card type labels.
	 * @return array<string, string>
	 */
	public function mg_get_credit_card_type_label( $labels ) {
		$labels['mastercard']       = __( 'MasterCard', 'mastercard-gateway' );
		$labels['visa']             = __( 'Visa', 'mastercard-gateway' );
		$labels['discover']         = __( 'Discover', 'mastercard-gateway' );
		$labels['american express'] = __( 'American Express', 'mastercard-gateway' );
		$labels['cartes bancaires'] = __( 'Cartes Bancaires', 'mastercard-gateway' );
		$labels['diners']           = __( 'Diners', 'mastercard-gateway' );
		$labels['jcb']              = __( 'JCB', 'mastercard-gateway' );

		return $labels;
	}
}
