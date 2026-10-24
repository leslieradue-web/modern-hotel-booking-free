<?php
/**
 * Default Modification Request Email Template.
 *
 * Dispatched to the customer acknowledging receipt of a stay modification
 * request submitted via the Guest Self-Service Portal.
 *
 * @package MHBO\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mhbo_guests_details_row = '<tr><td style="color: #64748b">Current Guests:</td><td style="text-align: right">{guests}</td></tr>';

?>
<div style="background-color: #f4f7f9;padding: 20px 10px;font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif">
    <!-- Hidden Preheader Preview Text -->
    <div style="display: none; font-size: 1px; color: #f8fafc; line-height: 1px; font-family: sans-serif; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;">
        We have received your stay modification request for Reservation #{booking_id} at {company_name}. Our front desk is reviewing availability.
    </div>
    <div style="max-width: 650px;margin: 0 auto;background-color: #ffffff;border: 1px solid #e2e8f0;border-radius: 16px;overflow: visible">
        
        <div style="padding: 20px;border-bottom: 1px solid #f1f5f9">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="padding-bottom: 10px">
                        <img src="{mhbo_logo_url}" alt="{company_name} Logo" style="width: 90%;max-width: 150px;height: auto">
                    </td>
                    <td align="right" style="font-size: 12px;color: #64748b;line-height: 1.4">
                        <strong style="color: #0f172a;font-size: 14px">{company_name}</strong><br>
                        {company_address}<br>
                        <br>
                        {company_phone}<br>                       
                        <a href="{company_website}" style="color: #0077b6;text-decoration: none">{company_website}</a><br>
                    </td>
                </tr>
            </table>
        </div>

        <div style="padding: 30px">
            <p style="font-size: 16px;margin-top: 0">Hi <strong>{customer_name}</strong>,</p>
            <p>Thank you for contacting <strong>{company_name}</strong> regarding your upcoming stay!</p>
            
            <div style="background-color: #eff6ff;border-left: 4px solid #3b82f6;padding: 15px;margin: 25px 0;border-radius: 4px">
                <strong style="color: #1d4ed8;font-size: 15px;">Stay Modification Request Received</strong><br>
                <span style="font-size: 14px;color: #1e40af;">We have received your request to modify Reservation #{booking_id}. Our reservations desk is checking room availability, calendar schedules, and rate adjustments, and will follow up with you shortly.</span>
            </div>

            <h3 style="color: #0077b6;border-bottom: 2px solid #f0f4f8;padding-bottom: 10px;margin-bottom: 20px;font-size: 18px">Requested Changes:</h3>
            
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px;line-height: 1.8;color: #333;background-color: #f8fafc;padding: 14px 18px;border-radius: 8px;border: 1px solid #e2e8f0;margin-bottom: 25px;">
                <tr><td style="color: #64748b;width: 40%;"><strong>Requested Dates:</strong></td><td style="text-align: right">{requested_dates}</td></tr>
                <tr><td style="color: #64748b"><strong>Requested Guests:</strong></td><td style="text-align: right">{requested_guests}</td></tr>
                <tr><td style="color: #64748b;vertical-align: top"><strong>Special Notes / Requests:</strong></td><td style="text-align: right">{modification_notes}</td></tr>
            </table>

            <h3 style="color: #0077b6;border-bottom: 2px solid #f0f4f8;padding-bottom: 10px;margin-bottom: 20px;font-size: 18px">Current Booking Details:</h3>
            
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px;line-height: 1.8;color: #333">
                <tr><td style="color: #64748b"><strong>Booking ID:</strong></td><td style="text-align: right">#{booking_id} <small style="color: #94a3b8">({status})</small></td></tr>
                <tr><td style="color: #64748b">Name:</td><td style="text-align: right">{customer_name}</td></tr>
                <tr><td style="color: #64748b">Room:</td><td style="text-align: right">{room_name}</td></tr>
                <tr><td style="color: #64748b">Current Check-in:</td><td style="text-align: right">{check_in} ({check_in_time})</td></tr>
                <tr><td style="color: #64748b">Current Check-out:</td><td style="text-align: right">{check_out} ({check_out_time})</td></tr>
                <tr><td style="color: #64748b">Current Nights:</td><td style="text-align: right">{nights}</td></tr>
                <?php echo $mhbo_guests_details_row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static template markup with placeholder tokens ?>
                <tr><td style="color: #64748b">E-mail:</td><td style="text-align: right">{customer_email}</td></tr>
                <tr><td style="color: #64748b">Tel:</td><td style="text-align: right">{customer_phone}</td></tr>
                {security_token_row}
            </table>

            {portal_card}

            {social_links}

            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top: 30px">
                <tr>
                    <td>
                        <p style="margin: 0;font-size: 14px;color: #475569">Warm regards, our team is at your service!</p>
                        <p style="margin: 5px 0 0 0;font-weight: bold">{company_name}</p>
                    </td>
                    <td style="text-align: right;vertical-align: bottom">
                        <a href="{whatsapp_link}" style="background-color: #25D366;color: #ffffff;padding: 8px 15px;text-decoration: none;border-radius: 6px;font-weight: bold;font-size: 13px">WhatsApp</a>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>
