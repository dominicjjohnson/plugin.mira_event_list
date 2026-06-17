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

        if ( $action === 'export_csv' ) {
            check_admin_referer( 'mira_export_csv' );
            $this->output_csv();
            exit;
        }
    }

    // ── Router ────────────────────────────────────────────────────────────

    public function render_page() {
        if ( isset( $_GET['booking_id'] ) ) {
            $this->render_detail( intval( $_GET['booking_id'] ) );
        } else {
            $this->render_list();
        }
    }

    // ── List view ─────────────────────────────────────────────────────────

    private function render_list() {
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'mira_bookings';

        $valid_statuses = array( 'pending', 'paid', 'complete' );
        $status_filter  = sanitize_key( $_GET['status'] ?? '' );
        $event_filter   = intval( $_GET['event_id'] ?? 0 );

        if ( ! in_array( $status_filter, $valid_statuses, true ) ) {
            $status_filter = '';
        }

        // Build WHERE
        $conditions = array();
        if ( $status_filter ) {
            $conditions[] = $wpdb->prepare( 'status = %s', $status_filter );
        }
        if ( $event_filter ) {
            $conditions[] = $wpdb->prepare( 'event_id = %d', $event_filter );
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
                'page'     => 'mira-bookings',
                'action'   => 'export_csv',
                'status'   => $status_filter ?: null,
                'event_id' => $event_filter ?: null,
            ) ), admin_url( 'edit.php?post_type=mira_event' ) ),
            'mira_export_csv'
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Bookings', 'mira-event-list' ); ?></h1>

            <?php if ( isset( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Booking deleted.', 'mira-event-list' ); ?></p></div>
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
                <?php esc_html_e( 'Checkout started, payment not yet confirmed (usually abandoned)', 'mira-event-list' ); ?> &nbsp;
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
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'mira-event-list' ); ?></button>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
                    ⬇ <?php esc_html_e( 'Export CSV', 'mira-event-list' ); ?>
                </a>
            </form>

            <ul class="subsubsub" style="margin-bottom:8px">
                <li>
                    <a href="<?php echo esc_url( $event_filter ? add_query_arg( 'event_id', $event_filter, $base_url ) : $base_url ); ?>"
                       <?php echo ! $status_filter ? 'class="current"' : ''; ?>>
                        <?php esc_html_e( 'All', 'mira-event-list' ); ?>
                        <span class="count">(<?php echo intval( $total ); ?>)</span>
                    </a> |
                </li>
                <?php foreach ( $valid_statuses as $i => $s ) :
                    $n       = isset( $counts[ $s ] ) ? intval( $counts[ $s ]->n ) : 0;
                    $tab_url = add_query_arg( array_filter( array(
                        'status'   => $s,
                        'event_id' => $event_filter ?: null,
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
                        <th style="width:90px"><?php esc_html_e( 'Status', 'mira-event-list' ); ?></th>
                        <th style="width:130px"><?php esc_html_e( 'Date', 'mira-event-list' ); ?></th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $bookings ) ) : ?>
                    <tr><td colspan="8"><?php esc_html_e( 'No bookings found.', 'mira-event-list' ); ?></td></tr>
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
                        <td><?php echo $this->status_badge( $b->status ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'd M Y H:i', strtotime( $b->created_at ) ) ); ?></td>
                        <td>
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
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html( $booking->booking_reference ); ?>
                <?php echo $this->status_badge( $booking->status ); ?>
            </h1>
            <p>
                <a href="<?php echo esc_url( $back_url ); ?>">← <?php esc_html_e( 'Back to Bookings', 'mira-event-list' ); ?></a>
                &nbsp;&nbsp;
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

        if ( ! in_array( $status_filter, $valid_statuses, true ) ) {
            $status_filter = '';
        }

        $conditions = array();
        if ( $status_filter ) {
            $conditions[] = $wpdb->prepare( 'b.status = %s', $status_filter );
        }
        if ( $event_filter ) {
            $conditions[] = $wpdb->prepare( 'b.event_id = %d', $event_filter );
        }
        $where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

        $rows = $wpdb->get_results(
            "SELECT b.booking_reference, b.event_id, b.created_at, b.status,
                    b.quantity, b.ticket_price, b.donation_amount, b.total_amount,
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
