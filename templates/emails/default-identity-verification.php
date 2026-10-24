<?php
/**
 * Default Identity Verification Email Template.
 *
 * Dispatched to the customer containing a one-time numeric security PIN (OTP)
 * for AI Concierge authentication and Guest Self-Service verification.
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
        Your single-use security verification PIN for {company_name} is {verification_code}. Valid for {verification_expiry}.
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
            <div style="text-align: center; margin-bottom: 24px;">
                <h2 style="color: #0f172a; margin-top: 0; font-size: 22px; font-weight: 700;">Verify Your Identity</h2>
                <p style="font-size: 15px; color: #475569; margin: 8px 0 0 0; line-height: 1.5;">Please use the following single-use verification code to confirm your identity with <strong>{company_name}</strong>. This code will expire in <strong>{verification_expiry}</strong>.</p>
            </div>
            
            <div style="margin: 25px auto; padding: 20px; background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; text-align: center; max-width: 240px;">
                <span style="font-size: 34px; font-weight: 800; letter-spacing: 6px; color: #0f172a; font-family: monospace; display: block;">{verification_code}</span>
            </div>

            <p style="font-size: 13px; color: #64748b; text-align: center; margin: 24px 0 0 0; line-height: 1.5;">
                If you did not request this security code, please ignore this email. Your account and reservation details remain safe and secure.
            </p>

            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top: 35px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                <tr>
                    <td>
                        <p style="margin: 0;font-size: 14px;color: #475569">Best regards,</p>
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
