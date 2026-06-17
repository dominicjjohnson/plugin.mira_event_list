<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MiraBookings {

    private $stripe;

    public function __construct() {
        $this->stripe = new MiraStripe();

        add_action( 'wp_ajax_mira_create_booking',        array( $this, 'ajax_create_booking' ) );
        add_action( 'wp_ajax_nopriv_mira_create_booking', array( $this, 'ajax_create_booking' ) );

        add_action( 'wp_ajax_mira_save_attendees',        array( $this, 'ajax_save_attendees' ) );
        add_action( 'wp_ajax_nopriv_mira_save_attendees', array( $this, 'ajax_save_attendees' ) );

        add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );

        add_shortcode( 'mira_booking_success', array( $this, 'booking_success_shortcode' ) );
    }

    public function register_webhook_endpoint() {
        register_rest_route( 'mira/v1', '/stripe-webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_stripe_webhook' ),
            'permission_callback' => '__return_true',
        ) );
    }

    private function generate_booking_reference() {
        global $wpdb;
        $table = $wpdb->prefix . 'mira_bookings';
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $len   = strlen( $chars ) - 1;

        do {
            $ref = 'MIR-';
            for ( $i = 0; $i < 6; $i++ ) {
                $ref .= $chars[ random_int( 0, $len ) ];
            }
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE booking_reference = %s", $ref ) );
        } while ( $exists );

        return $ref;
    }

    private function generate_ticket_number( $booking_reference, $index ) {
        return $booking_reference . '-' . str_pad( $index, 2, '0', STR_PAD_LEFT );
    }

    // ── AJAX: create booking + Stripe session ────────────────────────────

    public function ajax_create_booking() {
        check_ajax_referer( 'mira_booking_nonce', 'nonce' );

        $event_id   = intval( $_POST['event_id'] ?? 0 );
        $quantity   = intval( $_POST['quantity'] ?? 0 );
        $donation   = floatval( $_POST['donation'] ?? 0 );
        $lead_email = sanitize_email( $_POST['lead_email'] ?? '' );

        if ( empty( $lead_email ) || ! is_email( $lead_email ) ) {
            wp_send_json_error( __( 'Please enter a valid email address.', 'mira-event-list' ) );
        }

        if ( ! $event_id || $quantity < 1 || $quantity > 10 ) {
            wp_send_json_error( __( 'Invalid booking data.', 'mira-event-list' ) );
        }

        $event = get_post( $event_id );
        if ( ! $event || $event->post_type !== 'mira_event' || $event->post_status !== 'publish' ) {
            wp_send_json_error( __( 'Event not found.', 'mira-event-list' ) );
        }

        if ( ! get_post_meta( $event_id, '_tickets_enabled', true ) ) {
            wp_send_json_error( __( 'Tickets are not available for this event.', 'mira-event-list' ) );
        }

        $ticket_price   = floatval( get_post_meta( $event_id, '_ticket_price', true ) );
        $donation       = max( 0.0, $donation );
        $total          = ( $ticket_price * $quantity ) + $donation;
        $donation_pence = intval( round( $donation * 100 ) );

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'mira_bookings';

        $booking_reference = $this->generate_booking_reference();

        $inserted = $wpdb->insert( $bookings_table, array(
            'event_id'          => $event_id,
            'booking_reference' => $booking_reference,
            'lead_email'        => $lead_email,
            'quantity'          => $quantity,
            'ticket_price'      => $ticket_price,
            'donation_amount'   => $donation,
            'total_amount'      => $total,
            'status'            => 'pending',
        ), array( '%d', '%s', '%s', '%d', '%f', '%f', '%f', '%s' ) );

        if ( ! $inserted ) {
            wp_send_json_error( __( 'Could not create booking. Please try again.', 'mira-event-list' ) );
        }

        $booking_id = $wpdb->insert_id;

        $session = $this->stripe->create_checkout_session(
            $event_id, $quantity, $donation_pence, $booking_id, $booking_reference, $lead_email
        );

        if ( is_wp_error( $session ) ) {
            $wpdb->delete( $bookings_table, array( 'id' => $booking_id ), array( '%d' ) );
            wp_send_json_error( $session->get_error_message() );
        }

        $wpdb->update(
            $bookings_table,
            array( 'stripe_session_id' => $session['id'] ),
            array( 'id' => $booking_id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array( 'url' => $session['url'] ) );
    }

    // ── AJAX: save attendees + send tickets ──────────────────────────────

    public function ajax_save_attendees() {
        check_ajax_referer( 'mira_attendees_nonce', 'nonce' );

        $session_id    = sanitize_text_field( $_POST['session_id'] ?? '' );
        $raw_attendees = isset( $_POST['attendees'] ) && is_array( $_POST['attendees'] )
            ? $_POST['attendees']
            : array();

        if ( empty( $session_id ) || empty( $raw_attendees ) ) {
            wp_send_json_error( __( 'Missing required information.', 'mira-event-list' ) );
        }

        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $bookings_table WHERE stripe_session_id = %s AND status = 'paid'",
            $session_id
        ) );

        if ( ! $booking ) {
            wp_send_json_error( __( 'Payment not yet confirmed. Please wait a moment and try again.', 'mira-event-list' ) );
        }

        if ( count( $raw_attendees ) !== intval( $booking->quantity ) ) {
            wp_send_json_error( __( 'Attendee count does not match ticket quantity.', 'mira-event-list' ) );
        }

        $existing = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $attendees_table WHERE booking_id = %d",
            $booking->id
        ) ) );
        if ( $existing > 0 ) {
            wp_send_json_error( __( 'Attendee details have already been submitted for this booking.', 'mira-event-list' ) );
        }

        // Validate all attendees before inserting any
        $attendees = array();
        foreach ( $raw_attendees as $i => $row ) {
            $name  = sanitize_text_field( $row['name'] ?? '' );
            $email = sanitize_email( $row['email'] ?? '' );
            if ( empty( $name ) || ! is_email( $email ) ) {
                /* translators: %d: position number */
                wp_send_json_error( sprintf( __( 'Invalid name or email for attendee %d.', 'mira-event-list' ), $i + 1 ) );
            }
            $attendees[] = array( 'name' => $name, 'email' => $email );
        }

        $event         = get_post( $booking->event_id );
        $email_handler = new MiraEmails();
        $saved         = array();

        foreach ( $attendees as $idx => $att ) {
            $ticket_number = $this->generate_ticket_number( $booking->booking_reference, $idx + 1 );

            $wpdb->insert( $attendees_table, array(
                'booking_id'    => $booking->id,
                'name'          => $att['name'],
                'email'         => $att['email'],
                'ticket_number' => $ticket_number,
                'is_lead'       => ( $idx === 0 ) ? 1 : 0,
            ), array( '%d', '%s', '%s', '%s', '%d' ) );

            $saved[] = array(
                'id'            => $wpdb->insert_id,
                'name'          => $att['name'],
                'email'         => $att['email'],
                'ticket_number' => $ticket_number,
            );
        }

        foreach ( $saved as $attendee ) {
            $email_handler->send_ticket( $attendee, $booking, $event );
        }

        $wpdb->update(
            $bookings_table,
            array( 'status' => 'complete' ),
            array( 'id' => $booking->id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array( 'message' => __( 'Tickets sent!', 'mira-event-list' ) ) );
    }

    // ── Stripe webhook ───────────────────────────────────────────────────

    public function handle_stripe_webhook( WP_REST_Request $request ) {
        $payload    = $request->get_body();
        $sig_header = $request->get_header( 'stripe-signature' );

        if ( ! $this->stripe->verify_webhook( $payload, $sig_header ) ) {
            return new WP_REST_Response( 'Invalid signature', 400 );
        }

        $event = json_decode( $payload, true );
        if ( empty( $event['type'] ) ) {
            return new WP_REST_Response( 'OK', 200 );
        }

        if ( $event['type'] === 'checkout.session.completed' ) {
            $this->mark_booking_paid( $event['data']['object'] );
        }

        return new WP_REST_Response( 'OK', 200 );
    }

    public function mark_booking_paid( $session ) {
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'mira_bookings';

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM $bookings_table WHERE stripe_session_id = %s",
            $session['id']
        ) );

        if ( ! $booking || $booking->status !== 'pending' ) {
            return;
        }

        $wpdb->update(
            $bookings_table,
            array(
                'status'                => 'paid',
                'stripe_payment_intent' => $session['payment_intent'] ?? '',
            ),
            array( 'id' => $booking->id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    // ── Shortcode: [mira_booking_success] ────────────────────────────────

    public function booking_success_shortcode( $atts ) {
        $session_id = sanitize_text_field( $_GET['session_id'] ?? '' );

        if ( empty( $session_id ) ) {
            return '<p class="mira-notice">' . esc_html__( 'No booking session found.', 'mira-event-list' ) . '</p>';
        }

        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $bookings_table WHERE stripe_session_id = %s",
            $session_id
        ) );

        if ( ! $booking ) {
            return '<p class="mira-notice">' . esc_html__( 'Booking not found.', 'mira-event-list' ) . '</p>';
        }

        // If the webhook hasn't fired yet, verify directly with Stripe
        if ( $booking->status === 'pending' ) {
            $stripe_session = $this->stripe->get_session( $session_id );
            if ( is_wp_error( $stripe_session ) || ( $stripe_session['payment_status'] ?? '' ) !== 'paid' ) {
                return '<p class="mira-notice">' . esc_html__( 'Payment not yet confirmed. Please wait a moment and refresh the page.', 'mira-event-list' ) . '</p>';
            }
            $this->mark_booking_paid( $stripe_session );
            $booking->status = 'paid';
        }

        // Attendees already collected — show confirmation
        if ( $booking->status === 'complete' ) {
            $attendees = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $attendees_table WHERE booking_id = %d ORDER BY id ASC",
                $booking->id
            ) );
            return $this->render_confirmation( $booking, $attendees );
        }

        // Show attendee collection form
        $event       = get_post( $booking->event_id );
        $buyer_email = '';

        $stripe_session = $this->stripe->get_session( $session_id );
        if ( ! is_wp_error( $stripe_session ) ) {
            $buyer_email = $stripe_session['customer_details']['email'] ?? '';
        }

        return $this->render_attendee_form( $booking, $event, $session_id, $buyer_email );
    }

    private function render_attendee_form( $booking, $event, $session_id, $buyer_email ) {
        $button_color      = get_option( 'mira_event_button_color', '#28a745' );
        $button_text_color = get_option( 'mira_event_button_text_color', '#fff' );
        $nonce             = wp_create_nonce( 'mira_attendees_nonce' );
        $ajax_url          = admin_url( 'admin-ajax.php' );
        $quantity          = intval( $booking->quantity );

        ob_start();
        ?>
        <div class="mira-success-page">
            <div class="mira-success-header">
                <div class="mira-success-icon">&#10003;</div>
                <h2><?php esc_html_e( 'Payment confirmed!', 'mira-event-list' ); ?></h2>
                <p><?php echo wp_kses(
                    sprintf(
                        _n(
                            'You\'ve purchased <strong>%1$d ticket</strong> for <strong>%2$s</strong>.',
                            'You\'ve purchased <strong>%1$d tickets</strong> for <strong>%2$s</strong>.',
                            $quantity,
                            'mira-event-list'
                        ),
                        $quantity,
                        esc_html( $event->post_title )
                    ),
                    array( 'strong' => array() )
                ); ?></p>
                <p class="mira-success-sub"><?php esc_html_e( 'Please enter the details for each attendee below. A ticket will be emailed to each person.', 'mira-event-list' ); ?></p>
            </div>

            <form id="mira-attendees-form"
                  data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                  data-nonce="<?php echo esc_attr( $nonce ); ?>">

                <input type="hidden" name="session_id" value="<?php echo esc_attr( $session_id ); ?>">

                <?php for ( $i = 0; $i < $quantity; $i++ ) : ?>
                    <div class="mira-attendee-row">
                        <h3 class="mira-attendee-heading">
                            <?php echo $i === 0
                                ? esc_html__( 'Your details', 'mira-event-list' )
                                : sprintf( esc_html__( 'Guest %d', 'mira-event-list' ), $i ); ?>
                        </h3>
                        <div class="mira-field-pair">
                            <div class="mira-field-group">
                                <label><?php esc_html_e( 'Full Name', 'mira-event-list' ); ?> <span class="mira-required">*</span></label>
                                <input type="text"
                                       name="attendees[<?php echo $i; ?>][name]"
                                       required
                                       placeholder="<?php esc_attr_e( 'Full name', 'mira-event-list' ); ?>">
                            </div>
                            <div class="mira-field-group">
                                <label><?php esc_html_e( 'Email Address', 'mira-event-list' ); ?> <span class="mira-required">*</span></label>
                                <input type="email"
                                       name="attendees[<?php echo $i; ?>][email]"
                                       required
                                       placeholder="email@example.com"
                                       value="<?php echo $i === 0 ? esc_attr( $buyer_email ) : ''; ?>">
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>

                <div class="mira-form-footer">
                    <button type="submit"
                            class="mira-submit-btn"
                            style="background-color:<?php echo esc_attr( $button_color ); ?>;color:<?php echo esc_attr( $button_text_color ); ?>;">
                        <?php esc_html_e( 'Send My Tickets', 'mira-event-list' ); ?>
                    </button>
                    <p id="mira-attendees-message" class="mira-form-message" aria-live="polite"></p>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_confirmation( $booking, $attendees ) {
        ob_start();
        ?>
        <div class="mira-booking-confirmed">
            <div class="mira-confirmed-icon">&#10003;</div>
            <h2><?php esc_html_e( "You're all set!", 'mira-event-list' ); ?></h2>
            <p><?php echo wp_kses(
                sprintf(
                    __( 'Booking reference: <strong>%s</strong>', 'mira-event-list' ),
                    esc_html( $booking->booking_reference )
                ),
                array( 'strong' => array() )
            ); ?></p>
            <p><?php esc_html_e( 'Tickets have been emailed to:', 'mira-event-list' ); ?></p>
            <ul class="mira-confirmed-list">
                <?php foreach ( $attendees as $a ) : ?>
                    <li>
                        <span class="mira-confirmed-name"><?php echo esc_html( $a->name ); ?></span>
                        <span class="mira-confirmed-email"><?php echo esc_html( $a->email ); ?></span>
                        <span class="mira-confirmed-ticket"><?php echo esc_html( $a->ticket_number ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }
}
