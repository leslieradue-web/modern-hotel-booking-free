<?php
/**
 * Default Admin New Booking Notification Email Template.
 *
 * @package MHBO\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mhbo_guests_details_row = '<tr><td style="color: #64748b">Guests:</td><td style="text-align: right">{guests}</td></tr>';

?>
<div style="background-color: #f4f7f9;padding: 20px 10px;font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif">
    <div style="max-width: 650px;margin: 0 auto;background-color: #ffffff;border: 1px solid #e2e8f0;border-radius: 16px;overflow: visible">
        <div style="background: #2563eb; color: #ffffff; padding: 20px; border-radius: 15px 15px 0 0;">
            <h2 style="margin: 0; font-size: 20px; color: #ffffff;">New Booking Received &mdash; #{booking_id}</h2>
            <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">A new guest reservation has been created on {site_name}.</p>
        </div>
        <div style="padding: 30px">
            <h3 style="color: #0f172a; border-bottom: 2px solid #f0f4f8; padding-bottom: 8px; margin-top: 0; font-size: 16px;">Guest Information</h3>
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px; line-height: 1.8; color: #334155; margin-bottom: 20px;">
                <tr><td style="color: #64748b; width: 35%;">Guest Name:</td><td><strong>{customer_name}</strong></td></tr>
                <tr><td style="color: #64748b;">Email:</td><td><a href="mailto:{customer_email}" style="color: #2563eb;">{customer_email}</a></td></tr>
                <tr><td style="color: #64748b;">Phone:</td><td>{customer_phone}</td></tr>
                <tr><td style="color: #64748b;">Special Requests:</td><td>{special_requests}</td></tr>
                <tr><td style="color: #64748b;"></td><td>{custom_fields}</td></tr>
            </table>

            <h3 style="color: #0f172a; border-bottom: 2px solid #f0f4f8; padding-bottom: 8px; margin-top: 24px; font-size: 16px;">Reservation Details</h3>
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px; line-height: 1.8; color: #334155; margin-bottom: 20px;">
                <tr><td style="color: #64748b; width: 35%;">Room:</td><td><strong>{room_name}</strong> {room_number}</td></tr>
                <tr><td style="color: #64748b;">Check-in:</td><td>{check_in} ({check_in_time})</td></tr>
                <tr><td style="color: #64748b;">Check-out:</td><td>{check_out} ({check_out_time})</td></tr>
                <tr><td style="color: #64748b;">Nights:</td><td>{nights} Night(s)</td></tr>
                <?php echo $mhbo_guests_details_row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static template markup with placeholder tokens ?>
                <tr><td style="color: #64748b;">Total Price:</td><td><strong style="color: #0f172a;">{total_price}</strong></td></tr>
                <tr><td style="color: #64748b;">Payment Status:</td><td>{payment_details}</td></tr>
            </table>

            {reservation_breakdown}

            <p style="text-align: center; margin: 30px 0 10px 0;">
                <a href="{view_url}" style="background: #2563eb; color: #ffffff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block;">View Booking in Dashboard &rarr;</a>
            </p>
        </div>
    </div>
</div>
