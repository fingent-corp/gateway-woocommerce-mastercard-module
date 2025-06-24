<?php
/**
 * Class Mastercard_Payment_Token_CC file.
 *
 * @package Mastercard
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Credit Card Payment Token.
 *
 * Representation of a payment token for credit cards.
 *
 * @class       Mastercard_Payment_Token_CC
 * @since       1.5.0.1
 */
class Mastercard_Payment_Token_CC extends WC_Payment_Token_CC {

    /**
     * Token Type String.
     *
     * @var string
     */
    protected $type = 'cc';

    /**
     * Stores Credit Card payment token data.
     *
     * @var array
     */
    protected $extra_data = array(
        'last4'          => '',
        'expiry_year'    => '',
        'expiry_month'   => '',
        'card_type'      => '',
        'funding_method' => '',
    );

    /**
     * Define additional fields
     * 
     * @since  1.5.0.1
     */
    protected function populate_defaults() {
        parent::populate_defaults();

        $this->set_prop( 'funding_method', '' );
    }

    /**
     * Returns the card funding type (CREDIT or DEBIT).
     *
     * @since  1.5.0.1
     * @param  string $context What the value is for. Valid values are view and edit.
     * @return string Funding Method
     */
    public function get_funding_method( $context = 'view' ) {
        return $this->get_prop( 'funding_method', $context );
    }

    /**
     * Set the card funding type (CREDIT or DEBIT).
     *
     * @since 1.5.0.1
     * @param string $type Credit card funding type (CREDIT or DEBIT).
     */
    public function set_funding_method( $value ) {
        $this->set_prop( 'funding_method', wc_clean( $value ) );
    }
}