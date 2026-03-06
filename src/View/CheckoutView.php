<?php
namespace Fingent\Mastercard\View;

use WC_Order;
use Fingent\Mastercard\Core\View;
use Fingent\Mastercard\Core\PaymentGatewayCC;
use Fingent\Mastercard\Model\MastercardGateway;
use Fingent\Mastercard\Controller\FrontendController;
use Fingent\Mastercard\Controller\UtilityController;
use Fingent\Mastercard\Controller\PaymentController;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CheckoutView
 *
 * Responsible for rendering the appropriate checkout view based on
 * the selected Mastercard integration method.
 */
class CheckoutView {
    protected $order;
    protected $gateway;
    protected $frontend;
    protected $payment;
    protected $utility;
    protected $args;

    /**
     * CheckoutView constructor.
     *
     * @param WC_Order $order WooCommerce order instance.
     * @param array    $args  Additional arguments passed to the view.
     */
    public function __construct( $order, $args ) {
        $this->order    = $order;
        $this->args     = $args;

        // Initialize necessary controllers and models
        $this->gateway  = MastercardGateway::get_instance();
        $this->frontend = FrontendController::get_instance();
        $this->utility  = UtilityController::get_instance();      
        $this->payment  = PaymentController::get_instance();
    }

    /**
     * Render the checkout view based on the selected payment method.
     */
    public function render() {
        switch ( $this->args[ 'method' ] ) {
            case 'session':
                // Determine if tokenization should be displayed
                $display_tokenization = $this->gateway->supports( 'tokenization' ) 
                    && is_checkout() 
                    && $this->gateway->saved_cards;

                // Prepare credit card form
                $cc_form            = new PaymentGatewayCC();
                $cc_form->id        = $this->gateway->id;
                $cc_form->supports  = $this->filter_supported_features();

                // Render Hosted Session view
                View::render( 'checkout/hosted-session', array(
                    'order'                => $this->order,
                    'cc_form'              => $cc_form,
                    'gateway'              => $this->gateway,
                    'frontend'             => $this->frontend,
                    'utility'              => $this->utility,
                    'payment'              => $this->payment,
                    'display_tokenization' => $display_tokenization
                ) );
                break;

            // Case for Hosted Checkout (redirect to Mastercard payment page)
            case 'checkout':
                View::render( 'checkout/hosted-checkout', array(
                    'order'   => $this->order,
                    'gateway' => $this->gateway,
                    'utility' => $this->utility,
                ) );
                break;

            // Case for Hosted Checkout (redirect to Mastercard payment page)
            case 'admin':
                View::render( 'admin/preview-form', array(
                    'gateway' => $this->gateway,
                ) );
                break;    

            // Default fallback (e.g., 3D Secure form)
            default:
                View::render( '3dsecure/form', array(
                    'order' => $this->order,
                    'args'  => $this->args
                ) );
                break;
        }
    }

    /**
     * Filters supported features if saved cards are disabled.
     *
     * @return array Filtered supported features.
     */
    protected function filter_supported_features() {
        $supported = $this->gateway->supports;

        // Remove tokenization if saved cards are not enabled
        if ( false === $this->gateway->saved_cards ) {
            foreach ( array_keys( $supported, 'tokenization', true ) as $key ) {
                unset( $supported[ $key ] );
            }
        }

        return $supported;
    }
}
