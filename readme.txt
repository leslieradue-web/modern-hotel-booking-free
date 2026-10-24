=== Modern Hotel Booking — Direct Booking Engine, Availability Calendar & Vacation Rental PMS ===
Contributors: leslierad
Donate link: https://modernhotelwp.com/
Tags: hotel booking, vacation rental, Booking Engine, bed and breakfast, accommodation
Requires at least: 6.6
Tested up to: 7.1
Stable tag: 3.0.0
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hotel booking plugin and availability calendar for vacation rentals, B&Bs and accommodations. Direct booking engine, PMS and guest portal.

== Description ==

**Stop losing 15%–25% of every stay to Airbnb, Booking.com and VRBO commissions.**

**Modern Hotel Booking** is a fast, self-hosted **hotel booking plugin, direct booking engine, availability calendar and property management system (PMS)** built for independent bed and breakfasts (B&Bs), vacation rentals, boutique hotels, guesthouses, resorts and cabin accommodations.

Engineered for **WordPress 7.1+** (with full support back to WP 6.6) on a **100% Zero-jQuery Vanilla JS and React architecture**, this **hotel reservation system** and **booking calendar** renders interactive availability calendars with an **~70% smaller initial HTML payload** than legacy accommodation booking plugins—so your pages stay fast, mobile-friendly (`>= 44px` WCAG 2.2 AA touch targets), and visually refined out of the box with its signature **Midnight Navy & Champagne** hospitality aesthetic or your active `theme.json` palette.

> *"The plug-in does everything needed for my B&B."* — **@georger1970** (5-Star WordPress.org Review)

### Why Independent Property Owners Switch to Modern Hotel Booking

* **Zero Commissions & 100% Direct Bookings** — Keep 100% of your room revenue with your own **direct booking engine**. Every hotel reservation, guest profile, and folio ledger transaction lives in dedicated, indexed custom database tables on your own WordPress server.
* **Complete Free Booking Flow (No Paid Gateway Required to Start)** — Accept direct bookings and room reservations immediately out of the box using **Pay on Arrival**, **Direct Bank Wire Transfer (IBAN/BIC/SWIFT)**, **Revolut Pay (Link & QR Code)**, or **WhatsApp Click-to-Chat** confirmations.
* **Blisteringly Fast Progressive Calendar (`0 KB` jQuery)** — Front-heavy progressive hydration renders the first 4 months immediately for instant page load and preloads up to 2 years in the background during idle browser time.
* **Passwordless Guest Self-Service Portal (`[mhbo_guest_portal]`)** — Guests open their booking via a 1-click cryptographic magic link (or Booking ID + Email lookup) to view their personalized stay countdown, live USALI billing ledger, and 1-tap printable travel voucher, or to cancel/modify dates without creating a WordPress account.
* **Built-In Email Delivery Health Inspector & 14 Templates** — Solves the #1 headache in WordPress booking plugins (confirmation emails landing in spam) with a live 4-tile diagnostic probe for SMTP transport, sender-domain alignment, SPF DNS records, and DMARC policies.
* **Native 2026 WordPress Architecture** — Built with 8 Gutenberg blocks, Headless React DataViews (`@wordpress/dataviews`), FullCalendar v7.1.3 operational views, and an optional 24/7 AI Concierge powered by the **WordPress 7.1 Abilities API** (`wp_register_ability`).

---

### Everything Included in the Free Edition

Every feature listed below is **100% included in the Free plugin on WordPress.org**—no booking caps, no trial timers, and no forced third-party account signup.

#### 1. Progressive Availability Calendar, Direct Booking Engine & Slide-Out Drawer
* **Unlimited Room Types & Physical Room Units** — Manage single vacation rentals, bed and breakfast (B&B) rooms, guesthouse suites, boutique hotel accommodations, or multi-unit room categories with automatic physical unit allocation and natural room-number sorting (`Room 1, Room 2 ... Room 10`).
* **Atomic Double-Booking Prevention** — Database-level hotel reservation locking (`GET_LOCK`) prevents race-condition overbookings when two visitors check out on your booking calendar for the same dates simultaneously.
* **Slide-Out Modal Checkout Drawer** — Guests select check-in and check-out dates on the progressive availability calendar, review the instant `"Continue to Book"` summary card and complete their direct booking inside a smooth slide-out modal drawer without page reloads.
* **Responsive 3-Column Accommodation Showcase** — Display guest rooms in a responsive card grid (`[mhbo_rooms]`) with amenity badges, guest capacity chips, photo galleries, and starting nightly rates.
* **Flexible Room Rates & Stay Rules** — Configure per-room custom nightly rate overrides, extra adult occupancy surcharges, global minimum and maximum stay nights, custom advance booking windows (14 days up to 2 years), and room maintenance status blocks.
* **Refined Hospitality Design & Dark Mode** — Ships with a polished **Midnight Navy (`#1a365d`) & Champagne (`#f2e2c4`)** default theme, automatic `theme.json` token inheritance, and `.mhbo-theme-dark` WCAG AAA high-contrast dark mode support.

#### 2. Commission-Free Direct & Offline Payment Gateways
* **Pay on Arrival (Cash or Card Terminal at Front Desk)** — Let guests reserve online and settle their balance when they check in.
* **Direct Bank Wire Transfer (IBAN / BIC / SWIFT)** — Turnkey banking configuration displaying your Account Holder, IBAN, BIC/SWIFT, and reference instructions with 1-click copy buttons at checkout and in confirmation emails.
* **Revolut Pay (Direct Link & Scannable QR Code)** — Accept low-fee instant payments via your `revolut.me` or merchant link and checkout QR code.
* **WhatsApp Click-to-Chat Reservations** — Let guests submit a reservation and open a pre-filled WhatsApp confirmation message directly with your property.

#### 3. Passwordless Guest Self-Service Portal (`[mhbo_guest_portal]`)
* **Instant Verified Lookup & 1-Click Magic Links** — Guests unlock their portal via an HMAC-signed magic link in their confirmation email or by entering their Booking ID + Email—greeting them by name with their VIP status badge and live stay countdown.
* **Itemized USALI Billing Ledger** — Displays a transparent breakdown of nightly accommodation charges, applicable taxes/fees, verified payments received, and remaining balance due.
* **Self-Service Cancellations & Modification Requests** — Guests can cancel unpaid bookings immediately in one click (automatically releasing room inventory) or submit a guarded cancellation/date-change request for paid stays.
* **1-Tap Printable Travel Voucher** — Dedicated `@media print` stylesheet formats a clean, ink-friendly confirmation voucher with property address, check-in/out times and reservation summary.

#### 4. Executive Command Center, FullCalendar Grid & 2-Column PMS Workstation
* **Executive Hotel Command Center** — Unified operational dashboard featuring a circular **Live Occupancy Pulse** gauge, **6-Box Front-Desk KPI Micro-Grid** (*Arrivals Today, Departures Today, In-House Guests, Action Needed, Available Tonight, Total Stays*), **8 Quick Operational Command Shortcut Cards**, and a native WP Dashboard summary widget.
* **Main Hotel Booking Calendar & Hover Popovers** — Powered by **FullCalendar v7.1.3**, the month-grid operational calendar displays multi-room booking bars, occupancy KPIs, and interactive **event hover popovers** showing guest stay details, folio totals, and a 1-click link directly to their Guest CRM profile.
* **2-Column Front-Desk Manual Reservation Workstation** — Enter walk-in and phone bookings in seconds: select a returning guest from the **CRM Quick-Fill dropdown** (with instant auto-fill and VIP/Caution ribbon alerts), pick dates on the inline Flatpickr calendar, watch real-time server-side USALI folio calculation, and record payment with **1-tap quick-tender buttons** (`Pay in Full`, `Deposit Only`, `Pay on Arrival`).
* **Verified Folio Payment Ledger** — Record partial or full front-desk payments (Cash, POS Terminal, Bank Wire, Check) with strict transaction-summed accounting—eliminating phantom deposit balances.
* **Granular Hospitality Staff Roles** — Built-in **Hotel Manager**, **Front Desk Staff**, and **Housekeeping Staff** WordPress roles enforce clear 3-tier capability boundaries so staff only access the screens they need.

#### 5. Guest CRM Directory & Slide-Out Profile Drawer
* **Headless React DataViews Guest Directory** — Search, filter, sort, and paginate guest profiles inside `wp-admin` using native WordPress DataViews (`@wordpress/dataviews`).
* **Slide-Out Guest Profile Drawer** — Click any guest row to smoothly slide out their profile drawer displaying **Lifetime Value (LTV)**, total bookings, VIP status badge, chronological stay history timeline, 1–5 star staff ratings, private management notes and high-visibility **Caution Flags** (`Do Not Rent` / incident warnings).
* **Zero-Overhead Opt-In Switch** — Run the full Guest CRM or disable it completely with a single toggle.

#### 6. Email Delivery Health Inspector & 14 Transactional Email Templates
* **Live 4-Tile Email & DNS Health Grid** — Automatically inspects whether WordPress is sending via **SMTP** or raw PHP `mail()`, verifies **sender-domain alignment**, and queries live **SPF** and **DMARC** DNS records to prevent booking emails from landing in spam.
* **1-Click Diagnostic Test Probe & Failure Capture** — Trigger a live test email probe directly from `Settings > Emails` and catch silent mailer errors with automatic `wp_mail_failed` diagnostic banners.
* **14 Core Transactional Email Templates & Admin BCC Audit Trail** — Manage 14 lifecycle notification templates with live placeholder insertion, theme-override support (`{your-theme}/modern-hotel-booking/emails/`), automatic deduplicated admin BCC financial archiving and multilingual translation for **Polylang, WPML, qTranslate-XT, and Bogo**.

#### 7. Optional 24/7 AI Hotel Concierge, WP 7.1 Abilities API & MCP Discovery
* **Glassmorphic Virtual Receptionist** — Optional floating or inline chat widget that answers visitor questions about live room availability, nightly pricing, check-in policies, amenities, and local tips using real-time database tools and interactive rate cards.
* **Bring Your Own Free API Key or WP 7.0+ AI Connector** — Connect **Google Gemini** (including free-tier Gemini keys from Google AI Studio), **OpenAI**, **Anthropic**, **OpenRouter**, or the native **WordPress 7.0+ AI Client Connector** (`30 requests/hour/IP` in Free).
* **10 Native WordPress 7.1 Abilities & Hybrid MCP Server** — Registers 10 schema-validated hospitality abilities (`mhbo/get-hotel-info`, `mhbo/check-availability`, `mhbo/get-room-details`, `mhbo/get-policies`, `mhbo/get-knowledge-base`, `mhbo/get-local-tips`, `mhbo/get-business-card`, `mhbo/create-booking-link`, `mhbo/get-price-breakdown`, `mhbo/recommend-rooms`), exposes a **Streamable HTTP Model Context Protocol (MCP) Server** (with official `WordPress/mcp-adapter` bridge), generates **`llms.txt` / `llms-full.txt` GEO manifests** and an **OpenAPI 3.1** specification, and includes browser-native Web Speech API bidirectional voice (Speech-to-Text microphone dictation input and Text-to-Speech spoken responses).

#### 8. 8 Native Gutenberg Blocks, 11 Shortcodes & 3 Elementor Widgets
* **8 Native Gutenberg Blocks** (Search `"Hotel"` in the Block Inserter): Booking Form (`mhbo/booking-form`), Room Availability Calendar (`mhbo/room-calendar`), Company Info (`mhbo/company-info`), WhatsApp Button (`mhbo/whatsapp-button`), Banking Details (`mhbo/banking-details`), Revolut Pay Details (`mhbo/revolut-details`), Hotel Business Card (`mhbo/business-card`), and AI Concierge Chat Widget (`mhbo/ai-chat-widget`).
* **11 Shortcodes**: `[mhbo_booking_form]` (alias `[modern_hotel_booking]`), `[mhbo_room_calendar]`, `[mhbo_rooms]`, `[mhbo_guest_portal]`, `[mhbo_ai_concierge]`, `[mhbo_company_info]`, `[mhbo_whatsapp]`, `[mhbo_banking_details]`, `[mhbo_revolut_details]`, `[mhbo_business_card]` and `[mhbo_payment_methods]`.
* **3 Native Elementor Widgets** + Classic Sidebar Search Widget.

---

### Who Is Modern Hotel Booking Built For?

* **Bed and Breakfasts (B&Bs) & Country Inns** — Replace paper diaries and 18% OTA commissions with an intuitive bed and breakfast booking calendar, Pay-on-Arrival or Bank Wire checkout, and reliable hotel reservation emails.
* **Vacation Rentals, Chalets & Cabin Hosts** — Rent whole vacation rental properties or individual cottages with per-unit rates, minimum-stay rules, WhatsApp or Revolut Pay checkout, and a self-service guest voucher portal.
* **Boutique Hotels & Guesthouses (5–50 Rooms)** — Run a complete boutique hotel and guesthouse front-desk command center with automatic physical unit allocation, walk-in PMS reservations, staff roles and a lifetime Guest CRM.
* **Serviced Apartments & Extended Stays** — Configure accommodation booking windows up to 2 years out with minimum/maximum stay controls and transparent USALI folio ledgers.
* **WordPress Agencies & Freelancers** — Deliver a fast, theme-native, GPL-licensed direct booking engine and hotel reservation system with template overrides, clean CSS variables and zero jQuery conflicts.

---

### Optional Commercial Editions (Starter & Pro)

The Free edition runs a complete direct booking engine website with offline, bank wire, Revolut, or WhatsApp payments indefinitely. Optional commercial editions at [modernhotelwp.com](https://modernhotelwp.com/) add:

* **Modern Hotel Booking Starter** — **Stripe Elements** (Cards, Apple Pay, Google Pay, 3DS2), **PayPal Smart Buttons**, **Braintree Drop-in**, **Dynamic PDF & HTML Tax Invoices**, **Multi-Mode Tax Engine (VAT Inclusive vs. Sales Tax/GST Exclusive & Split Lodging/Extras Rates)**, **Child Occupancy & Age-Tiered Pricing (ages 1–17)**, **Prevent Same-Day Turnover**, and **60 req/hr/IP** AI Concierge capacity with native voice dictation & spoken responses.
* **Modern Hotel Booking Pro** — Everything in Starter plus **Automated 2-Way iCal Sync** (**Airbnb, Booking.com, VRBO, Expedia & Google Calendar**), **Multi-Tier Deposits & Balance Payment Links**, **Fullscreen Front-Desk Tablet Kiosk & Live Tape Chart (Gantt)**, **Multi-Room Group Bookings & Split Folios**, **Housekeeping Manifests**, **Advanced Yield Rules (Seasonal Rate Schedules, Interactive FullCalendar Override Editor, Orphan-Gap Auto-Correction, Weekend Multipliers, Booking Extras, Promo Coupons)**, **Guest CRM Marketing & Review Automation**, **STR Revenue Analytics (ADR, RevPAR, TRevPAR)**, and **AI Concierge Pro** (17 abilities/MCP tools, Google Hotel Center XML feeds, 200 req/hr capacity, and Studio ElevenLabs neural voice synthesis).

---

### External Services & Privacy Disclosure

Modern Hotel Booking is self-hosted by default and **never transmits guest booking data to any external server unless you explicitly enable one of the optional integrations below**:

1. **Google Fonts Typography (`fonts.googleapis.com` / `fonts.gstatic.com` — Free, Starter, Pro)**: Loads *Inter* and *Outfit* typefaces for calendar and dashboard typography via standard browser font requests (no cookies or guest form data sent). [Google Fonts Privacy FAQ](https://developers.google.com/fonts/faq/privacy) | [Google Privacy Policy](https://policies.google.com/privacy).
2. **WhatsApp Click-to-Chat (Optional — Free, Starter, Pro)**: Redirects the guest's browser to `https://wa.me/` with a pre-filled reservation summary (check-in/out dates, room name, reference, guest name) only when enabled and clicked by the guest. [WhatsApp Terms](https://www.whatsapp.com/legal/terms-of-service) | [WhatsApp Privacy Policy](https://www.whatsapp.com/legal/privacy-policy).
3. **Revolut Pay Link / QR Code (Optional — Free, Starter, Pro)**: Redirects the guest to your configured `revolut.me` or Revolut Merchant URL upon checkout. [Revolut Terms](https://www.revolut.com/legal/terms/) | [Revolut Privacy Policy](https://www.revolut.com/legal/privacy/).
4. **AI Concierge LLM Providers (Optional — Free, Starter, Pro; Disabled by Default)**: Sends guest chat messages and availability/pricing context (plus booking lookup/draft parameters in Pro) when you configure your own API key or the WP 7.0+ AI Client Connector. Endpoints: Google Gemini (`generativelanguage.googleapis.com`), OpenAI (`api.openai.com`), Anthropic (`api.anthropic.com`), OpenRouter (`openrouter.ai`), and ElevenLabs TTS in Pro (`api.elevenlabs.io`). [Google API Terms](https://developers.google.com/terms) | [Google Privacy](https://policies.google.com/privacy) | [OpenAI Privacy](https://openai.com/policies/privacy-policy) | [Anthropic Privacy](https://www.anthropic.com/legal/privacy) | [OpenRouter Privacy](https://openrouter.ai/privacy) | [ElevenLabs Privacy](https://elevenlabs.io/privacy-policy).
5. **Online Payment Gateways: Stripe, PayPal & Braintree (Optional — Starter & Pro Only)**: Processes card and wallet payments in Starter/Pro via `api.stripe.com` / `js.stripe.com`, `api.paypal.com` / `www.paypal.com` and `api.braintreegateway.com` / `js.braintreegateway.com` (sending booking total, currency, reference, and billing details; card numbers are tokenized directly by gateway SDKs). [Stripe Privacy](https://stripe.com/privacy) | [PayPal Privacy](https://www.paypal.com/webapps/mpp/ua/privacy-full) | [Braintree Privacy](https://www.braintreepayments.com/legal/braintree-privacy-policy).
6. **External OTA iCal Synchronization Feeds (Optional — Pro Only)**: Sends HTTP GET requests to external `.ics` calendar URLs configured by the administrator (Airbnb, Booking.com, VRBO, Expedia) in Pro.
7. **Commercial License & Update Server (Starter & Pro Only)**: Sends license key, site URL and plugin version to `https://modernhotelwp.com/` (and legacy `https://startmysuccess.com/`) in Starter/Pro (never present in Free). [Modern Hotel WP Privacy Policy](https://modernhotelwp.com/privacy-policy/) | [StartMySuccess Privacy Policy](https://startmysuccess.com/privacy-policy/).

### Credits

Open-source JavaScript libraries bundled locally in the plugin package:
* **Flatpickr v4.6.13** — MIT License — [flatpickr.js.org](https://flatpickr.js.org)
* **FullCalendar v7.1.3** — MIT License — [fullcalendar.io](https://fullcalendar.io)
* **Chart.js v4.5.1** — MIT License — [chartjs.org](https://www.chartjs.org)

== Installation ==

### Go Live in 5 Minutes (Quick Start)

1. In your WordPress admin dashboard, go to **Plugins > Add New**, search for **Modern Hotel Booking**, and click **Install Now** then **Activate**.
2. **Set Your Hotel Timezone & Currency** — Open **Hotel Booking > Settings > General** to configure your property timezone, currency formatting, check-in/out times, global minimum/maximum stay limits, and advance booking window.
3. **Add Room Types & Physical Units** — Go to **Hotel Booking > Room Types** and **Rooms** to add your accommodation categories, adult capacities, base nightly rates, extra adult surcharges, amenity badges, and physical units.
4. **Enable Direct & Offline Payments** — Under **Hotel Booking > Settings > Business Info**, configure **Direct Bank Wire Transfer (IBAN/BIC/SWIFT)**, **Revolut Pay QR/Link**, or **WhatsApp** reservation requests alongside built-in **Pay on Arrival**.
5. **Publish Your Booking Page** — Insert any of the **8 native Gutenberg blocks** (search `"Hotel"` in the Block Inserter) or place `[mhbo_booking_form]`, `[mhbo_room_calendar]`, `[mhbo_rooms]` and `[mhbo_guest_portal]` onto your pages.
6. **Run Your Front Desk** — Track today's arrivals, departures, in-house stays, interactive FullCalendar popovers, walk-in reservations, and folio balances from your WordPress dashboard.

== Frequently Asked Questions ==

= Can I accept real hotel bookings and payments in the Free version without Stripe? =

Yes. The Free edition on WordPress.org is a complete, working reservation system with zero booking limits. Out of the box, you can accept unlimited reservations using **Pay on Arrival** (cash or card terminal at your front desk), **Direct Bank Wire Transfer (IBAN/BIC/SWIFT)**, **Revolut Pay** (payment link and scannable QR code), and **WhatsApp Click-to-Chat** confirmations. You can also log partial or full payments manually in the built-in Front-Desk Folio Ledger. Online card gateways (**Stripe, PayPal, and Braintree**) are only needed if you want automated online card processing in the **Starter** or **Pro** editions.

= What is the difference between the Free, Starter, and Pro editions? =

* **Free (WordPress.org)**: Unlimited room types and physical units, progressive Vanilla JS availability calendar, slide-out checkout drawer, responsive room showcase grid, per-room custom rates & extra adult surcharges, global min/max stay rules, offline & direct payment methods (Pay on Arrival, Bank Wire, Revolut, WhatsApp), Passwordless Guest Self-Service Portal (`[mhbo_guest_portal]`), Guest CRM Core with React DataViews & Slide-Out Profile Drawer, Executive Command Center, FullCalendar v7.1.3 operational calendar with hover popovers, 2-Column Manual Reservation PMS Workstation, Email Delivery Health Inspector & 14 transactional email templates, 3 hospitality staff roles, 8 Gutenberg blocks, 11 shortcodes, 3 Elementor widgets, and AI Concierge Core (10 WP 7.1 abilities, hybrid Streamable HTTP MCP Server, `llms.txt` / OpenAPI 3.1 discovery, 30 req/hr/IP).
* **Starter**: Adds **Stripe Elements** (Cards, Apple Pay, Google Pay, 3DS2), **PayPal Smart Buttons**, **Braintree Drop-in**, **Dynamic PDF & HTML Tax Invoices**, **Multi-Mode Tax Engine (VAT Inclusive vs. Sales Tax/GST Exclusive & Split Accommodation/Extras Tax Rates)**, **Child Occupancy & Age-Tiered Pricing (ages 1–17)**, **Prevent Same-Day Turnover** buffers, and **60 req/hr/IP** AI Concierge capacity.
* **Pro**: Adds **Automated 2-Way iCal Sync** (Airbnb, Booking.com, VRBO, Expedia, Google Calendar), **Multi-Tier Deposits & Scheduled Balance Links**, **Fullscreen Front-Desk Tablet Mode & Live Tape Chart (Gantt)**, **Multi-Room Group Bookings**, **Housekeeping Manifests**, **Advanced Yield Pricing (Seasonal Rate Schedules, Interactive FullCalendar Override Editor, Orphan-Gap Auto-Correction, Weekend Multipliers, Booking Extras, Promo Coupons)**, **Guest CRM Marketing & Review Automation**, **Executive Revenue Analytics (ADR, RevPAR, TRevPAR)** and **AI Concierge Pro** (17 abilities/MCP tools, Google Hotel Center XML feeds, Speech-to-Text dictation, and ElevenLabs voice).

= Can I sync my WordPress availability calendar with Airbnb, Booking.com, and VRBO? =

Automated two-way iCal calendar synchronization (importing and exporting `.ics` feeds every 30 minutes with RFC 5545 conflict detection and orphan cleanup) is available in **Modern Hotel Booking Pro**. In the Free and Starter editions, availability is managed directly on your WordPress site and via the Front-Desk Manual Reservation Workstation.

= Will an interactive availability calendar slow down my WordPress website? =

No. Modern Hotel Booking is engineered with **0 KB of jQuery** and uses **Front-Heavy Progressive Calendar Hydration**. Instead of dumping thousands of DOM nodes into your initial page HTML, the server renders only a lightweight 4-month availability window for instant First Contentful Paint (cutting initial HTML size by ~70%), then quietly hydrates the remaining 2-year booking window in the background when the browser is idle.

= How do guests view, print, or cancel their reservation online? =

Place the `[mhbo_guest_portal]` shortcode on any page and assign it in **Hotel Booking > Settings > General**. Guests can click the cryptographically signed **1-Click Magic Link** in their confirmation email or look up their stay by **Booking ID + Email** to view their personal greeting, VIP status badge, stay countdown, itemized USALI billing ledger, 1-tap printable travel voucher, instant cancellation button (for unpaid bookings), and stay-modification request form.

= Why aren't hotel booking confirmation emails landing in the guest's inbox? =

Shared WordPress hosting often blocks raw PHP `mail()` or fails domain authentication checks. Modern Hotel Booking includes a built-in **Email Delivery Health Inspector** under **Hotel Booking > Settings > Emails**. Its 4-tile live diagnostic grid inspects your active mailer transport (SMTP vs. `mail()`), sender-domain alignment, and live **SPF** and **DMARC** DNS records, paired with a **1-Click Diagnostic Test Probe** so you can verify inbox delivery before your first guest books.

= How does the AI Concierge work, and do I have to pay for it? =

The AI Concierge is completely optional and can be toggled on or off anytime. In the **Free** version, it connects directly using your own API key from **Google Gemini** (which offers a free API tier via Google AI Studio), **OpenAI**, **Anthropic**, **OpenRouter**, or the native **WordPress 7.0+ AI Client Connector**—answering visitor availability and pricing questions with interactive rate cards and zero monthly middleware fees.

= Does this plugin work with Gutenberg block themes, Elementor, and multilingual plugins? =

Yes. Search `"Hotel"` in the WordPress Block Inserter to access all **8 native Gutenberg blocks**, or use the **3 native Elementor widgets** and **11 shortcodes** (`[mhbo_booking_form]`, `[mhbo_room_calendar]`, `[mhbo_rooms]`, `[mhbo_guest_portal]` and more) across Block themes, Classic themes, Bricks, Divi, and Beaver Builder. For multilingual properties, it features native integration with **Polylang, WPML, qTranslate-XT, and Bogo**, plus a built-in String Translation table for room names and all 14 email templates.

= How does the plugin handle GDPR, cookie consent, and guest data privacy? =

All booking and guest data is stored locally in your WordPress database. The plugin integrates natively with WordPress Core's **Export Personal Data** and **Erase Personal Data** tools, enforces explicit GDPR checkout consent checkboxes and bridges directly with the **WP Consent API** and **Complianz** so browser `localStorage` is only accessed after appropriate consent is granted.

== Screenshots ==

1. **Interactive Availability Calendar & Slide-Out Booking Drawer (GIF)** — Front-end progressive Vanilla JS calendar (`0 KB` jQuery) with date selection, smooth scroll to the "Continue to Book" summary card, slide-out modal checkout drawer, Pay on Arrival selection, and instant confirmation.
2. **2-Column Front-Desk Manual Reservation PMS Workstation (GIF)** — Front-desk reservation entry selecting a returning VIP guest from the CRM Quick-Fill dropdown with instant auto-fill, real-time USALI folio calculation, and 1-tap quick tender.
3. **Passwordless Guest Self-Service Portal & Printable Travel Voucher (GIF)** — Instant verified lookup via Booking ID + Email (or 1-click magic link), unlocking a personalized greeting, VIP status badge, stay countdown, itemized USALI billing ledger and 1-tap printable travel voucher.
4. **Main Hotel Booking Calendar & FullCalendar Hover Popover (GIF)** — Month-grid operational calendar showing multi-room bookings, occupancy KPIs, and an interactive event hover popover displaying stay details, folio total and a 1-click Guest CRM profile link.
5. **Guest CRM Directory & Slide-Out Profile Drawer (GIF)** — Built on native WordPress React DataViews (`@wordpress/dataviews`); clicking any guest row smoothly slides out their profile drawer with Lifetime Value (LTV), stay history timeline, ratings and staff notes.
6. **Email Delivery Health Inspector & DNS Diagnostic Probe (GIF)** — 4-tile live diagnostic grid inspecting SMTP mailer transport, sender-domain alignment, SPF DNS records, and DMARC policies with a 1-click test email probe.
7. **24/7 AI Hotel Concierge with WordPress 7.1 Abilities API (GIF)** — Floating glassmorphic chat widget answering visitor availability and pricing questions in real time using live database tools and interactive room rate cards.
8. **Executive Hotel Command Center & Live Occupancy Pulse** — Unified operational dashboard with circular Occupancy Pulse gauge, 6-box front-desk KPI micro-grid, and 8 Quick Operational Command shortcut cards.
9. **Granular Hospitality Staff Roles & Permission Boundaries** — Built-in operational roles (`Hotel Manager`, `Front Desk Staff`, `Housekeeping Staff`) with a dedicated 3-column capability boundary overview.
10. **Accommodation Showcase & Responsive Room Rates Grid** — 3-column responsive guest room showcase (`[mhbo_rooms]`) with amenity badges, guest capacity chips, photo galleries and starting nightly rates.
11. **Commission-Free Direct & Offline Payment Gateways** — Turnkey offline payment configuration showing Direct Bank Wire Transfer settings (IBAN, BIC, SWIFT, Account Holder) alongside Revolut Pay QR and WhatsApp subtabs.
12. **Native Gutenberg Blocks & Block Themes Integration** — WordPress Block Inserter searched for "Hotel", displaying all 8 native Modern Hotel Booking blocks ready to drop into any page or template.
13. **Automated Transactional Email Templates Catalog & BCC Audit Trail** — 14 core transactional email notification templates with automatic deduplicated admin BCC audit archiving and full HTML/placeholder customization.

== Changelog ==

= 3.0.0 =
* **Added**: **Passwordless Guest Self-Service Portal (`[mhbo_guest_portal]`)** — 1-click cryptographic magic link authentication and verified Booking ID + Email lookup, personalized VIP greeting, stay countdown, live itemized USALI billing ledger, ink-friendly printable travel vouchers, instant self-service cancellations for unpaid reservations, and guarded stay modification requests.
* **Added**: **2-Column Front-Desk Manual Reservation PMS Workstation** — Integrated PMS desk with CRM Guest Quick-Fill autocomplete (VIP and Caution Flag ribbons), inline Flatpickr calendar, real-time server-side USALI folio recalculation, and 1-tap quick-tender payment buttons (`Pay in Full`, `Deposit Only`, `Pay on Arrival`).
* **Added**: **Guest CRM Core & Slide-Out Profile Drawer** — Headless WordPress React DataViews (`@wordpress/dataviews`) directory with sliding profile drawer displaying Lifetime Spend (LTV), chronological stay history, 1–5 star staff ratings, private management notes and caution alerts.
* **Added**: **Email Delivery Health Inspector & 14 Modular Email Templates** — Live 4-tile diagnostic inspector (SMTP vs. `mail()`, sender-domain alignment, SPF & DMARC DNS health) with 1-click test probe, deduplicated admin BCC financial archiving, and theme-overridable template partials (`EmailTemplate` service).
* **Added**: **Executive Hotel Command Center & WP Dashboard Widget** — Unified operational workstation featuring a circular Live Occupancy Pulse gauge, 6-box front-desk KPI micro-grid and 8 quick operational command cards.
* **Added**: **Granular Hospitality Staff Roles & Permission Boundaries** — Dedicated `Hotel Manager`, `Front Desk Staff`, and `Housekeeping Staff` roles enforcing clear 3-tier capability boundaries.
* **Added**: **100% 15-Locale Native Translation Parity** — Full native translations across 15 supported languages (5,719 strings each, zero missing) with compiled MO, L10N.PHP, and JSON files, plus quad-core multilingual compatibility (WPML, Polylang, qTranslate-XT, Bogo).
* **Improved**: **Front-Heavy Progressive Calendar Hydration** — Server renders an immediate 4-month availability window (~70% smaller initial HTML payload) and hydrates up to 24 months asynchronously during browser idle time (`requestIdleCallback`).
* **Improved**: **FullCalendar v7.1.3 & Interactive Hover Popovers** — Upgraded calendar engine with screen-reader table semantics, interactive stay details hover popovers and direct 1-click links to Guest CRM profiles.
* **Improved**: **3-Tier Product Architecture Alignment** — Structured package distribution across Free Core (WordPress.org), Starter (Stripe, PayPal, Braintree, PDF Tax Invoices, Multi-Mode Tax, Child Age Pricing), and Pro (Fullscreen Live Tablet Mode with Tape Chart Gantt, 2-Way iCal Sync, Housekeeping Operations, and AI Concierge Pro).
* **Improved**: **Enterprise WordPress 7.1+ & PHP 8.2–8.4 Architecture** — 100% Zero-jQuery Vanilla JS and React design, PHPStan Level 8 baseline-free certification across all 166 files, prepared dynamic SQL statements and native WordPress 7.1 Abilities API integration.

For the full historical changelog of earlier versions, see [changelog.txt](https://plugins.svn.wordpress.org/modern-hotel-booking/trunk/changelog.txt).

== Upgrade Notice ==

= 3.0.0 =
Major 3.0 release: Passwordless Guest Portal ([mhbo_guest_portal]), Email Delivery & SPF/DMARC Health Inspector, 2-Column Manual Reservation PMS, Guest CRM with React DataViews, 100% 15-locale translations, FullCalendar v7.1.3 and ~70% faster progressive calendar loading.
