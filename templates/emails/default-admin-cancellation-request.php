<?php
/**
 * Default Admin Cancellation & Refund Request Alert Email Template.
 *
 * Dispatched to hotel administrators and reservation staff when a guest requests
 * cancellation for a reservation that has payments collected.
 *
 * @package MHBO\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="background-color: #f4f7f9;padding: 20px 10px;font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif">
    <div style="max-width: 650px;margin: 0 auto;background-color: #ffffff;border: 1px solid #fca5a5;border-radius: 16px;overflow: visible">
        <div style="background: #b91c1c; color: #ffffff; padding: 20px; border-radius: 15px 15px 0 0;">
            <h2 style="margin: 0; font-size: 20px; color: #ffffff;">&#9888; Action Required: Cancellation & Refund Request &mdash; #{booking_id}</h2>
            <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">A guest has requested cancellation for a reservation with payment on file.</p>
        </div>
        <div style="padding: 30px">
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px; color: #991b1b; font-size: 14px; line-height: 1.5;">
                <strong>Payment on File: {payment_amount}</strong><br>
                This reservation cannot be automatically cancelled because payments have been collected. Please verify your property cancellation policy, process any eligible refund via your payment gateway, and update the reservation status in the PMS.
            </div>

            <h3 style="color: #0f172a; border-bottom: 2px solid #f0f4f8; padding-bottom: 8px; margin-top: 0; font-size: 16px;">Reservation Details</h3>
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px; line-height: 1.8; color: #334155; margin-bottom: 20px;">
                <tr><td style="color: #64748b; width: 35%;">Booking ID:</td><td><strong>#{booking_id}</strong></td></tr>
                <tr><td style="color: #64748b;">Guest Name:</td><td><strong>{customer_name}</strong></td></tr>
                <tr><td style="color: #64748b;">Guest Email:</td><td><a href="mailto:{customer_email}" style="color: #2563eb;">{customer_email}</a></td></tr>
                <tr><td style="color: #64748b;">Guest Phone:</td><td>{customer_phone}</td></tr>
                <tr><td style="color: #64748b;">Room:</td><td><strong>{room_name}</strong> {room_number}</td></tr>
                <tr><td style="color: #64748b;">Stay Dates:</td><td>{check_in} &ndash; {check_out} ({nights} nights)</td></tr>
                <tr><td style="color: #64748b;">Total Paid:</td><td><strong style="color: #166534;">{payment_amount}</strong></td></tr>
                <tr><td style="color: #64748b; vertical-align: top;">Cancellation Reason:</td><td>{cancellation_reason}</td></tr>
            </table>

            <p style="text-align: center; margin: 30px 0 10px 0;">
                <a href="{admin_booking_url}" style="background: #b91c1c; color: #ffffff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block;">Review Booking in PMS &rarr;</a>
            </p>
        </div>
    </div>
</div>
