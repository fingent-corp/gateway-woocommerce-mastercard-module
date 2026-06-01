<?php
namespace Fingent\Mastercard\Emails;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Payment Request Email Class
 *
 * Handles sending the custom "Payment Request" email for the Mastercard
 * Pay-by-Link feature. Extends WooCommerce's core WC_Email class to leverage
 * existing email sending functionality, templates, and settings.
 *
 * @package Fingent\Mastercard\Emails
*/

class PaymentRevokedEmail extends \WC_Email {
    /**
     * The generated payment link URL.
     *
     * @var string
     */
    public $payment_link;

    /**
     * Payment link expiry date/time returned from API.
     *
     * @var string
     */
    public $expiry_date_time;

     /**
     * Number of allowed payment attempts (from API response).
     *
     * @var int
     */
    public $allowed_attempts;

    /**
     * Constructor.
     *
     * Sets email properties such as ID, subject, description, heading,
     * and initializes the WooCommerce email parent class.
     */

    public function __construct() {
        $this->id             = MG_ENTERPRISE_ID;
        $this->title          = PAY_BY_LINK_REVOKED_EMAIL_HEADING; 
        $this->description    = PAY_BY_LINK_REVOKED_EMAIL_DESCRIPTION;
        $this->heading        = PAY_BY_LINK_REVOKED_EMAIL_SUBJECT;
        $this->subject        = 'Payment Link Revoked from ' . get_bloginfo( 'name' );
        $this->customer_email = true;
        
        parent::__construct();
    }

    /**
     * Trigger the sending of this email.
     *
     * Populates the email with order details. If no order ID is supplied,
     * a dummy order is created (mainly for preview/testing purposes).
     *
     * @param int $order_id Optional. WooCommerce order ID.
     * @return bool True when send succeeds, false otherwise.
     */
    public function trigger( $order_id = 0 ) {
        if ( $order_id ) {
            $this->object    = wc_get_order( $order_id );
            $this->recipient = $this->object->get_billing_email();
        } else {
            $this->object = wc_create_order();
            $this->object->add_product( wc_get_product( 1 ), 1 ); 
            $this->object->calculate_totals();
            $this->recipient = $this->object->get_billing_email();
        }

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            return false;
        }

        return (bool) $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
    }

    /**
     * Get the HTML content for the email.
     *
     * Loads the WooCommerce email template and passes required variables
     * for rendering the email in HTML format.
     *
     * @return string Rendered HTML email content.
    */
    public function get_content_html() {
        return wc_get_template_html(
            PAY_BY_LINK_REVOKED_TEMPLATE,
            array(
                'order'           => $this->object,
                'email_heading'   => $this->get_heading(),
                'email'           => $this,
                'payment_link'    => $this->payment_link,
                'expiry_date_time'=> $this->expiry_date_time,
                'allowed_attempts'=> $this->allowed_attempts,
                ),
            '',
            PAY_BY_LINK_BASE
        );
    }

    /**
     * Get the plain text content for the email.
     *
     * Renders the plain text version of the email using the corresponding template.
     *
     * @return string Rendered plain text email content.
     */
    public function get_content_plain() {
        return wc_get_template_html(
            PAY_BY_LINK_REVOKED_TEMPLATE,
            array(
                'order'            => $this->object,
                'email_heading'    => $this->get_heading(),
                'email'            => $this,
                'payment_link'     => $this->payment_link,
                'expiry_date_time' => $this->expiry_date_time,
                'allowed_attempts' => $this->allowed_attempts,
            ),
            '',
            PAY_BY_LINK_BASE
        );
    }
}
