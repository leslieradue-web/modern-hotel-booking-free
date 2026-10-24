<?php
/**
 * Default Fallback Booking Email Template.
 *
 * @package MHBO\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mhbo_guests_summary_line = '<p>Guests: {guests}</p>';

?>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <p>Hello {customer_name},</p>
    <p>Your booking #{booking_id} for {room_name} is now {status}.</p>
    <p>Check-in: {check_in} from {check_in_time}<br>Check-out: {check_out} by {check_out_time}</p>
    <?php echo $mhbo_guests_summary_line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static template markup with placeholder tokens ?>
    <p>Best regards,<br>{company_name}</p>
    {social_links}
</div>
