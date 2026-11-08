<?php
if (!defined('ABSPATH')) exit;

class StaffApp_Install {
    public static function activate() {
        self::tables();
        self::defaults();
        self::uploads();
        self::page();
    }

    public static function tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}staffapp_employees (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pib VARCHAR(255) NOT NULL,
            posada VARCHAR(255) NOT NULL,
            date_in DATE NULL,
            date_out DATE NULL,
            main_photo TEXT NULL,
            photos LONGTEXT NULL,
            descriptors LONGTEXT NULL,
            fired TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pib (pib(191)),
            KEY fired (fired)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}staffapp_attendance (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            emp_id BIGINT UNSIGNED NULL,
            emp_name VARCHAR(255) NOT NULL,
            date DATE NOT NULL,
            time TIME NOT NULL,
            type VARCHAR(20) NOT NULL,
            guard_name VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY date (date),
            KEY emp_id (emp_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}staffapp_guards (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}staffapp_vehicles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            number_plate VARCHAR(40) NOT NULL,
            trailer_plate VARCHAR(40) NULL,
            brand VARCHAR(255) NULL,
            company VARCHAR(255) NULL,
            driver VARCHAR(255) NULL,
            phone VARCHAR(80) NULL,
            cargo VARCHAR(255) NULL,
            photo TEXT NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY number_plate (number_plate)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}staffapp_vehicle_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_id BIGINT UNSIGNED NULL,
            number_plate VARCHAR(40) NOT NULL,
            trailer_plate VARCHAR(40) NULL,
            brand VARCHAR(255) NULL,
            company VARCHAR(255) NULL,
            driver VARCHAR(255) NULL,
            cargo VARCHAR(255) NULL,
            gross DECIMAL(12,2) NULL,
            tare DECIMAL(12,2) NULL,
            net DECIMAL(12,2) NULL,
            event_type VARCHAR(20) NOT NULL,
            date DATE NOT NULL,
            time TIME NOT NULL,
            guard_name VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY date (date),
            KEY number_plate (number_plate)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}staffapp_settings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(191) NOT NULL,
            setting_value LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $cc;");
    }

    public static function defaults() {
        global $wpdb;
        $t = $wpdb->prefix . 'staffapp_settings';
        self::set_default($t, 'manager_password', wp_hash_password('1111'));
        self::set_default($t, 'license_key', '');
        self::set_default($t, 'license_domain', parse_url(home_url(), PHP_URL_HOST));
        self::set_default($t, 'license_status', 'inactive');
        self::set_default($t, 'test_mode', '1');
        if (!get_option('staffapp_company_name')) update_option('staffapp_company_name', get_bloginfo('name'));
        if (!get_option('staffapp_face_threshold')) update_option('staffapp_face_threshold', '0.48');
        $guards = $wpdb->prefix . 'staffapp_guards';
        $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM $guards");
        if (!$cnt) $wpdb->insert($guards, ['name'=>'Охоронець 1','password'=>wp_hash_password('111')]);
    }

    private static function set_default($table, $key, $value) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE setting_key=%s", $key));
        if (!$exists) $wpdb->insert($table, ['setting_key'=>$key,'setting_value'=>$value]);
    }

    public static function uploads() {
        $dir = wp_upload_dir()['basedir'] . '/staffapp_photos';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        if (!file_exists($dir . '/index.html')) file_put_contents($dir . '/index.html', '');
    }

    public static function page() {
        if (!get_page_by_path('staffapp')) {
            wp_insert_post([
                'post_title' => 'StaffApp',
                'post_name' => 'staffapp',
                'post_content' => '[staffapp]',
                'post_status' => 'publish',
                'post_type' => 'page'
            ]);
        }
    }
}
