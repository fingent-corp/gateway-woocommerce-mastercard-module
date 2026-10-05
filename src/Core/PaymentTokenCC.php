<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * WooCommerce credit card payment token for Mastercard Gateway.
 *
 * @package Fingent\Mastercard\Core
 */

namespace Fingent\Mastercard\Core;

use WC_Payment_Token_CC;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce Credit Card Payment Token.
 *
 * Representation of a payment token for credit cards.
 *
 * @class       Mastercard_Payment_Token_CC
 * @since       1.5.0
 */
class PaymentTokenCC extends WC_Payment_Token_CC {

	/**
	 * Token Type String.
	 *
	 * @var string
	 */
	protected $type = 'cc';

	/**
	 * Stores Credit Card payment token data.
	 *
	 * @var array<string, string>
	 */
	protected $extra_data = array(
		'last4'          => '',
		'expiry_year'    => '',
		'expiry_month'   => '',
		'card_type'      => '',
		'funding_method' => '',
	);

	/**
	 * Define additional token fields.
	 *
	 * @since  1.5.0
	 * @return void
	 */
	protected function populate_defaults(): void {
		/**
		 * Parent defaults callback.
		 *
		 * @var callable
		 */
		$parent_populate = array( parent::class, 'populate_defaults' );
		$parent_populate();

		$this->set_prop( 'funding_method', '' );
	}

	/**
	 * Returns the card funding type (CREDIT or DEBIT).
	 *
	 * @since  1.5.0
	 * @param  string $context What the value is for. Valid values are view and edit.
	 * @return string Funding Method
	 */
	public function get_funding_method( $context = 'view' ) {
		return $this->get_prop( 'funding_method', $context );
	}

	/**
	 * Set the card funding type (CREDIT or DEBIT).
	 *
	 * @since 1.5.0
	 * @param string $value Credit card funding type (CREDIT or DEBIT).
	 * @return void
	 */
	public function set_funding_method( $value ): void {
		$this->set_prop( 'funding_method', wc_clean( $value ) );
	}

	/**
	 * Get type to display to user.
	 *
	 * @since  2.6.0
	 * @param  string $deprecated Deprecated since WooCommerce 3.0.
	 * @return string
	 */
	public function get_display_name( $deprecated = '' ) {
		$expiry_year = (string) $this->get_expiry_year();
		$expiry_tail = substr( $expiry_year, 2 );
		/* translators: 1: credit card type 2: last 4 digits 3: expiry month 4: expiry year */
		$label = __( '%1$s ending in %2$s (expires %3$s/%4$s)', 'mastercard-gateway' );

		return (string) sprintf(
			is_string( $label ) ? $label : '%1$s ending in %2$s (expires %3$s/%4$s)',
			wc_get_credit_card_type_label( $this->get_card_type() ),
			$this->get_last4(),
			$this->get_expiry_month(),
			false === $expiry_tail ? '' : $expiry_tail
		);
	}
}
