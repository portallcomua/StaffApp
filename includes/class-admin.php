<?php
if (!defined('ABSPATH')) exit;

class StaffApp_Admin {
    public static function init(){ add_action('admin_menu',[__CLASS__,'menu']); add_action('admin_init',[__CLASS__,'save']); add_action('admin_head-toplevel_page_staffapp',[__CLASS__,'admin_head']); }
    public static function menu(){ add_menu_page('StaffApp','StaffApp','manage_options','staffapp',[__CLASS__,'page'],'dashicons-id-alt',30); }

    public static function admin_head(){
        echo '<link rel="manifest" href="' . esc_url(STAFFAPP_URL . 'pwa/manifest.json') . '">';
        echo '<meta name="theme-color" content="#0d0f14">';
        echo '<meta name="mobile-web-app-capable" content="yes">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    }
    private static function setting($key,$default=''){global $wpdb;$t=$wpdb->prefix.'staffapp_settings';$v=$wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $t WHERE setting_key=%s",$key));return $v===null?$default:$v;}
    private static function set_setting($key,$value){global $wpdb;$t=$wpdb->prefix.'staffapp_settings';$id=$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE setting_key=%s",$key));if($id)$wpdb->update($t,['setting_value'=>$value],['id'=>$id]);else$wpdb->insert($t,['setting_key'=>$key,'setting_value'=>$value]);}
    public static function save(){
        if(!current_user_can('manage_options') || empty($_POST['staffapp_admin_nonce']) || !wp_verify_nonce($_POST['staffapp_admin_nonce'],'staffapp_admin')) return;
        global $wpdb;
        if(isset($_POST['staffapp_save_settings'])){
            update_option('staffapp_company_name', sanitize_text_field($_POST['company_name'] ?? ''));
            $face_threshold = filter_var($_POST['face_threshold'] ?? '', FILTER_VALIDATE_FLOAT);
            if($face_threshold === false || $face_threshold < 0.35 || $face_threshold > 0.60){
                $face_threshold = 0.48;
                add_settings_error('staffapp','face_threshold_error','Поріг розпізнавання має бути числом від 0.35 до 0.60. Збережено 0.48.','error');
            }
            update_option('staffapp_face_threshold', (string)$face_threshold);
            StaffApp_License::set_setting('test_mode', !empty($_POST['test_mode']) ? '1' : '0');
            if(!empty($_POST['manager_password'])) self::set_setting('manager_password', wp_hash_password((string)$_POST['manager_password']));
            self::set_setting('github_repo', sanitize_text_field($_POST['github_repo'] ?? 'portallcomua/staffapp'));
            add_settings_error('staffapp','saved','Налаштування збережено','updated');
        }
        if(isset($_POST['staffapp_activate_license'])){
            $license_key = sanitize_text_field($_POST['license_key'] ?? '');
            $secret_key = sanitize_text_field($_POST['product_secret_key'] ?? '');
            $domain = StaffApp_License::current_domain();
            $result = StaffApp_License::activate($license_key, $secret_key, $domain);
            if (is_wp_error($result)) {
                add_settings_error('staffapp','license_error','❌ ' . $result->get_error_message(),'error');
            } else {
                add_settings_error('staffapp','license_ok','✅ Ліцензію активовано і прив’язано до домену: ' . esc_html($domain),'updated');
            }
        }
        if(isset($_POST['staffapp_deactivate_license'])){
            StaffApp_License::set_setting('license_status','inactive');
            StaffApp_License::set_setting('license_error','');
            add_settings_error('staffapp','license_off','Ліцензію вимкнено локально. Дані плагіна не видалені.','updated');
        }
        if(isset($_POST['staffapp_add_guard'])){
            $name=sanitize_text_field($_POST['guard_name']??''); $pwd=(string)($_POST['guard_pwd']??'');
            if($name&&$pwd){$wpdb->insert($wpdb->prefix.'staffapp_guards',['name'=>$name,'password'=>wp_hash_password($pwd)]);add_settings_error('staffapp','guard','Охоронця додано','updated');}
        }
        if(isset($_POST['staffapp_update_guard'])){
            $id=(int)($_POST['guard_id']??0); $pwd=(string)($_POST['guard_new_pwd']??'');
            if($id&&$pwd){$wpdb->update($wpdb->prefix.'staffapp_guards',['password'=>wp_hash_password($pwd)],['id'=>$id]);add_settings_error('staffapp','guardup','Пароль охоронця змінено','updated');}
        }
        if(isset($_POST['staffapp_delete_guard'])){
            $id=(int)($_POST['guard_id']??0); if($id){$wpdb->delete($wpdb->prefix.'staffapp_guards',['id'=>$id]);add_settings_error('staffapp','guarddel','Охоронця видалено','updated');}
        }
    }
    public static function page(){
        global $wpdb; settings_errors('staffapp');
        $guards=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}staffapp_guards ORDER BY id DESC");
        $domain=parse_url(home_url(),PHP_URL_HOST);
        $page=get_page_by_path('staffapp'); $url=$page?get_permalink($page):home_url('/staffapp/');
        ?>
        <div class="wrap staffapp-admin">
            <h1>StaffApp КПП 5.0</h1>
            <p><a class="button button-primary" href="<?php echo esc_url($url); ?>" target="_blank">Відкрити StaffApp</a></p>
            <form method="post">
                <?php wp_nonce_field('staffapp_admin','staffapp_admin_nonce'); ?>
                <h2>Основні налаштування</h2>
                <table class="form-table">
                    <tr><th>Назва фірми</th><td><input type="text" name="company_name" value="<?php echo esc_attr(get_option('staffapp_company_name', get_bloginfo('name'))); ?>" class="regular-text"></td></tr>
                    <tr><th>Новий пароль керівника</th><td><input type="password" name="manager_password" class="regular-text"><p class="description">Початковий пароль: <b>1111</b>. Якщо поле порожнє — пароль не зміниться.</p></td></tr>
                    <tr><th>Поріг розпізнавання облич</th><td><input type="number" name="face_threshold" min="0.35" max="0.60" step="0.01" value="<?php echo esc_attr(get_option('staffapp_face_threshold','0.48')); ?>" class="small-text"><p class="description">Діапазон 0.35–0.60: нижче — суворіше, вище — м’якше. Стартово 0.48. Не збільшуйте поріг без перевірки на реальних фото.</p></td></tr>
                    <tr><th>Тестовий режим</th><td><label><input type="checkbox" name="test_mode" value="1" <?php checked(StaffApp_License::setting('test_mode','1'), '1'); ?>> Дозволити тестування без ліцензії</label><p class="description">Для твоїх тестів залиш увімкненим. Перед продажем клієнту вимикаємо: тоді без Payhip License Key StaffApp не відкриється.</p></td></tr>
                </table>

                <h2>Активація Payhip / прив’язка до домену</h2>
                <table class="form-table">
                    <tr><th>Статус</th><td><strong><?php echo esc_html(StaffApp_License::status_label()); ?></strong><p class="description">У продажному режимі плагін працює тільки після активації ключа і тільки на домені: <b><?php echo esc_html(StaffApp_License::current_domain()); ?></b></p></td></tr>
                    <tr><th>License key покупця</th><td><input type="text" name="license_key" value="<?php echo esc_attr(StaffApp_License::setting('license_key')); ?>" class="regular-text" autocomplete="off"></td></tr>
                    <tr><th>Product secret key Payhip</th><td><input type="password" name="product_secret_key" value="<?php echo esc_attr(StaffApp_License::setting('product_secret_key')); ?>" class="regular-text" autocomplete="off"><p class="description">Береться в Payhip у товарі, де увімкнені license keys. Для простого старту зберігається тут. У майбутньому краще винести на UAServer License Center.</p></td></tr>
                    <tr><th>Прив’язаний домен</th><td><input type="text" value="<?php echo esc_attr(StaffApp_License::setting('license_domain', StaffApp_License::current_domain())); ?>" class="regular-text" readonly><p class="description">Домен фіксується автоматично при натисканні “Активувати”. Клієнт не вводить домен вручну.</p></td></tr>
                    <tr><th>Покупець / товар</th><td><?php echo esc_html(StaffApp_License::setting('license_buyer_email','—')); ?> / <?php echo esc_html(StaffApp_License::setting('license_product_name','—')); ?><br><span class="description">Остання перевірка: <?php echo esc_html(StaffApp_License::setting('license_verified_at','—')); ?></span></td></tr>
                </table>
                <p>
                    <input type="submit" name="staffapp_activate_license" class="button button-primary" value="Активувати ліцензію">
                    <input type="submit" name="staffapp_deactivate_license" class="button" value="Вимкнути локально" onclick="return confirm('Вимкнути локальну активацію?')">
                </p>

                <h2>GitHub автооновлення</h2>
                <table class="form-table">
                    <tr><th>GitHub repo</th><td><input type="text" name="github_repo" value="<?php echo esc_attr(self::setting('github_repo','portallcomua/staffapp')); ?>" class="regular-text"><p class="description">Заготовка для update-check.</p></td></tr>
                </table>

                <h2>PWA / ярлик</h2>
                <p><b>Кнопка ярлика показується тільки тут в адмінці та у кабінеті керівника.</b> У режимі охоронця її немає.</p>
                <p>
                    <button type="button" class="button button-primary" id="staffapp-install-pwa-admin">Встановити ярлик / додаток на цьому пристрої</button>
                    <a class="button" href="<?php echo esc_url($url); ?>" target="_blank">Відкрити сторінку StaffApp</a>
                </p>
                <p class="description">Якщо браузер не показує встановлення: відкрий сторінку StaffApp у Chrome → меню → “Додати на головний екран” / “Встановити додаток”.</p>
                <script>
                (function(){
                    if('serviceWorker' in navigator){ navigator.serviceWorker.register('<?php echo esc_js(STAFFAPP_URL . 'pwa/service-worker.js'); ?>').catch(function(){}); }
                    var promptEvent = null;
                    window.addEventListener('beforeinstallprompt', function(e){ e.preventDefault(); promptEvent = e; });
                    var btn = document.getElementById('staffapp-install-pwa-admin');
                    if(btn){
                        btn.addEventListener('click', function(){
                            if(promptEvent){ promptEvent.prompt(); promptEvent.userChoice.finally(function(){ promptEvent = null; }); }
                            else { alert('Якщо кнопка не спрацювала: відкрийте сторінку StaffApp у Chrome і виберіть меню → Додати на головний екран / Встановити додаток.'); }
                        });
                    }
                })();
                </script>
                <p><input type="submit" name="staffapp_save_settings" class="button button-primary" value="Зберегти"></p>
            </form>

            <hr>
            <h2>Охоронці</h2>
            <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
                <?php wp_nonce_field('staffapp_admin','staffapp_admin_nonce'); ?>
                <input type="text" name="guard_name" placeholder="ПІБ охоронця" required>
                <input type="password" name="guard_pwd" placeholder="Пароль" required>
                <button class="button button-primary" name="staffapp_add_guard">Додати охоронця</button>
            </form>
            <table class="widefat striped"><thead><tr><th>ID</th><th>ПІБ</th><th>Дата</th><th>Змінити пароль</th><th>Видалити</th></tr></thead><tbody>
                <?php if(!$guards): ?><tr><td colspan="5">Охоронців немає.</td></tr><?php endif; ?>
                <?php foreach($guards as $g): ?><tr>
                    <td><?php echo (int)$g->id; ?></td><td><b><?php echo esc_html($g->name); ?></b></td><td><?php echo esc_html($g->created_at); ?></td>
                    <td><form method="post" style="display:flex;gap:8px;"><?php wp_nonce_field('staffapp_admin','staffapp_admin_nonce'); ?><input type="hidden" name="guard_id" value="<?php echo (int)$g->id; ?>"><input type="password" name="guard_new_pwd" placeholder="Новий пароль"><button class="button" name="staffapp_update_guard">Змінити</button></form></td>
                    <td><form method="post" onsubmit="return confirm('Видалити охоронця?')"><?php wp_nonce_field('staffapp_admin','staffapp_admin_nonce'); ?><input type="hidden" name="guard_id" value="<?php echo (int)$g->id; ?>"><button class="button button-link-delete" name="staffapp_delete_guard">Видалити</button></form></td>
                </tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }
}
