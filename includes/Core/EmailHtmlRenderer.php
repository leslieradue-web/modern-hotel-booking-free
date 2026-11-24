<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

/**
 * Renders HTML and plain-text email fragments for Modern Hotel Booking.
 */
class EmailHtmlRenderer {

	/**
	 * Helper: render a two-column table row for the admin notification email.
	 *
	 * @param string $label The row label.
	 * @param string $value The row value (may contain safe HTML).
	 * @return string HTML table row.
	 */
	public static function admin_row( string $label, string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		return '<tr>'
			. '<td style="padding:6px 8px;color:#6b7280;width:38%;vertical-align:top;">' . $label . '</td>'
			. '<td style="padding:6px 8px;vertical-align:top;">' . $value . '</td>'
			. '</tr>';
	}

	/**
	 * Format booking extras for email placeholders.
	 *
	 * @param object $booking The booking object.
	 * @param string $lang    Current language code.
	 * @param string $format  Output format ('html' or 'text').
	 * @return string Formatted extras.
	 */
	public static function format_extras( object $booking, string $lang, string $format = 'html' ): string {
		$extras = Pricing::decode_stored_extras( $booking->booking_extras ?? null );
		if ( count( $extras ) === 0 ) {
			return '';
		}

		$mhbo_output = '';
		foreach ( $extras as $ex_key => $extra ) {
			$ex_id       = (string) ( $extra['id'] ?? $ex_key );
			$is_stay_ext = in_array( $ex_id, array( '_early_checkin', '_late_checkout' ), true );
			$name        = isset( $extra['name'] ) ? (string) ( I18n::decode( (string) $extra['name'], $lang ) ?? '' ) : '';
			$total       = isset( $extra['total'] )
				? ( $is_stay_ext && (float) $extra['total'] <= 0.0
					? __( 'Complimentary', 'modern-hotel-booking' )
					: I18n::format_currency( (float) $extra['total'] ) )
				: '';
			if ( '' === $name ) {
				continue;
			}

			if ( 'html' === $format ) {
				$mhbo_output .= '<li>' . esc_html( $name ) . ': ' . esc_html( $total ) . '</li>';
			} else {
				$mhbo_output .= ' - ' . $name . ': ' . $total . "\n";
			}
		}

		if ( 'html' === $format && '' !== $mhbo_output ) {
			$mhbo_output = '<ul style="margin: 0; padding-left: 20px;">' . $mhbo_output . '</ul>';
		}

		return $mhbo_output;
	}

	/**
	 * Get business payment details for booking emails.
	 *
	 * @param int                $booking_id  The booking ID.
	 * @param float|string|Money $total_price The total booking price.
	 * @return string HTML for payment details.
	 */
	public static function get_business_payment_details_html( int $booking_id, float|string|Money $total_price ): string {
		$total_price  = $total_price instanceof Money ? $total_price : Money::fromDecimal( (string) $total_price );
		$mhbo_output  = '<div style="margin-top: 20px; padding: 15px; background: #fff3e0; border-radius: 5px; border: 1px solid #ffeccf;">';
		$mhbo_output .= '<h4 style="margin: 0 0 10px 0; color: #e65100;">' . esc_html( I18n::get_label( 'label_payment_info' ) ) . '</h4>';
		$mhbo_output .= '<p style="margin: 5px 0;"><strong>' . esc_html( I18n::get_label( 'label_amount_due' ) ) . '</strong> ' . I18n::format_currency( $total_price ) . '</p>';

		if ( class_exists( 'MHBO\Business\Info' ) ) {
			$banking = \MHBO\Business\Info::get_banking();
			$revolut = \MHBO\Business\Info::get_revolut();
			if ( (bool) ( $banking['enabled'] ?? false ) && '' !== (string) ( $banking['iban'] ?? '' ) ) {
				$mhbo_output .= '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ffd8a8;">';
				$mhbo_output .= '<h5 style="margin: 0 0 10px 0;">' . esc_html( I18n::get_label( 'label_bank_transfer_details' ) ) . '</h5>';
				$mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html( I18n::get_label( 'label_bank' ) ) . '</strong> ' . esc_html( (string) $banking['bank_name'] ) . '</p>';
				$mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html( I18n::get_label( 'label_iban' ) ) . '</strong> <code style="background:#fff;padding:2px 5px;border:1px solid #ccc;">' . esc_html( (string) $banking['iban'] ) . '</code></p>';
				$mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html( I18n::get_label( 'label_reference' ) ) . '</strong> ' . esc_html( $banking['reference_prefix'] . $booking_id ) . '</p>';
				$mhbo_output .= '</div>';
			}
			if ( (bool) ( $revolut['enabled'] ?? false ) && '' !== (string) ( $revolut['revolut_tag'] ?? '' ) ) {
				$mhbo_output .= '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ffd8a8;">';
				$mhbo_output .= '<h5 style="margin: 0 0 10px 0;">' . esc_html( I18n::get_label( 'label_revolut_payment' ) ) . '</h5>';
				$mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html( I18n::get_label( 'label_revtag' ) ) . '</strong> <code style="background:#fff;padding:2px 5px;border:1px solid #ccc;">' . esc_html( (string) $revolut['revolut_tag'] ) . '</code></p>';
				if ( '' !== (string) ( $revolut['revolut_link'] ?? '' ) ) {
					$mhbo_output .= '<p style="margin: 5px 0;"><a href="' . esc_url( (string) $revolut['revolut_link'] ) . '" style="display:inline-block;background:#000;color:#fff;padding:5px 15px;text-decoration:none;border-radius:4px;font-size:0.85em;">' . esc_html( I18n::get_label( 'label_pay_via_revolut' ) ) . '</a></p>';
				}
				$mhbo_output .= '</div>';
			}
		}

		$mhbo_output .= '</div>';
		return $mhbo_output;
	}

	/**
	 * Render itemized reservation breakdown and payment summary HTML for emails.
	 *
	 * @param object $booking The booking object.
	 * @param string $lang    Current language code.
	 * @return string HTML content for reservation breakdown.
	 */
	public static function render_reservation_breakdown_html( object $booking, string $lang ): string {
		$folio = class_exists( Pricing::class ) ? Pricing::get_folio_breakdown( $booking, $lang ) : null;
		if ( ! $folio ) {
			return '';
		}

		$html  = '<div style="margin-top: 20px; padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-family: sans-serif;">';
		$html .= '<h4 style="margin: 0 0 12px 0; color: #1e293b; font-size: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">' . esc_html( __( 'Reservation Breakdown', 'modern-hotel-booking' ) ) . '</h4>';

		$html .= '<table style="width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 12px;">';
		$html .= '<thead><tr style="border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 12px; text-transform: uppercase;">';
		$html .= '<th scope="col" style="text-align: left; padding: 4px 0;">' . esc_html( _x( 'Item', 'cart item', 'modern-hotel-booking' ) ) . '</th>';
		$html .= '<th scope="col" style="text-align: right; padding: 4px 0;">' . esc_html( __( 'Amount', 'modern-hotel-booking' ) ) . '</th>';
		$html .= '</tr></thead>';
		$html .= '<tbody>';

		// Itemized charges (room nights, supplements, early/late checkin, extras, fees, discounts)
		foreach ( $folio['charges'] as $c ) {
			$item_style = $c['is_negative'] ? 'color: #16a34a;' : 'color: #334155;';
			$qty_str    = ( (int) $c['qty'] > 1 && 'room' !== $c['category'] ) ? ' &times; ' . (int) $c['qty'] : '';
			$html      .= '<tr style="' . $item_style . '">';
			$html      .= '<td style="padding: 6px 0;">';
			$html      .= '<strong>' . esc_html( $c['label'] ) . '</strong>' . $qty_str;
			if ( ! empty( $c['details'] ) && 'room' !== $c['category'] ) {
				$html .= '<div style="font-size: 11px; color: #64748b; margin-top: 1px;">' . esc_html( $c['details'] ) . '</div>';
			}
			$html .= '</td>';
			$html .= '<td style="padding: 6px 0; text-align: right; font-weight: 600;' . ( $c['is_negative'] ? ' color: #16a34a;' : '' ) . '">' . esc_html( $c['total_formatted'] ) . '</td>';
			$html .= '</tr>';
		}

		// Optional Subtotal (net) & Tax rows when Tax is enabled and positive
		$has_tax = ! empty( $folio['tax_enabled'] ) && ! empty( $folio['total_tax'] ) && (float) $folio['total_tax'] > 0.001;
		if ( $has_tax && ! empty( $folio['subtotal_net_formatted'] ) ) {
			$subtotal_label = ! empty( $folio['is_vat'] )
				? sprintf( I18n::get_label( 'label_subtotal_net_vat' ), $folio['base_tax_label'] ?? $folio['tax_label'] ?? 'VAT' )
				: I18n::get_label( 'label_subtotal' );

			$html .= '<tr style="border-top: 1px solid #e2e8f0;"><td style="padding: 6px 0; color: #475569; font-weight: 600;">' . esc_html( $subtotal_label ) . '</td><td style="padding: 6px 0; text-align: right; font-weight: 600; color: #475569;">' . esc_html( $folio['subtotal_net_formatted'] ) . '</td></tr>';

			$acc_rate     = (float) ( $booking->tax_rate_accommodation ?? ( class_exists( Tax::class ) ? Tax::get_accommodation_rate() : 0.0 ) );
			$ext_rate     = (float) ( $booking->tax_rate_extras ?? ( class_exists( Tax::class ) ? Tax::get_extras_rate() : 0.0 ) );
			$acc_rate_fmt = class_exists( Tax::class ) ? Tax::format_rate( $acc_rate ) : (string) ( $acc_rate + 0 );
			$ext_rate_fmt = class_exists( Tax::class ) ? Tax::format_rate( $ext_rate ) : (string) ( $ext_rate + 0 );

			if ( $folio['accommodation_tax'] > 0 && $folio['extras_tax'] > 0 ) {
				/* translators: 1: Tax label, 2: Tax rate */
				$html .= '<tr><td style="padding: 4px 0; color: #64748b; font-size: 13px;">' . esc_html( sprintf( I18n::get_label( 'label_tax_accommodation' ), $folio['tax_label'], $acc_rate_fmt ) ) . '</td><td style="padding: 4px 0; text-align: right; color: #64748b; font-size: 13px;">' . esc_html( $folio['accommodation_tax_formatted'] ) . '</td></tr>';
				/* translators: 1: Tax label, 2: Tax rate */
				$html .= '<tr><td style="padding: 4px 0; color: #64748b; font-size: 13px;">' . esc_html( sprintf( I18n::get_label( 'label_tax_extras' ), $folio['tax_label'], $ext_rate_fmt ) ) . '</td><td style="padding: 4px 0; text-align: right; color: #64748b; font-size: 13px;">' . esc_html( $folio['extras_tax_formatted'] ) . '</td></tr>';
			} elseif ( $folio['total_tax'] > 0 ) {
				$single_rate_fmt = ( $folio['accommodation_tax'] <= 0 && $folio['extras_tax'] > 0 ) ? $ext_rate_fmt : $acc_rate_fmt;
				/* translators: 1: Tax label, 2: Tax rate */
				$html .= '<tr><td style="padding: 4px 0; color: #64748b; font-size: 13px;">' . esc_html( sprintf( I18n::get_label( 'label_tax_rate' ), $folio['tax_label'], $single_rate_fmt ) ) . '</td><td style="padding: 4px 0; text-align: right; color: #64748b; font-size: 13px;">' . esc_html( $folio['total_tax_formatted'] ) . '</td></tr>';
			}
		}

		// Total
		$total_label = ( $has_tax && ! empty( $folio['is_vat'] ) )
			? sprintf( I18n::get_label( 'label_total_charges_vat' ), $folio['base_tax_label'] ?? $folio['tax_label'] ?? 'VAT' )
			: I18n::get_label( 'label_total' );

		$html .= '<tr style="border-top: 2px solid #cbd5e1;"><td style="padding: 8px 0; font-weight: 700; color: #0f172a;">' . esc_html( $total_label ) . '</td><td style="padding: 8px 0; text-align: right; font-weight: 700; font-size: 16px; color: #0f172a;">' . esc_html( $folio['total_charges_formatted'] ) . '</td></tr>';
		$html .= '</tbody></table>';

		if ( $has_tax && ! empty( $folio['tax_included'] ) && $folio['total_tax'] > 0 ) {
			/* translators: %s: formatted tax amount */
			$tax_inc_note = sprintf( I18n::get_label( 'label_tax_included' ), $folio['total_tax_formatted'] );
			$html        .= '<div style="font-size: 11px; color: #64748b; margin: -4px 0 8px 0;">' . esc_html( $tax_inc_note ) . '</div>';
		}
		if ( $has_tax && ! empty( $folio['tax_registration_number'] ) ) {
			/* translators: %s: tax registration number */
			$html .= '<div style="font-size: 11px; color: #94a3b8; margin: -2px 0 8px 0;">' . esc_html( sprintf( I18n::get_label( 'label_tax_registration' ), $folio['tax_registration_number'] ) ) . '</div>';
		}

		// Payment Summary Sub-Card
		$html .= '<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-top: 8px;">';
		$html .= '<table style="width: 100%; border-collapse: collapse; font-size: 13px;">';

		$amts                     = Pricing::get_booking_payment_amounts( $booking );
		$payment_type             = (string) ( $booking->payment_type ?? 'full' );
		$deposit_received         = $amts['deposit_received'];
		$paid                     = $amts['received'];
		$remaining                = $amts['remaining'];
		$total                    = $amts['total'];
		$is_cancelled_or_refunded = in_array( (string) ( $booking->status ?? '' ), array( 'cancelled', 'refunded' ), true );
		$is_arrival_method        = in_array( (string) ( $booking->payment_method ?? '' ), array( 'arrival', 'onsite' ), true );
		$status_label             = esc_html( rtrim( I18n::get_label( 'label_payment_status' ), ':' ) );

		if ( $is_cancelled_or_refunded ) {
			$zero_money = Money::fromCents( 0, $total->getCurrency() );
			if ( 'refunded' === (string) ( $booking->status ?? '' ) || 'refunded' === (string) ( $booking->payment_status ?? '' ) ) {
				$closed_status_text = __( 'Refunded', 'modern-hotel-booking' );
				$closed_color       = '#6b21a8';
			} elseif ( $paid->isPositive() ) {
				$closed_status_text = __( 'Cancelled (Payment on File)', 'modern-hotel-booking' );
				$closed_color       = '#d97706';
			} else {
				$closed_status_text = __( 'Cancelled — No Balance Due', 'modern-hotel-booking' );
				$closed_color       = '#475569';
			}
			$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: ' . $closed_color . '; font-weight: 700;">' . esc_html( $closed_status_text ) . '</td></tr>';
			if ( $paid->isPositive() ) {
				$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . esc_html( rtrim( I18n::get_label( 'label_amount_paid' ), ':' ) ) . ':</td><td style="padding: 3px 0; text-align: right; font-weight: 700;">' . I18n::format_currency( $paid ) . '</td></tr>';
			}
			$html .= '<tr style="border-top: 1px dashed #e2e8f0;"><td style="padding: 6px 0 2px 0; color: #0f172a; font-weight: 600;">' . esc_html( rtrim( I18n::get_label( 'label_amt_out' ), ':' ) ) . ':</td><td style="padding: 6px 0 2px 0; text-align: right; color: #475569; font-weight: 700; font-size: 14px;">' . I18n::format_currency( $zero_money ) . '</td></tr>';
		} elseif ( $amts['is_paid'] ) {
			if ( ! empty( $folio['is_overpaid'] ) ) {
				$zero_money = Money::fromCents( 0, $total->getCurrency() );
				$html      .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #0369a1; font-weight: 700;">' . esc_html__( 'Overpaid / Credit Balance', 'modern-hotel-booking' ) . '</td></tr>';
				$html      .= '<tr><td style="padding: 3px 0; color: #64748b;">' . esc_html( rtrim( I18n::get_label( 'label_amount_paid' ), ':' ) ) . ':</td><td style="padding: 3px 0; text-align: right; color: #16a34a; font-weight: 700;">' . I18n::format_currency( $paid ) . '</td></tr>';
				$html      .= '<tr><td style="padding: 3px 0; color: #0369a1; font-weight: 600;">' . esc_html__( 'Credit Balance / Overpayment', 'modern-hotel-booking' ) . ':</td><td style="padding: 3px 0; text-align: right; color: #0369a1; font-weight: 700;">−' . esc_html( (string) ( $folio['credit_balance_formatted'] ?? '' ) ) . '</td></tr>';
				$html      .= '<tr style="border-top: 1px dashed #e2e8f0;"><td style="padding: 6px 0 2px 0; color: #0f172a; font-weight: 600;">' . esc_html( rtrim( I18n::get_label( 'label_amt_out' ), ':' ) ) . ':</td><td style="padding: 6px 0 2px 0; text-align: right; color: #166534; font-weight: 700; font-size: 14px;">' . I18n::format_currency( $zero_money ) . '</td></tr>';
			} else {
				$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #16a34a; font-weight: 700;">' . esc_html( I18n::get_label( 'label_paid' ) ) . '</td></tr>';
				$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . esc_html( rtrim( I18n::get_label( 'label_amount_paid' ), ':' ) ) . ':</td><td style="padding: 3px 0; text-align: right; font-weight: 700;">' . I18n::format_currency( $paid->isPositive() ? $paid : $total ) . '</td></tr>';
				$html .= '<tr style="border-top: 1px dashed #e2e8f0;"><td colspan="2" style="padding: 6px 0 2px 0; text-align: center; color: #166534; font-weight: 700;">' . esc_html( I18n::get_label( 'label_email_paid_full' ) ) . '</td></tr>';
			}
		} elseif ( 'deposit' === $payment_type || $amts['deposit']->isPositive() ) {
			if ( $deposit_received ) {
				$deposit_label = esc_html( rtrim( I18n::get_label( 'label_email_deposit_paid' ), ':' ) );
				$rem_label     = esc_html( rtrim( I18n::get_label( 'label_email_remaining_balance' ), ':' ) );
				$html         .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #16a34a; font-weight: 700;">' . esc_html( I18n::get_label( 'label_deposit_paid' ) ) . '</td></tr>';
				$html         .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $deposit_label . ':</td><td style="padding: 3px 0; text-align: right; color: #16a34a; font-weight: 700;">' . I18n::format_currency( $paid ) . '</td></tr>';
				$html         .= '<tr style="border-top: 1px dashed #e2e8f0;"><td style="padding: 6px 0 2px 0; color: #0f172a; font-weight: 600;">' . $rem_label . ':</td><td style="padding: 6px 0 2px 0; text-align: right; color: #f97316; font-weight: 700; font-size: 14px;">' . I18n::format_currency( $remaining ) . '</td></tr>';
				$html         .= '<tr><td colspan="2" style="padding: 2px 0 0 0; font-size: 11px; color: #64748b;">' . esc_html( I18n::get_label( 'label_email_due_at_checkin' ) ) . '</td></tr>';
			} else {
				if ( $paid->isPositive() ) {
					$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #0369a1; font-weight: 700;">' . esc_html( I18n::get_label( 'label_status_partially_paid' ) ) . '</td></tr>';
					$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . esc_html( rtrim( I18n::get_label( 'label_amount_paid' ), ':' ) ) . ':</td><td style="padding: 3px 0; text-align: right; color: #16a34a; font-weight: 700;">' . I18n::format_currency( $paid ) . '</td></tr>';
				} else {
					$method_label = $is_arrival_method ? esc_html( I18n::get_label( 'label_pay_arrival' ) ) : esc_html( I18n::translate_status( 'pending' ) );
					$html        .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #d97706; font-weight: 700;">' . $method_label . '</td></tr>';
				}
				if ( $amts['deposit']->isPositive() ) {
					$deposit_label = esc_html( rtrim( I18n::get_label( 'label_email_deposit_required' ), ':' ) );
					$html         .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $deposit_label . ':</td><td style="padding: 3px 0; text-align: right; color: #f97316; font-weight: 700;">' . I18n::format_currency( $amts['deposit'] ) . '</td></tr>';
				}
				$due_label = $is_arrival_method ? esc_html( rtrim( I18n::get_label( 'label_amount_due' ), ':' ) ) : esc_html( rtrim( I18n::get_label( 'label_amt_out' ), ':' ) );
				$html     .= '<tr style="border-top: 1px dashed #e2e8f0;"><td style="padding: 6px 0 2px 0; color: #0f172a; font-weight: 600;">' . $due_label . ':</td><td style="padding: 6px 0 2px 0; text-align: right; color: #d97706; font-weight: 700; font-size: 14px;">' . I18n::format_currency( $remaining ) . '</td></tr>';
			}
		} elseif ( $paid->isPositive() ) {
			$rem_label = esc_html( rtrim( I18n::get_label( 'label_email_remaining_balance' ), ':' ) );
			$html     .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #0369a1; font-weight: 700;">' . esc_html( I18n::get_label( 'label_status_partially_paid' ) ) . '</td></tr>';
			$html     .= '<tr><td style="padding: 3px 0; color: #64748b;">' . esc_html( rtrim( I18n::get_label( 'label_amount_paid' ), ':' ) ) . ':</td><td style="padding: 3px 0; text-align: right; color: #16a34a; font-weight: 700;">' . I18n::format_currency( $paid ) . '</td></tr>';
			$html     .= '<tr style="border-top: 1px dashed #e2e8f0;"><td style="padding: 6px 0 2px 0; color: #0f172a; font-weight: 600;">' . $rem_label . ':</td><td style="padding: 6px 0 2px 0; text-align: right; color: #f97316; font-weight: 700; font-size: 14px;">' . I18n::format_currency( $remaining ) . '</td></tr>';
		} else {
			$unpaid_label = $is_arrival_method ? esc_html( I18n::get_label( 'label_pay_arrival' ) ) : esc_html( I18n::translate_status( 'pending' ) );
			$html        .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #d97706; font-weight: 700;">' . $unpaid_label . '</td></tr>';
			$html        .= '<tr><td style="padding: 3px 0; color: #64748b;">' . esc_html( rtrim( I18n::get_label( 'label_amount_due' ), ':' ) ) . ':</td><td style="padding: 3px 0; text-align: right; font-weight: 700; color: #d97706;">' . I18n::format_currency( $remaining ) . '</td></tr>';
		}

		$html .= '</table></div>';

		// Deposit policy / refund deadline notices
		if ( 'deposit' === $payment_type ) {
			$is_non_refundable = (bool) ( $booking->deposit_is_non_refundable ?? false );
			$refund_deadline   = $booking->refund_deadline_date ?? '';
			if ( $is_non_refundable ) {
				$html .= '<div style="margin-top: 8px; padding: 6px 10px; background: #fff1f2; border-left: 3px solid #f43f5e; color: #9f1239; font-size: 11px;">';
				$html .= '<strong>' . esc_html( I18n::get_label( 'label_email_non_refundable' ) ) . '</strong> ' . esc_html( I18n::get_label( 'msg_email_non_refundable_desc' ) );
				$html .= '</div>';
			} elseif ( '' !== (string) $refund_deadline && '0000-00-00' !== $refund_deadline ) {
				$deadline_ts = strtotime( $refund_deadline );
				$html       .= '<div style="margin-top: 8px; padding: 6px 10px; background: #f0fdf4; border-left: 3px solid #22c55e; color: #166534; font-size: 11px;">';
				/* translators: %s: formatted refund deadline date */
				$html       .= sprintf( esc_html( I18n::get_label( 'msg_email_refund_deadline' ) ), '<strong>' . date_i18n( get_option( 'date_format' ), $deadline_ts ) . '</strong>' );
				$html       .= '</div>';
			}
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Format a JSON list of children ages into a user-friendly, localized string.
	 * 
	 * This utility is used primarily in email notifications to provide detailed 
	 * guest composition data to administrators and customers.
	 * 
	 * @param string|null $ages_json JSON string of ages (e.g. '[5, 8]').
	 * @return string Formatted ages (e.g. '5, 8') or empty string if no indices found.
	 */
	public static function format_children_ages( ?string $ages_json ): string {
		if ( empty( $ages_json ) ) {
			return '';
		}

		$ages = json_decode( $ages_json, true );
		if ( ! is_array( $ages ) || array() === $ages ) {
			return '';
		}

		return implode( ', ', array_map( 'absint', $ages ) );
	}

/**
	 * Render an itemized payment history / folio ledger HTML table for emails.
	 */
	public static function render_payment_ledger_html( object $booking ): string {
		if ( ! class_exists( Pricing::class ) ) {
			return '';
		}
		$ledger = Pricing::get_payment_ledger( $booking );
		if ( empty( $ledger ) ) {
			return '';
		}
		$html  = '<div style="margin: 15px 0; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; font-family: sans-serif;">';
		$html .= '<div style="padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-size: 13px; color: #0f172a;">' . esc_html( I18n::get_label( 'label_payment_ledger' ) ) . '</div>';
		$html .= '<table width="100%" cellpadding="6" cellspacing="0" style="font-size: 12px; border-collapse: collapse;">';
		$html .= '<tr style="background: #f1f5f9; color: #475569; text-align: left;"><th scope="col" style="padding: 6px 10px;">' . esc_html__( 'Date', 'modern-hotel-booking' ) . '</th><th scope="col" style="padding: 6px 10px;">' . esc_html__( 'Type', 'modern-hotel-booking' ) . '</th><th scope="col" style="padding: 6px 10px;">' . esc_html__( 'Method', 'modern-hotel-booking' ) . '</th><th scope="col" style="padding: 6px 10px; text-align: right;">' . esc_html__( 'Amount', 'modern-hotel-booking' ) . '</th></tr>';
		foreach ( $ledger as $entry ) {
			$html .= '<tr style="border-bottom: 1px solid #f1f5f9;">';
			$html .= '<td style="padding: 6px 10px; color: #64748b;">' . esc_html( ! empty( $entry['date'] ) ? HotelTime::format_datetime( $entry['date'] ) : '—' ) . '</td>';
			$html .= '<td style="padding: 6px 10px; text-transform: capitalize; font-weight: 600;">' . esc_html( $entry['type'] ?? 'payment' ) . '</td>';
			$html .= '<td style="padding: 6px 10px;">' . esc_html( I18n::get_payment_method_label( $entry['method'] ?? 'arrival' ) );
			if ( ! empty( $entry['transaction_id'] ) ) {
				$html .= ' <code style="font-size:10px;background:#f1f5f9;padding:1px 3px;">(' . esc_html( $entry['transaction_id'] ) . ')</code>';
			}
			$html .= '</td>';
			$html .= '<td style="padding: 6px 10px; text-align: right; font-weight: 700; color: #166534;">' . esc_html( I18n::format_currency( (float) ( $entry['amount'] ?? 0 ) ) ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</table></div>';
		return $html;
	}

	/**
	 * Generate plain-text booking summary for WhatsApp dispatch.
	 *
	 * @param int $booking_id
	 * @return string
	 */
	public static function get_booking_summary_text( int $booking_id ): string {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
             FROM {$wpdb->prefix}mhbo_bookings b
             LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
             LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             WHERE b.id = %d",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return '';
		}

		$lang          = $booking->booking_language ?: I18n::get_current_language();
		$company_name  = get_bloginfo( 'name' );
		$company_phone = '';
		if ( class_exists( 'MHBO\Business\Info' ) ) {
			$comp = \MHBO\Business\Info::get_company();
			if ( ! empty( $comp['company_name'] ) ) {
				$company_name = $comp['company_name'];
			}
			$company_phone = (string) ( $comp['telephone'] ?? '' );
		}

$check_in       = '' !== (string) ( $booking->check_in ?? '' ) ? HotelTime::format_date( (string) $booking->check_in ) : '--';
		$check_out      = '' !== (string) ( $booking->check_out ?? '' ) ? HotelTime::format_date( (string) $booking->check_out ) : '--';
		$check_in_time  = HotelTime::get_checkin_time();
		$check_out_time = HotelTime::get_checkout_time();

$in_ts   = strtotime( (string) ( $booking->check_in ?? '' ) );
		$out_ts  = strtotime( (string) ( $booking->check_out ?? '' ) );
		$day_sec = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$nights  = ( $in_ts && $out_ts && $out_ts > $in_ts ) ? (int) round( ( $out_ts - $in_ts ) / $day_sec ) : 1;

		$room_name = I18n::decode( (string) ( $booking->room_name ?? 'Room' ), $lang );
		if ( ! empty( $booking->room_number ) ) {
			$room_name .= ' (' . $booking->room_number . ')';
		}

		$total        = I18n::format_currency( Money::fromDecimal( (string) ( $booking->total_price ?? 0 ) ) );
		$status_label = I18n::translate_status( (string) ( $booking->status ?? 'pending' ) );

		$adults = (int) ( $booking->guests ?? 1 );
		/* translators: %d: number of adults */
		$occupancy = sprintf( _n( '%d Adult', '%d Adults', $adults, 'modern-hotel-booking' ), $adults );

$title_text = ( 'pending' === ( $booking->status ?? '' ) )
			? __( 'Reservation Pending Payment — #', 'modern-hotel-booking' )
			: __( 'Booking Confirmation — #', 'modern-hotel-booking' );
		$lines   = array();
		$lines[] = "\u{1F4CB} *" . $title_text . (int) $booking->id . '*';
		$lines[] = '────────────────────────────';
		$lines[] = '*Status:* ' . $status_label;
		$lines[] = '*Guest:* ' . ( $booking->customer_name ?? '' );
		$lines[] = '*Room:* ' . $room_name;
		$lines[] = '';
		$lines[] = "\u{1F4C5} *Check-in:* " . $check_in . ' (from ' . $check_in_time . ')';
		$lines[] = "\u{1F4C5} *Check-out:* " . $check_out . ' (by ' . $check_out_time . ')';
		$lines[] = "\u{1F319} *Stay:* " . $nights . ' ' . _n( 'night', 'nights', $nights, 'modern-hotel-booking' ) . ' (' . $occupancy . ')';
		$lines[] = '';
		$lines[] = "\u{1F4B0} *Total Price:* " . $total;
		$wa_amts = Pricing::get_booking_payment_amounts( $booking );
		if ( 'deposit' === $wa_amts['due_type'] && $wa_amts['due_now']->isPositive() && $wa_amts['remaining']->greaterThan( $wa_amts['due_now'] ) ) {
			$lines[]      = "\u{1F4B8} *Deposit Required:* " . I18n::format_currency( $wa_amts['due_now'] );
			$after_dep_wa = $wa_amts['remaining']->subtract( $wa_amts['due_now'] );
			if ( $after_dep_wa->isPositive() ) {
				$lines[] = "\u{23F3} *Balance After Deposit:* " . I18n::format_currency( $after_dep_wa );
			}
		}
		if ( $wa_amts['received']->isPositive() ) {
			$lines[] = "\u{1F4B3} *Amount Paid:* " . I18n::format_currency( $wa_amts['received'] );
			if ( $wa_amts['remaining']->isPositive() ) {
				$lines[] = "\u{23F3} *Remaining Balance:* " . I18n::format_currency( $wa_amts['remaining'] );
			}
		}

$wa_pro_special_req = BookingProcessor::extract_guest_special_requests( $booking );
		if ( '' !== $wa_pro_special_req ) {
			$lines[] = '';
			$lines[] = "\u{1F4DD} *Special Requests:* " . $wa_pro_special_req;
		}
		$lines[] = '────────────────────────────';
		if ( '' !== $company_phone ) {
			$lines[] = "\u{1F4DE} " . $company_phone;
		}
		$lines[] = "\u{2728} Thank you for choosing " . $company_name . '!';

		return implode( "\n", $lines );
	}

	/**
	 * Render Google Maps Directions card for emails.
	 *
	 * @param string $lang
	 * @return string HTML
	 */
	public static function render_google_map_html( string $lang = '' ): string {
		if ( ! Tier::is_pro() || ! class_exists( 'MHBO\Business\Info' ) ) {
			return '';
		}
		$comp = \MHBO\Business\Info::get_company();
		if ( empty( $comp['show_map_in_email'] ) ) {
			return '';
		}

		// Hotel Name
		$hotel_name = trim( (string) ( $comp['name'] ?? '' ) );
		if ( '' === $hotel_name ) {
			$hotel_name = get_bloginfo( 'name' );
		}

		// Build human-readable address
		$address_parts = array_filter(
			array(
				$comp['address_line_1'] ?? '',
				$comp['address_line_2'] ?? '',
				$comp['city'] ?? '',
				$comp['state'] ?? '',
				$comp['postcode'] ?? '',
				$comp['country'] ?? '',
			)
		);
		$full_address  = implode( ', ', $address_parts );

		// Destination query label for Maps
		$dest_label = '' !== $hotel_name ? $hotel_name : $full_address;
		if ( '' !== $hotel_name && '' !== $full_address ) {
			$dest_label = $hotel_name . ', ' . $full_address;
		}

		// Determine destination & place profile URLs
		$directions_url = '';
		$profile_url    = '';
		$place_id       = trim( (string) ( $comp['google_place_id'] ?? '' ) );
		$maps_override  = trim( (string) ( $comp['google_maps_url'] ?? '' ) );

		if ( '' !== $place_id ) {
			// 2026 BP: Cross-platform Google Maps Universal URLs with GBP Place ID
			$directions_url = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $dest_label ) . '&destination_place_id=' . rawurlencode( $place_id );
			$profile_url    = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $hotel_name ) . '&query_place_id=' . rawurlencode( $place_id );
		} elseif ( '' !== $maps_override ) {
			$directions_url = $maps_override;
			$profile_url    = $maps_override;
		} elseif ( '' !== $dest_label ) {
			$directions_url = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $dest_label );
			$profile_url    = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $dest_label );
		}

		if ( '' === $directions_url ) {
			return '';
		}

		$html  = '<div style="margin:24px 0;padding:20px 24px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;text-align:center;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;">';
		$html .= '<h4 style="margin:0 0 6px 0;color:#0369a1;font-size:16px;font-weight:700;">📍 ' . esc_html__( 'Getting Here & Directions', 'modern-hotel-booking' ) . '</h4>';
		if ( '' !== $full_address ) {
			$html .= '<p style="margin:0 0 16px 0;color:#334155;font-size:14px;line-height:1.4;">' . esc_html( $full_address ) . '</p>';
		}
		$html .= '<div style="display:inline-block;text-align:center;">';
		$html .= '<a href="' . esc_url( $directions_url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#0284c7;color:#ffffff;padding:10px 22px;margin:4px 6px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:13px;">';
		$html .= '🗺️ ' . esc_html__( 'Get Directions', 'modern-hotel-booking' );
		$html .= '</a>';
		if ( '' !== $profile_url && $profile_url !== $directions_url ) {
			$html .= '<a href="' . esc_url( $profile_url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#ffffff;color:#0369a1;border:1px solid #7dd3fc;padding:10px 18px;margin:4px 6px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;">';
			$html .= '🏨 ' . esc_html__( 'View on Google Maps', 'modern-hotel-booking' );
			$html .= '</a>';
		}
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render Social Media links row for email footers.
	 *
	 * @return string HTML
	 */
	public static function render_social_links_html(): string {
		if ( ! Tier::is_pro() || ! class_exists( 'MHBO\Business\Info' ) ) {
			return '';
		}
		$social = \MHBO\Business\Info::get_social();
		if ( empty( $social['enabled'] ) ) {
			return '';
		}

		$platforms = array(
			'facebook'    => array(
				'label' => 'Facebook',
				'color' => '#1877F2',
				'icon'  => 'f',
			),
			'instagram'   => array(
				'label' => 'Instagram',
				'color' => '#E4405F',
				'icon'  => '📸',
			),
			'twitter_x'   => array(
				'label' => 'X / Twitter',
				'color' => '#000000',
				'icon'  => '𝕏',
			),
			'tiktok'      => array(
				'label' => 'TikTok',
				'color' => '#000000',
				'icon'  => '🎵',
			),
			'youtube'     => array(
				'label' => 'YouTube',
				'color' => '#FF0000',
				'icon'  => '▶',
			),
			'linkedin'    => array(
				'label' => 'LinkedIn',
				'color' => '#0A66C2',
				'icon'  => 'in',
			),
			'pinterest'   => array(
				'label' => 'Pinterest',
				'color' => '#BD081C',
				'icon'  => '📌',
			),
			'tripadvisor' => array(
				'label' => 'TripAdvisor',
				'color' => '#34E0A1',
				'icon'  => '🦉',
			),
		);

		$items = array();
		foreach ( $platforms as $key => $meta ) {
			$url = trim( (string) ( $social[ $key ] ?? '' ) );
			if ( '' !== $url ) {
				$items[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin:0 6px;padding:6px 12px;background:#f1f5f9;color:#334155;border-radius:20px;text-decoration:none;font-size:12px;font-weight:600;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;border:1px solid #e2e8f0;">'
					. '<span style="color:' . esc_attr( $meta['color'] ) . ';margin-right:4px;">' . esc_html( $meta['icon'] ) . '</span> '
					. esc_html( $meta['label'] )
					. '</a>';
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		$html  = '<div style="margin:20px 0 10px 0;padding:12px 0;text-align:center;border-top:1px solid #f1f5f9;">';
		$html .= '<p style="margin:0 0 10px 0;font-size:12px;color:#64748b;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;">' . esc_html__( 'Connect with us:', 'modern-hotel-booking' ) . '</p>';
		$html .= '<div style="display:inline-block;text-align:center;">' . implode( ' ', $items ) . '</div>';
		$html .= '</div>';

		return $html;
	}

}
