<?php
if (!defined('ABSPATH')) exit;

class StaffApp_License {
    const PAYHIP_VERIFY_URL = 'https://payhip.com/api/v2/license/verify';

    public static function setting($key, $default='') {
        global $wpdb;
        $t = $wpdb->prefix . 'staffapp_settings';
        $v = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $t WHERE setting_key=%s", $key));
        return $v === null ? $default : $v;
    }

    public static function set_setting($key, $value) {
        global $wpdb;
        $t = $wpdb->prefix . 'staffapp_settings';
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE setting_key=%s", $key));
        if ($id) {
            $wpdb->update($t, ['setting_value' => $value], ['id' => $id]);
        } else {
            $wpdb->insert($t, ['setting_key' => $key, 'setting_value' => $value]);
        }
    }

    public static function current_domain() {
        $host = parse_url(home_url(), PHP_URL_HOST);
        $host = strtolower((string)$host);
        return preg_replace('/^www\./', '', $host);
    }

    public static function normalize_domain($domain) {
        $domain = trim(strtolower((string)$domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        $domain = preg_replace('/^www\./', '', $domain);
        return $domain;
    }

    public static function is_test_mode() {
        return self::setting('test_mode', '1') === '1';
    }

    public static function is_active() {
        $status = self::setting('license_status', 'inactive');
        $bound = self::normalize_domain(self::setting('license_domain', ''));
        $current = self::current_domain();
        $key = self::setting('license_key', '');
        return $status === 'active' && $key !== '' && $bound !== '' && $bound === $current;
    }

    public static function can_use() {
        return self::is_active() || self::is_test_mode();
    }

    public static function status_label() {
        if (self::is_test_mode()) return 'Тестовий режим увімкнено';
        $status = self::setting('license_status', 'inactive');
        $bound = self::normalize_domain(self::setting('license_domain', ''));
        $current = self::current_domain();
        if ($status === 'active' && $bound === $current) return 'Активна';
        if ($status === 'active' && $bound && $bound !== $current) return 'Прив’язана до іншого домену: ' . $bound;
        if ($status === 'error') return 'Помилка активації: ' . self::setting('license_error', 'невідомо');
        return 'Не активована';
    }

    public static function activate($license_key, $product_secret_key, $domain = '') {
        $license_key = sanitize_text_field($license_key);
        $product_secret_key = sanitize_text_field($product_secret_key);
        $domain = self::normalize_domain($domain ?: self::current_domain());
        $current = self::current_domain();

        if (!$license_key) return new WP_Error('missing_key', 'Введіть License key.');
        if (!$product_secret_key) return new WP_Error('missing_secret', 'Введіть Product secret key з Payhip.');
        if ($domain !== $current) return new WP_Error('bad_domain', 'Домен активації має збігатися з поточним сайтом: ' . $current);

        $old_domain = self::normalize_domain(self::setting('license_domain', ''));
        $old_key = self::setting('license_key', '');
        if ($old_key && $old_key !== $license_key && self::setting('license_status') === 'active') {
            return new WP_Error('already_active', 'Плагін вже активований іншим ключем. Спочатку скиньте ліцензію вручну в БД або через майбутній License Center.');
        }
        if ($old_domain && $old_domain !== $current && self::setting('license_status') === 'active') {
            return new WP_Error('domain_locked', 'Ліцензія вже прив’язана до іншого домену: ' . $old_domain);
        }

        $url = add_query_arg(['license_key' => rawurlencode($license_key)], self::PAYHIP_VERIFY_URL);
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'product-secret-key' => $product_secret_key,
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            self::set_setting('license_status', 'error');
            self::set_setting('license_error', $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($body['data'])) {
            $msg = 'Payhip не підтвердив ключ. HTTP ' . $code;
            self::set_setting('license_status', 'error');
            self::set_setting('license_error', $msg);
            return new WP_Error('payhip_failed', $msg);
        }

        $data = $body['data'];
        if (isset($data['enabled']) && !$data['enabled']) {
            self::set_setting('license_status', 'error');
            self::set_setting('license_error', 'Ключ вимкнений або повернення платежу.');
            return new WP_Error('license_disabled', 'Ключ вимкнений або повернення платежу.');
        }

        self::set_setting('license_key', $license_key);
        self::set_setting('product_secret_key', $product_secret_key);
        self::set_setting('license_domain', $current);
        self::set_setting('license_status', 'active');
        self::set_setting('license_error', '');
        self::set_setting('license_buyer_email', sanitize_email($data['buyer_email'] ?? ''));
        self::set_setting('license_product_name', sanitize_text_field($data['product_name'] ?? ''));
        self::set_setting('license_variant_name', sanitize_text_field($data['variant_name'] ?? ''));
        self::set_setting('license_verified_at', current_time('mysql'));

        return true;
    }
}
