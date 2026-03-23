=== Modern Hotel Booking ===
Contributors: leslierad
Tags: vacation rental, booking engine, room booking, direct booking, reservation system
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 2.2.7.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress hotel and accommodation booking system. Take direct bookings for your guesthouse or small hotel, 100% commission-free.

== Description ==

**Modern Hotel Booking** is a powerful free **reservation system** and **booking engine** built specifically for independent properties. Whether you are managing a single **vacation rental** or a multi-room boutique hotel, our plugin gives you full control.

Stop paying 15%+ commissions to OTAs like Airbnb or Booking.com. Secure **direct bookings** on your own WordPress website using our flexible, commission-free **room booking** platform.

This versatile **booking engine** is perfectly designed for:

* **Vacation Rentals & Cabins** — works seamlessly as a standalone property manager for your **vacation rental**.
* **Guesthouses & B&Bs** — simple, reliable per-night **room booking** logic.
* **Boutique Hotels & Hostels** — interactive front-desk **reservation system** to manage unlimited properties effortlessly.

### Key Features (Free — No Limits)

* **Unlimited Room Types** with capacity controls and pricing.
* **Real-Time Availability Calendar** — interactive visual status for all rooms in your **reservation system**.
* **Smart Booking Engine Form** — AJAX-powered with instant price calculation for seamless **direct bookings**.
* **Automated Email Notifications** — customizable confirmations for guests and admins.
* **Native Gutenberg Blocks** — add **room booking** forms to any page seamlessly.
* **Mobile-First Design** — fully responsive forms and calendars.
* **Multilingual Ready** — WPML, Polylang and qTranslate-X compatible.

== Pro Version ==
Need more power for your direct booking business? Upgrade to Pro and get:

* Online Payments (Stripe, PayPal)
* iCal Two-Way Sync with Airbnb, Booking.com and VRBO
* Seasonal & Dynamic Pricing
* Advanced Email Templates
* Revenue Analytics Dashboard
* GDPR Compliant tools
* Developer REST API

**Pricing** (simple & transparent):
- First year (introductory): Personal $89 | Business (5 Licenses) $249 | Agency (25 Licenses) $749
- Renewal every year after (same low price for all tiers): **just $49/year**

All plans include updates and priority support during the active license period. Cancel anytime — no forced auto-renewal.

Visit the [Pro Version page](https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/) to purchase.

== Installation ==

1. Go to Plugins > Add New in your WordPress admin.
2. Search for "Modern Hotel Booking".
3. Click Install Now and then Activate.

== Quick Start Guide ==

1. Go to **Hotel Booking > Room Types** — Create your room types (e.g., Double Room, Triple Room). Set capacity, base price, and all other options.
2. Go to **Hotel Booking > Rooms** — Add the actual rooms and assign them to a room type. You can set custom prices or availability status here.
3. (Optional) Go to **Hotel Booking > Pricing Rules** — Add seasonal pricing (fixed amount or percentage).
4. Create or choose a page for bookings and add one of these:
   - Gutenberg block: **Modern Hotel Booking** (or **Hotel Booking Form Preview**)
   - Shortcode: `[mhbo_booking_form]` (recommended) or `[modern_hotel_booking]`
   - Or use the widget
5. Go to **Hotel Booking > Settings** — **IMPORTANT**: Select your Booking Page from the dropdown and save. Configure any other options (emails, currency, etc.) and save again.
6. (Optional) For individual room pages, use the **Room Availability Calendar** block or shortcode `[mhbo_room_calendar room_id="1"]` (replace 1 with the actual room ID).

Once set up, guests can search and create bookings from your dedicated booking page or from individual room pages. The system shows real-time availability and calculates prices instantly.

== Frequently Asked Questions ==

= Is this room booking plugin really free? =
Yes! The core reservation system, availability calendar, room types, and email notifications are 100% free with no limits on direct bookings or rooms.

= Can I use it for a guesthouse or beach house? =
Absolutely. Modern Hotel Booking works perfectly for single properties, vacation rentals, beach houses, cabins, guesthouses, and small boutique hotels.

= Does it sync with Airbnb or Booking.com? =
The free version allows manual date blocking. The Pro version adds full two-way iCal synchronization with all major OTAs.

= Is it mobile friendly? =
Yes — the entire booking engine and reservation forms are fully responsive and mobile-optimized.

= Does it support multiple properties? =
Yes. Create unlimited "Room Types" that can act as separate properties (e.g., "Seaside Villa" and "Mountain Cabin").

= Where can I get the Pro version? =
Visit [https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/](https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/).

Pricing is simple:
• First year introductory price: $89 (1 site), $249 (5 sites) or $749 (25 sites)
• After year 1: renew at just **$49/year** (same price for all tiers) for continued updates, features and support.

You can keep using the version you purchased indefinitely without renewing, but you will stop receiving updates and new features.

== Screenshots ==

1. **Responsive Booking Form** — Clean interface with date selection and real-time availability.
2. **Admin Dashboard** — Overview of upcoming bookings and revenue.
3. **Room Management** — Easy setup for room types, capacity, and base pricing.
4. **Availability Calendar** — Interactive visual booking calendar for checking room status.
5. **Settings Panel** — Extensive configuration for emails, currencies, and rules.

== Credits ==

This plugin uses the following third-party libraries:

* **FullCalendar** - Copyright (c) 2024-2025 Adam Shaw
  * Version: 6.1.20
  * License: MIT
  * License File: docs/FullCalendar-LICENSE.md
  * Source: https://fullcalendar.io
  * Used for: Admin bookings calendar display

* **Chart.js** - Copyright (c) 2014-2024 Chart.js Contributors
  * Version: 4.5.1
  * License: MIT
  * Source: https://www.chartjs.org
  * Used for: Revenue analytics charts

* **Flatpickr** - Copyright (c) 2023 Gregory Petrosyan
  * Version: 4.6.13
  * License: MIT
  * Source: https://flatpickr.js.org
  * Used for: Date and time picker in booking forms

All third-party libraries are bundled locally and are not loaded from external CDNs.

== Privacy Policy ==

Modern Hotel Booking is designed with privacy in mind. This plugin does not collect or transmit any guest or admin data to our servers. All booking details are stored locally in your WordPress database.

If you choose to use the "Powered By" link (disabled by default), a backlink to our website will be displayed on the frontend booking forms. No tracking data is sent.

For more information, please see our [Privacy Policy](https://startmysuccess.com/privacy-policy/).

== External Services ==

This plugin (Free version) works entirely offline within your WordPress installation.

The **Pro version** (available separately) integrates with the following external services:

* **Stripe** (Payment Processing) - Used for processing credit card payments for direct bookings.
  * [Terms of Service](https://stripe.com/terms)
  * [Privacy Policy](https://stripe.com/privacy)
* **PayPal** (Payment Processing) - Used for processing PayPal and credit card payments for direct bookings.
  * [Terms of Service](https://www.paypal.com/webapps/mpp/ua/useragreement-full)
  * [Privacy Policy](https://www.paypal.com/webapps/mpp/ua/privacy-full)
* **iCal Synchronisation** - Connects to external calendars like Airbnb, Booking.com, and Google Calendar via their public iCal feeds. No data is sent to our servers.

Both services are optional and only active if configured in the Pro version.

== Changelog ==

= 2.2.7.7 =
* Localization: Comprehensive multi-language update across all 15+ supported languages (FR, DE, ES, IT, CS, NL, PL, PT, RU, SV).
* Localization: Added 140+ critical hospitality and guest-facing translations (Batch 5).
* Improved: Unified room availability logic using core Pricing methods for improved reliability.
* Improved: Payment processing reliability issues when extras are added to bookings.
* Improved: Guest checkout workflow and order creation workflow.
* Enhanced: Improved build process cleanup (excluded composer.json and development scripts).

= 2.2.7.6 =
* Compliance: Updated WP-release build process, strict separation of Pro/Free markers.
* Fixed: Resolved NonceVerification, DirectDatabaseQuery, and PreparedSQL warnings.
* Fixed: System Status widget to strictly meet WordPress.org repository rules.
* Built: Free version verified clean against all repository compliance tests.

== Upgrade Notice ==

= 2.2.7.6 =
Compliance and performance update. Recommended for all users on WordPress 6.9.