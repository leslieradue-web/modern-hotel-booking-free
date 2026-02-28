<?php declare(strict_types=1);

/**
 * I18n — Multilingual Abstraction Layer
 *
 * Supports qTranslate-X, WPML, Polylang, and vanilla WordPress.
 * Internal storage format: [:en]English[:ro]Romanian[:de]German[:]
 *
 * @package MHB\Core
 * @since   2.0.0
 */

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

class I18n
{
    /**
     * Initialize translation filters.
     */
    public static function init()
    {
        add_filter('gettext_modern-hotel-booking', array(self::class, 'filter_gettext'), 10, 3);
    }

    /**
     * Filter plugin translations to prevent blank strings.
     * 
     * If a translation is found to be empty or just whitespace in the .mo file,
     * WP returns it as is. We force it to fallback to the original English string.
     *
     * @param string $translated
     * @param string $text
     * @param string $domain
     * @return string
     */
    public static function filter_gettext($translated, $text, $domain)
    {
        if (empty(trim($translated))) {
            return $text;
        }
        return $translated;
    }
    /**
     * Detect which multilingual plugin is active.
     *
     * @return string 'qtranslate'|'wpml'|'polylang'|'none'
     */
    public static function detect_plugin()
    {
        if (defined('QTX_VERSION') || function_exists('qtranxf_getLanguage')) {
            return 'qtranslate';
        }
        if (defined('ICL_SITEPRESS_VERSION') || function_exists('icl_get_languages')) {
            return 'wpml';
        }
        if (function_exists('pll_current_language') || defined('POLYLANG_VERSION')) {
            return 'polylang';
        }
        return 'none';
    }

    /**
     * Helper to get the 2-letter locale code.
     *
     * @return string
     */
    private static function locale_code()
    {
        return substr(get_locale(), 0, 2);
    }

    /**
     * Get current front-end/admin language code (2-letter).
     *
     * @return string
     */
    public static function get_current_language()
    {
        // Handle admin side language selection via URL param (e.g., ?lang=ro)
        if (is_admin() && isset($_GET['lang'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter, no state change
            return sanitize_key(wp_unslash($_GET['lang'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        switch (self::detect_plugin()) {
            case 'qtranslate':
                return function_exists('qtranxf_getLanguage') ? call_user_func('qtranxf_getLanguage') : self::locale_code();
            case 'wpml':
                return apply_filters('wpml_current_language', self::locale_code());
            case 'polylang':
                $lang = function_exists('pll_current_language') ? call_user_func('pll_current_language') : self::locale_code();
                return $lang ? $lang : self::locale_code();
            default:
                return self::locale_code();
        }
    }

    /**
     * Get default site language code.
     *
     * @return string
     */
    public static function get_default_language()
    {
        switch (self::detect_plugin()) {
            case 'qtranslate':
                global $q_config;
                return isset($q_config['default_language']) ? $q_config['default_language'] : 'en';
            case 'wpml':
                return apply_filters('wpml_default_language', 'en');
            case 'polylang':
                return function_exists('pll_default_language') ? call_user_func('pll_default_language') : 'en';
            default:
                return 'en';
        }
    }

    /**
     * Get all available languages as an array of 2-letter codes.
     *
     * @return string[]
     */
    public static function get_available_languages()
    {
        switch (self::detect_plugin()) {
            case 'qtranslate':
                global $q_config;
                return isset($q_config['enabled_languages']) ? $q_config['enabled_languages'] : array(self::locale_code());

            case 'wpml':
                $langs = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));
                return is_array($langs) ? array_keys($langs) : array(self::locale_code());

            case 'polylang':
                if (function_exists('pll_languages_list')) {
                    $list = call_user_func('pll_languages_list', array('fields' => 'slug'));
                    return !empty($list) ? $list : array(self::locale_code());
                }
                return array(self::locale_code());

            default:
                $langs = array_unique(array('en', self::locale_code()));
                return apply_filters('mhb_i18n_get_available_languages', array_values($langs));
        }
    }

    /**
     * Decode a multilingual string.
     * Format: [:en]Hello[:ro]Salut[:]
     *
     * @param string      $text The string to decode.
     * @param string|null $lang Optional language code.
     * @param bool        $fallback Whether to fallback to other languages if requested is missing.
     * @return string|null
     */
    public static function decode($text, $lang = null, $fallback = true)
    {
        if (empty($text) || !is_string($text)) {
            return $text;
        }

        if (!$lang) {
            $lang = self::get_current_language();
        }

        // qTranslate style parsing - leverage native function if available (only if fallback is enabled)
        if ($fallback && function_exists('qtranxf_use')) {
            return qtranxf_use($lang, $text);
        }

        // Detect if this is a plain string (no multilingual tags)
        $is_plain = false === strpos($text, '[:');

        // Handle plain strings on multilingual sites
        // If it's a plain string AND we are on a multilingual site AND fallback is disabled,
        // treat it as not translated for ANY specific language request.
        // This allows the caller to fall back to other localized settings (like translated pages).
        if ($is_plain && 'none' !== self::detect_plugin() && !$fallback) {
            return null;
        }

        // Manual parsing fallback for single-language strings or when fallback is allowed
        if ($is_plain) {
            return $text;
        }

        // qTranslate style parsing
        // Supports both 2-letter codes [:en] and 5-letter codes [:ro_RO]
        $blocks = preg_split('/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($blocks) < 3) {
            return $text;
        }

        // Build a map of languages to content
        $map = [];
        for ($i = 1; $i < count($blocks); $i += 2) {
            if (isset($blocks[$i + 1])) {
                $lang_key = strtolower($blocks[$i]);
                $content = $blocks[$i + 1];

                // Strip optional closing tag [:] if it was caught in the split
                if (substr($content, -3) === '[:]') {
                    $content = substr($content, 0, -3);
                }

                $map[$lang_key] = $content;
            }
        }

        // Normalize requested language
        $lang = strtolower($lang);
        $lang_short = substr($lang, 0, 2);

        // 1. Try exact match (e.g. ro_RO == ro_RO)
        if (!empty($map[$lang])) {
            return $map[$lang];
        }

        // 2. Try prefix match (e.g. ro requested, ro_RO available)
        if (2 === strlen($lang)) {
            foreach ($map as $k => $v) {
                if (0 === strpos($k, $lang . '_')) {
                    return $v;
                }
            }
        }

        // 3. Try short match (e.g. ro_RO requested, ro available)
        if (!empty($map[$lang_short])) {
            return $map[$lang_short];
        }

        // If no fallback allowed, stop here
        if (!$fallback) {
            return null;
        }

        // 4. Fallback to default language
        $default = strtolower(self::get_default_language());
        if (!empty($map[$default])) {
            return $map[$default];
        }
        $default_short = substr($default, 0, 2);
        if (!empty($map[$default_short])) {
            return $map[$default_short];
        }

        // 5. Fallback to English
        if (!empty($map['en'])) {
            return $map['en'];
        }

        // 6. Final fallback to the first available non-empty block
        foreach ($map as $val) {
            if (!empty($val)) {
                return apply_filters('mhb_i18n_decode', $val, $text, $lang);
            }
        }

        // 7. Final, final fallback: strip all tags
        $result = preg_replace('/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', '', $text);
        $result = str_replace('[:]', '', $result);

        return apply_filters('mhb_i18n_decode', trim($result) ?: $text, $text, $lang);
    }

    /**
     * Encode an array of [lang => text] into a multilingual string.
     *
     * @param array $values
     * @return string
     */
    public static function encode($values)
    {
        if (!is_array($values)) {
            return $values;
        }
        $out = '';
        foreach ($values as $lang => $text) {
            if (!empty($text)) {
                $out .= "[:{$lang}]{$text}";
            }
        }
        if (!empty($out)) {
            $out .= '[:]';
        }
        return $out;
    }

    /**
     * Translate a string with optional language support.
     * 
     * This is a wrapper for WordPress __() function with language-specific decoding.
     *
     * @param string $text Text to translate.
     * @param string $domain Text domain (default: 'modern-hotel-booking').
     * @param string|null $language Optional language code for multilingual strings.
     * @return string Translated text.
     */
    public static function __($text, $domain = 'modern-hotel-booking', $language = null)
    {
        // First, get the WordPress translation
        $translated = __($text, $domain); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain

        // If translation is empty, fallback to the original English text
        if (empty($translated)) {
            $translated = $text;
        }

        // If a specific language is requested, decode any multilingual format
        if (false !== strpos($translated, '[:')) {
            $decoded = self::decode($translated, $language);
            if (!empty($decoded)) {
                return $decoded;
            }
        }

        return $translated;
    }

    /**
     * Format currency based on site settings.
     *
     * @param float|int $amount
     * @return string
     */
    public static function format_currency($amount)
    {
        $symbol = get_option('mhb_currency_symbol', '$');
        $position = get_option('mhb_currency_position', 'before');
        $decimal_separator = apply_filters('mhb_currency_decimal_separator', '.');
        $thousand_separator = apply_filters('mhb_currency_thousand_separator', ',');
        $decimals = apply_filters('mhb_currency_decimals', 0);

        $formatted = number_format((float) $amount, $decimals, $decimal_separator, $thousand_separator);

        if ('before' === $position) {
            return $symbol . $formatted;
        }
        return $formatted . $symbol;
    }

    /**
     * Format a date string based on WP settings.
     *
     * @param string $date_string
     * @return string
     */
    public static function format_date($date_string)
    {
        if (empty($date_string)) {
            return '';
        }
        return date_i18n(get_option('date_format'), strtotime($date_string));
    }

    /**
     * Get a localized label for front-end/admin use.
     *
     * @param string $key
     * @return string
     */
    public static function get_label($key)
    {
        // Check for database override first
        $override = get_option("mhb_label_{$key}");

        $labels = self::get_all_default_labels();
        $default_val = isset($labels[$key]) ? $labels[$key] : $key;

        $value = !empty($override) ? $override : $default_val;

        // Check for translated string via WPML/Polylang
        // Pass the value as default so plugins can find/translate it
        $translated = self::get_translated_string("Label: {$key}", $value, 'MHB Frontend Labels');

        // If translation is found and not empty, decode it (it might still be qTranslate format)
        if (!empty($translated)) {
            $decoded = self::decode($translated);
            if (!empty($decoded)) {
                return $decoded;
            }
        }

        // Fallback to English if the result is empty
        // This ensures buttons/labels are never blank
        if (isset($labels[$key])) {
            return self::decode($labels[$key], 'en');
        }

        return $key;
    }

    /**
     * Get all default labels in multilingual format.
     *
     * @return array
     */
    public static function get_all_default_labels()
    {
        return array(
            'btn_search_rooms' => __('Search Rooms', 'modern-hotel-booking'),
            'label_check_in' => __('Check-in', 'modern-hotel-booking'),
            'label_check_out' => __('Check-out', 'modern-hotel-booking'),
            // translators: %s: check-in time (e.g., 14:00)
            'label_check_in_from' => '[:en]from %s[:ro]de la %s[:de]ab %s[:es]desde %s[:fr]à partir de %s[:it]dalle %s[:pt]a partir das %s[:nl]vanaf %s[:ru]с %s[:zh]从 %s[:ja]%s から[:ar]من %s[:tr]saat %s[:]',
            // translators: %s: check-out time (e.g., 11:00)
            'label_check_out_by' => '[:en]by %s[:ro]până la %s[:de]bis %s[:es]antes de %s[:fr]avant %s[:it]entro le %s[:pt]até %s[:nl]vóór %s[:ru]до %s[:zh]%s 前[:ja]%s まで[:ar]بحلول %s[:tr]saat %s\'e kadar[:]',
            'label_guests' => __('Guests', 'modern-hotel-booking'),
            'label_children' => '[:en]Children[:ro]Copii[:de]Kinder[:es]Niños[:fr]Enfants[:it]Bambini[:pt]Crianças[:nl]Kinderen[:ru]Дети[:zh]儿童[:ja]子供[:ar]أطفال[:tr]Çocuklar[:]',
            'label_child_ages' => '[:en]Child Ages[:ro]Vârste Copii[:de]Alter der Kinder[:es]Edades de los niños[:fr]Âges des enfants[:it]Età dei bambini[:pt]Idades das crianças[:nl]Leeftijden van kinderen[:ru]Возраст детей[:zh]儿童年龄[:ja]子供の年齢[:ar]أعمار الأطفال[:tr]Çocuk Yaşları[:]',
            'label_child_n_age' => '[:en]Child %d Age[:ro]Vârsta Copil %d[:de]Alter Kind %d[:es]Edad del niño %d[:fr]Âge de l\'enfant %d[:it]Età del bambino %d[:pt]Idade da criança %d[:nl]Leeftijd kind %d[:ru]Возраст ребенка %d[:zh]儿童 %d 年龄[:ja]子供 %d の年齢[:ar]عمر الطفل %d[:tr]Çocuk %d Yaşı[:]',
            'label_guest' => __('Guest', 'modern-hotel-booking'),
            // translators: 1: check-in date, 2: check-out date
            'label_available_rooms' => __('Available Rooms from %1$s to %2$s', 'modern-hotel-booking'),
            'label_no_rooms' => __('No rooms available for these dates.', 'modern-hotel-booking'),
            'label_per_night' => '[:en]per night[:ro]pe noapte[:de]pro Nacht[:es]por noche[:fr]par nuit[:it]por notte[:pt]por noite[:nl]per nacht[:ru]за nacht[:zh]每晚[:ja]1泊あたり[:ar]لكل ليلة[:tr]gecelik[:]',
            'label_total_nights' => '[:en]%d nights: %s[:ro]%d nopți: %s[:de]%d Nächte: %s[:es]%d noches: %s[:fr]%d nuits: %s[:it]%d notti: %s[:pt]%d noites: %s[:nl]%d nachten: %s[:ru]%d ночей: %s[:zh]%d 晚: %s[:ja]%d 泊: %s[:ar]%d ليالي: %s[:tr]%d gece: %s[:]',
            'label_max_guests' => '[:en]Max guests: %d[:ro]Nr. maxim de oaspeți: %d[:de]Max. Gäste: %d[:es]Máximo de personas: %d[:fr]Max d\'invités: %d[:it]Massimo ospiti: %d[:pt]Máximo de hóspedes: %d[:nl]Max gasten: %d[:ru]Макс. гостей: %d[:zh]最大人数: %d[:ja]最大宿泊人数: %d[:ar]أقصى عدد للضيوف: %d[:tr]Maksimum misafir: %d[:]',
            'btn_book_now' => '[:en]Book Now[:ro]Rezervă Acum[:de]Jetzt buchen[:es]Reservar ahora[:fr]Réserver maintenant[:it]Prenota ora[:pt]Reservar agora[:nl]Nu boeken[:ru]Забронировать[:zh]立即预订[:ja]今すぐ予約[:ar]احجز الآن[:tr]Şimdi Rezervasyon Yap[:]',
            'label_complete_booking' => '[:en]Complete Your Booking[:ro]Finalizează Rezervarea[:de]Buchung abschließen[:es]Complete su reserva[:fr]Complétez votre réservation[:it]Completa la tua prenotazione[:pt]Conclua sua reserva[:nl]Voltooi uw boeking[:ru]Завершите бронирование[:zh]完成预订[:ja]予約を完了する[:ar]أكمل حجزك[:tr]Rezervasyonunuzu Tamamlayın[:]',
            'label_total' => '[:en]Total Price[:ro]Preț Total[:de]Gesamtpreis[:es]Precio total[:fr]Prix total[:it]Prezzo totale[:pt]Preço total[:nl]Totale prijs[:ru]Итого[:zh]总价[:ja]合計料金[:ar]السعر الإجمالي[:tr]Toplam Fiyat[:]',
            'label_name' => '[:en]Full Name[:ro]Nume Complet[:de]Vollständiger Name[:es]Nombre completo[:fr]Nom complet[:it]Nome completo[:pt]Nome completo[:nl]Volledige naam[:ru]Полное имя[:zh]全名[:ja]フルネーム[:ar]الاسم الكامل[:tr]Ad Soyad[:]',
            'label_email' => '[:en]Email Address[:ro]Adresă de Email[:de]E-Mail-Address[:es]Correo electrónico[:fr]Adresse e-mail[:it]Indirizzo email[:pt]Endereço de e-mail[:nl]E-mailadres[:ru]Электронная почта[:zh]电子邮件[:ja]メールアドレス[:ar]البريد الإلكتروني[:tr]E-posta Adresi[:]',
            'label_phone' => '[:en]Phone Number[:ro]Număr de Telefon[:de]Telefonnummer[:es]Número de teléfono[:fr]Numéro de téléphone[:it]Numero di telefono[:pt]Número de telefone[:nl]Telefoonnummer[:ru]Номер телефона[:zh]电话号码[:ja]電話番号[:ar]رقم الهاتف[:tr]Telefon Numarası[:]',
            'btn_confirm_booking' => '[:en]Confirm Booking[:ro]Confirmă Rezervarea[:de]Buchung bestätigen[:es]Confirmar reserva[:fr]Confirmer la réservation[:it]Conferma prenotazione[:pt]Confirmar reserva[:nl]Boeking bevestigen[:ru]Подтвердить бронирование[:zh]确认预订[:ja]予約を確定する[:ar]تأكيد الحجز[:tr]Rezervasyonu Onayla[:]',
            'btn_pay_confirm' => '[:en]Pay & Confirm[:ro]Plătește și Confirmă[:de]Bezahlen & Bestätigen[:es]Pagar y confirmar[:fr]Payer et confirmer[:it]Paga e confirma[:pt]Pagar e confirmar[:nl]Betalen & bevestigen[:ru]Оплатить и подтвердить[:zh]支付并确认[:ja]支払って確定する[:ar]الدفع والتأكيد[:tr]Öde ve Onayla[:]',
            'msg_booking_confirmed' => '[:en]Booking Confirmed![:ro]Rezervare Confirmată![:de]Buchung bestätigt![:es]¡Reserva confirmada![:fr]Réservation confirmée![:it]Prenotazione confermata![:pt]Reserva confirmada![:nl]Boeking bevestigd![:ru]Бронирование подтверждено![:zh]预订已确认！[:ja]予約が確定しました！[:ar]تم تأكيد الحجز![:tr]Rezervasyon Onaylandı![:]',
            'msg_confirmation_sent' => '[:en]A confirmation email has been sent to you.[:ro]Un e-mail de confirmare v-a fost trimis.[:de]Eine Bestätigungs-E-Mail wurde an Sie gesendet.[:es]Se le ha enviado un correo electrónico de confirmación.[:fr]Un e-mail de confirmation vous a été envoyé.[:it]Ti è stata inviata un\'email di conferma.[:pt]Um e-mail de confirmação foi enviado para você.[:nl]Er is een bevestigingsmail naar u verzonden.[:ru]Вам было отправлено письмо с подтверждением.[:zh]确认邮件已发送至您的邮箱。[:ja]確認メールが送信されました。[:ar]تم إرسال بريد إلكتروني للتأكيد إليك.[:tr]Onay e-postası tarafınıza gönderilmiştir.[:]',
            'msg_booking_received' => '[:en]Booking Pending[:ro]Rezervare în Așteptare[:de]Buchung ausstehend[:es]Reserva pendiente[:fr]Réservation en attente[:it]Prenotazione in sospeso[:pt]Reserva pendente[:nl]Boeking in behandeling[:ru]Бронирование в ожидании[:zh]预订待处理[:ja]予約保留中[:ar]الحجز قيد الانتظار[:tr]Rezervasyon Beklemede[:]',
            'msg_booking_received_detail' => '[:en]We have received your request and will contact you shortly.[:ro]Am primit cererea dumneavoastră și vă vom contacta în curând.[:de]Wir haben Ihre Anfrage erhalten und werden uns in Kürze bei Ihnen melden.[:es]Hemos recibido su solicitud y nos pondremos en contacto con usted a la brevedad.[:fr]Nous avons reçu votre demande et vous contacterons sous peu.[:it]Abbiamo ricevuto la tua richiesta e ti contatteremo a breve.[:pt]Recebemos sua solicitação e entraremos em contato em breve.[:nl]We hebben uw aanvraag ontvangen en nemen binnenkort contact met u op.[:ru]Мы получили ваш запрос и свяжемся с вами в ближайшее время.[:zh]我们已收到您的请求，将尽快กับ您联系。[:ja]リクエストを確認しました。まもなくご連絡いたします。[:ar]لقد استلمنا طلبك وسنتصل بك قريباً.[:tr]Talebiniz alınmıştır, en kısa sürede sizinle iletişime geçilecektir.[:]',
            'label_arrival_msg' => '[:en]You will pay %s upon arrival at the hotel.[:ro]Veți plăti %s la sosirea la hotel.[:de]Sie zahlen %s bei der Ankunft im Hotel.[:es]Pagará %s a su llegada al hotel.[:fr]Vous paierez %s à votre arrivée à l\'hôtel.[:it]Pagherai %s all\'arrivo in hotel.[:pt]Você pagará %s na chegada ao hotel.[:nl]U betaalt %s bij aankomst in het hotel.[:ru]Вы оплатите %s по прибытии в отель.[:zh]您将在到达酒店时支付 %s。[:ja]到着時に %s をお支払いいただきます。[:ar]سوف تدفع %s عند الوصول إلى الفندق.[:tr]Otele vardığınızda %s ödeyeceksiniz.[:]',
            'label_payment_method' => '[:en]Payment Method[:ro]Metodă de Plată[:de]Zahlungsart[:es]Método de pago[:fr]Mode de paiement[:it]Metodo di pagamento[:pt]Método de pagamento[:nl]Betaalmethode[:ru]Способ оплаты[:zh]付款方式[:ja]支払い方法[:ar]طريقة الدفع[:tr]Ödeme Yöntemi[:]',
            'label_pay_arrival' => '[:en]Pay on Arrival[:ro]Plată la sosire[:de]Zahlung vor Ort[:es]Pago a la llegada[:fr]Payer à l\'arrivée[:it]Paga all\'arrivo[:pt]Pagar na chegada[:nl]Betalen bij aankomst[:ru]Оплата по прибытии[:zh]到店付款[:ja]現地払い[:ar]الدفع عند الوصول[:tr]Varışta Öde[:]',
            'label_special_requests' => '[:en]Special Requests / Notes[:ro]Cereri Speciale / Note[:de]Besondere Wünsche / Anmerkungen[:es]Peticiones especiales / Notas[:fr]Demandes spéciales / Notes[:it]Richieste speciali / Note[:pt]Pedidos especiais / Notas[:nl]Speciale verzoeken / opmerkingen[:ru]Особые пожелания / Примечания[:zh]特殊要求/备注[:ja]特別なリクエスト / 備考[:ar]طلبات خاصة / ملاحظات[:tr]Özel İstekler / Notlar[:]',
            'label_select_check_in' => '[:en]Select your check-in date[:ro]Selectați data de check-in[:de]Wählen Sie Ihr Anreisedatum[:es]Seleccione su fecha de entrada[:fr]Sélectionnez votre date d\'arrivée[:it]Seleziona la data di arrivo[:pt]Selecione a data de check-in[:nl]Selecteer uw aankomstdatum[:ru]Выберите дату заезда[:zh]选择入住日期[:ja]チェックイン日を選択してください[:ar]اختر تاريخ تسجيل الوصول[:tr]Giriş tarihinizi seçin[:]',
            'label_select_check_out' => '[:en]Now select your check-out date[:ro]Acum selectați data de check-out[:de]Wählen Sie nun Ihr Abreisedatum[:es]Ahora seleccione su fecha de salida[:fr]Maintenant, sélectionnez votre date de départ[:it]Ora seleziona la data de partenza[:pt]Agora selecione a data de check-out[:nl]Selecteer nu uw vertrekdatum[:ru]Теперь выберите дату выезда[:zh]选择退房日期[:ja]チェックアウト日を選択してください[:ar]الآن اختر تاريخ تسجيل المغادرة[:tr]Şimdi çıkış tarihinizi seçin[:]',
            'label_stay_dates' => '[:en]Stay Dates[:ro]Perioada Sejurului[:de]Aufenthaltsdaten[:es]Fechas de estancia[:fr]Dates de séjour[:it]Date del soggiorno[:pt]Datas da estadia[:nl]Verblijfsdata[:ru]Даты пребывания[:zh]入住日期[:ja]宿泊日程[:ar]تواريخ الإقامة[:tr]Konaklama Tarihleri[:]',
            'label_select_dates' => '[:en]Select Dates[:ro]Selectați Datele[:de]Daten auswählen[:es]Seleccionar fechas[:fr]Sélectionner les dates[:it]Seleziona le date[:pt]Selecionar datas[:nl]Data selecteren[:ru]Выберите даты[:zh]选择日期[:ja]日程を選択[:ar]اخter التواريخ[:tr]Tarihleri Seçin[:]',
            'label_your_selection' => '[:en]Your Selection[:ro]Selecția ta[:de]Ihre Auswahl[:es]Su selección[:fr]Votre sélection[:it]La tua selezione[:pt]Sua seleção[:nl]Uw selectie[:ru]Ваш выбор[:zh]您的选择[:ja]選択内容[:ar]اختيارك[:tr]Seçiminiz[:]',
            'label_continue_booking' => '[:en]Continue to Booking[:ro]Continuă spre rezervare[:de]Weiter zur Buchung[:es]Continuar con la reserva[:fr]Continuer vers la réservation[:it]Continua con la prenotazione[:pt]Continuar para a reserva[:nl]Doorgaan naar boeking[:ru]Продолжить бронирование[:zh]继续预订[:ja]予約を続ける[:ar]المتابعة إلى الحجز[:tr]Rezervasyona Devam Et[:]',
            'label_dates_selected' => '[:en]Dates selected. Complete the form below.[:ro]Datele au fost selectate. Completați formularul de mai jos.[:de]Termine ausgewählt. Vervollständigen Sie das Formular unten.[:es]Fechas seleccionadas. Complete el formulario a continuación.[:fr]Dates sélectionnées. Remplissez le formulaire ci-dessous.[:it]Date selezionate. Completa il modulo sottostante.[:pt]Datas selecionadas. Preencha o formulário abaixo.[:nl]Data geselecteerd. Vul het onderstaande formulier in.[:ru]Даты выбраны. Заполните форму ниже.[:zh]日期已选定。请填写下表。[:ja]日程が選択されました。以下のフォームに入力してください。[:ar]تم اختيار التواريخ. أكمل النموذج أدناه.[:tr]Tarihler seçildi. Aşağıdaki formu doldurun.[:]',
            'label_credit_card' => '[:en]Credit Card[:ro]Card de Credit[:de]Kreditkarte[:es]Tarjeta de crédito[:fr]Carte de crédit[:it]Carta di credito[:pt]Cartão de crédito[:nl]Creditcard[:ru]Кредитная карта[:zh]信用卡[:ja]クレジットカード[:ar]بطاقة ائتمان[:tr]Kredi Kartı[:]',
            'label_paypal' => '[:en]PayPal[:ro]PayPal[:de]PayPal[:es]PayPal[:fr]PayPal[:it]PayPal[:pt]PayPal[:nl]PayPal[:ru]PayPal[:zh]PayPal[:ja]PayPal[:ar]PayPal[:tr]PayPal[:]',
            'label_confirm_request' => '[:en]Click below to confirm your booking request.[:ro]Faceți clic mai jos pentru a confirma cererea de rezervare.[:de]Klicken Sie unten, um Ihre Buchungsanfrage zu bestätigen.[:es]Haga clic a continuación para confirmar su solicitud de reserva.[:fr]Cliquez ci-dessous pour confirmer votre demande de réservation.[:it]Clicca sotto per confermare la tua richiesta di prenotazione.[:pt]Clique abaixo para confirmar sua solicitação de reserva.[:nl]Klik hieronder om uw boekingsaanvraag te bevestigen.[:ru]Нажмите ниже, чтобы подтвердить запрос na бронирование.[:zh]点击下方确认您的预订请求。[:ja]下のボタンをクリックして予約リクエストを確定してください。[:ar]انقر أدناه لتأكيد طلب حجزك.[:tr]Rezervasyon talebinizi onaylamak için aşağıya tıklayın.[:]',
            'label_tax_breakdown' => '[:en]%s Breakdown[:ro]Defalcare %s[:de]%s Aufschlüsselung[:es]Desglose de %s[:fr]Répartition %s[:it]Ripartizione %s[:pt]Detalhamento %s[:nl]%s Specificatie[:ru]Разбивка %s[:zh]%s 明细[:ja]%s 内訳[:ar]تفصيل %s[:tr]%s Dökümü[:]',
            'label_tax_total' => '[:en]Total %s: %s[:ro]Total %s: %s[:de]Gesamt %s: %s[:es]Total %s: %s[:fr]Total %s: %s[:it]Totale %s: %s[:pt]Total %s: %s[:nl]Totaal %s: %s[:ru]Итого %s: %s[:zh]%s 总计: %s[:ja]%s 合計: %s[:ar]إجمالي %s: %s[:tr]Toplam %s: %s[:]',
            'label_tax_registration' => '[:en]Tax Registration: %s[:ro]Înregistrare Fiscală: %s[:de]Steuernummer: %s[:es]Registro Fiscal: %s[:fr]Enregistrement fiscal: %s[:it]Registrazione Fiscale: %s[:pt]Registro Fiscal: %s[:nl]Belastingregistratie: %s[:ru]Налоговая регистрация: %s[:zh]税务登记: %s[:ja]税務登録: %s[:ar]التسجيل الضريبي: %s[:tr]Vergi Kaydı: %s[:]',
            'label_includes_tax' => '[:en](includes %s)[:ro](include %s)[:de](inkl. %s)[:es](incluye %s)[:fr](inclut %s)[:it](include %s)[:pt](inclui %s[:nl](inclusief %s)[:ru](включает %s)[:zh](含 %s)[:ja](%s 込)[:ar](يشمل %s)[:tr](%s dahil)[:]',
            'label_price_includes_tax' => '[:en]Price includes %s (%s%%)[:ro]Prețul include %s (%s%%)[:de]Preis enthält %s (%s%%)[:es]El precio incluye %s (%s%%)[:fr]Le prix inclut %s (%s%%)[:it]Il prezzo include %s (%s%%)[:pt]O preço incluye %s (%s%%)[:nl]Prijs inclusief %s (%s%%)[:ru]Precio включает %s (%s%%)[:zh]价格包含 %s (%s%%)[:ja]価格には %s (%s%%) が含まれます[:ar]السعر يشمل %s (%s%%)[:tr]Fiyat %s (%s%%) içerir[:]',
            'label_tax_added_at_checkout' => '[:en]%s (%s%%) will be added at checkout[:ro]%s (%s%%) va fi adăugat la finalizare[:de]%s (%s%%) werden an der Kasse hinzugefügt[:es]%s (%s%%) se añadirá en el pago[:fr]%s (%s%%) seront ajoutés au paiement[:it]%s (%s%%) verranno aggiunti al pagamento[:pt]%s (%s%%) serão adicionados no pagamento[:nl]%s (%s%%) wordt toegevoegd bij betaling[:ru]%s (%s%%) будет добавлен при оформлении[:zh]%s (%s%%) 将在结账时添加[:ja]チェックアウト時に %s (%s%%) が追加されます[:ar]سيتم إضافة %s (%s%%) عند الدفع[:tr]Ödeme sırasında %s (%s%%) eklenecektir[:]',
            'label_subtotal' => '[:en]Subtotal[:ro]Subtotal[:de]Zwischensumme[:es]Subtotal[:fr]Sous-total[:it]Subtotale[:pt]Subtotal[:nl]Subtotaal[:ru]Подитог[:zh]小计[:ja]小計[:ar]المجموع الفرعي[:tr]Ara Toplam[:]',
            'label_room' => '[:en]Room[:ro]Cameră[:de]Zimmer[:es]Habitación[:fr]Chambre[:it]Camera[:pt]Quarto[:nl]Kamer[:ru]Номер[:zh]房间[:ja]客室[:ar]غرفة[:tr]Oda[:]',
            'label_extras' => '[:en]Extras[:ro]Extra[:de]Extras[:es]Extras[:fr]Extras[:it]Extra[:pt]Extras[:nl]Extra\'s[:ru]Дополнительно[:zh]附加服务[:ja]オプション[:ar]إضافي[:tr]Ekstra[:]',
            'label_item' => '[:en]Item[:ro]Articol[:de]Posten[:es]Artículo[:fr]Article[:it]Articolo[:pt]Item[:nl]Item[:ru]Позиция[:zh]项目[:ja]項目[:ar]بند[:tr]Öğe[:]',
            'label_amount' => '[:en]Amount[:ro]Sumă[:de]Betrag[:es]Monto[:fr]Montant[:it]Importo[:pt]Valor[:nl]Bedrag[:ru]Сумма[:zh]金额[:ja]金額[:ar]المبلغ[:tr]Tutar[:]',
            'label_booking_summary' => '[:en]Booking Summary[:ro]Sumar Rezervare[:de]Buchungsübersicht[:es]Resumen de reserva[:fr]Résumé de la réservation[:it]Riepilogo prenotazione[:pt]Resumo da reserva[:nl]Boekingssamenvatting[:ru]Сводка бронирования[:zh]预订摘要[:ja]予約概要[:ar]ملخص الحجز[:tr]Rezervasyon Özeti[:]',
            'label_accommodation' => '[:en]Accommodation[:ro]Cazare[:de]Unterkunft[:es]Alojamiento[:fr]Hébergement[:it]Alloggio[:pt]Acomodação[:nl]Accommodatie[:ru]Размещение[:zh]住宿[:ja]宿泊[:ar]الإقامة[:tr]Konaklama[:]',
            'label_extras_item' => '[:en]Extras[:ro]Extra opțiuni[:de]Extras[:es]Extras[:fr]Extras[:it]Extra[:pt]Extras[:nl]Extra\'s[:ru]Дополнительно[:zh]附加服务[:ja]オプション[:ar]إضافي[:tr]Ekstralar[:]',
            'label_tax_accommodation' => '[:en]%1$s - Accommodation (%2$s%%)[:ro]%1$s - Cazare (%2$s%%)[:de]%1$s - Unterkunft (%2$s%%)[:]',
            'label_tax_extras' => '[:en]%1$s - Extras (%2$s%%)[:ro]%1$s - Extra opțiuni (%2$s%%)[:de]%1$s - Extras (%2$s%%)[:]',
            'label_tax_rate' => '[:en]%1$s (%2$s%%)[:ro]%1$s (%2$s%%)[:de]%1$s (%2$s%%)[:]',
            'label_availability_error' => __('Dates are not available.', 'modern-hotel-booking'),
            'label_room_not_found' => __('Room not found.', 'modern-hotel-booking'),
            'label_secure_payment' => __('Secure Online Payment', 'modern-hotel-booking'),
            'label_security_error' => __('Security verification failed. Please refresh the page.', 'modern-hotel-booking'),
            'label_rate_limit_error' => __('Too many attempts. Please wait a minute.', 'modern-hotel-booking'),
            'label_spam_honeypot' => __('Leave this field empty', 'modern-hotel-booking'),
            'label_room_alt_text' => __('Room Image', 'modern-hotel-booking'),
            'label_calendar_no_id' => __('No room ID specified for calendar.', 'modern-hotel-booking'),
            'label_calendar_config_error' => __('Booking Page URL not configured.', 'modern-hotel-booking'),
            'label_loading' => __('Loading...', 'modern-hotel-booking'),
            'label_to' => __('to', 'modern-hotel-booking'),
            'btn_processing' => __('Processing...', 'modern-hotel-booking'),
            'msg_gdpr_required' => __('Please accept the privacy policy to continue.', 'modern-hotel-booking'),
            'msg_paypal_required' => __('Please use the PayPal button to complete your payment.', 'modern-hotel-booking'),
            'label_enhance_stay' => __('Enhance Your Stay', 'modern-hotel-booking'),
            'label_per_person' => __('per person', 'modern-hotel-booking'),
            'label_per_person_per_night' => __('per person / night', 'modern-hotel-booking'),
            'label_tax_note_includes' => /* translators: %s: Tax rate percentage */ __('Price includes %s', 'modern-hotel-booking'),
            'label_tax_note_plus' => /* translators: %s: Tax rate percentage */ __('Price plus %s', 'modern-hotel-booking'),
            'label_tax_note_includes_multi' => /* translators: %1$s: Tax label, %2$s: Tax rate percentage */ __('Price includes %1$s (%2$s%%)', 'modern-hotel-booking'),
            'label_tax_note_plus_multi' => /* translators: %1$s: Tax label, %2$s: Tax rate percentage */ __('Price plus %1$s (%2$s%%)', 'modern-hotel-booking'),
            'label_select_dates_error' => __('Please select check-in and check-out dates.', 'modern-hotel-booking'),
            'label_legend_confirmed' => '[:en]Booked[:ro]Rezervat[:de]Gebucht[:es]Reservado[:fr]Réservé[:it]Prenotato[:pt]Reservado[:nl]Geboekt[:ru]Забронировано[:zh]已预订[:ja]予約済み[:ar]محجوز[:tr]Rezerve[:]',
            'label_legend_pending' => '[:en]Pending[:ro]În așteptare[:de]Ausstehend[:es]Pendiente[:fr]En attente[:it]In attesa[:pt]Pendente[:nl]In afwachting[:ru]Ожидание[:zh]待定[:ja]保留中[:ar]قيد الانتظار[:tr]Beklemede[:]',
            'label_legend_available' => '[:en]Available[:ro]Disponibil[:de]Verfügbar[:es]Disponible[:fr]Disponible[:it]Disponibile[:pt]Disponível[:nl]Beschikbaar[:ru]Доступно[:zh]可预订[:ja]空室あり[:ar]متاح[:tr]Müsait[:]',
            'label_block_no_room' => __('Please select a Room ID in block settings.', 'modern-hotel-booking'),
            'label_check_in_past' => __('Check-in date cannot be in the past.', 'modern-hotel-booking'),
            'label_check_out_after' => __('Check-out date must be after check-in date.', 'modern-hotel-booking'),
            'label_check_in_future' => __('Check-in date cannot be more than 2 years in the future.', 'modern-hotel-booking'),
            'label_check_out_future' => __('Check-out date cannot be more than 2 years in the future.', 'modern-hotel-booking'),
            'label_name_too_long' => __('Name is too long (maximum 100 characters).', 'modern-hotel-booking'),
            'label_phone_too_long' => __('Phone number is too long (maximum 30 characters).', 'modern-hotel-booking'),
            'label_max_children_error' => /* translators: %d: Maximum number of children */ __('Error: Maximum children for this room is %d.', 'modern-hotel-booking'),
            'label_price_calc_error' => __('Error calculating price. Please check dates.', 'modern-hotel-booking'),
            'label_fill_all_fields' => __('Please fill in all required fields.', 'modern-hotel-booking'),
            'label_invalid_email' => __('Please provide a valid email address.', 'modern-hotel-booking'),
            'label_field_required' => /* translators: %s: Field name */ __('The field "%s" is required.', 'modern-hotel-booking'),
            'label_spam_detected' => __('Spam detected.', 'modern-hotel-booking'),
            'label_already_booked' => __('Sorry, this room was just booked by someone else or is unavailable for these dates.', 'modern-hotel-booking'),
            'label_max_adults_error' => /* translators: %d: Maximum number of adults */ __('Error: Maximum adults for this room is %d.', 'modern-hotel-booking'),
            'label_rest_pro_error' => __('REST API access is a Pro feature.', 'modern-hotel-booking'),
            'label_invalid_nonce' => __('Invalid nonce.', 'modern-hotel-booking'),
            'label_api_rate_limit' => __('Too many requests. Please try again later.', 'modern-hotel-booking'),
            'msg_payment_success_email' => '[:en]Payment received successfully. A confirmation email has been sent.[:ro]Plata a fost primită cu succes. Un e-mail de confirmare a fost trimis.[:]',
            'msg_booking_arrival_email' => '[:en]Your booking is confirmed. Payment will be collected on arrival. A confirmation email has been sent.[:ro]Rezervarea este confirmată. Plata va fi încasată la sosire. Un e-mail de confirmare a fost trimis.[:]',
            'label_payment_failed' => '[:en]Payment Failed[:ro]Plata a Eșuat[:]',
            'msg_payment_failed_detail' => '[:en]Your payment could not be processed. Please try again or contact us for assistance.[:ro]Plata nu a putut fi procesată. Vă rugăm să încercați din nou sau să ne contactați pentru asistență.[:]',
            'msg_booking_received_pending' => '[:en]Your reservation is under review and needs to be approved before becoming reserved.[:ro]Rezervarea dumneavoastră este în curs de revizuire și trebuie să fie aprobată înainte de a deveni rezervată.[:]',
            'label_payment_status' => '[:en]Payment Status:[:ro]Status Plată:[:]',
            'label_paid' => '[:en]Paid[:ro]Plătit[:]',
            'label_amount_paid' => '[:en]Amount Paid:[:ro]Sumă Plătită:[:]',
            'label_transaction_id' => '[:en]Transaction ID:[:ro]ID Tranzacție:[:]',
            'label_failed' => '[:en]Failed[:ro]Eșuat[:]',
            'label_dates_no_longer_available' => '[:en]Sorry, these dates are no longer available. Please select different dates.[:ro]Ne pare rău, aceste date nu mai sunt disponibile. Vă rugăm să selectați alte date.[:]',
            'label_invalid_booking_calc' => __('Invalid booking details. Cannot calculate amount.', 'modern-hotel-booking'),
            'label_stripe_not_configured' => __('Stripe is not configured.', 'modern-hotel-booking'),
            'label_paypal_not_configured' => __('PayPal is not configured. Please contact the site administrator.', 'modern-hotel-booking'),
            'label_paypal_connection_error' => __('Unable to connect to PayPal. Please try again later.', 'modern-hotel-booking'),
            'label_paypal_auth_failed' => __('Failed to authenticate with PayPal. Please check your PayPal credentials.', 'modern-hotel-booking'),
            'label_paypal_order_create_error' => __('Unable to create PayPal order. Please try again later.', 'modern-hotel-booking'),
            'label_paypal_currency_unsupported' => /* translators: %s: Currency code */ __('Currency %s is not supported by your PayPal account.', 'modern-hotel-booking'),
            'label_paypal_generic_error' => /* translators: %s: Error message */ __('PayPal error: %s', 'modern-hotel-booking'),
            'label_missing_order_id' => __('Missing order ID.', 'modern-hotel-booking'),
            'label_paypal_capture_error' => __('Unable to capture payment. Please try again later.', 'modern-hotel-booking'),
            'label_payment_already_processed' => __('This payment has already been processed.', 'modern-hotel-booking'),
            'label_payment_declined_paypal' => __('The payment was declined by PayPal. Please try a different payment method.', 'modern-hotel-booking'),
            'label_payment_confirmation' => __('Payment Confirmation', 'modern-hotel-booking'),
            'label_privacy_policy' => __('privacy policy', 'modern-hotel-booking'),
            'label_terms_conditions' => __('Terms & Conditions', 'modern-hotel-booking'),
            'label_payment_info' => __('Payment Information', 'modern-hotel-booking'),
            'msg_pay_on_arrival_email' => __('Payment will be collected upon arrival at the property.', 'modern-hotel-booking'),
            'label_amount_due' => __('Amount Due', 'modern-hotel-booking'),
            'label_payment_date' => __('Payment Date', 'modern-hotel-booking'),
            'label_paypal_order_failed' => __('Failed to create PayPal order.', 'modern-hotel-booking'),
            'label_security_verification_failed' => __('Security verification failed. Please refresh the page and try again.', 'modern-hotel-booking'),
            'label_paypal_client_id_missing' => /* translators: %s: Environment (Sandbox/Live) */ __('PayPal %s Client ID is not configured.', 'modern-hotel-booking'),
            'label_paypal_secret_missing' => /* translators: %s: Environment (Sandbox/Live) */ __('PayPal %s Secret is not configured.', 'modern-hotel-booking'),
            'label_stripe_intent_missing' => __('Stripe payment failed: Payment intent missing.', 'modern-hotel-booking'),
            'label_stripe_generic_error' => /* translators: %s: Error message */ __('Stripe API error: %s', 'modern-hotel-booking'),
            'label_paypal_id_missing' => __('PayPal payment failed: Order ID missing.', 'modern-hotel-booking'),
            'label_payment_required' => __('Payment is required.', 'modern-hotel-booking'),
            'label_api_not_configured' => __('API key has not been configured. Set it in Hotel Booking → Settings.', 'modern-hotel-booking'),
            'label_invalid_api_key' => __('Invalid or missing API key.', 'modern-hotel-booking'),
            'label_webhook_sig_required' => __('Webhook signature required. Unauthorized requests are rejected.', 'modern-hotel-booking'),
            'label_stripe_webhook_secret_missing' => __('Stripe webhook secret not configured. Please set it in Settings.', 'modern-hotel-booking'),
            'label_invalid_stripe_sig_format' => __('Invalid Stripe signature format.', 'modern-hotel-booking'),
            'label_webhook_expired' => __('Webhook timestamp outside acceptable range.', 'modern-hotel-booking'),
            'label_invalid_stripe_sig' => __('Invalid Stripe webhook signature.', 'modern-hotel-booking'),
            'label_missing_paypal_headers' => __('Missing required PayPal webhook headers.', 'modern-hotel-booking'),
            'label_invalid_customer' => __('Valid customer name and email are required.', 'modern-hotel-booking'),
            'label_invalid_dates' => __('Invalid booking dates.', 'modern-hotel-booking'),
            'label_booking_failed' => __('Failed to create the booking.', 'modern-hotel-booking'),
            'label_permission_denied' => __('Permission denied.', 'modern-hotel-booking'),
            'label_stripe_pk_missing' => /* translators: %s: Environment (Sandbox/Live) */ __('Stripe %s Publishable Key is not configured.', 'modern-hotel-booking'),
            'label_stripe_sk_missing' => /* translators: %s: Environment (Sandbox/Live) */ __('Stripe %s Secret Key is not configured.', 'modern-hotel-booking'),
            'label_stripe_invalid_pk_format' => /* translators: %1$s: Expected key prefix, %2$s: Environment mode */ __('Invalid publishable key format. Expected key starting with "%1$s" for %2$s mode.', 'modern-hotel-booking'),
            'label_credentials_spaces' => __('Credentials contain extra spaces', 'modern-hotel-booking'),
            'label_mode_mismatch' => __('Using Sandbox credentials in Live mode (or vice versa)', 'modern-hotel-booking'),
            'label_credentials_expired' => __('Credentials have expired or been rotated', 'modern-hotel-booking'),
            'label_creds_valid_env' => /* translators: %s: Environment (Sandbox/Live) */ __('PayPal %s credentials are valid!', 'modern-hotel-booking'),
            'label_stripe_creds_valid' => /* translators: %s: Environment (Sandbox/Live) */ __('Stripe %s credentials are valid!', 'modern-hotel-booking'),
            'label_connection_failed' => /* translators: %s: Error message */ __('Connection failed: %s', 'modern-hotel-booking'),
            'label_auth_failed_env' => /* translators: %s: Error message */ __('Authentication failed: %s', 'modern-hotel-booking'),
            'label_common_causes' => __('Common causes:', 'modern-hotel-booking'),
        );
    }

    /**
     * Register a string for translation with WPML/Polylang
     *
     * @param string $name String name/identifier
     * @param string $value String value
     * @param string $context Context/package name
     */
    public static function register_string($name, $value, $context = 'Modern Hotel Booking')
    {
        $plugin = self::detect_plugin();

        if ('wpml' === $plugin) {
            // WPML String Translation
            do_action('wpml_register_single_string', $context, $name, $value);
        } elseif ('polylang' === $plugin) {
            // Polylang String Translation (if Polylang String Translation addon is available)
            if (function_exists('pll_register_string')) {
                call_user_func('pll_register_string', $name, $value, $context);
            }
        }
        // qTranslate-X doesn't require string registration - uses inline format
    }

    /**
     * Get a translated string from WPML/Polylang
     *
     * @param string $name String name/identifier
     * @param string $default Default value if not translated
     * @param string $context Context/package name
     * @param string|null $language Language code (optional)
     * @return string Translated string
     */
    public static function get_translated_string($name, $default = '', $context = 'Modern Hotel Booking', $language = null)
    {
        $plugin = self::detect_plugin();

        if (null === $language) {
            $language = self::get_current_language();
        }

        if ('wpml' === $plugin) {
            // WPML String Translation
            $translated = apply_filters('wpml_translate_single_string', $default, $context, $name, $language);
            return !empty($translated) ? $translated : $default;
        } elseif ('polylang' === $plugin) {
            // Polylang String Translation
            if (function_exists('pll__')) {
                $translated = call_user_func('pll__', $default);
                return !empty($translated) ? $translated : $default;
            }
        }

        // qTranslate-X or no plugin - return default (which may contain qTranslate format)
        $decoded = self::decode($default, $language);
        return !empty($decoded) ? $decoded : $default;
    }

    /**
     * Register all plugin strings for translation
     * Should be called on plugin activation or admin init
     */
    public static function register_plugin_strings()
    {
        // Register tax-related strings
        $tax_label = get_option('mhb_tax_label', '[:en]VAT[:ro]TVA[:]');
        self::register_string('Tax Label', $tax_label, 'MHB Tax Settings');

        // Register email template subjects and messages
        $statuses = ['pending', 'confirmed', 'cancelled', 'payment'];
        foreach ($statuses as $status) {
            $subject = get_option("mhb_email_{$status}_subject", '');
            if (!empty($subject)) {
                self::register_string("Email {$status} Subject", $subject, 'MHB Email Templates');
            }
            $message = get_option("mhb_email_{$status}_message", '');
            if (!empty($message)) {
                self::register_string("Email {$status} Message", $message, 'MHB Email Templates');
            }
        }

        // Register frontend labels
        // Register all available frontend labels
        $labels = self::get_all_default_labels();
        foreach ($labels as $key => $default_val) {
            $label = get_option("mhb_label_{$key}", '');
            if (!empty($label)) {
                self::register_string("Label: {$key}", $label, 'MHB Frontend Labels');
            } else {
                // Register default value so it appears in string translation tools
                self::register_string("Label: {$key}", $default_val, 'MHB Frontend Labels');
            }
        }
    }

    /**
     * Translate a booking status slug.
     *
     * @param string $status
     * @return string
     */
    public static function translate_status($status)
    {
        switch ($status) {
            case 'pending':
                return __('Pending', 'modern-hotel-booking');
            case 'confirmed':
                return __('Confirmed', 'modern-hotel-booking');
            case 'cancelled':
                return __('Cancelled', 'modern-hotel-booking');
            default:
                return ucfirst($status);
        }
    }

    /**
     * Translate a payment method slug.
     *
     * @param string $method
     * @return string
     */
    public static function translate_payment_method($method)
    {
        switch ($method) {
            case 'onsite':
            case 'arrival':
                return __('Onsite / Manual', 'modern-hotel-booking');
            case 'stripe':
                return __('Stripe', 'modern-hotel-booking');
            case 'paypal':
                return __('PayPal', 'modern-hotel-booking');
            default:
                return ucfirst($method);
        }
    }

    /**
     * Translate a payment status slug.
     *
     * @param string $status
     * @return string
     */
    public static function translate_payment_status($status)
    {
        switch ($status) {
            case 'pending':
                return __('Pending', 'modern-hotel-booking');
            case 'processing':
                return __('Processing', 'modern-hotel-booking');
            case 'completed':
                return __('Completed', 'modern-hotel-booking');
            case 'failed':
                return __('Failed', 'modern-hotel-booking');
            case 'refunded':
                return __('Refunded', 'modern-hotel-booking');
            default:
                return ucfirst($status);
        }
    }

    /**
     * Check if a currency code is a valid ISO-4217 code.
     * 
     * This list includes common currencies supported by major payment processors (Stripe/PayPal).
     *
     * @param string $code 3-letter currency code.
     * @return bool
     */
    public static function is_valid_currency($code)
    {
        $code = strtoupper(trim($code));
        $valid_codes = [
            'USD',
            'EUR',
            'GBP',
            'JPY',
            'CAD',
            'AUD',
            'CHF',
            'CNY',
            'SEK',
            'NZD',
            'KRW',
            'SGD',
            'NOK',
            'MXN',
            'INR',
            'RUB',
            'ZAR',
            'TRY',
            'BRL',
            'TWD',
            'DKK',
            'PLN',
            'THB',
            'IDR',
            'HUF',
            'CZK',
            'ILS',
            'CLP',
            'PHP',
            'AED',
            'COP',
            'SAR',
            'MYR',
            'RON',
            'VND',
            'ARS',
            'EGP',
            'IRR',
            'KWD',
            'LKR',
            'UAH',
            'VEF',
            'HNL',
            'GTQ',
            'CRC',
            'DOP',
            'PEN',
            'UYU',
            'PYG',
            'BOB',
            'NIO',
            'ISK',
            'HRK',
            'BGN',
            'RON',
            'LVL',
            'LTL',
            'EEK',
            'SKK',
            'SIT',
            'CYP',
            'MTL',
            'TZS',
            'UGX',
            'KES',
            'GHS',
            'NGN',
            'ZMW',
            'MUR',
            'SCR',
            'MGA',
            'MAD',
            'TND',
            'DZD',
            'EGP',
            'QAR',
            'OMR',
            'BHD',
            'JOD',
            'LBP',
            'AMD',
            'AZN',
            'GEL',
            'KZT',
            'UZS',
            'TJS',
            'KGS',
            'AFN',
            'PKR',
            'BDT',
            'NPR',
            'MVR',
            'MMK',
            'LAK',
            'KHR',
            'MOP',
            'HKD',
            'FJD',
            'XPF',
            'XAF',
            'XOF',
            'XCD',
            'ANG',
            'AWG',
            'BBD',
            'BSD',
            'BZD',
            'BMD',
            'GIP',
            'JMD',
            'KYD',
            'LRD',
            'SBD',
            'SRD',
            'TOP',
            'TTD',
            'VUV',
            'WST',
            'XDR'
        ];
        return in_array($code, $valid_codes, true);
    }
}

