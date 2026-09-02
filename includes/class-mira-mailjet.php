<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mailjet contact sync.
 *
 * Pushes booking email addresses into a Mailjet contact list and tags each
 * contact with a per-event boolean contact property so campaigns can be
 * targeted at the people who booked a specific event.
 *
 * One call per contact: POST /v3/REST/contactslist/{list}/managecontact with
 * Action=addnoforce creates (or finds) the contact, adds it to the list and
 * sets the properties. The per-event boolean property is created on the fly
 * via POST /v3/REST/contactmetadata the first time it is used.
 */
class MiraMailjet {

    const API_BASE      = 'https://api.mailjet.com/v3/REST/';
    const KNOWN_PROPS   = 'mira_mailjet_known_props';   // array of property names already ensured
    const LAST_ERROR    = 'mira_mailjet_last_error';    // human-readable last failure
    const LISTS_CACHE   = 'mira_mailjet_lists';         // transient: [ [id,name,count], ... ]

    /* ── Configuration ─────────────────────────────────────────────────── */

    public static function api_key() {
        return trim( (string) get_option( 'mira_mailjet_api_key', '' ) );
    }

    public static function secret_key() {
        return trim( (string) get_option( 'mira_mailjet_secret_key', '' ) );
    }

    public static function list_id() {
        return trim( (string) get_option( 'mira_mailjet_list_id', '' ) );
    }

    /** Keys present — enough to call the API (e.g. to list contact lists). */
    public static function has_keys() {
        return self::api_key() !== '' && self::secret_key() !== '';
    }

    /** Keys + target list present. */
    public static function is_configured() {
        return self::has_keys() && self::list_id() !== '';
    }

    /** Fully configured and the master switch is on. */
    public static function is_enabled() {
        return get_option( 'mira_mailjet_enabled', '' ) === '1' && self::is_configured();
    }

    /* ── Low-level request ─────────────────────────────────────────────── */

    /**
     * @return array{code:int,data:mixed}|WP_Error
     */
    public static function request( $method, $path, $body = null ) {
        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( self::api_key() . ':' . self::secret_key() ),
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 10,
        );

        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }

        $url = ( strpos( $path, 'http' ) === 0 ) ? $path : self::API_BASE . ltrim( $path, '/' );
        $res = wp_remote_request( $url, $args );

        if ( is_wp_error( $res ) ) {
            return $res;
        }

        return array(
            'code' => (int) wp_remote_retrieve_response_code( $res ),
            'data' => json_decode( wp_remote_retrieve_body( $res ), true ),
        );
    }

    private static function ok( $code ) {
        return $code >= 200 && $code < 300;
    }

    private static function error_message( $res ) {
        if ( is_wp_error( $res ) ) {
            return $res->get_error_message();
        }
        if ( isset( $res['data']['ErrorMessage'] ) ) {
            return $res['data']['ErrorMessage'];
        }
        return 'HTTP ' . $res['code'];
    }

    private static function log_error( $context, $message ) {
        $entry = sprintf( '[%s] %s — %s', current_time( 'mysql' ), $context, $message );
        update_option( self::LAST_ERROR, $entry, false );
        error_log( 'MiraMailjet: ' . $entry );
    }

    /* ── Per-event tag (boolean contact property) ──────────────────────── */

    /** Coerce arbitrary text into a valid Mailjet property name. */
    public static function sanitize_tag( $raw ) {
        $tag = strtolower( trim( (string) $raw ) );
        $tag = preg_replace( '/[^a-z0-9]+/', '_', $tag );
        $tag = trim( $tag, '_' );
        return substr( $tag, 0, 120 );
    }

    /** The tag we would auto-generate for an event: evt_<yyyymmdd>_<slug>. */
    public static function generate_event_tag( $event_id ) {
        $post = get_post( $event_id );
        if ( ! $post ) {
            return '';
        }
        $date = get_post_meta( $event_id, '_event_date', true );
        $ymd  = $date ? gmdate( 'Ymd', strtotime( $date ) ) : '';
        $slug = $post->post_name ?: sanitize_title( $post->post_title );
        return self::sanitize_tag( 'evt_' . ( $ymd ? $ymd . '_' : '' ) . $slug );
    }

    /**
     * The tag actually in use for an event: the editor override if set,
     * otherwise the generated one (persisted on first use so it stays stable
     * even if the event is later renamed or rescheduled).
     */
    public static function event_tag( $event_id ) {
        $stored = get_post_meta( $event_id, '_mailjet_event_tag', true );
        if ( $stored ) {
            return self::sanitize_tag( $stored );
        }
        $tag = self::generate_event_tag( $event_id );
        if ( $tag ) {
            update_post_meta( $event_id, '_mailjet_event_tag', $tag );
        }
        return $tag;
    }

    /** Create the boolean contact property in Mailjet if we haven't already. */
    public static function ensure_property( $name ) {
        if ( ! $name ) {
            return false;
        }

        $known = (array) get_option( self::KNOWN_PROPS, array() );
        if ( in_array( $name, $known, true ) ) {
            return true;
        }

        $res = self::request( 'POST', 'contactmetadata', array(
            'Datatype'  => 'bool',
            'NameSpace' => 'static',
            'Name'      => $name,
        ) );

        $ok = false;
        if ( ! is_wp_error( $res ) ) {
            if ( self::ok( $res['code'] ) ) {
                $ok = true;
            } elseif ( $res['code'] === 400 || $res['code'] === 304 ) {
                // Already exists — treat as success.
                $msg = strtolower( (string) self::error_message( $res ) );
                $ok  = ( strpos( $msg, 'already' ) !== false || strpos( $msg, 'duplicate' ) !== false );
            }
        }

        if ( $ok ) {
            $known[] = $name;
            update_option( self::KNOWN_PROPS, array_values( array_unique( $known ) ), false );
        } else {
            self::log_error( 'ensure_property ' . $name, self::error_message( $res ) );
        }

        return $ok;
    }

    /* ── Sync a single contact ─────────────────────────────────────────── */

    /**
     * @return string 'ok' | 'partial' (added to list, tag not set) | 'failed' | 'skipped'
     */
    public static function sync_contact( $email, $name, $tag ) {
        if ( ! self::is_enabled() ) {
            return 'skipped';
        }

        $email = sanitize_email( $email );
        if ( ! $email || ! is_email( $email ) ) {
            return 'skipped';
        }

        if ( $tag ) {
            self::ensure_property( $tag );
        }

        $path = 'contactslist/' . rawurlencode( self::list_id() ) . '/managecontact';

        $body = array(
            'Email'  => $email,
            'Action' => 'addnoforce',
        );
        if ( $name ) {
            $body['Name'] = $name;
        }
        if ( $tag ) {
            $body['Properties'] = array( $tag => true );
        }

        $res = self::request( 'POST', $path, $body );

        if ( ! is_wp_error( $res ) && self::ok( $res['code'] ) ) {
            return 'ok';
        }

        // The property may have been rejected (e.g. restricted API key could not
        // create it). Retry without it so the email still lands on the list.
        if ( $tag && ( is_wp_error( $res ) || $res['code'] >= 400 ) ) {
            unset( $body['Properties'] );
            $retry = self::request( 'POST', $path, $body );
            if ( ! is_wp_error( $retry ) && self::ok( $retry['code'] ) ) {
                self::log_error( $email, sprintf( 'added to list but tag "%s" not set: %s', $tag, self::error_message( $res ) ) );
                return 'partial';
            }
            $res = $retry;
        }

        self::log_error( $email, self::error_message( $res ) );
        return 'failed';
    }

    /* ── Booking-level helpers ─────────────────────────────────────────── */

    /** Sync the checkout / lead email for a booking row. */
    public static function sync_booking_lead( $booking ) {
        if ( ! $booking || empty( $booking->lead_email ) ) {
            return;
        }
        self::sync_contact( $booking->lead_email, '', self::event_tag( $booking->event_id ) );
    }

    /** Sync every named attendee email for a booking. */
    public static function sync_booking_attendees( $booking_id ) {
        global $wpdb;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT event_id FROM {$wpdb->prefix}mira_bookings WHERE id = %d",
            $booking_id
        ) );
        if ( ! $booking ) {
            return;
        }

        $tag       = self::event_tag( $booking->event_id );
        $attendees = $wpdb->get_results( $wpdb->prepare(
            "SELECT name, email FROM {$wpdb->prefix}mira_attendees WHERE booking_id = %d",
            $booking_id
        ) );

        foreach ( $attendees as $a ) {
            self::sync_contact( $a->email, $a->name, $tag );
        }
    }

    /* ── Settings helpers ──────────────────────────────────────────────── */

    /** Contact lists on the account, for the settings-page picker. Cached 5 min. */
    public static function get_lists( $force = false ) {
        if ( ! $force ) {
            $cache = get_transient( self::LISTS_CACHE );
            if ( is_array( $cache ) ) {
                return $cache;
            }
        }

        if ( ! self::has_keys() ) {
            return array();
        }

        $res = self::request( 'GET', 'contactslist?Limit=1000&Sort=Name' );
        if ( is_wp_error( $res ) || $res['code'] !== 200 || empty( $res['data']['Data'] ) ) {
            return array();
        }

        $lists = array();
        foreach ( $res['data']['Data'] as $l ) {
            if ( ! empty( $l['IsDeleted'] ) ) {
                continue;
            }
            $lists[] = array(
                'id'    => (int) $l['ID'],
                'name'  => (string) $l['Name'],
                'count' => (int) ( $l['SubscriberCount'] ?? 0 ),
            );
        }

        set_transient( self::LISTS_CACHE, $lists, 5 * MINUTE_IN_SECONDS );
        return $lists;
    }
}
