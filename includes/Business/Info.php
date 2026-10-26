<?php
declare(strict_types=1);

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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;

class Info {

	/** @var self|null */
	private static ?self $instance = null;

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
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/*
	═══════════════════════════════════════════════════════════════
		ADMIN MENU
		═══════════════════════════════════════════════════════════════ */

/*
	═══════════════════════════════════════════════════════════════
		ADMIN ASSETS
		═══════════════════════════════════════════════════════════════ */

	/**
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( false === strpos( $hook, 'mhbo-settings' ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'business' !== $tab ) {
			return;
		}

		wp_enqueue_script( 'mhbo-admin-business', MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-business.js', array(), MHBO_VERSION, true );

		wp_enqueue_media();

		wp_enqueue_style(
			'mhbo-admin-business',
			MHBO_PLUGIN_URL . 'assets/css/mhbo-admin-business.css',
			array(),
			MHBO_VERSION
		);

		wp_enqueue_script(
			'mhbo-admin-media-upload',
			MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-media-upload.js',
			array(),
			MHBO_VERSION,
			true
		);
	}

	/*
	═══════════════════════════════════════════════════════════════
		DEFAULT VALUES
		═══════════════════════════════════════════════════════════════ */

	/**
	 * @return array{
	 *   company_name: string,
	 *   address_line_1: string,
	 *   address_line_2: string,
	 *   city: string,
	 *   state: string,
	 *   postcode: string,
	 *   country: string,
	 *   telephone: string,
	 *   email: string,
	 *   contact_name: string,
	 *   logo_id: int,
	 *   logo_url: string,
	 *   tax_id: string,
	 *   registration_no: string,
	 *   website: string,
	 *   google_place_id?: string,
	 *   google_maps_url?: string,
	 *   show_map_in_email?: int
	 * }
	 */
	public static function get_company_defaults(): array {
		return array(
			'company_name'      => '',
			'address_line_1'    => '',
			'address_line_2'    => '',
			'city'              => '',
			'state'             => '',
			'postcode'          => '',
			'country'           => '',
			'telephone'         => '',
			'email'             => '',
			'contact_name'      => '',
			'logo_id'           => 0,
			'logo_url'          => '',
			'tax_id'            => '',
			'registration_no'   => '',
			'website'           => '',
			
		);
	}

	/**
	 * @return array{
	 *   enabled: int,
	 *   phone_number: string,
	 *   default_msg: string,
	 *   button_text: string,
	 *   display_style: string,
	 *   position: string
	 * }
	 */
	public static function get_whatsapp_defaults(): array {
		return array(
			'enabled'       => 0,
			'phone_number'  => '',
			'default_msg'   => '',
			'button_text'   => 'Chat on WhatsApp',
			'display_style' => 'button',
			'position'      => 'bottom-right',
		);
	}

	/**
	 * @return array{
	 *   enabled: int,
	 *   bank_name: string,
	 *   account_name: string,
	 *   account_number: string,
	 *   iban: string,
	 *   swift_bic: string,
	 *   sort_code: string,
	 *   branch_address: string,
	 *   reference_prefix: string,
	 *   instructions: string
	 * }
	 */
	public static function get_banking_defaults(): array {
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

	/**
	 * @return array{
	 *   enabled: int,
	 *   revolut_name: string,
	 *   revolut_tag: string,
	 *   revolut_iban: string,
	 *   revolut_link: string,
	 *   qr_code_id: int,
	 *   qr_code_url: string,
	 *   instructions: string
	 * }
	 */
	public static function get_revolut_defaults(): array {
		return array(
			'enabled'      => 0,
			'revolut_name' => '',
			'revolut_tag'  => '',
			'revolut_iban' => '',
			'revolut_link' => '',
			'qr_code_id'   => 0,
			'qr_code_url'  => '',
			'instructions' => '',
		);
	}

/*
	═══════════════════════════════════════════════════════════════
		OPTION GETTERS
		═══════════════════════════════════════════════════════════════ */

	/** @return array{company_name: string, address_line_1: string, address_line_2: string, city: string, state: string, postcode: string, country: string, telephone: string, email: string, contact_name: string, logo_id: int, logo_url: string, tax_id: string, registration_no: string, website: string, google_place_id?: string, google_maps_url?: string, show_map_in_email?: int} */
	public static function get_company(): array {
		/** @var array{company_name: string, address_line_1: string, address_line_2: string, city: string, state: string, postcode: string, country: string, telephone: string, email: string, contact_name: string, logo_id: int, logo_url: string, tax_id: string, registration_no: string, website: string, google_place_id?: string, google_maps_url?: string, show_map_in_email?: int} $result */
		$result = wp_parse_args( get_option( self::OPT_COMPANY, array() ), self::get_company_defaults() );
		return $result;
	}

	/** @return array{enabled: int, phone_number: string, default_msg: string, button_text: string, display_style: string, position: string} */
	public static function get_whatsapp(): array {
		/** @var array{enabled: int, phone_number: string, default_msg: string, button_text: string, display_style: string, position: string} $result */
		$result = wp_parse_args( get_option( self::OPT_WHATSAPP, array() ), self::get_whatsapp_defaults() );
		return $result;
	}

	/** @return array{enabled: int, bank_name: string, account_name: string, account_number: string, iban: string, swift_bic: string, sort_code: string, branch_address: string, reference_prefix: string, instructions: string} */
	public static function get_banking(): array {
		/** @var array{enabled: int, bank_name: string, account_name: string, account_number: string, iban: string, swift_bic: string, sort_code: string, branch_address: string, reference_prefix: string, instructions: string} $result */
		$result = wp_parse_args( get_option( self::OPT_BANKING, array() ), self::get_banking_defaults() );
		return $result;
	}

	/** @return array{enabled: int, revolut_name: string, revolut_tag: string, revolut_iban: string, revolut_link: string, qr_code_id: int, qr_code_url: string, instructions: string} */
	public static function get_revolut(): array {
		/** @var array{enabled: int, revolut_name: string, revolut_tag: string, revolut_iban: string, revolut_link: string, qr_code_id: int, qr_code_url: string, instructions: string} $result */
		$result = wp_parse_args( get_option( self::OPT_REVOLUT, array() ), self::get_revolut_defaults() );
		return $result;
	}

/*
	═══════════════════════════════════════════════════════════════
		SAVE HANDLER
		═══════════════════════════════════════════════════════════════ */

	/**
	 * @param array<string, mixed> $data
	 */
	public function handle_save( array $data ): void {
		$tab        = isset( $data['mhbo_business_subtab'] ) ? sanitize_key( wp_unslash( $data['mhbo_business_subtab'] ) ) : 'company';
		$valid_tabs = array( 'company', 'whatsapp', 'banking', 'revolut', 'social' );

		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'company';
		}

		switch ( $tab ) {
			case 'company':
				$this->save_company( $data );
				break;
			case 'whatsapp':
				$this->save_whatsapp( $data );
				break;
			case 'banking':
				$this->save_banking( $data );
				break;
			case 'revolut':
				$this->save_revolut( $data );
				break;
			
		}

		add_settings_error( 'mhbo_settings', 'saved', __( 'Business settings saved successfully.', 'modern-hotel-booking' ), 'success' );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading page slug for redirect URL only, not processing form data.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'mhbo-settings';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => $current_page,
					'tab'              => 'business',
					'subtab'           => $tab,
					'settings-updated' => 'true',
					'mhbo_updated'     => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_company( array $data ): void {
		$clean_data = array();
		// 2026/WP Repo Compliance: Explicit unslashing and sanitization per-key
		$clean_data['company_name']    = isset( $data['company_name'] ) ? sanitize_text_field( wp_unslash( $data['company_name'] ) ) : '';
		$clean_data['address_line_1']  = isset( $data['address_line_1'] ) ? sanitize_text_field( wp_unslash( $data['address_line_1'] ) ) : '';
		$clean_data['address_line_2']  = isset( $data['address_line_2'] ) ? sanitize_text_field( wp_unslash( $data['address_line_2'] ) ) : '';
		$clean_data['city']            = isset( $data['city'] ) ? sanitize_text_field( wp_unslash( $data['city'] ) ) : '';
		$clean_data['state']           = isset( $data['state'] ) ? sanitize_text_field( wp_unslash( $data['state'] ) ) : '';
		$clean_data['postcode']        = isset( $data['postcode'] ) ? sanitize_text_field( wp_unslash( $data['postcode'] ) ) : '';
		$clean_data['country']         = isset( $data['country'] ) ? sanitize_text_field( wp_unslash( $data['country'] ) ) : '';
		$clean_data['telephone']       = isset( $data['telephone'] ) ? sanitize_text_field( wp_unslash( $data['telephone'] ) ) : '';
		$clean_data['email']           = isset( $data['email'] ) ? sanitize_email( wp_unslash( $data['email'] ) ) : '';
		$clean_data['contact_name']    = isset( $data['contact_name'] ) ? sanitize_text_field( wp_unslash( $data['contact_name'] ) ) : '';
		$clean_data['logo_id']         = isset( $data['logo_id'] ) ? absint( wp_unslash( $data['logo_id'] ) ) : 0;
		$clean_data['logo_url']        = isset( $data['logo_url'] ) ? esc_url_raw( wp_unslash( $data['logo_url'] ) ) : '';
		$clean_data['tax_id']          = isset( $data['tax_id'] ) ? sanitize_text_field( wp_unslash( $data['tax_id'] ) ) : '';
		$clean_data['registration_no'] = isset( $data['registration_no'] ) ? sanitize_text_field( wp_unslash( $data['registration_no'] ) ) : '';
		$clean_data['website']         = isset( $data['website'] ) ? esc_url_raw( wp_unslash( $data['website'] ) ) : '';

update_option( self::OPT_COMPANY, $clean_data );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_whatsapp( array $data ): void {
		$clean_data                  = array();
		$clean_data['enabled']       = isset( $data['wa_enabled'] ) ? 1 : 0;
		$clean_data['phone_number']  = isset( $data['wa_phone_number'] ) ? sanitize_text_field( wp_unslash( $data['wa_phone_number'] ) ) : '';
		$clean_data['default_msg']   = isset( $data['wa_default_msg'] ) ? sanitize_textarea_field( wp_unslash( $data['wa_default_msg'] ) ) : '';
		$clean_data['button_text']   = isset( $data['wa_button_text'] ) ? sanitize_text_field( wp_unslash( $data['wa_button_text'] ) ) : '';
		$clean_data['display_style'] = isset( $data['wa_display_style'] ) ? sanitize_key( wp_unslash( $data['wa_display_style'] ) ) : 'button';
		$clean_data['position']      = isset( $data['wa_position'] ) ? sanitize_key( wp_unslash( $data['wa_position'] ) ) : 'bottom-right';

		if ( ! in_array( $clean_data['display_style'], array( 'button', 'floating', 'link' ), true ) ) {
			$clean_data['display_style'] = 'button';
		}
		if ( ! in_array( $clean_data['position'], array( 'bottom-right', 'bottom-left' ), true ) ) {
			$clean_data['position'] = 'bottom-right';
		}

		$clean_data['phone_number'] = (string) preg_replace( '/[^\d+]/', '', $clean_data['phone_number'] );

		update_option( self::OPT_WHATSAPP, $clean_data );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_banking( array $data ): void {
		$clean_data                     = array();
		$clean_data['enabled']          = isset( $data['bank_enabled'] ) ? 1 : 0;
		$clean_data['bank_name']        = isset( $data['bank_name'] ) ? sanitize_text_field( wp_unslash( $data['bank_name'] ) ) : '';
		$clean_data['account_name']     = isset( $data['account_name'] ) ? sanitize_text_field( wp_unslash( $data['account_name'] ) ) : '';
		$clean_data['account_number']   = isset( $data['account_number'] ) ? sanitize_text_field( wp_unslash( $data['account_number'] ) ) : '';
		$clean_data['iban']             = isset( $data['iban'] ) ? sanitize_text_field( wp_unslash( $data['iban'] ) ) : '';
		$clean_data['swift_bic']        = isset( $data['swift_bic'] ) ? sanitize_text_field( wp_unslash( $data['swift_bic'] ) ) : '';
		$clean_data['sort_code']        = isset( $data['sort_code'] ) ? sanitize_text_field( wp_unslash( $data['sort_code'] ) ) : '';
		$clean_data['branch_address']   = isset( $data['branch_address'] ) ? sanitize_textarea_field( wp_unslash( $data['branch_address'] ) ) : '';
		$clean_data['reference_prefix'] = isset( $data['reference_prefix'] ) ? sanitize_text_field( wp_unslash( $data['reference_prefix'] ) ) : '';
		$clean_data['instructions']     = isset( $data['bank_instructions'] ) ? wp_kses_post( wp_unslash( $data['bank_instructions'] ) ) : '';

		$clean_data['iban'] = strtoupper( (string) preg_replace( '/\s+/', '', (string) $clean_data['iban'] ) );

		update_option( self::OPT_BANKING, $clean_data );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_revolut( array $data ): void {
		$clean_data                 = array();
		$clean_data['enabled']      = isset( $data['rev_enabled'] ) ? 1 : 0;
		$clean_data['revolut_name'] = isset( $data['revolut_name'] ) ? sanitize_text_field( wp_unslash( $data['revolut_name'] ) ) : '';
		$clean_data['revolut_tag']  = isset( $data['revolut_tag'] ) ? sanitize_text_field( wp_unslash( $data['revolut_tag'] ) ) : '';
		$clean_data['revolut_iban'] = isset( $data['revolut_iban'] ) ? sanitize_text_field( wp_unslash( $data['revolut_iban'] ) ) : '';
		$clean_data['revolut_link'] = isset( $data['revolut_link'] ) ? esc_url_raw( wp_unslash( $data['revolut_link'] ) ) : '';
		$clean_data['qr_code_id']   = isset( $data['qr_code_id'] ) ? absint( wp_unslash( $data['qr_code_id'] ) ) : 0;
		$clean_data['qr_code_url']  = isset( $data['qr_code_url'] ) ? esc_url_raw( wp_unslash( $data['qr_code_url'] ) ) : '';
		$clean_data['instructions'] = isset( $data['rev_instructions'] ) ? wp_kses_post( wp_unslash( $data['rev_instructions'] ) ) : '';

		$clean_data['revolut_iban'] = strtoupper( (string) preg_replace( '/\s+/', '', (string) $clean_data['revolut_iban'] ) );

		update_option( self::OPT_REVOLUT, $clean_data );
	}

/*
	═══════════════════════════════════════════════════════════════
		RENDER ADMIN PAGE
		═══════════════════════════════════════════════════════════════ */

	/**
	 * Render the settings tab content within the main Settings page.
	 */
	public static function render_settings_tab(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
		$active_subtab = ( isset( $_GET['subtab'] ) ? sanitize_key( wp_unslash( $_GET['subtab'] ) ) : '' ) ?: 'company';
		$company       = self::get_company();
		$whatsapp      = self::get_whatsapp();
		$banking       = self::get_banking();
		$revolut       = self::get_revolut();

		$tabs = array(
			'company'    => array(
				'icon'  => 'building',
				'label' => I18n::get_label( 'business_info_tab_company' ),
			),
			'whatsapp'   => array(
				'icon'  => 'phone',
				'label' => I18n::get_label( 'business_info_tab_whatsapp' ),
			),
			'banking'    => array(
				'icon'  => 'money-alt',
				'label' => I18n::get_label( 'business_info_tab_banking' ),
			),
			'revolut'    => array(
				'icon'  => 'money',
				'label' => I18n::get_label( 'business_info_tab_revolut' ),
			),
			
			'shortcodes' => array(
				'icon'  => 'shortcode',
				'label' => I18n::get_label( 'business_info_tab_shortcodes' ),
			),
		);
		?>
		<div class="mhbo-business-info-content">
			<div style="margin-bottom: 20px;">
				<h2 style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;"><?php echo esc_html( I18n::get_label( 'business_info_title' ) ); ?></h2>
				<p class="mhbo-settings-section-desc"><?php echo esc_html( I18n::get_label( 'business_info_desc' ) ); ?></p>
			</div>

			<!-- Subtab Navigation Bar -->
			<div class="mhbo-subtabs-nav" style="display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
				<?php
				foreach ( $tabs as $slug => $tab_info ) :
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading page slug for tab URL only, not processing form data.
					$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'mhbo-settings';
					$tab_url      = add_query_arg(
						array(
							'tab'    => 'business',
							'subtab' => $slug,
						),
						admin_url( 'admin.php?page=' . $current_page ) 
					);
					$is_active    = ( $slug === $active_subtab );
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>"
						class="mhbo-subtab-btn <?php echo esc_attr( $is_active ? 'active' : '' ); ?>"
						style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 8px; font-size: 13.5px; font-weight: 600; text-decoration: none; transition: all 0.15s ease; <?php echo $is_active ? 'background: #2563eb; color: #ffffff; box-shadow: 0 2px 4px rgba(37,99,235,0.2);' : 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;'; ?>">
						<span class="dashicons dashicons-<?php echo esc_attr( $tab_info['icon'] ); ?>" style="font-size: 17px; width: 17px; height: 17px; margin-top: 1px;"></span>
						<span><?php echo esc_html( $tab_info['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

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
	 * @param array<string, mixed> $data Company info values.
	 */
	private function render_tab_company( array $data ): void {
		?>
		<div class="mhbo-settings-card-container">

			<!-- Card 1: Property Identity & Location -->
			<div class="mhbo-settings-section-card">
				<div class="mhbo-settings-section-header">
					<div class="mhbo-settings-section-title-wrap">
						<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-building"></span></div>
						<div>
							<h3 class="mhbo-settings-section-title"><?php echo esc_html( I18n::get_label( 'business_info_tab_company' ) ); ?></h3>
							<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Primary hotel identification, guest contact persona, and physical location.', 'modern-hotel-booking' ); ?></p>
						</div>
					</div>
				</div>

				<div class="mhbo-settings-grid mhbo-grid-2col">
					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="company_name"><?php echo esc_html( I18n::get_label( 'business_label_company_name' ) ); ?></label>
						<input type="text" id="company_name" name="company_name" value="<?php echo esc_attr( $data['company_name'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="contact_name"><?php echo esc_html( I18n::get_label( 'business_label_contact_name' ) ); ?></label>
						<input type="text" id="contact_name" name="contact_name" value="<?php echo esc_attr( $data['contact_name'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group mhbo-span-full">
						<label class="mhbo-form-label" for="address_line_1"><?php echo esc_html( I18n::get_label( 'business_label_address_1' ) ); ?></label>
						<input type="text" id="address_line_1" name="address_line_1" value="<?php echo esc_attr( $data['address_line_1'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group mhbo-span-full">
						<label class="mhbo-form-label" for="address_line_2"><?php echo esc_html( I18n::get_label( 'business_label_address_2' ) ); ?></label>
						<input type="text" id="address_line_2" name="address_line_2" value="<?php echo esc_attr( $data['address_line_2'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="city"><?php echo esc_html( I18n::get_label( 'business_label_city' ) ); ?></label>
						<input type="text" id="city" name="city" value="<?php echo esc_attr( $data['city'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="state"><?php echo esc_html( I18n::get_label( 'business_label_state' ) ); ?></label>
						<input type="text" id="state" name="state" value="<?php echo esc_attr( $data['state'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="postcode"><?php echo esc_html( I18n::get_label( 'business_label_postcode' ) ); ?></label>
						<input type="text" id="postcode" name="postcode" value="<?php echo esc_attr( $data['postcode'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="country"><?php echo esc_html( I18n::get_label( 'business_label_country' ) ); ?></label>
						<input type="text" id="country" name="country" value="<?php echo esc_attr( $data['country'] ); ?>" class="mhbo-form-input" />
					</div>
				</div>
			</div>

			<!-- Card 2: Contact, Web & Legal Registration -->
			<div class="mhbo-settings-section-card">
				<div class="mhbo-settings-section-header">
					<div class="mhbo-settings-section-title-wrap">
						<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-id-alt"></span></div>
						<div>
							<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Contact, Branding & Legal Registration', 'modern-hotel-booking' ); ?></h3>
							<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Official details rendered on invoices, email receipts, and booking summaries.', 'modern-hotel-booking' ); ?></p>
						</div>
					</div>
				</div>

				<div class="mhbo-settings-grid mhbo-grid-2col">
					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="telephone"><?php echo esc_html( I18n::get_label( 'business_label_telephone' ) ); ?></label>
						<input type="tel" id="telephone" name="telephone" value="<?php echo esc_attr( $data['telephone'] ); ?>" class="mhbo-form-input" placeholder="+1 234 567 8900" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="email"><?php echo esc_html( I18n::get_label( 'business_label_email' ) ); ?></label>
						<input type="email" id="email" name="email" value="<?php echo esc_attr( $data['email'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="website"><?php echo esc_html( I18n::get_label( 'business_label_website' ) ); ?></label>
						<input type="url" id="website" name="website" value="<?php echo esc_url( $data['website'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="tax_id"><?php echo esc_html( I18n::get_label( 'business_label_tax_id' ) ); ?></label>
						<input type="text" id="tax_id" name="tax_id" value="<?php echo esc_attr( $data['tax_id'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="registration_no"><?php echo esc_html( I18n::get_label( 'business_label_registration_no' ) ); ?></label>
						<input type="text" id="registration_no" name="registration_no" value="<?php echo esc_attr( $data['registration_no'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label"><?php echo esc_html( I18n::get_label( 'business_label_logo' ) ); ?></label>
						<?php $this->render_media_field( 'mhbo_logo', (int) $data['logo_id'], (string) $data['logo_url'], I18n::get_label( 'business_label_select_logo' ) ); ?>
					</div>
				</div>
			</div>

</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function render_tab_whatsapp( array $data ): void {
		$wa_enabled = ! empty( $data['enabled'] ) ? 1 : 0;
		?>
		<div class="mhbo-settings-card-container">
			<div class="mhbo-settings-section-card">
				<div class="mhbo-settings-section-header">
					<div class="mhbo-settings-section-title-wrap">
						<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-phone"></span></div>
						<div>
							<h3 class="mhbo-settings-section-title"><?php echo esc_html( I18n::get_label( 'business_info_tab_whatsapp' ) ); ?></h3>
							<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Configure real-time guest communication via WhatsApp floating buttons and inquiry links.', 'modern-hotel-booking' ); ?></p>
						</div>
					</div>
				</div>

				<div class="mhbo-settings-grid mhbo-grid-2col">
					<div class="mhbo-form-group mhbo-span-full">
						<div class="mhbo-switch-row">
							<div class="mhbo-switch-info">
								<span class="mhbo-switch-title"><?php echo esc_html( I18n::get_label( 'business_label_wa_enable' ) ); ?></span>
								<p class="mhbo-switch-desc"><?php echo esc_html( I18n::get_label( 'business_label_wa_enable_desc' ) ); ?></p>
							</div>
							<label class="mhbo-toggle-switch">
								<input type="hidden" name="wa_enabled" value="0">
								<input type="checkbox" id="wa_enabled" name="wa_enabled" value="1" <?php checked( 1, $wa_enabled ); ?> />
								<span class="mhbo-toggle-slider"></span>
							</label>
						</div>
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="wa_phone_number"><?php echo esc_html( I18n::get_label( 'business_label_wa_phone' ) ); ?></label>
						<input type="text" id="wa_phone_number" name="wa_phone_number" value="<?php echo esc_attr( $data['phone_number'] ); ?>" class="mhbo-form-input" placeholder="+34612345678" />
						<p class="mhbo-form-help"><?php echo esc_html( I18n::get_label( 'business_label_wa_phone_desc' ) ); ?></p>
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="wa_button_text"><?php echo esc_html( I18n::get_label( 'business_label_wa_btn' ) ); ?></label>
						<input type="text" id="wa_button_text" name="wa_button_text" value="<?php echo esc_attr( $data['button_text'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group mhbo-span-full">
						<label class="mhbo-form-label" for="wa_default_msg"><?php echo esc_html( I18n::get_label( 'business_label_wa_msg' ) ); ?></label>
						<textarea id="wa_default_msg" name="wa_default_msg" rows="3" class="mhbo-form-input"><?php echo esc_textarea( $data['default_msg'] ); ?></textarea>
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="wa_display_style"><?php echo esc_html( I18n::get_label( 'business_label_wa_style' ) ); ?></label>
						<select id="wa_display_style" name="wa_display_style" class="mhbo-form-input">
							<option value="button" <?php selected( $data['display_style'], 'button' ); ?>><?php echo esc_html( I18n::get_label( 'business_info_style_button' ) ); ?></option>
							<option value="floating" <?php selected( $data['display_style'], 'floating' ); ?>><?php echo esc_html( I18n::get_label( 'business_info_style_floating' ) ); ?></option>
							<option value="link" <?php selected( $data['display_style'], 'link' ); ?>><?php echo esc_html( I18n::get_label( 'business_info_style_link' ) ); ?></option>
						</select>
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="wa_position"><?php echo esc_html( I18n::get_label( 'business_label_wa_pos' ) ); ?></label>
						<select id="wa_position" name="wa_position" class="mhbo-form-input">
							<option value="bottom-right" <?php selected( $data['position'], 'bottom-right' ); ?>><?php echo esc_html( I18n::get_label( 'general_pos_bottom_right' ) ); ?></option>
							<option value="bottom-left" <?php selected( $data['position'], 'bottom-left' ); ?>><?php echo esc_html( I18n::get_label( 'general_pos_bottom_left' ) ); ?></option>
						</select>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function render_tab_banking( array $data ): void {
		$bank_enabled = ! empty( $data['enabled'] ) ? 1 : 0;
		?>
		<div class="mhbo-settings-card-container">
			<div class="mhbo-settings-section-card">
				<div class="mhbo-settings-section-header">
					<div class="mhbo-settings-section-title-wrap">
						<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-money-alt"></span></div>
						<div>
							<h3 class="mhbo-settings-section-title"><?php echo esc_html( I18n::get_label( 'business_info_tab_banking' ) ); ?></h3>
							<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Direct bank transfer details and wire instructions for manual offline booking settlements.', 'modern-hotel-booking' ); ?></p>
						</div>
					</div>
				</div>

				<div class="mhbo-settings-grid mhbo-grid-2col">
					<div class="mhbo-form-group mhbo-span-full">
						<div class="mhbo-switch-row">
							<div class="mhbo-switch-info">
								<span class="mhbo-switch-title"><?php echo esc_html( I18n::get_label( 'business_label_bank_enable' ) ); ?></span>
								<p class="mhbo-switch-desc"><?php echo esc_html( I18n::get_label( 'business_label_bank_enable_desc' ) ); ?></p>
							</div>
							<label class="mhbo-toggle-switch">
								<input type="hidden" name="bank_enabled" value="0">
								<input type="checkbox" id="bank_enabled" name="bank_enabled" value="1" <?php checked( 1, $bank_enabled ); ?> />
								<span class="mhbo-toggle-slider"></span>
							</label>
						</div>
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="bank_name"><?php echo esc_html( I18n::get_label( 'business_label_bank_name' ) ); ?></label>
						<input type="text" id="bank_name" name="bank_name" value="<?php echo esc_attr( $data['bank_name'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="account_name"><?php echo esc_html( I18n::get_label( 'business_label_bank_acc_name' ) ); ?></label>
						<input type="text" id="account_name" name="account_name" value="<?php echo esc_attr( $data['account_name'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="account_number"><?php echo esc_html( I18n::get_label( 'business_label_bank_acc_no' ) ); ?></label>
						<input type="text" id="account_number" name="account_number" value="<?php echo esc_attr( $data['account_number'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="iban"><?php echo esc_html( I18n::get_label( 'business_label_bank_iban' ) ); ?></label>
						<input type="text" id="iban" name="iban" value="<?php echo esc_attr( $data['iban'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="swift_bic"><?php echo esc_html( I18n::get_label( 'business_label_bank_swift' ) ); ?></label>
						<input type="text" id="swift_bic" name="swift_bic" value="<?php echo esc_attr( $data['swift_bic'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="sort_code"><?php echo esc_html( I18n::get_label( 'business_label_bank_sort' ) ); ?></label>
						<input type="text" id="sort_code" name="sort_code" value="<?php echo esc_attr( $data['sort_code'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group mhbo-span-full">
						<label class="mhbo-form-label" for="branch_address"><?php echo esc_html( I18n::get_label( 'business_label_bank_address' ) ); ?></label>
						<textarea id="branch_address" name="branch_address" rows="2" class="mhbo-form-input"><?php echo esc_textarea( $data['branch_address'] ); ?></textarea>
					</div>

					<div class="mhbo-form-group mhbo-span-full">
						<label class="mhbo-form-label" for="reference_prefix"><?php echo esc_html( I18n::get_label( 'business_label_bank_prefix' ) ); ?></label>
						<input type="text" id="reference_prefix" name="reference_prefix" value="<?php echo esc_attr( $data['reference_prefix'] ); ?>" class="mhbo-form-input" placeholder="BOOKING-" />
						<p class="mhbo-form-help"><?php echo esc_html( I18n::get_label( 'business_label_bank_prefix_desc' ) ); ?></p>
					</div>

					<div class="mhbo-form-group mhbo-span-full">
						<label class="mhbo-form-label" for="bank_instructions"><?php echo esc_html( I18n::get_label( 'business_label_bank_instr' ) ); ?></label>
						<?php
						wp_editor(
							$data['instructions'],
							'bank_instructions',
							array(
								'textarea_name' => 'bank_instructions',
								'textarea_rows' => 5,
								'media_buttons' => false,
								'teeny'         => true,
							) 
						);
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function render_tab_revolut( array $data ): void {
		$rev_enabled = ! empty( $data['enabled'] ) ? 1 : 0;
		?>
		<div class="mhbo-settings-card-container">
			<div class="mhbo-settings-section-card">
				<div class="mhbo-settings-section-header">
					<div class="mhbo-settings-section-title-wrap">
						<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-money"></span></div>
						<div>
							<h3 class="mhbo-settings-section-title"><?php echo esc_html( I18n::get_label( 'business_info_tab_revolut' ) ); ?></h3>
							<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Revolut payment link, Revtag, and instant QR code collection for seamless guest settlements.', 'modern-hotel-booking' ); ?></p>
						</div>
					</div>
				</div>

				<div class="mhbo-settings-grid mhbo-grid-2col">
					<div class="mhbo-form-group mhbo-span-full">
						<div class="mhbo-switch-row">
							<div class="mhbo-switch-info">
								<span class="mhbo-switch-title"><?php echo esc_html( I18n::get_label( 'business_label_rev_enable' ) ); ?></span>
								<p class="mhbo-switch-desc"><?php echo esc_html( I18n::get_label( 'business_label_rev_enable_desc' ) ); ?></p>
							</div>
							<label class="mhbo-toggle-switch">
								<input type="hidden" name="rev_enabled" value="0">
								<input type="checkbox" id="rev_enabled" name="rev_enabled" value="1" <?php checked( 1, $rev_enabled ); ?> />
								<span class="mhbo-toggle-slider"></span>
							</label>
						</div>
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="revolut_name"><?php echo esc_html( I18n::get_label( 'business_label_rev_name' ) ); ?></label>
						<input type="text" id="revolut_name" name="revolut_name" value="<?php echo esc_attr( $data['revolut_name'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="revolut_tag"><?php echo esc_html( I18n::get_label( 'business_label_rev_tag' ) ); ?></label>
						<input type="text" id="revolut_tag" name="revolut_tag" value="<?php echo esc_attr( $data['revolut_tag'] ); ?>" class="mhbo-form-input" placeholder="@yourname" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="revolut_iban"><?php echo esc_html( I18n::get_label( 'business_label_rev_iban' ) ); ?></label>
						<input type="text" id="revolut_iban" name="revolut_iban" value="<?php echo esc_attr( $data['revolut_iban'] ); ?>" class="mhbo-form-input" />
					</div>

					<div class="mhbo-form-group">
						<label class="mhbo-form-label" for="revolut_link"><?php echo esc_html( I18n::get_label( 'business_label_rev_link' ) ); ?></label>
						<input type="url" id="revolut_link" name="revolut_link" value="<?php echo esc_url( $data['revolut_link'] ); ?>" class="mhbo-form-input" placeholder="https://revolut.me/yourname" />
					</div>

					<div class="mhbo-form-group mhbo-span-full">
						<label class="mhbo-form-label"><?php echo esc_html( I18n::get_label( 'business_label_rev_qr' ) ); ?></label>
						<?php $this->render_media_field( 'mhbo_qr_code', (int) $data['qr_code_id'], (string) $data['qr_code_url'], I18n::get_label( 'business_label_rev_select_qr' ) ); ?>
					</div>

					<div class="mhbo-form-group mhbo-span-full">
						<label class="mhbo-form-label" for="rev_instructions"><?php echo esc_html( I18n::get_label( 'business_label_rev_instr' ) ); ?></label>
						<?php
						wp_editor(
							$data['instructions'],
							'rev_instructions',
							array(
								'textarea_name' => 'rev_instructions',
								'textarea_rows' => 5,
								'media_buttons' => false,
								'teeny'         => true,
							) 
						);
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the shortcodes tab.
	 */
	private function render_tab_shortcodes(): void {
		$shortcodes = array(
			// ---- Core / Free Tier ----
			array(
				'tag'        => '[mhbo_booking_form]',
				'alias'      => '[modern_hotel_booking]',
				'desc'       => I18n::get_label( 'shortcode_desc_booking_form' ),
				'attrs'      => 'room_id, show_calendar',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Reservations & Guests', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_rooms]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_rooms_grid' ),
				'attrs'      => 'columns, limit',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Reservations & Guests', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_room_calendar]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_room_calendar' ),
				'attrs'      => 'room_id',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Reservations & Guests', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_guest_portal]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_guest_portal' ),
				'attrs'      => 'booking_id, email, token',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Reservations & Guests', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_company_info]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_company_info' ),
				'attrs'      => 'show_logo, show_address, show_contact, show_registration, layout',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Property & Contact', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_whatsapp]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_whatsapp' ),
				'attrs'      => 'style, text, message',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Property & Contact', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_banking_details]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_banking' ),
				'attrs'      => 'show_instructions, booking_id, layout',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Payments & Checkout', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_revolut_details]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_revolut' ),
				'attrs'      => 'show_qr, show_link, layout',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Payments & Checkout', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_business_card]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_card' ),
				'attrs'      => 'sections',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Property & Contact', 'modern-hotel-booking' ),
			),
			array(
				'tag'        => '[mhbo_payment_methods]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_all_methods' ),
				'attrs'      => 'booking_id',
				'tier'       => 'free',
				'tier_badge' => __( 'Core / Free', 'modern-hotel-booking' ),
				'category'   => __( 'Payments & Checkout', 'modern-hotel-booking' ),
			),

			// ---- All Tiers / Pro Enhanced ----
			array(
				'tag'        => '[mhbo_ai_concierge]',
				'alias'      => '',
				'desc'       => I18n::get_label( 'shortcode_desc_ai_concierge' ),
				'attrs'      => 'variant, position, welcome_message, theme',
				'tier'       => 'all',
				'tier_badge' => __( 'All Tiers · Pro Enhanced', 'modern-hotel-booking' ),
				'category'   => __( 'AI & Automation', 'modern-hotel-booking' ),
			),

);

		$count_all  = count( $shortcodes );
		$count_free = 0;
		$count_enh  = 0;
		$count_pro  = 0;

		foreach ( $shortcodes as $sc ) {
			if ( 'free' === $sc['tier'] ) {
				++$count_free;
			} elseif ( 'all' === $sc['tier'] ) {
				++$count_enh;
			} elseif ( 'pro' === $sc['tier'] ) {
				++$count_pro;
			}
		}
		?>
		<div class="mhbo-settings-card-container">
			<div class="mhbo-settings-section-card">
				<div class="mhbo-settings-section-header" style="flex-wrap: wrap; gap: 12px;">
					<div class="mhbo-settings-section-title-wrap">
						<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-shortcode"></span></div>
						<div>
							<h3 class="mhbo-settings-section-title"><?php echo esc_html( I18n::get_label( 'business_info_tab_shortcodes' ) ); ?></h3>
							<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Comprehensive registry of all Modern Hotel Booking shortcodes organized by edition tier and functional category. Click any shortcode or copy button to instantly copy it to your clipboard.', 'modern-hotel-booking' ); ?></p>
						</div>
					</div>
					<span id="mhbo-shortcodes-copy-toast" style="display: none; font-size: 12px; font-weight: 600; color: #166534; background: #dcfce7; padding: 6px 14px; border-radius: 6px; border: 1px solid #bbf7d0; box-shadow: 0 2px 4px rgba(0,0,0,0.05); transition: all 0.2s ease;">
						<?php esc_html_e( 'Copied shortcode to clipboard!', 'modern-hotel-booking' ); ?>
					</span>
				</div>

				<!-- Controls Bar: Tier Filter Pills & Quick Search -->
				<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">
					<div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
						<span style="font-size: 12px; font-weight: 600; color: #64748b; margin-right: 4px;"><?php esc_html_e( 'Filter by Tier:', 'modern-hotel-booking' ); ?></span>
						<button type="button" class="mhbo-tier-filter-btn active" data-filter="all" onclick="mhboFilterTier(this, 'all')">
							<?php esc_html_e( 'All Shortcodes', 'modern-hotel-booking' ); ?> <span class="mhbo-filter-count"><?php echo esc_html( (string) $count_all ); ?></span>
						</button>
						<button type="button" class="mhbo-tier-filter-btn" data-filter="free" onclick="mhboFilterTier(this, 'free')">
							<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
							<?php esc_html_e( 'Core / Free', 'modern-hotel-booking' ); ?> <span class="mhbo-filter-count"><?php echo esc_html( (string) $count_free ); ?></span>
						</button>
						<button type="button" class="mhbo-tier-filter-btn" data-filter="all-tier" onclick="mhboFilterTier(this, 'all-tier')">
							<span class="dashicons dashicons-admin-generic" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
							<?php esc_html_e( 'All Tiers · Pro Enhanced', 'modern-hotel-booking' ); ?> <span class="mhbo-filter-count"><?php echo esc_html( (string) $count_enh ); ?></span>
						</button>
						<?php if ( $count_pro > 0 ) : ?>
							<button type="button" class="mhbo-tier-filter-btn" data-filter="pro" onclick="mhboFilterTier(this, 'pro')">
								<span class="dashicons dashicons-star-filled" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle; color: #d97706;"></span>
								<?php esc_html_e( 'Pro Edition', 'modern-hotel-booking' ); ?> <span class="mhbo-filter-count"><?php echo esc_html( (string) $count_pro ); ?></span>
							</button>
						<?php endif; ?>
					</div>
					<div style="flex: 1; min-width: 200px; max-width: 320px;">
						<input type="text" id="mhbo-shortcode-search" placeholder="<?php esc_attr_e( 'Search shortcodes or attributes...', 'modern-hotel-booking' ); ?>"
							oninput="mhboSearchShortcodes(this.value)"
							style="width: 100%; font-size: 13px; padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: #ffffff;" />
					</div>
				</div>

				<div class="mhbo-settings-grid">
					<div class="mhbo-form-group mhbo-span-full">
						<div id="mhbo-shortcodes-list" style="display: flex; flex-direction: column; gap: 12px;">
							<?php foreach ( $shortcodes as $sc ) : ?>
								<?php
								$tier_slug = ( 'all' === $sc['tier'] ) ? 'all-tier' : $sc['tier'];
								?>
								<div class="mhbo-shortcode-row" data-tier="<?php echo esc_attr( $tier_slug ); ?>" data-search="<?php echo esc_attr( strtolower( $sc['tag'] . ' ' . $sc['alias'] . ' ' . $sc['desc'] . ' ' . $sc['attrs'] . ' ' . $sc['category'] ) ); ?>"
									style="display: flex; align-items: flex-start; justify-content: space-between; padding: 16px 18px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; flex-wrap: wrap; gap: 14px; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.03);"
									onmouseover="this.style.borderColor='#93c5fd'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.05)';"
									onmouseout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='0 1px 2px rgba(0,0,0,0.03)';">
									
									<div style="display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 300px;">
										<div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
											<code onclick="mhboCopyShortcode(this)"
												title="<?php esc_attr_e( 'Click to copy shortcode', 'modern-hotel-booking' ); ?>"
												style="font-size: 13.5px; font-weight: 700; color: #0369a1; background: #f0f9ff; border: 1px solid #bae6fd; padding: 4px 10px; border-radius: 6px; cursor: pointer; transition: all 0.15s ease;">
												<?php echo esc_html( $sc['tag'] ); ?>
											</code>
											
											<?php if ( ! empty( $sc['alias'] ) ) : ?>
												<span style="font-size: 12px; color: #94a3b8; font-weight: 500;"><?php esc_html_e( 'or', 'modern-hotel-booking' ); ?></span>
												<code onclick="mhboCopyShortcode(this)"
													title="<?php esc_attr_e( 'Click to copy shortcode', 'modern-hotel-booking' ); ?>"
													style="font-size: 12px; font-weight: 600; color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; padding: 3px 8px; border-radius: 6px; cursor: pointer; transition: all 0.15s ease;">
													<?php echo esc_html( $sc['alias'] ); ?>
												</code>
											<?php endif; ?>

											<!-- Tier Badge -->
											<?php if ( 'free' === $sc['tier'] ) : ?>
												<span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.3px;">
													<span class="dashicons dashicons-yes-alt" style="font-size: 12px; width: 12px; height: 12px;"></span>
													<?php echo esc_html( $sc['tier_badge'] ); ?>
												</span>
											<?php elseif ( 'all' === $sc['tier'] ) : ?>
												<span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: #1d4ed8; background: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.3px;">
													<span class="dashicons dashicons-admin-generic" style="font-size: 12px; width: 12px; height: 12px;"></span>
													<?php echo esc_html( $sc['tier_badge'] ); ?>
												</span>
											<?php elseif ( 'pro' === $sc['tier'] ) : ?>
												<span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: #6d28d9; background: #f5f3ff; border: 1px solid #ddd6fe; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.3px;">
													<span class="dashicons dashicons-star-filled" style="font-size: 12px; width: 12px; height: 12px; color: #f59e0b;"></span>
													<?php echo esc_html( $sc['tier_badge'] ); ?>
												</span>
											<?php endif; ?>

											<!-- Category Badge -->
											<span style="font-size: 11px; font-weight: 600; color: #64748b; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 6px;">
												<?php echo esc_html( $sc['category'] ); ?>
											</span>
										</div>

										<p style="font-size: 13px; color: #334155; margin: 0; line-height: 1.5;"><?php echo esc_html( $sc['desc'] ); ?></p>
									</div>

									<div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
										<div style="font-size: 11.5px; color: #64748b; text-align: right;">
											<span style="font-weight: 600;"><?php echo esc_html( I18n::get_label( 'general_label_attributes' ) ); ?>:</span>
											<?php
											$attr_list = array_map( 'trim', explode( ',', $sc['attrs'] ) );
											?>
											<span style="display: inline-flex; flex-wrap: wrap; gap: 4px; margin-left: 4px; justify-content: flex-end;">
												<?php foreach ( $attr_list as $attr_item ) : ?>
													<code style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #334155;"><?php echo esc_html( $attr_item ); ?></code>
												<?php endforeach; ?>
											</span>
										</div>

										<button type="button" class="button button-small" onclick="mhboCopyShortcodeText('<?php echo esc_js( $sc['tag'] ); ?>')"
											style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: #475569; border-radius: 4px;">
											<span class="dashicons dashicons-clipboard" style="font-size: 13px; width: 13px; height: 13px;"></span>
											<?php esc_html_e( 'Copy Tag', 'modern-hotel-booking' ); ?>
										</button>
									</div>
								</div>
							<?php endforeach; ?>

							<div id="mhbo-no-shortcodes-found" style="display: none; padding: 24px; text-align: center; color: #64748b; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px;">
								<span class="dashicons dashicons-search" style="font-size: 24px; width: 24px; height: 24px; margin-bottom: 8px; color: #94a3b8;"></span>
								<p style="margin: 0; font-size: 13px; font-weight: 500;"><?php esc_html_e( 'No matching shortcodes found.', 'modern-hotel-booking' ); ?></p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<style>
		.mhbo-tier-filter-btn {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 5px 12px;
			border-radius: 6px;
			font-size: 12px;
			font-weight: 600;
			color: #475569;
			background: #ffffff;
			border: 1px solid #cbd5e1;
			cursor: pointer;
			transition: all 0.15s ease;
		}
		.mhbo-tier-filter-btn:hover {
			background: #f1f5f9;
			border-color: #94a3b8;
			color: #0f172a;
		}
		.mhbo-tier-filter-btn.active {
			background: #0284c7;
			border-color: #0284c7;
			color: #ffffff !important;
		}
		.mhbo-tier-filter-btn.active .mhbo-filter-count {
			background: rgba(255,255,255,0.25);
			color: #ffffff;
		}
		.mhbo-tier-filter-btn.active .dashicons {
			color: #ffffff !important;
		}
		.mhbo-filter-count {
			display: inline-block;
			padding: 1px 6px;
			border-radius: 9999px;
			font-size: 10.5px;
			font-weight: 700;
			background: #e2e8f0;
			color: #475569;
		}
		</style>

		<script>
		let mhboCurrentTier = 'all';
		let mhboCurrentQuery = '';

		function mhboFilterTier(btn, tier) {
			mhboCurrentTier = tier;
			document.querySelectorAll('.mhbo-tier-filter-btn').forEach(function(b) {
				b.classList.remove('active');
			});
			if (btn) btn.classList.add('active');
			mhboApplyFilters();
		}

		function mhboSearchShortcodes(q) {
			mhboCurrentQuery = (q || '').trim().toLowerCase();
			mhboApplyFilters();
		}

		function mhboApplyFilters() {
			const rows = document.querySelectorAll('.mhbo-shortcode-row');
			let visibleCount = 0;

			rows.forEach(function(row) {
				const rowTier = row.getAttribute('data-tier') || '';
				const rowSearch = row.getAttribute('data-search') || '';

				const matchesTier = (mhboCurrentTier === 'all' || rowTier === mhboCurrentTier);
				const matchesQuery = (!mhboCurrentQuery || rowSearch.indexOf(mhboCurrentQuery) !== -1);

				if (matchesTier && matchesQuery) {
					row.style.display = 'flex';
					visibleCount++;
				} else {
					row.style.display = 'none';
				}
			});

			const noResults = document.getElementById('mhbo-no-shortcodes-found');
			if (noResults) {
				noResults.style.display = (visibleCount === 0) ? 'block' : 'none';
			}
		}

		function mhboCopyShortcode(el) {
			const text = el.innerText.trim();
			mhboCopyShortcodeText(text);
		}

		function mhboCopyShortcodeText(text) {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function() {
					mhboShowCopyToast(text);
				});
			} else {
				const textArea = document.createElement('textarea');
				textArea.value = text;
				document.body.appendChild(textArea);
				textArea.select();
				try {
					document.execCommand('copy');
					mhboShowCopyToast(text);
				} catch (err) {}
				document.body.removeChild(textArea);
			}
		}

		function mhboShowCopyToast(text) {
			const toast = document.getElementById('mhbo-shortcodes-copy-toast');
			if (toast) {
				toast.style.display = 'inline-block';
				toast.innerText = '<?php echo esc_js( __( 'Copied', 'modern-hotel-booking' ) ); ?> ' + text + '!';
				setTimeout(function() {
					toast.style.display = 'none';
				}, 2500);
			}
		}
		</script>
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
	private function render_media_field( string $prefix, int $image_id, string $image_url, string $title ): void {
		$has_image = '' !== (string) $image_url;
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
			<?php // phpcs:disable WordPressVIPMinimum.Security.ProperEscapingFunction.hrefSrcEscUrl -- data-target-url is a DOM element ID attribute, not a URL. ?>
			<button type="button" class="button mhbo-upload-btn" data-target-id="<?php echo esc_attr( $prefix ); ?>_id" data-target-url="<?php echo esc_attr( $prefix ); ?>_url" data-preview="<?php echo esc_attr( $prefix ); ?>_preview" data-title="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $title ); ?></button>
			<button type="button" class="button mhbo-remove-btn" data-target-id="<?php echo esc_attr( $prefix ); ?>_id" data-target-url="<?php echo esc_attr( $prefix ); ?>_url" data-preview="<?php echo esc_attr( $prefix ); ?>_preview" <?php echo esc_attr( $has_image ? '' : 'style=display:none;' ); ?>><?php echo esc_html( I18n::get_label( 'general_btn_remove' ) ); ?></button>
			<?php // phpcs:enable WordPressVIPMinimum.Security.ProperEscapingFunction.hrefSrcEscUrl ?>
		</div>
		<?php
	}
}
