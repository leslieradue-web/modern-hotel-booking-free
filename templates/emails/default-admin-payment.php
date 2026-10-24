<?php
/**
 * Default Admin Payment Received Notification Email Template.
 *
 * @package MHBO\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="background-color: #f4f7f9;padding: 20px 10px;font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif">
    <div style="max-width: 650px;margin: 0 auto;background-color: #ffffff;border: 1px solid #e2e8f0;border-radius: 16px;overflow: visible">
        <div style="background: #16a34a; color: #ffffff; padding: 20px; border-radius: 15px 15px 0 0;">
            <h2 style="margin: 0; font-size: 20px; color: #ffffff;">Payment Received &mdash; Booking #{booking_id}</h2>
            <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">A payment has been captured for reservation #{booking_id} on {site_name}.</p>
        </div>
        <div style="padding: 30px">
            <p style="font-size: 15px; margin-top: 0; color: #1e293b;">A payment of <strong>{payment_amount}</strong> has been successfully processed for <strong>{customer_name}</strong>.</p>
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px; line-height: 1.8; color: #334155; margin: 20px 0;">
                <tr><td style="color: #64748b; width: 35%;">Booking ID:</td><td>#{booking_id}</td></tr>
                <tr><td style="color: #64748b;">Guest Name:</td><td>{customer_name}</td></tr>
                <tr><td style="color: #64748b;">Email:</td><td><a href="mailto:{customer_email}" style="color: #2563eb; text-decoration: none;">{customer_email}</a></td></tr>
                <tr><td style="color: #64748b;">Room:</td><td>{room_name}</td></tr>
                <tr><td style="color: #64748b;">Amount Paid:</td><td><strong>{payment_amount}</strong></td></tr>
                <tr><td style="color: #64748b;">Payment Method:</td><td>{payment_method}</td></tr>
                <tr><td style="color: #64748b;">Transaction ID:</td><td><code>{payment_transaction_id}</code></td></tr>
                <tr><td style="color: #64748b;">Date & Time:</td><td>{payment_date}</td></tr>
                {payment_note_row}
            </table>
            <p style="text-align: center; margin: 30px 0 10px 0;">
                <a href="{view_url}" style="background: #2563eb; color: #ffffff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block;">View Booking in Dashboard &rarr;</a>
            </p>
        </div>
    </div>
</div>
