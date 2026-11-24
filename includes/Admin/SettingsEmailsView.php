<?php declare(strict_types=1);

namespace MHBO\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;

/**
 * Email Templates & Health Inspector Settings View
 *
 * Renders the Email Health Inspector card and Email Templates management tab.
 */
class SettingsEmailsView {

    /**
     * Render the Email Delivery Health & Diagnostic Inspector card.
     */
    private static function render_email_health_inspector_card(): void {
        $sender_email = \MHBO\Core\Email::get_notification_email();
        $current_user = wp_get_current_user();
        $test_target  = $current_user->user_email ?: $sender_email;
        $site_domain  = (string) preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
        ?>
        <div id="mhbo-email-health-card" class="mhbo-card mhbo-email-health-card" style="margin-bottom: 28px; padding: 24px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 42px; height: 42px; border-radius: 10px; background: #eff6ff; display: flex; align-items: center; justify-content: center; color: #2563eb;">
                        <span class="dashicons dashicons-shield" style="font-size: 24px; width: 24px; height: 24px;"></span>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 4px 0; font-size: 17px; font-weight: 700; color: #0f172a;">
                            <?php esc_html_e('Email Delivery Health & Diagnostic Inspector', 'modern-hotel-booking'); ?>
                        </h3>
                        <p style="margin: 0; font-size: 13px; color: #64748b;">
                            <?php esc_html_e('Live audit of your active mailer engine, domain DNS records (SPF & DMARC), and 1-click test probe.', 'modern-hotel-booking'); ?>
                        </p>
                    </div>
                </div>
                <div>
                    <button type="button" id="mhbo-btn-rescan-email" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; margin-top: 1px;"></span>
                        <?php esc_html_e('Re-scan Deliverability', 'modern-hotel-booking'); ?>
                    </button>
                </div>
            </div>

            <!-- Health Tiles Grid -->
            <div id="mhbo-email-diagnostic-tiles" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 20px;">
                <div class="mhbo-diagnostic-tile" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 4px;"><?php esc_html_e('Active Mailer Engine', 'modern-hotel-booking'); ?></div>
                    <div id="mhbo-tile-mailer" style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update mhbo-spin" style="font-size: 16px; width: 16px; height: 16px; color: #475569;" aria-hidden="true"></span>
                        <span style="color: #475569;"><?php esc_html_e('Inspecting…', 'modern-hotel-booking'); ?></span>
                    </div>
                </div>

                <div class="mhbo-diagnostic-tile" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 4px;"><?php esc_html_e('Sender & Domain Alignment', 'modern-hotel-booking'); ?></div>
                    <div id="mhbo-tile-domain" style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update mhbo-spin" style="font-size: 16px; width: 16px; height: 16px; color: #475569;" aria-hidden="true"></span>
                        <span style="color: #475569;"><?php esc_html_e('Inspecting…', 'modern-hotel-booking'); ?></span>
                    </div>
                </div>

                <div class="mhbo-diagnostic-tile" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 4px;"><?php esc_html_e('SPF DNS Record', 'modern-hotel-booking'); ?></div>
                    <div id="mhbo-tile-spf" style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update mhbo-spin" style="font-size: 16px; width: 16px; height: 16px; color: #475569;" aria-hidden="true"></span>
                        <span style="color: #475569;"><?php esc_html_e('Inspecting…', 'modern-hotel-booking'); ?></span>
                    </div>
                </div>

                <div class="mhbo-diagnostic-tile" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 4px;"><?php esc_html_e('DMARC Policy Record', 'modern-hotel-booking'); ?></div>
                    <div id="mhbo-tile-dmarc" style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update mhbo-spin" style="font-size: 16px; width: 16px; height: 16px; color: #475569;" aria-hidden="true"></span>
                        <span style="color: #475569;"><?php esc_html_e('Inspecting…', 'modern-hotel-booking'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Dynamic Alerts Container -->
            <div id="mhbo-diagnostic-alerts" style="display: none; margin-bottom: 20px;"></div>

            <!-- Last Recorded Failure Banner -->
            <div id="mhbo-diagnostic-failure-banner" style="display: none; margin-bottom: 20px; padding: 14px 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b;"></div>

            <!-- 1-Click Send Test Email Probe -->
            <div style="padding: 18px 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px;">
                <h4 style="margin: 0 0 6px 0; font-size: 14px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-email-alt" style="color: #2563eb;"></span>
                    <?php esc_html_e('1-Click Diagnostic Email Probe', 'modern-hotel-booking'); ?>
                </h4>
                <p style="margin: 0 0 14px 0; font-size: 13px; color: #64748b;">
                    <?php esc_html_e('Send a live test email directly through your server. If sending fails, the system will intercept the underlying PHPMailer error and display troubleshooting steps.', 'modern-hotel-booking'); ?>
                </p>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 260px;">
                        <input type="email" id="mhbo-test-email-recipient" class="regular-text" style="width: 100%; max-width: 420px; height: 38px; border-radius: 6px;" value="<?php echo esc_attr($test_target); ?>" placeholder="you@example.com">
                    </div>
                    <button type="button" id="mhbo-btn-send-test-email" class="button button-primary" style="height: 38px; display: inline-flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-email" style="margin-top: 2px;"></span>
                        <?php esc_html_e('Send Test Email', 'modern-hotel-booking'); ?>
                    </button>
                    <span id="mhbo-test-email-spinner" class="dashicons dashicons-update mhbo-spin" style="display: none; color: #2563eb; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
                <div id="mhbo-test-email-result" style="display: none; margin-top: 14px;"></div>
            </div>

            <!-- Collapsible Deliverability Setup Guide -->
            <details class="mhbo-deliverability-guide" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #ffffff;">
                <summary style="padding: 12px 16px; font-size: 13px; font-weight: 600; color: #334155; cursor: pointer; background: #f8fafc; user-select: none;">
                    📖 <?php esc_html_e('Domain Setup & Recommended SMTP Solutions (Best Practices Guide)', 'modern-hotel-booking'); ?>
                </summary>
                <div style="padding: 18px 20px; font-size: 13px; color: #475569; line-height: 1.6;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                        <div>
                            <h5 style="margin: 0 0 6px 0; font-size: 14px; color: #0f172a; font-weight: 700;">🚀 <?php esc_html_e('Option A (Recommended): Install a Dedicated SMTP Plugin', 'modern-hotel-booking'); ?></h5>
                            <p style="margin: 0 0 10px 0;">
                                <?php esc_html_e('Default WordPress PHP mail() is sent directly from your web server without reputation or digital signing, which causes Gmail, Yahoo, and Outlook to flag emails as spam or drop them silently.', 'modern-hotel-booking'); ?>
                            </p>
                            <p style="margin: 0 0 10px 0;">
                                <?php esc_html_e('We strongly recommend installing one of the following free plugins to route emails through an authenticated provider (such as Brevo, SendGrid, Amazon SES, Google Workspace, or Microsoft 365):', 'modern-hotel-booking'); ?>
                            </p>
                            <ul style="margin: 0 0 10px 20px; list-style: disc;">
                                <li><strong>FluentSMTP</strong> (100% Free, zero ads, excellent logging)</li>
                                <li><strong>WP Mail SMTP</strong> (Widely used, simple setup wizard)</li>
                            </ul>
                        </div>
                        <div>
                            <h5 style="margin: 0 0 6px 0; font-size: 14px; color: #0f172a; font-weight: 700;">🛡️ <?php esc_html_e('Domain DNS Authentication Checklist', 'modern-hotel-booking'); ?></h5>
                            <p style="margin: 0 0 8px 0;">
                                <?php esc_html_e('To pass modern 2024–2026 inbox security checks, your domain DNS manager (e.g. Cloudflare, cPanel, GoDaddy) must have:', 'modern-hotel-booking'); ?>
                            </p>
                            <div style="background: #f1f5f9; padding: 10px 14px; border-radius: 6px; font-family: monospace; font-size: 12px; margin-bottom: 8px;">
                                <strong>SPF TXT:</strong> v=spf1 include:your-mail-host.com ~all
                            </div>
                            <div style="background: #f1f5f9; padding: 10px 14px; border-radius: 6px; font-family: monospace; font-size: 12px; margin-bottom: 8px;">
                                <strong>DMARC TXT:</strong> Name: _dmarc.<?php echo esc_html($site_domain); ?><br>Value: v=DMARC1; p=none;
                            </div>
                            <p style="margin: 0; font-size: 12px; color: #64748b;">
                                <em><?php esc_html_e('Tip: Never use an @gmail.com or @yahoo.com address as the sender from your custom domain — Google and Yahoo DMARC policies will reject the mail at the receiving server.', 'modern-hotel-booking'); ?></em>
                            </p>
                        </div>
                    </div>
                </div>
            </details>
        </div>
        <?php
    }

    /**
     * Render email templates tab.
     *
     * @return void
     */
    public static function render_email_templates_tab(): void {
        $is_pro = false;
        if ( class_exists( '\MHBO\Core\Tier' ) ) {
            $is_pro = \MHBO\Core\Tier::is_effective_at_least( \MHBO\Core\Tier::TIER_PRO );
        }

$statuses = array(
            'pending'   => array(
                'label'     => I18n::get_label('email_status_pending'),
                'badge_bg'  => '#fef3c7',
                'badge_fg'  => '#92400e',
                'icon'      => 'dashicons-clock',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest immediately when their booking request is awaiting confirmation.', 'modern-hotel-booking'),
            ),
            'confirmed' => array(
                'label'     => I18n::get_label('email_status_confirmed'),
                'badge_bg'  => '#dcfce7',
                'badge_fg'  => '#166534',
                'icon'      => 'dashicons-yes-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest when their reservation is approved & confirmed.', 'modern-hotel-booking'),
            ),
            'cancelled' => array(
                'label'     => I18n::get_label('email_status_cancelled'),
                'badge_bg'  => '#fee2e2',
                'badge_fg'  => '#991b1b',
                'icon'      => 'dashicons-no-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest if their booking is rejected or cancelled.', 'modern-hotel-booking'),
            ),
            'payment'   => array(
                'label'     => I18n::get_label('email_status_payment'),
                'badge_bg'  => '#eff6ff',
                'badge_fg'  => '#1e40af',
                'icon'      => 'dashicons-money-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Payment receipt sent to the guest when checkout payment is completed.', 'modern-hotel-booking'),
            ),
            'stay_dates_updated' => array(
                'label'     => I18n::get_label('email_status_stay_dates_updated'),
                'badge_bg'  => '#ecfdf5',
                'badge_fg'  => '#065f46',
                'icon'      => 'dashicons-calendar',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest acknowledging that their stay dates have been updated in the Guest Self-Service Portal.', 'modern-hotel-booking'),
            ),
            'modification_request' => array(
                'label'     => I18n::get_label('email_status_modification_request'),
                'badge_bg'  => '#e0e7ff',
                'badge_fg'  => '#3730a3',
                'icon'      => 'dashicons-calendar-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest acknowledging that their stay modification request has been submitted to the front desk.', 'modern-hotel-booking'),
            ),
            'cancellation_request' => array(
                'label'     => I18n::get_label('email_status_cancellation_request'),
                'badge_bg'  => '#fef3c7',
                'badge_fg'  => '#92400e',
                'icon'      => 'dashicons-warning',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest acknowledging that their cancellation request for a paid booking has been received and is under staff review.', 'modern-hotel-booking'),
            ),
            'email_updated' => array(
                'label'     => I18n::get_label('email_status_email_updated'),
                'badge_bg'  => '#fee2e2',
                'badge_fg'  => '#991b1b',
                'icon'      => 'dashicons-shield',
                'pro'       => false,
                'recipient' => _x('Guest & Admin', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Security alert dispatched to old and new email addresses, as well as property staff, when a contact email is updated.', 'modern-hotel-booking'),
            ),
            'identity_verification' => array(
                'label'     => I18n::get_label('email_status_identity_verification'),
                'badge_bg'  => '#ecfdf5',
                'badge_fg'  => '#065f46',
                'icon'      => 'dashicons-shield-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('One-time verification PIN sent to guest email to verify reservation ownership and concierge access.', 'modern-hotel-booking'),
            ),
            'admin_new_booking' => array(
                'label'     => I18n::get_label('email_status_admin_new_booking'),
                'badge_bg'  => '#f3e8ff',
                'badge_fg'  => '#6b21a8',
                'icon'      => 'dashicons-bell',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Alert sent to property administrators whenever a new booking is submitted.', 'modern-hotel-booking'),
            ),
            'admin_payment' => array(
                'label'     => I18n::get_label('email_status_admin_payment'),
                'badge_bg'  => '#ecfdf5',
                'badge_fg'  => '#065f46',
                'icon'      => 'dashicons-cart',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Notification sent to property administrators when a payment is processed.', 'modern-hotel-booking'),
            ),
            'admin_modification_request' => array(
                'label'     => I18n::get_label('email_status_admin_modification_request'),
                'badge_bg'  => '#eff6ff',
                'badge_fg'  => '#1d4ed8',
                'icon'      => 'dashicons-edit',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Alert sent to hotel administrators and reservation staff when a guest submits a stay modification request in the portal.', 'modern-hotel-booking'),
            ),
            'admin_cancellation_request' => array(
                'label'     => I18n::get_label('email_status_admin_cancellation_request'),
                'badge_bg'  => '#fef2f2',
                'badge_fg'  => '#b91c1c',
                'icon'      => 'dashicons-flag',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Action-required alert sent to property staff when a guest requests cancellation for a reservation with payments on file.', 'modern-hotel-booking'),
            ),
            'admin_cancellation' => array(
                'label'     => I18n::get_label('email_status_admin_cancellation'),
                'badge_bg'  => '#fee2e2',
                'badge_fg'  => '#dc2626',
                'icon'      => 'dashicons-dismiss',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Notification sent to property administrators when an unpaid reservation is cancelled by a guest via the self-service portal.', 'modern-hotel-booking'),
            ),
            
        );

        $langs = I18n::get_available_languages();
        $is_multilingual = count($langs) > 1;
        ?>
        <?php self::render_email_health_inspector_card(); ?>
        <div class="mhbo-settings-section mhbo-email-templates-section">
            <div style="margin-bottom: 24px;">
                <h3 style="font-size:18px; font-weight:700; color:#0f172a; margin:0 0 6px 0;"><?php esc_html_e('Custom Email Templates & Multilingual Overrides', 'modern-hotel-booking'); ?></h3>
                <p style="color:#64748b; font-size:14px; margin:0; line-height:1.5;">
                    <?php esc_html_e('Customize the subject lines and message bodies of all automated emails. Leave any field empty to use the system default template.', 'modern-hotel-booking'); ?>
                    <?php if ($is_multilingual) : ?>
                        <span style="display:block; margin-top:4px; color:#2563eb; font-weight:500;">
                            🌐 <?php esc_html_e('Multilingual Mode Active: Translate templates individually for each supported language.', 'modern-hotel-booking'); ?>
                        </span>
                    <?php endif; ?>
                    <span style="display:block; margin-top:4px; color:#475569; font-size:13px;">
                        💡 <?php esc_html_e('Theme Overrides: Default email template partials can also be customized via your theme in modern-hotel-booking/emails/ or mhbo/emails/.', 'modern-hotel-booking'); ?>
                    </span>
                </p>
            </div>

            <?php
            foreach ($statuses as $id => $meta) : 
                $is_locked = ! empty($meta['pro']) && ! $is_pro;
                $card_style = $is_locked
                    ? 'margin-bottom: 24px; padding: 22px; background:#fff; border: 1px solid #e9d5ff; border-radius: 12px; box-shadow: 0 1px 3px rgba(147, 51, 234, 0.05);'
                    : 'margin-bottom: 24px; padding: 22px; background:#fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);';
				?>
                <div class="mhbo-card" style="<?php echo esc_attr($card_style); ?>">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h3 style="margin: 0 0 4px 0; font-size:16px; font-weight:700; color:#0f172a; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons <?php echo esc_attr($meta['icon']); ?>" style="color:<?php echo $is_locked ? '#9333ea' : '#2563eb'; ?>; font-size:20px; width:20px; height:20px;"></span>
                                <?php echo esc_html($meta['label']); ?>
                                <?php if ( ! empty($meta['pro'])) : ?>
                                    <span style="background: linear-gradient(135deg, #2563eb, #7c3aed); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.5px; text-transform: uppercase;">PRO</span>
                                <?php endif; ?>
                            </h3>
                            <p style="margin:0; font-size:13px; color:#64748b;"><?php echo esc_html($meta['desc']); ?></p>
                        </div>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span style="font-size:11px; font-weight:600; padding:3px 10px; border-radius:99px; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;">
                                <?php echo esc_html($meta['recipient']); ?>
                            </span>
                            <span style="font-size:11px; font-weight:700; text-transform:uppercase; padding:3px 10px; border-radius:99px; background:<?php echo esc_attr($meta['badge_bg']); ?>; color:<?php echo esc_attr($meta['badge_fg']); ?>;">
                                <?php echo esc_html($id); ?>
                            </span>
                        </div>
                    </div>

<?php
                    foreach ($langs as $code) : 
                        $raw_subject = get_option("mhbo_email_{$id}_subject", '');
                        $raw_message = get_option("mhbo_email_{$id}_message", '');
                        
                        $subject = (string) ( I18n::decode($raw_subject, $code, true) ?? '' );
                        $message = (string) ( I18n::decode($raw_message, $code, true) ?? '' );
                        $lang_label = I18n::get_language_name($code);
						?>
                        <div class="mhbo-lang-group" style="<?php echo $is_multilingual ? 'margin-bottom: 18px; padding: 16px 20px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #2563eb;' : ''; ?>">
                            <?php if ($is_multilingual) : ?>
                                <h4 style="margin: 0 0 12px 0; color: #1e40af; font-size:13px; font-weight:700; display:flex; align-items:center; gap:6px;">
                                    <span class="dashicons dashicons-translation" style="font-size:16px; width:16px; height:16px;"></span>
                                    <?php echo esc_html($lang_label); ?> (<?php echo esc_html(strtoupper($code)); ?>)
                                </h4>
                            <?php endif; ?>

                            <div style="margin-bottom: 14px;">
                                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">
                                    <?php echo esc_html(I18n::get_label('email_label_subject')); ?>
                                </label>
                                <input type="text" 
                                    name="mhbo_email_templates[<?php echo esc_attr($id); ?>][subject][<?php echo esc_attr($code); ?>]"
                                    value="<?php echo esc_attr($subject); ?>" 
                                    class="widefat" 
                                    placeholder="<?php echo esc_attr($is_locked ? __('Available exclusively in Modern Hotel Booking Pro...', 'modern-hotel-booking') : __('Leave empty to use default subject...', 'modern-hotel-booking')); ?>"
                                    style="border-radius: 6px; height: 38px;<?php echo $is_locked ? ' background:#f8fafc; color:#94a3b8; cursor:not-allowed;' : ''; ?>"
                                    <?php disabled($is_locked); ?>>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">
                                    <?php echo esc_html(I18n::get_label('email_label_message')); ?>
                                </label>
                                <textarea 
                                    name="mhbo_email_templates[<?php echo esc_attr($id); ?>][message][<?php echo esc_attr($code); ?>]" 
                                    rows="7"
                                    class="widefat" 
                                    placeholder="<?php echo esc_attr($is_locked ? __('Available exclusively in Modern Hotel Booking Pro...', 'modern-hotel-booking') : __('Leave empty to use built-in responsive HTML template...', 'modern-hotel-booking')); ?>"
                                    style="border-radius: 6px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;<?php echo $is_locked ? ' background:#f8fafc; color:#94a3b8; cursor:not-allowed;' : ''; ?>"
                                    <?php disabled($is_locked); ?>><?php echo esc_textarea($message); ?></textarea>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <!-- Comprehensive Categorized Placeholder Reference Guide -->
            <div class="mhbo-card" style="margin-top: 32px; padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                    <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-editor-code" style="color: #2563eb; font-size: 22px; width: 22px; height: 22px;"></span>
                        <?php echo esc_html(I18n::get_label('email_placeholders_title')); ?>
                    </h3>
                    <span id="mhbo-copy-toast" style="display:none; font-size:12px; font-weight:600; color:#166534; background:#dcfce7; padding:3px 10px; border-radius:6px; border:1px solid #bbf7d0;">
                        <?php esc_html_e('Copied tag to clipboard!', 'modern-hotel-booking'); ?>
                    </span>
                </div>
                <p style="color: #64748b; font-size: 13px; margin: 0 0 20px 0; line-height: 1.5;">
                    <?php echo esc_html(I18n::get_label('email_placeholders_desc_full')); ?>
                </p>

                <?php
                $extras_fields_heading = __('Custom Checkout Fields', 'modern-hotel-booking');

$placeholder_categories = array(
                    __('Guest Details', 'modern-hotel-booking') => array(
                        '{customer_name}'     => __('Guest full name', 'modern-hotel-booking'),
                        '{guest_name}'        => __('Guest full name (synonym)', 'modern-hotel-booking'),
                        '{first_name}'        => __('Guest first name', 'modern-hotel-booking'),
                        '{last_name}'         => __('Guest last name', 'modern-hotel-booking'),
                        '{customer_email}'    => __('Guest email address', 'modern-hotel-booking'),
                        '{customer_phone}'    => __('Guest phone number', 'modern-hotel-booking'),
                        '{special_requests}'  => __('Special requests submitted at checkout', 'modern-hotel-booking'),
                        '{arrival_time}'      => __('Estimated arrival time', 'modern-hotel-booking'),
                    ),
                    __('Stay & Accommodation Details', 'modern-hotel-booking') => array(
                        '{booking_id}'        => __('Booking ID number (e.g. 1042)', 'modern-hotel-booking'),
                        '{booking_token}'     => __('Secure booking reference token', 'modern-hotel-booking'),
                        '{view_url}'          => __('Direct link to view / manage reservation', 'modern-hotel-booking'),
                        '{room_name}'         => __('Room type name', 'modern-hotel-booking'),
                        '{room_number}'       => __('Assigned room unit number (e.g. 101, A2)', 'modern-hotel-booking'),
                        '{check_in}'          => __('Check-in date (formatted)', 'modern-hotel-booking'),
                        '{check_out}'         => __('Check-out date (formatted)', 'modern-hotel-booking'),
                        '{check_in_time}'     => __('Check-in time (e.g. 14:00)', 'modern-hotel-booking'),
                        '{check_out_time}'    => __('Check-out time (e.g. 11:00)', 'modern-hotel-booking'),
                        '{nights}'            => __('Number of nights', 'modern-hotel-booking'),
                        '{guests}'            => __('Adult guest count', 'modern-hotel-booking'),

'{status}'            => __('Translated booking status', 'modern-hotel-booking'),
                    ),
                    __('Financials, Deposits & Payment Summary', 'modern-hotel-booking') => array(
                        '{total_price}'            => __('Total booking price (formatted)', 'modern-hotel-booking'),
                        '{payment_amount}'         => __('Amount paid so far (formatted)', 'modern-hotel-booking'),
                        '{payment_method}'         => __('Payment method name (translated)', 'modern-hotel-booking'),
                        '{due_now}'                => __('Current amount due now (formatted)', 'modern-hotel-booking'),
                        '{due_type}'               => __('Due label (Deposit Required or Balance Due)', 'modern-hotel-booking'),
                        '{remaining_balance}'      => __('Remaining balance amount due (formatted)', 'modern-hotel-booking'),
                        '{payment_ledger}'         => __('Itemized payment ledger history (HTML)', 'modern-hotel-booking'),
                        '{payment_transaction_id}' => __('Gateway transaction or charge ID', 'modern-hotel-booking'),
                        '{payment_date}'           => __('Payment timestamp', 'modern-hotel-booking'),
                        '{payment_details}'        => __('Payment confirmation status card', 'modern-hotel-booking'),
                        
                        '{reservation_breakdown}'  => __('Complete itemized HTML reservation breakdown table', 'modern-hotel-booking'),
                    ),
                    $extras_fields_heading => array(
                        
                        '{custom_fields}'     => __('Formatted custom guest checkout fields', 'modern-hotel-booking'),
                    ),
                    __('Guest Self-Service, Requests & Identity Verification', 'modern-hotel-booking') => array(
                        '{cancellation_reason}' => __('Guest cancellation reason or explanatory note', 'modern-hotel-booking'),
                        '{requested_dates}'     => __('Requested new stay dates from guest portal', 'modern-hotel-booking'),
                        '{requested_guests}'    => __('Requested new guest composition from guest portal', 'modern-hotel-booking'),
                        '{modification_notes}'  => __('Special requests or notes for stay modification', 'modern-hotel-booking'),
                        '{old_check_in}'        => __('Previous check-in date (stay dates updated email)', 'modern-hotel-booking'),
                        '{old_check_out}'       => __('Previous check-out date (stay dates updated email)', 'modern-hotel-booking'),
                        '{amount_paid}'         => __('Formatted amount paid (cancellation review & admin payment alerts)', 'modern-hotel-booking'),
                        '{old_email}'           => __('Previous guest contact email address (security alert)', 'modern-hotel-booking'),
                        '{new_email}'           => __('Updated guest contact email address (security alert)', 'modern-hotel-booking'),
                        '{admin_booking_url}'   => __('Direct URL to open reservation in WordPress PMS', 'modern-hotel-booking'),
                        '{verification_code}'   => __('6-digit security verification PIN code', 'modern-hotel-booking'),
                        '{verification_expiry}' => __('Verification code validity window (e.g. 20 minutes)', 'modern-hotel-booking'),
                        '{portal_card}'         => __('Styled Guest Self-Service Portal login card with link & token', 'modern-hotel-booking'),
                        '{security_token_row}'  => __('Table row displaying the security reference token', 'modern-hotel-booking'),
                        '{cancellation_policy}' => __('Property cancellation & modification policy notice', 'modern-hotel-booking'),
                    ),
                    
                    __('Property & Business Information', 'modern-hotel-booking') => array(
                        '{company_name}'         => __('Property / hotel registered name', 'modern-hotel-booking'),
                        '{hotel_name}'           => __('Hotel property name (synonym)', 'modern-hotel-booking'),
                        '{company_address}'      => __('Full hotel street address', 'modern-hotel-booking'),
                        '{company_phone}'        => __('Hotel telephone number', 'modern-hotel-booking'),
                        '{company_email}'        => __('Hotel inquiries email address', 'modern-hotel-booking'),
                        '{company_website}'      => __('Hotel website link', 'modern-hotel-booking'),
                        '{company_registration}' => __('Business registration number', 'modern-hotel-booking'),
                        '{whatsapp_number}'      => __('WhatsApp contact phone', 'modern-hotel-booking'),
                        '{whatsapp_link}'        => __('Click-to-chat WhatsApp direct URL', 'modern-hotel-booking'),
                        '{mhbo_logo_url}'        => __('Hotel logo image URL', 'modern-hotel-booking'),
                        '{logo_header}'          => __('Branded hotel logo header HTML', 'modern-hotel-booking'),
                        '{site_name}'            => __('WordPress site name', 'modern-hotel-booking'),
                        '{site_title}'           => __('WordPress site title (synonym)', 'modern-hotel-booking'),
                        
                    ),
                    
                );
                ?>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
                    <?php foreach ($placeholder_categories as $category_title => $tags) : ?>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                            <h4 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 700; color: #1e293b; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                                <?php echo esc_html($category_title); ?>
                            </h4>
                            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                <?php foreach ($tags as $tag => $tag_desc) : ?>
                                    <tr>
                                        <td style="padding: 5px 0; vertical-align: top; width: 45%;">
                                            <code onclick="mhboCopyTag(this)" title="<?php esc_attr_e('Click to copy', 'modern-hotel-booking'); ?>" style="cursor: pointer; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 11px; display: inline-block;">
                                                <?php echo esc_html($tag); ?>
                                            </code>
                                        </td>
                                        <td style="padding: 5px 0; color: #64748b; vertical-align: top;">
                                            <?php echo esc_html($tag_desc); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <script>
        function mhboCopyTag(el) {
            const text = el.innerText.trim();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    const toast = document.getElementById('mhbo-copy-toast');
                    if (toast) {
                        toast.style.display = 'inline-block';
                        toast.innerText = '<?php echo esc_js(__('Copied', 'modern-hotel-booking')); ?> ' + text + ' !';
                        setTimeout(function() {
                            toast.style.display = 'none';
                        }, 2500);
                    }
                });
            }
        }
        </script>
        <?php
    }
}
