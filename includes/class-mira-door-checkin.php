<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * No-login door check-in page: /door-checkin/?key=SECRET[&event=ID]
 *
 * Door staff share one device and can't practically log into wp-admin
 * between arrivals, so access is gated by a long random secret in the URL
 * instead of a WP account. Treat that URL like a password — only share it
 * with door staff, never post it publicly.
 */
class MiraDoorCheckin {

    const QUERY_VAR = 'mira_door_checkin';

    /** Change this before sharing the door link. */
    const SECRET = 'fe3f1b1a0f2ba3d8b5639a27e0f7c4fbee837ab1fcc482a2';

    public function __construct() {
        add_action( 'init', array( $this, 'add_rewrite_rule' ) );
        add_filter( 'query_vars', array( $this, 'add_query_var' ) );
        add_action( 'template_redirect', array( $this, 'maybe_render' ) );
    }

    public function add_rewrite_rule() {
        add_rewrite_rule( '^door-checkin/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
    }

    public function add_query_var( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function maybe_render() {
        if ( ! get_query_var( self::QUERY_VAR ) ) {
            return;
        }

        $key = isset( $_REQUEST['key'] ) ? (string) $_REQUEST['key'] : '';
        if ( ! hash_equals( self::SECRET, $key ) ) {
            status_header( 403 );
            nocache_headers();
            exit( 'Forbidden' );
        }

        global $wpdb;
        $bookings_table  = $wpdb->prefix . 'mira_bookings';
        $attendees_table = $wpdb->prefix . 'mira_attendees';

        // AJAX: toggle one attendee's checked-in state.
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && isset( $_POST['toggle_id'] ) ) {
            nocache_headers();
            header( 'Content-Type: application/json' );

            $id  = absint( $_POST['toggle_id'] );
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT checked_in_at FROM $attendees_table WHERE id = %d", $id
            ) );
            if ( ! $row ) {
                echo wp_json_encode( array( 'error' => 'not found' ) );
                exit;
            }

            $new_value = $row->checked_in_at ? null : current_time( 'mysql' );
            $wpdb->update(
                $attendees_table,
                array( 'checked_in_at' => $new_value ),
                array( 'id' => $id )
            );

            echo wp_json_encode( array( 'checked_in_at' => $new_value ) );
            exit;
        }

        // AJAX: mark a whole booking as paid (e.g. cash on the door).
        // Payment applies to the booking, not one ticket, so this updates
        // every attendee row that shares this booking_id.
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && isset( $_POST['mark_paid_booking_id'] ) ) {
            nocache_headers();
            header( 'Content-Type: application/json' );

            $booking_id = absint( $_POST['mark_paid_booking_id'] );
            $wpdb->update(
                $bookings_table,
                array( 'payment_received' => 1, 'status' => 'complete' ),
                array( 'id' => $booking_id ),
                array( '%d', '%s' ),
                array( '%d' )
            );

            $status = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM $bookings_table WHERE id = %d", $booking_id
            ) );

            echo wp_json_encode( array( 'status' => $status ) );
            exit;
        }

        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );

        $event_id = absint( $_GET['event'] ?? 0 );

        echo '<!doctype html><html><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html__( 'Door Check-in', 'mira-event-list' ) . '</title>';
        $this->render_styles();
        echo '</head><body>';

        if ( ! $event_id ) {
            $this->render_event_picker( $wpdb, $bookings_table, $attendees_table, $key );
        } else {
            $this->render_event_list( $wpdb, $bookings_table, $attendees_table, $key, $event_id );
        }

        echo '</body></html>';
        exit;
    }

    private function render_styles() {
        ?>
        <style>
            * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #111; color: #eee; margin: 0; padding: 16px; }
            h1 { font-size: 1.4rem; margin: 0 0 16px; }
            a { color: #6cf; }
            .event-link { display: block; background: #222; border: 1px solid #333; border-radius: 10px; padding: 14px 16px; margin-bottom: 10px; text-decoration: none; color: #eee; }
            .event-link .count { color: #9a9; font-size: 0.9rem; }
            #summary { position: sticky; top: 0; background: #111; padding: 8px 0 12px; font-size: 1.2rem; font-weight: 600; z-index: 5; }
            #search { width: 100%; padding: 14px; font-size: 1.1rem; border-radius: 10px; border: 1px solid #444; background: #1c1c1c; color: #fff; margin-bottom: 12px; }
            .row { display: flex; align-items: center; justify-content: space-between; background: #1c1c1c; border-radius: 10px; padding: 12px 14px; margin-bottom: 8px; }
            .row.hidden { display: none; }
            .row .info { flex: 1; min-width: 0; }
            .row .name { font-size: 1.05rem; font-weight: 600; }
            .row .meta { font-size: 0.85rem; color: #999; margin-top: 2px; }
            .row .meta.pending { color: #e8b93f; }
            .btn { flex-shrink: 0; margin-left: 12px; padding: 12px 18px; border-radius: 8px; border: none; font-size: 1rem; font-weight: 600; }
            .btn.in { background: #2a2; color: #fff; }
            .btn.out { background: #333; color: #ddd; border: 1px solid #555; }
            .btn.paid { background: #e8b93f; color: #111; }
            .back { display: inline-block; margin-bottom: 12px; }
        </style>
        <?php
    }

    private function render_event_picker( $wpdb, $bookings_table, $attendees_table, $key ) {
        $events = $wpdb->get_results( "
            SELECT p.ID, p.post_title, p.post_date,
                   COUNT(a.id) AS total, SUM(a.checked_in_at IS NOT NULL) AS checked_in
            FROM {$wpdb->posts} p
            JOIN $bookings_table b ON b.event_id = p.ID
            JOIN $attendees_table a ON a.booking_id = b.id
            WHERE p.post_type = 'mira_event'
            GROUP BY p.ID
            ORDER BY p.post_date DESC
        " );

        echo '<h1>' . esc_html__( 'Select an event', 'mira-event-list' ) . '</h1>';
        if ( ! $events ) {
            echo '<p>' . esc_html__( 'No events with bookings found.', 'mira-event-list' ) . '</p>';
            return;
        }
        foreach ( $events as $e ) {
            $url = add_query_arg( array( 'key' => $key, 'event' => $e->ID ), home_url( '/door-checkin/' ) );
            printf(
                '<a class="event-link" href="%s"><div>%s</div><div class="count">%d / %d checked in &middot; %s</div></a>',
                esc_url( $url ),
                esc_html( $e->post_title ),
                (int) $e->checked_in,
                (int) $e->total,
                esc_html( date_i18n( 'j M Y', strtotime( $e->post_date ) ) )
            );
        }
    }

    private function render_event_list( $wpdb, $bookings_table, $attendees_table, $key, $event_id ) {
        $title = get_the_title( $event_id );
        $rows  = $wpdb->get_results( $wpdb->prepare( "
            SELECT a.id, a.name, a.ticket_number, a.checked_in_at, b.id AS booking_id, b.booking_reference, b.status
            FROM $attendees_table a
            JOIN $bookings_table b ON a.booking_id = b.id
            WHERE b.event_id = %d
            ORDER BY a.name ASC
        ", $event_id ) );

        $back_url = add_query_arg( array( 'key' => $key ), home_url( '/door-checkin/' ) );
        printf( '<a class="back" href="%s">&larr; %s</a>', esc_url( $back_url ), esc_html__( 'All events', 'mira-event-list' ) );
        printf( '<h1>%s</h1>', esc_html( $title ) );

        $checked_in_count = 0;
        foreach ( $rows as $r ) {
            if ( $r->checked_in_at ) $checked_in_count++;
        }

        printf( '<div id="summary">%d / %d ' . esc_html__( 'checked in', 'mira-event-list' ) . '</div>', $checked_in_count, count( $rows ) );
        echo '<input id="search" type="text" placeholder="' . esc_attr__( 'Search name or ticket #...', 'mira-event-list' ) . '">';
        echo '<div id="list">';
        foreach ( $rows as $r ) {
            $is_in    = ! empty( $r->checked_in_at );
            $is_paid  = $r->status === 'complete';
            printf(
                '<div class="row" data-search="%s" data-id="%d" data-booking-id="%d">
                    <div class="info">
                        <div class="name">%s</div>
                        <div class="meta%s"><span class="status-label">%s</span> &middot; %s</div>
                    </div>
                    %s
                    <button class="btn %s" onclick="mtoggle(this)">%s</button>
                </div>',
                esc_attr( strtolower( $r->name . ' ' . $r->ticket_number . ' ' . $r->booking_reference ) ),
                (int) $r->id,
                (int) $r->booking_id,
                esc_html( $r->name ),
                $is_paid ? '' : ' pending',
                $is_paid ? esc_html__( 'Paid', 'mira-event-list' ) : esc_html( strtoupper( $r->status ) ),
                esc_html( $r->ticket_number ),
                $is_paid ? '' : '<button class="btn paid" onclick="mpaid(this)">' . esc_html__( 'Mark Paid', 'mira-event-list' ) . '</button>',
                $is_in ? 'in' : 'out',
                $is_in ? '&#10003; ' . esc_html__( 'In', 'mira-event-list' ) : esc_html__( 'Check in', 'mira-event-list' )
            );
        }
        echo '</div>';
        ?>
        <script>
        document.getElementById('search').addEventListener('input', function () {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#list .row').forEach(function (row) {
                row.classList.toggle('hidden', q && row.dataset.search.indexOf(q) === -1);
            });
        });

        function mtoggle(btn) {
            var row = btn.closest('.row');
            var id = row.dataset.id;
            btn.disabled = true;
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'toggle_id=' + encodeURIComponent(id) + '&key=<?php echo esc_js( $key ); ?>'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var isIn = !!data.checked_in_at;
                btn.classList.toggle('in', isIn);
                btn.classList.toggle('out', !isIn);
                btn.innerHTML = isIn ? '&#10003; In' : 'Check in';
                btn.disabled = false;
                var summary = document.getElementById('summary');
                var total = document.querySelectorAll('#list .row').length;
                var inCount = document.querySelectorAll('#list .btn.in').length;
                summary.textContent = inCount + ' / ' + total + ' checked in';
            })
            .catch(function () { btn.disabled = false; alert('Network error, try again'); });
        }

        function mpaid(btn) {
            var row = btn.closest('.row');
            var bookingId = row.dataset.bookingId;
            btn.disabled = true;
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'mark_paid_booking_id=' + encodeURIComponent(bookingId) + '&key=<?php echo esc_js( $key ); ?>'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status !== 'complete') {
                    btn.disabled = false;
                    alert('Could not mark as paid, try again');
                    return;
                }
                // One booking can cover several attendees — update every row for it.
                document.querySelectorAll('.row[data-booking-id="' + bookingId + '"]').forEach(function (r) {
                    r.querySelector('.meta').classList.remove('pending');
                    r.querySelector('.status-label').textContent = 'Paid';
                    var paidBtn = r.querySelector('.btn.paid');
                    if (paidBtn) paidBtn.remove();
                });
            })
            .catch(function () { btn.disabled = false; alert('Network error, try again'); });
        }
        </script>
        <?php
    }
}
