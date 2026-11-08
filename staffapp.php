<?php
/**
 * Plugin Name: StaffApp КПП
 * Plugin URI: https://uaserver.pp.ua/
 * Description: КПП-система: працівники, розпізнавання облич, охоронці, транспорт, вагова, OCR номерів, PWA, доменна ліцензія.
 * Version: 5.0.0
 * Author: UAServer
 * License: GPL v2 or later
 * Text Domain: staffapp
 */

if (!defined('ABSPATH')) exit;

define('STAFFAPP_VERSION', '5.0.0');
define('STAFFAPP_FILE', __FILE__);
define('STAFFAPP_DIR', plugin_dir_path(__FILE__));
define('STAFFAPP_URL', plugin_dir_url(__FILE__));
define('STAFFAPP_REST_NS', 'staffapp/v1');

require_once STAFFAPP_DIR . 'includes/class-install.php';
require_once STAFFAPP_DIR . 'includes/class-license.php';
require_once STAFFAPP_DIR . 'includes/class-rest.php';
require_once STAFFAPP_DIR . 'includes/class-admin.php';
require_once STAFFAPP_DIR . 'includes/class-shortcode.php';

register_activation_hook(__FILE__, ['StaffApp_Install', 'activate']);

add_action('plugins_loaded', function(){
    StaffApp_REST::init();
    StaffApp_Admin::init();
    StaffApp_Shortcode::init();
});

add_action('wp_enqueue_scripts', function(){
    if (!is_singular()) return;
    global $post;
    if (!$post || strpos((string)$post->post_content, '[staffapp]') === false) return;
    wp_enqueue_style('staffapp-css', STAFFAPP_URL . 'assets/css/staffapp.css', [], STAFFAPP_VERSION);
    wp_enqueue_script('tfjs', 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@3.21.0/dist/tf.min.js', [], null, true);
    wp_enqueue_script('faceapi', 'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js', ['tfjs'], null, true);
    wp_enqueue_script('tesseract', 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js', [], null, true);
    wp_enqueue_script('staffapp-js', STAFFAPP_URL . 'assets/js/staffapp.js', ['faceapi','tesseract'], STAFFAPP_VERSION, true);
    wp_localize_script('staffapp-js', 'STAFFAPP', [
        'api' => esc_url_raw(rest_url(STAFFAPP_REST_NS)),
        'nonce' => wp_create_nonce('wp_rest'),
        'photoUrl' => esc_url_raw(wp_upload_dir()['baseurl'] . '/staffapp_photos/'),
        'pluginUrl' => esc_url_raw(STAFFAPP_URL),
        'threshold' => (float)get_option('staffapp_face_threshold', 0.48),
        'company' => get_option('staffapp_company_name', get_bloginfo('name')),
        'domain' => home_url(),
        'licenseActive' => StaffApp_License::can_use(),
        'licenseStatus' => StaffApp_License::status_label(),
    ]);
});

add_action('wp_head', function(){
    if (!is_singular()) return;
    global $post;
    if (!$post || strpos((string)$post->post_content, '[staffapp]') === false) return;
    echo '<link rel="manifest" href="' . esc_url(STAFFAPP_URL . 'pwa/manifest.json') . '">' . "\n";
    echo '<meta name="theme-color" content="#0d0f14">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
});
