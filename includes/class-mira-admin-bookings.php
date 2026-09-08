<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MiraAdminBookings {

    public function __construct() {
        add_action( 'admin_menu',  array( $this, 'register_menu' ) );
        add_action( 'admin_init',  array( $this, 'handle_actions' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'edit.php?post_type=mira_event',
            __( 'Bookings', 'mira-event-list' ),
            __( 'Bookings', 'mira-event-list' ),
            'manage_options',
            'mira-bookings',
            array( $this, 'render_page' )
        );
    }

    // ── Actions (run before headers) ─────────────────────────────────────

    public function handle_actions() {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== 'mira-bookings' ) {
            return;
        }

        $action = sanitize_key( $_GET['action'] ?? '' );

        if ( $action === 'create_manual' && ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
            check_admin_referer( 'mira_create_manual_booking' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to do this.', 'mira-event-list' ) );
            }

            $result = $this->create_manual_booking( $_POST );

            if ( is_wp_error( $result ) ) {
                wp_safe_redirect( add_query_arg( array(
                    'page'         => 'mira-bookings',
                    'action'       => 'add',
                    'manual_error' => rawurlencode( $result->get_error_message() ),
                ), admin_url( 'edit.php?post_type=mira_event' ) ) );
                exit;
            }

            wp_safe_redirect( add_query_arg( array(
                'page'       => 'mira-bookings',
                'booking_id' => $result['booking_id'],
                'created'    => '1',
                'sent'       => (int) $result['sent'],
                'pending'    => (int) $result['pending'],
            ), admin_url( 'edit.php?post_type=mira_event' ) ) );
            exit;
        }

        if ( $action === 'toggle_paid' && isset( $_GET['booking_id'] ) ) {
            $booking_id = intval( $_GET['booking_id'] );
            check_admin_referer( 'mira_toggle_paid_' . $booking_id );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to do this.', 'mira-event-list' ) );
            }

            global $wpdb;
            $bookings_table = $wpdb->prefix . 'mira_bookings';
            $booking        = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $bookings_table WHERE id = %d", $booking_id
            ) );

            $sent   = 0;
            $marked = 0;

            if ( $booking && $booking->payment_method && $booking->payment_method !== 'stripe' ) {
                $now_received = $booking->payment_received ? 0 : 1;
                $updates = array( 'payment_received' => $now_received );
                $formats = array( '%d' );

                // First payment on a still-pending manual booking: promote it to
                // "complete" and email any tickets that haven't gone out yet.
                if ( $now_received && $booking->status === 'pending' ) {
                    $updates['status'] = 'complete';
                    $formats[]         = '%s';
                }

                $wpdb->update( $bookings_table, $updates, array( 'id' => $booking_id ), $formats, array( '%d' ) );

                if ( $now_received ) {
                    $sent = $this->send_unsent_tickets( $booking_id );
                    if ( class_exists( 'MiraMailjet' ) && MiraMailjet::is_enabled() ) {
                        MiraMailjet::sync_booking_attendees( $booking_id );
                    }
                }
                $marked = $now_received;
            }

            $redirect = wp_get_referer();
            if ( ! $redirect || strpos( $redirect, 'page=mira-bookings' ) === false ) {
                $redirect = add_query_arg(
                    array( 'page' => 'mira-bookings', 'booking_id' => $booking_id ),
                    admin_url( 'edit.php?post_type=mira_event' )
                );
            }
            $redirect = add_query_arg(
                array( 'marked_paid' => $marked, 'paid_sent' => $sent ),
                remove_query_arg( array( 'action', '_wpnonce', 'marked_paid', 'paid_sent' ), $redirect )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( $action === 'delete' && isset( $_GET['booking_id'] ) ) {
            $booking_id = intval( $_GET['booking_id'] );
            check_admin_referer( 'mira_delete_booking_' . $booking_id );

            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'mira_attendees', array( 'booking_id' => $booking_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mira_bookings',  array( 'id'         => $booking_id ), array( '%d' ) );

            wp_redirect( add_query_arg(
                array( 'page' => 'mira-bookings', 'deleted' => '1' ),
                admin_url( 'edit.php?post_type=mira_event' )
            ) );
            exit;
        }

        if ( $action === 'resend_tickets' && isset( $_GET['booking_id'] ) ) {
            $booking_id = intval( $_GET['booking_id'] );
            check_admin_referer( 'mira_resend_tickets_' . $booking_id );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to do this.', 'mira-event-list' ) );
            }

            $sent = $this->resend_tickets( $booking_id );

            $redirect = wp_get_referer();
            if ( ! $redirect || strpos( $redirect, 'page=mira-bookings' ) === false ) {
                $redirect = add_query_arg(
                    array( 'page' => 'mira-bookings', 'booking_id' => $booking_id ),
                    admin_url( 'edit.php?post_type=mira_event' )
                );
            }

            wp_safe_redirect( add_query_arg( 'resent', $sent, remove_query_arg( array( 'resent', 'action', '_wpnonce' ), $redirect ) ) );
            exit;
        }

        if ( $action === 'export_csv' ) {
            check_admin_referer( 'mira_export_csv' );
            $this->output_csv();
            exit;
        }

        if ( $action === 'mailjet_sync' ) {
            check_admin_referer( 'mira_mailjet_sync' );
            $this->run_mailjet_sync( max( 0, intval( $_GET['offset'] ?? 0 ) ) );
            exit;
        }
    }

    // ── Mailjet backfill (chunked, self-advancing) ───────────────────────

    const MAILJET_BATCH = 15;
    const MAILJET_TOTALS = 'mira_mailjet_backfill_totals';

    private function run_mailjet_sync( $offset ) {
        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'mira-event-list' ) );
        }

        if ( ! class_exists( 'MiraMailjet' ) || ! MiraMailjet::is_enabled() ) {
            wp_die( esc_html__( 'Mailjet sync is not enabled. Configure it under Events → Settings first.', 'mira-event-list' ) );
        }

        @set_time_limit( 120 );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $bookings_table WHERE status IN ('paid','complete')"
        );

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_id, lead_email FROM $bookings_table
             WHERE status IN ('paid','complete')
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            self::MAILJET_BATCH,
            $offset
        ) );

        $totals = get_transient( self::MAILJET_TOTALS );
        if ( ! is_array( $totals ) ) {
            $totals = array( 'ok' => 0, 'partial' => 0, 'failed' => 0, 'skipped' => 0 );
        }

        foreach ( $bookings as $b ) {
            $tag  = MiraMailjet::event_tag( $b->event_id );
            $seen = array();

            $emails = array();
            if ( ! empty( $b->lead_email ) ) {
                $emails[ strtolower( $b->lead_email ) ] = array( 'email' => $b->lead_email, 'name' => '' );
            }
            $attendees = $wpdb->get_results( $wpdb->prepare(
                "SELECT name, email FROM $attendees_table WHERE booking_id = %d",
                $b->id
            ) );
            foreach ( $attendees as $a ) {
                $emails[ strtolower( $a->email ) ] = array( 'email' => $a->email, 'name' => $a->name );
            }

            foreach ( $emails as $row ) {
                $key = $row['email'] . '|' . $tag;
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $result = MiraMailjet::sync_contact( $row['email'], $row['name'], $tag );
                $totals[ $result ] = ( $totals[ $result ] ?? 0 ) + 1;
            }
        }

        $processed = $offset + count( $bookings );
        $done      = count( $bookings ) < self::MAILJET_BATCH || $processed >= $total;

        set_transient( self::MAILJET_TOTALS, $totals, HOUR_IN_SECONDS );

        $list_url = admin_url( 'edit.php?post_type=mira_event&page=mira-bookings' );

        if ( $done ) {
            delete_transient( self::MAILJET_TOTALS );
            wp_safe_redirect( add_query_arg( array(
                'mailjet_done'    => '1',
                'mailjet_ok'      => (int) $totals['ok'],
                'mailjet_partial' => (int) $totals['partial'],
                'mailjet_failed'  => (int) $totals['failed'],
            ), $list_url ) );
            exit;
        }

        $next_url = wp_nonce_url(
            add_query_arg( array(
                'post_type' => 'mira_event',
                'page'      => 'mira-bookings',
                'action'    => 'mailjet_sync',
                'offset'    => $processed,
            ), admin_url( 'edit.php' ) ),
            'mira_mailjet_sync'
        );

        $pct = $total ? round( $processed / $total * 100 ) : 100;

        // Minimal self-advancing progress page.
        nocache_headers();
        echo '<!doctype html><meta charset="utf-8">';
        echo '<meta http-equiv="refresh" content="1;url=' . esc_url( $next_url ) . '">';
        echo '<title>' . esc_html__( 'Syncing to Mailjet…', 'mira-event-list' ) . '</title>';
        echo '<div style="font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;max-width:520px;margin:80px auto;padding:0 20px">';
        echo '<h1 style="font-size:18px">' . esc_html__( 'Syncing booking emails to Mailjet…', 'mira-event-list' ) . '</h1>';
        echo '<div style="background:#e5e7eb;border-radius:6px;height:14px;overflow:hidden">';
        echo '<div style="background:#15803d;height:100%;width:' . esc_attr( $pct ) . '%"></div></div>';
        echo '<p>' . sprintf(
            /* translators: 1: processed count, 2: total count */
            esc_html__( '%1$d of %2$d bookings processed. This page continues automatically.', 'mira-event-list' ),
            (int) $processed,
            (int) $total
        ) . '</p>';
        echo '<p>' . sprintf(
            /* translators: 1: synced, 2: partial, 3: failed */
            esc_html__( 'Synced: %1$d · Tag missing: %2$d · Failed: %3$d', 'mira-event-list' ),
            (int) $totals['ok'],
            (int) $totals['partial'],
            (int) $totals['failed']
        ) . '</p>';
        echo '<p><a href="' . esc_url( $list_url ) . '">' . esc_html__( 'Stop and return to Bookings', 'mira-event-list' ) . '</a></p>';
        echo '</div>';
        exit;
    }

    // ── Resend tickets ───────────────────────────────────────────────────

    /**
     * Re-send the ticket email for every attendee on a booking, with a copy
     * CC'd to the site admin. Returns the number of emails sent.
     */
    private function resend_tickets( $booking_id ) {
        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $bookings_table WHERE id = %d", $booking_id
        ) );
        if ( ! $booking ) {
            return 0;
        }

        $attendees = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $attendees_table WHERE booking_id = %d ORDER BY is_lead DESC, id ASC",
            $booking_id
        ) );
        if ( empty( $attendees ) ) {
            return 0;
        }

        $event = get_post( $booking->event_id );
        if ( ! $event ) {
            return 0;
        }

        $admin_email   = get_option( 'admin_email' );
        $cc_headers    = $admin_email ? array( 'Cc: ' . $admin_email ) : array();
        $email_handler = new MiraEmails();
        $sent          = 0;

        foreach ( $attendees as $a ) {
            $ok = $email_handler->send_ticket(
                array(
                    'id'            => $a->id,
                    'name'          => $a->name,
                    'email'         => $a->email,
                    'ticket_number' => $a->ticket_number,
                ),
                $booking,
                $event,
                $cc_headers
            );
            if ( $ok ) {
                $sent++;
            }
        }

        return $sent;
    }

    // ── Router ────────────────────────────────────────────────────────────

    public function render_page() {
        if ( ( sanitize_key( $_GET['action'] ?? '' ) ) === 'add' ) {
            $this->render_add_form();
        } elseif ( isset( $_GET['booking_id'] ) ) {
            $this->render_detail( intval( $_GET['booking_id'] ) );
        } else {
            $this->render_list();
        }
    }

    // ── Manual / cash booking ────────────────────────────────────────────

    /**
     * Payment methods available for manual bookings: slug => label.
     */
    private function payment_methods() {
        return array(
            'cash'          => __( 'Cash', 'mira-event-list' ),
            'card'          => __( 'Card (in person)', 'mira-event-list' ),
            'bank_transfer' => __( 'Bank transfer', 'mira-event-list' ),
            'free'          => __( 'Free / complimentary', 'mira-event-list' ),
        );
    }

    private function payment_method_label( $slug ) {
        if ( $slug === 'stripe' || $slug === '' ) {
            return __( 'Stripe', 'mira-event-list' );
        }
        $methods = $this->payment_methods();
        return $methods[ $slug ] ?? ucfirst( str_replace( '_', ' ', $slug ) );
    }

    private function payment_method_badge( $slug ) {
        if ( $slug === 'stripe' || $slug === '' ) {
            return ''; // Stripe is the default — no badge needed.
        }
        return sprintf(
            '<span style="background:#4b5563;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;text-transform:uppercase">%s</span>',
            esc_html( $this->payment_method_label( $slug ) )
        );
    }

    /**
     * Local booking-reference generator (mirrors MiraBookings::generate_booking_reference).
     */
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

    /**
     * Create a booking from the manual-entry form. Returns
     * array( 'booking_id' => int, 'sent' => int ) or WP_Error.
     */
    private function create_manual_booking( array $data ) {
        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        $event_id = intval( $data['event_id'] ?? 0 );
        $event    = $event_id ? get_post( $event_id ) : null;
        if ( ! $event || $event->post_type !== 'mira_event' ) {
            return new WP_Error( 'mira_manual', __( 'Please choose a valid event.', 'mira-event-list' ) );
        }

        $method      = sanitize_key( $data['payment_method'] ?? '' );
        if ( ! array_key_exists( $method, $this->payment_methods() ) ) {
            return new WP_Error( 'mira_manual', __( 'Please choose a payment method.', 'mira-event-list' ) );
        }

        // Attendees — drop fully empty rows, validate the rest.
        $raw_attendees = ( isset( $data['attendees'] ) && is_array( $data['attendees'] ) ) ? $data['attendees'] : array();
        $attendees     = array();
        foreach ( $raw_attendees as $i => $row ) {
            $name  = sanitize_text_field( $row['name'] ?? '' );
            $email = sanitize_email( $row['email'] ?? '' );
            if ( $name === '' && $email === '' ) {
                continue;
            }
            if ( $name === '' || ! is_email( $email ) ) {
                /* translators: %d: attendee row number */
                return new WP_Error( 'mira_manual', sprintf( __( 'Enter a name and a valid email for attendee %d, or clear that row.', 'mira-event-list' ), $i + 1 ) );
            }
            $attendees[] = array( 'name' => $name, 'email' => $email );
        }

        if ( empty( $attendees ) ) {
            return new WP_Error( 'mira_manual', __( 'Add at least one attendee.', 'mira-event-list' ) );
        }

        $quantity = count( $attendees );

        $ticket_price = isset( $data['ticket_price'] ) && $data['ticket_price'] !== ''
            ? max( 0.0, floatval( $data['ticket_price'] ) )
            : floatval( get_post_meta( $event_id, '_ticket_price', true ) );

        $donation = max( 0.0, floatval( $data['donation_amount'] ?? 0 ) );
        $total    = ( $method === 'free' ) ? 0.0 : ( $ticket_price * $quantity ) + $donation;

        $note = sanitize_textarea_field( $data['admin_note'] ?? '' );

        $received = ( ! empty( $data['payment_received'] ) || $method === 'free' ) ? 1 : 0;
        // Never email a ticket for money that hasn't arrived. An unpaid booking
        // is saved as "pending" and can be marked paid (which sends the tickets)
        // later.
        $send     = $received && ! empty( $data['send_tickets'] );
        $cc_admin = ! empty( $data['cc_admin'] );
        $status   = $received ? 'complete' : 'pending';

        $booking_reference = $this->generate_booking_reference();

        $inserted = $wpdb->insert( $bookings_table, array(
            'event_id'          => $event_id,
            'booking_reference' => $booking_reference,
            'lead_email'        => $attendees[0]['email'],
            'quantity'          => $quantity,
            'ticket_price'      => $ticket_price,
            'donation_amount'   => $donation,
            'total_amount'      => $total,
            'status'            => $status,
            'payment_method'    => $method,
            'payment_received'  => $received,
            'admin_note'        => $note,
        ), array( '%d', '%s', '%s', '%d', '%f', '%f', '%f', '%s', '%s', '%d', '%s' ) );

        if ( ! $inserted ) {
            return new WP_Error( 'mira_manual', __( 'Could not save the booking. Please try again.', 'mira-event-list' ) );
        }

        $booking_id = (int) $wpdb->insert_id;
        $booking    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $bookings_table WHERE id = %d", $booking_id ) );

        $saved = array();
        foreach ( $attendees as $idx => $att ) {
            $ticket_number = $booking_reference . '-' . str_pad( $idx + 1, 2, '0', STR_PAD_LEFT );
            $wpdb->insert( $attendees_table, array(
                'booking_id'    => $booking_id,
                'name'          => $att['name'],
                'email'         => $att['email'],
                'ticket_number' => $ticket_number,
                'is_lead'       => ( $idx === 0 ) ? 1 : 0,
            ), array( '%d', '%s', '%s', '%s', '%d' ) );

            $saved[] = array(
                'id'            => (int) $wpdb->insert_id,
                'name'          => $att['name'],
                'email'         => $att['email'],
                'ticket_number' => $ticket_number,
            );
        }

        $sent = 0;
        if ( $send ) {
            $cc_headers = array();
            if ( $cc_admin && get_option( 'admin_email' ) ) {
                $cc_headers[] = 'Cc: ' . get_option( 'admin_email' );
            }
            $email_handler = new MiraEmails();
            foreach ( $saved as $attendee ) {
                if ( $email_handler->send_ticket( $attendee, $booking, $event, $cc_headers ) ) {
                    $sent++;
                }
            }
        }

        if ( $received && class_exists( 'MiraMailjet' ) && MiraMailjet::is_enabled() ) {
            MiraMailjet::sync_booking_attendees( $booking_id );
        }

        return array( 'booking_id' => $booking_id, 'sent' => $sent, 'pending' => $received ? 0 : 1 );
    }

    /**
     * Email the ticket to every attendee on a booking whose ticket has not been
     * sent yet. Returns the number of emails sent.
     */
    private function send_unsent_tickets( $booking_id ) {
        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $bookings_table WHERE id = %d", $booking_id
        ) );
        if ( ! $booking ) {
            return 0;
        }

        $attendees = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $attendees_table WHERE booking_id = %d AND sent_at IS NULL ORDER BY is_lead DESC, id ASC",
            $booking_id
        ) );
        if ( empty( $attendees ) ) {
            return 0;
        }

        $event = get_post( $booking->event_id );
        if ( ! $event ) {
            return 0;
        }

        $email_handler = new MiraEmails();
        $sent          = 0;
        foreach ( $attendees as $a ) {
            $ok = $email_handler->send_ticket(
                array(
                    'id'            => $a->id,
                    'name'          => $a->name,
                    'email'         => $a->email,
                    'ticket_number' => $a->ticket_number,
                ),
                $booking,
                $event
            );
            if ( $ok ) {
                $sent++;
            }
        }

        return $sent;
    }

    private function render_add_form() {
        $events = get_posts( array(
            'post_type'      => 'mira_event',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $prices = array();
        foreach ( $events as $ev ) {
            $prices[ $ev->ID ] = (float) get_post_meta( $ev->ID, '_ticket_price', true );
        }

        $back_url   = admin_url( 'edit.php?post_type=mira_event&page=mira-bookings' );
        $form_action = add_query_arg( array(
            'post_type' => 'mira_event',
            'page'      => 'mira-bookings',
            'action'    => 'create_manual',
        ), admin_url( 'edit.php' ) );
        $error = isset( $_GET['manual_error'] ) ? sanitize_text_field( wp_unslash( $_GET['manual_error'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Add Manual Booking', 'mira-event-list' ); ?></h1>
            <p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Bookings', 'mira-event-list' ); ?></a></p>

            <?php if ( $error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $events ) ) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e( 'Create a published event first.', 'mira-event-list' ); ?></p></div>
            <?php else : ?>
            <form method="post" action="<?php echo esc_url( $form_action ); ?>">
                <?php wp_nonce_field( 'mira_create_manual_booking' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mira-mb-event"><?php esc_html_e( 'Event', 'mira-event-list' ); ?></label></th>
                        <td>
                            <select name="event_id" id="mira-mb-event" required>
                                <option value=""><?php esc_html_e( '— Select an event —', 'mira-event-list' ); ?></option>
                                <?php foreach ( $events as $ev ) : ?>
                                    <option value="<?php echo esc_attr( $ev->ID ); ?>"
                                            data-price="<?php echo esc_attr( number_format( $prices[ $ev->ID ], 2, '.', '' ) ); ?>">
                                        <?php echo esc_html( $ev->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mira-mb-method"><?php esc_html_e( 'Payment method', 'mira-event-list' ); ?></label></th>
                        <td>
                            <select name="payment_method" id="mira-mb-method">
                                <?php foreach ( $this->payment_methods() as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mira-mb-price"><?php esc_html_e( 'Ticket price (£)', 'mira-event-list' ); ?></label></th>
                        <td>
                            <input type="number" step="0.01" min="0" name="ticket_price" id="mira-mb-price" class="small-text">
                            <p class="description"><?php esc_html_e( "Pre-filled from the event. Set to 0 for a free ticket. Total = price × attendees + donation.", 'mira-event-list' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mira-mb-donation"><?php esc_html_e( 'Donation (£)', 'mira-event-list' ); ?></label></th>
                        <td><input type="number" step="0.01" min="0" name="donation_amount" id="mira-mb-donation" class="small-text" value="0"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Attendees', 'mira-event-list' ); ?></th>
                        <td>
                            <table id="mira-mb-attendees" style="border-spacing:0">
                                <tbody>
                                    <?php for ( $i = 0; $i < 1; $i++ ) : ?>
                                    <tr>
                                        <td style="padding:0 8px 8px 0">
                                            <input type="text" name="attendees[<?php echo $i; ?>][name]"
                                                   placeholder="<?php esc_attr_e( 'Full name', 'mira-event-list' ); ?>" class="regular-text">
                                        </td>
                                        <td style="padding:0 8px 8px 0">
                                            <input type="email" name="attendees[<?php echo $i; ?>][email]"
                                                   placeholder="email@example.com" class="regular-text">
                                        </td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                            <button type="button" class="button" id="mira-mb-add-row"><?php esc_html_e( '+ Add attendee', 'mira-event-list' ); ?></button>
                            <p class="description"><?php esc_html_e( 'One ticket is issued per attendee. The first attendee is the lead booker.', 'mira-event-list' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Options', 'mira-event-list' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="payment_received" id="mira-mb-received" value="1"> <?php esc_html_e( 'Payment received', 'mira-event-list' ); ?></label>
                            <p class="description" style="margin-top:2px"><?php esc_html_e( 'Leave unticked to save the booking as pending. You can mark it paid later, which sends the tickets then.', 'mira-event-list' ); ?></p>
                            <label style="display:block;margin-top:8px"><input type="checkbox" name="send_tickets" id="mira-mb-send" value="1" checked> <?php esc_html_e( 'Email tickets to each attendee now', 'mira-event-list' ); ?></label>
                            <label><input type="checkbox" name="cc_admin" value="1"> <?php
                                /* translators: %s: site admin email */
                                printf( esc_html__( 'CC the site admin (%s) on ticket emails', 'mira-event-list' ), esc_html( get_option( 'admin_email' ) ) );
                            ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mira-mb-note"><?php esc_html_e( 'Note', 'mira-event-list' ); ?></label></th>
                        <td><textarea name="admin_note" id="mira-mb-note" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Optional — e.g. paid cash on the door, collected by…', 'mira-event-list' ); ?>"></textarea></td>
                    </tr>
                </table>

                <?php submit_button( __( 'Create Booking', 'mira-event-list' ) ); ?>
            </form>

            <script>
            (function(){
                var eventSel = document.getElementById('mira-mb-event');
                var priceIn  = document.getElementById('mira-mb-price');
                eventSel.addEventListener('change', function(){
                    var opt = eventSel.options[eventSel.selectedIndex];
                    var p   = opt ? opt.getAttribute('data-price') : '';
                    if ( p !== null && p !== '' && ( priceIn.value === '' || priceIn.dataset.autofill === '1' ) ) {
                        priceIn.value = p;
                        priceIn.dataset.autofill = '1';
                    }
                });
                priceIn.addEventListener('input', function(){ priceIn.dataset.autofill = '0'; });

                var received = document.getElementById('mira-mb-received');
                var sendBox  = document.getElementById('mira-mb-send');
                function syncSend(){
                    sendBox.disabled = ! received.checked;
                    sendBox.parentNode.style.opacity = received.checked ? '' : '.5';
                }
                received.addEventListener('change', syncSend);
                syncSend();

                var tbody = document.querySelector('#mira-mb-attendees tbody');
                var rows  = 1;
                document.getElementById('mira-mb-add-row').addEventListener('click', function(){
                    var i  = rows++;
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td style="padding:0 8px 8px 0"><input type="text" name="attendees[' + i + '][name]" placeholder="<?php echo esc_js( __( 'Full name', 'mira-event-list' ) ); ?>" class="regular-text"></td>' +
                        '<td style="padding:0 8px 8px 0"><input type="email" name="attendees[' + i + '][email]" placeholder="email@example.com" class="regular-text"></td>';
                    tbody.appendChild(tr);
                });
            })();
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── List view ─────────────────────────────────────────────────────────

    private function render_list() {
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'mira_bookings';

        $valid_statuses = array( 'pending', 'paid', 'complete' );
        $status_filter  = sanitize_key( $_GET['status'] ?? '' );
        $event_filter   = intval( $_GET['event_id'] ?? 0 );
        $method_filter  = sanitize_key( $_GET['payment_method'] ?? '' );

        if ( ! in_array( $status_filter, $valid_statuses, true ) ) {
            $status_filter = '';
        }
        $valid_methods = array_merge( array( 'stripe' ), array_keys( $this->payment_methods() ) );
        if ( ! in_array( $method_filter, $valid_methods, true ) ) {
            $method_filter = '';
        }

        // Build WHERE
        $conditions = array();
        if ( $status_filter ) {
            $conditions[] = $wpdb->prepare( 'status = %s', $status_filter );
        }
        if ( $event_filter ) {
            $conditions[] = $wpdb->prepare( 'event_id = %d', $event_filter );
        }
        if ( $method_filter ) {
            $conditions[] = $wpdb->prepare( 'payment_method = %s', $method_filter );
        }
        $where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

        $bookings = $wpdb->get_results( "SELECT * FROM $bookings_table $where ORDER BY created_at DESC" );

        // Status counts (respecting event filter)
        $event_where = $event_filter ? $wpdb->prepare( 'WHERE event_id = %d', $event_filter ) : '';
        $counts      = $wpdb->get_results(
            "SELECT status, COUNT(*) as n FROM $bookings_table $event_where GROUP BY status",
            OBJECT_K
        );
        $total = array_sum( wp_list_pluck( $counts, 'n' ) );

        // Events for dropdown (only those with bookings)
        $event_ids    = $wpdb->get_col( "SELECT DISTINCT event_id FROM $bookings_table ORDER BY event_id DESC" );
        $events_list  = array_filter( array_map( 'get_post', $event_ids ) );

        // Summary data (paid + complete only)
        $summary = $wpdb->get_results(
            "SELECT event_id,
                    COUNT(*) AS bookings,
                    SUM(quantity) AS tickets,
                    SUM(total_amount) AS revenue
             FROM $bookings_table
             WHERE status IN ('paid','complete')
             GROUP BY event_id
             ORDER BY revenue DESC"
        );

        $base_url   = admin_url( 'edit.php?post_type=mira_event&page=mira-bookings' );
        $export_url = wp_nonce_url(
            add_query_arg( array_filter( array(
                'page'           => 'mira-bookings',
                'action'         => 'export_csv',
                'status'         => $status_filter ?: null,
                'event_id'       => $event_filter ?: null,
                'payment_method' => $method_filter ?: null,
            ) ), admin_url( 'edit.php?post_type=mira_event' ) ),
            'mira_export_csv'
        );
        $add_url = add_query_arg( array( 'action' => 'add' ), $base_url );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Bookings', 'mira-event-list' ); ?></h1>
            <a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add Manual Booking', 'mira-event-list' ); ?></a>
            <hr class="wp-header-end">

            <?php if ( isset( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Booking deleted.', 'mira-event-list' ); ?></p></div>
            <?php endif; ?>

            <?php if ( isset( $_GET['created'] ) ) : ?>
                <?php echo $this->created_notice( intval( $_GET['sent'] ?? 0 ), ! empty( $_GET['pending'] ) ); ?>
            <?php endif; ?>

            <?php if ( isset( $_GET['marked_paid'] ) ) : ?>
                <?php echo $this->marked_paid_notice( ! empty( $_GET['marked_paid'] ), intval( $_GET['paid_sent'] ?? 0 ) ); ?>
            <?php endif; ?>

            <?php if ( isset( $_GET['resent'] ) ) : ?>
                <?php echo $this->resent_notice( intval( $_GET['resent'] ) ); ?>
            <?php endif; ?>

            <?php if ( isset( $_GET['mailjet_done'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    printf(
                        /* translators: 1: synced, 2: partial, 3: failed */
                        esc_html__( 'Mailjet sync finished. Synced: %1$d · Added without tag: %2$d · Failed: %3$d.', 'mira-event-list' ),
                        intval( $_GET['mailjet_ok'] ?? 0 ),
                        intval( $_GET['mailjet_partial'] ?? 0 ),
                        intval( $_GET['mailjet_failed'] ?? 0 )
                    );
                    if ( intval( $_GET['mailjet_failed'] ?? 0 ) || intval( $_GET['mailjet_partial'] ?? 0 ) ) {
                        echo ' ' . esc_html__( 'See Events → Settings → Mailjet Sync for the last error.', 'mira-event-list' );
                    }
                ?></p></div>
            <?php endif; ?>

            <?php if ( ! empty( $summary ) ) : ?>
            <h2 style="margin-top:1.5em"><?php esc_html_e( 'Revenue Summary (paid &amp; complete bookings)', 'mira-event-list' ); ?></h2>
            <table class="wp-list-table widefat fixed striped" style="max-width:700px;margin-bottom:2em">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Event', 'mira-event-list' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'Bookings', 'mira-event-list' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'Revenue', 'mira-event-list' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $summary as $row ) :
                    $e = get_post( $row->event_id );
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( 'event_id', $row->event_id, $base_url ) ); ?>">
                                <?php echo esc_html( $e ? $e->post_title : '(deleted)' ); ?>
                            </a>
                        </td>
                        <td><?php echo intval( $row->bookings ); ?></td>
                        <td><?php echo intval( $row->tickets ); ?></td>
                        <td>£<?php echo number_format( $row->revenue, 2 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h2><?php esc_html_e( 'All Bookings', 'mira-event-list' ); ?></h2>

            <?php /* Status key */ ?>
            <p style="color:#555;margin-bottom:.5em;font-size:13px">
                <?php echo $this->status_badge( 'pending' ); ?>
                <?php esc_html_e( 'Payment not yet confirmed — an abandoned checkout, or a manual booking awaiting payment', 'mira-event-list' ); ?> &nbsp;
                <?php echo $this->status_badge( 'paid' ); ?>
                <?php esc_html_e( 'Payment confirmed, attendee form not yet completed', 'mira-event-list' ); ?> &nbsp;
                <?php echo $this->status_badge( 'complete' ); ?>
                <?php esc_html_e( 'Payment confirmed, attendee details collected', 'mira-event-list' ); ?>
            </p>

            <?php /* Filters + export */ ?>
            <form method="get" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
                <input type="hidden" name="post_type" value="mira_event">
                <input type="hidden" name="page"      value="mira-bookings">
                <?php if ( $status_filter ) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
                <?php endif; ?>
                <select name="event_id">
                    <option value=""><?php esc_html_e( 'All events', 'mira-event-list' ); ?></option>
                    <?php foreach ( $events_list as $ev ) : ?>
                        <option value="<?php echo esc_attr( $ev->ID ); ?>" <?php selected( $event_filter, $ev->ID ); ?>>
                            <?php echo esc_html( $ev->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="payment_method">
                    <option value=""><?php esc_html_e( 'All methods', 'mira-event-list' ); ?></option>
                    <option value="stripe" <?php selected( $method_filter, 'stripe' ); ?>><?php esc_html_e( 'Stripe', 'mira-event-list' ); ?></option>
                    <?php foreach ( $this->payment_methods() as $slug => $label ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $method_filter, $slug ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'mira-event-list' ); ?></button>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
                    ⬇ <?php esc_html_e( 'Export CSV', 'mira-event-list' ); ?>
                </a>
                <?php if ( class_exists( 'MiraMailjet' ) && MiraMailjet::is_enabled() ) :
                    $mailjet_url = wp_nonce_url(
                        add_query_arg( array(
                            'post_type' => 'mira_event',
                            'page'      => 'mira-bookings',
                            'action'    => 'mailjet_sync',
                            'offset'    => 0,
                        ), admin_url( 'edit.php' ) ),
                        'mira_mailjet_sync'
                    );
                    ?>
                    <a href="<?php echo esc_url( $mailjet_url ); ?>" class="button button-secondary"
                       onclick="return confirm('<?php esc_attr_e( 'Sync every buyer and attendee email from all paid and complete bookings to Mailjet now?', 'mira-event-list' ); ?>')">
                        <?php esc_html_e( 'Sync all to Mailjet', 'mira-event-list' ); ?>
                    </a>
                <?php endif; ?>
            </form>

            <ul class="subsubsub" style="margin-bottom:8px">
                <li>
                    <a href="<?php echo esc_url( add_query_arg( array_filter( array(
                        'event_id'       => $event_filter ?: null,
                        'payment_method' => $method_filter ?: null,
                    ) ), $base_url ) ); ?>"
                       <?php echo ! $status_filter ? 'class="current"' : ''; ?>>
                        <?php esc_html_e( 'All', 'mira-event-list' ); ?>
                        <span class="count">(<?php echo intval( $total ); ?>)</span>
                    </a> |
                </li>
                <?php foreach ( $valid_statuses as $i => $s ) :
                    $n       = isset( $counts[ $s ] ) ? intval( $counts[ $s ]->n ) : 0;
                    $tab_url = add_query_arg( array_filter( array(
                        'status'         => $s,
                        'event_id'       => $event_filter ?: null,
                        'payment_method' => $method_filter ?: null,
                    ) ), $base_url );
                    ?>
                    <li>
                        <a href="<?php echo esc_url( $tab_url ); ?>"
                           <?php echo $status_filter === $s ? 'class="current"' : ''; ?>>
                            <?php echo esc_html( ucfirst( $s ) ); ?>
                            <span class="count">(<?php echo $n; ?>)</span>
                        </a>
                        <?php echo $i < count( $valid_statuses ) - 1 ? ' |' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:130px"><?php esc_html_e( 'Reference', 'mira-event-list' ); ?></th>
                        <th><?php esc_html_e( 'Event', 'mira-event-list' ); ?></th>
                        <th><?php esc_html_e( 'Lead Contact', 'mira-event-list' ); ?></th>
                        <th style="width:60px"><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></th>
                        <th style="width:80px"><?php esc_html_e( 'Total', 'mira-event-list' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'Method', 'mira-event-list' ); ?></th>
                        <th style="width:90px"><?php esc_html_e( 'Status', 'mira-event-list' ); ?></th>
                        <th style="width:130px"><?php esc_html_e( 'Date', 'mira-event-list' ); ?></th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $bookings ) ) : ?>
                    <tr><td colspan="9"><?php esc_html_e( 'No bookings found.', 'mira-event-list' ); ?></td></tr>
                <?php else :
                    // Preload lead contacts for all bookings in one query
                    $booking_ids   = wp_list_pluck( $bookings, 'id' );
                    $ids_in        = implode( ',', array_map( 'intval', $booking_ids ) );
                    $leads         = $wpdb->get_results(
                        "SELECT booking_id, name, email FROM {$wpdb->prefix}mira_attendees WHERE booking_id IN ($ids_in) AND is_lead = 1"
                    );
                    $leads_by_id   = array();
                    foreach ( $leads as $l ) {
                        $leads_by_id[ $l->booking_id ] = $l;
                    }

                    foreach ( $bookings as $b ) :
                        $event      = get_post( $b->event_id );
                        $event_name = $event ? $event->post_title : '(deleted)';
                        $detail_url = add_query_arg( 'booking_id', $b->id, $base_url );
                        $delete_url = wp_nonce_url(
                            add_query_arg( array(
                                'page'       => 'mira-bookings',
                                'action'     => 'delete',
                                'booking_id' => $b->id,
                            ), admin_url( 'edit.php?post_type=mira_event' ) ),
                            'mira_delete_booking_' . $b->id
                        );
                        $resend_url = wp_nonce_url(
                            add_query_arg( array(
                                'page'       => 'mira-bookings',
                                'action'     => 'resend_tickets',
                                'booking_id' => $b->id,
                            ), admin_url( 'edit.php?post_type=mira_event' ) ),
                            'mira_resend_tickets_' . $b->id
                        );
                        $mark_paid_url = wp_nonce_url(
                            add_query_arg( array(
                                'page'       => 'mira-bookings',
                                'action'     => 'toggle_paid',
                                'booking_id' => $b->id,
                            ), admin_url( 'edit.php?post_type=mira_event' ) ),
                            'mira_toggle_paid_' . $b->id
                        );
                        $b_is_manual = $b->payment_method && $b->payment_method !== 'stripe';
                        $lead = $leads_by_id[ $b->id ] ?? null;
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( $detail_url ); ?>">
                                <strong><?php echo esc_html( $b->booking_reference ); ?></strong>
                            </a>
                        </td>
                        <td><?php echo esc_html( $event_name ); ?></td>
                        <td>
                            <?php if ( $lead ) : ?>
                                <?php echo esc_html( $lead->name ); ?><br>
                                <small><?php echo esc_html( $lead->email ); ?></small>
                            <?php elseif ( ! empty( $b->lead_email ) ) : ?>
                                <a href="mailto:<?php echo esc_attr( $b->lead_email ); ?>"><?php echo esc_html( $b->lead_email ); ?></a>
                            <?php else : ?>
                                <em style="color:#999"><?php esc_html_e( 'Not collected', 'mira-event-list' ); ?></em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval( $b->quantity ); ?></td>
                        <td>£<?php echo number_format( $b->total_amount, 2 ); ?></td>
                        <td>
                            <?php
                            $mb = $this->payment_method_badge( $b->payment_method );
                            echo $mb ? $mb : '<span style="color:#999">' . esc_html__( 'Stripe', 'mira-event-list' ) . '</span>';
                            if ( $mb && ! $b->payment_received && $b->total_amount > 0 ) {
                                echo '<br><span style="color:#b00;font-size:11px;font-weight:600">' . esc_html__( 'UNPAID', 'mira-event-list' ) . '</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo $this->status_badge( $b->status ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'd M Y H:i', strtotime( $b->created_at ) ) ); ?></td>
                        <td>
                            <?php if ( $b_is_manual && ! $b->payment_received ) : ?>
                                <a href="<?php echo esc_url( $mark_paid_url ); ?>"
                                   onclick="return confirm('<?php esc_attr_e( 'Mark this booking as paid and email tickets to every attendee who has not had one yet?', 'mira-event-list' ); ?>')">
                                    <strong><?php esc_html_e( 'Mark paid &amp; send', 'mira-event-list' ); ?></strong>
                                </a><br>
                            <?php endif; ?>
                            <?php if ( $b->status === 'complete' ) : ?>
                                <a href="<?php echo esc_url( $resend_url ); ?>"
                                   onclick="return confirm('<?php esc_attr_e( 'Re-send the ticket email to every attendee on this booking? A copy will be sent to the site admin.', 'mira-event-list' ); ?>')">
                                    <?php esc_html_e( 'Resend', 'mira-event-list' ); ?>
                                </a><br>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( $delete_url ); ?>"
                               style="color:#b00"
                               onclick="return confirm('<?php esc_attr_e( 'Delete this booking and all its attendee records? This cannot be undone.', 'mira-event-list' ); ?>')">
                                <?php esc_html_e( 'Delete', 'mira-event-list' ); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Detail view ───────────────────────────────────────────────────────

    private function render_detail( $booking_id ) {
        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $bookings_table WHERE id = %d", $booking_id ) );

        if ( ! $booking ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Booking not found.', 'mira-event-list' ) . '</p></div>';
            return;
        }

        $event     = get_post( $booking->event_id );
        $attendees = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $attendees_table WHERE booking_id = %d ORDER BY is_lead DESC, id ASC",
            $booking_id
        ) );

        $back_url   = admin_url( 'edit.php?post_type=mira_event&page=mira-bookings' );
        $delete_url = wp_nonce_url(
            add_query_arg( array(
                'page'       => 'mira-bookings',
                'action'     => 'delete',
                'booking_id' => $booking->id,
            ), admin_url( 'edit.php?post_type=mira_event' ) ),
            'mira_delete_booking_' . $booking->id
        );
        $resend_url = wp_nonce_url(
            add_query_arg( array(
                'page'       => 'mira-bookings',
                'action'     => 'resend_tickets',
                'booking_id' => $booking->id,
            ), admin_url( 'edit.php?post_type=mira_event' ) ),
            'mira_resend_tickets_' . $booking->id
        );
        $toggle_paid_url = wp_nonce_url(
            add_query_arg( array(
                'page'       => 'mira-bookings',
                'action'     => 'toggle_paid',
                'booking_id' => $booking->id,
            ), admin_url( 'edit.php?post_type=mira_event' ) ),
            'mira_toggle_paid_' . $booking->id
        );
        $is_manual = $booking->payment_method && $booking->payment_method !== 'stripe';
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html( $booking->booking_reference ); ?>
                <?php echo $this->status_badge( $booking->status ); ?>
            </h1>

            <?php if ( isset( $_GET['resent'] ) ) : ?>
                <?php echo $this->resent_notice( intval( $_GET['resent'] ) ); ?>
            <?php endif; ?>

            <?php if ( isset( $_GET['marked_paid'] ) ) : ?>
                <?php echo $this->marked_paid_notice( ! empty( $_GET['marked_paid'] ), intval( $_GET['paid_sent'] ?? 0 ) ); ?>
            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url( $back_url ); ?>">← <?php esc_html_e( 'Back to Bookings', 'mira-event-list' ); ?></a>
                &nbsp;&nbsp;
                <?php if ( $is_manual && ! $booking->payment_received ) : ?>
                    <a href="<?php echo esc_url( $toggle_paid_url ); ?>" class="button button-primary"
                       onclick="return confirm('<?php esc_attr_e( 'Mark this booking as paid and email tickets to every attendee who has not had one yet?', 'mira-event-list' ); ?>')">
                        <?php esc_html_e( 'Mark as paid & send tickets', 'mira-event-list' ); ?>
                    </a>
                    &nbsp;&nbsp;
                <?php endif; ?>
                <?php if ( ! empty( $attendees ) ) : ?>
                    <a href="<?php echo esc_url( $resend_url ); ?>"
                       onclick="return confirm('<?php esc_attr_e( 'Re-send the ticket email to every attendee on this booking? A copy will be sent to the site admin.', 'mira-event-list' ); ?>')">
                        <?php esc_html_e( 'Resend tickets', 'mira-event-list' ); ?>
                    </a>
                    &nbsp;&nbsp;
                <?php endif; ?>
                <a href="<?php echo esc_url( $delete_url ); ?>"
                   style="color:#b00"
                   onclick="return confirm('<?php esc_attr_e( 'Delete this booking and all its attendee records? This cannot be undone.', 'mira-event-list' ); ?>')">
                    <?php esc_html_e( 'Delete booking', 'mira-event-list' ); ?>
                </a>
            </p>

            <table class="form-table" style="max-width:600px">
                <tr>
                    <th><?php esc_html_e( 'Event', 'mira-event-list' ); ?></th>
                    <td><?php echo esc_html( $event ? $event->post_title : '(deleted)' ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></th>
                    <td><?php echo intval( $booking->quantity ); ?> × £<?php echo number_format( $booking->ticket_price, 2 ); ?></td>
                </tr>
                <?php if ( $booking->donation_amount > 0 ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Donation', 'mira-event-list' ); ?></th>
                    <td>£<?php echo number_format( $booking->donation_amount, 2 ); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php esc_html_e( 'Total', 'mira-event-list' ); ?></th>
                    <td><strong>£<?php echo number_format( $booking->total_amount, 2 ); ?></strong></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Payment method', 'mira-event-list' ); ?></th>
                    <td>
                        <?php echo esc_html( $this->payment_method_label( $booking->payment_method ) ); ?>
                        <?php if ( $is_manual ) : ?>
                            &nbsp;—&nbsp;
                            <?php if ( $booking->payment_received ) : ?>
                                <span style="color:#15803d;font-weight:600"><?php esc_html_e( 'payment received', 'mira-event-list' ); ?></span>
                                (<a href="<?php echo esc_url( $toggle_paid_url ); ?>"
                                    onclick="return confirm('<?php esc_attr_e( 'Mark this booking as not paid?', 'mira-event-list' ); ?>')"><?php esc_html_e( 'mark unpaid', 'mira-event-list' ); ?></a>)
                            <?php else : ?>
                                <span style="color:#b00;font-weight:600"><?php esc_html_e( 'unpaid', 'mira-event-list' ); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ( ! empty( $booking->admin_note ) ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Note', 'mira-event-list' ); ?></th>
                    <td><?php echo nl2br( esc_html( $booking->admin_note ) ); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( ! empty( $booking->lead_email ) ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Contact email', 'mira-event-list' ); ?></th>
                    <td><a href="mailto:<?php echo esc_attr( $booking->lead_email ); ?>"><?php echo esc_html( $booking->lead_email ); ?></a></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php esc_html_e( 'Date', 'mira-event-list' ); ?></th>
                    <td><?php echo esc_html( date_i18n( 'd M Y H:i', strtotime( $booking->created_at ) ) ); ?></td>
                </tr>
                <?php if ( $booking->stripe_payment_intent ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Stripe payment', 'mira-event-list' ); ?></th>
                    <td><code><?php echo esc_html( $booking->stripe_payment_intent ); ?></code></td>
                </tr>
                <?php endif; ?>
            </table>

            <h2><?php esc_html_e( 'Attendees', 'mira-event-list' ); ?></h2>

            <?php if ( empty( $attendees ) ) : ?>
                <p><?php esc_html_e( 'No attendee details collected yet.', 'mira-event-list' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="max-width:700px">
                    <thead>
                        <tr>
                            <th style="width:140px"><?php esc_html_e( 'Ticket #', 'mira-event-list' ); ?></th>
                            <th><?php esc_html_e( 'Name', 'mira-event-list' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'mira-event-list' ); ?></th>
                            <th style="width:130px"><?php esc_html_e( 'Ticket sent', 'mira-event-list' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $attendees as $a ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $a->ticket_number ); ?>
                                <?php if ( $a->is_lead ) : ?>
                                    <span class="dashicons dashicons-admin-users" title="<?php esc_attr_e( 'Lead booker', 'mira-event-list' ); ?>"></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $a->name ); ?></td>
                            <td><?php echo esc_html( $a->email ); ?></td>
                            <td>
                                <?php echo $a->sent_at
                                    ? esc_html( date_i18n( 'd M Y H:i', strtotime( $a->sent_at ) ) )
                                    : '<em>' . esc_html__( 'Not sent', 'mira-event-list' ) . '</em>'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── CSV export ────────────────────────────────────────────────────────

    private function output_csv() {
        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        $valid_statuses = array( 'pending', 'paid', 'complete' );
        $status_filter  = sanitize_key( $_GET['status'] ?? '' );
        $event_filter   = intval( $_GET['event_id'] ?? 0 );
        $method_filter  = sanitize_key( $_GET['payment_method'] ?? '' );

        if ( ! in_array( $status_filter, $valid_statuses, true ) ) {
            $status_filter = '';
        }
        $valid_methods = array_merge( array( 'stripe' ), array_keys( $this->payment_methods() ) );
        if ( ! in_array( $method_filter, $valid_methods, true ) ) {
            $method_filter = '';
        }

        $conditions = array();
        if ( $status_filter ) {
            $conditions[] = $wpdb->prepare( 'b.status = %s', $status_filter );
        }
        if ( $event_filter ) {
            $conditions[] = $wpdb->prepare( 'b.event_id = %d', $event_filter );
        }
        if ( $method_filter ) {
            $conditions[] = $wpdb->prepare( 'b.payment_method = %s', $method_filter );
        }
        $where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

        $rows = $wpdb->get_results(
            "SELECT b.booking_reference, b.event_id, b.created_at, b.status,
                    b.quantity, b.ticket_price, b.donation_amount, b.total_amount,
                    b.payment_method, b.payment_received, b.admin_note,
                    b.stripe_payment_intent,
                    a.ticket_number, a.name, a.email, a.is_lead, a.sent_at
             FROM $bookings_table b
             LEFT JOIN $attendees_table a ON a.booking_id = b.id
             $where
             ORDER BY b.created_at DESC, b.id, a.is_lead DESC, a.id"
        );

        $filename = 'bookings';
        if ( $event_filter ) {
            $ev = get_post( $event_filter );
            if ( $ev ) {
                $filename .= '-' . sanitize_title( $ev->post_title );
            }
        }
        $filename .= '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // UTF-8 BOM for Excel

        fputcsv( $out, array(
            'Booking Ref', 'Event', 'Date', 'Status',
            'Tickets', 'Ticket Price', 'Donation', 'Total',
            'Payment Method', 'Payment Received', 'Note',
            'Stripe Payment Intent',
            'Ticket #', 'Name', 'Email', 'Lead Booker', 'Ticket Email Sent',
        ) );

        foreach ( $rows as $r ) {
            $ev = get_post( $r->event_id );
            fputcsv( $out, array(
                $r->booking_reference,
                $ev ? $ev->post_title : '(deleted)',
                $r->created_at,
                $r->status,
                $r->quantity,
                $r->ticket_price,
                $r->donation_amount,
                $r->total_amount,
                $this->payment_method_label( $r->payment_method ),
                $r->payment_received ? 'Yes' : 'No',
                $r->admin_note ?? '',
                $r->stripe_payment_intent,
                $r->ticket_number ?? '',
                $r->name ?? '',
                $r->email ?? '',
                $r->is_lead ? 'Yes' : ( $r->ticket_number ? 'No' : '' ),
                $r->sent_at ?? '',
            ) );
        }

        fclose( $out );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function created_notice( $sent, $pending ) {
        $msg = $pending
            ? __( 'Manual booking saved as pending. Mark it paid when the money arrives — that sends the tickets.', 'mira-event-list' )
            : __( 'Manual booking created.', 'mira-event-list' );

        if ( ! $pending && $sent > 0 ) {
            $msg .= ' ' . sprintf(
                /* translators: %d: number of ticket emails sent */
                _n( '%d ticket email sent.', '%d ticket emails sent.', $sent, 'mira-event-list' ),
                $sent
            );
        }

        return '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }

    private function marked_paid_notice( $now_paid, $sent ) {
        if ( ! $now_paid ) {
            return '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Booking marked as not paid.', 'mira-event-list' )
                . '</p></div>';
        }

        $msg = __( 'Booking marked as paid.', 'mira-event-list' );
        $msg .= ' ' . ( $sent > 0
            ? sprintf(
                /* translators: %d: number of ticket emails sent */
                _n( '%d ticket email sent.', '%d ticket emails sent.', $sent, 'mira-event-list' ),
                $sent
            )
            : __( 'No new ticket emails were needed.', 'mira-event-list' ) );

        return '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }

    private function resent_notice( $count ) {
        if ( $count < 1 ) {
            return '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html__( 'No tickets were re-sent — this booking has no attendee details yet.', 'mira-event-list' )
                . '</p></div>';
        }

        $admin_email = get_option( 'admin_email' );

        return '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
            /* translators: 1: number of tickets, 2: admin email address */
            _n(
                '%1$d ticket re-sent. A copy was also sent to %2$s.',
                '%1$d tickets re-sent. A copy was also sent to %2$s.',
                $count,
                'mira-event-list'
            ),
            $count,
            $admin_email
        ) ) . '</p></div>';
    }

    private function status_badge( $status ) {
        $colours = array(
            'pending'  => '#b45309',
            'paid'     => '#1d4ed8',
            'complete' => '#15803d',
        );
        $bg = isset( $colours[ $status ] ) ? $colours[ $status ] : '#6b7280';
        return sprintf(
            '<span style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;text-transform:uppercase">%s</span>',
            esc_attr( $bg ),
            esc_html( $status )
        );
    }
}
