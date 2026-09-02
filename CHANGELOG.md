# Changelog

All notable changes to the Mira Event List plugin will be documented in this file.

## [2.3.0] - 2026-09-02

### Added
- Mailjet contact sync. Buyer and attendee email addresses from paid bookings are
  pushed to a Mailjet contact list as bookings complete.
  - Each contact is tagged with a per-event boolean contact property
    (`evt_<yyyymmdd>_<slug>` by default, editable per event in the Ticketing box).
    The property is created in Mailjet automatically on first use.
  - Buyer email syncs when payment is confirmed; attendee emails sync when the
    attendee details form is submitted.
  - New settings section **Events → Settings → Mailjet Sync**: enable toggle,
    API key, secret key, contact list ID, plus a live list of the account's
    contact lists with their numeric IDs.
  - **Bookings → Sync all to Mailjet**: chunked backfill of every existing paid
    and complete booking.
- Note: each event creates one Mailjet contact property; prune old ones in Mailjet
  periodically if you run many events.

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
