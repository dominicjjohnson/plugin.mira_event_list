<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MiraDatabase {

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_bookings = "CREATE TABLE $bookings_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id bigint(20) UNSIGNED NOT NULL,
            stripe_session_id varchar(255) NOT NULL DEFAULT '',
            stripe_payment_intent varchar(255) NOT NULL DEFAULT '',
            booking_reference varchar(20) NOT NULL,
            lead_email varchar(255) NOT NULL DEFAULT '',
            quantity smallint(5) UNSIGNED NOT NULL DEFAULT 1,
            ticket_price decimal(10,2) NOT NULL DEFAULT 0.00,
            donation_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            total_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_stripe_session (stripe_session_id(50)),
            KEY idx_event_id (event_id),
            KEY idx_status (status)
        ) $charset_collate;";

        $sql_attendees = "CREATE TABLE $attendees_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) UNSIGNED NOT NULL,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            ticket_number varchar(50) NOT NULL,
            is_lead tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_booking_id (booking_id),
            UNIQUE KEY uniq_ticket (ticket_number)
        ) $charset_collate;";

        dbDelta( $sql_bookings );
        dbDelta( $sql_attendees );
    }

    public static function create_success_page() {
        $page_id = (int) get_option( 'mira_stripe_success_page_id', 0 );

        if ( $page_id && get_post( $page_id ) ) {
            return $page_id;
        }

        // Check if a page with this slug already exists
        $existing = get_page_by_path( 'booking-complete' );
        if ( $existing ) {
            update_option( 'mira_stripe_success_page_id', $existing->ID );
            return $existing->ID;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => 'Booking Complete',
            'post_name'    => 'booking-complete',
            'post_content' => '[mira_booking_success]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );

        if ( ! is_wp_error( $page_id ) ) {
            update_option( 'mira_stripe_success_page_id', $page_id );
            return $page_id;
        }

        return 0;
    }
}
