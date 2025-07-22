# Mira Event List Plugin

A WordPress plugin to manage events with custom post type and display them via shortcode.

## Features

1. **Custom Post Type: Events**
   - Event name (using WordPress post title)
   - Event Logo (using WordPress Featured Image, displayed at 250px wide)
   - Event date (calendar picker)
   - Event link (URL field for external event links)

2. **Shortcode Display**
   - `[mira_event_list]` - Displays all future events in a responsive grid
   - 3-column grid on desktop, 2-column on tablet, 1-column on mobile
   - Events are automatically sorted with the next event first
   - "Goto Event" button aligned at the bottom of each card when event link is provided
   - Customizable button text and color via admin settings

## Installation

1. Upload the `mira-event-list` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. You'll see a new "Events" menu item in your WordPress admin

## Usage

### Creating Events

1. Go to **Events > Add New** in your WordPress admin
2. Enter the event name in the title field
3. Add event description in the content editor
4. Set the event date using the date picker in the Event Details meta box
5. Set the Featured Image for the event logo (will be displayed at 250px wide)
6. Add an event link URL (optional) - this will add a "Goto Event" button at the bottom of the card
7. Publish the event

### Plugin Settings

1. Go to **Events > Settings** in your WordPress admin
2. Customize the button text (default: "Goto Event")
3. Choose the button color using the color picker
4. Save changes

### Displaying Events

Use the shortcode `[mira_event_list]` in any post, page, or widget to display future events.

**Shortcode Parameters:**
- `limit` - Number of events to display (default: all events)

**Examples:**
```
[mira_event_list]           // Display all future events
[mira_event_list limit="5"] // Display next 5 events
```

## Styling

The plugin includes default CSS styling with a responsive grid layout. You can customize the appearance by adding CSS to your theme:

- `.mira-event-list` - Container for all events (CSS Grid)
- `.mira-event-item` - Individual event item
- `.event-logo` - Event logo container
- `.event-content` - Event content container
- `.event-date` - Event date
- `.event-excerpt` - Event excerpt
- `.goto-event-btn-bottom` - "Goto Event" button (bottom-aligned)
- `.event-goto-button-bottom` - Container for the bottom button

## Development Notes

**Version 1.0 - 22nd July 2025**

This plugin was developed to create an event list similar to: https://www.amg-world.co.uk/our-events/

Key functionality implemented:
1. ✅ Date order - next event first
2. ✅ Once an event date has passed, remove it from the list
3. ✅ Fields for each event: Event name, Event Logo, Event date, Link to more details
4. ✅ Front end display: Logo (250px wide x auto), clickable logos and buttons
5. ✅ Admin screens for editing CPT events
6. ✅ Shortcode `[mira_event_list]`
7. ✅ Responsive grid layout (3/2/1 columns)
8. ✅ Admin settings for button customization

## Requirements

- WordPress 4.0 or higher
- PHP 5.6 or higher

## Version

1.0.0

## License

GPL v2 or later
