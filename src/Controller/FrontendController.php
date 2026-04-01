<?php
namespace Fingent\Mastercard\Controller;

use WC_Order;
use WC_Order_Item_Fee;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Controller\PaymentController;
use Fingent\Mastercard\Helper\CheckoutBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class FrontendController {
	/**
	 * Singleton instance.
	 *
	 * @var FrontendController|null
	 */
	private static ?FrontendController $instance = null;
	
	/**
	 * UtilityController.
	 *
	 * @var bool
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
	public static function get_instance():FrontendController {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * FrontendController constructor.
	 * 
	 * @throws Exception If there's a problem connecting to the gateway.
	 */
	public function __construct() {
		$this->gateway = MastercardGateway::get_instance();
		$this->utility = UtilityController::get_instance();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_footer', array( $this, 'refresh_handling_fees_on_checkout' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_js_extra_attribute' ), 10 );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_gateway_scripts' ), 10 );
		add_action( 'template_redirect', array( $this, 'define_default_payment_gateway' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'clear_session_storage' ), 10 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_handling_fee' ), 10, 1 );	
		add_filter( 'woocommerce_saved_payment_methods_list', array( $this, 'remove_saved_mastercard_methods' ), 10, 2 );
		add_filter( 'woocommerce_payment_gateway_get_saved_payment_method_option_html', array( $this, 'mastercard_saved_payment_method_option_html' ), 10, 3 );
		add_filter( 'woocommerce_payment_gateway_save_new_payment_method_option_html', array( $this, 'mastercard_saved_new_payment_method_option_html' ), 10, 3 );
		add_filter( 'woocommerce_payment_gateway_get_new_payment_method_option_html', array( $this, 'mg_get_new_payment_method_option_html' ), 10, 2 );
		add_filter( 'woocommerce_payment_token_class', array( $this, 'override_mg_token_class' ), 10, 2 );
		add_filter( 'woocommerce_credit_card_type_labels', array( $this, 'mg_get_credit_card_type_label' ), 10, 2 );

		$ajax = array(
			'get_surcharge_amount'           => 'get_surcharge_amount',
			'update_selected_payment_method' => 'update_selected_payment_method'
		);

		foreach( $ajax as $handler => $function_name ) {
			add_action( 'wp_ajax_' . $handler, array( $this, $function_name . '_handler' ) );
			add_action( 'wp_ajax_nopriv_' . $handler, array( $this, $function_name . '_handler' ) );
		}

		if( ! is_admin() ) {
			// set_exception_handler( array( $this, 'exception_handler' ) );
		}
	}

	/**
	 * Clear output bufffer.
	 *
	 * @version 1.0
	 * @package Helpfie
	 */
	public function load_textdomain() {
		$locale =  $this->gateway->get_option( 'locale' );
		$mofile = plugin_dir_path( MG_ENTERPRISE_MAIN_FILE ) . 'languages/mastercard-gateway-' . $locale . '.mo';
		
		if ( file_exists( $mofile ) ) {
			load_textdomain( 'mastercard-gateway', $mofile );
		} else {
			load_plugin_textdomain(
				MG_ENTERPRISE_TEXTDOMAIN,
				false,
				trailingslashit( dirname( plugin_basename( MG_ENTERPRISE_MAIN_FILE ) ) ) . 'i18n/'
			);
		}
	}

	/**
	 * This function is responsible for including the necessary payment gateway scripts.
	 *
	 * @return void
	 */
	public function payment_gateway_scripts() {
		$order_id = get_query_var( 'order-pay' );
		$order    = new WC_Order( $order_id ); 

		if ( $order->get_payment_method() !== $this->gateway->id ) {
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

			wp_localize_script( 'woocommerce_mastercard_hosted_session', 'mgParams',
				array( 
					'gatewayId'          => MG_ENTERPRISE_ID,
					'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
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
		$scripts = array( $this->utility->get_hosted_checkout_js() );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				if ( false !== strpos( $tag, $script ) ) {
					return str_replace( 
						' src', 
						' async data-error="errorCallback" data-beforeRedirect="befroreRedirctCallback" data-afterRedirect="afterRedirectCallback" data-complete="completeCallback" src', 
						$tag 
					);
				}
			}
		}
		return $tag;
	}

		/**
	 * Refreshes the handling fees on the checkout page dynamically.
	 * 
	 * This function is typically hooked into WooCommerce's AJAX or checkout update events.
	 * It ensures that handling fees are recalculated whenever the checkout page is refreshed,
	 * preventing outdated fee calculations due to changes in cart contents or other conditions.
	 */
	public function refresh_handling_fees_on_checkout() {
		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			static $executed = false;

			if ( $executed ) {
				return;
			}

        	$executed      = true;
        	$amount_type   = $this->gateway->get_option( HF_AMT_TYPE_TXT );
        	$handling_fee  = $this->gateway->get_option( HF_AMT_TXT ) ? $this->gateway->get_option( HF_AMT_TXT ) : 0;

			if ( HF_PERCENTAGE === $amount_type ) {
				$surcharge = (float)( WC()->cart->cart_contents_total ) * ( (float) $handling_fee / 100 );
			} else {
				$surcharge = $handling_fee;
			}
	        ?>
	        <script type="text/javascript">
				const handlingText = '<?php echo sanitize_title( !empty( $this->gateway->get_option( HF_TEXT ) ) ? $this->gateway->get_option( HF_TEXT ) : HF_DEFAULT_TEXT ); ?>';
				const handlingFeeWrapper = '<div class="wc-block-components-totals-item wc-block-components-totals-fees wc-block-components-totals-fees__<?php echo sanitize_title( !empty( $this->gateway->get_option( HF_TEXT ) ) ? $this->gateway->get_option( HF_TEXT ) : HF_DEFAULT_TEXT ); ?>"><span class="wc-block-components-totals-item__label"><?php echo !empty( $this->gateway->get_option( HF_TEXT ) ) ? $this->gateway->get_option( HF_TEXT ) : HF_DEFAULT_TEXT; ?></span><span class="wc-block-formatted-money-amount wc-block-components-formatted-money-amount wc-block-components-totals-item__value"><?php echo wc_price( $surcharge ); ?></span><div class="wc-block-components-totals-item__description"></div></div>';

				jQuery(function($) {
					// Detect when payment method is changed
					$( document ).on( 'change', 'input[name="payment_method"]', function() { 
						$( document.body ).trigger( "update_checkout" );
					});
				});
			</script>
	        <?php
		}
	}

	/**
	 * Define the default payment gateway for the checkout process.
	 *
	 * This function sets the default payment method when a customer visits
	 * the checkout page. It ensures the preferred gateway (e.g., Simplify Payments)
	 * is pre-selected to streamline the checkout experience.
	 */
	public function define_default_payment_gateway() {    
        if( is_checkout() && ! is_wc_endpoint_url() ) {
            $payment_gateways = WC()->payment_gateways->get_available_payment_gateways(); 
            $first_gateway    = reset( $payment_gateways );

            WC()->session->set( 'chosen_payment_method', $first_gateway->id );
        } elseif( is_wc_endpoint_url() && get_query_var( 'order-pay' ) ) { 
            $order_id       = esc_attr( get_query_var( 'order-pay' ) );
            $order          = wc_get_order( $order_id );
            $payment_method = WC()->session->get( 'chosen_payment_method' );
            if( $payment_method !== MG_ENTERPRISE_ID ) {
                $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
                $order->set_payment_method( MG_ENTERPRISE_ID );
                $order->set_payment_method_title( $available_gateways[MG_ENTERPRISE_ID]->get_title() );
                $order->save();
                WC()->session->set( 'chosen_payment_method', MG_ENTERPRISE_ID );
            }
        }
    }

	/**
	 * This function clears the session storage after orders placed by mastercard payment gateway plugin.
	 *
	 * @return void
	 */
	public function clear_session_storage( $order_id ) {
		if ( ! $order_id ) {
			return;
		}
	
		$order = wc_get_order( $order_id );
	
		if ( $order instanceof WC_Order && $order->get_payment_method() === MG_ENTERPRISE_ID ) {
			wp_enqueue_script(
				'clear-session-storage',
				UtilityController::plugin_url() . '/assets/js/clear-session.js', 
				array(),
				MG_ENTERPRISE_MODULE_VERSION,
				true
			);
		}
	}

	/**
	 * Removes user-added Mastercard payment methods from the WooCommerce saved payment methods list.
	 *
	 * This function loops through the saved payment methods, filters out any method
	 * associated with the MG_ENTERPRISE_ID, and removes empty categories if no methods remain.
	 *
	 * @param array $saved_methods An array of saved payment methods categorized by type.
	 * @param int   $customer_id   The ID of the current customer.
	 *
	 * @return array The filtered list of saved payment methods without Mastercard (MPGS).
	 */
	public function remove_saved_mastercard_methods( $saved_methods, $customer_id ) {
		if ( empty( $saved_methods ) || ! is_array( $saved_methods ) ) {
			return $saved_methods;
		}
	
		foreach ( $saved_methods as $key => $methods ) {
			$saved_methods[$key] = array_filter( $methods, function ( $method ) {
				return empty( $method['method']['gateway'] ) || $method['method']['gateway'] !== MG_ENTERPRISE_ID;
			});
	
			if ( empty( $saved_methods[$key] ) ) {
				unset( $saved_methods[$key] );
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
	 * @return void 
	 */
	public function update_selected_payment_method_handler() {
	    if ( isset( $_POST['payment_method'] ) ) {
	    	$payment_method = sanitize_text_field( $_POST['payment_method'] ); 
	        WC()->session->set( 'chosen_payment_method', $payment_method );
	        WC()->cart->calculate_totals();
	        wp_send_json_success();
	    } else {
	        wp_send_json_error();
	    }
	}

	/**
	 * Calculates and returns the surcharge amount for a transaction.
	 *
	 * This method determines the surcharge based on predefined rules,
	 * such as a fixed percentage or flat fee. It is typically used
	 * to add additional costs to a payment transaction.
	 *
	 * @return float The calculated surcharge amount.
	 */
	public function get_surcharge_amount_handler() {		
		$order_id          = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : null;
		$order             = wc_get_order( $order_id );
		$order_builder     = new CheckoutBuilder( $order );
		$surcharge_enabled = $this->gateway->get_option( SUR_ENABLED );
		$card_type 		   = strtoupper( $this->gateway->get_option( SUR_CARD_TYPE ) );
		$funding_method    = isset( $_POST['funding_method'] ) ? sanitize_text_field( wp_unslash( $_POST['funding_method'] ) ) : null;
		$token             = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : null;
		$source_type       = isset( $_POST['source_type'] ) ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : null;

		if ( ! $order_id || $card_type !== $funding_method ) {
			if ( empty( $token ) && empty( $source_type ) ) {
				$return = array(
					'message' => 'Unfortunately, we couldn’t update the order total at this time.',
					'code'    => 400
				);
				wp_send_json( $return );
			}
		}
		
		$order = wc_get_order( $order_id );

		if( $order && 'yes' === $surcharge_enabled ) {

			$amount_type    = $this->gateway->get_option( SUR_AMT_TYPE_TXT );
			$surcharge_fee  = $this->gateway->get_option( SUR_AMT_TXT ) ? $this->gateway->get_option( SUR_AMT_TXT ) : 0;
			$surcharge_text = $this->gateway->get_option( SUR_TEXT );
			$surcharge_text = !empty( $surcharge_text ) ? $surcharge_text : __( 'Surcharge', MG_ENTERPRISE_TEXTDOMAIN );

			if ( HF_PERCENTAGE === $amount_type ) {
				$surcharge = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / ( 100 - (float) $surcharge_fee) );
			} else {
				$surcharge = (float) $surcharge_fee;
			}

			$surcharge = $order_builder->formattedPrice( $surcharge );
	        $fee       = new WC_Order_Item_Fee();
		    $fee->set_name( $surcharge_text );
		    $fee->set_amount( $surcharge );
		    $fee->set_total( $surcharge );

		    // Add the fee to the order
		    $order->add_item( $fee );

		    // Save the order
		    $order->calculate_totals( false );
		    $order->update_meta_data( '_mpgs_surcharge_fee', $surcharge );
		    $order->save();

		    $return = array(
			    'code'        => 200,
			    'order_total' => wc_price( $order->get_total() )
			);

			wp_send_json( $return );
		}
	}

	/**
	 * Adds a handling fee to the WooCommerce cart calculation.
	 * 
	 * This ensures that the handling fee is added during the cart calculation process.
	 */
	public function add_handling_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $chosen_gateway = WC()->session->get( 'chosen_payment_method' );

        if ( ! empty( $chosen_gateway ) ) {
			if ( isset( $this->gateway->hf_enabled ) && 'yes' === $this->gateway->hf_enabled && MG_ENTERPRISE_ID === $chosen_gateway ){
				$handling_text = $this->gateway->get_option( HF_TEXT );
				$handling_text = !empty( $handling_text ) ? $handling_text : HF_DEFAULT_TEXT;
				$amount_type   = $this->gateway->get_option( HF_AMT_TYPE_TXT );
				$handling_fee  = $this->gateway->get_option( HF_AMT_TXT ) ? $this->gateway->get_option( HF_AMT_TXT ) : 0;

				if ( HF_PERCENTAGE === $amount_type ) {
					$surcharge = (float)( WC()->cart->cart_contents_total ) * ( (float) $handling_fee / 100 );
				} else {
					$surcharge = $handling_fee;
				}

			    WC()->cart->add_fee( $handling_text, $surcharge, true, '' );
			}
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
	 * @param WC_Order $order The WooCommerce order object containing order details.
	 */
	public function display_surcharge_message( $order ) {
		$message             = '';
		$surcharge_enabled   = $this->gateway->get_option( SUR_ENABLED );
		$surcharge_fee       = (float) $this->gateway->get_option( SUR_AMT_TXT );
		if ( 'yes' === $surcharge_enabled && $surcharge_fee > 0 ) {
			$message = sprintf(
				
				__(
					'<div class="mg-surcharge-notice-banner"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg><div class="mg-surcharge-notice-content">%1$s</div></div>',
					MG_ENTERPRISE_TEXTDOMAIN
				),
				$this->get_surcharge_message( $order ),
			);
		}


		return $message;
	}

	/**
	 * Generates and returns a surcharge message for the provided order.
	 *
	 * @param WC_Order $order The WooCommerce order object.
	 * @return string The surcharge message, typically displayed to inform
	 *                the customer about additional charges applied to their order.
	 *
	 * This method is commonly used to calculate and communicate surcharges
	 * (e.g., credit card fees, payment gateway fees) applied during checkout.
	 * Ensure proper handling of order data and formatting for customer clarity.
	 */
	public function get_surcharge_message( $order ) {
		$order_builder       = new CheckoutBuilder( $order );
		$amount_type         = $this->gateway->get_option( SUR_AMT_TYPE_TXT );
		$surcharge_fee       = $this->gateway->get_option( SUR_AMT_TXT ) ? $this->gateway->get_option( SUR_AMT_TXT ) : 0;
		$mg_card_type        = $this->gateway->get_option( SUR_CARD_TYPE );
		$translated_card     = __( $mg_card_type, MG_ENTERPRISE_TEXTDOMAIN );
		$surcharge_card_type = sprintf( __( '%s Card', MG_ENTERPRISE_TEXTDOMAIN ), $translated_card );
	
		if ( HF_FIXED === $amount_type ) {
			$default_msg = __(
				'When using a {{MG_CARD_TYPE}} an additional surcharge of <b>{{MG_SUR_AMT}}</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.',
				MG_ENTERPRISE_TEXTDOMAIN
			);
		} else {
			$default_msg = SUR_DEFAULT_MSG; 
		}
	
		// Use saved message if set, otherwise use dynamic default
		$surcharge_message = $this->gateway->get_option( SUR_MSG ) ? $this->gateway->get_option( SUR_MSG ) : $default_msg;
	
		// Calculate surcharge
		if ( HF_PERCENTAGE === $amount_type ) {
			$surcharge = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / ( 100 - (float) $surcharge_fee ) );
		} else {
			$surcharge = $surcharge_fee;
		}
	
		$surcharge           = $order_builder->formattedPrice( $surcharge );
		$total_total         = (float) $order->get_total() + (float) $surcharge;
		$surcharge_fee_label = ( HF_PERCENTAGE === $amount_type ) ? $surcharge_fee . '%' : '';
	
		return str_replace(
			array( '{{MG_SUR_AMT}}', '{{MG_SUR_PCT}}', '{{MG_CARD_TYPE}}', '{{MG_TOTAL_AMT}}' ),
			array( wc_price( $surcharge ), $surcharge_fee_label, $surcharge_card_type, wc_price( $total_total ) ),
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
	 * @param WC_Order $order The WooCommerce order object containing the order details.
	 * @return string The surcharge confirmation html, typically displayed to inform
	 *                the customer about additional charges applied to their order.
	 */
	public function display_surcharge_confirmation_box( $order ) {
		$surcharge_text      = $this->gateway->get_option( SUR_TEXT );
		$surcharge_text      = !empty( $surcharge_text ) ? $surcharge_text : 'Surcharge';
		$amount_type         = $this->gateway->get_option( SUR_AMT_TYPE_TXT );
		$surcharge_fee       = $this->gateway->get_option( SUR_AMT_TXT ) ? $this->gateway->get_option( SUR_AMT_TXT ) : 0;
		$surcharge_card_type = __( $this->gateway->get_option( SUR_CARD_TYPE ), MG_ENTERPRISE_TEXTDOMAIN ) . ' ' . __( 'Card', MG_ENTERPRISE_TEXTDOMAIN );

		if ( HF_FIXED === $amount_type ) {
			 $default_msg = __( 'When using a {{MG_CARD_TYPE}} an additional surcharge of <b>{{MG_SUR_AMT}}</b> will be applied, bringing the total payable amount to <b>{{MG_TOTAL_AMT}}</b>.', MG_ENTERPRISE_TEXTDOMAIN );
		} else {
			$default_msg = SUR_DEFAULT_MSG;
		}

		$surcharge_message = $this->gateway->get_option( SUR_MSG ) ? $this->gateway->get_option( SUR_MSG ) : $default_msg;

		if ( HF_PERCENTAGE === $amount_type ) {
			$surcharge = (float) ( $order->get_total() ) * ( (float) $surcharge_fee / (100 - (float) $surcharge_fee) );
		} else {
			$surcharge = $surcharge_fee;
		}

		$total_total         = (float) $order->get_total() + (float) $surcharge;
		$surcharge_fee_label = ( HF_PERCENTAGE === $amount_type ) ? $surcharge_fee . '%' : '';
		$message             =  str_replace(
			array( '{{MG_SUR_AMT}}', '{{MG_SUR_PCT}}', '{{MG_CARD_TYPE}}', '{{MG_TOTAL_AMT}}' ),
			array( wc_price( $surcharge ), $surcharge_fee_label, $surcharge_card_type, wc_price( $total_total ) ),
			$surcharge_message
		);

		$order_html = sprintf(
			/* translators: 1. Order total text, 2. Order total amount, 3. Surcharge text, 4. Surcharge amount, 5. Grand total text, 6. Grant total. */
			__( '<ul><li><label>%1$s:</label> %2$s</li><li><label>%3$s:</label> %4$s</li><li><label>%5$s:</label> %6$s</li></ul>', MG_ENTERPRISE_TEXTDOMAIN ),
			apply_filters( 'mastercard_order_pay_order_total_text', __( 'Order Total', MG_ENTERPRISE_TEXTDOMAIN ) ),
			wc_price( $order->get_total() ),
			$surcharge_text,
			wc_price( $surcharge ),
			apply_filters( 'mastercard_order_pay_grand_total_text', __( 'Grand Total', MG_ENTERPRISE_TEXTDOMAIN ) ),
			wc_price( $total_total )
		);	

		return sprintf(
			/* translators: 1. Surcharge message, 2. Order total text, 3. Order total amount, 4. Surcharge text, 5. Surcharge amount, 6. Grand total text, 7. Grant total, 8. Confirm button text, 9. Cancel button text. */
			__( '<p>%1$s</p>%2$s<div class="mg_button_wrapper"><button type="button"class="wp-element-button wp-element-confirm-button">%3$s</button><a type="button"class="wp-element-button wp-element-cancel-button" href="%4$s">%5$s</a></div>', MG_ENTERPRISE_TEXTDOMAIN ),
			$message,
			$order_html,
			apply_filters( 'mastercard_order_pay_confirm_button_text', __( 'Confirm', MG_ENTERPRISE_TEXTDOMAIN ) ),
			esc_url( wc_get_checkout_url() ),
			apply_filters( 'mastercard_order_pay_cancel_button_text', __( 'Cancel', MG_ENTERPRISE_TEXTDOMAIN ) )
		);
	}

	/**
	 * Customize the HTML output for a saved Mastercard payment method.
	 *
	 * This function modifies how each saved Mastercard payment method is displayed
	 * on the WooCommerce checkout page. It builds a radio input and label for each
	 * saved token, allowing users to select one of their stored payment methods.
	 *
	 * @param string                        $html    The original HTML output.
	 * @param WC_Payment_Token              $token   The saved payment method token.
	 * @param WC_Payment_Gateway            $gateway The payment gateway instance.
	 *
	 * @return string Modified HTML output for the saved payment method option.
	 */
	public function mastercard_saved_payment_method_option_html( $html, $token, $gateway ) {
		$card_type = $token->get_meta( 'funding_method' ); 
		$html      = sprintf(
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
			esc_attr( $token->get_id() ),
			esc_html( $token->get_display_name() ),
			checked( $token->is_default(), true, false ),
			esc_attr( $card_type ) 
		);
	
		return $html;
	}

		/**
	 * Exception handler function.
	 *
	 * @param array $exception The exception data.
	 *
	 * @return void
	 */
	public function exception_handler( $exception ) {
		$message  = '<div class="wc-block-components-notice-banner is-error"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg><div class="wc-block-components-notice-banner__content"><ul><li>';
		$message .= sprintf(
			/* translators: %s: error message */
			__( 'Error: "%s"', MG_ENTERPRISE_TEXTDOMAIN ),
			$exception->getMessage()
		);
		$message .= '</li></ul></div></div>';

		echo wp_kses_post( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Generates and returns custom CSS variables for hosted checkout styles.
	 *
	 * Fetches saved UI styling from the WordPress options table, decodes
	 * them from JSON, and constructs a :root CSS block containing custom
	 * properties for surcharge notices, form fields, and inputs.
	 *
	 * @return string CSS variable declarations scoped to :root
	 */
	public function get_hosted_checkout_styles() {
	    $styles           = get_option( 'woocommerce_' . MG_ENTERPRISE_ID . '_style_defaults' ); 
	    $surcharge_styles = json_decode( $styles['surcharge_fee_style'] ?? '{}' );
	    $form_styles      = json_decode( $styles['payment_form_style'] ?? '{}' );
	    $input_styles     = json_decode( $styles['payment_input_style'] ?? '{}' );
	    $paybtn_styles    = json_decode( $styles['pay_button_style'] ?? '{}' );
	    $consent_style    = json_decode( $styles['consent_style'] ?? '{}' );
	    $confirm_style    = json_decode( $styles['confirm_button_style'] ?? '{}' );
	    $cancel_style     = json_decode( $styles['cancel_button_style'] ?? '{}' );

	    $get = function ( $object, $property ) {
	        return isset( $object->{$property} ) ? $object->{$property} : '';
	    };

	    return <<<CSS
			:root {
			    /* Surcharge Notice Styles */
			    --surcharge-bg-color: {$get($surcharge_styles, 'bgColor')};
			    --surcharge-border-color: {$get($surcharge_styles, 'borderColor')};
			    --surcharge-border-radius: {$get($surcharge_styles, 'borderRadius')};
			    --surcharge-font-family: {$get($surcharge_styles, 'fontFamily')};
			    --surcharge-font-color: {$get($surcharge_styles, 'fontColor')};
			    --surcharge-font-size: {$get($surcharge_styles, 'fontSize')};
			    --surcharge-font-weight: {$get($surcharge_styles, 'fontWeight')};
			    --surcharge-line-height: {$get($surcharge_styles, 'lineHeight')};

			    /* Payment Form Styles */
			    --form-bg-color: {$get($form_styles, 'bgColor')};
			    --form-border-color: {$get($form_styles, 'borderColor')};
			    --form-border-radius: {$get($form_styles, 'borderRadius')};
			    --form-font-family: {$get($form_styles, 'fontFamily')};
			    --form-font-color: {$get($form_styles, 'fontColor')};
			    --form-font-size: {$get($form_styles, 'fontSize')};
			    --form-font-weight: {$get($form_styles, 'fontWeight')};
			    --form-line-height: {$get($form_styles, 'lineHeight')};

			    /* Payment Input Field Styles */
			    --input-bg-color: {$get($input_styles, 'bgColor')};
			    --input-border-color: {$get($input_styles, 'borderColor')};
			    --input-border-radius: {$get($input_styles, 'borderRadius')};
			    --input-font-family: {$get($input_styles, 'fontFamily')};
			    --input-font-color: {$get($input_styles, 'fontColor')};
			    --input-font-size: {$get($input_styles, 'fontSize')};
			    --input-font-weight: {$get($input_styles, 'fontWeight')};
			    --input-line-height: {$get($input_styles, 'lineHeight')};

			    /* Pay Button Styles */
			    --paybtn-bg-color: {$get($paybtn_styles, 'bgColor')};
			    --paybtn-border-color: {$get($paybtn_styles, 'borderColor')};
			    --paybtn-border-radius: {$get($paybtn_styles, 'borderRadius')};
			    --paybtn-font-family: {$get($paybtn_styles, 'fontFamily')};
			    --paybtn-font-color: {$get($paybtn_styles, 'fontColor')};
			    --paybtn-font-size: {$get($paybtn_styles, 'fontSize')};
			    --paybtn-font-weight: {$get($paybtn_styles, 'fontWeight')};
			    --paybtn-line-height: {$get($paybtn_styles, 'lineHeight')};
			    --paybtn-bg-hover-color: {$get($paybtn_styles, 'hoverBgColor')};
			    --paybtn-border-hover-color: {$get($paybtn_styles, 'hoverBorderColor')};
			    --paybtn-font-hover-color: {$get($paybtn_styles, 'hoverFontColor')};

			    /* Pay Button Styles */
			    --consent-bg-color: {$get($consent_style, 'bgColor')};
			    --consent-border-color: {$get($consent_style, 'borderColor')};
			    --consent-border-radius: {$get($consent_style, 'borderRadius')};
			    --consent-font-family: {$get($consent_style, 'fontFamily')};
			    --consent-font-color: {$get($consent_style, 'fontColor')};
			    --consent-font-size: {$get($consent_style, 'fontSize')};
			    --consent-font-weight: {$get($consent_style, 'fontWeight')};
			    --consent-line-height: {$get($consent_style, 'lineHeight')};

			    /* Confirm Button Styles */
			    --confirm-bg-color: {$get($confirm_style, 'bgColor')};
			    --confirm-border-color: {$get($confirm_style, 'borderColor')};
			    --confirm-border-radius: {$get($confirm_style, 'borderRadius')};
			    --confirm-font-family: {$get($confirm_style, 'fontFamily')};
			    --confirm-font-color: {$get($confirm_style, 'fontColor')};
			    --confirm-font-size: {$get($confirm_style, 'fontSize')};
			    --confirm-font-weight: {$get($confirm_style, 'fontWeight')};
			    --confirm-line-height: {$get($confirm_style, 'lineHeight')};
			    --confirm-bg-hover-color: {$get($confirm_style, 'hoverBgColor')};
			    --confirm-border-hover-color: {$get($confirm_style, 'hoverBorderColor')};
			    --confirm-font-hover-color: {$get($confirm_style, 'hoverFontColor')};

			    /* Confirm Button Styles */
			    --cancel-bg-color: {$get($cancel_style, 'bgColor')};
			    --cancel-border-color: {$get($cancel_style, 'borderColor')};
			    --cancel-border-radius: {$get($cancel_style, 'borderRadius')};
			    --cancel-font-family: {$get($cancel_style, 'fontFamily')};
			    --cancel-font-color: {$get($cancel_style, 'fontColor')};
			    --cancel-font-size: {$get($cancel_style, 'fontSize')};
			    --cancel-font-weight: {$get($cancel_style, 'fontWeight')};
			    --cancel-line-height: {$get($cancel_style, 'lineHeight')};
			    --cancel-bg-hover-color: {$get($cancel_style, 'hoverBgColor')};
			    --cancel-border-hover-color: {$get($cancel_style, 'hoverBorderColor')};
			    --cancel-font-hover-color: {$get($cancel_style, 'hoverFontColor')};
			}
		CSS;
	}

	/**
	 * Dynamically loads Google Fonts used in Mastercard payment UI customization.
	 *
	 * This method retrieves the unique font families selected across various
	 * UI components (e.g., form, input, buttons) from stored WooCommerce options.
	 * It then constructs a single Google Fonts API URL to reduce HTTP requests,
	 * and enqueues it using WordPress' native `wp_enqueue_style()`.
	 *
	 * This ensures all selected fonts are available for rendering the admin preview
	 * and frontend hosted checkout styles consistently.
	 *
	 * @return void
	 */
	public function load_google_font_families() {
		$options = get_option( 'woocommerce_' . MG_ENTERPRISE_ID . '_style_defaults' ); 

		if( $options ) {
			$font_families = $this->utility->extract_unique_font_families( $options );

			if ( empty( $font_families ) ) {
		        return '';
		    }

    		$base_url    = 'https://fonts.googleapis.com/css2?';
		    $font_params = [];

		    foreach ( $font_families as $font ) {
		        $encoded_font  = str_replace( ' ', '+', $font );
		        $font_params[] = "family={$encoded_font}:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400";
		    }

		    $google_font = $base_url . implode( '&', $font_params ) . '&display=swap';

			wp_enqueue_style(
				'woocommerce-mastercard-google-font',
				$google_font,
				array(),
				null
			);
		}
	}

	/**
	 * Outputs a checkbox for saving a new payment method to the database.
	 *
	 * @since 2.6.0
	 */

	 public function mastercard_saved_new_payment_method_option_html( $html, $gateway ) {
		$html = sprintf(
			'<p class="form-row woocommerce-SavedPaymentMethods-saveNew custom-class">
				<input id="wc-%1$s-new-payment-method" name="wc-%1$s-new-payment-method" type="checkbox" value="true" style="width:auto;" />
				<label for="wc-%1$s-new-payment-method" style="display:inline;">%2$s</label>
			</p>',
			esc_attr( $gateway->id ),
			esc_html__( 'Save to account',MG_ENTERPRISE_TEXTDOMAIN )
		);
		return $html;
	}

	/**
	 * Displays a radio button for entering a new payment method (new CC details) instead of using a saved method.
	 * Only displayed when a gateway supports tokenization.
	 *
	 * 
	 */
	public function mg_get_new_payment_method_option_html( $html, $gateway ) {
		$label = apply_filters(
			'woocommerce_payment_gateway_get_new_payment_method_option_html_label',
			$gateway->new_method_label ? $gateway->new_method_label : __( 'Use a new payment method', MG_ENTERPRISE_TEXTDOMAIN ),
			$gateway
		);
	
		$html = sprintf(
			'<li class="woocommerce-SavedPaymentMethods-new custom-radio-option">
				<input id="wc-%1$s-payment-token-new" type="radio" name="wc-%1$s-payment-token" value="new" style="width:auto;" class="woocommerce-SavedPaymentMethods-tokenInput" />
				<label for="wc-%1$s-payment-token-new">%2$s</label>
			</li>',
			esc_attr( $gateway->id ),
			esc_html( $label )
		);
	
		return $html;
	}

	/**
	 * Override the default WC credit card token class with our custom class.
	 *
	 * @param string $class The class name WC wants to use.
	 * @param string $type  The token type (e.g., 'cc').
	 * @return string       The class to use for the given token type.
	*/
	public static function override_mg_token_class( $class, $type ) {
		if ( 'cc' === strtolower( $type ) ) {
			return \Fingent\Mastercard\Core\PaymentTokenCC::class;
		}
		return $class;
	}

	/**
	 * Get a nice name for credit card providers.
	 *
	 * @since  2.6.0
	 * @param  string $type Provider Slug/Type.
	 * @return string
	*/
	public function mg_get_credit_card_type_label( $labels ) {
		$labels['mastercard'] = __( 'MasterCard', MG_ENTERPRISE_TEXTDOMAIN );
		$labels['visa']       = __( 'Visa', MG_ENTERPRISE_TEXTDOMAIN );
		$labels['discover']   = __( 'Discover', MG_ENTERPRISE_TEXTDOMAIN );
		$labels['american express'] = __( 'American Express', MG_ENTERPRISE_TEXTDOMAIN );
		$labels['cartes bancaires'] = __( 'Cartes Bancaires', MG_ENTERPRISE_TEXTDOMAIN );
		$labels['diners']     = __( 'Diners', MG_ENTERPRISE_TEXTDOMAIN );
		$labels['jcb']        = __( 'JCB', MG_ENTERPRISE_TEXTDOMAIN );
	
		return $labels;
	}

	
}
