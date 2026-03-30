<?php
/**
 * Business Info Settings
 *
 * Admin settings page for company details, WhatsApp, banking, and Revolut.
 * Namespace-aligned with PSR-4 autoloader.
 *
 * @package ModernHotelBooking
 * @since   2.1.0
 */

namespace MHBO\Business;

if (!defined('ABSPATH')) exit;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Info {

    /** @var self|null */
    private static $instance = null;

    /** Option keys — prefixed per WP.org rules. */
    const OPT_COMPANY  = 'mhbo_company_info';
    const OPT_WHATSAPP = 'mhbo_whatsapp_info';
    const OPT_BANKING  = 'mhbo_banking_info';
    const OPT_REVOLUT  = 'mhbo_revolut_info';

    /** Nonce constants. */
    const NONCE_SAVE_ACTION = 'mhbo_save_business_info';
    const NONCE_SAVE_FIELD  = 'mhbo_business_nonce';
    const NONCE_TAB_ACTION  = 'mhbo_admin_tab_nav';

    /** @return self */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /* ═══════════════════════════════════════════════════════════════
       ADMIN MENU
       ═══════════════════════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════════════════════
       ADMIN ASSETS
       ═══════════════════════════════════════════════════════════════ */

    /**
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'hotel-booking_page_mhbo-settings' !== $hook ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ('business' === $tab) {
            wp_enqueue_script('mhbo-admin-business', MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-business.js', ['jquery'], MHBO_VERSION, true);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if ('mhbo_save_business' === $action) {
            // This block was empty in the provided snippet, assuming it's for future use or a placeholder.
        }

        // The original check for 'tab' is replaced by the above 'if ('business' === $tab)' block
        // and the subsequent enqueue calls are now conditional on 'business' tab being active.
        if ('business' !== $tab) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'mhbo-admin-business',
            MHBO_PLUGIN_URL . 'assets/css/mhbo-admin-business.css',
            array(),
            '2.1.0'
        );

        wp_enqueue_script(
            'mhbo-admin-media-upload',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-media-upload.js',
            array( 'jquery' ),
            '2.1.0',
            true
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       DEFAULT VALUES
       ═══════════════════════════════════════════════════════════════ */

    /** @return array */
    public static function get_company_defaults() {
        return array(
            'company_name'    => '',
            'address_line_1'  => '',
            'address_line_2'  => '',
            'city'            => '',
            'state'           => '',
            'postcode'        => '',
            'country'         => '',
            'telephone'       => '',
            'email'           => '',
            'contact_name'    => '',
            'logo_id'         => 0,
            'logo_url'        => '',
            'tax_id'          => '',
            'registration_no' => '',
            'website'         => '',
        );
    }

    /** @return array */
    public static function get_whatsapp_defaults() {
        return array(
            'enabled'       => 0,
            'phone_number'  => '',
            'default_msg'   => '',
            'button_text'   => 'Chat on WhatsApp',
            'display_style' => 'button',
            'position'      => 'bottom-right',
        );
    }

    /** @return array */
    public static function get_banking_defaults() {
        return array(
            'enabled'          => 0,
            'bank_name'        => '',
            'account_name'     => '',
            'account_number'   => '',
            'iban'             => '',
            'swift_bic'        => '',
            'sort_code'        => '',
            'branch_address'   => '',
            'reference_prefix' => '',
            'instructions'     => '',
        );
    }

    /** @return array */
    public static function get_revolut_defaults() {
        return array(
            'enabled'       => 0,
            'revolut_name'  => '',
            'revolut_tag'   => '',
            'revolut_iban'  => '',
            'revolut_link'  => '',
            'qr_code_id'    => 0,
            'qr_code_url'   => '',
            'instructions'  => '',
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       OPTION GETTERS
       ═══════════════════════════════════════════════════════════════ */

    /** @return array */
    public static function get_company() {
        return wp_parse_args( get_option( self::OPT_COMPANY, array() ), self::get_company_defaults() );
    }

    /** @return array */
    public static function get_whatsapp() {
        return wp_parse_args( get_option( self::OPT_WHATSAPP, array() ), self::get_whatsapp_defaults() );
    }

    /** @return array */
    public static function get_banking() {
        return wp_parse_args( get_option( self::OPT_BANKING, array() ), self::get_banking_defaults() );
    }

    /** @return array */
    public static function get_revolut() {
        return wp_parse_args( get_option( self::OPT_REVOLUT, array() ), self::get_revolut_defaults() );
    }

    /* ═══════════════════════════════════════════════════════════════
       SAVE HANDLER
       ═══════════════════════════════════════════════════════════════ */

    public function handle_save(array $data) {
        $tab        = isset($data['mhbo_business_subtab']) ? sanitize_key(wp_unslash($data['mhbo_business_subtab'])) : 'company';
        $valid_tabs = array('company', 'whatsapp', 'banking', 'revolut');

        if (!in_array($tab, $valid_tabs, true)) {
            $tab = 'company';
        }

        switch ($tab) {
            case 'company':
                $this->save_company($data);
                break;
            case 'whatsapp':
                $this->save_whatsapp($data);
                break;
            case 'banking':
                $this->save_banking($data);
                break;
            case 'revolut':
                $this->save_revolut($data);
                break;
        }

        add_settings_error('mhbo_settings', 'saved', __('Business settings saved successfully.', 'modern-hotel-booking'), 'success');

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'         => 'mhbo-settings',
                    'tab'          => 'business',
                    'subtab'       => $tab,
                    'mhbo_updated' => 1,
                ),
                admin_url('admin.php')
            )
        );
        exit;

    }

    private function save_company(array $data) {
        $clean_data = array();
        // 2026/WP Repo Compliance: Explicit unslashing and sanitization per-key
        $clean_data['company_name']    = isset($data['company_name'])    ? sanitize_text_field(wp_unslash($data['company_name']))    : '';
        $clean_data['address_line_1']  = isset($data['address_line_1'])  ? sanitize_text_field(wp_unslash($data['address_line_1']))  : '';
        $clean_data['address_line_2']  = isset($data['address_line_2'])  ? sanitize_text_field(wp_unslash($data['address_line_2']))  : '';
        $clean_data['city']            = isset($data['city'])            ? sanitize_text_field(wp_unslash($data['city']))            : '';
        $clean_data['state']           = isset($data['state'])           ? sanitize_text_field(wp_unslash($data['state']))           : '';
        $clean_data['postcode']        = isset($data['postcode'])        ? sanitize_text_field(wp_unslash($data['postcode']))        : '';
        $clean_data['country']         = isset($data['country'])         ? sanitize_text_field(wp_unslash($data['country']))         : '';
        $clean_data['telephone']       = isset($data['telephone'])       ? sanitize_text_field(wp_unslash($data['telephone']))       : '';
        $clean_data['email']           = isset($data['email'])           ? sanitize_email(wp_unslash($data['email']))                : '';
        $clean_data['contact_name']    = isset($data['contact_name'])    ? sanitize_text_field(wp_unslash($data['contact_name']))    : '';
        $clean_data['logo_id']         = isset($data['logo_id'])         ? absint(wp_unslash($data['logo_id']))                      : 0;
        $clean_data['logo_url']        = isset($data['logo_url'])        ? esc_url_raw(wp_unslash($data['logo_url']))                : '';
        $clean_data['tax_id']          = isset($data['tax_id'])          ? sanitize_text_field(wp_unslash($data['tax_id']))          : '';
        $clean_data['registration_no'] = isset($data['registration_no']) ? sanitize_text_field(wp_unslash($data['registration_no'])) : '';
        $clean_data['website']         = isset($data['website'])         ? esc_url_raw(wp_unslash($data['website']))                 : '';

        update_option(self::OPT_COMPANY, $clean_data);
    }

    private function save_whatsapp(array $data) {
        $clean_data = array();
        $clean_data['enabled']       = isset($data['wa_enabled']) ? 1 : 0;
        $clean_data['phone_number']  = isset($data['wa_phone_number'])  ? sanitize_text_field(wp_unslash($data['wa_phone_number']))    : '';
        $clean_data['default_msg']   = isset($data['wa_default_msg'])   ? sanitize_textarea_field(wp_unslash($data['wa_default_msg'])) : '';
        $clean_data['button_text']   = isset($data['wa_button_text'])   ? sanitize_text_field(wp_unslash($data['wa_button_text']))     : '';
        $clean_data['display_style'] = isset($data['wa_display_style']) ? sanitize_key(wp_unslash($data['wa_display_style']))          : 'button';
        $clean_data['position']      = isset($data['wa_position'])      ? sanitize_key(wp_unslash($data['wa_position']))               : 'bottom-right';

        if (!in_array($clean_data['display_style'], array('button', 'floating', 'link'), true)) {
            $clean_data['display_style'] = 'button';
        }
        if (!in_array($clean_data['position'], array('bottom-right', 'bottom-left'), true)) {
            $clean_data['position'] = 'bottom-right';
        }

        $clean_data['phone_number'] = (string) preg_replace('/[^\d+]/', '', $clean_data['phone_number']);

        update_option(self::OPT_WHATSAPP, $clean_data);
    }

    private function save_banking(array $data) {
        $clean_data = array();
        $clean_data['enabled']          = isset($data['bank_enabled']) ? 1 : 0;
        $clean_data['bank_name']        = isset($data['bank_name'])        ? sanitize_text_field(wp_unslash($data['bank_name']))          : '';
        $clean_data['account_name']     = isset($data['account_name'])     ? sanitize_text_field(wp_unslash($data['account_name']))       : '';
        $clean_data['account_number']   = isset($data['account_number'])   ? sanitize_text_field(wp_unslash($data['account_number']))     : '';
        $clean_data['iban']             = isset($data['iban'])             ? sanitize_text_field(wp_unslash($data['iban']))                : '';
        $clean_data['swift_bic']        = isset($data['swift_bic'])        ? sanitize_text_field(wp_unslash($data['swift_bic']))          : '';
        $clean_data['sort_code']        = isset($data['sort_code'])        ? sanitize_text_field(wp_unslash($data['sort_code']))          : '';
        $clean_data['branch_address']   = isset($data['branch_address'])   ? sanitize_textarea_field(wp_unslash($data['branch_address'])) : '';
        $clean_data['reference_prefix'] = isset($data['reference_prefix']) ? sanitize_text_field(wp_unslash($data['reference_prefix']))   : '';
        $clean_data['instructions']     = isset($data['bank_instructions']) ? wp_kses_post(wp_unslash($data['bank_instructions']))        : '';

        $clean_data['iban'] = strtoupper((string) preg_replace('/\s+/', '', (string) $clean_data['iban']));

        update_option(self::OPT_BANKING, $clean_data);
    }

    private function save_revolut(array $data) {
        $clean_data = array();
        $clean_data['enabled']      = isset($data['rev_enabled']) ? 1 : 0;
        $clean_data['revolut_name'] = isset($data['revolut_name']) ? sanitize_text_field(wp_unslash($data['revolut_name'])) : '';
        $clean_data['revolut_tag']  = isset($data['revolut_tag'])  ? sanitize_text_field(wp_unslash($data['revolut_tag']))  : '';
        $clean_data['revolut_iban'] = isset($data['revolut_iban']) ? sanitize_text_field(wp_unslash($data['revolut_iban'])) : '';
        $clean_data['revolut_link'] = isset($data['revolut_link']) ? esc_url_raw(wp_unslash($data['revolut_link']))         : '';
        $clean_data['qr_code_id']   = isset($data['qr_code_id'])   ? absint(wp_unslash($data['qr_code_id']))                   : 0;
        $clean_data['qr_code_url']  = isset($data['qr_code_url'])  ? esc_url_raw(wp_unslash($data['qr_code_url']))         : '';
        $clean_data['instructions'] = isset($data['rev_instructions']) ? wp_kses_post(wp_unslash($data['rev_instructions'])) : '';

        $clean_data['revolut_iban'] = strtoupper((string) preg_replace('/\s+/', '', (string) $clean_data['revolut_iban']));

        update_option(self::OPT_REVOLUT, $clean_data);
    }

    /* ═══════════════════════════════════════════════════════════════
       RENDER ADMIN PAGE
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Render the settings tab content within the main Settings page.
     */
    public static function render_settings_tab() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
        $active_subtab = (isset($_GET['subtab']) ? sanitize_key(wp_unslash($_GET['subtab'])) : '') ?: 'company';
        $company  = self::get_company();
        $whatsapp = self::get_whatsapp();
        $banking  = self::get_banking();
        $revolut  = self::get_revolut();

        $tabs = array(
            'company'    => array( 'icon' => 'building',  'label' => __( 'Company', 'modern-hotel-booking' ) ),
            'whatsapp'   => array( 'icon' => 'phone',     'label' => __( 'WhatsApp', 'modern-hotel-booking' ) ),
            'banking'    => array( 'icon' => 'money-alt', 'label' => __( 'Bank Transfer', 'modern-hotel-booking' ) ),
            'revolut'    => array( 'icon' => 'money',     'label' => __( 'Revolut', 'modern-hotel-booking' ) ),
            'shortcodes' => array( 'icon' => 'shortcode', 'label' => __( 'Shortcodes & Blocks', 'modern-hotel-booking' ) ),
        );
        ?>
        <div class="mhbo-business-info-content">
            <h1 style="margin-bottom: 25px; font-weight: 800; color: #1a3b5d;"><?php esc_html_e( 'Business Information', 'modern-hotel-booking' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Configure your company details, contact info, and payment methods. All fields are optional.', 'modern-hotel-booking' ); ?>
            </p>

            <h2 class="nav-tab-wrapper mhbo-tabs" style="margin-bottom: 20px;">
                <?php foreach ( $tabs as $slug => $tab_info ) :
                    $tab_url = add_query_arg( array( 'tab' => 'business', 'subtab' => $slug ), admin_url( 'admin.php?page=mhbo-settings' ) );
                ?>
                    <a href="<?php echo esc_url( $tab_url ); ?>"
                       class="nav-tab <?php echo esc_attr( $slug === $active_subtab ? 'nav-tab-active' : '' ); ?>">
                        <span class="dashicons dashicons-<?php echo esc_attr( $tab_info['icon'] ); ?>"></span>
                        <?php echo esc_html( $tab_info['label'] ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <div class="mhbo-tab-content">
                <input type="hidden" name="mhbo_business_subtab" value="<?php echo esc_attr( (string) $active_subtab ); ?>">
                <?php
                switch ( $active_subtab ) {
                    case 'company':
                        self::get_instance()->render_tab_company( $company );
                        break;
                    case 'whatsapp':
                        self::get_instance()->render_tab_whatsapp( $whatsapp );
                        break;
                    case 'banking':
                        self::get_instance()->render_tab_banking( $banking );
                        break;
                    case 'revolut':
                        self::get_instance()->render_tab_revolut( $revolut );
                        break;
                    case 'shortcodes':
                        self::get_instance()->render_tab_shortcodes();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array $data Company info values.
     */
    private function render_tab_company( $data ) {
        ?>

<table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="company_name"><?php esc_html_e( 'Company Name', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="company_name" name="company_name" value="<?php echo esc_attr( $data['company_name'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="contact_name"><?php esc_html_e( 'Contact Name', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="contact_name" name="contact_name" value="<?php echo esc_attr( $data['contact_name'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="address_line_1"><?php esc_html_e( 'Address Line 1', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="address_line_1" name="address_line_1" value="<?php echo esc_attr( $data['address_line_1'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="address_line_2"><?php esc_html_e( 'Address Line 2', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="address_line_2" name="address_line_2" value="<?php echo esc_attr( $data['address_line_2'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="city"><?php esc_html_e( 'City', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="city" name="city" value="<?php echo esc_attr( $data['city'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="state"><?php esc_html_e( 'State / Region', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="state" name="state" value="<?php echo esc_attr( $data['state'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="postcode"><?php esc_html_e( 'Postcode / ZIP', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="postcode" name="postcode" value="<?php echo esc_attr( $data['postcode'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="country"><?php esc_html_e( 'Country', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="country" name="country" value="<?php echo esc_attr( $data['country'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="telephone"><?php esc_html_e( 'Telephone', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="tel" id="telephone" name="telephone" value="<?php echo esc_attr( $data['telephone'] ); ?>" class="regular-text" placeholder="+1 234 567 8900" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email"><?php esc_html_e( 'Email', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="email" id="email" name="email" value="<?php echo esc_attr( $data['email'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="website"><?php esc_html_e( 'Website URL', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="url" id="website" name="website" value="<?php echo esc_url( $data['website'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tax_id"><?php esc_html_e( 'Tax / VAT ID', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="tax_id" name="tax_id" value="<?php echo esc_attr( $data['tax_id'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="registration_no"><?php esc_html_e( 'Registration No.', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="registration_no" name="registration_no" value="<?php echo esc_attr( $data['registration_no'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Company Logo', 'modern-hotel-booking' ); ?></label></th>
                        <td><?php $this->render_media_field( 'mhbo_logo', (int) $data['logo_id'], (string) $data['logo_url'], __( 'Select Logo', 'modern-hotel-booking' ) ); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php
    }

    /** @param array $data */
    private function render_tab_whatsapp( $data ) {
        ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable WhatsApp', 'modern-hotel-booking' ); ?></th>
                        <td><label><input type="checkbox" name="wa_enabled" value="1" <?php checked( $data['enabled'], 1 ); ?> /> <?php esc_html_e( 'Enable WhatsApp contact button', 'modern-hotel-booking' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_phone_number"><?php esc_html_e( 'Phone Number', 'modern-hotel-booking' ); ?></label></th>
                        <td>
                            <input type="text" id="wa_phone_number" name="wa_phone_number" value="<?php echo esc_attr( $data['phone_number'] ); ?>" class="regular-text" placeholder="+34612345678" />
                            <p class="description"><?php esc_html_e( 'International format with country code.', 'modern-hotel-booking' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_default_msg"><?php esc_html_e( 'Default Message', 'modern-hotel-booking' ); ?></label></th>
                        <td><textarea id="wa_default_msg" name="wa_default_msg" rows="3" class="large-text"><?php echo esc_textarea( $data['default_msg'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_button_text"><?php esc_html_e( 'Button Text', 'modern-hotel-booking' ); ?></label></th>
                        <td><input type="text" id="wa_button_text" name="wa_button_text" value="<?php echo esc_attr( $data['button_text'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_display_style"><?php esc_html_e( 'Display Style', 'modern-hotel-booking' ); ?></label></th>
                        <td>
                            <select id="wa_display_style" name="wa_display_style">
                                <option value="button" <?php selected( $data['display_style'], 'button' ); ?>><?php esc_html_e( 'Inline Button', 'modern-hotel-booking' ); ?></option>
                                <option value="floating" <?php selected( $data['display_style'], 'floating' ); ?>><?php esc_html_e( 'Floating Button', 'modern-hotel-booking' ); ?></option>
                                <option value="link" <?php selected( $data['display_style'], 'link' ); ?>><?php esc_html_e( 'Text Link', 'modern-hotel-booking' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wa_position"><?php esc_html_e( 'Floating Position', 'modern-hotel-booking' ); ?></label></th>
                        <td>
                            <select id="wa_position" name="wa_position">
                                <option value="bottom-right" <?php selected( $data['position'], 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'modern-hotel-booking' ); ?></option>
                                <option value="bottom-left" <?php selected( $data['position'], 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'modern-hotel-booking' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php
    }

    /** @param array $data */
    private function render_tab_banking( $data ) {
        ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable Bank Transfer', 'modern-hotel-booking' ); ?></th>
                        <td><label><input type="checkbox" name="bank_enabled" value="1" <?php checked( $data['enabled'], 1 ); ?> /> <?php esc_html_e( 'Show bank transfer details to guests', 'modern-hotel-booking' ); ?></label></td>
                    </tr>
                    <tr><th scope="row"><label for="bank_name"><?php esc_html_e( 'Bank Name', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="bank_name" name="bank_name" value="<?php echo esc_attr( $data['bank_name'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="account_name"><?php esc_html_e( 'Account Holder Name', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="account_name" name="account_name" value="<?php echo esc_attr( $data['account_name'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="account_number"><?php esc_html_e( 'Account Number', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="account_number" name="account_number" value="<?php echo esc_attr( $data['account_number'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="iban"><?php esc_html_e( 'IBAN', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="iban" name="iban" value="<?php echo esc_attr( $data['iban'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="swift_bic"><?php esc_html_e( 'SWIFT / BIC', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="swift_bic" name="swift_bic" value="<?php echo esc_attr( $data['swift_bic'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="sort_code"><?php esc_html_e( 'Sort Code', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="sort_code" name="sort_code" value="<?php echo esc_attr( $data['sort_code'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="branch_address"><?php esc_html_e( 'Branch Address', 'modern-hotel-booking' ); ?></label></th><td><textarea id="branch_address" name="branch_address" rows="3" class="large-text"><?php echo esc_textarea( $data['branch_address'] ); ?></textarea></td></tr>
                    <tr>
                        <th scope="row"><label for="reference_prefix"><?php esc_html_e( 'Reference Prefix', 'modern-hotel-booking' ); ?></label></th>
                        <td>
                            <input type="text" id="reference_prefix" name="reference_prefix" value="<?php echo esc_attr( $data['reference_prefix'] ); ?>" class="regular-text" placeholder="BOOKING-" />
                            <p class="description"><?php esc_html_e( 'e.g. "BOOKING-" produces "BOOKING-1234".', 'modern-hotel-booking' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bank_instructions"><?php esc_html_e( 'Payment Instructions', 'modern-hotel-booking' ); ?></label></th>
                        <td><?php wp_editor( $data['instructions'], 'bank_instructions', array( 'textarea_name' => 'bank_instructions', 'textarea_rows' => 5, 'media_buttons' => false, 'teeny' => true ) ); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php
    }

    /** @param array $data */
    private function render_tab_revolut( $data ) {
        ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable Revolut', 'modern-hotel-booking' ); ?></th>
                        <td><label><input type="checkbox" name="rev_enabled" value="1" <?php checked( $data['enabled'], 1 ); ?> /> <?php esc_html_e( 'Show Revolut payment option', 'modern-hotel-booking' ); ?></label></td>
                    </tr>
                    <tr><th scope="row"><label for="revolut_name"><?php esc_html_e( 'Revolut Name', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="revolut_name" name="revolut_name" value="<?php echo esc_attr( $data['revolut_name'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="revolut_tag"><?php esc_html_e( 'Revolut Tag', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="revolut_tag" name="revolut_tag" value="<?php echo esc_attr( $data['revolut_tag'] ); ?>" class="regular-text" placeholder="@yourname" /></td></tr>
                    <tr><th scope="row"><label for="revolut_iban"><?php esc_html_e( 'Revolut IBAN', 'modern-hotel-booking' ); ?></label></th><td><input type="text" id="revolut_iban" name="revolut_iban" value="<?php echo esc_attr( $data['revolut_iban'] ); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row"><label for="revolut_link"><?php esc_html_e( 'Revolut.me Link', 'modern-hotel-booking' ); ?></label></th><td><input type="url" id="revolut_link" name="revolut_link" value="<?php echo esc_url( $data['revolut_link'] ); ?>" class="regular-text" placeholder="https://revolut.me/yourname" /></td></tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'QR Code', 'modern-hotel-booking' ); ?></label></th>
                        <td><?php $this->render_media_field( 'mhbo_qr_code', (int) $data['qr_code_id'], (string) $data['qr_code_url'], __( 'Select QR Code', 'modern-hotel-booking' ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rev_instructions"><?php esc_html_e( 'Payment Instructions', 'modern-hotel-booking' ); ?></label></th>
                        <td><?php wp_editor( $data['instructions'], 'rev_instructions', array( 'textarea_name' => 'rev_instructions', 'textarea_rows' => 5, 'media_buttons' => false, 'teeny' => true ) ); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php
    }

    private function render_tab_shortcodes() {
        ?>
        <div class="mhbo-shortcodes-reference">
            <h2><?php esc_html_e( 'Available Shortcodes', 'modern-hotel-booking' ); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e( 'Shortcode', 'modern-hotel-booking' ); ?></th><th><?php esc_html_e( 'Description', 'modern-hotel-booking' ); ?></th><th><?php esc_html_e( 'Attributes', 'modern-hotel-booking' ); ?></th></tr></thead>
                <tbody>
                    <tr><td><code>[mhbo_company_info]</code></td><td><?php esc_html_e( 'Company details and logo.', 'modern-hotel-booking' ); ?></td><td><code>show_logo</code> <code>show_address</code> <code>show_contact</code> <code>show_registration</code> <code>layout</code></td></tr>
                    <tr><td><code>[mhbo_whatsapp]</code></td><td><?php esc_html_e( 'WhatsApp button or link.', 'modern-hotel-booking' ); ?></td><td><code>style</code> <code>text</code> <code>message</code></td></tr>
                    <tr><td><code>[mhbo_banking_details]</code></td><td><?php esc_html_e( 'Bank transfer details.', 'modern-hotel-booking' ); ?></td><td><code>show_instructions</code> <code>booking_id</code> <code>layout</code></td></tr>
                    <tr><td><code>[mhbo_revolut_details]</code></td><td><?php esc_html_e( 'Revolut payment info.', 'modern-hotel-booking' ); ?></td><td><code>show_qr</code> <code>show_link</code> <code>layout</code></td></tr>
                    <tr><td><code>[mhbo_business_card]</code></td><td><?php esc_html_e( 'Combined card.', 'modern-hotel-booking' ); ?></td><td><code>sections</code></td></tr>
                    <tr><td><code>[mhbo_payment_methods]</code></td><td><?php esc_html_e( 'All payment methods.', 'modern-hotel-booking' ); ?></td><td><code>booking_id</code></td></tr>
                </tbody>
            </table>
            <h2 style="margin-top:30px;"><?php esc_html_e( 'Gutenberg Blocks', 'modern-hotel-booking' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Search "Hotel" in the block inserter.', 'modern-hotel-booking' ); ?></p>
        </div>
        <?php
    }

    /**
     * Reusable media upload field.
     *
     * @param string $prefix    Field prefix for IDs.
     * @param int    $image_id  Attachment ID.
     * @param string $image_url Attachment URL.
     * @param string $title     Media modal title.
     */
    private function render_media_field( $prefix, $image_id, $image_url, $title ) {
        $has_image = ! empty( $image_url );
        $id_field  = str_replace( 'mhbo_', '', $prefix ) . '_id';
        $url_field = str_replace( 'mhbo_', '', $prefix ) . '_url';
        ?>
        <div class="mhbo-media-upload-wrap">
            <input type="hidden" name="<?php echo esc_attr( (string) $id_field ); ?>" id="<?php echo esc_attr( $prefix ); ?>_id" value="<?php echo esc_attr( (string) $image_id ); ?>" />
            <input type="hidden" name="<?php echo esc_attr( (string) $url_field ); ?>" id="<?php echo esc_attr( $prefix ); ?>_url" value="<?php echo esc_url( $image_url ); ?>" />
            <div id="<?php echo esc_attr( $prefix ); ?>_preview" class="mhbo-image-preview" <?php echo esc_attr( $has_image ? '' : 'style=display:none;' ); ?>>
                <?php if ( $has_image ) : ?>
                    <img src="<?php echo esc_url( $image_url ); ?>" alt="" style="max-width:200px;height:auto;" />
                <?php endif; ?>
            </div>
            <button type="button" class="button mhbo-upload-btn" data-target-id="<?php echo esc_attr( $prefix ); ?>_id" data-target-url="<?php echo esc_attr( $prefix ); ?>_url" data-preview="<?php echo esc_attr( $prefix ); ?>_preview" data-title="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $title ); ?></button>
            <button type="button" class="button mhbo-remove-btn" data-target-id="<?php echo esc_attr( $prefix ); ?>_id" data-target-url="<?php echo esc_attr( $prefix ); ?>_url" data-preview="<?php echo esc_attr( $prefix ); ?>_preview" <?php echo esc_attr( $has_image ? '' : 'style=display:none;' ); ?>><?php esc_html_e( 'Remove', 'modern-hotel-booking' ); ?></button>
        </div>
        <?php
    }
}
