<?php
namespace Fingent\Mastercard\Core;

use WC_Payment_Gateway_CC;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Show the payment form for Mastercard Gateway.
 */
class PaymentGatewayCC extends WC_Payment_Gateway_CC {
	/**
	 * Outputs fields for entering credit card information.
	 */
	public function form() {
		wp_enqueue_script( 'wc-credit-card-form' );

		$fields       = array();
		$allowed_html = array(
			'p'     => array(
				'class' => true,
			),
			'label' => array(
				'for' => true,
			),
			'input' => array(
				'id'             => true,
				'class'          => true,
				'inputmode'      => true,
				'readonly'       => true,
				'autocomplete'   => true,
				'autocorrect'    => true,
				'autocapitalize' => true,
				'spellcheck'     => true,
				'type'           => true,
			),
			'span' => array(            
				'class' => true,
			),
		);

		$cvc_field = '<p class="form-row form-row-wide">
			<label for="' . esc_attr( MG_ENTERPRISE_ID ) . '-card-cvc">' . esc_html__( 'Security Code', MG_ENTERPRISE_TEXTDOMAIN ) . '&nbsp;<span class="required">*</span></label>
			<input id="' . esc_attr( MG_ENTERPRISE_ID ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" readonly="readonly" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" ' . $this->field_name( 'card-cvc' ) . ' style="width:100px" /></p>';

		$default_fields = array(
			'card-number-field'       => '<p class="form-row form-row-wide">
				<label for="' . esc_attr( MG_ENTERPRISE_ID ) . '-card-number">' . esc_html__( 'Card number', MG_ENTERPRISE_TEXTDOMAIN ) . '&nbsp;<span class="required">*</span></label>
				<input id="' . esc_attr( MG_ENTERPRISE_ID ) . '-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" readonly="readonly" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" ' . $this->field_name( 'card-number' ) . ' /></p>',
			'card-expiry-month-field' => '<p class="form-row form-row-first">
				<label for="' . esc_attr( MG_ENTERPRISE_ID ) . '-card-expiry-month">' . esc_html__( 'Expiry (MM)', MG_ENTERPRISE_TEXTDOMAIN ) . '&nbsp;<span class="required">*</span></label>
				<input id="' . esc_attr( MG_ENTERPRISE_ID ) . '-card-expiry-month" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" readonly="readonly" autocomplete="cc-exp-month" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" ' . $this->field_name( 'card-expiry-month' ) . ' /></p>',
			'card-expiry-year-field'  => '<p class="form-row form-row-last">
				<label for="' . esc_attr( MG_ENTERPRISE_ID ) . '-card-expiry-year">' . esc_html__( 'Expiry (YY)', MG_ENTERPRISE_TEXTDOMAIN ) . '&nbsp;<span class="required">*</span></label>
				<input id="' . esc_attr( MG_ENTERPRISE_ID ) . '-card-expiry-year" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" readonly="readonly" autocomplete="cc-exp-year" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" ' . $this->field_name( 'card-expiry-year' ) . ' /></p>',
		);

		$default_fields['card-cvc-field'] = $cvc_field;

		$fields = wp_parse_args( $fields, apply_filters( 'woocommerce_credit_card_form_fields', $default_fields, MG_ENTERPRISE_ID ) );	
		?>

		<fieldset id="wc-<?php echo esc_attr( MG_ENTERPRISE_ID ); ?>-cc-form" class="wc-credit-card-form wc-payment-form">
			<?php do_action( 'woocommerce_credit_card_form_start', MG_ENTERPRISE_ID ); ?>
			<?php
			foreach ( $fields as $field ) {
				echo wp_kses( $field, $allowed_html );
			}
			?>
			<?php do_action( 'woocommerce_credit_card_form_end', MG_ENTERPRISE_ID ); ?>
			<div class="clear"></div>
		</fieldset>
		<?php

		if ( $this->get_tokens() ) {
			foreach ( $this->get_tokens() as $token ) {
				echo '<fieldset class="token-cvc" id="token-cvc-' . esc_attr( $token->get_id() ) . '">
					<p class="form-row form-row-wide">
						<label for="' . esc_attr( MG_ENTERPRISE_ID ) . '-saved-card-cvc-' . esc_attr( $token->get_id() ) . '">' . esc_html__( 'Security Code', MG_ENTERPRISE_TEXTDOMAIN ) . '&nbsp;<span class="required">*</span></label>
						<input id="' . esc_attr( MG_ENTERPRISE_ID ) . '-saved-card-cvc-' . esc_attr( $token->get_id() ) . '" class="input-text wc-credit-card-form-card-cvc" readonly="readonly" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" style="width:100px" />
					</p>
				</fieldset>';
			}
		}
	}
}
