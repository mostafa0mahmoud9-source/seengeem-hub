<?php
/**
 * Plugin Name: SeenGeem WhatsApp Bridge
 * Description: جسر واتساب لمساعد سين جيم: رقمان (طلاب وأولياء أمور / مدرسين ومتابعة)، Cloud API رسمي + بوابة QR احتياطية، طابور رسائل، تتبع التسليم، وواجهة API للتطبيقات.
 * Version: 2.0.0
 * Author: SeenGeem
 * Text Domain: sgb
 */
if (!defined('ABSPATH')) { exit; }

define('SGB_VER', '2.0.0');
define('SGB_OPT', 'sgb_settings');

/* ───────────── الإعدادات ───────────── */
function sgb_defaults() {
    return array(
        'api_key'     => '',
        'app_secret'  => '',
        'verify_token'=> '',
        'quiet_start' => 22,
        'quiet_end'   => 8,
        'daily_cap'   => 800,
        'max_attempts'=> 3,
        'channels'    => array(
            'students' => array('mode' => 'both', 'phone_number_id' => '', 'token' => '', 'label' => 'رقم الطلاب وأولياء الأمور'),
            'staff'    => array('mode' => 'both', 'phone_number_id' => '', 'token' => '', 'label' => 'رقم المدرسين والمتابعة'),
        ),
    );
}
function sgb_settings() {
    $s = get_option(SGB_OPT, array());
    $d = sgb_defaults();
    $s = array_replace_recursive($d, is_array($s) ? $s : array());
    return $s;
}
function sgb_channel($ch) {
    $s = sgb_settings();
    return isset($s['channels'][$ch]) ? $s['channels'][$ch] : null;
}

/* ───────────── التفعيل وقاعدة البيانات ───────────── */
register_activation_hook(__FILE__, 'sgb_activate');
function sgb_activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = $wpdb->prefix . 'sgb_outbox';
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        channel VARCHAR(16) NOT NULL,
        to_phone VARCHAR(20) NOT NULL,
        body TEXT NULL,
        template VARCHAR(100) NULL,
        params TEXT NULL,
        rule_key VARCHAR(60) NULL,
        dedupe_key VARCHAR(120) NULL,
        priority TINYINT NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT 'queued',
        attempts TINYINT NOT NULL DEFAULT 0,
        next_try INT UNSIGNED NOT NULL DEFAULT 0,
        wa_id VARCHAR(100) NULL,
        error TEXT NULL,
        created INT UNSIGNED NOT NULL,
        sent_at INT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY st (status, channel, next_try),
        KEY dd (dedupe_key),
        KEY wa (wa_id)
    ) $c;");
    $t2 = $wpdb->prefix . 'sgb_incoming';
    dbDelta("CREATE TABLE $t2 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        channel VARCHAR(16) NOT NULL,
        from_phone VARCHAR(20) NOT NULL,
        body TEXT NULL,
        created INT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        KEY ph (from_phone)
    ) $c;");
    $s = sgb_settings();
    if (!$s['api_key'])      { $s['api_key'] = wp_generate_password(40, false); }
    if (!$s['verify_token']) { $s['verify_token'] = wp_generate_password(24, false); }
    update_option(SGB_OPT, $s, false);
    if (!wp_next_scheduled('sgb_cron')) { wp_schedule_event(time() + 60, 'sgb_minute', 'sgb_cron'); }
}
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('sgb_cron'); });

add_filter('cron_schedules', function ($s) {
    $s['sgb_minute'] = array('interval' => 60, 'display' => 'كل دقيقة');
    return $s;
});
add_action('init', function () {
    if (!wp_next_scheduled('sgb_cron')) { wp_schedule_event(time() + 60, 'sgb_minute', 'sgb_cron'); }
});

/* ───────────── أدوات ───────────── */
function sgb_phone($raw) {
    $p = preg_replace('/\D+/', '', (string) $raw);
    if ($p === '') { return ''; }
    if (strpos($p, '00') === 0) { $p = substr($p, 2); }
    if (strlen($p) === 11 && strpos($p, '01') === 0) { $p = '2' . $p; }       // 01xxxxxxxxx → 201xxxxxxxxx
    elseif (strlen($p) === 10 && strpos($p, '1') === 0) { $p = '20' . $p; }   // 1xxxxxxxxx  → 201xxxxxxxxx
    return (strlen($p) >= 11 && strlen($p) <= 15) ? $p : '';
}
function sgb_mask($p) { return strlen($p) > 6 ? substr($p, 0, 4) . '****' . substr($p, -3) : $p; }
function sgb_is_optout($phone) {
    $list = get_option('sgb_optout', array());
    return in_array($phone, (array) $list, true);
}
function sgb_set_optout($phone, $on = true) {
    $list = (array) get_option('sgb_optout', array());
    $list = array_values(array_diff($list, array($phone)));
    if ($on) { $list[] = $phone; }
    update_option('sgb_optout', array_values(array_unique($list)), false);
}
function sgb_render($text, $vars) {
    foreach ((array) $vars as $k => $v) { $text = str_replace('{' . $k . '}', (string) $v, $text); }
    return $text;
}
function sgb_in_quiet_hours() {
    $s = sgb_settings();
    $h = (int) current_time('G');
    $a = (int) $s['quiet_start']; $b = (int) $s['quiet_end'];
    if ($a === $b) { return false; }
    return $a > $b ? ($h >= $a || $h < $b) : ($h >= $a && $h < $b);
}

/**
 * إضافة رسالة للطابور. تُستدعى من أي مكان:
 *   do_action('sgb_enqueue', 'students', '01012345678', 'نص الرسالة', array('rule'=>'renew_7','dedupe'=>'renew_7:55:2026-12-01'));
 * أو عبر واجهة REST: POST /wp-json/sgb/v1/enqueue
 */
function sgb_enqueue($channel, $phone, $body, $args = array()) {
    global $wpdb;
    $channel = $channel === 'staff' ? 'staff' : 'students';
    $p = sgb_phone($phone);
    if (!$p) { return new WP_Error('bad_phone', 'رقم غير صالح'); }
    if (sgb_is_optout($p)) { return new WP_Error('optout', 'العميل طلب إيقاف الرسائل'); }
    $t = $wpdb->prefix . 'sgb_outbox';
    $dk = isset($args['dedupe']) ? substr((string) $args['dedupe'], 0, 120) : null;
    if ($dk) {
        $hrs = isset($args['dedupe_hours']) ? max(1, (int) $args['dedupe_hours']) : 72;
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE dedupe_key=%s AND created>%d AND status<>'failed' LIMIT 1", $dk, time() - $hrs * 3600));
        if ($exists) { return new WP_Error('duplicate', 'رسالة مكررة تم تجاهلها'); }
    }
    $wpdb->insert($t, array(
        'channel'  => $channel,
        'to_phone' => $p,
        'body'     => wp_kses_post_deep_text($body),
        'template' => isset($args['template']) ? sanitize_text_field($args['template']) : null,
        'params'   => isset($args['params']) ? wp_json_encode(array_values((array) $args['params'])) : null,
        'rule_key' => isset($args['rule']) ? sanitize_key($args['rule']) : null,
        'dedupe_key' => $dk,
        'priority' => isset($args['priority']) ? (int) $args['priority'] : 0,
        'status'   => 'queued',
        'created'  => time(),
    ));
    return (int) $wpdb->insert_id;
}
function wp_kses_post_deep_text($t) { return trim(wp_strip_all_tags((string) $t)); }
add_action('sgb_enqueue', function ($ch, $phone, $body, $args = array()) { sgb_enqueue($ch, $phone, $body, $args); }, 10, 4);

/* ───────────── الإرسال عبر Cloud API ───────────── */
function sgb_send_cloud($row) {
    $cfg = sgb_channel($row->channel);
    if (!$cfg || !$cfg['phone_number_id'] || !$cfg['token']) { return new WP_Error('no_cloud', 'Cloud API غير مضبوط لهذه القناة'); }
    $payload = array('messaging_product' => 'whatsapp', 'to' => $row->to_phone);
    if ($row->template) {
        $params = $row->params ? json_decode($row->params, true) : array();
        $comp = array();
        if ($params) {
            $comp[] = array('type' => 'body', 'parameters' => array_map(function ($v) { return array('type' => 'text', 'text' => (string) $v); }, $params));
        }
        $payload['type'] = 'template';
        $payload['template'] = array('name' => $row->template, 'language' => array('code' => 'ar'), 'components' => $comp);
    } else {
        $payload['type'] = 'text';
        $payload['text'] = array('preview_url' => false, 'body' => $row->body);
    }
    $r = wp_remote_post('https://graph.facebook.com/v21.0/' . rawurlencode($cfg['phone_number_id']) . '/messages', array(
        'timeout' => 20,
        'headers' => array('Authorization' => 'Bearer ' . $cfg['token'], 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode($payload),
    ));
    if (is_wp_error($r)) { return $r; }
    $code = wp_remote_retrieve_response_code($r);
    $j = json_decode(wp_remote_retrieve_body($r), true);
    if ($code >= 200 && $code < 300 && !empty($j['messages'][0]['id'])) { return $j['messages'][0]['id']; }
    $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
    $ec = isset($j['error']['code']) ? $j['error']['code'] : 0;
    return new WP_Error('cloud_' . $ec, $msg);
}

/* ───────────── معالج الطابور (كرون كل دقيقة) ───────────── */
add_action('sgb_cron', 'sgb_process_queue');
function sgb_process_queue() {
    global $wpdb;
    if (get_transient('sgb_lock')) { return; }
    set_transient('sgb_lock', 1, 55);
    $t = $wpdb->prefix . 'sgb_outbox';
    $s = sgb_settings();
    $quiet = sgb_in_quiet_hours();
    $midnight = (new DateTime('today', wp_timezone()))->getTimestamp();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE status='queued' AND next_try<=%d ORDER BY priority DESC, id ASC LIMIT 25", time()));
    foreach ($rows as $row) {
        if ($quiet && (int) $row->priority < 1) { continue; }
        $cfg = sgb_channel($row->channel);
        $sentToday = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE channel=%s AND sent_at>=%d AND status IN ('sent','delivered','read')", $row->channel, $midnight));
        if ($sentToday >= (int) $s['daily_cap'] && (int) $row->priority < 1) { continue; }
        $mode = $cfg ? $cfg['mode'] : 'both';
        if ($mode === 'qr') {
            $wpdb->update($t, array('status' => 'qr_pending'), array('id' => $row->id));
            continue;
        }
        $res = sgb_send_cloud($row);
        if (is_wp_error($res)) {
            $att = (int) $row->attempts + 1;
            if ($mode === 'both') {
                $wpdb->update($t, array('status' => 'qr_pending', 'attempts' => $att, 'error' => $res->get_error_message()), array('id' => $row->id));
            } elseif ($att >= (int) $s['max_attempts']) {
                $wpdb->update($t, array('status' => 'failed', 'attempts' => $att, 'error' => $res->get_error_message()), array('id' => $row->id));
            } else {
                $wpdb->update($t, array('attempts' => $att, 'next_try' => time() + 120 * $att, 'error' => $res->get_error_message()), array('id' => $row->id));
            }
        } else {
            $wpdb->update($t, array('status' => 'sent', 'wa_id' => $res, 'sent_at' => time(), 'error' => null), array('id' => $row->id));
        }
    }
    delete_transient('sgb_lock');
}

/* ───────────── REST API ───────────── */
function sgb_auth($req) {
    if (current_user_can('manage_options')) { return true; }
    $s = sgb_settings();
    $k = $req->get_header('x-sgb-key');
    return $s['api_key'] && $k && hash_equals($s['api_key'], $k);
}
add_action('rest_api_init', function () {
    $ns = 'sgb/v1';
    register_rest_route($ns, '/webhook', array(
        array('methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sgb_webhook_verify'),
        array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sgb_webhook_receive'),
    ));
    register_rest_route($ns, '/enqueue', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = $req->get_json_params();
        $items = isset($b['items']) ? $b['items'] : array($b);
        $out = array();
        foreach ((array) $items as $it) {
            $r = sgb_enqueue(isset($it['channel']) ? $it['channel'] : 'students', isset($it['phone']) ? $it['phone'] : '', isset($it['body']) ? $it['body'] : '', $it);
            $out[] = is_wp_error($r) ? array('ok' => false, 'error' => $r->get_error_code()) : array('ok' => true, 'id' => $r);
        }
        return $out;
    }));
    register_rest_route($ns, '/pull', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'sgb_outbox';
        $ch = $req->get_param('channel') === 'staff' ? 'staff' : 'students';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,to_phone,body FROM $t WHERE status='qr_pending' AND channel=%s ORDER BY priority DESC, id ASC LIMIT 20", $ch));
        foreach ($rows as $r) { $wpdb->update($t, array('status' => 'sending'), array('id' => $r->id)); }
        return $rows;
    }));
    register_rest_route($ns, '/ack', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'sgb_outbox';
        $b = $req->get_json_params();
        $ok = !empty($b['ok']);
        $wpdb->update($t, array(
            'status'  => $ok ? 'sent' : 'failed',
            'sent_at' => $ok ? time() : null,
            'wa_id'   => $ok && !empty($b['wa_id']) ? sanitize_text_field($b['wa_id']) : null,
            'error'   => $ok ? null : sanitize_text_field(isset($b['error']) ? $b['error'] : 'qr_failed'),
        ), array('id' => (int) $b['id']));
        return array('ok' => true);
    }));
    register_rest_route($ns, '/incoming', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = $req->get_json_params();
        sgb_store_incoming(isset($b['channel']) ? $b['channel'] : 'students', isset($b['from']) ? $b['from'] : '', isset($b['body']) ? $b['body'] : '');
        return array('ok' => true);
    }));
    register_rest_route($ns, '/stats', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => 'sgb_stats'));
    register_rest_route($ns, '/retry', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'sgb_outbox';
        $wpdb->query("UPDATE $t SET status='queued', attempts=0, next_try=0 WHERE status='failed'");
        return array('ok' => true);
    }));
    register_rest_route($ns, '/optout', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = $req->get_json_params();
        $p = sgb_phone(isset($b['phone']) ? $b['phone'] : '');
        if (!$p) { return new WP_Error('bad_phone', 'رقم غير صالح', array('status' => 400)); }
        sgb_set_optout($p, empty($b['remove']));
        return array('ok' => true);
    }));
    register_rest_route($ns, '/send-test', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = $req->get_json_params();
        $r = sgb_enqueue(isset($b['channel']) ? $b['channel'] : 'students', isset($b['phone']) ? $b['phone'] : '', isset($b['body']) ? $b['body'] : 'رسالة تجربة من مساعد سين جيم 💜', array('priority' => 2));
        return is_wp_error($r) ? new WP_Error($r->get_error_code(), $r->get_error_message(), array('status' => 400)) : array('ok' => true, 'id' => $r);
    }));
});

function sgb_store_incoming($channel, $from, $body) {
    global $wpdb;
    $p = sgb_phone($from);
    if (!$p) { return; }
    $wpdb->insert($wpdb->prefix . 'sgb_incoming', array('channel' => $channel === 'staff' ? 'staff' : 'students', 'from_phone' => $p, 'body' => mb_substr(wp_strip_all_tags((string) $body), 0, 1000), 'created' => time()));
    if (preg_match('/^\s*(stop|إيقاف|ايقاف|الغاء|إلغاء|لا ترسل)\s*$/iu', (string) $body)) { sgb_set_optout($p, true); }
    if (preg_match('/^\s*(start|اشتراك|تشغيل)\s*$/iu', (string) $body)) { sgb_set_optout($p, false); }
    do_action('sgb_incoming_message', $channel, $p, $body);
}

function sgb_webhook_verify($req) {
    $s = sgb_settings();
    if ($req->get_param('hub_mode') === 'subscribe' && $s['verify_token'] && hash_equals($s['verify_token'], (string) $req->get_param('hub_verify_token'))) {
        header('Content-Type: text/plain');
        echo esc_html((string) $req->get_param('hub_challenge'));
        exit;
    }
    return new WP_Error('forbidden', 'رمز التحقق غير صحيح', array('status' => 403));
}
function sgb_webhook_receive($req) {
    global $wpdb;
    $s = sgb_settings();
    $raw = $req->get_body();
    if ($s['app_secret']) {
        $sig = $req->get_header('x-hub-signature-256');
        $calc = 'sha256=' . hash_hmac('sha256', $raw, $s['app_secret']);
        if (!$sig || !hash_equals($calc, $sig)) { return new WP_Error('bad_sig', 'توقيع غير صحيح', array('status' => 403)); }
    }
    $j = json_decode($raw, true);
    $t = $wpdb->prefix . 'sgb_outbox';
    foreach ((array) (isset($j['entry']) ? $j['entry'] : array()) as $e) {
        foreach ((array) (isset($e['changes']) ? $e['changes'] : array()) as $c) {
            $v = isset($c['value']) ? $c['value'] : array();
            $pid = isset($v['metadata']['phone_number_id']) ? $v['metadata']['phone_number_id'] : '';
            $chan = 'students';
            foreach ($s['channels'] as $k => $cfg) { if ($cfg['phone_number_id'] && $cfg['phone_number_id'] === $pid) { $chan = $k; } }
            foreach ((array) (isset($v['statuses']) ? $v['statuses'] : array()) as $st) {
                $map = array('sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed');
                if (!empty($st['id']) && isset($map[$st['status']])) {
                    $upd = array('status' => $map[$st['status']]);
                    if ($st['status'] === 'failed') { $upd['error'] = isset($st['errors'][0]['title']) ? sanitize_text_field($st['errors'][0]['title']) : 'failed'; }
                    $wpdb->update($t, $upd, array('wa_id' => $st['id']));
                }
            }
            foreach ((array) (isset($v['messages']) ? $v['messages'] : array()) as $m) {
                $body = isset($m['text']['body']) ? $m['text']['body'] : (isset($m['button']['text']) ? $m['button']['text'] : '');
                sgb_store_incoming($chan, isset($m['from']) ? $m['from'] : '', $body);
            }
        }
    }
    return array('ok' => true);
}

function sgb_stats() {
    global $wpdb;
    $t = $wpdb->prefix . 'sgb_outbox';
    $midnight = (new DateTime('today', wp_timezone()))->getTimestamp();
    $by = $wpdb->get_results("SELECT channel, status, COUNT(*) n FROM $t GROUP BY channel, status", ARRAY_A);
    $today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE sent_at>=%d AND status IN ('sent','delivered','read')", $midnight));
    $recent = $wpdb->get_results("SELECT id,channel,to_phone,body,rule_key,status,error,created FROM $t ORDER BY id DESC LIMIT 60", ARRAY_A);
    foreach ($recent as &$r) { $r['to_phone'] = sgb_mask($r['to_phone']); }
    $inc = $wpdb->get_results("SELECT channel,from_phone,body,created FROM {$wpdb->prefix}sgb_incoming ORDER BY id DESC LIMIT 30", ARRAY_A);
    foreach ($inc as &$r) { $r['from_phone'] = sgb_mask($r['from_phone']); }
    $s = sgb_settings();
    $conn = array();
    foreach ($s['channels'] as $k => $c) { $conn[$k] = array('label' => $c['label'], 'mode' => $c['mode'], 'cloud_ready' => (bool) ($c['phone_number_id'] && $c['token'])); }
    return array('time' => time(), 'sent_today' => $today, 'by' => $by, 'recent' => $recent, 'incoming' => $inc, 'channels' => $conn, 'quiet_now' => sgb_in_quiet_hours(), 'version' => SGB_VER);
}

/* ───────────── صفحة الإعدادات ───────────── */
add_action('admin_menu', function () {
    add_menu_page('واتساب المساعد', 'واتساب المساعد', 'manage_options', 'sgb', 'sgb_page', 'dashicons-format-chat', 58);
});
function sgb_page() {
    if (!current_user_can('manage_options')) { return; }
    $msg = '';
    if (isset($_POST['sgb_save']) && check_admin_referer('sgb_save')) {
        $s = sgb_settings();
        foreach (array('students', 'staff') as $k) {
            $s['channels'][$k]['mode'] = in_array($_POST["mode_$k"], array('cloud', 'qr', 'both'), true) ? $_POST["mode_$k"] : 'both';
            $s['channels'][$k]['phone_number_id'] = sanitize_text_field(wp_unslash($_POST["pid_$k"]));
            $tok = trim(wp_unslash($_POST["tok_$k"]));
            if ($tok !== '') { $s['channels'][$k]['token'] = sanitize_text_field($tok); }
        }
        $sec = trim(wp_unslash($_POST['app_secret']));
        if ($sec !== '') { $s['app_secret'] = sanitize_text_field($sec); }
        $s['quiet_start'] = max(0, min(23, (int) $_POST['quiet_start']));
        $s['quiet_end'] = max(0, min(23, (int) $_POST['quiet_end']));
        $s['daily_cap'] = max(1, (int) $_POST['daily_cap']);
        update_option(SGB_OPT, $s, false);
        $msg = 'تم الحفظ ✅';
    }
    $s = sgb_settings();
    $hook = esc_url(rest_url('sgb/v1/webhook'));
    echo '<div class="wrap" dir="rtl"><h1>واتساب مساعد سين جيم</h1>';
    if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
    echo '<form method="post">'; wp_nonce_field('sgb_save');
    foreach ($s['channels'] as $k => $c) {
        echo '<h2>' . esc_html($c['label']) . '</h2><table class="form-table">';
        echo '<tr><th>طريقة الإرسال</th><td><select name="mode_' . esc_attr($k) . '">';
        foreach (array('both' => 'Cloud API + QR احتياطي', 'cloud' => 'Cloud API فقط', 'qr' => 'QR فقط') as $v => $l) { echo '<option value="' . esc_attr($v) . '"' . selected($c['mode'], $v, false) . '>' . esc_html($l) . '</option>'; }
        echo '</select></td></tr>';
        echo '<tr><th>Phone Number ID</th><td><input class="regular-text" name="pid_' . esc_attr($k) . '" value="' . esc_attr($c['phone_number_id']) . '"></td></tr>';
        echo '<tr><th>Access Token</th><td><input class="regular-text" type="password" name="tok_' . esc_attr($k) . '" placeholder="' . ($c['token'] ? '•••• محفوظ (اتركه فارغًا للإبقاء عليه)' : '') . '" autocomplete="off"></td></tr></table>';
    }
    echo '<h2>عام</h2><table class="form-table">';
    echo '<tr><th>App Secret (للتحقق من الويب هوك)</th><td><input class="regular-text" type="password" name="app_secret" autocomplete="off" placeholder="' . ($s['app_secret'] ? '•••• محفوظ' : '') . '"></td></tr>';
    echo '<tr><th>ساعات الهدوء</th><td>من <input type="number" min="0" max="23" name="quiet_start" value="' . (int) $s['quiet_start'] . '" style="width:70px"> إلى <input type="number" min="0" max="23" name="quiet_end" value="' . (int) $s['quiet_end'] . '" style="width:70px"> (لا يُرسل غير العاجل)</td></tr>';
    echo '<tr><th>الحد اليومي لكل رقم</th><td><input type="number" name="daily_cap" value="' . (int) $s['daily_cap'] . '"></td></tr></table>';
    echo '<p><button class="button button-primary" name="sgb_save" value="1">حفظ</button></p></form>';
    echo '<hr><h2>بيانات الربط</h2><table class="form-table">';
    echo '<tr><th>Webhook URL (في Meta)</th><td><code>' . $hook . '</code></td></tr>';
    echo '<tr><th>Verify Token</th><td><code>' . esc_html($s['verify_token']) . '</code></td></tr>';
    echo '<tr><th>مفتاح التطبيقات / البوابة (X-SGB-Key)</th><td><code>' . esc_html($s['api_key']) . '</code> — ضعه في تطبيق الكمبيوتر والموبايل وفي بوابة QR.</td></tr>';
    echo '<tr><th>ربطها بالقواعد الحالية</th><td><code>do_action(\'sgb_enqueue\', \'students\', $phone, $text, array(\'rule\' =&gt; \'renew_7\', \'dedupe\' =&gt; \'renew_7:\'.$id));</code></td></tr></table></div>';
}
require_once __DIR__ . '/includes/import.php';
