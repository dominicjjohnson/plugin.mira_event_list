# Mira Event List Plugin

A WordPress plugin to manage events (custom post type), display them via shortcodes,
and sell tickets through Stripe with a full bookings back office.

There is an in-admin version of this guide at **Events → Guide**.

## Features

- **Events** custom post type — title, Featured Image (logo), event date, display
  date, location, external link, documents, FAQs.
- **Shortcodes** for an events grid, a single "next event" feature, and a dynamic
  list of upcoming events for menus/widgets.
- **Stripe ticketing** per event — price, optional donations, maximum capacity
  with "SOLD OUT" and "Only X tickets left" messaging.
- **Bookings** admin — list/detail views, statuses, per-event revenue summary,
  CSV export, resend tickets, and delete.
- **Manual / cash bookings** — add attendees by hand, record the payment method,
  and mark as paid + send tickets when the money arrives.
- **Dynamic "Tickets" menu** — a nav-menu item that fills itself with upcoming events.
- **Mailjet sync** — push buyer/attendee emails to a contact list, tagged per event.

## Shortcodes

| Shortcode | What it does | Attributes |
| --- | --- | --- |
| `[mira_event_list]` | Responsive grid of every upcoming event, soonest first. Each card shows a booking form, a "Goto Event" button, or "SOLD OUT" / "Only X tickets left". | `limit` (default: all) |
| `[mira_next_event]` | The single soonest upcoming event as a feature block: banner, title, booking form. | — |
| `[mira_ticket_menu]` | A plain `<ul>` of upcoming events (title + date) linking to each event page. For sidebars, blocks, footers, or menu plugins that run shortcodes. | `limit` (6), `tickets_only` (0), `show_date` (1), `past` (0), `class` (""), `empty` ("No upcoming events") |
| `[mira_booking_success]` | Post-payment page: confirms payment, collects attendee name/email, emails tickets. Added automatically to the "Booking Complete" page on activation. | — |

**Examples**

```
[mira_event_list]
[mira_event_list limit="6"]
[mira_next_event]
[mira_ticket_menu limit="8" tickets_only="1"]
[mira_ticket_menu show_date="0" class="my-footer-list" empty="Nothing on sale right now"]
```

## Sections

### Creating events

**Events → Add New**. Title = event name, Featured Image = logo (shown 250px wide),
set the **Event Date** in the Event Details box. An external **Event Link** adds a
"Goto Event" button on the grid card when ticketing is off.

### Ticketing (per event)

In the **Ticketing** meta box:

- **Enable Ticketing** — replaces the event-link button with a Stripe booking form.
- **Price per Ticket** — GBP; also pre-fills the manual-booking price.
- **Maximum Tickets** — capacity. "SOLD OUT" shows once *paid* tickets reach it;
  "Only X tickets left" shows once the remaining count (which includes *pending*
  orders) is within the final 25%. Blank/0 = unlimited. A live "Sold so far"
  readout is shown in the box.
- **Enable Donations** — optional donation field on the booking form.
- **Mailjet Tag** — per-event boolean contact property (auto-named if blank).

### Dynamic "Tickets" menu

1. **Appearance → Menus → Screen Options → tick "CSS Classes"**.
2. Add the class `mira-ticket-menu` to the parent menu item (or point a Custom
   Link at `#mira-tickets`).
3. Save. The item fills with the next upcoming events, soonest first.

Filters (use in your theme's `functions.php`):

```php
add_filter( 'mira_ticket_menu_count', fn() => 8 );            // events shown (default 6)
add_filter( 'mira_ticket_menu_show_date', '__return_false' );  // hide the date suffix
add_filter( 'mira_ticket_menu_tickets_only', '__return_true' ); // ticketed events only
```

### Bookings (Events → Bookings)

- **Statuses** — `pending` (payment not confirmed: abandoned checkout, or a manual
  booking awaiting payment), `paid` (paid, attendee details outstanding),
  `complete` (paid + attendee details collected).
- **Filters** — status, event, payment method.
- **Revenue Summary** — per-event bookings/tickets/revenue for paid + complete.
- **Row actions** — Resend tickets (CC'd to the site admin), Delete, and
  "Mark paid & send" for unpaid manual bookings.
- **Export CSV** — one row per attendee, respecting the current filters.
- **Sync all to Mailjet** — backfill (visible only when Mailjet Sync is on).

### Manual / cash bookings

**Bookings → Add Manual Booking**. Choose the event and payment method (cash /
card in person / bank transfer / free), add one row per attendee, and a note.

- "Payment received" **unticked** → saved as `pending`, no tickets sent. Later use
  **Mark as paid & send tickets** to complete it and email everyone who hasn't
  had a ticket yet.
- "Payment received" **ticked** → saved as `complete`; tickets are emailed
  immediately if "Email tickets now" is left on.
- Manual bookings feed event capacity — pending ones reduce "tickets left", paid
  ones count towards SOLD OUT.

### Settings (Events → Settings)

- **Button Customisation** — text/colours/new-tab for the grid's "Goto Event" button.
- **Stripe Payments** — Test/Live mode, secret keys, and a separate webhook
  signing secret per mode. Add the webhook endpoint
  `<site>/wp-json/mira/v1/stripe-webhook` in Stripe for the
  `checkout.session.completed` event; test and live are distinct endpoints with
  different `whsec_…` secrets, so paste each into its matching field. Keys and
  secrets are validated on save (wrong prefix → not saved, previous value kept).
- **Ticket Emails** — from name/address and subject (`{event_name}` placeholder).
- **Mailjet Sync** — enable toggle, API + secret keys, contact-list ID.

## Developer hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `mira_ticket_menu_count` | int | Events in the dynamic Tickets menu (default 6). |
| `mira_ticket_menu_show_date` | bool | Append the date to each Tickets-menu item (default true). |
| `mira_ticket_menu_tickets_only` | bool | Limit the Tickets menu to ticketed events (default false). |

`MiraBookings::event_capacity( $event_id )` returns the capacity snapshot
(`max`, `sold_paid`, `sold_all`, `remaining`, `sold_out`, `show_remaining`).

## Styling hooks

- `.mira-event-list` / `.mira-event-item` — grid and cards
- `.mira-booking-form` / `.mira-book-btn` — booking form
- `.mira-tickets-left` / `.mira-sold-out` — capacity messaging
- `ul.mira-ticket-menu` / `.mira-ticket-menu-item` / `.mira-ticket-menu-date` — `[mira_ticket_menu]`

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- A Stripe account (for ticketing)

## License

GPL v2 or later
