<?php
/**
 * Plugin Name: Mira Event List
 * Plugin URI: https://github.com/dominicjjohnson/plugin.mira_event_list
 * Description: A WordPress plugin to manage events with custom post type and display them via shortcode.
 * Version: 1.0.0
 * Author: Miramedia / Dominic Johnson
 * Author URI: https://about.me/dominicjjohnson
 * License: GPL v2 or later
 * Text Domain: mira-event-list
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class MiraEventList {
    
    public function __construct() {
        add_action('init', array($this, 'create_event_post_type'));
        add_action('init', array($this, 'register_shortcode'));
        add_action('add_meta_boxes', array($this, 'add_event_meta_boxes'));
        add_action('save_post', array($this, 'save_event_meta'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        
        // Add image size for event logos
        add_action('after_setup_theme', array($this, 'add_image_sizes'));
    }
    
    /**
     * Create the Events custom post type
     */
    public function create_event_post_type() {
        $labels = array(
            'name'               => _x('Events', 'post type general name', 'mira-event-list'),
            'singular_name'      => _x('Event', 'post type singular name', 'mira-event-list'),
            'menu_name'          => _x('Events', 'admin menu', 'mira-event-list'),
            'name_admin_bar'     => _x('Event', 'add new on admin bar', 'mira-event-list'),
            'add_new'            => _x('Add New', 'event', 'mira-event-list'),
            'add_new_item'       => __('Add New Event', 'mira-event-list'),
            'new_item'           => __('New Event', 'mira-event-list'),
            'edit_item'          => __('Edit Event', 'mira-event-list'),
            'view_item'          => __('View Event', 'mira-event-list'),
            'all_items'          => __('All Events', 'mira-event-list'),
            'search_items'       => __('Search Events', 'mira-event-list'),
            'parent_item_colon'  => __('Parent Events:', 'mira-event-list'),
            'not_found'          => __('No events found.', 'mira-event-list'),
            'not_found_in_trash' => __('No events found in Trash.', 'mira-event-list')
        );

        $args = array(
            'labels'             => $labels,
            'description'        => __('Description.', 'mira-event-list'),
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'events'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-calendar-alt',
            'supports'           => array('title', 'editor', 'thumbnail', 'excerpt')
        );

        register_post_type('mira_event', $args);
    }
    
    /**
     * Add custom image size for event logos
     */
    public function add_image_sizes() {
        add_image_size('event-logo', 250, 0, false); // 250px wide, auto height
    }
    
    /**
     * Add meta boxes for event fields
     */
    public function add_event_meta_boxes() {
        add_meta_box(
            'event-details',
            __('Event Details', 'mira-event-list'),
            array($this, 'event_meta_box_callback'),
            'mira_event',
            'normal',
            'high'
        );
    }
    
    /**
     * Meta box callback function
     */
    public function event_meta_box_callback($post) {
        // Add nonce for security
        wp_nonce_field(basename(__FILE__), 'event_nonce');
        
        // Get saved values
        $event_date = get_post_meta($post->ID, '_event_date', true);
        $event_link = get_post_meta($post->ID, '_event_link', true);
        
        ?>
        <table class="form-table">
            <tr>
                <th><label for="event_date"><?php _e('Event Date', 'mira-event-list'); ?></label></th>
                <td>
                    <input type="date" id="event_date" name="event_date" value="<?php echo esc_attr($event_date); ?>" />
                    <p class="description"><?php _e('Select the event date', 'mira-event-list'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="event_link"><?php _e('Event Link', 'mira-event-list'); ?></label></th>
                <td>
                    <input type="url" id="event_link" name="event_link" value="<?php echo esc_attr($event_link); ?>" class="large-text" />
                    <p class="description"><?php _e('Enter the URL for the event (e.g., registration page, event website)', 'mira-event-list'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Event Logo', 'mira-event-list'); ?></th>
                <td>
                    <p class="description"><?php _e('Use the Featured Image for the event logo (will be displayed at 250px wide)', 'mira-event-list'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Save meta box data
     */
    public function save_event_meta($post_id) {
        // Verify nonce
        if (!isset($_POST['event_nonce']) || !wp_verify_nonce($_POST['event_nonce'], basename(__FILE__))) {
            return;
        }
        
        // Check if user can edit post
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Don't save on autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Save event date
        if (isset($_POST['event_date'])) {
            update_post_meta($post_id, '_event_date', sanitize_text_field($_POST['event_date']));
        }
        
        // Save event link
        if (isset($_POST['event_link'])) {
            update_post_meta($post_id, '_event_link', esc_url_raw($_POST['event_link']));
        }
    }
    
    /**
     * Enqueue scripts for frontend
     */
    public function enqueue_scripts() {
        // Use filemtime() for cache busting during development
        $css_file = plugin_dir_path(__FILE__) . 'assets/style.css';
        $version = file_exists($css_file) ? filemtime($css_file) : '1.0.0';
        
        wp_enqueue_style('mira-event-list-style', plugin_dir_url(__FILE__) . 'assets/style.css', array(), $version);
    }
    
    /**
     * Enqueue scripts for admin
     */
    public function admin_enqueue_scripts($hook) {
        // No longer need admin scripts since we're using featured image
    }
    
    /**
     * Register shortcode
     */
    public function register_shortcode() {
        add_shortcode('mira_event_list', array($this, 'event_list_shortcode'));
    }
    
    /**
     * Shortcode callback to display future events
     */
    public function event_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => -1,
        ), $atts, 'mira_event_list');
        
        // Get current date
        $current_date = date('Y-m-d');
        
        // Query for future events
        $args = array(
            'post_type' => 'mira_event',
            'post_status' => 'publish',
            'posts_per_page' => $atts['limit'],
            'meta_key' => '_event_date',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => array(
                array(
                    'key' => '_event_date',
                    'value' => $current_date,
                    'compare' => '>='
                )
            )
        );
        
        $events = new WP_Query($args);
        
        if (!$events->have_posts()) {
            return '<p>' . __('No upcoming events found.', 'mira-event-list') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="mira-event-list">
            <?php while ($events->have_posts()): $events->the_post(); ?>
                <?php
                $event_date = get_post_meta(get_the_ID(), '_event_date', true);
                $event_link = get_post_meta(get_the_ID(), '_event_link', true);
                $formatted_date = $event_date ? date('F j, Y', strtotime($event_date)) : '';
                ?>
                <div class="mira-event-item">
                    <?php if (has_post_thumbnail()): ?>
                        <div class="event-logo">
                            <?php if ($event_link): ?>
                                <?php
                                $open_new_window = get_option('mira_event_open_new_window', '1');
                                $target_attr = $open_new_window ? 'target="_blank" rel="noopener"' : '';
                                ?>
                                <a href="<?php echo esc_url($event_link); ?>" <?php echo $target_attr; ?> class="event-logo-link">
                                    <?php the_post_thumbnail('event-logo', array('alt' => get_the_title())); ?>
                                </a>
                            <?php else: ?>
                                <?php the_post_thumbnail('event-logo', array('alt' => get_the_title())); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="event-content">
                        <?php if ($formatted_date): ?>
                            <div class="event-date">
                                <strong><?php _e('Date:', 'mira-event-list'); ?></strong> <?php echo esc_html($formatted_date); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (has_excerpt()): ?>
                            <div class="event-excerpt">
                                <?php the_excerpt(); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="event-goto-button-bottom">
                            <?php if ($event_link): ?>
                                <?php
                                $button_text = get_option('mira_event_button_text', 'Goto Event');
                                $button_color = get_option('mira_event_button_color', '#28a745');
                                $open_new_window = get_option('mira_event_open_new_window', '1');
                                $target_attr = $open_new_window ? 'target="_blank" rel="noopener"' : '';
                                ?>
                                <a href="<?php echo esc_url($event_link); ?>" 
                                   class="goto-event-btn-bottom" 
                                   <?php echo $target_attr; ?>
                                   style="background-color: <?php echo esc_attr($button_color); ?>;">
                                    <?php echo esc_html($button_text); ?>
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
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=mira_event',
            __('Event Settings', 'mira-event-list'),
            __('Settings', 'mira-event-list'),
            'manage_options',
            'mira-event-settings',
            array($this, 'options_page')
        );
    }
    
    /**
     * Initialize settings
     */
    public function settings_init() {
        register_setting('mira_event_settings', 'mira_event_button_text');
        register_setting('mira_event_settings', 'mira_event_button_color');
        register_setting('mira_event_settings', 'mira_event_open_new_window');
        
        add_settings_section(
            'mira_event_settings_section',
            __('Button Customization', 'mira-event-list'),
            array($this, 'settings_section_callback'),
            'mira_event_settings'
        );
        
        add_settings_field(
            'mira_event_button_text',
            __('Button Text', 'mira-event-list'),
            array($this, 'button_text_render'),
            'mira_event_settings',
            'mira_event_settings_section'
        );
        
        add_settings_field(
            'mira_event_button_color',
            __('Button Color', 'mira-event-list'),
            array($this, 'button_color_render'),
            'mira_event_settings',
            'mira_event_settings_section'
        );
        
        add_settings_field(
            'mira_event_open_new_window',
            __('Open in New Window', 'mira-event-list'),
            array($this, 'open_new_window_render'),
            'mira_event_settings',
            'mira_event_settings_section'
        );
    }
    
    /**
     * Button text field
     */
    public function button_text_render() {
        $value = get_option('mira_event_button_text', 'Goto Event');
        ?>
        <input type="text" name="mira_event_button_text" value="<?php echo esc_attr($value); ?>" />
        <p class="description"><?php _e('Text displayed on the event button', 'mira-event-list'); ?></p>
        <?php
    }
    
    /**
     * Button color field
     */
    public function button_color_render() {
        $value = get_option('mira_event_button_color', '#28a745');
        ?>
        <input type="color" name="mira_event_button_color" value="<?php echo esc_attr($value); ?>" />
        <p class="description"><?php _e('Background color for the event button', 'mira-event-list'); ?></p>
        <?php
    }
    
    /**
     * Open new window checkbox field
     */
    public function open_new_window_render() {
        $value = get_option('mira_event_open_new_window', '1'); // Default to checked (new window)
        ?>
        <input type="checkbox" name="mira_event_open_new_window" value="1" <?php checked($value, '1'); ?> />
        <p class="description"><?php _e('Check to open event links in a new window/tab. Uncheck to open in the same window.', 'mira-event-list'); ?></p>
        <?php
    }
    
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>' . __('Customize the appearance and text of the event buttons.', 'mira-event-list') . '</p>';
        echo '<p>' . __('You can use the shortcode [mira_event_list] to display the event list.', 'mira-event-list') . '</p>';
    }
    
    /**
     * Options page
     */
    public function options_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Mira Event List Settings', 'mira-event-list'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('mira_event_settings');
                do_settings_sections('mira_event_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

// Initialize the plugin
new MiraEventList();

// Activation hook to flush rewrite rules
register_activation_hook(__FILE__, 'mira_event_list_activation');
function mira_event_list_activation() {
    // Create the post type
    $plugin = new MiraEventList();
    $plugin->create_event_post_type();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'mira_event_list_deactivation');
function mira_event_list_deactivation() {
    flush_rewrite_rules();
}
