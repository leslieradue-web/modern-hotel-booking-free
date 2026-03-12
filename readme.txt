=== Modern Hotel Booking ===
Contributors: leslieradue-web
Tags: hotel booking, reservation system, booking calendar, bnb, property management
Requires at least: 5.6
Tested up to: 6.9
Stable tag: 2.2.7.1
Requires PHP: 7.4
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The ultimate Hotel Booking & Reservation System for managing rooms, availability, and direct guest reservations.

== Description ==

Modern Hotel Booking is a high-performance Property Management System (PMS) designed for Hotels, Bed & Breakfasts, and Vacation Rentals. Manage room types, set pricing, and take direct bookings with zero commissions.

This plugin provides a robust booking engine that operates entirely within your WordPress environment.

=== Features (Included in this version) ===
* Complete Room Management: Create unlimited room types with capacity controls.
* Real-Time Availability Calendar: Interactive visual status for all rooms.
* Smart Booking Form: AJAX-powered form with instant price calculation.
* Native Gutenberg Blocks: Seamlessly add booking forms to any page.
* Automated Notifications: Customizable email confirmations for guests and admins.
* Multilingual Support: Ready for WPML, Polylang, and qTranslate-X.
* GDPR Compliant: Built-in tools for data privacy.
* Developer API: REST API endpoints for custom integrations.

== Pro Version Availability ==
Modern Hotel Booking Pro is available for users requiring advanced business automation, including Stripe/PayPal payment gateway integration, iCal channel management (Airbnb, Booking.com), and detailed revenue analytics. Please visit our website for details on the Pro edition.

== Installation ==
1. Go to Plugins > Add New in your WordPress admin.
2. Search for "Modern Hotel Booking".
3. Click Install Now and then Activate.

== Quick Start Guide ==

How to use the Modern Hotel Booking Plugin after installing

1. Go to **Hotel Booking > Room Types** — Create your room types (e.g., Double Room, Triple Room). Set capacity, base price, and all other options.
2. Go to **Hotel Booking > Rooms** — Add the actual rooms and assign them to a room type. You can set custom prices or availability status here.
3. (Optional) Go to **Hotel Booking > Pricing Rules** — Add seasonal pricing (fixed amount or percentage).
4. Create or choose a page for bookings and add one of these:
   - Gutenberg block: **Modern Hotel Booking** (or **Hotel Booking Form Preview**)
   - Shortcode: `[modern_hotel_booking]`
   - Or use the widget
5. Go to **Hotel Booking > Settings** — **IMPORTANT**: Select your Booking Page from the dropdown and save. Configure any other options (emails, currency, etc.) and save again.
6. (Optional) For individual room pages, use the **Room Availability Calendar** block or shortcode `[mhbo_room_calendar room_id="1"]` (replace 1 with the actual room ID).

How it works  
Once set up, guests can search and create bookings from your dedicated booking page or from individual room pages. The system shows real-time availability and calculates prices instantly.

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

* **Stripe** (Payment Processing) - Used for processing credit card payments for bookings.
  * [Terms of Service](https://stripe.com/terms)
  * [Privacy Policy](https://stripe.com/privacy)
* **iCal Synchronisation** - Connects to external calendars like Airbnb, Booking.com, and Google Calendar via their public iCal feeds. No data is sent to our servers.

Both services are optional and only active if configured in the Pro version.

== Screenshots ==

1. **Responsive Booking Form:** Clean interface with date selection and real-time availability.
2. **Admin Dashboard:** Overview of upcoming bookings and revenue.
3. **Room Management:** Easy setup for room types, capacity, and base pricing.
4. **Availability Calendar:** Interactive visual calendar for checking room status.
5. **Settings Panel:** Extensive configuration for emails, currencies, and rules.

== Changelog ==

= 2.2.7.1 =
* Updated: Version bump for release

= 2.2.7.0 =
* Fixed: Performance tab removed from Free version (was causing fatal error)
* Fixed: Removed Pro-only settings hooks from Free build
* Fixed: WordPress.DB PHPCS warnings - added NoCaching ignores for database queries using transients
* Fixed: WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare warnings
* Security: Full input sanitization and output escaping review completed

= 2.2.6.9 =
* Major release: Compliance audit completed to ensure full functionality and removal of restricted features per WordPress.org guidelines.
* Fixed: WordPress.org i18n linting errors - added translators comments for placeholder strings
* Fixed: Ordered placeholders in translation strings (%1$s, %2$s format)
* Fixed: WPML hooks with phpcs:ignore comments (third-party integration)
* Fixed: Output escaping in admin dashboard widget
* Fixed: Global variable prefixes in uninstall.php (WordPress security standards)
* Security: Enhanced escaping for all frontend and admin outputs

= 2.2.6.8 =
* Fixed: WordPress.org Guideline 5 (Trialware) compliance - completely removed all Pro-gated code from FREE version
* Fixed: Removed all $is_pro_active conditional checks from FREE build
* Fixed: Replaced Pro feature blocks with upsell notices
* Fixed: Various undefined variable warnings

= 2.2.6.7 =
* Fixed: Admin bookings calendar not loading (FullCalendar missing plugins)
* Updated: FullCalendar library to bundle version including dayGrid, timeGrid, interaction plugins
* Added: Credits section documenting third-party libraries

= 2.2.6.6 =
* Updates and improvements as per recent changes.

= 2.2.6.5 =
* Changes as per recent updates.

= 2.2.6.3 =
* Security: Full prefix refactoring from MHB to MHBO (4-character prefix per WP.org guidelines).
* Compliance: Changed powered_by_link default to OFF (requires user opt-in).
* Compliance: Enhanced External Services documentation per WP.org requirements.
* Compliance: Fully removed Stripe/PayPal API code from Free version (trialware compliance).
* Improvement: Added automated refactoring script for prefix changes.
* Improvement: Updated vendor libraries (Chart.js, FullCalendar).

= 2.2.6.2 =
* Fixes per WP.org review implemented.

= 2.2.6.1 =
* Fixed: Version consistency across all plugin files including block metadata.
* Fixed: Synchronized version numbers in block.json and block.asset.php files.
* Improved: Build process stability and version verification.

= 2.2.6.0 =
* Improved: Build process to exclude PHPStan baseline from public repository.
* Optimized: Clean distribution of development artifacts.

= 2.2.5.9 =
* Fixed: Automation error in release workflow related to deleted CI configuration.
* Optimized: Version consistency across all plugin components and documentation.

= 2.2.5.8 =
* Added: Automated PHPStan analysis for local development.
* Improved: Build process to preserve metadata files (.gitignore, .gitattributes) in public repository.
* Improved: CI/CD release workflow with explicit free version sync logic.
* Fixed: Strict type compliance for Level 10 static analysis.
* Optimized: Distignore list to exclude all development artifacts from official ZIP.

= 2.2.5.7 =
* Added: Fallback to English for empty translations in backend.
* Improved: Robustness of multi-language decoding for international locales.
* Improved: PHP 7.4-8.5 compatibility audit and fixes (explicit nullables, switch vs match).
* Verified: Full compatibility with WordPress 7.0 (beta).
* Security: Comprehensive audit of output escaping in Shortcode and Tax systems.
* Updated: Repository and Author links for consistency.
* Feature: Powered By link can now be disabled by all users.

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


== Upgrade Notice ==

= 2.2.6.0 =
Maintenance update to clean up development artifacts from the public repository and distribution packages.

