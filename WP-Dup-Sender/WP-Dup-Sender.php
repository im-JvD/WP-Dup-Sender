<?php
/*
Plugin Name:        WP Dup Sender
Plugin URI:         https://github.com/im-JvD/WP-Dup-Sender
Description:        با این افزونه می‌توانید بصورت دستی و خودکار تمامیه بسته ‌های نصبی آسان که توسط افزونه Duplicator تهییه شده را به تاپیک‌گروه تلگرامی خود توسط ربات تلگرامی ارسال نمایید .
Version:            1.3.1
Author:             محمد جواد کریمی
 Author URI:        https://mohamadjavadkarimi.ir/
*/

if (!defined('ABSPATH')) exit;

class WP_Dup_Sender {
    private $option_name = 'wp_dup_sender_opts';
    private $last_processed_option = 'wp_dup_sender_last_time';
    private $last_message_hash = 'wp_dup_sender_last_hash';

    public function __construct() {
        register_activation_hook(__FILE__, array($this,'on_activate'));
        register_uninstall_hook(__FILE__, array('WP_Dup_Sender','on_uninstall'));
        add_action('admin_menu', array($this,'admin_menu'));
        add_action('admin_init', array($this,'register_settings'));
        add_action('admin_enqueue_scripts', array($this,'enqueue_assets'));
        add_action('wp_ajax_wds_send_test', array($this,'ajax_send_test'));
        add_action('wp_ajax_wds_manual_public', array($this,'ajax_manual_public'));
        add_action('wp_ajax_wds_set_webhook', array($this,'ajax_set_webhook'));
        add_action('wp_ajax_wds_send_file', array($this,'ajax_send_file'));
        add_action('shutdown', array($this,'shutdown_check'));
        add_action('init', array($this,'handle_webhook_endpoint'));
    }

    public function on_activate(){
        $defaults = array(
            'duplicator_dir' => WP_CONTENT_DIR . '/backups-dup-lite/',
            'bot_token' => '',
            'chat_id' => '',
            'thread_id' => '',
            'public_thread_id' => '1',
            'site_url' => '',
            'msg_template' => "📦 بکاپ جدید آماده شد\n\n🔹 نام: <b>{archive_name}</b>\n🔹 حجم: {size_mb} MB\n🔹 تاریخ: {date}\n\n🔗 {archive_url}",
            'parse_mode' => 'HTML',
            'auto_delete_old' => 1,
            'max_keep' => 1
        );
        if (!get_option($this->option_name)) add_option($this->option_name,$defaults);
        if (!get_option($this->last_processed_option)) add_option($this->last_processed_option,0);
        if (!get_option($this->last_message_hash)) add_option($this->last_message_hash,'');

        // create uploads/BackupSite/
        $this->ensure_backup_dir();

        // redirect to settings dashboard
        set_transient('wds_do_activation_redirect', 1, 30);
    }

    public static function on_uninstall(){
        delete_option('wp_dup_sender_opts');
        delete_option('wp_dup_sender_last_time');
        delete_option('wp_dup_sender_last_hash');
    }

    public function admin_menu(){
        $page = add_menu_page('WP Dup Sender','WP Dup Sender','manage_options','wds-settings',array($this,'settings_page'),'dashicons-cloud',61);
        add_action("load-{$page}", array($this,'maybe_activation_redirect'));
    }

    public function maybe_activation_redirect(){
        if (get_transient('wds_do_activation_redirect')){
            delete_transient('wds_do_activation_redirect');
            wp_safe_redirect(admin_url('admin.php?page=wds-settings&tab=dashboard'));
            exit;
        }
    }

    public function register_settings(){
        register_setting($this->option_name, $this->option_name, array($this,'validate_options'));
    }

    public function validate_options($in){
        $out = get_option($this->option_name, array());
        $out['duplicator_dir'] = rtrim(sanitize_text_field($in['duplicator_dir'] ?? $out['duplicator_dir']), '/\\') . '/';
        $out['bot_token'] = sanitize_text_field($in['bot_token'] ?? '');
        $out['chat_id'] = sanitize_text_field($in['chat_id'] ?? '');
        $out['thread_id'] = sanitize_text_field($in['thread_id'] ?? '');
        $out['public_thread_id'] = sanitize_text_field($in['public_thread_id'] ?? '1');
        $out['site_url'] = rtrim(sanitize_text_field($in['site_url'] ?? $out['site_url']), '/');
        $out['msg_template'] = trim($in['msg_template'] ?? $out['msg_template']);
        $out['parse_mode'] = in_array($in['parse_mode'] ?? 'HTML', array('HTML','MarkdownV2')) ? $in['parse_mode'] : 'HTML';
        $out['auto_delete_old'] = !empty($in['auto_delete_old']) ? 1 : 0;
        $out['max_keep'] = max(1,intval($in['max_keep'] ?? 1));
        return $out;
    }

    public function enqueue_assets($hook){
        if (strpos($hook,'wds-settings') === false) return;
        wp_enqueue_style('wds-admin-style', plugins_url('assets/wds-admin.css', __FILE__));
        wp_enqueue_script('wds-admin-js', plugins_url('assets/wds-admin.js', __FILE__), array('jquery'), false, true);
        wp_localize_script('wds-admin-js','wds_ajax', array('ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wds_nonce')));
    }

    private function get_backup_dir(){
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'BackupSite/';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        return $dir;
    }

    private function get_backup_url_base(){
        $up = wp_upload_dir();
        return trailingslashit($up['baseurl']) . 'BackupSite/';
    }

    public function ensure_backup_dir(){
        $this->get_backup_dir();
    }

    public function settings_page(){
        if (!current_user_can('manage_options')) return;
        $opts = get_option($this->option_name);
        $last = get_option($this->last_processed_option,0);
        ?>
        <div class="wrap wds-wrap">
            <div class="wds-header">
                <h1>WP Dup Sender</h1>
                <button id="wds-save-btn" class="wds-btn-primary">بروزرسانی تنظیمات افزونه</button>
            </div>

            <div class="wds-tabs">
                <nav class="wds-nav">
                    <a class="wds-tab-link active" data-tab="dashboard">داشبورد</a>
                    <a class="wds-tab-link" data-tab="bot">تنظیمات ربات و تاپیک</a>
                    <a class="wds-tab-link" data-tab="template">فرمت پیام‌ها</a>
                    <a class="wds-tab-link" data-tab="public">ارسال پیام عمومی</a>
                </nav>

                <form id="wds-settings-form" method="post" action="options.php">
                    <?php settings_fields($this->option_name); ?>
                    <div class="wds-tab-content">

                        <section id="tab-dashboard" class="wds-panel active">
                            <h2>داشبورد</h2>
                            <p>آخرین پردازش: <strong><?php echo $last ? date('Y-m-d H:i:s',$last) : 'هنوز پردازش نشده'; ?></strong></p>
                            <p><strong>پوشهٔ Duplicator (خوانده می‌شود):</strong> <?php echo esc_html($opts['duplicator_dir']); ?></p>

                            <h3>فایل‌های ساخته‌شده</h3>
                            <?php
                            $files = $this->get_dup_files($opts['duplicator_dir']);
                            if (empty($files)) {
                                echo '<p>هیچ فایلی یافت نشد.</p>';
                            } else {
                                echo '<table class="wds-table"><thead><tr><th>نام فایل</th><th>تاریخ</th><th>حجم</th><th>عملیات</th></tr></thead><tbody>';
                                foreach ($files as $f) {
                                    echo '<tr>';
                                    echo '<td>'.esc_html(basename($f)).'</td>';
                                    echo '<td>'.esc_html(date('Y-m-d H:i:s',filemtime($f))).'</td>';
                                    echo '<td>'.esc_html(size_format(filesize($f))).'</td>';
                                    echo '<td><a class="wds-btn manual-download" href="'.esc_attr($this->get_backup_url_for_download(basename($f))).'" target="_blank">دانلود مستقیم</a> ';
                                    echo '<button class="wds-btn wds-send-file" data-file="'.esc_attr(basename($f)).'">ارسال دستی</button></td>';
                                    echo '</tr>';
                                }
                                echo '</tbody></table>';
                            }
                            ?>
                            <p class="wds-note">فایل نهایی آرشیو پس از پردازش در مسیر <code><?php echo esc_html($this->get_backup_dir()); ?></code> قرار می‌گیرد.</p>
                        </section>

                        <section id="tab-bot" class="wds-panel">
                            <h2>تنظیمات ربات و تاپیک</h2>
                            <table class="form-table">
                                <tr><th>توکن ربات</th><td><input type="text" name="<?php echo $this->option_name; ?>[bot_token]" value="<?php echo esc_attr($opts['bot_token']); ?>" style="width:100%"></td></tr>
                                <tr><th>آیدی گروه (عدد)</th><td><input type="text" name="<?php echo $this->option_name; ?>[chat_id]" value="<?php echo esc_attr($opts['chat_id']); ?>"></td></tr>
                                <tr><th>آیدی تاپیک برای فایل‌ها (message_thread_id)</th><td><input type="text" name="<?php echo $this->option_name; ?>[thread_id]" value="<?php echo esc_attr($opts['thread_id']); ?>"></td></tr>
                                <tr><th>آیدی تاپیک عمومی (برای پیام‌های عمومی و فعال‌سازی)</th><td><input type="text" name="<?php echo $this->option_name; ?>[public_thread_id]" value="<?php echo esc_attr($opts['public_thread_id'] ?? '1'); ?>"></td></tr>
                                <tr><th>آدرس دامنه برای لینک دانلود (بدون اسلش پایانی)</th><td><input type="text" name="<?php echo $this->option_name; ?>[site_url]" value="<?php echo esc_attr($opts['site_url'] ?? ''); ?>" style="width:100%"><p class="wds-desc">مثال: example.com یا https://example.com</p></td></tr>
                                <tr><th>حذف خودکار نسخه‌های قدیمی</th><td><label><input type="checkbox" name="<?php echo $this->option_name; ?>[auto_delete_old]" <?php checked(1,$opts['auto_delete_old']); ?> value="1"> فعال</label> <br>تعداد نگهداری: <input type="number" name="<?php echo $this->option_name; ?>[max_keep]" value="<?php echo esc_attr($opts['max_keep']); ?>" min="1" style="width:80px"></td></tr>
                            </table>

                            <p><button id="wds-send-test" class="wds-btn-primary">ارسال پیام تست</button> <span id="wds-test-result"></span></p>
                        </section>

                        <section id="tab-template" class="wds-panel">
                            <h2>فرمت پیام‌ها</h2>
                            <p>متغیرهای قابل استفاده: <code>{archive_name}</code>, <code>{archive_url}</code>, <code>{size_mb}</code>, <code>{date}</code></p>
                            <p>حالت فرمت:</p>
                            <select name="<?php echo $this->option_name; ?>[parse_mode]">
                                <option value="HTML" <?php selected('HTML',$opts['parse_mode']); ?>>HTML</option>
                                <option value="MarkdownV2" <?php selected('MarkdownV2',$opts['parse_mode']); ?>>MarkdownV2</option>
                            </select>
                            <p>قالب پیام:</p>
                            <textarea name="<?php echo $this->option_name; ?>[msg_template]" rows="8" style="width:100%"><?php echo esc_textarea($opts['msg_template']); ?></textarea>
                            <p class="wds-desc">برای راهنمایی بیشتر درباره بولد/زیرخط از حالت انتخاب‌شده استفاده کنید.</p>
                        </section>

                        <section id="tab-public" class="wds-panel">
                            <h2>ارسال پیام عمومی</h2>
                            <p>پیام به تاپیک عمومی (آیدی پیش‌فرض: 1)</p>
                            <textarea id="wds-public-msg" rows="5" style="width:100%"></textarea>
                            <p><button id="wds-send-public" class="wds-btn-primary">ارسال پیام عمومی</button> <span id="wds-public-result"></span></p>

                            <h3>تنظیمات ارسال خودکار</h3>
                            <p>در هنگام فعال‌سازی یا بروزرسانی افزونه، پیام اطلاع‌رسانی به تاپیک عمومی ارسال می‌شود (قابل غیرفعال‌سازی از طریق حذف توکن یا چت آیدی).</p>
                        </section>

                    </div>

                    <input type="hidden" name="<?php echo $this->option_name; ?>[duplicator_dir]" value="<?php echo esc_attr($opts['duplicator_dir']); ?>">
                    <?php submit_button('ذخیره تنظیمات','primary','wds-submit',false); ?>
                </form>

            </div>
        </div>

        <style>
        .wds-wrap{font-family:inherit}
        .wds-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
        .wds-btn-primary{background: linear-gradient(90deg,#2563eb,#06b6d4); color:#fff;border:none;padding:8px 14px;border-radius:8px;cursor:pointer}
        .wds-nav{display:flex;gap:10px;margin-bottom:12px}
        .wds-tab-link{padding:8px 12px;border-radius:8px;background:#f1f5f9;cursor:pointer}
        .wds-tab-link.active{background:#1e73be;color:#fff}
        .wds-panel{display:none;padding:12px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .wds-panel.active{display:block}
        </style>

        <script>
        (function($){
            $('.wds-tab-link').off('click').on('click', function(){
                $('.wds-tab-link').removeClass('active');
                $(this).addClass('active');
                var tab = $(this).data('tab');
                $('.wds-panel').removeClass('active');
                $('#tab-'+tab).addClass('active');
            });

            $('#wds-save-btn').off('click').on('click', function(e){
                e.preventDefault();
                $('#wds-submit').click();
            });

            $('#wds-send-test').off('click').on('click', function(e){
                e.preventDefault();
                $('#wds-test-result').text('در حال ارسال...');
                $.post(ajaxurl, { action: 'wds_send_test', _wpnonce: '<?php echo wp_create_nonce("wds_nonce"); ?>' }, function(r){
                    $('#wds-test-result').text(r.data ? r.data : r);
                });
            });

            $('#wds-send-public').off('click').on('click', function(e){
                e.preventDefault();
                $('#wds-public-result').text('در حال ارسال...');
                $.post(ajaxurl, { action: 'wds_manual_public', message: $('#wds-public-msg').val(), _wpnonce: '<?php echo wp_create_nonce("wds_nonce"); ?>' }, function(r){
                    $('#wds-public-result').text(r.data ? r.data : r);
                });
            });

            // manual send for files
            $('.wds-send-file').off('click').on('click', function(e){
                e.preventDefault();
                var file = $(this).data('file');
                if(!confirm('آیا مطمئن هستید می‌خواهید این فایل را ارسال کنید؟')) return;
                $(this).text('در حال ارسال...');
                $.post(ajaxurl, { action: 'wds_send_file', file: file, _wpnonce: '<?php echo wp_create_nonce("wds_nonce"); ?>' }, function(r){
                    alert(r.data || r);
                    location.reload();
                });
            });

        })(jQuery);
        </script>

        <?php
    }

    private function get_dup_files($dir){
        $dir = rtrim($dir,'/\\') . '/';
        if (!is_dir($dir)) return array();
        $files = glob($dir . '*.{zip,php}', GLOB_BRACE);
        if (!$files) return array();
        usort($files, function($a,$b){ return filemtime($b) - filemtime($a); });
        return $files;
    }

    private function get_backup_url_for_download($basename){
        return $this->get_backup_url_base() . $basename;
    }

    public function shutdown_check(){
        $opts = get_option($this->option_name);
        $dup_dir = rtrim($opts['duplicator_dir'],'/\\') . '/';
        if (!is_dir($dup_dir)) return;

        $files = $this->get_dup_files($dup_dir);
        if (empty($files)) return;

        $newer = 0;
        foreach($files as $f) $newer = max($newer, filemtime($f));
        $last = get_option($this->last_processed_option,0);
        if ($newer <= $last) return;

        $archive = null; $installer = null;
        foreach($files as $f){
            $ext = strtolower(pathinfo($f,PATHINFO_EXTENSION));
            if (!$archive && $ext === 'zip') $archive = $f;
            if (!$installer && $ext === 'php' && stripos(basename($f),'installer')!==false) $installer = $f;
            if ($archive && $installer) break;
        }
        if (!$archive){
            foreach($files as $f){
                if (strtolower(pathinfo($f,PATHINFO_EXTENSION)) === 'zip'){ $archive = $f; break; }
            }
        }
        if (!$archive) return;

        $time = time();
        $dest_dir = $this->get_backup_dir();
        $final_name = 'backup-' . date('Ymd-His',$time) . '.zip';
        $final_path = $dest_dir . $final_name;

        $zip = new ZipArchive();
        if ($zip->open($final_path, ZipArchive::CREATE)!==true) return;
        $zip->addFile($archive, basename($archive));
        if ($installer && file_exists($installer)) $zip->addFile($installer, basename($installer));
        $zip->close();

        if (!empty($opts['auto_delete_old'])) $this->cleanup_final_files($dest_dir, intval($opts['max_keep']));

        $this->cleanup_dup_origins($dup_dir, array($archive,$installer));

        $size_mb = round(filesize($final_path)/1024/1024,2);
        // Build archive_url using site_url setting when present
        $site_field = rtrim($opts['site_url'] ?? '', '/');
        if (!empty($site_field)) {
            // ensure scheme
            if (!preg_match('/^https?:\\/\\//i',$site_field)) $site_field = 'https://' . $site_field;
            $archive_url = $site_field . '/wp-content/uploads/BackupSite/' . $final_name;
        } else {
            $archive_url = 'File stored at: ' . $final_path;
        }

        $repl = array(
            '{archive_name}' => $final_name,
            '{archive_url}' => $archive_url,
            '{size_mb}' => $size_mb,
            '{date}' => date('Y-m-d H:i:s',$time)
        );
        $message = strtr($opts['msg_template'],$repl);

        $hash = md5($message);
        $last_hash = get_option($this->last_message_hash,'');
        if ($last_hash === $hash) {
            update_option($this->last_processed_option, $time);
            return;
        }

        $sent = $this->send_telegram_message($opts['bot_token'],$opts['chat_id'],$message,$opts['thread_id'],$opts['parse_mode']);

        if ($sent){
            update_option($this->last_message_hash,$hash);
            update_option($this->last_processed_option,$time);
        }
    }

    private function cleanup_final_files($dir,$max_keep=1){
        $files = glob($dir . '*.zip');
        if (!$files) return;
        usort($files, function($a,$b){ return filemtime($b)-filemtime($a); });
        $remove = array_slice($files, $max_keep);
        foreach($remove as $r) @unlink($r);
    }

    private function cleanup_dup_origins($dup_dir,$except=array()){
        $files = glob($dup_dir . '*.{zip,php}', GLOB_BRACE);
        foreach($files as $f){
            if (in_array($f,$except)) continue;
            if (time() - filemtime($f) < 5) continue;
            @unlink($f);
        }
    }

    private function send_telegram_message($token,$chat,$text,$thread='',$parse='HTML'){
        if (empty($token) || empty($chat)) return false;
        $url = "https://api.telegram.org/bot".$token."/sendMessage";
        $params = array('chat_id'=>$chat,'text'=>$text,'disable_web_page_preview'=>false);
        if (!empty($thread)) $params['message_thread_id'] = (int)$thread;
        if (!empty($parse)) $params['parse_mode'] = ($parse==='MarkdownV2'?'MarkdownV2':'HTML');
        $args = array('body'=>$params,'timeout'=>15);
        $resp = wp_remote_post($url,$args);
        if (is_wp_error($resp)) return false;
        $code = wp_remote_retrieve_response_code($resp);
        return ($code>=200 && $code<300);
    }

    public function ajax_send_test(){
        check_ajax_referer('wds_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('عدم دسترسی');
        $opts = get_option($this->option_name);
        $msg = "🔧 پیام تست - WP Dup Sender\nتاریخ: ".date('Y-m-d H:i:s');
        $hash = md5($msg);
        $last_hash = get_option($this->last_message_hash,'');
        if ($last_hash === $hash && (time() - (int)get_option($this->last_processed_option,0) < 5)) {
            wp_send_json_error('پیام قبلاً ارسال شده');
        }
        $ok = $this->send_telegram_message($opts['bot_token'],$opts['chat_id'],$msg,$opts['public_thread_id'],$opts['parse_mode']);
        if ($ok){
            update_option($this->last_message_hash,$hash);
            update_option($this->last_processed_option,time());
            wp_send_json_success('پیام تست ارسال شد');
        }
        wp_send_json_error('ارسال ناموفق - تنظیمات را بررسی کنید');
    }

    public function ajax_manual_public(){
        check_ajax_referer('wds_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('عدم دسترسی');
        $msg = sanitize_textarea_field($_POST['message'] ?? '');
        if (empty($msg)) wp_send_json_error('پیام خالی است');
        $opts = get_option($this->option_name);
        $hash = md5($msg);
        $last_hash = get_option($this->last_message_hash,'');
        if ($last_hash === $hash && (time() - (int)get_option($this->last_processed_option,0) < 5)) {
            wp_send_json_error('پیام قبلاً ارسال شده');
        }
        $ok = $this->send_telegram_message($opts['bot_token'],$opts['chat_id'],$msg,$opts['public_thread_id'],$opts['parse_mode']);
        if ($ok){
            update_option($this->last_message_hash,$hash);
            update_option($this->last_processed_option,time());
            wp_send_json_success('پیام عمومی ارسال شد');
        }
        wp_send_json_error('ارسال ناموفق');
    }

    public function ajax_send_file(){
        check_ajax_referer('wds_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('عدم دسترسی');
        $file = sanitize_text_field($_POST['file'] ?? '');
        if (empty($file)) wp_send_json_error('نام فایل خالی است');
        $opts = get_option($this->option_name);
        $dup_dir = rtrim($opts['duplicator_dir'],'/\\') . '/';
        $full = $dup_dir . $file;
        if (!file_exists($full)) wp_send_json_error('فایل منبع یافت نشد');

        $time = time();
        $dest_dir = $this->get_backup_dir();
        $final_name = 'manual-' . date('Ymd-His',$time) . '-' . sanitize_file_name($file) . '.zip';
        $final_path = $dest_dir . $final_name;
        $zip = new ZipArchive();
        if ($zip->open($final_path, ZipArchive::CREATE)!==true) wp_send_json_error('خطا در ایجاد آرشیو');
        $zip->addFile($full, basename($full));
        $installer = dirname($full) . '/' . preg_replace('/\.zip$/i','-installer.php', basename($full));
        if (file_exists($installer)) $zip->addFile($installer, basename($installer));
        $zip->close();

        $size_mb = round(filesize($final_path)/1024/1024,2);
        $site_field = rtrim($opts['site_url'] ?? '', '/');
        if (!empty($site_field)) {
            if (!preg_match('/^https?:\\/\\//i',$site_field)) $site_field = 'https://' . $site_field;
            $archive_url = $site_field . '/wp-content/uploads/BackupSite/' . $final_name;
        } else {
            $archive_url = 'File stored at: ' . $final_path;
        }

        $repl = array('{archive_name}'=>$final_name,'{archive_url}'=>$archive_url,'{size_mb}'=>$size_mb,'{date}'=>date('Y-m-d H:i:s',$time));
        $message = strtr($opts['msg_template'],$repl);

        $hash = md5($message);
        $last_hash = get_option($this->last_message_hash,'');
        if ($last_hash === $hash) wp_send_json_error('پیام قبلاً ارسال شده');

        $sent = $this->send_telegram_message($opts['bot_token'],$opts['chat_id'],$message,$opts['thread_id'],$opts['parse_mode']);
        if ($sent){
            update_option($this->last_message_hash,$hash);
            update_option($this->last_processed_option,$time);
            wp_send_json_success('فایل ارسال شد');
        }
        wp_send_json_error('ارسال ناموفق');
    }

    public function ajax_set_webhook(){
        check_ajax_referer('wds_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('عدم دسترسی');
        $opts = get_option($this->option_name);
        $token = trim($opts['bot_token'] ?? '');
        if (empty($token)) wp_send_json_error('توکن ربات تنظیم نشده');
        $webhook_url = site_url('?wds_webhook=1');
        $url = "https://api.telegram.org/bot{$token}/setWebhook";
        $response = wp_remote_post($url, array('body'=>array('url'=>$webhook_url),'timeout'=>15));
        if (is_wp_error($response)) {
            wp_send_json_error('خطا در فراخوانی API: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code >=200 && $code <300) {
            wp_send_json_success('درخواست وبهوک ارسال شد. پاسخ: ' . wp_strip_all_tags($body));
        } else {
            wp_send_json_error('خطا: ' . wp_strip_all_tags($body));
        }
    }

    public function handle_webhook_endpoint(){
        if (isset($_GET['wds_webhook'])){
            status_header(200);
            echo 'OK';
            exit;
        }
    }
} // end class

new WP_Dup_Sender();
