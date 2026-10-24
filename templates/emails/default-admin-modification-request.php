<?php
/**
 * Default Admin Stay Modification Request Email Template.
 *
 * Dispatched to hotel administrators and reservation staff when a guest submits
 * a stay modification request via the Guest Self-Service Portal.
 *
 * @package MHBO\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="background-color: #f4f7f9;padding: 20px 10px;font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif">
    <div style="max-width: 650px;margin: 0 auto;background-color: #ffffff;border: 1px solid #e2e8f0;border-radius: 16px;overflow: visible">
        <div style="background: #2563eb; color: #ffffff; padding: 20px; border-radius: 15px 15px 0 0;">
            <h2 style="margin: 0; font-size: 20px; color: #ffffff;">Stay Modification Request &mdash; #{booking_id}</h2>
            <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">A guest has requested stay modifications via the Guest Self-Service Portal.</p>
        </div>
        <div style="padding: 30px">
            <h3 style="color: #0f172a; border-bottom: 2px solid #f0f4f8; padding-bottom: 8px; margin-top: 0; font-size: 16px;">Guest & Current Booking</h3>
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px; line-height: 1.8; color: #334155; margin-bottom: 20px;">
                <tr><td style="color: #64748b; width: 35%;">Booking Reference:</td><td><strong>#{booking_id}</strong></td></tr>
                <tr><td style="color: #64748b;">Guest Name:</td><td><strong>{customer_name}</strong></td></tr>
                <tr><td style="color: #64748b;">Guest Email:</td><td><a href="mailto:{customer_email}" style="color: #2563eb;">{customer_email}</a></td></tr>
                <tr><td style="color: #64748b;">Guest Phone:</td><td>{customer_phone}</td></tr>
                <tr><td style="color: #64748b;">Current Stay:</td><td>{check_in} &ndash; {check_out} ({nights} nights)</td></tr>
                <tr><td style="color: #64748b;">Room:</td><td><strong>{room_name}</strong> {room_number}</td></tr>
            </table>

            <h3 style="color: #0f172a; border-bottom: 2px solid #f0f4f8; padding-bottom: 8px; margin-top: 24px; font-size: 16px;">Requested Modifications</h3>
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px; line-height: 1.8; color: #1e293b; background: #eff6ff; padding: 16px 20px; border-radius: 8px; border: 1px solid #bfdbfe; margin-bottom: 24px;">
                <tr><td style="color: #1e40af; width: 35%; font-weight: 600;">Requested Dates:</td><td style="font-weight: bold;">{requested_dates}</td></tr>
                <tr><td style="color: #1e40af; font-weight: 600;">Requested Guests:</td><td>{requested_guests}</td></tr>
                <tr><td style="color: #1e40af; font-weight: 600; vertical-align: top;">Guest Notes / Reason:</td><td style="white-space: pre-wrap;">{modification_notes}</td></tr>
            </table>

            <p style="text-align: center; margin: 30px 0 10px 0;">
                <a href="{admin_booking_url}" style="background: #2563eb; color: #ffffff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block;">Open Booking in PMS &rarr;</a>
            </p>
        </div>
    </div>
</div>
