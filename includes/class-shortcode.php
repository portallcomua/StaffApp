<?php
if (!defined('ABSPATH')) exit;
class StaffApp_Shortcode {
    public static function init(){ add_shortcode('staffapp',[__CLASS__,'render']); }
    public static function render(){ ob_start(); ?>
        <div id="staffapp-root">
            <div class="staffapp-loading">
                <div class="staffapp-logo">StaffApp КПП</div>
                <div>Завантаження системи...</div>
            </div>
        </div>
        <noscript>Для роботи StaffApp потрібен JavaScript.</noscript>
    <?php return ob_get_clean(); }
}
