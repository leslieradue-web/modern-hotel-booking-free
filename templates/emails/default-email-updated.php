<?php
/**
 * Default Email Address Updated Security Notification Template.
 *
 * Dispatched to both old and new email addresses, as well as property staff,
 * whenever a guest contact email is updated via the Guest Self-Service Portal.
 *
 * @package MHBO\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="background-color: #f4f7f9;padding: 20px 10px;font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif">
    <!-- Hidden Preheader Preview Text -->
    <div style="display: none; font-size: 1px; color: #f8fafc; line-height: 1px; font-family: sans-serif; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;">
        Security Notice: The contact email address for Booking #{booking_id} at {company_name} was recently updated.
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
            <p>This is an automated security notice regarding your reservation at <strong>{company_name}</strong>.</p>
            
            <div style="background-color: #fef2f2;border-left: 4px solid #ef4444;padding: 16px;margin: 25px 0;border-radius: 4px">
                <strong style="color: #991b1b;font-size: 15px;">Security Notice: Email Address Updated</strong><br>
                <span style="font-size: 14px;color: #7f1d1d;">The contact email address associated with your reservation was recently updated via our Guest Self-Service Portal.</span>
            </div>

            <h3 style="color: #0077b6;border-bottom: 2px solid #f0f4f8;padding-bottom: 10px;margin-bottom: 20px;font-size: 18px">Update Summary:</h3>
            
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px;line-height: 1.8;color: #333;background-color: #f8fafc;padding: 14px 18px;border-radius: 8px;border: 1px solid #e2e8f0;margin-bottom: 25px;">
                <tr><td style="color: #64748b;width: 35%;"><strong>Booking ID:</strong></td><td>#{booking_id}</td></tr>
                <tr><td style="color: #64748b"><strong>Guest Name:</strong></td><td>{customer_name}</td></tr>
                <tr><td style="color: #64748b"><strong>Room:</strong></td><td>{room_name}</td></tr>
                <tr><td style="color: #64748b"><strong>Stay Dates:</strong></td><td>{check_in} &ndash; {check_out}</td></tr>
                <tr><td style="color: #64748b"><strong>Previous Email:</strong></td><td style="color: #64748b;text-decoration: line-through">{old_email}</td></tr>
                <tr><td style="color: #64748b"><strong>New Email:</strong></td><td style="font-weight: bold;color: #0f172a">{new_email}</td></tr>
            </table>

            <div style="background: #f1f5f9;border-radius: 8px;padding: 14px 16px;margin-bottom: 24px;font-size: 13px;color: #475569;line-height: 1.6;">
                <strong>Did you authorize this change?</strong><br>
                If you made this update yourself via our portal, no further action is required. If you did <em>not</em> request this change, please contact our reservations desk immediately so we can secure your reservation.
            </div>

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
