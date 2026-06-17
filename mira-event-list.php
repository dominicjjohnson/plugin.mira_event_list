<?php
/**
 * Plugin Name: Mira Event List
 * Plugin URI: https://github.com/dominicjjohnson/plugin.mira_event_list
 * Description: A WordPress plugin to manage events with custom post type, shortcode display, and Stripe ticket purchasing.
 * Version: 2.2.0
 * Author: Miramedia / Dominic Johnson
 * Author URI: https://about.me/dominicjjohnson
 * License: GPL v2 or later
 * Text Domain: mira-event-list
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MIRA_EVENT_LIST_VERSION', '2.2.0' );
define( 'MIRA_EVENT_LIST_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MIRA_EVENT_LIST_URL',     plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'is_plugin_active' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-database.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-stripe.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-emails.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-bookings.php';
require_once MIRA_EVENT_LIST_PATH . 'includes/class-mira-admin-bookings.php';

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
        add_filter( 'manage_mira_event_posts_columns',       array( $this, 'event_admin_columns' ) );
        add_action( 'manage_mira_event_posts_custom_column', array( $this, 'event_admin_column_content' ), 10, 2 );

        new MiraBookings();
        new MiraAdminBookings();
    }

    public function register_meta_fields() {
        $string_fields = array(
            '_event_date', '_display_date', '_event_location', '_event_link',
            '_event_registration_url', '_event_registration_cta',
            '_event_documents', '_event_faqs',
            '_tickets_enabled', '_ticket_price', '_enable_donation',
        );
        foreach ( $string_fields as $key ) {
            register_post_meta( 'mira_event', $key, array(
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => '__return_true',
            ) );
        }
        foreach ( array( '_event_post_category', '_event_sponsor_type' ) as $key ) {
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
                __( 'People &amp; Charities', 'mira-event-list' ),
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
                <th><?php esc_html_e( 'Enable Donations', 'mira-event-list' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="enable_donation" value="1" <?php checked( $enable_donation, '1' ); ?>>
                        <?php esc_html_e( 'Allow buyers to add an optional donation at checkout', 'mira-event-list' ); ?>
                    </label>
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
        update_post_meta( $post_id, '_enable_donation', isset( $_POST['enable_donation'] ) ? '1' : '' );
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

        $companies = get_posts( array( 'post_type' => 'mmevmt_company', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ) );
        $persons   = get_posts( array( 'post_type' => 'mmevmt_person',  'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ) );
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

    private function render_event_extras( $post_id ) {
        $charities = json_decode( get_post_meta( $post_id, '_event_charities', true ) ?: '[]', true ) ?: array();
        $people    = json_decode( get_post_meta( $post_id, '_event_people',    true ) ?: '[]', true ) ?: array();

        $tickets_enabled   = get_post_meta( $post_id, '_tickets_enabled', true );
        $ticket_price      = floatval( get_post_meta( $post_id, '_ticket_price', true ) );
        $enable_donation   = get_post_meta( $post_id, '_enable_donation', true );
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

            <div class="mira-event-booking-section">
                <?php if ( $tickets_enabled ) :
                    $btn_label = '£' . number_format( $ticket_price, 2 ) . ' per ticket — Book Now';
                ?>
                    <form class="mira-booking-form"
                          data-event-id="<?php echo esc_attr( $post_id ); ?>"
                          data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                          data-nonce="<?php echo esc_attr( wp_create_nonce( 'mira_booking_nonce' ) ); ?>"
                          data-ticket-price="<?php echo esc_attr( $ticket_price ); ?>">
                        <div class="mira-booking-fields">
                            <div class="mira-qty-wrap">
                                <label><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></label>
                                <select name="quantity" class="mira-qty-select">
                                    <?php for ( $i = 1; $i <= 10; $i++ ) : ?>
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

            <?php if ( $tickets_enabled ) :
                $btn_label = '£' . number_format( $ticket_price, 2 ) . ' per ticket — Book Now';
            ?>
                <form class="mira-booking-form"
                      data-event-id="<?php echo esc_attr( $post_id ); ?>"
                      data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                      data-nonce="<?php echo esc_attr( wp_create_nonce( 'mira_booking_nonce' ) ); ?>"
                      data-ticket-price="<?php echo esc_attr( $ticket_price ); ?>">
                    <div class="mira-booking-fields">
                        <div class="mira-qty-wrap">
                            <label><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></label>
                            <select name="quantity" class="mira-qty-select">
                                <?php for ( $i = 1; $i <= 10; $i++ ) : ?>
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
        add_shortcode( 'mira_event_list',  array( $this, 'event_list_shortcode' ) );
        add_shortcode( 'mira_next_event',  array( $this, 'event_next_shortcode' ) );
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
                $formatted_date  = $event_date ? date( 'j F Y', strtotime( $event_date ) ) : '';
            ?>
                <div class="mira-event-item">

                    <?php if ( has_post_thumbnail() ) : ?>
                        <div class="event-logo">
                            <?php if ( $event_link && ! $tickets_enabled ) : ?>
                                <a href="<?php echo esc_url( $event_link ); ?>" <?php echo $target_attr; ?> class="event-logo-link">
                                    <?php the_post_thumbnail( 'event-logo', array( 'alt' => get_the_title() ) ); ?>
                                </a>
                            <?php else : ?>
                                <?php the_post_thumbnail( 'event-logo', array( 'alt' => get_the_title() ) ); ?>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <div class="event-title">
                            <?php if ( $event_link && ! $tickets_enabled ) : ?>
                                <a href="<?php echo esc_url( $event_link ); ?>" <?php echo $target_attr; ?> class="event-title-link">
                                    <?php the_title(); ?>
                                </a>
                            <?php else : ?>
                                <?php the_title(); ?>
                            <?php endif; ?>
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
                            <?php if ( $tickets_enabled ) :
                                $btn_label = '£' . number_format( $ticket_price, 2 ) . ' per ticket — Book Now';
                            ?>
                                <form class="mira-booking-form"
                                      data-event-id="<?php echo esc_attr( $post_id ); ?>"
                                      data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                                      data-nonce="<?php echo esc_attr( wp_create_nonce( 'mira_booking_nonce' ) ); ?>"
                                      data-ticket-price="<?php echo esc_attr( $ticket_price ); ?>">

                                    <div class="mira-booking-fields">
                                        <div class="mira-qty-wrap">
                                            <label for="mira-qty-<?php echo $post_id; ?>"><?php esc_html_e( 'Tickets', 'mira-event-list' ); ?></label>
                                            <select id="mira-qty-<?php echo $post_id; ?>" name="quantity" class="mira-qty-select">
                                                <?php for ( $i = 1; $i <= 10; $i++ ) : ?>
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
        register_setting( 'mira_event_settings', 'mira_stripe_mode',            array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'mira_event_settings', 'mira_stripe_test_secret',     array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'mira_event_settings', 'mira_stripe_live_secret',     array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'mira_event_settings', 'mira_stripe_webhook_secret',  array( 'sanitize_callback' => 'sanitize_text_field' ) );

        add_settings_section(
            'mira_stripe_section',
            __( 'Stripe Payments', 'mira-event-list' ),
            array( $this, 'stripe_section_callback' ),
            'mira_event_settings'
        );

        add_settings_field( 'mira_stripe_mode',           __( 'Mode', 'mira-event-list' ),            array( $this, 'stripe_mode_render' ),           'mira_event_settings', 'mira_stripe_section' );
        add_settings_field( 'mira_stripe_test_secret',    __( 'Test Secret Key', 'mira-event-list' ),  array( $this, 'stripe_test_secret_render' ),    'mira_event_settings', 'mira_stripe_section' );
        add_settings_field( 'mira_stripe_live_secret',    __( 'Live Secret Key', 'mira-event-list' ),  array( $this, 'stripe_live_secret_render' ),    'mira_event_settings', 'mira_stripe_section' );
        add_settings_field( 'mira_stripe_webhook_secret', __( 'Webhook Secret', 'mira-event-list' ),   array( $this, 'stripe_webhook_secret_render' ), 'mira_event_settings', 'mira_stripe_section' );

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
           <small><?php esc_html_e( 'Listen for the event: checkout.session.completed', 'mira-event-list' ); ?></small></p>
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

    public function stripe_test_secret_render() {
        $v = get_option( 'mira_stripe_test_secret', '' );
        echo '<input type="password" name="mira_stripe_test_secret" value="' . esc_attr( $v ) . '" class="regular-text" autocomplete="new-password">';
        echo '<p class="description">' . esc_html__( 'Starts with sk_test_', 'mira-event-list' ) . '</p>';
    }

    public function stripe_live_secret_render() {
        $v = get_option( 'mira_stripe_live_secret', '' );
        echo '<input type="password" name="mira_stripe_live_secret" value="' . esc_attr( $v ) . '" class="regular-text" autocomplete="new-password">';
        echo '<p class="description">' . esc_html__( 'Starts with sk_live_', 'mira-event-list' ) . '</p>';
    }

    public function stripe_webhook_secret_render() {
        $v = get_option( 'mira_stripe_webhook_secret', '' );
        echo '<input type="password" name="mira_stripe_webhook_secret" value="' . esc_attr( $v ) . '" class="regular-text" autocomplete="new-password">';
        echo '<p class="description">' . esc_html__( 'Signing secret from your Stripe webhook. Starts with whsec_', 'mira-event-list' ) . '</p>';
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

    // ── Options page ─────────────────────────────────────────────────────

    public function options_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Mira Event List Settings', 'mira-event-list' ); ?></h1>
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
        update_option( 'mira_event_list_db_version', MIRA_EVENT_LIST_VERSION );
    }
} );

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
