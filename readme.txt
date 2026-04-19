=== Modern Hotel Booking ===
Contributors: leslierad
Tags: room booking, availability calendar, vacation rental, guesthouse, reservation system
Requires at least: 6.6
Tested up to: 6.9
Stable tag: 2.3.5
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free room booking system for guesthouses, vacation rentals & boutique hotels. Direct bookings. Zero commissions. No setup fees.

== Description ==

**Modern Hotel Booking** is a powerful free **room booking plugin** and complete **accommodation reservation system** built for independent properties.

Whether you are managing a single **vacation rental** or a multi-room boutique hotel, our plugin gives you full control of your property.

Stop paying 15%+ commissions to OTAs like Airbnb or Booking.com. Secure **direct bookings** on your own WordPress website — commission-free, forever.

This versatile plugin is perfectly designed for:

* **Guesthouses & B&Bs** — simple, reliable per-night **guesthouse booking** logic.
* **Vacation Rentals & Cabins** — works seamlessly as a standalone property manager.
* **Boutique Hotels & Hostels** — manage unlimited rooms with a real-time **availability calendar**.

### 🚀 Key Features (Free — No Limits)

* **Unlimited Room Types** — complete control over capacity and pricing.
* **Real-Time Availability Calendar** — interactive visual status for all rooms.
* **Smart Booking Form** — AJAX-powered with instant price calculation.
* **Business Info & Communication** — Integrated WhatsApp chat, Company profiles, and business card displays.
* **Offline Payment Support** — Built-in support for Bank Transfers (IBAN/SWIFT) and Revolut payments with QR codes.
* **Automated Email Notifications** — customizable confirmations for guests and admins.
* **7 Native Gutenberg Blocks** — add booking forms, calendars, and business info to any page (Hotel: Booking Form, Room Calendar, Company Profile, etc.).
* **Mobile-First Design** — fully responsive forms and calendars.
* **Multilingual Ready** — WPML, Polylang and qTranslate-X compatible.
* **AI Concierge** — intelligent guest assistant that reads your hotel data.
* **Zero-Trace Privacy** — No tracking, no analytics, no data collection.

### 🏆 Pro Version

Need more automation for your **direct booking** business? Upgrade to **Modern Hotel Booking Pro** and get:

* **Online Payments** — Stripe and PayPal integration
* **iCal Two-Way Sync** — Connect your **availability calendar** with Airbnb, Booking.com and VRBO
* **Seasonal & Dynamic Pricing** — Custom weekend and holiday rates
* **Advanced Email Templates** — Fully customize guest communication
* **Deposits & Partial Payments** — Secure revenue upfront
* **Revenue Analytics Dashboard** — Track your business growth
* **Tax Calculations & Extras Pricing** — VAT, Sales tax, and add-on services
* **Actionable AI Actions** — automated bookings and cancellations via AI

All plans include updates and priority support. Cancel anytime — no forced auto-renewal.

[View Pro Features & Pricing](https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/) | [Privacy Policy](https://startmysuccess.com/privacy-policy/) | [GitHub](https://github.com/leslieradue-web/modern-hotel-booking-free)

== Installation ==

1. Go to Plugins > Add New in your WordPress admin.
2. Search for "Modern Hotel Booking".
3. Click Install Now and then Activate.

== Quick Start Guide ==

1. Go to **Hotel Booking > Room Types** — Create your room types (e.g., Double Room, Triple Room). Set capacity, base price, and all other options.
2. Go to **Hotel Booking > Rooms** — Add the actual rooms and assign them to a room type. You can set custom prices or availability status here.
3. (Optional) Go to **Hotel Booking > Pricing Rules** — Add seasonal pricing (fixed amount or percentage).
4. Create or choose a page for bookings and add one of these:
   - Gutenberg block: **Hotel: Booking Form**
   - Shortcode: `[mhbo_booking_form]` or `[modern_hotel_booking]`
   - Or use the widget
5. Go to **Hotel Booking > Settings** — **IMPORTANT**: Select your Booking Page from the dropdown and save. Configure any other options (emails, currency, etc.) and save again.
6. (Optional) For individual room pages, use the **Hotel: Room Calendar** block or shortcode `[mhbo_room_calendar room_id="1"]` (replace 1 with the actual room ID).
7. (New) Use the **Business Info** tab in settings to configure WhatsApp, Bank Details, and Revolut. Display them anywhere using blocks like **Hotel: Company Profile**, **Hotel: Chat on WhatsApp**, or the combined **Hotel: Business Contact Card**.

Once set up, guests can search and create bookings from your dedicated booking page or individual room pages. The system shows real-time availability and calculates prices instantly.

== Frequently Asked Questions ==

= Is this room booking plugin really free? =
Yes! The core reservation system, availability calendar, room types, and email notifications are 100% free with no limits on bookings or rooms.

= Can I use it for a guesthouse or beach house? =
Absolutely. Modern Hotel Booking works perfectly as an accommodation booking system for guesthouses, vacation rentals, beach houses, cabins, and small boutique hotels.

= Does it sync with Airbnb or Booking.com? =
The free version allows manual date blocking via the availability calendar. The Pro version adds full two-way iCal synchronization with all major OTAs to prevent double-bookings.

= Is it mobile friendly? =
Yes — the entire room booking engine and reservation forms are fully responsive and mobile-optimized.

= Does it support multiple properties? =
Yes. Create unlimited "Room Types" that act as separate properties (e.g., "Seaside Villa" and "Mountain Cabin").

= Where can I get the Pro version? =
Visit [StartMySuccess.com](https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/) to view all advanced features, pricing, and licensing options.

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

This plugin uses **WordPress Transients** to temporarily store guest session data (such as name, email, and phone number) for up to 2 hours. This data is used exclusively to personalize the conversation with the AI Concierge and to pre-fill booking forms for the guest's convenience. This data is never shared with third parties other than the configured AI provider during active chat turns.

If you choose to use the "Powered By" link (disabled by default), a backlink to our website will be displayed on the frontend booking forms. No tracking data is sent.

For more information, please see our [Privacy Policy](https://startmysuccess.com/privacy-policy/).

== External Services ==

This plugin integrates with the following external services to enhance your direct booking experience. All connections are optional and only active if configured by the site administrator:

* **WhatsApp (Communication)** - Facilitates direct communication between guests and owners via WhatsApp links (no automated data collection).
  * [Privacy Policy](https://www.whatsapp.com/legal/privacy-policy-eea)
* **Revolut (Payments)** - Facilitates peer-to-peer payments via Revolut.me links and QR codes.
  * [Privacy Policy](https://www.revolut.com/legal/privacy-policy/)
* **AI Concierge (Gemini / OpenAI)** - If enabled, the plugin sends guest messages and property data to Google (Gemini) or OpenAI to provide automated guest assistance. No data is stored on external servers by this plugin.
  * [Google Privacy](https://policies.google.com/privacy) | [OpenAI Privacy](https://openai.com/privacy/)

The **Pro version** (available separately) adds connections to:

* **Stripe & PayPal** (Payment Processing) - Securely processes credit card and account payments.
  * [Stripe Privacy](https://stripe.com/privacy) | [PayPal Privacy](https://www.paypal.com/webapps/mpp/ua/privacy-full)
* **iCal Synchronisation** - Connects to external calendars (Airbnb, Booking.com, Google) via public feeds. No data is sent to our servers.
* **StartMySuccess (Maintenance)** - Used for license verification and update checks in the Pro version.
  * [Developer Privacy](https://startmysuccess.com/privacy-policy/)

== Data & Privacy ==

This plugin uses WordPress Transients (temporary server-side storage) for the following purposes:

* **Rate Limiting**: Used to prevent API abuse by temporarily storing anonymized request counts per IP address (2-minute TTL).
* **AI Concierge Memory**: If the AI Concierge is active, guest conversation context is stored using transients to provide a coherent multi-turn experience. No PII is permanently stored.
* **Update Checks**: Pro version license status and update availability are cached to ensure optimal admin performance.

All data is stored locally on your WordPress server and is not shared with the developer, except for optional AI processing (Gemini/OpenAI) if explicitly enabled.

== Changelog ==

= 2.3.5 =
* **New AI Concierge Chatbot** (Free) — Guests can now chat with an intelligent AI receptionist. They can ask questions about your property, check availability, and make reservations directly through text or voice.
* **Invoicing System** (Free) — Generate beautiful invoices and either print them or email them to guests automatically.
* **Minimum / Maximum Nights Rules** — Set minimum and maximum stay requirements on both Room Types and individual Rooms.
* **Pro Updates:**
  * New multi-select pricing override tool — easily set custom prices by room type or for individual rooms.
  * Option to add compulsory items (e.g. cleaning fee).
  * Option to add a compulsory service fee (as a percentage or fixed amount of the total booking).

= 2.3.2 =
* NEW: AI Concierge — intelligent guest support integrated with hotel data.
* NEW: Business Card Gutenberg Block — combined WhatsApp/Bank/Contact info.

= 2.3.1 =
* New: Added interactive business blocks (WhatsApp, Banking, Revolut) for enhanced direct guest communication.
* Improved: Performance and styling refinements for the Booking Form and Room Calendar blocks.
* Improved: Complete localization parity for core and Pro features in 15 languages.

== Upgrade Notice ==
= 2.3.5 =
Major new features! Free version now includes an AI Concierge Chatbot, full invoicing, and min/max nights rules. Pro adds advanced pricing tools and compulsory fees. Highly recommended update.