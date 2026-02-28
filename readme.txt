Modern Hotel Booking
Contributors: leslieradue-web
Tags: hotel booking, reservation system, booking calendar, bnb, property management
Requires at least: 5.6
Tested up to: 6.9
Stable tag: 2.2.5.7
Requires PHP: 7.4
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The ultimate Hotel Booking & Reservation System. Manage rooms and reservations and handle availability.

== Description ==

**Modern Hotel Booking** is a comprehensive **WordPress Hotel Booking Plugin** designed for Hotels, Bed & Breakfasts (BnBs), Guest Houses, Apartments, and Vacation Rentals.

Unlike bloated reservation systems, Modern Hotel Booking offers a high-performance, mobile-responsive booking engine that lets you take direct bookings with **zero commissions**.

Whether you are building a site for a luxury hotel or a single vacation rental, this plugin provides a complete Property Management System (PMS) to handle room types, seasonal pricing, and guest management directly from your WordPress dashboard.

### 🚀 Why Choose Modern Hotel Booking?

*   **Boost Direct Bookings:** Professional booking forms that convert visitors into guests.
*   **Mobile Ready:** Fully responsive design works perfectly on all devices.
*   **Developer Friendly:** Built with a modern PHP architecture, REST API, and strict coding standards.
*   **Performance First:** Smart micro-caching ensures your site remains fast, even with complex availability checks.

### ✨ Key Features (Free)

*   **Complete Room Management:** Create unlimited room types (Deluxe, Standard, Hostel Beds) with capacity controls.
*   **Real-Time Availability Calendar:** Interactive calendar showing immediate room status.
*   **Smart Booking Form:** AJAX-powered form with date picker, guest count, and instant price calculation.
*   **Native Gutenberg Blocks:** Drag-and-drop the `Booking Form` and `Room Calendar` directly into your pages.
*   **Automated Notifications:** Send customizable confirmation emails to guests and admins.
*   **Multilingual Ready:** Full support for WPML, Polylang, and qTranslate-X. Perfect for international tourism.
*   **GDPR Compliant:** Built-in privacy tools for data export and erasure.
*   **Developer API:** Robust REST API endpoints for custom integrations and mobile apps.

### 🏆 Pro Features (Business Automation)

Take your hospitality business to the next level with advanced payment and synchronization tools.

**💳 Secure Payment Processing**
*   **Stripe Integration:** Accept Credit Cards, Apple Pay, and Google Pay.
*   **PayPal Standard:** Secure payments with full IPN verification.
*   **Pay on Arrival:** Offer flexibility for guests to pay at the hotel.
*   **Fraud Protection:** Advanced rate limiting and MySQL advisory locks to prevent double bookings.

**🔄 Channel Manager (iCal Sync)**
Stop double bookings! Automatically sync your availability with:
*   **Airbnb**
*   **Booking.com**
*   **Expedia**
*   **Google Calendar**
*   **VRBO** & HomeAway

**📈 Dynamic Pricing & Extras**
*   **Seasonal Rates:** Set higher prices for weekends, holidays, or peak seasons.
*   **Booking Add-ons:** Upsell breakfast, airport transfers, tours, and spa packages.
*   **Children Pricing:** Smart logic for age-specific pricing and room allocation.
*   **Advanced Tax System:** Configure VAT, Sales Tax, and city taxes with country-specific rules.

**📊 Analytics & Reporting**
*   Visual Dashboard with revenue charts.
*   Track Occupancy Rates and Average Daily Rate (ADR).
*   Export booking data for accounting.

== Installation ==

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress admin.
2. Search for **"Modern Hotel Booking"**.
3. Click **Install Now** and then **Activate**.

= Manual Installation =

1. Upload the `modern-hotel-booking` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. A new menu item **Hotel Booking** will appear in your sidebar.

= Quick Start Guide =

1. Go to **Hotel Booking > Room Types** to define your accommodation (e.g., "Sea View Suite").
2. Go to **Hotel Booking > Rooms** to add the actual rooms available.
3. Add the **Modern Hotel Booking** block to any page to display the reservation form.

== Frequently Asked Questions ==

= Can I use this for a single vacation rental or BnB? =
Yes! Modern Hotel Booking is perfect for single properties, villas, apartments, and Bed & Breakfasts. You simply create one "Room Type" and assign one "Room" to it.

= Does it support Elementor, Divi, or other page builders? =
Yes. While we have native Gutenberg blocks, you can use our shortcodes `[mhb_booking_form]` and `[mhb_calendar]` inside any page builder, including Elementor, Divi, Beaver Builder, and WPBakery.

= Can I sync availability with Airbnb and Booking.com? =
Yes. The Pro version includes a bi-directional iCal synchronization engine. You can import calendars from OTAs (Airbnb, Booking.com) to block dates on your site, and export your site's bookings back to them to prevent double bookings.

= Is the plugin translation ready? =
Absolutely. We support WPML, Polylang, and qTranslate-X. All frontend labels and email templates can be translated to serve international guests.

= How do I enable Apple Pay and Google Pay? =
This is a Pro feature available via the Stripe integration. Once you enable Stripe in the settings, Apple Pay and Google Pay buttons will appear automatically for supported devices.

= Is the booking form responsive? =
Yes, the booking form and availability calendars are fully responsive and optimized for mobile phones and tablets.

= Where is credit card data stored? =
Nowhere on your server. We use tokenized payments via Stripe and PayPal (Pro). This ensures you are PCI-compliant and guest data is secure.

== Screenshots ==

1. **Responsive Booking Form:** Clean interface with date selection and real-time availability.
2. **Admin Dashboard:** Overview of upcoming bookings and revenue.
3. **Room Management:** Easy setup for room types, capacity, and base pricing.
4. **Availability Calendar:** Interactive visual calendar for checking room status.
5. **Settings Panel:** Extensive configuration for emails, currencies, and rules.
6. **Payment Gateways (Pro):** Stripe and PayPal configuration with sandbox modes.
7. **Tax Settings (Pro):** Advanced VAT and Sales Tax management.
8. **Analytics (Pro):** Visual charts for occupancy and revenue tracking.
9. **Booking Extras (Pro):** Management of add-on services like breakfast.
10. **iCal Sync (Pro):** Synchronization manager for Airbnb and Booking.com.

== Changelog ==

= 2.2.5.6 =
* Added: Fallback to English for empty translations in backend.
* Improved: Robustness of multi-language decoding for international locales.
* Improved: PHP 7.4-8.5 compatibility audit and fixes (explicit nullables, switch vs match).
* Verified: Full compatibility with WordPress 7.0 (beta).
* Security: Comprehensive audit of output escaping in Shortcode and Tax systems.

= 2.2.5.5 =
* Updated: Author information.
* Fixed: Plugin header placement for strict_types compatibility.
* Improved: SEO metadata for directory ranking.

= 2.2.5.1 =
* Fixed: Critical "strict_types declaration" error by removing UTF-8 Byte Order Marks (BOM).
* Improved: Pro Dashboard refreshed for performance.
* Verified: Full PHP 8.x compatibility.

= 2.2.5.0 =
* Improved: Performance optimization with targeted object caching.
* Improved: Security hardening with input sanitization audits.
* Fixed: Repository standard compliance (index.php files, line endings).

= 2.2.4.9 =
* Fixed: License grace period logic.
* Verified: Pricing and tax logic accuracy.
* Improved: Frontend UI responsiveness.

= 2.2.4.8 =
* Fixed: Calendar cache invalidation issues.
* Fixed: Real-time price updates for children count.
* Fixed: Email placeholder replacements.

= 2.2.4.6 =
* Fixed: Pending booking status styling.
* Fixed: Mobile responsiveness for search button and date picker.

= 2.2.4.5 =
* Verified: Zero errors on WordPress Plugin Checker.
* Security: Comprehensive audit of sanitization and escaping.
* Added: Race condition protection via MySQL advisory locks.

= 2.2.4.1 =
* Fixed: Fatal error regarding missing Pro classes in Free version.

= 2.2.3 =
* Code: Added strict typing compliance and caching layer.
* Standards: Added index.php files for directory compliance.

= 2.2.2 =
* Feature: Enhanced VAT/TAX breakdown renderer.
* Security: HMAC-SHA256 signature verification for webhooks.

= 2.2.0 =
* Major Feature: Stripe & PayPal Integration.
* Major Feature: VAT/TAX System.
* Major Feature: Payment Webhooks and Status Tracking.

= 2.1.0 =
* Feature: Children pricing logic and email placeholders.
* Improved: iCal parsing robustness.

= 2.0.8 =
* Feature: Native Block Editor (Gutenberg) support.

= 2.0.0 =
* Rewrite: Complete architecture overhaul with PSR-4.
* Feature: Multilingual support and GDPR tools.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.2.5.6 =
Critical compatibility update for PHP 8.4/8.5 and WordPress 7.0. Includes major multilingual robustness fixes.

= 2.2.5.5 =
SEO and Header optimization. Please update to ensure proper directory listing and PHP compatibility.