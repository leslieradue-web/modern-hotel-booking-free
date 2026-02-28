<?php declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tax Calculation Class
 * 
 * Handles VAT and Sales Tax calculations for hotel bookings.
 * Supports three modes:
 * - disabled: No tax calculation
 * - vat: VAT Inclusive (prices include tax)
 * - sales_tax: Sales Tax Exclusive (tax added on top)
 */
class Tax
{
    /**
     * Tax modes
     */
    const MODE_DISABLED = 'disabled';
    const MODE_VAT = 'vat';
    const MODE_SALES_TAX = 'sales_tax';

    /**
     * Rounding modes
     */
    const ROUND_PER_LINE = 'per_line';
    const ROUND_PER_TOTAL = 'per_total';

    /**
     * Check if tax is enabled.
     *
     * Returns false for Free users (no active Pro license) regardless of
     * the stored mhb_tax_mode option.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return false; // Free version - tax disabled
    }

    /**
     * Get current tax mode.
     *
     * Returns MODE_DISABLED for Free users (no active Pro license) regardless
     * of the stored mhb_tax_mode option, ensuring all downstream callers
     * (calculate_booking_tax, get_settings, etc.) receive a neutral value.
     *
     * @return string One of the MODE_* constants.
     */
    public static function get_mode(): string {
        return self::MODE_DISABLED; // Free version
    }

    /**
     * Get tax label for current language
     *
     * @param string $language Language code (optional)
     * @return string
     */
    public static function get_label($language = null)
    {
        if (null === $language) {
            $language = I18n::get_current_language();
        }
        $label = get_option('mhb_tax_label', '[:en]VAT[:ro]TVA[:]');
        $decoded = I18n::decode($label, $language);
        return !empty($decoded) ? $decoded : 'VAT';
    }

    /**
     * Get tax registration number
     *
     * @return string
     */
    public static function get_registration_number()
    {
        return get_option('mhb_tax_registration_number', '');
    }

    /**
     * Get accommodation tax rate
     *
     * @return float
     */
    public static function get_accommodation_rate()
    {
        return floatval(get_option('mhb_tax_rate_accommodation', 0.00));
    }

    /**
     * Get extras tax rate
     *
     * @return float
     */
    public static function get_extras_rate()
    {
        return floatval(get_option('mhb_tax_rate_extras', 0.00));
    }

    /**
     * Get rounding mode
     *
     * @return string
     */
    public static function get_rounding_mode()
    {
        return get_option('mhb_tax_rounding_mode', self::ROUND_PER_TOTAL);
    }

    /**
     * Get decimal places
     *
     * @return int
     */
    public static function get_decimal_places()
    {
        return intval(get_option('mhb_tax_decimal_places', 2));
    }

    /**
     * Get all tax settings as an array
     *
     * @return array Tax settings
     */
    public static function get_settings()
    {
        $mode = self::get_mode();

        return [
            'enabled' => self::is_enabled(),
            'mode' => $mode,
            'label' => self::get_label(),
            'registration_number' => self::get_registration_number(),
            'accommodation_rate' => self::get_accommodation_rate(),
            'extras_rate' => self::get_extras_rate(),
            'rounding_mode' => self::get_rounding_mode(),
            'decimal_places' => self::get_decimal_places(),
            'display_frontend' => (bool) get_option('mhb_tax_display_frontend', false),
            'display_email' => (bool) get_option('mhb_tax_display_email', true),
            'prices_include_tax' => self::MODE_VAT === $mode,
        ];
    }

    /**
     * Calculate tax from gross amount (VAT Inclusive mode)
     * 
     * Formula: Net = Gross / (1 + Rate/100), Tax = Gross - Net
     *
     * @param float $gross_amount Gross amount (includes tax)
     * @param float $tax_rate Tax rate as percentage
     * @param bool $round Whether to round the result (default: true)
     * @return array ['net' => float, 'tax' => float, 'gross' => float]
     */
    public static function calculate_from_gross($gross_amount, $tax_rate, $round = true)
    {
        $decimal_places = self::get_decimal_places();

        if ($tax_rate == 0 || $gross_amount == 0) {
            return [
                'net' => $round ? round(floatval($gross_amount), $decimal_places) : floatval($gross_amount),
                'tax' => 0.00,
                'gross' => $round ? round(floatval($gross_amount), $decimal_places) : floatval($gross_amount)
            ];
        }

        $net = $gross_amount / (1 + ($tax_rate / 100));
        $tax = $gross_amount - $net;

        if ($round) {
            return [
                'net' => round($net, $decimal_places),
                'tax' => round($tax, $decimal_places),
                'gross' => round(floatval($gross_amount), $decimal_places)
            ];
        }

        return [
            'net' => $net,
            'tax' => $tax,
            'gross' => floatval($gross_amount)
        ];
    }

    /**
     * Calculate tax from net amount (Sales Tax Exclusive mode)
     * 
     * Formula: Tax = Net * (Rate/100), Gross = Net + Tax
     *
     * @param float $net_amount Net amount (before tax)
     * @param float $tax_rate Tax rate as percentage
     * @param bool $round Whether to round the result (default: true)
     * @return array ['net' => float, 'tax' => float, 'gross' => float]
     */
    public static function calculate_from_net($net_amount, $tax_rate, $round = true)
    {
        $decimal_places = self::get_decimal_places();

        if ($tax_rate == 0 || $net_amount == 0) {
            return [
                'net' => $round ? round(floatval($net_amount), $decimal_places) : floatval($net_amount),
                'tax' => 0.00,
                'gross' => $round ? round(floatval($net_amount), $decimal_places) : floatval($net_amount)
            ];
        }

        $tax = $net_amount * ($tax_rate / 100);
        $gross = $net_amount + $tax;

        if ($round) {
            return [
                'net' => round(floatval($net_amount), $decimal_places),
                'tax' => round($tax, $decimal_places),
                'gross' => round($gross, $decimal_places)
            ];
        }

        return [
            'net' => floatval($net_amount),
            'tax' => $tax,
            'gross' => $gross
        ];
    }

    /**
     * Calculate tax for an amount based on current mode
     *
     * @param float $amount The amount (gross for VAT, net for Sales Tax)
     * @param float $tax_rate Tax rate as percentage
     * @param string $mode Tax mode (optional, uses current setting)
     * @param bool $round Whether to round the result (default: true)
     * @return array ['net' => float, 'tax' => float, 'gross' => float]
     */
    public static function calculate_tax($amount, $tax_rate, $mode = null, $round = true)
    {
        if (null === $mode) {
            $mode = self::get_mode();
        }

        if (self::MODE_VAT === $mode) {
            return self::calculate_from_gross($amount, $tax_rate, $round);
        } elseif (self::MODE_SALES_TAX === $mode) {
            return self::calculate_from_net($amount, $tax_rate, $round);
        }

        // Disabled mode - no tax
        $decimal_places = self::get_decimal_places();
        return [
            'net' => $round ? round(floatval($amount), $decimal_places) : floatval($amount),
            'tax' => 0.00,
            'gross' => $round ? round(floatval($amount), $decimal_places) : floatval($amount)
        ];
    }

    /**
     * Calculate full booking tax breakdown
     *
     * @param array $booking_data Booking data containing:
     *   - room_total: float Total room charges
     *   - children_total: float Total children charges (optional)
     *   - extras_total: float Total extras charges (optional)
     *   - extras: array Extras breakdown (optional)
     * @return array Tax breakdown
     */
    public static function calculate_booking_tax($booking_data)
    {
        $mode = self::get_mode();
        $accommodation_rate = self::get_accommodation_rate();
        $extras_rate = self::get_extras_rate();
        $rounding_mode = self::get_rounding_mode();
        $decimal_places = self::get_decimal_places();

        // For ROUND_PER_TOTAL, we calculate unrounded values first
        $should_round_individual = (self::ROUND_PER_LINE === $rounding_mode);

        $result = [
            'enabled' => self::is_enabled(),
            'mode' => $mode,
            'label' => self::get_label(),
            'registration_number' => self::get_registration_number(),
            'rates' => [
                'accommodation' => $accommodation_rate,
                'extras' => $extras_rate
            ],
            'breakdown' => [
                'room' => null,
                'children' => null,
                'extras' => []
            ],
            'totals' => [
                'subtotal_net' => 0.00,
                'total_tax' => 0.00,
                'total_gross' => 0.00
            ],
            'rounding' => [
                'mode' => $rounding_mode,
                'decimal_places' => $decimal_places
            ],
            'calculated_at' => current_time('mysql')
        ];

        if (!self::is_enabled()) {
            // Tax disabled - just pass through amounts
            $room_total = floatval($booking_data['room_total'] ?? 0);
            $children_total = floatval($booking_data['children_total'] ?? 0);
            $extras_total = floatval($booking_data['extras_total'] ?? 0);
            $total = $room_total + $children_total + $extras_total;

            $result['totals'] = [
                'room_net' => round($room_total, $decimal_places),
                'room_tax' => 0.00,
                'children_net' => round($children_total, $decimal_places),
                'children_tax' => 0.00,
                'extras_net' => round($extras_total, $decimal_places),
                'extras_tax' => 0.00,
                'subtotal_net' => round($total, $decimal_places),
                'total_tax' => 0.00,
                'total_gross' => round($total, $decimal_places)
            ];
            return $result;
        }

        // Calculate room tax
        $room_total = floatval($booking_data['room_total'] ?? 0);
        if (0 < $room_total) {
            $room_calc = self::calculate_tax($room_total, $accommodation_rate, $mode, $should_round_individual);
            $result['breakdown']['room'] = [
                'gross_amount' => $room_total,
                'net' => $room_calc['net'],
                'tax_rate' => $accommodation_rate,
                'tax' => $room_calc['tax'],
                'gross' => $room_calc['gross']
            ];
        }

        // Calculate children tax
        $children_total = floatval($booking_data['children_total'] ?? 0);
        if (0 < $children_total) {
            $children_calc = self::calculate_tax($children_total, $accommodation_rate, $mode, $should_round_individual);
            $result['breakdown']['children'] = [
                'gross_amount' => $children_total,
                'net' => $children_calc['net'],
                'tax_rate' => $accommodation_rate,
                'tax' => $children_calc['tax'],
                'gross' => $children_calc['gross']
            ];
        }

        // Calculate extras tax
        $extras_total = floatval($booking_data['extras_total'] ?? 0);
        $extras_items = $booking_data['extras'] ?? [];

        if (0 < $extras_total && !empty($extras_items)) {
            foreach ($extras_items as $extra) {
                $extra_amount = floatval($extra['total'] ?? 0);
                if (0 < $extra_amount) {
                    $extra_calc = self::calculate_tax($extra_amount, $extras_rate, $mode, $should_round_individual);
                    $result['breakdown']['extras'][] = [
                        'id' => $extra['id'] ?? '',
                        'name' => I18n::decode($extra['name'] ?? ''),
                        'gross_amount' => $extra_amount,
                        'net' => $extra_calc['net'],
                        'tax_rate' => $extras_rate,
                        'tax' => $extra_calc['tax'],
                        'gross' => $extra_calc['gross']
                    ];
                }
            }
        } elseif (0 < $extras_total) {
            // No item breakdown, just total
            $extras_calc = self::calculate_tax($extras_total, $extras_rate, $mode, $should_round_individual);
            $result['breakdown']['extras'][] = [
                'id' => 'total',
                'name' => 'Extras',
                'gross_amount' => $extras_total,
                'net' => $extras_calc['net'],
                'tax_rate' => $extras_rate,
                'tax' => $extras_calc['tax'],
                'gross' => $extras_calc['gross']
            ];
        }

        // Calculate totals - always round at the end
        $total_net = 0;
        $total_tax = 0;
        $total_gross = 0;

        // Initialize component totals
        $room_net = 0;
        $room_tax = 0;
        $children_net = 0;
        $children_tax = 0;
        $extras_net = 0;
        $extras_tax = 0;

        if (isset($result['breakdown']['room'])) {
            $room_net = $result['breakdown']['room']['net'];
            $room_tax = $result['breakdown']['room']['tax'];
            $total_net += $room_net;
            $total_tax += $room_tax;
            $total_gross += $result['breakdown']['room']['gross'];
        }
        if (isset($result['breakdown']['children'])) {
            $children_net = $result['breakdown']['children']['net'];
            $children_tax = $result['breakdown']['children']['tax'];
            $total_net += $children_net;
            $total_tax += $children_tax;
            $total_gross += $result['breakdown']['children']['gross'];
        }
        foreach ($result['breakdown']['extras'] as $extra) {
            $extras_net += $extra['net'];
            $extras_tax += $extra['tax'];
            $total_net += $extra['net'];
            $total_tax += $extra['tax'];
            $total_gross += $extra['gross'];
        }

        // Round totals
        $result['totals'] = [
            'room_net' => round($room_net, $decimal_places),
            'room_tax' => round($room_tax, $decimal_places),
            'children_net' => round($children_net, $decimal_places),
            'children_tax' => round($children_tax, $decimal_places),
            'extras_net' => round($extras_net, $decimal_places),
            'extras_tax' => round($extras_tax, $decimal_places),
            'subtotal_net' => round($total_net, $decimal_places),
            'total_tax' => round($total_tax, $decimal_places),
            'total_gross' => round($total_gross, $decimal_places)
        ];

        // For ROUND_PER_TOTAL, also round the individual breakdown items for display
        if (self::ROUND_PER_TOTAL === $rounding_mode) {
            if (isset($result['breakdown']['room'])) {
                $result['breakdown']['room']['net'] = round($result['breakdown']['room']['net'], $decimal_places);
                $result['breakdown']['room']['tax'] = round($result['breakdown']['room']['tax'], $decimal_places);
                $result['breakdown']['room']['gross'] = round($result['breakdown']['room']['gross'], $decimal_places);
            }
            if (isset($result['breakdown']['children'])) {
                $result['breakdown']['children']['net'] = round($result['breakdown']['children']['net'], $decimal_places);
                $result['breakdown']['children']['tax'] = round($result['breakdown']['children']['tax'], $decimal_places);
                $result['breakdown']['children']['gross'] = round($result['breakdown']['children']['gross'], $decimal_places);
            }
            foreach ($result['breakdown']['extras'] as &$extra) {
                $extra['net'] = round($extra['net'], $decimal_places);
                $extra['tax'] = round($extra['tax'], $decimal_places);
                $extra['gross'] = round($extra['gross'], $decimal_places);
            }
            unset($extra); // Break reference
        }

        return $result;
    }

    /**
     * Alias for calculate_booking_tax for backward compatibility
     *
     * @param array $booking_data Booking data
     * @return array Tax breakdown
     */
    public static function calculate_breakdown($booking_data)
    {
        return self::calculate_booking_tax($booking_data);
    }

    /**
     * Get stored tax breakdown for a booking
     *
     * @param int $booking_id Booking ID
     * @return array|null Tax breakdown or null if not found
     */
    public static function get_tax_breakdown($booking_id)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, admin-only internal reconstruction
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_breakdown, tax_mode, tax_rate_accommodation, tax_rate_extras,
                    room_total_net, children_total_net, extras_total_net,
                    room_tax, children_tax, extras_tax,
                    subtotal_net, total_tax, total_gross
             FROM {$wpdb->prefix}mhb_bookings WHERE id = %d",
            $booking_id
        ), ARRAY_A);

        if (!$booking) {
            return null;
        }

        // If we have JSON breakdown, use it
        if (!empty($booking['tax_breakdown'])) {
            return json_decode($booking['tax_breakdown'], true);
        }

        // Otherwise, reconstruct from database columns
        return [
            'enabled' => self::MODE_DISABLED !== $booking['tax_mode'],
            'mode' => $booking['tax_mode'],
            'rates' => [
                'accommodation' => floatval($booking['tax_rate_accommodation']),
                'extras' => floatval($booking['tax_rate_extras'])
            ],
            'breakdown' => [
                'room' => [
                    'net' => floatval($booking['room_total_net']),
                    'tax' => floatval($booking['room_tax'])
                ],
                'children' => [
                    'net' => floatval($booking['children_total_net']),
                    'tax' => floatval($booking['children_tax'])
                ],
                'extras' => [
                    'net' => floatval($booking['extras_total_net']),
                    'tax' => floatval($booking['extras_tax'])
                ]
            ],
            'totals' => [
                'subtotal_net' => floatval($booking['subtotal_net']),
                'total_tax' => floatval($booking['total_tax']),
                'total_gross' => floatval($booking['total_gross'])
            ]
        ];
    }

    /**
     * Format tax breakdown for display
     *
     * @param array $breakdown Tax breakdown array
     * @param string $language Language code (optional)
     * @return array Formatted breakdown for display
     */
    public static function format_tax_breakdown($breakdown, $language = null)
    {
        if (null === $language) {
            $language = I18n::get_current_language();
        }

        $label = self::get_label($language);
        $mode = $breakdown['mode'] ?? self::MODE_DISABLED;

        $formatted = [
            'enabled' => $breakdown['enabled'] ?? false,
            'mode' => $mode,
            'label' => $label,
            'registration_number' => $breakdown['registration_number'] ?? '',
            'items' => [],
            'totals' => []
        ];

        // Room
        if (isset($breakdown['breakdown']['room']) && !empty($breakdown['breakdown']['room'])) {
            $room = $breakdown['breakdown']['room'];
            $formatted['items'][] = [
                'type' => 'room',
                'label' => I18n::get_label('label_room') ?? __('Room', 'modern-hotel-booking'),
                'net' => $room['net'] ?? 0,
                'tax_rate' => $room['tax_rate'] ?? 0,
                'tax' => $room['tax'] ?? 0,
                'gross' => $room['gross'] ?? $room['gross_amount'] ?? 0,
                'net_formatted' => I18n::format_currency($room['net'] ?? 0),
                'tax_formatted' => I18n::format_currency($room['tax'] ?? 0),
                'gross_formatted' => I18n::format_currency($room['gross'] ?? $room['gross_amount'] ?? 0)
            ];
        }

        // Children
        if (isset($breakdown['breakdown']['children']) && !empty($breakdown['breakdown']['children'])) {
            $children = $breakdown['breakdown']['children'];
            if (($children['gross'] ?? $children['gross_amount'] ?? 0) > 0) {
                $formatted['items'][] = [
                    'type' => 'children',
                    'label' => I18n::get_label('label_children') ?? __('Children', 'modern-hotel-booking'),
                    'net' => $children['net'] ?? 0,
                    'tax_rate' => $children['tax_rate'] ?? 0,
                    'tax' => $children['tax'] ?? 0,
                    'gross' => $children['gross'] ?? $children['gross_amount'] ?? 0,
                    'net_formatted' => I18n::format_currency($children['net'] ?? 0),
                    'tax_formatted' => I18n::format_currency($children['tax'] ?? 0),
                    'gross_formatted' => I18n::format_currency($children['gross'] ?? $children['gross_amount'] ?? 0)
                ];
            }
        }

        // Extras
        if (isset($breakdown['breakdown']['extras']) && !empty($breakdown['breakdown']['extras'])) {
            foreach ($breakdown['breakdown']['extras'] as $extra) {
                $extra_name = $extra['name'] ?? '';
                if (!empty($extra_name)) {
                    $extra_name = I18n::decode($extra_name, $language);
                } else {
                    $extra_name = I18n::get_label('label_extras') ?? __('Extras', 'modern-hotel-booking');
                }

                $formatted['items'][] = [
                    'type' => 'extra',
                    'label' => $extra_name,
                    'net' => $extra['net'] ?? 0,
                    'tax_rate' => $extra['tax_rate'] ?? 0,
                    'tax' => $extra['tax'] ?? 0,
                    'gross' => $extra['gross'] ?? $extra['gross_amount'] ?? 0,
                    'net_formatted' => I18n::format_currency($extra['net'] ?? 0),
                    'tax_formatted' => I18n::format_currency($extra['tax'] ?? 0),
                    'gross_formatted' => I18n::format_currency($extra['gross'] ?? $extra['gross_amount'] ?? 0)
                ];
            }
        }

        // Totals
        $totals = $breakdown['totals'] ?? [];
        $formatted['totals'] = [
            'subtotal_net' => $totals['subtotal_net'] ?? 0,
            'total_tax' => $totals['total_tax'] ?? 0,
            'total_gross' => $totals['total_gross'] ?? 0,
            'subtotal_net_formatted' => I18n::format_currency($totals['subtotal_net'] ?? 0),
            'total_tax_formatted' => I18n::format_currency($totals['total_tax'] ?? 0),
            'total_gross_formatted' => I18n::format_currency($totals['total_gross'] ?? 0),
            'tax_included_note' => self::MODE_VAT === $mode
                ? sprintf(I18n::get_label('label_includes_tax'), $label)
                : ''
        ];

        return $formatted;
    }

    /**
     * Generate HTML for tax breakdown display
     *
     * @param array $breakdown Tax breakdown
     * @param string $language Language code (optional)
     * @param bool $is_email Whether to use email-friendly inline styles
     * @return string HTML
     */
    public static function render_breakdown_html($breakdown, $language = null, $is_email = false)
    {
        $tax_enabled = self::is_enabled() || ($breakdown['enabled'] ?? false) === true;

        $formatted = self::format_tax_breakdown($breakdown, $language);
        if (empty($formatted['items'])) {
            return '';
        }

        $styles = [
            'container' => $is_email ? 'margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 5px; font-family: Arial, sans-serif;' : 'mhb-tax-breakdown',
            'title' => $is_email ? 'margin: 0 0 15px 0; font-size: 16px; color: #333;' : '',
            'table' => $is_email ? 'width: 100%; border-collapse: collapse; font-size: 14px;' : 'mhb-tax-table',
            'th' => $is_email ? 'text-align: left; padding: 8px 0; border-bottom: 1px solid #ddd; color: #666;' : '',
            'td' => $is_email ? 'padding: 8px 0; border-bottom: 1px solid #eee;' : '',
            'td_right' => $is_email ? 'padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;' : '',
            'total_row' => $is_email ? 'font-weight: bold; background: #eee;' : 'mhb-tax-total',
            'grand_total' => $is_email ? 'font-weight: bold; font-size: 16px; border-top: 2px solid #333;' : 'mhb-tax-grand-total',
            'reg_number' => $is_email ? 'margin-top: 15px; font-size: 12px; color: #999;' : 'mhb-tax-registration'
        ];

        // Get tax rates and amounts for separate display
        $rates = $breakdown['rates'] ?? [];
        $totals = $breakdown['totals'] ?? [];
        $accommodation_rate = floatval($rates['accommodation'] ?? 0);
        $extras_rate = floatval($rates['extras'] ?? 0);
        $room_tax = floatval($totals['room_tax'] ?? 0);
        $children_tax = floatval($totals['children_tax'] ?? 0);
        $extras_tax = floatval($totals['extras_tax'] ?? 0);
        $accommodation_tax_total = $room_tax + $children_tax;

        // Check if we need to show separate tax lines
        $show_separate_tax_lines = ($accommodation_rate !== $extras_rate) && ($accommodation_tax_total > 0 || $extras_tax > 0);

        // translators: %s: Tax label (e.g., "VAT", "Tax").
        $tax_breakdown_label = I18n::get_label('label_tax_breakdown') ?? __('%s Breakdown', 'modern-hotel-booking');
        $booking_summary_label = I18n::get_label('label_booking_summary') ?? __('Booking Summary', 'modern-hotel-booking');
        $title = $tax_enabled
            ? sprintf($tax_breakdown_label, $formatted['label'])
            : $booking_summary_label;

        ob_start();
        ?>
        <div class="<?php echo $is_email ? '' : esc_attr($styles['container']); ?>"
            style="<?php echo $is_email ? esc_attr($styles['container']) : ''; ?>">
            <h4 style="<?php echo esc_attr($styles['title']); ?>">
                <?php echo esc_html($title); ?>
            </h4>
            <table class="<?php echo $is_email ? '' : esc_attr($styles['table']); ?>"
                style="<?php echo $is_email ? esc_attr($styles['table']) : ''; ?>">
                <thead>
                    <tr>
                        <th style="<?php echo esc_attr($styles['th']); ?>">
                            <?php echo esc_html(I18n::get_label('label_item') ?? __('Item', 'modern-hotel-booking')); ?>
                        </th>
                        <th style="<?php echo esc_attr($styles['th']); ?> text-align: right;">
                            <?php echo esc_html(I18n::get_label('label_amount') ?? __('Amount', 'modern-hotel-booking')); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($formatted['items'] as $item): ?>
                        <tr>
                            <td style="<?php echo esc_attr($styles['td']); ?>">
                                <?php echo esc_html($item['label']); ?>
                            </td>
                            <td style="<?php echo esc_attr($styles['td_right']); ?>">
                                <?php echo esc_html($item['gross_formatted']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php if ($tax_enabled): ?>
                        <tr class="mhb-tax-total">
                            <td style="<?php echo esc_attr($styles['td']); ?>">
                                <strong><?php echo esc_html(I18n::get_label('label_subtotal') ?? __('Subtotal', 'modern-hotel-booking')); ?></strong>
                            </td>
                            <td style="<?php echo esc_attr($styles['td_right']); ?>">
                                <strong><?php echo esc_html($formatted['totals']['subtotal_net_formatted']); ?></strong>
                            </td>
                        </tr>
                        <?php if ($show_separate_tax_lines): ?>
                            <?php if ($accommodation_tax_total > 0): ?>
                                <tr class="mhb-tax-item">
                                    <td style="<?php echo esc_attr($styles['td']); ?>">
                                        <?php
                                        echo esc_html(sprintf(I18n::get_label('label_tax_accommodation') ?? /* translators: %1$s: Tax label, %2$s: Tax rate percentage */ __('%1$s - Accommodation (%2$s%%)', 'modern-hotel-booking'), $formatted['label'], $accommodation_rate)); ?>
                                    </td>
                                    <td style="<?php echo esc_attr($styles['td_right']); ?>">
                                        <?php echo esc_html(I18n::format_currency($accommodation_tax_total)); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($extras_tax > 0): ?>
                                <tr class="mhb-tax-item">
                                    <td style="<?php echo esc_attr($styles['td']); ?>">
                                        <?php
                                        echo esc_html(sprintf(I18n::get_label('label_tax_extras') ?? /* translators: %1$s: Tax label, %2$s: Tax rate percentage */ __('%1$s - Extras (%2$s%%)', 'modern-hotel-booking'), $formatted['label'], $extras_rate)); ?>
                                    </td>
                                    <td style="<?php echo esc_attr($styles['td_right']); ?>">
                                        <?php echo esc_html(I18n::format_currency($extras_tax)); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php else: ?>
                            <tr class="mhb-tax-item">
                                <td style="<?php echo esc_attr($styles['td']); ?>">
                                    <?php
                                    echo esc_html(sprintf(I18n::get_label('label_tax_rate') ?? /* translators: %1$s: Tax label, %2$s: Tax rate percentage */ __('%1$s (%2$s%%)', 'modern-hotel-booking'), $formatted['label'], $accommodation_rate)); ?>
                                </td>
                                <td style="<?php echo esc_attr($styles['td_right']); ?>">
                                    <?php echo esc_html($formatted['totals']['total_tax_formatted']); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    <tr class="<?php echo $is_email ? '' : esc_attr($styles['grand_total']); ?>">
                        <td
                            style="<?php echo esc_attr($styles['td']); ?> <?php echo $is_email ? esc_attr($styles['grand_total']) : ''; ?>">
                            <strong><?php echo esc_html(I18n::get_label('label_total') ?? __('Total Price', 'modern-hotel-booking')); ?></strong>
                        </td>
                        <td
                            style="<?php echo esc_attr($styles['td_right']); ?> <?php echo $is_email ? esc_attr($styles['grand_total']) : ''; ?>">
                            <strong><?php echo esc_html($formatted['totals']['total_gross_formatted']); ?></strong>
                        </td>
                    </tr>
                </tfoot>
            </table>
            <?php if ($tax_enabled && self::MODE_VAT === $formatted['mode']): ?>
                <p style="font-size: 12px; color: #666; margin-top: 5px;">
                    <?php echo esc_html($formatted['totals']['tax_included_note']); ?>
                </p>
            <?php endif; ?>
            <?php if ($tax_enabled && !empty($formatted['registration_number'])): ?>
                <p class="<?php echo $is_email ? '' : esc_attr($styles['reg_number']); ?>"
                    style="<?php echo $is_email ? esc_attr($styles['reg_number']) : ''; ?>">
                    <?php
                    // translators: %s: Tax registration number.
                    echo esc_html(sprintf(I18n::get_label('label_tax_registration') ?? __('Tax Registration: %s', 'modern-hotel-booking'), $formatted['registration_number'])); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate plain text for tax breakdown (for emails)
     *
     * @param array $breakdown Tax breakdown
     * @param string $language Language code (optional)
     * @return string Plain text
     */
    public static function render_breakdown_text($breakdown, $language = null)
    {
        if (!self::is_enabled() && ($breakdown['enabled'] ?? false) === false) {
            return '';
        }

        $formatted = self::format_tax_breakdown($breakdown, $language);
        $lines = [];
        $lines[] = sprintf(I18n::decode(I18n::get_label('label_tax_breakdown'), $language), $formatted['label']);
        $lines[] = str_repeat('-', 40);

        foreach ($formatted['items'] as $item) {
            $lines[] = sprintf(
                '  %s (%s%%): %s',
                $item['label'],
                $item['tax_rate'],
                $item['tax_formatted']
            );
        }

        $lines[] = str_repeat('-', 40);

        // Get tax rates and amounts for separate display
        $rates = $breakdown['rates'] ?? [];
        $totals = $breakdown['totals'] ?? [];
        $accommodation_rate = floatval($rates['accommodation'] ?? 0);
        $extras_rate = floatval($rates['extras'] ?? 0);
        $room_tax = floatval($totals['room_tax'] ?? 0);
        $children_tax = floatval($totals['children_tax'] ?? 0);
        $extras_tax = floatval($totals['extras_tax'] ?? 0);
        $accommodation_tax_total = $room_tax + $children_tax;

        // Check if we need to show separate tax lines
        $show_separate_tax_lines = ($accommodation_rate !== $extras_rate) && ($accommodation_tax_total > 0 || $extras_tax > 0);

        if ($show_separate_tax_lines) {
            if ($accommodation_tax_total > 0) {
                $lines[] = sprintf(
                    // translators: 1: tax label (e.g., VAT), 2: tax rate percentage, 3: formatted tax amount
                    __('  %1$s - Accommodation (%2$s%%): %3$s', 'modern-hotel-booking'),
                    $formatted['label'],
                    $accommodation_rate,
                    I18n::format_currency($accommodation_tax_total)
                );
            }
            if ($extras_tax > 0) {
                $lines[] = sprintf(
                    // translators: 1: tax label (e.g., VAT), 2: tax rate percentage, 3: formatted tax amount
                    __('  %1$s - Extras (%2$s%%): %3$s', 'modern-hotel-booking'),
                    $formatted['label'],
                    $extras_rate,
                    I18n::format_currency($extras_tax)
                );
            }
        } else {
            $lines[] = sprintf(I18n::decode(I18n::get_label('label_tax_total'), $language), $formatted['label'], $formatted['totals']['total_tax_formatted']);
        }

        if (!empty($formatted['registration_number'])) {
            $lines[] = sprintf(I18n::decode(I18n::get_label('label_tax_registration'), $language), $formatted['registration_number']);
        }

        return implode("\n", $lines);
    }

    /**
     * Store tax data with a booking.
     *
     * @param int $booking_id Booking ID
     * @param array $tax_breakdown Full tax breakdown array from calculate_booking_tax()
     * @return bool Success
     */
    public static function store_tax_data($booking_id, $tax_breakdown)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhb_bookings';

        // Use pre-calculated component totals from the breakdown if available
        $totals = $tax_breakdown['totals'] ?? [];
        $breakdown = $tax_breakdown['breakdown'] ?? [];

        $data = [
            'tax_enabled' => ($tax_breakdown['enabled'] ?? false) ? 1 : 0,
            'tax_mode' => $tax_breakdown['mode'] ?? self::MODE_DISABLED,
            'tax_rate_accommodation' => $tax_breakdown['rates']['accommodation'] ?? 0,
            'tax_rate_extras' => $tax_breakdown['rates']['extras'] ?? 0,
            'room_total_net' => $totals['room_net'] ?? $breakdown['room']['net'] ?? 0,
            'room_tax' => $totals['room_tax'] ?? $breakdown['room']['tax'] ?? 0,
            'children_total_net' => $totals['children_net'] ?? $breakdown['children']['net'] ?? 0,
            'children_tax' => $totals['children_tax'] ?? $breakdown['children']['tax'] ?? 0,
            'extras_total_net' => $totals['extras_net'] ?? 0,
            'extras_tax' => $totals['extras_tax'] ?? 0,
            'subtotal_net' => $totals['subtotal_net'] ?? 0,
            'total_tax' => $totals['total_tax'] ?? 0,
            'total_gross' => $totals['total_gross'] ?? 0,
            'tax_breakdown' => json_encode($tax_breakdown)
        ];

        // Fallback for extras if totals not populated (should not happen with latest calculate_booking_tax)
        if (0 >= $data['extras_total_net'] && !empty($breakdown['extras'])) {
            foreach ($breakdown['extras'] as $extra) {
                $data['extras_total_net'] += $extra['net'] ?? 0;
                $data['extras_tax'] += $extra['tax'] ?? 0;
            }
        }

        $format = [
            '%d', // tax_enabled
            '%s', // tax_mode
            '%f', // tax_rate_accommodation
            '%f', // tax_rate_extras
            '%f', // room_total_net
            '%f', // room_tax
            '%f', // children_total_net
            '%f', // children_tax
            '%f', // extras_total_net
            '%f', // extras_tax
            '%f', // subtotal_net
            '%f', // total_tax
            '%f', // total_gross
            '%s'  // tax_breakdown
        ];

        return $wpdb->update($table, $data, ['id' => $booking_id], $format, ['%d']) !== false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
    }

    /**
     * Get tax summary for a booking (simplified)
     *
     * @param int $booking_id Booking ID
     * @return array Simplified tax summary
     */
    public static function get_booking_tax_summary($booking_id)
    {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix
        $table = $wpdb->prefix . 'mhb_bookings';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin-only query
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_enabled, tax_mode, total_tax, total_gross, total_price
             FROM {$wpdb->prefix}mhb_bookings WHERE id = %d",
            $booking_id
        ));

        if (!$booking) {
            return [
                'has_tax' => false,
                'tax_amount' => 0,
                'tax_mode' => self::MODE_DISABLED
            ];
        }

        return [
            'has_tax' => (bool) $booking->tax_enabled,
            'tax_amount' => floatval($booking->total_tax),
            'tax_mode' => $booking->tax_mode,
            'total_gross' => floatval($booking->total_gross ?: $booking->total_price)
        ];
    }
}
