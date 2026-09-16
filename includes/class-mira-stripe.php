<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MiraStripe {

    private function get_secret_key() {
        $mode = get_option( 'mira_stripe_mode', 'test' );
        return $mode === 'live'
            ? get_option( 'mira_stripe_live_secret', '' )
            : get_option( 'mira_stripe_test_secret', '' );
    }

    /**
     * Webhook signing secret for the current mode.
     *
     * Test and live webhooks are separate endpoints in the Stripe dashboard
     * with different signing secrets, so we store one per mode. Falls back to
     * the legacy single `mira_stripe_webhook_secret` option for installs that
     * haven't re-saved settings since the split.
     */
    private function get_webhook_secret() {
        $mode   = get_option( 'mira_stripe_mode', 'test' );
        $option = $mode === 'live'
            ? 'mira_stripe_live_webhook_secret'
            : 'mira_stripe_test_webhook_secret';

        $secret = get_option( $option, '' );

        return $secret !== '' ? $secret : get_option( 'mira_stripe_webhook_secret', '' );
    }

    public function create_checkout_session( $event_id, $quantity, $donation_pence, $booking_id, $booking_reference, $customer_email = '' ) {
        $secret_key = $this->get_secret_key();
        if ( empty( $secret_key ) ) {
            return new WP_Error( 'no_stripe_key', __( 'Stripe API key not configured.', 'mira-event-list' ) );
        }

        $event = get_post( $event_id );
        if ( ! $event ) {
            return new WP_Error( 'no_event', __( 'Event not found.', 'mira-event-list' ) );
        }

        $ticket_price_pence = intval( round( floatval( get_post_meta( $event_id, '_ticket_price', true ) ) * 100 ) );

        $success_page_id = (int) get_option( 'mira_stripe_success_page_id', 0 );
        $success_base    = $success_page_id
            ? get_permalink( $success_page_id )
            : home_url( '/booking-complete/' );
        // Stripe replaces {CHECKOUT_SESSION_ID} server-side before redirect
        $success_url = add_query_arg( 'session_id', '{CHECKOUT_SESSION_ID}', $success_base );

        $cancel_url = get_post_type_archive_link( 'mira_event' ) ?: home_url( '/' );

        $line_items = array(
            array(
                'price_data' => array(
                    'currency'     => 'gbp',
                    'product_data' => array( 'name' => 'Ticket: ' . $event->post_title ),
                    'unit_amount'  => $ticket_price_pence,
                ),
                'quantity' => intval( $quantity ),
            ),
        );

        if ( $donation_pence > 0 ) {
            $line_items[] = array(
                'price_data' => array(
                    'currency'     => 'gbp',
                    'product_data' => array( 'name' => 'Donation' ),
                    'unit_amount'  => intval( $donation_pence ),
                ),
                'quantity' => 1,
            );
        }

        $body = array(
            'mode'        => 'payment',
            'submit_type' => 'book',
            'success_url' => $success_url,
            'cancel_url'  => $cancel_url,
            'line_items'  => $line_items,
            'metadata'    => array(
                'event_id'          => $event_id,
                'event_name'        => $event->post_title,
                'booking_id'        => $booking_id,
                'booking_reference' => $booking_reference,
            ),
            'payment_intent_data' => array(
                'description' => 'Tickets: ' . $event->post_title,
                'metadata'    => array(
                    'event_id'   => $event_id,
                    'event_name' => $event->post_title,
                ),
            ),
        );

        if ( ! empty( $customer_email ) ) {
            $body['customer_email'] = $customer_email;
        }

        $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => $body,
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data        = json_decode( wp_remote_retrieve_body( $response ), true );
        $status_code = (int) wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown Stripe error.', 'mira-event-list' );
            return new WP_Error( 'stripe_api_error', $message );
        }

        return $data;
    }

    /**
     * A reusable Stripe Payment Link for one event — a static Stripe-hosted
     * URL that needs no server round trip to load, used as an emergency
     * fallback when the normal AJAX booking flow is unavailable. Created
     * once via the API and cached as post meta, so it's automatic for every
     * event with no manual Stripe dashboard work. Quantity (1 up to the same
     * max the normal form allows) is adjustable on Stripe's own page.
     */
    public function get_or_create_payment_link( $event_id ) {
        $cached = get_post_meta( $event_id, '_stripe_payment_link_url', true );
        if ( $cached ) {
            return $cached;
        }

        $event = get_post( $event_id );
        if ( ! $event ) {
            return new WP_Error( 'no_event', __( 'Event not found.', 'mira-event-list' ) );
        }

        $ticket_price_pence = intval( round( floatval( get_post_meta( $event_id, '_ticket_price', true ) ) * 100 ) );
        if ( $ticket_price_pence <= 0 ) {
            return new WP_Error( 'no_price', __( 'No ticket price set for this event.', 'mira-event-list' ) );
        }

        $max_tickets = (int) get_post_meta( $event_id, '_max_tickets', true );
        $qty_max     = $max_tickets > 0 ? min( 10, $max_tickets ) : 10;

        $price = $this->api_request( 'prices', array(
            'currency'     => 'gbp',
            'unit_amount'  => $ticket_price_pence,
            'product_data' => array( 'name' => 'Ticket: ' . $event->post_title ),
        ) );
        if ( is_wp_error( $price ) ) {
            return $price;
        }

        $link = $this->api_request( 'payment_links', array(
            'line_items' => array(
                array(
                    'price'               => $price['id'],
                    'quantity'            => 1,
                    'adjustable_quantity' => array(
                        'enabled' => 'true',
                        'minimum' => 1,
                        'maximum' => $qty_max,
                    ),
                ),
            ),
            'metadata' => array(
                'event_id'   => $event_id,
                'event_name' => $event->post_title,
                'source'     => 'backup_payment_link',
            ),
            'after_completion' => array(
                'type'                 => 'hosted_confirmation',
                'hosted_confirmation'  => array(
                    'custom_message' => "Thanks! We'll email your ticket shortly. If you don't hear from us within a few hours, please email " . get_option( 'admin_email' ) . ' with the name(s) on the booking.',
                ),
            ),
        ) );
        if ( is_wp_error( $link ) ) {
            return $link;
        }

        update_post_meta( $event_id, '_stripe_payment_link_url', $link['url'] );

        return $link['url'];
    }

    /** Shared low-level POST to the Stripe API, used by the payment-link helpers above. */
    private function api_request( $endpoint, $body ) {
        $secret_key = $this->get_secret_key();
        if ( empty( $secret_key ) ) {
            return new WP_Error( 'no_stripe_key', __( 'Stripe API key not configured.', 'mira-event-list' ) );
        }

        $response = wp_remote_post( 'https://api.stripe.com/v1/' . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => $body,
            // Shorter than the main checkout call — this is a best-effort
            // fallback link, not core functionality, so don't let a slow
            // Stripe response drag out a normal page load.
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data        = json_decode( wp_remote_retrieve_body( $response ), true );
        $status_code = (int) wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown Stripe error.', 'mira-event-list' );
            return new WP_Error( 'stripe_api_error', $message );
        }

        return $data;
    }

    public function get_session( $session_id ) {
        $secret_key = $this->get_secret_key();
        if ( empty( $secret_key ) ) {
            return new WP_Error( 'no_stripe_key', __( 'Stripe API key not configured.', 'mira-event-list' ) );
        }

        $response = wp_remote_get(
            'https://api.stripe.com/v1/checkout/sessions/' . urlencode( $session_id ),
            array(
                'headers' => array( 'Authorization' => 'Bearer ' . $secret_key ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data        = json_decode( wp_remote_retrieve_body( $response ), true );
        $status_code = (int) wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            return new WP_Error( 'stripe_api_error', $data['error']['message'] ?? 'Unknown error' );
        }

        return $data;
    }

    public function verify_webhook( $payload, $sig_header ) {
        $webhook_secret = $this->get_webhook_secret();
        if ( empty( $webhook_secret ) || empty( $sig_header ) ) {
            return false;
        }

        $timestamp  = '';
        $signatures = array();

        foreach ( explode( ',', $sig_header ) as $part ) {
            $part = trim( $part );
            if ( strpos( $part, 't=' ) === 0 ) {
                $timestamp = substr( $part, 2 );
            } elseif ( strpos( $part, 'v1=' ) === 0 ) {
                $signatures[] = substr( $part, 3 );
            }
        }

        if ( empty( $timestamp ) || empty( $signatures ) ) {
            return false;
        }

        // Reject events older than 5 minutes
        if ( abs( time() - intval( $timestamp ) ) > 300 ) {
            return false;
        }

        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $webhook_secret );

        foreach ( $signatures as $sig ) {
            if ( hash_equals( $expected, $sig ) ) {
                return true;
            }
        }

        return false;
    }
}
