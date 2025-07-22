# Changelog

All notable changes to the Mira Event List plugin will be documented in this file.

## [1.0.0] - 2025-07-22

### Added
- Initial release of Mira Event List plugin
- Custom post type "Events" with the following fields:
  - Event name (post title)
  - Event date (calendar picker)
  - Event link (URL field)
  - Event logo (WordPress Featured Image, resized to 250px wide)
- Shortcode `[mira_event_list]` to display future events
- Responsive grid layout (3 columns on desktop, 2 on tablet, 1 on mobile)
- Admin settings panel for button customization:
  - Customizable button text
  - Customizable button color
- Clickable event logos (when event link is provided)
- Bottom-aligned "Goto Event" buttons
- Cache busting for CSS during development
- Events automatically sorted by date (earliest upcoming first)
- Only displays future events (past events are hidden)

### Features
- Clean, professional card-based design
- Fully responsive layout
- Hover effects on clickable elements
- WordPress coding standards compliant
- Translation ready
- Security best practices implemented
