# Changelog

All notable changes to the Mira Event List plugin will be documented in this file.

## [2.9.0] - 2026-09-16

### Added
- **`/door-checkin/` page.** Secret-key-gated, no-WP-login attendee check-in for
  door staff on shared devices: pick an event, search attendees, tap to
  toggle checked-in state, with a live running count. Includes a "Mark Paid"
  action for cash-on-arrival bookings. Adds `checked_in_at` to
  `wp_mira_attendees`.
- **Booking-failure diagnostics.** `assets/booking.js` now distinguishes a
  real HTTP failure (e.g. a WAF block) from an app-level error, and beacons a
  log line to a new `mira_log_client_error` AJAX action on any failure — this
  fires even when the main booking call itself is blocked before reaching
  this plugin's code, which is the case it exists to catch.
- **Emergency backup Stripe Payment Link.** Shown in the booking form's error
  message only if the normal AJAX booking call fails, so a customer can still
  pay while an underlying issue (e.g. a hosting-side block) is chased down.
  Created via the Stripe API on first view of each event and cached as post
  meta (`_stripe_payment_link_url`), so it's automatic per event with no
  manual Stripe dashboard work. Payments made this way are reconciled
  manually afterward via Bookings → Add Manual Booking.

## [2.7.0] - 2026-09-10

### Added
- **`[mira_next_event_rotator]` shortcode.** A split "next event" block: a poster
  that auto-rotates through every upcoming event's featured image (each links to
  its event, with clickable dots, pause-on-hover and `prefers-reduced-motion`
  respected), beside a booking widget fixed on the soonest event. Built for the
  V3 homepage hero area.
- **`[mira_events_grid]` shortcode.** Card grid of every upcoming event —
  banner, date kicker, title, venue and a per-card inline booking form. Reuses
  the existing `.mira-booking-form` markup and `assets/booking.js`, so multiple
  forms on one page and the Stripe redirect flow work unchanged. Optional
  `limit` attribute.

### Changed
- Booking-form markup extracted into a shared `render_booking_form()` helper
  (with an optional `show_note` flag) and a shared `upcoming_events_query()`.
  The single-event page and `[mira_next_event]` still render their own copies —
  no behavioural change to existing shortcodes.

## [2.6.1] - 2026-09-08

### Fixed
- **Stripe webhook signature verification.** The single `mira_stripe_webhook_secret`
  option was prone to being overwritten by browser password-manager autofill (an
  email address landing in the field), which made every live webhook fail with
  `400 Invalid signature`. Payment capture is unaffected — Stripe still charges
  the card, and the success page already confirms payment directly with Stripe —
  but the webhook is what marks bookings paid server-side and syncs the buyer to
  Mailjet without the customer returning to the site.

### Changed
- **Separate webhook signing secrets for test and live mode**
  (`mira_stripe_test_webhook_secret` / `mira_stripe_live_webhook_secret`), matching
  how Stripe issues one `whsec_…` per endpoint. A valid legacy value is migrated
  into the slot for the current mode on upgrade; the old option is still read as a
  fallback.
- **Stripe credential fields hardened against autofill** — rendered as plain text
  with `autocomplete="off"` and password-manager ignore hints, and validated on
  save: a value with the wrong prefix (`sk_`/`rk_` for keys, `whsec_` for webhook
  secrets) is rejected with an admin notice and the previous value is kept.

## [2.6.0] - 2026-09-08

### Added
- **Events → Guide**: an in-admin reference page covering every shortcode
  (`[mira_event_list]`, `[mira_next_event]`, `[mira_ticket_menu]`,
  `[mira_booking_success]`), the per-event ticketing options, the dynamic
  Tickets menu, the Bookings screen, manual/cash bookings, the settings
  sections, and developer hooks. README.md updated to match.
- **Dynamic "Tickets" menu**. The dropdown of recent events no longer has to be
  maintained by hand.
  - Add the CSS class `mira-ticket-menu` to the parent menu item under
    Appearance → Menus (or point a Custom Link at `#mira-tickets`), and the
    plugin fills it with the next few upcoming events, soonest first.
  - Tune it with filters: `mira_ticket_menu_count` (default 6),
    `mira_ticket_menu_show_date` (default true),
    `mira_ticket_menu_tickets_only` (default false).
  - New shortcode `[mira_ticket_menu]` renders the same list as a plain `<ul>`
    for widgets, blocks, or page content — attributes: `limit` (6),
    `tickets_only` (0), `show_date` (1), `past` (0), `class`, `empty`.
- **Maximum tickets per event** (Ticketing meta box → "Maximum Tickets"). Leave
  blank / 0 for unlimited.
  - **"Only X tickets left"** shows on the booking form once the remaining count
    falls within the final 25% of the maximum. This count includes pending
    (unpaid) orders, so it reflects tickets currently held, not just sold.
  - **"SOLD OUT"** replaces the booking form (on the single event page,
    `[mira_next_event]`, and the `[mira_event_list]` grid) once the number of
    **paid** tickets reaches the maximum. Pending orders do not trigger sold-out.
  - The ticket-quantity selector is capped to the number still available, and
    the checkout endpoint rejects any order that would oversell paid tickets.
  - The Ticketing meta box shows a live "Sold so far: N paid, M including
    pending (of MAX)" readout.
- **Bookings → Add Manual Booking**: create a booking by hand for people who pay
  cash, by card in person, by bank transfer, or who come in free / complimentary.
  - Pick the event, set the ticket price (pre-filled from the event, editable),
    add one row per attendee (name + email), and choose a payment method.
  - **Payment received** unticked → the booking is saved as **pending** and no
    tickets go out. When the money arrives, **Mark as paid & send tickets** (on
    the booking and in the list) flips it to paid and emails every attendee who
    hasn't had a ticket yet.
  - **Payment received** ticked → the booking is saved as `complete` and, if
    "Email tickets now" is left on, each attendee is emailed their ticket
    straight away (optionally CC'd to the site admin).
  - Records a free-text note (e.g. "paid cash on the door, collected by …").
  - The existing **Resend** action works on completed manual bookings, and paid
    manual bookings count toward the revenue summary.
  - Buyer/attendee emails sync to Mailjet when Mailjet Sync is enabled (on
    payment), exactly like Stripe bookings.
- **Bookings list**: new "Method" column (with an "UNPAID" flag and a
  "Mark paid & send" action for manual bookings awaiting payment) and a
  payment-method filter.
- **Booking detail**: payment method, note, a "Mark as paid & send tickets"
  button for unpaid manual bookings, and a "mark unpaid" toggle.
- **CSV export**: new Payment Method, Payment Received, and Note columns.

## [2.5.0] - 2026-09-03

### Added
- **Bookings → Resend**: each completed booking now has a "Resend" link (in the
  bookings list and on the booking detail page) that re-sends the ticket email to
  every attendee on that booking. A copy of each ticket is CC'd to the site admin
  address (Settings → General → Administration Email Address). Each attendee's
  "Ticket sent" timestamp is updated on a successful resend.

## [2.4.0] - 2026-09-03

### Added
- Talks can now be linked to an event from the "People, Charities & Talks" meta
  box (requires the TEDx Event Manager plugin). Linked talks with a YouTube
  link are embedded on the single event page, below the content and above the
  ticket booking form.

### Changed
- Event logos/titles in `[mira_event_list]` now always link to the event's own
  page on this site, instead of only linking out when an external Event Link
  was set and ticketing was disabled. The bottom "Goto Event" button/booking
  form behavior is unchanged.

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
