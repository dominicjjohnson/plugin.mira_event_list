<?php
/**
 * Plugin Name: Mira Event List
 * Plugin URI: https://github.com/dominicjjohnson/plugin.mira_event_list
 * Description: A WordPress plugin to manage events with custom post type, shortcode display, and Stripe ticket purchasing.
 * Version: 2.7.0
 * Author: Miramedia / Dominic Johnson
 * Author URI: https://about.me/dominicjjohnson
 * License: GPL v2 or later
 * Text Domain: mira-event-list
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MIRA_EVENT_LIST_VERSION', '2.9.0' );
define( 'MIRA_EVENT_LIST_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MIRA_EVENT_LIST_URL',     plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'is_plugin_active' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-database.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-mailjet.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-stripe.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-emails.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-bookings.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-admin-bookings.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-door-checkin.php';

add_filter( 'use_block_editor_for_post_type', function( $use, $post_type ) {
    return $post_type === 'mira_event' ? false : $use;
}, 10, 2 );

class MiraEventList {

    public function __construct() {
        add_action( 'init',              array( $this, 'create_event_post_type' ) );
        add_action( 'init',              array( $this, 'register_shortcode' ) );
        add_action( 'init',              array( $this, 'register_meta_fields' ) );
        add_action( 'add_meta_boxes',   array( $this, 'add_event_meta_boxes' ) );
        add_action( 'save_post',        array( $this, 'save_event_meta' ) );
        add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
        add_action( 'admin_menu',       array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init',       array( $this, 'settings_init' ) );
        add_action( 'after_setup_theme', array( $this, 'add_image_sizes' ) );
        add_filter( 'the_content',       array( $this, 'event_detail_content' ) );
        add_filter( 'wp_get_nav_menu_items', array( $this, 'nav_menu_ticket_items' ), 10, 3 );
        add_filter( 'manage_mira_event_posts_columns',       array( $this, 'event_admin_columns' ) );
        add_action( 'manage_mira_event_posts_custom_column', array( $this, 'event_admin_column_content' ), 10, 2 );

        new MiraBookings();
        new MiraAdminBookings();
        new MiraDoorCheckin();
    }

    public function register_meta_fields() {
        $string_fields = array(
            '_event_date', '_display_date', '_event_location', '_event_link',
            '_event_registration_url', '_event_registration_cta',
            '_event_documents', '_event_faqs',
            '_tickets_enabled', '_ticket_price', '_enable_donation',
            '_mailjet_event_tag',
        );
        foreach ( $string_fields as $key ) {
            register_post_meta( 'mira_event', $key, array(
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => '__return_true',
            ) );
        }
        foreach ( array( '_event_post_category', '_event_sponsor_type', '_max_tickets' ) as $key ) {
            register_post_meta( 'mira_event', $key, array(
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'integer',
                'auth_callback' => '__return_true',
            ) );
        }
    }

    public function create_event_post_type() {
        $labels = array(
            'name'               => _x( 'Events', 'post type general name', 'mira-event-list' ),
            'singular_name'      => _x( 'Event', 'post type singular name', 'mira-event-list' ),
            'menu_name'          => _x( 'Events', 'admin menu', 'mira-event-list' ),
            'name_admin_bar'     => _x( 'Event', 'add new on admin bar', 'mira-event-list' ),
            'add_new'            => _x( 'Add New', 'event', 'mira-event-list' ),
            'add_new_item'       => __( 'Add New Event', 'mira-event-list' ),
            'new_item'           => __( 'New Event', 'mira-event-list' ),
            'edit_item'          => __( 'Edit Event', 'mira-event-list' ),
            'view_item'          => __( 'View Event', 'mira-event-list' ),
            'all_items'          => __( 'All Events', 'mira-event-list' ),
            'search_items'       => __( 'Search Events', 'mira-event-list' ),
            'not_found'          => __( 'No events found.', 'mira-event-list' ),
            'not_found_in_trash' => __( 'No events found in Trash.', 'mira-event-list' ),
        );

        register_post_type( 'mira_event', array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'events' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_icon'          => 'dashicons-calendar-alt',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
        ) );
    }

    public function add_image_sizes() {
        add_image_size( 'event-logo', 250, 0, false );
    }

    // ── Meta boxes ───────────────────────────────────────────────────────

    public function add_event_meta_boxes() {
        add_meta_box(
            'event-details',
            __( 'Event Details', 'mira-event-list' ),
            array( $this, 'event_meta_box_callback' ),
            'mira_event', 'normal', 'high'
        );
        add_meta_box(
            'event-ticketing',
            __( 'Ticketing', 'mira-event-list' ),
            array( $this, 'ticketing_meta_box_callback' ),
            'mira_event', 'normal', 'high'
        );
        if ( is_plugin_active( 'miramedia-event-manager-for-tedx/miramedia-event-manager-for-tedx.php' ) ) {
            add_meta_box(
                'event-connections',
                __( 'People, Charities &amp; Talks', 'mira-event-list' ),
                array( $this, 'event_tedx_connections_meta_box_callback' ),
                'mira_event', 'normal', 'default'
            );
        } else {
            add_meta_box(
                'event-connections',
                __( 'Speakers &amp; Sponsors', 'mira-event-list' ),
                array( $this, 'event_connections_meta_box_callback' ),
                'mira_event', 'normal', 'default'
            );
        }
        add_meta_box(
            'event-documents',
            __( 'Documents', 'mira-event-list' ),
            array( $this, 'event_documents_meta_box_callback' ),
            'mira_event', 'normal', 'default'
        );
        add_meta_box(
            'event-faqs',
            __( 'FAQs', 'mira-event-list' ),
            array( $this, 'event_faqs_meta_box_callback' ),
            'mira_event', 'normal', 'default'
        );
    }

    public function event_meta_box_callback( $post ) {
        wp_nonce_field( basename( __FILE__ ), 'event_nonce' );

        $event_date               = get_post_meta( $post->ID, '_event_date', true );
        $event_link               = get_post_meta( $post->ID, '_event_link', true );
        $event_location           = get_post_meta( $post->ID, '_event_location', true );
        $display_date             = get_post_meta( $post->ID, '_display_date', true );
        $event_post_category      = (int) get_post_meta( $post->ID, '_event_post_category', true );
        $event_registration_url   = get_post_meta( $post->ID, '_event_registration_url', true );
        $event_registration_cta   = get_post_meta( $post->ID, '_event_registration_cta', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="event_date"><?php esc_html_e( 'Event Date', 'mira-event-list' ); ?></label></th>
                <td>
                    <input type="date" id="event_date" name="event_date" value="<?php echo esc_attr( $event_date ); ?>">
                    <p class="description"><?php esc_html_e( 'Select the event date.', 'mira-event-list' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="display_date"><?php esc_html_e( 'Display Date', 'mira-event-list' ); ?></label></th>
                <td>
                    <input type="text" id="display_date" name="display_date" value="<?php echo esc_attr( $display_date ); ?>" class="large-text">
                    <p class="description"><?php esc_html_e( 'Custom date label, e.g. "Summer 2025". If blank, the event date is used.', 'mira-event-list' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="event_location"><?php esc_html_e( 'Event Location', 'mira-event-list' ); ?></label></th>
                <td>
                    <input type="text" id="event_location" name="event_location" value="<?php echo esc_attr( $event_location ); ?>" class="large-text">
                </td>
            </tr>
            <tr>
                <th><label for="event_link"><?php esc_html_e( 'Event Link', 'mira-event-list' ); ?></label></th>
                <td>
                    <input type="url" id="event_link" name="event_link" value="<?php echo esc_attr( $event_link ); ?>" class="large-text">
                    <p class="description"><?php esc_html_e( 'Used when ticketing is disabled — links the button to an external page.', 'mira-event-list' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="event_post_category"><?php esc_html_e( 'Related Post Category', 'mira-event-list' ); ?></label></th>
                <td>
                    <?php wp_dropdown_categories( array(
                        'name'             => 'event_post_category',
                        'id'               => 'event_post_category',
                        'selected'         => $event_post_category,
                        'show_option_none' => __( '— None —', 'mira-event-list' ),
                        'option_none_value'=> 0,
                        'hide_empty'       => false,
                        'orderby'          => 'name',
                    ) ); ?>
                    <p class="description"><?php esc_html_e( 'Posts in this category will be treated as related content for this event.', 'mira-event-list' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="event_registration_url"><?php esc_html_e( 'Registration URL', 'mira-event-list' ); ?></label></th>
                <td>
                    <input type="url" id="event_registration_url" name="event_registration_url"
                           value="<?php echo esc_attr( $event_registration_url ); ?>" class="large-text">
                    <p class="description"><?php esc_html_e( 'Direct link to the registration or booking page for this event.', 'mira-event-list' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="event_registration_cta"><?php esc_html_e( 'Registration Button Text', 'mira-event-list' ); ?></label></th>
                <td>
                    <input type="text" id="event_registration_cta" name="event_registration_cta"
                           value="<?php echo esc_attr( $event_registration_cta ); ?>" class="regular-text"
                           placeholder="e.g. Book Now, Buy Tickets, Register Free">
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Event Logo', 'mira-event-list' ); ?></th>
                <td>
                    <p class="description"><?php esc_html_e( 'Use the Featured Image for the event logo (displayed at 250px wide).', 'mira-event-list' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function ticketing_meta_box_callback( $post ) {
        $tickets_enabled = get_post_meta( $post->ID, '_tickets_enabled', true );
        $ticket_price    = get_post_meta( $post->ID, '_ticket_price', true );
        $enable_donation = get_post_meta( $post->ID, '_enable_donation', true );
        $max_tickets     = (int) get_post_meta( $post->ID, '_max_tickets', true );
        $mailjet_tag     = get_post_meta( $post->ID, '_mailjet_event_tag', true );
        $capacity        = MiraBookings::event_capacity( $post->ID );
        $mailjet_auto    = MiraMailjet::generate_event_tag( $post->ID );
        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Enable Ticketing', 'mira-event-list' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tickets_enabled" value="1" <?php checked( $tickets_enabled, '1' ); ?>>
                        <?php esc_html_e( 'Sell tickets for this event via Stripe', 'mira-event-list' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'When enabled, a booking form replaces the event link button.', 'mira-event-list' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ticket_price"><?php esc_html_e( 'Price per Ticket', 'mira-event-list' ); ?></label></th>
                <td>
                    <span style="font-size:14px;margin-right:4px;">£</span>
                    <input type="number" id="ticket_price" name="ticket_price"
                           value="<?php echo esc_attr( $ticket_price ); ?>"
                           step="0.01" min="0" style="width:100px;">
                    <p class="description"><?php esc_html_e( 'Price in GBP per ticket, e.g. 25.00', 'mira-event-list' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="max_tickets"><?php esc_html_e( 'Maximum Tickets', 'mira-event-list' ); ?></label></th>
                <td>
                    <input type="number" id="max_tickets" name="max_tickets"
                           value="<?php echo $max_tickets ? esc_attr( $max_tickets ) : ''; ?>"
                           step="1" min="0" style="width:100px;">
                    <p class="description">
                        <?php esc_html_e( 'Total tickets available for this event. Leave blank or 0 for unlimited. The event shows "SOLD OUT" once this many paid tickets are sold, and a "tickets left" count appears in the final 25%.', 'mira-event-list' ); ?>
                        <?php if ( $max_tickets > 0 ) : ?>
                            <br>
                            <strong><?php printf(
                                /* translators: 1: paid tickets, 2: tickets incl. pending, 3: maximum */
                                esc_html__( 'Sold so far: %1$d paid, %2$d including pending orders (of %3$d).', 'mira-event-list' ),
                                (int) $capacity['sold_paid'],
                                (int) $capacity['sold_all'],
                                (int) $capacity['max']
                            ); ?></strong>
                            <?php if ( $capacity['sold_out'] ) : ?>
                                <span style="color:#b00;font-weight:700"><?php esc_html_e( '— SOLD OUT', 'mira-event-list' ); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Enable Donations', 'mira-event-list' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="enable_donation" value="1" <?php checked( $enable_donation, '1' ); ?>>
                        <?php esc_html_e( 'Allow buyers to add an optional donation at checkout', 'mira-event-list' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="mailjet_event_tag"><?php esc_html_e( 'Mailjet Tag', 'mira-event-list' ); ?></label></th>
                <td>
                    <input type="text" id="mailjet_event_tag" name="mailjet_event_tag"
                           value="<?php echo esc_attr( $mailjet_tag ); ?>"
                           placeholder="<?php echo esc_attr( $mailjet_auto ); ?>"
                           class="regular-text" autocapitalize="off" spellcheck="false">
                    <p class="description">
                        <?php esc_html_e( 'Boolean contact property set in Mailjet on everyone who books this event, for targeting campaigns. Leave blank to use the auto-generated name:', 'mira-event-list' ); ?>
                        <code><?php echo esc_html( $mailjet_auto ); ?></code>.
                        <?php esc_html_e( 'The property is created in Mailjet automatically on the first booking.', 'mira-event-list' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Save meta ────────────────────────────────────────────────────────

    public function save_event_meta( $post_id ) {
        if ( ! isset( $_POST['event_nonce'] ) || ! wp_verify_nonce( $_POST['event_nonce'], basename( __FILE__ ) ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Event details
        if ( isset( $_POST['event_date'] ) ) {
            update_post_meta( $post_id, '_event_date', sanitize_text_field( $_POST['event_date'] ) );
        }
        if ( isset( $_POST['display_date'] ) ) {
            update_post_meta( $post_id, '_display_date', sanitize_text_field( $_POST['display_date'] ) );
        }
        if ( isset( $_POST['event_location'] ) ) {
            update_post_meta( $post_id, '_event_location', sanitize_text_field( $_POST['event_location'] ) );
        }
        if ( isset( $_POST['event_link'] ) ) {
            update_post_meta( $post_id, '_event_link', esc_url_raw( $_POST['event_link'] ) );
        }
        if ( isset( $_POST['event_registration_url'] ) ) {
            update_post_meta( $post_id, '_event_registration_url', esc_url_raw( $_POST['event_registration_url'] ) );
        }
        if ( isset( $_POST['event_registration_cta'] ) ) {
            update_post_meta( $post_id, '_event_registration_cta', sanitize_text_field( $_POST['event_registration_cta'] ) );
        }
        update_post_meta( $post_id, '_event_post_category', intval( $_POST['event_post_category'] ?? 0 ) );

        // Documents
        if ( isset( $_POST['event_documents_nonce'] ) && wp_verify_nonce( $_POST['event_documents_nonce'], 'mira_event_documents_nonce' ) ) {
            $docs = array();
            if ( ! empty( $_POST['event_documents'] ) && is_array( $_POST['event_documents'] ) ) {
                foreach ( $_POST['event_documents'] as $row ) {
                    $url = esc_url_raw( $row['url'] ?? '' );
                    if ( $url ) {
                        $docs[] = array(
                            'id'    => intval( $row['id'] ?? 0 ),
                            'title' => sanitize_text_field( $row['title'] ?? '' ),
                            'url'   => $url,
                        );
                    }
                }
            }
            update_post_meta( $post_id, '_event_documents', wp_json_encode( $docs ) );
        }

        // FAQs
        if ( isset( $_POST['event_faqs_nonce'] ) && wp_verify_nonce( $_POST['event_faqs_nonce'], 'mira_event_faqs_nonce' ) ) {
            $faqs = array();
            if ( ! empty( $_POST['event_faqs'] ) && is_array( $_POST['event_faqs'] ) ) {
                foreach ( $_POST['event_faqs'] as $row ) {
                    $q = sanitize_text_field( $row['question'] ?? '' );
                    $a = sanitize_textarea_field( $row['answer'] ?? '' );
                    if ( $q ) {
                        $faqs[] = array( 'question' => $q, 'answer' => $a );
                    }
                }
            }
            update_post_meta( $post_id, '_event_faqs', wp_json_encode( $faqs ) );
        }

        // Connections
        if ( is_plugin_active( 'miramedia-event-manager-for-tedx/miramedia-event-manager-for-tedx.php' ) ) {
            $this->save_event_tedx_connections_meta( $post_id );
        } else {
            $this->save_event_connections_meta( $post_id );
        }

        // Ticketing
        update_post_meta( $post_id, '_tickets_enabled', isset( $_POST['tickets_enabled'] ) ? '1' : '' );
        if ( isset( $_POST['ticket_price'] ) ) {
            update_post_meta( $post_id, '_ticket_price', (string) floatval( $_POST['ticket_price'] ) );
        }
        if ( isset( $_POST['max_tickets'] ) ) {
            update_post_meta( $post_id, '_max_tickets', max( 0, intval( $_POST['max_tickets'] ) ) );
        }
        update_post_meta( $post_id, '_enable_donation', isset( $_POST['enable_donation'] ) ? '1' : '' );

        if ( isset( $_POST['mailjet_event_tag'] ) ) {
            $mailjet_tag = MiraMailjet::sanitize_tag( wp_unslash( $_POST['mailjet_event_tag'] ) );
            if ( $mailjet_tag === '' ) {
                delete_post_meta( $post_id, '_mailjet_event_tag' );
            } else {
                update_post_meta( $post_id, '_mailjet_event_tag', $mailjet_tag );
            }
        }
    }

    // ── Connections meta box ─────────────────────────────────────────────

    public function event_connections_meta_box_callback( $post ) {
        wp_nonce_field( 'mira_event_connections_nonce', 'event_connections_nonce' );

        $saved_type    = (int) get_post_meta( $post->ID, '_event_sponsor_type', true );
        $sponsor_types = get_terms( array( 'taxonomy' => 'sponsortype', 'hide_empty' => false ) );
        if ( is_wp_error( $sponsor_types ) ) $sponsor_types = array();
        ?>

        <h4 style="margin:0 0 8px;"><?php esc_html_e( 'Sponsor Type', 'mira-event-list' ); ?></h4>
        <?php if ( empty( $sponsor_types ) ) : ?>
            <p class="description"><?php esc_html_e( 'No sponsor types found. Add sponsor types via the Sponsor Types taxonomy first.', 'mira-event-list' ); ?></p>
        <?php else : ?>
            <select name="event_sponsor_type">
                <option value="0"><?php esc_html_e( '— None —', 'mira-event-list' ); ?></option>
                <?php foreach ( $sponsor_types as $st ) : ?>
                    <option value="<?php echo esc_attr( $st->term_id ); ?>" <?php selected( $saved_type, $st->term_id ); ?>>
                        <?php echo esc_html( $st->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description" style="margin-top:4px;"><?php esc_html_e( 'Individual sponsors are managed via the Sponsors post type.', 'mira-event-list' ); ?></p>
        <?php endif; ?>

        <h4 style="margin:20px 0 8px;"><?php esc_html_e( 'Speakers', 'mira-event-list' ); ?></h4>
        <?php
        $speakers = get_posts( array(
            'post_type'      => 'speakers',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish',
            'meta_query'     => array( array(
                'key'   => 'amg_speaker_event_id',
                'value' => $post->ID,
            ) ),
        ) );
        if ( ! empty( $speakers ) ) : ?>
            <ul style="margin:0 0 8px;padding-left:1.2em;">
                <?php foreach ( $speakers as $spk ) :
                    $job = get_post_meta( $spk->ID, 'speaker_speaker_job_title', true );
                    $edit_url = get_edit_post_link( $spk->ID );
                ?>
                    <li>
                        <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $spk->post_title ); ?></a>
                        <?php if ( $job ) : ?><span style="color:#777;"> — <?php echo esc_html( $job ); ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="description"><?php esc_html_e( 'These people are speaking at the event. To add more, edit each speaker and add an event.', 'mira-event-list' ); ?></p>
        <?php
    }

    private function save_event_connections_meta( $post_id ) {
        if ( ! isset( $_POST['event_connections_nonce'] ) || ! wp_verify_nonce( $_POST['event_connections_nonce'], 'mira_event_connections_nonce' ) ) {
            return;
        }

        update_post_meta( $post_id, '_event_sponsor_type', intval( $_POST['event_sponsor_type'] ?? 0 ) );
    }

    // ── TEDx connections meta box (People & Charities) ───────────────────

    public function event_tedx_connections_meta_box_callback( $post ) {
        wp_nonce_field( 'mira_event_tedx_connections_nonce', 'event_tedx_connections_nonce' );

        $charities = json_decode( get_post_meta( $post->ID, '_event_charities', true ) ?: '[]', true ) ?: array();
        $people    = json_decode( get_post_meta( $post->ID, '_event_people',    true ) ?: '[]', true ) ?: array();
        $talks     = json_decode( get_post_meta( $post->ID, '_event_talks',     true ) ?: '[]', true ) ?: array();

        $companies  = get_posts( array( 'post_type' => 'mmevmt_company', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ) );
        $persons    = get_posts( array( 'post_type' => 'mmevmt_person',  'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ) );
        $all_talks  = get_posts( array( 'post_type' => 'mmevmt_talk',    'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ) );
        ?>
        <style>
            .mira-meta-row { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
            .mira-meta-row select { flex:2; }
            .mira-meta-row input[type=text] { flex:1.5; }
        </style>

        <h4 style="margin:0 0 8px;"><?php esc_html_e( 'Charities &amp; Sponsors', 'mira-event-list' ); ?></h4>
        <?php if ( empty( $companies ) ) : ?>
            <p class="description"><?php esc_html_e( 'No companies found. Add companies via the Event Manager plugin first.', 'mira-event-list' ); ?></p>
        <?php else : ?>
            <div id="mira-charities-list">
                <div class="mira-meta-row mira-charity-row" data-template="1" style="display:none;">
                    <select name="event_charities[__IDX__][company_id]">
                        <option value=""><?php esc_html_e( '-- Select Company --', 'mira-event-list' ); ?></option>
                        <?php foreach ( $companies as $co ) : ?>
                            <option value="<?php echo esc_attr( $co->ID ); ?>"><?php echo esc_html( $co->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="event_charities[__IDX__][label]" placeholder="<?php esc_attr_e( 'e.g. Charity, Sponsor', 'mira-event-list' ); ?>">
                    <button type="button" class="button mira-remove-row"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                </div>
                <?php foreach ( $charities as $idx => $item ) : ?>
                    <div class="mira-meta-row mira-charity-row">
                        <select name="event_charities[<?php echo $idx; ?>][company_id]">
                            <option value=""><?php esc_html_e( '-- Select Company --', 'mira-event-list' ); ?></option>
                            <?php foreach ( $companies as $co ) : ?>
                                <option value="<?php echo esc_attr( $co->ID ); ?>" <?php selected( intval( $item['company_id'] ?? 0 ), $co->ID ); ?>><?php echo esc_html( $co->post_title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="event_charities[<?php echo $idx; ?>][label]" value="<?php echo esc_attr( $item['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Charity, Sponsor', 'mira-event-list' ); ?>">
                        <button type="button" class="button mira-remove-row"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-event-charity" class="button button-secondary" style="margin-top:6px;">
                <?php esc_html_e( '+ Add Company', 'mira-event-list' ); ?>
            </button>
        <?php endif; ?>

        <h4 style="margin:20px 0 8px;"><?php esc_html_e( 'People', 'mira-event-list' ); ?></h4>
        <?php if ( empty( $persons ) ) : ?>
            <p class="description"><?php esc_html_e( 'No people found. Add people via the Event Manager plugin first.', 'mira-event-list' ); ?></p>
        <?php else : ?>
            <div id="mira-people-list">
                <div class="mira-meta-row mira-person-row" data-template="1" style="display:none;">
                    <select name="event_people[__IDX__][person_id]">
                        <option value=""><?php esc_html_e( '-- Select Person --', 'mira-event-list' ); ?></option>
                        <?php foreach ( $persons as $p ) : ?>
                            <option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="event_people[__IDX__][description]" placeholder="<?php esc_attr_e( 'e.g. MC, Headliner', 'mira-event-list' ); ?>">
                    <button type="button" class="button mira-remove-row"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                </div>
                <?php foreach ( $people as $idx => $item ) : ?>
                    <div class="mira-meta-row mira-person-row">
                        <select name="event_people[<?php echo $idx; ?>][person_id]">
                            <option value=""><?php esc_html_e( '-- Select Person --', 'mira-event-list' ); ?></option>
                            <?php foreach ( $persons as $p ) : ?>
                                <option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( intval( $item['person_id'] ?? 0 ), $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="event_people[<?php echo $idx; ?>][description]" value="<?php echo esc_attr( $item['description'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. MC, Headliner', 'mira-event-list' ); ?>">
                        <button type="button" class="button mira-remove-row"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-event-person" class="button button-secondary" style="margin-top:6px;">
                <?php esc_html_e( '+ Add Person', 'mira-event-list' ); ?>
            </button>
        <?php endif; ?>

        <h4 style="margin:20px 0 8px;"><?php esc_html_e( 'Talks', 'mira-event-list' ); ?></h4>
        <?php if ( empty( $all_talks ) ) : ?>
            <p class="description"><?php esc_html_e( 'No talks found. Add talks via the Event Manager plugin first.', 'mira-event-list' ); ?></p>
        <?php else : ?>
            <div id="mira-talks-list">
                <div class="mira-meta-row mira-talk-row" data-template="1" style="display:none;">
                    <select name="event_talks[__IDX__][talk_id]">
                        <option value=""><?php esc_html_e( '-- Select Talk --', 'mira-event-list' ); ?></option>
                        <?php foreach ( $all_talks as $tk ) : ?>
                            <option value="<?php echo esc_attr( $tk->ID ); ?>"><?php echo esc_html( $tk->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button mira-remove-row"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                </div>
                <?php foreach ( $talks as $idx => $item ) : ?>
                    <div class="mira-meta-row mira-talk-row">
                        <select name="event_talks[<?php echo $idx; ?>][talk_id]">
                            <option value=""><?php esc_html_e( '-- Select Talk --', 'mira-event-list' ); ?></option>
                            <?php foreach ( $all_talks as $tk ) : ?>
                                <option value="<?php echo esc_attr( $tk->ID ); ?>" <?php selected( intval( $item['talk_id'] ?? 0 ), $tk->ID ); ?>><?php echo esc_html( $tk->post_title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button mira-remove-row"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-event-talk" class="button button-secondary" style="margin-top:6px;">
                <?php esc_html_e( '+ Add Talk', 'mira-event-list' ); ?>
            </button>
            <p class="description" style="margin-top:6px;"><?php esc_html_e( 'Talks with a YouTube link will be embedded below the event content, above the ticket form.', 'mira-event-list' ); ?></p>
        <?php endif; ?>
        <?php
    }

    private function save_event_tedx_connections_meta( $post_id ) {
        if ( ! isset( $_POST['event_tedx_connections_nonce'] ) || ! wp_verify_nonce( $_POST['event_tedx_connections_nonce'], 'mira_event_tedx_connections_nonce' ) ) {
            return;
        }

        $charities = array();
        if ( ! empty( $_POST['event_charities'] ) && is_array( $_POST['event_charities'] ) ) {
            foreach ( $_POST['event_charities'] as $row ) {
                $id = intval( $row['company_id'] ?? 0 );
                if ( $id > 0 ) {
                    $charities[] = array( 'company_id' => $id, 'label' => sanitize_text_field( $row['label'] ?? '' ) );
                }
            }
        }
        update_post_meta( $post_id, '_event_charities', wp_json_encode( $charities ) );

        $people = array();
        if ( ! empty( $_POST['event_people'] ) && is_array( $_POST['event_people'] ) ) {
            foreach ( $_POST['event_people'] as $row ) {
                $id = intval( $row['person_id'] ?? 0 );
                if ( $id > 0 ) {
                    $people[] = array( 'person_id' => $id, 'description' => sanitize_text_field( $row['description'] ?? '' ) );
                }
            }
        }
        update_post_meta( $post_id, '_event_people', wp_json_encode( $people ) );

        $talks = array();
        if ( ! empty( $_POST['event_talks'] ) && is_array( $_POST['event_talks'] ) ) {
            foreach ( $_POST['event_talks'] as $row ) {
                $id = intval( $row['talk_id'] ?? 0 );
                if ( $id > 0 ) {
                    $talks[] = array( 'talk_id' => $id );
                }
            }
        }
        update_post_meta( $post_id, '_event_talks', wp_json_encode( $talks ) );
    }

    // ── Documents meta box ──────────────────────────────────────────────

    public function event_documents_meta_box_callback( $post ) {
        wp_nonce_field( 'mira_event_documents_nonce', 'event_documents_nonce' );
        $documents = json_decode( get_post_meta( $post->ID, '_event_documents', true ) ?: '[]', true ) ?: array();
        ?>
        <div id="mira-documents-list">
            <div class="mira-doc-row" data-template="1" style="display:none;">
                <input type="text"   name="event_documents[__IDX__][title]" placeholder="<?php esc_attr_e( 'Document title', 'mira-event-list' ); ?>" class="regular-text">
                <input type="hidden" name="event_documents[__IDX__][id]"    class="mira-doc-id">
                <input type="hidden" name="event_documents[__IDX__][url]"   class="mira-doc-url">
                <button type="button" class="button mira-doc-upload"><?php esc_html_e( 'Choose File', 'mira-event-list' ); ?></button>
                <span class="mira-doc-filename" style="margin-left:6px;color:#555;font-size:12px;"></span>
                <button type="button" class="button mira-remove-row" style="margin-left:6px;"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
            </div>
            <?php foreach ( $documents as $idx => $doc ) :
                $filename = $doc['url'] ? basename( $doc['url'] ) : '';
            ?>
                <div class="mira-doc-row" style="margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                    <input type="text"   name="event_documents[<?php echo $idx; ?>][title]" value="<?php echo esc_attr( $doc['title'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Document title', 'mira-event-list' ); ?>" class="regular-text">
                    <input type="hidden" name="event_documents[<?php echo $idx; ?>][id]"    value="<?php echo esc_attr( $doc['id'] ?? '' ); ?>" class="mira-doc-id">
                    <input type="hidden" name="event_documents[<?php echo $idx; ?>][url]"   value="<?php echo esc_attr( $doc['url'] ?? '' ); ?>" class="mira-doc-url">
                    <button type="button" class="button mira-doc-upload"><?php esc_html_e( 'Choose File', 'mira-event-list' ); ?></button>
                    <span class="mira-doc-filename" style="margin-left:6px;color:#555;font-size:12px;"><?php echo esc_html( $filename ); ?></span>
                    <button type="button" class="button mira-remove-row" style="margin-left:6px;"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="add-event-document" class="button button-secondary" style="margin-top:8px;">
            <?php esc_html_e( '+ Add Document', 'mira-event-list' ); ?>
        </button>
        <?php
    }

    // ── FAQs meta box ────────────────────────────────────────────────────

    public function event_faqs_meta_box_callback( $post ) {
        wp_nonce_field( 'mira_event_faqs_nonce', 'event_faqs_nonce' );
        $faqs = json_decode( get_post_meta( $post->ID, '_event_faqs', true ) ?: '[]', true ) ?: array();
        ?>
        <div id="mira-faqs-list">
            <div class="mira-faq-row" data-template="1" style="display:none;margin-bottom:12px;">
                <div style="display:flex;gap:8px;align-items:flex-start;">
                    <div style="flex:1;">
                        <input type="text" name="event_faqs[__IDX__][question]"
                               placeholder="<?php esc_attr_e( 'Question', 'mira-event-list' ); ?>"
                               class="large-text" style="margin-bottom:4px;">
                        <textarea name="event_faqs[__IDX__][answer]" rows="3"
                                  placeholder="<?php esc_attr_e( 'Answer', 'mira-event-list' ); ?>"
                                  class="large-text"></textarea>
                    </div>
                    <button type="button" class="button mira-remove-row" style="margin-top:2px;"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                </div>
            </div>
            <?php foreach ( $faqs as $idx => $faq ) : ?>
                <div class="mira-faq-row" style="margin-bottom:12px;">
                    <div style="display:flex;gap:8px;align-items:flex-start;">
                        <div style="flex:1;">
                            <input type="text" name="event_faqs[<?php echo $idx; ?>][question]"
                                   value="<?php echo esc_attr( $faq['question'] ?? '' ); ?>"
                                   placeholder="<?php esc_attr_e( 'Question', 'mira-event-list' ); ?>"
                                   class="large-text" style="margin-bottom:4px;">
                            <textarea name="event_faqs[<?php echo $idx; ?>][answer]" rows="3"
                                      placeholder="<?php esc_attr_e( 'Answer', 'mira-event-list' ); ?>"
                                      class="large-text"><?php echo esc_textarea( $faq['answer'] ?? '' ); ?></textarea>
                        </div>
                        <button type="button" class="button mira-remove-row" style="margin-top:2px;"><?php esc_html_e( 'Remove', 'mira-event-list' ); ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="add-event-faq" class="button button-secondary" style="margin-top:4px;">
            <?php esc_html_e( '+ Add FAQ', 'mira-event-list' ); ?>
        </button>
        <?php
    }

    // ── Single event: append charities, people, booking ─────────────────

    public function event_detail_content( $content ) {
        if ( ! is_singular( 'mira_event' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        return $content . $this->render_event_extras( get_the_ID() );
    }

    // ── Ticket capacity helpers ─────────────────────────────────────────

    private function sold_out_notice() {
        return '<div class="mira-sold-out">' . esc_html__( 'SOLD OUT', 'mira-event-list' ) . '</div>';
    }

    private function tickets_left_notice( $cap ) {
        if ( empty( $cap['show_remaining'] ) ) {
            return '';
        }
        $n = (int) $cap['remaining'];
        $msg = $n > 0
            ? sprintf(
                /* translators: %d: tickets remaining */
                _n( 'Only %d ticket left', 'Only %d tickets left', $n, 'mira-event-list' ),
                $n
            )
            : __( 'Last few tickets — almost gone', 'mira-event-list' );

        return '<p class="mira-tickets-left">' . esc_html( $msg ) . '</p>';
    }

    /**
     * Emergency backup Stripe Payment Link for one event, or '' if it
     * couldn't be created (e.g. no Stripe key configured yet, or the API is
     * unreachable) — the booking form still works fine without it, this is
     * a fallback shown only if the normal AJAX booking call fails.
     */
    private function fallback_payment_link( $event_id ) {
        static $stripe = null;
        if ( ! $stripe ) {
            $stripe = new MiraStripe();
        }
        $link = $stripe->get_or_create_payment_link( $event_id );
        return is_wp_error( $link ) ? '' : $link;
    }

    /** Highest quantity a buyer may pick, capped to what's left. */
    private function booking_qty_max( $cap ) {
        if ( empty( $cap['max'] ) ) {
            return 10;
        }
        return max( 1, min( 10, (int) $cap['remaining'] ) );
    }

    /**
     * Shared booking widget markup for one event.
     *
     * Returns the same `.mira-booking-form` structure the single-event page and
     * `[mira_next_event]` render (so `assets/booking.js` binds to it unchanged):
     * a SOLD OUT notice when full, the qty / donation / total / Book Now form
     * when ticketing is on, an external link button when only `_event_link` is
     * set, or an empty string when the event has no booking route.
     *
     * @param int   $post_id Event post ID.
     * @param array $args    { @type bool $show_note Show the long Stripe redirect
     *                         paragraph. Default true. }
     * @return string
     */
    private function render_booking_form( $post_id, $args = array() ) {
        $args = wp_parse_args( $args, array( 'show_note' => true ) );

        $tickets_enabled = get_post_meta( $post_id, '_tickets_enabled', true );
        $ticket_price    = floatval( get_post_meta( $post_id, '_ticket_price', true ) );
        $enable_donation = get_post_meta( $post_id, '_enable_donation', true );
        $event_link      = get_post_meta( $post_id, '_event_link', true );
        $capacity        = MiraBookings::event_capacity( $post_id );

        $button_color      = get_option( 'mira_event_button_color', '#28a745' );
        $button_text_color = get_option( 'mira_event_button_text_color', '#fff' );
        $button_text       = get_option( 'mira_event_button_text', 'Goto Event' );
        $ajax_url          = admin_url( 'admin-ajax.php' );

        if ( $tickets_enabled && $capacity['sold_out'] ) {
            return $this->sold_out_notice();
        }

        if ( $tickets_enabled ) {
            $btn_label = '£' . number_format( $ticket_price, 2 ) . ' per ticket — Book Now';
            $qty_max   = $this->booking_qty_max( $capacity );

            ob_start();
            echo $this->tickets_left_notice( $capacity );
            ?>
            <form class="mira-booking-form"
                  data-event-id="<?php echo esc_attr( $post_id ); ?>"
                  data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                  data-nonce="<?php echo esc_attr( wp_create_nonce( 'mira_booking_nonce' ) ); ?>"
                  data-ticket-price="<?php echo esc_attr( $ticket_price ); ?>"
                  data-fallback-url="<?php echo esc_url( $this->fallback_payment_link( $post_id ) ); ?>">
                <div class="mira-booking-fields">
                    <div class="mira-qty-wrap">
                        <label for="mira-qty-<?php echo esc_attr( $post_id ); ?>"><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></label>
                        <select id="mira-qty-<?php echo esc_attr( $post_id ); ?>" name="quantity" class="mira-qty-select">
                            <?php for ( $i = 1; $i <= $qty_max; $i++ ) : ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php if ( $enable_donation ) : ?>
                        <div class="mira-donation-wrap">
                            <label for="mira-don-<?php echo esc_attr( $post_id ); ?>"><?php esc_html_e( 'Donation (£)', 'mira-event-list' ); ?></label>
                            <input id="mira-don-<?php echo esc_attr( $post_id ); ?>" type="number" name="donation" min="0" step="0.50" placeholder="0.00" class="mira-donation-input">
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mira-booking-total">Total: <strong class="mira-total-amount">£<?php echo number_format( $ticket_price, 2 ); ?></strong></div>
                <button type="submit" class="mira-book-btn"
                        data-label="<?php echo esc_attr( $btn_label ); ?>"
                        style="background-color:<?php echo esc_attr( $button_color ); ?>;color:<?php echo esc_attr( $button_text_color ); ?>;">
                    <?php echo esc_html( $btn_label ); ?>
                </button>
                <div class="mira-stripe-badge">
                    <svg width="11" height="13" viewBox="0 0 12 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 0C4.07 0 2.5 1.57 2.5 3.5V5H1.5A1.5 1.5 0 000 6.5v6A1.5 1.5 0 001.5 14h9A1.5 1.5 0 0012 12.5v-6A1.5 1.5 0 0010.5 5H9.5V3.5C9.5 1.57 7.93 0 6 0zm0 1.5c1.1 0 2 .9 2 2V5H4V3.5c0-1.1.9-2 2-2z" fill="currentColor"/></svg>
                    Secured by <span class="mira-stripe-wordmark">Stripe</span>
                </div>
                <div class="mira-booking-error" role="alert"></div>
                <?php if ( $args['show_note'] ) : ?>
                    <p class="mira-booking-note">You will be redirected to the Stripe credit card system to take payment. Once paid, you'll be redirected back here so we can email out your tickets. Any problems please email <a href="mailto:twcomedy@miramedia.co.uk">twcomedy@miramedia.co.uk</a>. Thanks so much for your support — we look forward to seeing you!</p>
                <?php endif; ?>
            </form>
            <?php
            return ob_get_clean();
        }

        if ( $event_link ) {
            return sprintf(
                '<a href="%1$s" class="mira-book-btn mira-book-btn--link" style="background-color:%2$s;color:%3$s;">%4$s</a>',
                esc_url( $event_link ),
                esc_attr( $button_color ),
                esc_attr( $button_text_color ),
                esc_html( $button_text )
            );
        }

        return '';
    }

    /**
     * Upcoming events (soonest first) as a WP_Query. Shared by the grid and
     * next-event rotator shortcodes.
     */
    private function upcoming_events_query( $limit = -1 ) {
        return new WP_Query( array(
            'post_type'      => 'mira_event',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_key'       => '_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => array( array(
                'key'     => '_event_date',
                'value'   => date( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            ) ),
        ) );
    }

    private function render_event_extras( $post_id ) {
        $charities = json_decode( get_post_meta( $post_id, '_event_charities', true ) ?: '[]', true ) ?: array();
        $people    = json_decode( get_post_meta( $post_id, '_event_people',    true ) ?: '[]', true ) ?: array();
        $talks     = json_decode( get_post_meta( $post_id, '_event_talks',     true ) ?: '[]', true ) ?: array();

        $tickets_enabled   = get_post_meta( $post_id, '_tickets_enabled', true );
        $ticket_price      = floatval( get_post_meta( $post_id, '_ticket_price', true ) );
        $enable_donation   = get_post_meta( $post_id, '_enable_donation', true );
        $capacity          = MiraBookings::event_capacity( $post_id );
        $event_link        = get_post_meta( $post_id, '_event_link', true );
        $button_color      = get_option( 'mira_event_button_color', '#28a745' );
        $button_text_color = get_option( 'mira_event_button_text_color', '#fff' );
        $button_text       = get_option( 'mira_event_button_text', 'Goto Event' );
        $ajax_url          = admin_url( 'admin-ajax.php' );

        ob_start();
        ?>
        <div class="mira-event-detail-extras">

            <?php if ( ! empty( $charities ) ) : ?>
                <div class="mira-event-charities">
                    <h3><?php esc_html_e( 'Charity', 'mira-event-list' ); ?></h3>
                    <div class="mira-charity-grid">
                        <?php foreach ( $charities as $item ) :
                            $company = get_post( intval( $item['company_id'] ) );
                            if ( ! $company ) continue;
                            $thumb = get_the_post_thumbnail( $company->ID, 'medium' );
                            $url   = get_permalink( $company->ID );
                        ?>
                            <div class="mira-charity-item">
                                <?php if ( $thumb ) : ?>
                                    <a href="<?php echo esc_url( $url ); ?>" class="mira-charity-logo"><?php echo $thumb; ?></a>
                                <?php endif; ?>
                                <div class="mira-charity-info">
                                    <?php if ( ! empty( $item['label'] ) ) : ?>
                                        <span class="mira-charity-label"><?php echo esc_html( $item['label'] ); ?></span>
                                    <?php endif; ?>
                                    <h4><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $company->post_title ); ?></a></h4>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $people ) ) : ?>
                <div class="mira-event-people">
                    <h3><?php esc_html_e( 'Lineup', 'mira-event-list' ); ?></h3>
                    <div class="mira-people-grid">
                        <?php foreach ( $people as $item ) :
                            $person = get_post( intval( $item['person_id'] ) );
                            if ( ! $person ) continue;
                            $thumb     = get_the_post_thumbnail( $person->ID, 'medium' );
                            $job_title = get_post_meta( $person->ID, 'speaker_speaker_job_title', true );
                        ?>
                            <div class="mira-person-item">
                                <?php if ( $thumb ) : ?>
                                    <div class="mira-person-photo"><?php echo $thumb; ?></div>
                                <?php endif; ?>
                                <div class="mira-person-info">
                                    <?php if ( ! empty( $item['description'] ) ) : ?>
                                        <span class="mira-person-role"><?php echo esc_html( $item['description'] ); ?></span>
                                    <?php endif; ?>
                                    <h4><?php echo esc_html( $person->post_title ); ?></h4>
                                    <?php if ( $job_title ) : ?>
                                        <p class="mira-person-title"><?php echo esc_html( $job_title ); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php
                $talk_videos_html = '';
                foreach ( $talks as $item ) {
                    $talk_id = intval( $item['talk_id'] ?? 0 );
                    if ( ! $talk_id || get_post_type( $talk_id ) !== 'mmevmt_talk' ) continue;

                    $youtube_link = get_post_meta( $talk_id, 'youtube_link', true );
                    $embed_url = ( $youtube_link && function_exists( 'mmevmt_get_youtube_embed_url' ) ) ? mmevmt_get_youtube_embed_url( $youtube_link ) : '';
                    if ( ! $embed_url ) continue;

                    $talk_title = get_the_title( $talk_id );
                    $talk_videos_html .= '<div class="person-talk-video">';
                    $talk_videos_html .= '<div class="person-talk-video-frame">';
                    $talk_videos_html .= '<iframe src="' . esc_url( $embed_url ) . '" title="' . esc_attr( $talk_title ) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';
                    $talk_videos_html .= '</div>';
                    $talk_videos_html .= '<p class="person-talk-title">' . esc_html( $talk_title ) . '</p>';
                    $talk_videos_html .= '</div>';
                }
            ?>

            <?php if ( ! empty( $talk_videos_html ) ) : ?>
                <div class="mira-event-talks">
                    <h3><?php echo esc_html( function_exists( 'mmevmt_get_label' ) ? mmevmt_get_label( 'talks_heading' ) : __( 'Talks', 'mira-event-list' ) ); ?></h3>
                    <div class="person-talks-list"><?php echo $talk_videos_html; ?></div>
                </div>
            <?php endif; ?>

            <div class="mira-event-booking-section">
                <?php if ( $tickets_enabled && $capacity['sold_out'] ) : ?>
                    <?php echo $this->sold_out_notice(); ?>
                <?php elseif ( $tickets_enabled ) :
                    $btn_label = '£' . number_format( $ticket_price, 2 ) . ' per ticket — Book Now';
                    $qty_max   = $this->booking_qty_max( $capacity );
                ?>
                    <?php echo $this->tickets_left_notice( $capacity ); ?>
                    <form class="mira-booking-form"
                          data-event-id="<?php echo esc_attr( $post_id ); ?>"
                          data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                          data-nonce="<?php echo esc_attr( wp_create_nonce( 'mira_booking_nonce' ) ); ?>"
                          data-ticket-price="<?php echo esc_attr( $ticket_price ); ?>"
                          data-fallback-url="<?php echo esc_url( $this->fallback_payment_link( $post_id ) ); ?>">
                        <div class="mira-booking-fields">
                            <div class="mira-qty-wrap">
                                <label><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></label>
                                <select name="quantity" class="mira-qty-select">
                                    <?php for ( $i = 1; $i <= $qty_max; $i++ ) : ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <?php if ( $enable_donation ) : ?>
                                <div class="mira-donation-wrap">
                                    <label><?php esc_html_e( 'Donation (£)', 'mira-event-list' ); ?></label>
                                    <input type="number" name="donation" min="0" step="0.50" placeholder="0.00" class="mira-donation-input">
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mira-booking-total">Total: <strong class="mira-total-amount">£<?php echo number_format( $ticket_price, 2 ); ?></strong></div>
                        <button type="submit" class="mira-book-btn"
                                data-label="<?php echo esc_attr( $btn_label ); ?>"
                                style="background-color:<?php echo esc_attr( $button_color ); ?>;color:<?php echo esc_attr( $button_text_color ); ?>;">
                            <?php echo esc_html( $btn_label ); ?>
                        </button>
                        <div class="mira-stripe-badge">
                            <svg width="11" height="13" viewBox="0 0 12 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 0C4.07 0 2.5 1.57 2.5 3.5V5H1.5A1.5 1.5 0 000 6.5v6A1.5 1.5 0 001.5 14h9A1.5 1.5 0 0012 12.5v-6A1.5 1.5 0 0010.5 5H9.5V3.5C9.5 1.57 7.93 0 6 0zm0 1.5c1.1 0 2 .9 2 2V5H4V3.5c0-1.1.9-2 2-2z" fill="currentColor"/></svg>
                            Secured by <span class="mira-stripe-wordmark">Stripe</span>
                        </div>
                        <div class="mira-booking-error" role="alert"></div>
                        <p class="mira-booking-note">You will be redirected to the Stripe credit card system to take payment. Once paid, you'll be redirected back here so we can email out your tickets. Any problems please email <a href="mailto:twcomedy@miramedia.co.uk">twcomedy@miramedia.co.uk</a>. Thanks so much for your support — we look forward to seeing you!</p>
                    </form>
                <?php elseif ( $event_link ) : ?>
                    <a href="<?php echo esc_url( $event_link ); ?>"
                       class="mira-event-link-btn"
                       style="background-color:<?php echo esc_attr( $button_color ); ?>;color:<?php echo esc_attr( $button_text_color ); ?>;">
                        <?php echo esc_html( $button_text ); ?>
                    </a>
                <?php endif; ?>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    // ── [mira_next_event] shortcode ──────────────────────────────────────

    public function event_next_shortcode( $atts ) {
        $events = new WP_Query( array(
            'post_type'      => 'mira_event',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => '_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => array( array(
                'key'     => '_event_date',
                'value'   => date( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            ) ),
        ) );

        if ( ! $events->have_posts() ) {
            return '';
        }

        $events->the_post();
        $post_id   = get_the_ID();
        $permalink = get_permalink();
        $title     = get_the_title();

        $tickets_enabled   = get_post_meta( $post_id, '_tickets_enabled', true );
        $ticket_price      = floatval( get_post_meta( $post_id, '_ticket_price', true ) );
        $enable_donation   = get_post_meta( $post_id, '_enable_donation', true );
        $capacity          = MiraBookings::event_capacity( $post_id );
        $button_color      = get_option( 'mira_event_button_color', '#28a745' );
        $button_text_color = get_option( 'mira_event_button_text_color', '#fff' );
        $ajax_url          = admin_url( 'admin-ajax.php' );

        ob_start();
        ?>
        <div class="mira-next-event">
            <span class="mira-next-event-label"><?php esc_html_e( 'Our next event', 'mira-event-list' ); ?></span>
            <?php if ( has_post_thumbnail() ) : ?>
                <a href="<?php echo esc_url( $permalink ); ?>">
                    <?php the_post_thumbnail( 'full', array( 'alt' => esc_attr( $title ), 'class' => 'mira-next-event-banner' ) ); ?>
                </a>
            <?php endif; ?>
            <h3 class="mira-next-event-title">
                <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
            </h3>

            <?php if ( $tickets_enabled && $capacity['sold_out'] ) : ?>
                <?php echo $this->sold_out_notice(); ?>
            <?php elseif ( $tickets_enabled ) :
                $btn_label = '£' . number_format( $ticket_price, 2 ) . ' per ticket — Book Now';
                $qty_max   = $this->booking_qty_max( $capacity );
            ?>
                <?php echo $this->tickets_left_notice( $capacity ); ?>
                <form class="mira-booking-form"
                      data-event-id="<?php echo esc_attr( $post_id ); ?>"
                      data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                      data-nonce="<?php echo esc_attr( wp_create_nonce( 'mira_booking_nonce' ) ); ?>"
                      data-ticket-price="<?php echo esc_attr( $ticket_price ); ?>"
                      data-fallback-url="<?php echo esc_url( $this->fallback_payment_link( $post_id ) ); ?>">
                    <div class="mira-booking-fields">
                        <div class="mira-qty-wrap">
                            <label><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></label>
                            <select name="quantity" class="mira-qty-select">
                                <?php for ( $i = 1; $i <= $qty_max; $i++ ) : ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <?php if ( $enable_donation ) : ?>
                            <div class="mira-donation-wrap">
                                <label><?php esc_html_e( 'Donation (£)', 'mira-event-list' ); ?></label>
                                <input type="number" name="donation" min="0" step="0.50" placeholder="0.00" class="mira-donation-input">
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mira-booking-total">Total: <strong class="mira-total-amount">£<?php echo number_format( $ticket_price, 2 ); ?></strong></div>
                    <button type="submit"
                            class="mira-book-btn"
                            data-label="<?php echo esc_attr( $btn_label ); ?>"
                            style="background-color:<?php echo esc_attr( $button_color ); ?>;color:<?php echo esc_attr( $button_text_color ); ?>;">
                        <?php echo esc_html( $btn_label ); ?>
                    </button>
                    <div class="mira-stripe-badge">
                        <svg width="11" height="13" viewBox="0 0 12 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 0C4.07 0 2.5 1.57 2.5 3.5V5H1.5A1.5 1.5 0 000 6.5v6A1.5 1.5 0 001.5 14h9A1.5 1.5 0 0012 12.5v-6A1.5 1.5 0 0010.5 5H9.5V3.5C9.5 1.57 7.93 0 6 0zm0 1.5c1.1 0 2 .9 2 2V5H4V3.5c0-1.1.9-2 2-2z" fill="currentColor"/></svg>
                        Secured by <span class="mira-stripe-wordmark">Stripe</span>
                    </div>
                    <div class="mira-booking-error" role="alert"></div>
                    <p class="mira-booking-note">You will be redirected to the Stripe credit card system to take payment. Once paid, you'll be redirected back here so we can email out your tickets. Any problems please email <a href="mailto:twcomedy@miramedia.co.uk">twcomedy@miramedia.co.uk</a>. Thanks so much for your support — we look forward to seeing you!</p>
                </form>
            <?php endif; ?>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    // ── [mira_next_event_rotator] shortcode ─────────────────────────────
    //
    // Left: a poster that auto-rotates through every upcoming event's featured
    // image (links to each event). Right: the booking widget, fixed on the
    // soonest event. Falls back to nothing when there are no upcoming events.

    public function next_event_rotator_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'interval' => 6000, // ms between slides
        ), $atts, 'mira_next_event_rotator' );

        $events = $this->upcoming_events_query( -1 );
        if ( ! $events->have_posts() ) {
            return '';
        }

        $slides = array();
        foreach ( $events->posts as $ev ) {
            if ( ! has_post_thumbnail( $ev->ID ) ) {
                continue;
            }
            $slides[] = array(
                'title' => get_the_title( $ev->ID ),
                'url'   => get_permalink( $ev->ID ),
                'img'   => get_the_post_thumbnail( $ev->ID, 'large', array( 'alt' => get_the_title( $ev->ID ), 'loading' => 'lazy' ) ),
            );
        }

        $next          = $events->posts[0];
        $next_id       = $next->ID;
        $ticket_price  = floatval( get_post_meta( $next_id, '_ticket_price', true ) );
        $event_date    = get_post_meta( $next_id, '_event_date', true );
        $display_date  = get_post_meta( $next_id, '_display_date', true );
        $location      = get_post_meta( $next_id, '_event_location', true );
        $when          = $display_date ?: ( $event_date ? date( 'l jS F Y', strtotime( $event_date ) ) : '' );

        ob_start();
        ?>
        <div class="twc-next-rotator" data-interval="<?php echo esc_attr( (int) $atts['interval'] ); ?>">

            <div class="twc-rotator" role="group" aria-label="<?php esc_attr_e( 'Upcoming event posters', 'mira-event-list' ); ?>">
                <?php foreach ( $slides as $i => $slide ) : ?>
                    <a class="twc-rotator-slide<?php echo 0 === $i ? ' is-active' : ''; ?>"
                       href="<?php echo esc_url( $slide['url'] ); ?>"
                       aria-hidden="<?php echo 0 === $i ? 'false' : 'true'; ?>">
                        <?php echo $slide['img']; ?>
                    </a>
                <?php endforeach; ?>
                <?php if ( count( $slides ) > 1 ) : ?>
                    <div class="twc-rotator-dots" role="tablist">
                        <?php foreach ( $slides as $i => $slide ) : ?>
                            <button type="button" class="twc-rotator-dot<?php echo 0 === $i ? ' is-active' : ''; ?>"
                                    data-index="<?php echo (int) $i; ?>"
                                    aria-label="<?php echo esc_attr( sprintf( __( 'Show poster %d', 'mira-event-list' ), $i + 1 ) ); ?>"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="twc-next-panel">
                <span class="mira-next-event-label"><?php esc_html_e( 'Next event', 'mira-event-list' ); ?></span>
                <h3 class="mira-next-event-title"><a href="<?php echo esc_url( get_permalink( $next_id ) ); ?>"><?php echo esc_html( get_the_title( $next_id ) ); ?></a></h3>
                <?php if ( $when || $location ) : ?>
                    <p class="twc-next-when"><?php
                        echo esc_html( $when );
                        if ( $when && $location ) echo '<br>';
                        echo esc_html( $location );
                    ?></p>
                <?php endif; ?>
                <?php echo $this->render_booking_form( $next_id, array( 'show_note' => false ) ); ?>
            </div>

        </div>
        <?php
        wp_reset_postdata();
        $this->print_rotator_script();
        return ob_get_clean();
    }

    /** One-time inline script that drives every `.twc-next-rotator` on the page. */
    private function print_rotator_script() {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;
        ?>
        <script>
        (function () {
            document.querySelectorAll('.twc-next-rotator').forEach(function (root) {
                var slides = root.querySelectorAll('.twc-rotator-slide');
                var dots   = root.querySelectorAll('.twc-rotator-dot');
                if (slides.length < 2) return;
                var i = 0, interval = parseInt(root.dataset.interval, 10) || 6000, timer;
                function show(n) {
                    slides[i].classList.remove('is-active');
                    slides[i].setAttribute('aria-hidden', 'true');
                    if (dots[i]) dots[i].classList.remove('is-active');
                    i = (n + slides.length) % slides.length;
                    slides[i].classList.add('is-active');
                    slides[i].setAttribute('aria-hidden', 'false');
                    if (dots[i]) dots[i].classList.add('is-active');
                }
                function start() { timer = setInterval(function () { show(i + 1); }, interval); }
                function stop() { clearInterval(timer); }
                dots.forEach(function (dot) {
                    dot.addEventListener('click', function () { stop(); show(parseInt(dot.dataset.index, 10)); start(); });
                });
                root.addEventListener('mouseenter', stop);
                root.addEventListener('mouseleave', start);
                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) start();
            });
        }());
        </script>
        <?php
    }

    // ── [mira_events_grid] shortcode ───────────────────────────────────
    //
    // Card grid of every upcoming event: banner, date kicker, title, venue and
    // an inline booking widget per card (same markup/JS as the single event).

    public function events_grid_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'limit' => -1 ), $atts, 'mira_events_grid' );

        $events = $this->upcoming_events_query( (int) $atts['limit'] );
        if ( ! $events->have_posts() ) {
            return '<p class="twc-events-grid-empty">' . esc_html__( 'No upcoming events just now — check back soon.', 'mira-event-list' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="twc-events-grid">
            <?php while ( $events->have_posts() ) : $events->the_post();
                $post_id      = get_the_ID();
                $event_date   = get_post_meta( $post_id, '_event_date', true );
                $display_date = get_post_meta( $post_id, '_display_date', true );
                $location     = get_post_meta( $post_id, '_event_location', true );
                $permalink    = get_permalink( $post_id );
                $kicker       = $display_date ?: ( $event_date ? date( 'D j M Y', strtotime( $event_date ) ) : '' );
            ?>
                <article class="twc-event-card">
                    <?php if ( has_post_thumbnail() ) : ?>
                        <a class="twc-event-card__banner" href="<?php echo esc_url( $permalink ); ?>">
                            <?php the_post_thumbnail( 'large', array( 'alt' => get_the_title(), 'loading' => 'lazy' ) ); ?>
                        </a>
                    <?php endif; ?>
                    <div class="twc-event-card__body">
                        <?php if ( $kicker ) : ?>
                            <div class="twc-event-card__kicker"><?php echo esc_html( $kicker ); ?></div>
                        <?php endif; ?>
                        <h3 class="twc-event-card__title"><a href="<?php echo esc_url( $permalink ); ?>"><?php the_title(); ?></a></h3>
                        <?php if ( $location ) : ?>
                            <div class="twc-event-card__venue"><?php echo esc_html( $location ); ?></div>
                        <?php endif; ?>
                        <div class="twc-event-card__book">
                            <?php echo $this->render_booking_form( $post_id, array( 'show_note' => false ) ); ?>
                        </div>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    // ── Scripts ──────────────────────────────────────────────────────────

    public function enqueue_scripts() {
        $css_path = MIRA_EVENT_LIST_PATH . 'assets/style.css';
        wp_enqueue_style(
            'mira-event-list-style',
            MIRA_EVENT_LIST_URL . 'assets/style.css',
            array(),
            file_exists( $css_path ) ? filemtime( $css_path ) : MIRA_EVENT_LIST_VERSION
        );

        $booking_css = MIRA_EVENT_LIST_PATH . 'assets/booking.css';
        wp_enqueue_style(
            'mira-booking-style',
            MIRA_EVENT_LIST_URL . 'assets/booking.css',
            array(),
            file_exists( $booking_css ) ? filemtime( $booking_css ) : MIRA_EVENT_LIST_VERSION
        );

        $booking_js = MIRA_EVENT_LIST_PATH . 'assets/booking.js';
        wp_enqueue_script(
            'mira-booking-script',
            MIRA_EVENT_LIST_URL . 'assets/booking.js',
            array(),
            file_exists( $booking_js ) ? filemtime( $booking_js ) : MIRA_EVENT_LIST_VERSION,
            true
        );

        $detail_css = MIRA_EVENT_LIST_PATH . 'assets/event-detail.css';
        wp_enqueue_style(
            'mira-event-detail',
            MIRA_EVENT_LIST_URL . 'assets/event-detail.css',
            array(),
            file_exists( $detail_css ) ? filemtime( $detail_css ) : MIRA_EVENT_LIST_VERSION
        );
    }

    public function admin_enqueue_scripts( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'mira_event' ) {
            return;
        }
        wp_enqueue_media();
        $js = MIRA_EVENT_LIST_PATH . 'assets/event-meta.js';
        wp_enqueue_script(
            'mira-event-meta',
            MIRA_EVENT_LIST_URL . 'assets/event-meta.js',
            array( 'jquery' ),
            file_exists( $js ) ? filemtime( $js ) : MIRA_EVENT_LIST_VERSION,
            true
        );
    }

    // ── Admin list columns ───────────────────────────────────────────────

    public function event_admin_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['event_category'] = __( 'Category', 'mira-event-list' );
            }
        }
        return $new;
    }

    public function event_admin_column_content( $column, $post_id ) {
        if ( $column === 'event_category' ) {
            $cat_id = (int) get_post_meta( $post_id, '_event_post_category', true );
            if ( $cat_id ) {
                $cat = get_category( $cat_id );
                echo $cat && ! is_wp_error( $cat ) ? esc_html( $cat->name ) : '—';
            } else {
                echo '—';
            }
        }
    }

    // ── Shortcode ────────────────────────────────────────────────────────

    public function register_shortcode() {
        add_shortcode( 'mira_event_list',         array( $this, 'event_list_shortcode' ) );
        add_shortcode( 'mira_next_event',         array( $this, 'event_next_shortcode' ) );
        add_shortcode( 'mira_next_event_rotator', array( $this, 'next_event_rotator_shortcode' ) );
        add_shortcode( 'mira_events_grid',        array( $this, 'events_grid_shortcode' ) );
        add_shortcode( 'mira_ticket_menu',        array( $this, 'ticket_menu_shortcode' ) );
    }

    // ── Ticket menu (dynamic list of upcoming events) ───────────────────

    /**
     * Upcoming events, soonest first, for the Tickets menu / shortcode.
     *
     * @return WP_Post[]
     */
    private function ticket_menu_events( $limit = 6, $tickets_only = false, $include_past = false ) {
        static $cache = array();
        $cache_key = (int) $limit . '|' . (int) $tickets_only . '|' . (int) $include_past;
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $meta_query = array();

        if ( ! $include_past ) {
            $meta_query[] = array(
                'key'     => '_event_date',
                'value'   => date( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            );
        }
        if ( $tickets_only ) {
            $meta_query[] = array(
                'key'     => '_tickets_enabled',
                'value'   => '1',
            );
        }
        if ( count( $meta_query ) > 1 ) {
            $meta_query['relation'] = 'AND';
        }

        $args = array(
            'post_type'      => 'mira_event',
            'post_status'    => 'publish',
            'posts_per_page' => $limit > 0 ? (int) $limit : -1,
            'meta_key'       => '_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );
        if ( $meta_query ) {
            $args['meta_query'] = $meta_query;
        }

        $q = new WP_Query( $args );

        $cache[ $cache_key ] = $q->posts;
        return $q->posts;
    }

    /** Short human date for an event: the display date if set, else the event date. */
    private function event_short_date( $event_id ) {
        $display = get_post_meta( $event_id, '_display_date', true );
        if ( $display ) {
            return $display;
        }
        $date = get_post_meta( $event_id, '_event_date', true );
        return $date ? date_i18n( 'j M Y', strtotime( $date ) ) : '';
    }

    /**
     * [mira_ticket_menu] — a plain <ul> of upcoming events, for menus that
     * accept shortcodes, widgets, or page content.
     *
     * Attributes: limit (6), tickets_only (0), show_date (1), past (0),
     * class (""), empty ("No upcoming events").
     */
    public function ticket_menu_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'limit'        => 6,
            'tickets_only' => 0,
            'show_date'    => 1,
            'past'         => 0,
            'class'        => '',
            'empty'        => __( 'No upcoming events', 'mira-event-list' ),
        ), $atts, 'mira_ticket_menu' );

        $events  = $this->ticket_menu_events(
            (int) $atts['limit'],
            (bool) intval( $atts['tickets_only'] ),
            (bool) intval( $atts['past'] )
        );
        $classes = trim( 'mira-ticket-menu ' . $atts['class'] );

        if ( empty( $events ) ) {
            return '<ul class="' . esc_attr( $classes ) . '"><li class="mira-ticket-menu-empty">'
                . esc_html( $atts['empty'] ) . '</li></ul>';
        }

        $show_date = (bool) intval( $atts['show_date'] );

        ob_start();
        echo '<ul class="' . esc_attr( $classes ) . '">';
        foreach ( $events as $ev ) {
            $date = $show_date ? $this->event_short_date( $ev->ID ) : '';
            printf(
                '<li class="mira-ticket-menu-item"><a href="%s">%s%s</a></li>',
                esc_url( get_permalink( $ev ) ),
                esc_html( get_the_title( $ev ) ),
                $date ? ' <span class="mira-ticket-menu-date">' . esc_html( $date ) . '</span>' : ''
            );
        }
        echo '</ul>';
        return ob_get_clean();
    }

    /** Is this nav-menu item the one that should hold the dynamic event list? */
    private function is_ticket_menu_item( $item ) {
        $classes = array_map( 'strtolower', (array) ( isset( $item->classes ) ? $item->classes : array() ) );
        if ( in_array( 'mira-ticket-menu', $classes, true ) ) {
            return true;
        }
        $url = strtolower( trim( (string) ( isset( $item->url ) ? $item->url : '' ) ) );
        return $url === '#mira-tickets';
    }

    /**
     * Inject upcoming events as sub-items under any nav-menu item tagged with
     * the CSS class "mira-ticket-menu" (or a custom link to "#mira-tickets").
     *
     * Filters: `mira_ticket_menu_count` (int, default 6),
     *          `mira_ticket_menu_show_date` (bool, default true),
     *          `mira_ticket_menu_tickets_only` (bool, default false).
     */
    public function nav_menu_ticket_items( $items, $menu, $args = array() ) {
        if ( is_admin() || empty( $items ) || ! is_array( $items ) ) {
            return $items;
        }

        $marked = false;
        foreach ( $items as $item ) {
            if ( $this->is_ticket_menu_item( $item ) ) {
                $marked = true;
                break;
            }
        }
        if ( ! $marked ) {
            return $items;
        }

        $limit        = (int) apply_filters( 'mira_ticket_menu_count', 6 );
        $show_date    = (bool) apply_filters( 'mira_ticket_menu_show_date', true );
        $tickets_only = (bool) apply_filters( 'mira_ticket_menu_tickets_only', false );
        $events       = $this->ticket_menu_events( $limit, $tickets_only );

        $rebuilt = array();
        $order   = 0;

        foreach ( $items as $item ) {
            $item->menu_order = ++$order;
            $rebuilt[]        = $item;

            if ( empty( $events ) || ! $this->is_ticket_menu_item( $item ) ) {
                continue;
            }

            foreach ( $events as $ev ) {
                $title = get_the_title( $ev );
                if ( $show_date ) {
                    $d = $this->event_short_date( $ev->ID );
                    if ( $d ) {
                        $title .= ' – ' . $d;
                    }
                }

                $child = new stdClass();
                $child->ID               = 900000000 + (int) $ev->ID;
                $child->db_id            = $child->ID;
                $child->menu_item_parent = (string) $item->ID;
                $child->object_id        = (int) $ev->ID;
                $child->object           = 'mira_event';
                $child->type             = 'post_type';
                $child->type_label       = __( 'Event', 'mira-event-list' );
                $child->title            = $title;
                $child->url              = get_permalink( $ev );
                $child->target           = '';
                $child->attr_title       = '';
                $child->description      = '';
                $child->classes          = array( 'mira-ticket-menu-item' );
                $child->xfn              = '';
                $child->current          = false;
                $child->menu_order       = ++$order;
                $child->post_type        = 'nav_menu_item';
                $child->post_status      = 'publish';

                $rebuilt[] = $child;
            }
        }

        return $rebuilt;
    }

    public function event_list_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'limit' => -1 ), $atts, 'mira_event_list' );

        $args = array(
            'post_type'      => 'mira_event',
            'post_status'    => 'publish',
            'posts_per_page' => $atts['limit'],
            'meta_key'       => '_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => array( array(
                'key'     => '_event_date',
                'value'   => date( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            ) ),
        );

        $events = new WP_Query( $args );

        if ( ! $events->have_posts() ) {
            return '<p>' . esc_html__( 'No upcoming events found.', 'mira-event-list' ) . '</p>';
        }

        $button_text       = get_option( 'mira_event_button_text', 'Goto Event' );
        $button_color      = get_option( 'mira_event_button_color', '#28a745' );
        $button_text_color = get_option( 'mira_event_button_text_color', '#fff' );
        $open_new_window   = get_option( 'mira_event_open_new_window', '1' );
        $target_attr       = $open_new_window ? 'target="_blank" rel="noopener"' : '';
        $ajax_url          = admin_url( 'admin-ajax.php' );

        ob_start();
        ?>
        <div class="mira-event-list">
            <?php while ( $events->have_posts() ) : $events->the_post();
                $post_id         = get_the_ID();
                $event_date      = get_post_meta( $post_id, '_event_date', true );
                $event_link      = get_post_meta( $post_id, '_event_link', true );
                $event_location  = get_post_meta( $post_id, '_event_location', true );
                $display_date    = get_post_meta( $post_id, '_display_date', true );
                $tickets_enabled = get_post_meta( $post_id, '_tickets_enabled', true );
                $ticket_price    = floatval( get_post_meta( $post_id, '_ticket_price', true ) );
                $enable_donation = get_post_meta( $post_id, '_enable_donation', true );
                $capacity        = MiraBookings::event_capacity( $post_id );
                $formatted_date  = $event_date ? date( 'j F Y', strtotime( $event_date ) ) : '';
            ?>
                <div class="mira-event-item">

                    <?php if ( has_post_thumbnail() ) : ?>
                        <div class="event-logo">
                            <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="event-logo-link">
                                <?php the_post_thumbnail( 'event-logo', array( 'alt' => get_the_title() ) ); ?>
                            </a>
                        </div>
                    <?php else : ?>
                        <div class="event-title">
                            <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="event-title-link">
                                <?php the_title(); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="event-content">
                        <?php if ( $display_date ) : ?>
                            <div class="event-date"><strong><?php echo esc_html( $display_date ); ?></strong></div>
                        <?php elseif ( $formatted_date ) : ?>
                            <div class="event-date"><strong><?php echo esc_html( $formatted_date ); ?></strong></div>
                        <?php endif; ?>

                        <?php if ( $event_location ) : ?>
                            <div class="event-location"><?php echo esc_html( $event_location ); ?></div>
                        <?php endif; ?>

                        <?php if ( has_excerpt() ) : ?>
                            <div class="event-excerpt"><?php the_excerpt(); ?></div>
                        <?php endif; ?>

                        <div class="event-goto-button-bottom">
                            <?php if ( $tickets_enabled && $capacity['sold_out'] ) : ?>
                                <?php echo $this->sold_out_notice(); ?>
                            <?php elseif ( $tickets_enabled ) :
                                $btn_label = '£' . number_format( $ticket_price, 2 ) . ' per ticket — Book Now';
                                $qty_max   = $this->booking_qty_max( $capacity );
                            ?>
                                <?php echo $this->tickets_left_notice( $capacity ); ?>
                                <form class="mira-booking-form"
                                      data-event-id="<?php echo esc_attr( $post_id ); ?>"
                                      data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                                      data-nonce="<?php echo esc_attr( wp_create_nonce( 'mira_booking_nonce' ) ); ?>"
                                      data-ticket-price="<?php echo esc_attr( $ticket_price ); ?>"
                                      data-fallback-url="<?php echo esc_url( $this->fallback_payment_link( $post_id ) ); ?>">

                                    <div class="mira-booking-fields">
                                        <div class="mira-qty-wrap">
                                            <label for="mira-qty-<?php echo $post_id; ?>"><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></label>
                                            <select id="mira-qty-<?php echo $post_id; ?>" name="quantity" class="mira-qty-select">
                                                <?php for ( $i = 1; $i <= $qty_max; $i++ ) : ?>
                                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <?php if ( $enable_donation ) : ?>
                                            <div class="mira-donation-wrap">
                                                <label for="mira-don-<?php echo $post_id; ?>"><?php esc_html_e( 'Donation (£)', 'mira-event-list' ); ?></label>
                                                <input id="mira-don-<?php echo $post_id; ?>"
                                                       type="number"
                                                       name="donation"
                                                       min="0"
                                                       step="0.50"
                                                       placeholder="0.00"
                                                       class="mira-donation-input">
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mira-booking-total">Total: <strong class="mira-total-amount">£<?php echo number_format( $ticket_price, 2 ); ?></strong></div>
                                    <button type="submit"
                                            class="mira-book-btn"
                                            data-label="<?php echo esc_attr( $btn_label ); ?>"
                                            style="background-color:<?php echo esc_attr( $button_color ); ?>;color:<?php echo esc_attr( $button_text_color ); ?>;">
                                        <?php echo esc_html( $btn_label ); ?>
                                    </button>
                                    <div class="mira-stripe-badge">
                                        <svg width="11" height="13" viewBox="0 0 12 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 0C4.07 0 2.5 1.57 2.5 3.5V5H1.5A1.5 1.5 0 000 6.5v6A1.5 1.5 0 001.5 14h9A1.5 1.5 0 0012 12.5v-6A1.5 1.5 0 0010.5 5H9.5V3.5C9.5 1.57 7.93 0 6 0zm0 1.5c1.1 0 2 .9 2 2V5H4V3.5c0-1.1.9-2 2-2z" fill="currentColor"/></svg>
                                        Secured by <span class="mira-stripe-wordmark">Stripe</span>
                                    </div>
                                    <div class="mira-booking-error" role="alert"></div>
                                    <p class="mira-booking-note">You will be redirected to the Stripe credit card system to take payment. Once paid, you'll be redirected back here so we can email out your tickets. Any problems please email <a href="mailto:twcomedy@miramedia.co.uk">twcomedy@miramedia.co.uk</a>. Thanks so much for your support — we look forward to seeing you!</p>
                                </form>

                            <?php elseif ( $event_link ) : ?>
                                <a href="<?php echo esc_url( $event_link ); ?>"
                                   class="goto-event-btn-bottom"
                                   <?php echo $target_attr; ?>
                                   style="background-color:<?php echo esc_attr( $button_color ); ?>;color:<?php echo esc_attr( $button_text_color ); ?>;">
                                    <?php echo esc_html( $button_text ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            <?php endwhile; ?>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    // ── Admin menu + settings ────────────────────────────────────────────

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=mira_event',
            __( 'Event Settings', 'mira-event-list' ),
            __( 'Settings', 'mira-event-list' ),
            'manage_options',
            'mira-event-settings',
            array( $this, 'options_page' )
        );

        add_submenu_page(
            'edit.php?post_type=mira_event',
            __( 'Mira Event List Guide', 'mira-event-list' ),
            __( 'Guide', 'mira-event-list' ),
            'edit_posts',
            'mira-event-guide',
            array( $this, 'guide_page' )
        );
    }

    public function settings_init() {
        // ── Button section ───────────────────────────────────────────────
        register_setting( 'mira_event_settings', 'mira_event_button_text' );
        register_setting( 'mira_event_settings', 'mira_event_button_color' );
        register_setting( 'mira_event_settings', 'mira_event_button_text_color' );
        register_setting( 'mira_event_settings', 'mira_event_open_new_window' );

        add_settings_section(
            'mira_button_section',
            __( 'Button Customisation', 'mira-event-list' ),
            function() {
                echo '<p>' . esc_html__( 'Customise the event buttons displayed in the shortcode.', 'mira-event-list' ) . '</p>';
                echo '<p>' . esc_html__( 'Shortcode: [mira_event_list]', 'mira-event-list' ) . '</p>';
            },
            'mira_event_settings'
        );

        add_settings_field( 'mira_event_button_text',       __( 'Button Text', 'mira-event-list' ),        array( $this, 'button_text_render' ),       'mira_event_settings', 'mira_button_section' );
        add_settings_field( 'mira_event_button_color',      __( 'Button Colour', 'mira-event-list' ),       array( $this, 'button_color_render' ),      'mira_event_settings', 'mira_button_section' );
        add_settings_field( 'mira_event_button_text_color', __( 'Button Text Colour', 'mira-event-list' ),  array( $this, 'button_text_color_render' ), 'mira_event_settings', 'mira_button_section' );
        add_settings_field( 'mira_event_open_new_window',   __( 'Open in New Window', 'mira-event-list' ),  array( $this, 'open_new_window_render' ),   'mira_event_settings', 'mira_button_section' );

        // ── Stripe section ───────────────────────────────────────────────
        register_setting( 'mira_event_settings', 'mira_stripe_mode',                 array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'mira_event_settings', 'mira_stripe_test_secret',          array( 'sanitize_callback' => array( $this, 'sanitize_stripe_test_key' ) ) );
        register_setting( 'mira_event_settings', 'mira_stripe_live_secret',          array( 'sanitize_callback' => array( $this, 'sanitize_stripe_live_key' ) ) );
        register_setting( 'mira_event_settings', 'mira_stripe_test_webhook_secret',  array( 'sanitize_callback' => array( $this, 'sanitize_stripe_test_webhook_secret' ) ) );
        register_setting( 'mira_event_settings', 'mira_stripe_live_webhook_secret',  array( 'sanitize_callback' => array( $this, 'sanitize_stripe_live_webhook_secret' ) ) );

        add_settings_section(
            'mira_stripe_section',
            __( 'Stripe Payments', 'mira-event-list' ),
            array( $this, 'stripe_section_callback' ),
            'mira_event_settings'
        );

        add_settings_field( 'mira_stripe_mode',                __( 'Mode', 'mira-event-list' ),                   array( $this, 'stripe_mode_render' ),                 'mira_event_settings', 'mira_stripe_section' );
        add_settings_field( 'mira_stripe_test_secret',         __( 'Test Secret Key', 'mira-event-list' ),         array( $this, 'stripe_test_secret_render' ),          'mira_event_settings', 'mira_stripe_section' );
        add_settings_field( 'mira_stripe_live_secret',         __( 'Live Secret Key', 'mira-event-list' ),         array( $this, 'stripe_live_secret_render' ),          'mira_event_settings', 'mira_stripe_section' );
        add_settings_field( 'mira_stripe_test_webhook_secret', __( 'Test Webhook Secret', 'mira-event-list' ),     array( $this, 'stripe_test_webhook_secret_render' ),  'mira_event_settings', 'mira_stripe_section' );
        add_settings_field( 'mira_stripe_live_webhook_secret', __( 'Live Webhook Secret', 'mira-event-list' ),     array( $this, 'stripe_live_webhook_secret_render' ),  'mira_event_settings', 'mira_stripe_section' );

        // ── Email section ────────────────────────────────────────────────
        register_setting( 'mira_event_settings', 'mira_ticket_from_name',     array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'mira_event_settings', 'mira_ticket_from_email',    array( 'sanitize_callback' => 'sanitize_email' ) );
        register_setting( 'mira_event_settings', 'mira_ticket_email_subject', array( 'sanitize_callback' => 'sanitize_text_field' ) );

        add_settings_section(
            'mira_email_section',
            __( 'Ticket Emails', 'mira-event-list' ),
            function() {
                echo '<p>' . esc_html__( 'Configure the emails sent to ticket holders.', 'mira-event-list' ) . '</p>';
            },
            'mira_event_settings'
        );

        add_settings_field( 'mira_ticket_from_name',     __( 'From Name', 'mira-event-list' ),     array( $this, 'ticket_from_name_render' ),     'mira_event_settings', 'mira_email_section' );
        add_settings_field( 'mira_ticket_from_email',    __( 'From Email', 'mira-event-list' ),     array( $this, 'ticket_from_email_render' ),    'mira_event_settings', 'mira_email_section' );
        add_settings_field( 'mira_ticket_email_subject', __( 'Email Subject', 'mira-event-list' ),  array( $this, 'ticket_email_subject_render' ), 'mira_event_settings', 'mira_email_section' );

        // ── Mailjet section ─────────────────────────────────────────────
        register_setting( 'mira_event_settings', 'mira_mailjet_enabled',    array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'mira_event_settings', 'mira_mailjet_api_key',    array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'mira_event_settings', 'mira_mailjet_secret_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'mira_event_settings', 'mira_mailjet_list_id',    array( 'sanitize_callback' => 'sanitize_text_field' ) );

        add_settings_section(
            'mira_mailjet_section',
            __( 'Mailjet Sync', 'mira-event-list' ),
            array( $this, 'mailjet_section_callback' ),
            'mira_event_settings'
        );

        add_settings_field( 'mira_mailjet_enabled',    __( 'Enable Sync', 'mira-event-list' ),      array( $this, 'mailjet_enabled_render' ),    'mira_event_settings', 'mira_mailjet_section' );
        add_settings_field( 'mira_mailjet_api_key',    __( 'API Key', 'mira-event-list' ),          array( $this, 'mailjet_api_key_render' ),    'mira_event_settings', 'mira_mailjet_section' );
        add_settings_field( 'mira_mailjet_secret_key', __( 'Secret Key', 'mira-event-list' ),       array( $this, 'mailjet_secret_key_render' ), 'mira_event_settings', 'mira_mailjet_section' );
        add_settings_field( 'mira_mailjet_list_id',    __( 'Contact List ID', 'mira-event-list' ),  array( $this, 'mailjet_list_id_render' ),    'mira_event_settings', 'mira_mailjet_section' );
    }

    public function sanitize_checkbox( $value ) {
        return $value === '1' ? '1' : '';
    }

    // ── Settings field renderers ─────────────────────────────────────────

    public function button_text_render() {
        $v = get_option( 'mira_event_button_text', 'Goto Event' );
        echo '<input type="text" name="mira_event_button_text" value="' . esc_attr( $v ) . '">';
    }

    public function button_color_render() {
        $v = get_option( 'mira_event_button_color', '#28a745' );
        echo '<input type="color" name="mira_event_button_color" value="' . esc_attr( $v ) . '">';
    }

    public function button_text_color_render() {
        $v = get_option( 'mira_event_button_text_color', '#fff' );
        echo '<input type="color" name="mira_event_button_text_color" value="' . esc_attr( $v ) . '">';
    }

    public function open_new_window_render() {
        $v = get_option( 'mira_event_open_new_window', '1' );
        echo '<label><input type="checkbox" name="mira_event_open_new_window" value="1" ' . checked( $v, '1', false ) . '>';
        echo ' ' . esc_html__( 'Open event links in a new tab', 'mira-event-list' ) . '</label>';
    }

    public function stripe_section_callback() {
        $webhook_url = rest_url( 'mira/v1/stripe-webhook' );
        $success_page_id = (int) get_option( 'mira_stripe_success_page_id', 0 );
        $success_url = $success_page_id ? get_permalink( $success_page_id ) : home_url( '/booking-complete/' );
        ?>
        <p><?php esc_html_e( 'Configure your Stripe keys. Keys are stored in the database — do not share them.', 'mira-event-list' ); ?></p>
        <p><strong><?php esc_html_e( 'Webhook URL (add this in your Stripe dashboard → Developers → Webhooks):', 'mira-event-list' ); ?></strong><br>
           <code><?php echo esc_html( $webhook_url ); ?></code><br>
           <small><?php esc_html_e( 'Listen for the event: checkout.session.completed', 'mira-event-list' ); ?></small><br>
           <small><?php esc_html_e( 'Test and live mode have separate webhook endpoints with different signing secrets — paste each into the matching field below.', 'mira-event-list' ); ?></small></p>
        <p><strong><?php esc_html_e( 'Booking success page:', 'mira-event-list' ); ?></strong><br>
           <code><?php echo esc_html( $success_url ); ?></code></p>
        <?php
    }

    public function stripe_mode_render() {
        $v = get_option( 'mira_stripe_mode', 'test' );
        ?>
        <label><input type="radio" name="mira_stripe_mode" value="test" <?php checked( $v, 'test' ); ?>>
            <?php esc_html_e( 'Test (use Stripe test keys)', 'mira-event-list' ); ?></label>&nbsp;&nbsp;
        <label><input type="radio" name="mira_stripe_mode" value="live" <?php checked( $v, 'live' ); ?>>
            <?php esc_html_e( 'Live', 'mira-event-list' ); ?></label>
        <?php
    }

    /**
     * Render a credential input that browser password managers leave alone.
     *
     * These fields kept getting clobbered by autofill (a saved email address
     * landing in the webhook-secret box, breaking signature verification), so
     * they are plain text with every "don't autofill me" hint we can give.
     */
    private function render_secret_field( $name, $value, $description ) {
        printf(
            '<input type="text" name="%1$s" value="%2$s" class="regular-text code" '
            . 'autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" '
            . 'data-lpignore="true" data-1p-ignore data-form-type="other" data-bwignore>',
            esc_attr( $name ),
            esc_attr( $value )
        );
        echo '<p class="description">' . esc_html( $description ) . '</p>';
    }

    public function stripe_test_secret_render() {
        $this->render_secret_field( 'mira_stripe_test_secret', get_option( 'mira_stripe_test_secret', '' ), __( 'Starts with sk_test_ (or rk_test_)', 'mira-event-list' ) );
    }

    public function stripe_live_secret_render() {
        $this->render_secret_field( 'mira_stripe_live_secret', get_option( 'mira_stripe_live_secret', '' ), __( 'Starts with sk_live_ (or rk_live_)', 'mira-event-list' ) );
    }

    public function stripe_test_webhook_secret_render() {
        $this->render_secret_field( 'mira_stripe_test_webhook_secret', get_option( 'mira_stripe_test_webhook_secret', '' ), __( 'Signing secret for your Stripe test-mode webhook. Starts with whsec_', 'mira-event-list' ) );
    }

    public function stripe_live_webhook_secret_render() {
        $this->render_secret_field( 'mira_stripe_live_webhook_secret', get_option( 'mira_stripe_live_webhook_secret', '' ), __( 'Signing secret for your Stripe live-mode webhook. Starts with whsec_', 'mira-event-list' ) );
    }

    // ── Stripe credential validation ─────────────────────────────────────
    //
    // Reject a value that does not look like the expected Stripe credential
    // (wrong or missing prefix) and keep whatever was stored before, so a
    // stray autofill or paste error can't silently break payments/webhooks.

    private function sanitize_stripe_credential( $option, $input, $prefixes, $label ) {
        $input = sanitize_text_field( $input );

        if ( $input === '' ) {
            return '';
        }

        foreach ( (array) $prefixes as $prefix ) {
            if ( strpos( $input, $prefix ) === 0 ) {
                return $input;
            }
        }

        add_settings_error(
            'mira_event_settings',
            $option,
            sprintf(
                /* translators: 1: field label, 2: expected prefix list */
                __( '%1$s was not saved: it must start with %2$s. The previous value has been kept.', 'mira-event-list' ),
                $label,
                implode( __( ' or ', 'mira-event-list' ), (array) $prefixes )
            )
        );

        return get_option( $option, '' );
    }

    public function sanitize_stripe_test_key( $input ) {
        return $this->sanitize_stripe_credential( 'mira_stripe_test_secret', $input, array( 'sk_test_', 'rk_test_' ), __( 'Test Secret Key', 'mira-event-list' ) );
    }

    public function sanitize_stripe_live_key( $input ) {
        return $this->sanitize_stripe_credential( 'mira_stripe_live_secret', $input, array( 'sk_live_', 'rk_live_' ), __( 'Live Secret Key', 'mira-event-list' ) );
    }

    public function sanitize_stripe_test_webhook_secret( $input ) {
        return $this->sanitize_stripe_credential( 'mira_stripe_test_webhook_secret', $input, array( 'whsec_' ), __( 'Test Webhook Secret', 'mira-event-list' ) );
    }

    public function sanitize_stripe_live_webhook_secret( $input ) {
        return $this->sanitize_stripe_credential( 'mira_stripe_live_webhook_secret', $input, array( 'whsec_' ), __( 'Live Webhook Secret', 'mira-event-list' ) );
    }

    public function ticket_from_name_render() {
        $v = get_option( 'mira_ticket_from_name', get_bloginfo( 'name' ) );
        echo '<input type="text" name="mira_ticket_from_name" value="' . esc_attr( $v ) . '" class="regular-text">';
    }

    public function ticket_from_email_render() {
        $v = get_option( 'mira_ticket_from_email', get_option( 'admin_email' ) );
        echo '<input type="email" name="mira_ticket_from_email" value="' . esc_attr( $v ) . '" class="regular-text">';
    }

    public function ticket_email_subject_render() {
        $v = get_option( 'mira_ticket_email_subject', 'Your ticket for {event_name}' );
        echo '<input type="text" name="mira_ticket_email_subject" value="' . esc_attr( $v ) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__( 'Use {event_name} as a placeholder for the event title.', 'mira-event-list' ) . '</p>';
    }

    // ── Mailjet settings renderers ───────────────────────────────────────

    public function mailjet_section_callback() {
        ?>
        <p><?php esc_html_e( 'Sync buyer and attendee email addresses into a Mailjet contact list as bookings are paid. Each contact is tagged with a per-event boolean property so you can target campaigns at the people who booked a specific event.', 'mira-event-list' ); ?></p>
        <p><?php esc_html_e( 'Find your keys in Mailjet under Account settings → REST API → API Key Management.', 'mira-event-list' ); ?></p>
        <?php

        $last_error = get_option( MiraMailjet::LAST_ERROR, '' );
        if ( $last_error ) {
            echo '<p style="color:#b00"><strong>' . esc_html__( 'Last sync error:', 'mira-event-list' ) . '</strong> <code>' . esc_html( $last_error ) . '</code></p>';
        }

        if ( MiraMailjet::has_keys() ) {
            $lists = MiraMailjet::get_lists();
            if ( empty( $lists ) ) {
                echo '<p style="color:#b00">' . esc_html__( 'Could not load contact lists — check the API key and secret above.', 'mira-event-list' ) . '</p>';
            } else {
                echo '<p><strong>' . esc_html__( 'Your contact lists (copy the numeric ID into the field below):', 'mira-event-list' ) . '</strong></p>';
                echo '<table class="wp-list-table widefat fixed striped" style="max-width:520px;margin-bottom:1em"><thead><tr>';
                echo '<th style="width:110px">' . esc_html__( 'ID', 'mira-event-list' ) . '</th>';
                echo '<th>' . esc_html__( 'Name', 'mira-event-list' ) . '</th>';
                echo '<th style="width:110px">' . esc_html__( 'Contacts', 'mira-event-list' ) . '</th>';
                echo '</tr></thead><tbody>';
                foreach ( $lists as $l ) {
                    printf(
                        '<tr><td><code>%d</code></td><td>%s</td><td>%s</td></tr>',
                        (int) $l['id'],
                        esc_html( $l['name'] ),
                        esc_html( number_format_i18n( $l['count'] ) )
                    );
                }
                echo '</tbody></table>';
            }
        }
    }

    public function mailjet_enabled_render() {
        $v = get_option( 'mira_mailjet_enabled', '' );
        echo '<input type="hidden" name="mira_mailjet_enabled" value="0">';
        echo '<label><input type="checkbox" name="mira_mailjet_enabled" value="1" ' . checked( $v, '1', false ) . '> ';
        echo esc_html__( 'Push booking emails to Mailjet as bookings are paid', 'mira-event-list' ) . '</label>';
    }

    public function mailjet_api_key_render() {
        $v = get_option( 'mira_mailjet_api_key', '' );
        echo '<input type="text" name="mira_mailjet_api_key" value="' . esc_attr( $v ) . '" class="regular-text" autocomplete="off" autocapitalize="off" spellcheck="false">';
    }

    public function mailjet_secret_key_render() {
        $v = get_option( 'mira_mailjet_secret_key', '' );
        echo '<input type="password" name="mira_mailjet_secret_key" value="' . esc_attr( $v ) . '" class="regular-text" autocomplete="new-password">';
    }

    public function mailjet_list_id_render() {
        $v = get_option( 'mira_mailjet_list_id', '' );
        echo '<input type="text" name="mira_mailjet_list_id" value="' . esc_attr( $v ) . '" class="regular-text" inputmode="numeric">';
        echo '<p class="description">' . esc_html__( 'Numeric ID of the contact list new bookers are added to (e.g. the TWComedy.club list). See the table above.', 'mira-event-list' ) . '</p>';
    }

    // ── Guide page ───────────────────────────────────────────────────────

    public function guide_page() {
        $success_page_id = (int) get_option( 'mira_stripe_success_page_id', 0 );
        $success_link    = $success_page_id ? get_permalink( $success_page_id ) : '';
        $webhook_url     = rest_url( 'mira/v1/stripe-webhook' );
        $settings_url    = admin_url( 'edit.php?post_type=mira_event&page=mira-event-settings' );
        $bookings_url    = admin_url( 'edit.php?post_type=mira_event&page=mira-bookings' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Mira Event List — Guide', 'mira-event-list' ); ?></h1>
            <p style="max-width:820px"><?php
                printf(
                    /* translators: %s: plugin version */
                    esc_html__( 'Reference for shortcodes, event options, bookings and settings. Plugin version %s.', 'mira-event-list' ),
                    esc_html( MIRA_EVENT_LIST_VERSION )
                );
            ?></p>

            <div style="max-width:820px">

            <h2><?php esc_html_e( 'Quick start', 'mira-event-list' ); ?></h2>
            <ol>
                <li><?php esc_html_e( 'Create events under Events → Add New. The title is the event name; the Featured Image is the logo. Set the Event Date in the Event Details box.', 'mira-event-list' ); ?></li>
                <li><?php
                    printf(
                        wp_kses( __( 'Put %s on a page to show the grid of upcoming events.', 'mira-event-list' ), array( 'code' => array() ) ),
                        '<code>[mira_event_list]</code>'
                    );
                ?></li>
                <li><?php
                    printf(
                        wp_kses( __( 'To sell tickets: open an event, tick %1$s in the Ticketing box and set a price. Add your Stripe keys under %2$s.', 'mira-event-list' ), array( 'strong' => array(), 'a' => array( 'href' => array() ) ) ),
                        '<strong>' . esc_html__( 'Enable Ticketing', 'mira-event-list' ) . '</strong>',
                        '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Events → Settings', 'mira-event-list' ) . '</a>'
                    );
                ?></li>
            </ol>

            <h2><?php esc_html_e( 'Shortcodes', 'mira-event-list' ); ?></h2>
            <table class="widefat striped" style="margin-bottom:1em">
                <thead>
                    <tr>
                        <th style="width:190px"><?php esc_html_e( 'Shortcode', 'mira-event-list' ); ?></th>
                        <th><?php esc_html_e( 'What it does', 'mira-event-list' ); ?></th>
                        <th style="width:280px"><?php esc_html_e( 'Attributes', 'mira-event-list' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[mira_event_list]</code></td>
                        <td><?php esc_html_e( 'Responsive grid of every upcoming event, soonest first. Each card shows a booking form (when ticketing is on), a "Goto Event" button (when an external link is set), or "SOLD OUT" / "Only X tickets left" based on capacity.', 'mira-event-list' ); ?></td>
                        <td><code>limit</code> — <?php esc_html_e( 'number of events (default: all)', 'mira-event-list' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>[mira_next_event]</code></td>
                        <td><?php esc_html_e( 'The single soonest upcoming event as a feature block: banner image, title, and its booking form.', 'mira-event-list' ); ?></td>
                        <td><?php esc_html_e( 'none', 'mira-event-list' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>[mira_ticket_menu]</code></td>
                        <td><?php esc_html_e( 'A plain <ul> list of upcoming events (title + date) linking to each event page. For sidebars, blocks, footers, or menu plugins that run shortcodes. For the main nav menu, use the CSS-class method below instead.', 'mira-event-list' ); ?></td>
                        <td>
                            <code>limit</code> (6),
                            <code>tickets_only</code> (0),
                            <code>show_date</code> (1),
                            <code>past</code> (0),
                            <code>class</code> (&quot;&quot;),
                            <code>empty</code> (&quot;No upcoming events&quot;)
                        </td>
                    </tr>
                    <tr>
                        <td><code>[mira_booking_success]</code></td>
                        <td><?php
                            esc_html_e( 'The post-payment page: confirms payment, collects each attendee\'s name and email, and emails out the tickets. Added automatically to the "Booking Complete" page on activation — you should not normally place this yourself.', 'mira-event-list' );
                            if ( $success_link ) {
                                echo ' <a href="' . esc_url( $success_link ) . '" target="_blank" rel="noopener">' . esc_html__( 'View page', 'mira-event-list' ) . '</a>';
                            }
                        ?></td>
                        <td><?php esc_html_e( 'none', 'mira-event-list' ); ?></td>
                    </tr>
                </tbody>
            </table>
            <p><strong><?php esc_html_e( 'Examples', 'mira-event-list' ); ?></strong></p>
            <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto">[mira_event_list]
[mira_event_list limit="6"]
[mira_next_event]
[mira_ticket_menu limit="8" tickets_only="1"]
[mira_ticket_menu show_date="0" class="my-footer-list" empty="Nothing on sale right now"]</pre>

            <h2><?php esc_html_e( 'Dynamic "Tickets" menu', 'mira-event-list' ); ?></h2>
            <p><?php esc_html_e( 'Turn a nav-menu item into a self-updating dropdown of upcoming events:', 'mira-event-list' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Appearance → Menus → Screen Options (top right) → tick "CSS Classes".', 'mira-event-list' ); ?></li>
                <li><?php
                    printf(
                        wp_kses( __( 'Open the parent menu item (e.g. "Tickets") and put %s in the CSS Classes field. Alternatively add a Custom Link pointing at %s.', 'mira-event-list' ), array( 'code' => array() ) ),
                        '<code>mira-ticket-menu</code>',
                        '<code>#mira-tickets</code>'
                    );
                ?></li>
                <li><?php esc_html_e( 'Save. The item is filled with the next upcoming events, soonest first. Remove any events you previously added by hand.', 'mira-event-list' ); ?></li>
            </ol>
            <p><?php esc_html_e( 'Tune it from your theme\'s functions.php:', 'mira-event-list' ); ?></p>
            <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto">add_filter( 'mira_ticket_menu_count', fn() =&gt; 8 );            // how many events (default 6)
add_filter( 'mira_ticket_menu_show_date', '__return_false' );  // hide the date suffix
add_filter( 'mira_ticket_menu_tickets_only', '__return_true' ); // ticketed events only</pre>

            <h2><?php esc_html_e( 'Per-event options (Ticketing box)', 'mira-event-list' ); ?></h2>
            <table class="widefat striped" style="margin-bottom:1em">
                <tbody>
                    <tr>
                        <td style="width:190px"><strong><?php esc_html_e( 'Enable Ticketing', 'mira-event-list' ); ?></strong></td>
                        <td><?php esc_html_e( 'Replaces the event-link button with a Stripe booking form.', 'mira-event-list' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Price per Ticket', 'mira-event-list' ); ?></strong></td>
                        <td><?php esc_html_e( 'In GBP. Used for the booking form and pre-fills the manual-booking price.', 'mira-event-list' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Maximum Tickets', 'mira-event-list' ); ?></strong></td>
                        <td><?php esc_html_e( 'Total capacity. "SOLD OUT" shows once paid tickets reach this number. "Only X tickets left" appears once the remaining count is within the final 25% — that count includes pending (unpaid) orders. Blank or 0 = unlimited. The box shows a live "Sold so far" readout.', 'mira-event-list' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Enable Donations', 'mira-event-list' ); ?></strong></td>
                        <td><?php esc_html_e( 'Adds an optional donation field to the booking form.', 'mira-event-list' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Mailjet Tag', 'mira-event-list' ); ?></strong></td>
                        <td><?php esc_html_e( 'Boolean contact property set in Mailjet on everyone who books this event. Leave blank to use the auto-generated name.', 'mira-event-list' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Bookings', 'mira-event-list' ); ?></h2>
            <p><?php
                printf(
                    wp_kses( __( 'Manage every booking under %s.', 'mira-event-list' ), array( 'a' => array( 'href' => array() ) ) ),
                    '<a href="' . esc_url( $bookings_url ) . '">' . esc_html__( 'Events → Bookings', 'mira-event-list' ) . '</a>'
                );
            ?></p>
            <ul style="list-style:disc;padding-left:20px">
                <li><strong><?php esc_html_e( 'Statuses', 'mira-event-list' ); ?>:</strong>
                    <em><?php esc_html_e( 'pending', 'mira-event-list' ); ?></em> — <?php esc_html_e( 'payment not confirmed (abandoned checkout, or a manual booking awaiting payment)', 'mira-event-list' ); ?>;
                    <em><?php esc_html_e( 'paid', 'mira-event-list' ); ?></em> — <?php esc_html_e( 'paid, attendee details not yet collected', 'mira-event-list' ); ?>;
                    <em><?php esc_html_e( 'complete', 'mira-event-list' ); ?></em> — <?php esc_html_e( 'paid and attendee details collected', 'mira-event-list' ); ?>.
                </li>
                <li><strong><?php esc_html_e( 'Filters', 'mira-event-list' ); ?>:</strong> <?php esc_html_e( 'by status, event, and payment method.', 'mira-event-list' ); ?></li>
                <li><strong><?php esc_html_e( 'Revenue Summary', 'mira-event-list' ); ?>:</strong> <?php esc_html_e( 'per-event bookings, tickets and revenue for paid + complete bookings.', 'mira-event-list' ); ?></li>
                <li><strong><?php esc_html_e( 'Row actions', 'mira-event-list' ); ?>:</strong> <?php esc_html_e( 'Resend tickets (CC\'d to the site admin), Delete, and — for unpaid manual bookings — "Mark paid & send".', 'mira-event-list' ); ?></li>
                <li><strong><?php esc_html_e( 'Export CSV', 'mira-event-list' ); ?>:</strong> <?php esc_html_e( 'one row per attendee, respecting the current filters.', 'mira-event-list' ); ?></li>
                <li><strong><?php esc_html_e( 'Sync all to Mailjet', 'mira-event-list' ); ?>:</strong> <?php esc_html_e( 'backfill every paid/complete booking (shown only when Mailjet Sync is on).', 'mira-event-list' ); ?></li>
            </ul>

            <h3><?php esc_html_e( 'Manual / cash bookings', 'mira-event-list' ); ?></h3>
            <p><?php esc_html_e( 'Use "Add Manual Booking" for people who pay cash, by card in person, by bank transfer, or who come in free.', 'mira-event-list' ); ?></p>
            <ul style="list-style:disc;padding-left:20px">
                <li><?php esc_html_e( 'Pick the event and payment method, add one row per attendee (name + email), and a note.', 'mira-event-list' ); ?></li>
                <li><?php esc_html_e( '"Payment received" unticked → saved as pending, no tickets sent. When the money arrives, use "Mark as paid & send tickets" to complete it and email everyone.', 'mira-event-list' ); ?></li>
                <li><?php esc_html_e( '"Payment received" ticked → saved as complete; tickets are emailed straight away if "Email tickets now" is left on.', 'mira-event-list' ); ?></li>
                <li><?php esc_html_e( 'Manual bookings count towards event capacity: pending ones reduce "tickets left", paid ones count towards SOLD OUT.', 'mira-event-list' ); ?></li>
            </ul>

            <h2><?php esc_html_e( 'Settings (Events → Settings)', 'mira-event-list' ); ?></h2>
            <table class="widefat striped" style="margin-bottom:1em">
                <tbody>
                    <tr>
                        <td style="width:190px"><strong><?php esc_html_e( 'Button Customisation', 'mira-event-list' ); ?></strong></td>
                        <td><?php esc_html_e( 'Text, colours and new-tab behaviour for the "Goto Event" button in the grid.', 'mira-event-list' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Stripe Payments', 'mira-event-list' ); ?></strong></td>
                        <td>
                            <?php esc_html_e( 'Test / Live mode, secret keys, and a per-mode webhook signing secret. Add this endpoint in your Stripe dashboard (event: checkout.session.completed):', 'mira-event-list' ); ?>
                            <br><code><?php echo esc_html( $webhook_url ); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Ticket Emails', 'mira-event-list' ); ?></strong></td>
                        <td><?php
                            printf(
                                wp_kses( __( 'From name, from address, and subject line. %s is replaced with the event name.', 'mira-event-list' ), array( 'code' => array() ) ),
                                '<code>{event_name}</code>'
                            );
                        ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Mailjet Sync', 'mira-event-list' ); ?></strong></td>
                        <td><?php esc_html_e( 'Enable toggle, API + secret keys, and contact-list ID. When on, buyer and attendee emails are pushed to Mailjet as bookings are paid, tagged per event.', 'mira-event-list' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Developer hooks', 'mira-event-list' ); ?></h2>
            <table class="widefat striped">
                <tbody>
                    <tr><td style="width:260px"><code>mira_ticket_menu_count</code></td><td><?php esc_html_e( 'int — events in the dynamic Tickets menu (default 6).', 'mira-event-list' ); ?></td></tr>
                    <tr><td><code>mira_ticket_menu_show_date</code></td><td><?php esc_html_e( 'bool — append the date to each Tickets-menu item (default true).', 'mira-event-list' ); ?></td></tr>
                    <tr><td><code>mira_ticket_menu_tickets_only</code></td><td><?php esc_html_e( 'bool — limit the Tickets menu to events with ticketing enabled (default false).', 'mira-event-list' ); ?></td></tr>
                </tbody>
            </table>

            </div>
        </div>
        <?php
    }

    // ── Options page ─────────────────────────────────────────────────────

    public function options_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Mira Event List Settings', 'mira-event-list' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mira_event&page=mira-event-guide' ) ); ?>"><?php esc_html_e( '📖 Shortcodes &amp; feature guide', 'mira-event-list' ); ?></a></p>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'mira_event_settings' );
                do_settings_sections( 'mira_event_settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

new MiraEventList();

// ── DB upgrade check (runs on every load, cheap after first pass) ────────
add_action( 'plugins_loaded', function() {
    if ( get_option( 'mira_event_list_db_version' ) !== MIRA_EVENT_LIST_VERSION ) {
        MiraDatabase::create_tables();

        // v2.6.1: webhook signing secret split into per-mode options. Carry a
        // valid legacy value over to the slot for the currently selected mode.
        $legacy = get_option( 'mira_stripe_webhook_secret', '' );
        if ( strpos( (string) $legacy, 'whsec_' ) === 0 ) {
            $target = get_option( 'mira_stripe_mode', 'test' ) === 'live'
                ? 'mira_stripe_live_webhook_secret'
                : 'mira_stripe_test_webhook_secret';
            if ( get_option( $target, '' ) === '' ) {
                update_option( $target, $legacy );
            }
        }

        // v2.8.0 added the /door-checkin/ rewrite rule. Rewrite rules aren't
        // registered yet this early (that happens on 'init'), so defer the
        // flush rather than calling it here — it would flush a rule set that
        // doesn't include the new one yet.
        update_option( 'mira_event_list_needs_rewrite_flush', 1 );

        update_option( 'mira_event_list_db_version', MIRA_EVENT_LIST_VERSION );
    }
} );

add_action( 'init', function() {
    if ( get_option( 'mira_event_list_needs_rewrite_flush' ) ) {
        flush_rewrite_rules();
        delete_option( 'mira_event_list_needs_rewrite_flush' );
    }
}, 20 );

// ── Activation / deactivation ────────────────────────────────────────────

register_activation_hook( __FILE__, 'mira_event_list_activation' );
function mira_event_list_activation() {
    $plugin = new MiraEventList();
    $plugin->create_event_post_type();
    flush_rewrite_rules();

    MiraDatabase::create_tables();
    MiraDatabase::create_success_page();
}

register_deactivation_hook( __FILE__, 'mira_event_list_deactivation' );
function mira_event_list_deactivation() {
    flush_rewrite_rules();
}
