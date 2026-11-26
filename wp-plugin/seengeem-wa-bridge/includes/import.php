<?php
/**
 * وصلة استيراد رسائل «مساعد سين جيم» القديم (sgh/v1) إلى طابور الواتساب.
 * - تقرأ الرسائل الجاهزة (Jobs) من واجهة الإضافة القديمة داخليًا (بدون الحاجة لكودها).
 * - تحترم: الموافقة (consent)، إلغاء الاشتراك، الإيقاف المؤقت، موعد الاستحقاق، الحد اليومي لكل جهة.
 * - مغلقة افتراضيًا (mode=off) حتى تفعّلها من «واتساب المساعد ← استيراد المساعد».
 */
if (!defined('ABSPATH')) { exit; }

function sgb_imp_defaults() {
    return array('mode' => 'off', 'require_consent' => 1, 'per_contact_cap' => 2, 'templates' => '', 'statuses' => 'preview,queued,pending,due');
}
function sgb_imp_settings() {
    return array_replace(sgb_imp_defaults(), (array) get_option('sgb_import', array()));
}
function sgb_imp_template_map($text) {
    $map = array();
    foreach (preg_split('/\R/', (string) $text) as $line) {
        if (strpos($line, '=') !== false) {
            list($k, $v) = array_map('trim', explode('=', $line, 2));
            if ($k !== '' && $v !== '') { $map[sanitize_key($k)] = sanitize_text_field($v); }
        }
    }
    return $map;
}

/** قرار قبول/رفض رسالة (دالة نقية لتسهّل الاختبار). */
function sgb_import_decide($job, $contact, $cfg, $now) {
    if (!$contact) { return array(false, 'no_contact'); }
    $allowed = array_map('trim', explode(',', (string) $cfg['statuses']));
    if (!in_array(isset($job['status']) ? $job['status'] : '', $allowed, true)) { return array(false, 'status'); }
    if ((int) (isset($job['due']) ? $job['due'] : 0) > $now) { return array(false, 'not_due'); }
    if (empty($contact['phone'])) { return array(false, 'no_phone'); }
    if ((int) (isset($contact['opted_out']) ? $contact['opted_out'] : 0)) { return array(false, 'opted_out'); }
    if ((int) (isset($contact['paused_until']) ? $contact['paused_until'] : 0) > $now) { return array(false, 'paused'); }
    if (!empty($cfg['require_consent']) && !(int) (isset($contact['consent']) ? $contact['consent'] : 0)) { return array(false, 'no_consent'); }
    if ((isset($job['category']) ? $job['category'] : '') === 'marketing' && !(int) (isset($contact['marketing']) ? $contact['marketing'] : 0)) { return array(false, 'no_marketing'); }
    if (trim((string) (isset($job['body']) ? $job['body'] : '')) === '') { return array(false, 'empty'); }
    return array(true, 'ok');
}

function sgb_import_fetch_state() {
    $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
    if (!$admins) { return new WP_Error('no_admin', 'لا يوجد مستخدم مدير'); }
    $prev = get_current_user_id();
    wp_set_current_user((int) $admins[0]);
    $res = rest_do_request(new WP_REST_Request('GET', '/sgh/v1/state'));
    wp_set_current_user($prev);
    if ($res->is_error()) { return $res->as_error(); }
    return $res->get_data();
}

function sgb_import_run() {
    global $wpdb;
    $cfg = sgb_imp_settings();
    $stats = array('time' => time(), 'seen' => 0, 'queued' => 0, 'skipped' => array());
    $state = sgb_import_fetch_state();
    if (is_wp_error($state)) {
        $stats['error'] = $state->get_error_message();
        update_option('sgb_import_last', $stats, false);
        return $stats;
    }
    $contacts = array();
    foreach ((array) (isset($state['contacts']) ? $state['contacts'] : array()) as $c) { $contacts[$c['id']] = $c; }
    $jobs = (array) (isset($state['jobs']) ? $state['jobs'] : array());
    usort($jobs, function ($a, $b) { return (int) $a['priority'] - (int) $b['priority']; });
    $now = time();
    $midnight = (new DateTime('today', wp_timezone()))->getTimestamp();
    $t = $wpdb->prefix . 'sgb_outbox';
    $tpl = sgb_imp_template_map($cfg['templates']);
    foreach ($jobs as $job) {
        $stats['seen']++;
        $c = isset($contacts[$job['contact_id']]) ? $contacts[$job['contact_id']] : null;
        list($ok, $why) = sgb_import_decide($job, $c, $cfg, $now);
        if ($ok) {
            $phone = sgb_phone($c['phone']);
            if (!$phone) { $ok = false; $why = 'bad_phone'; }
            else {
                $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE to_phone=%s AND created>=%d AND status<>'failed'", $phone, $midnight));
                if ($n >= (int) $cfg['per_contact_cap']) { $ok = false; $why = 'contact_cap'; }
            }
        }
        if (!$ok) { $stats['skipped'][$why] = (isset($stats['skipped'][$why]) ? $stats['skipped'][$why] : 0) + 1; continue; }
        $ctx = json_decode((string) $job['context'], true);
        $args = array('rule' => $job['rule_key'], 'dedupe' => 'sgh:' . $job['dedupe'], 'dedupe_hours' => 720, 'priority' => ((int) $job['priority'] <= 12) ? 1 : 0);
        if (isset($tpl[$job['rule_key']])) {
            $args['template'] = $tpl[$job['rule_key']];
            $vars = is_array($ctx) && isset($ctx['vars']) ? (array) $ctx['vars'] : array();
            $args['params'] = array_map(function ($v) { return is_scalar($v) ? (string) $v : ''; }, array_values($vars));
        }
        $body = html_entity_decode(wp_strip_all_tags((string) $job['body']), ENT_QUOTES, 'UTF-8');
        $r = sgb_enqueue($job['channel'] === 'staff' ? 'staff' : 'students', $c['phone'], $body, $args);
        if (is_wp_error($r)) { $code = $r->get_error_code(); $stats['skipped'][$code] = (isset($stats['skipped'][$code]) ? $stats['skipped'][$code] : 0) + 1; }
        else { $stats['queued']++; }
    }
    update_option('sgb_import_last', $stats, false);
    return $stats;
}

/* تشغيل تلقائي كل 5 دقائق مع الكرون الأساسي */
add_action('sgb_cron', function () {
    $cfg = sgb_imp_settings();
    if ($cfg['mode'] !== 'auto' || get_transient('sgb_imp_lock')) { return; }
    set_transient('sgb_imp_lock', 1, 300);
    sgb_import_run();
}, 20);

/* تقرير + تحكم من التطبيقات */
add_action('rest_api_init', function () {
    register_rest_route('sgb/v1', '/report', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        global $wpdb;
        $t = $wpdb->prefix . 'sgb_outbox';
        $since = time() - 7 * 86400;
        $by_rule = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(rule_key,'manual') rule_key, status, COUNT(*) n FROM $t WHERE created>=%d GROUP BY rule_key, status", $since), ARRAY_A);
        $sent = $wpdb->get_col($wpdb->prepare("SELECT sent_at FROM $t WHERE sent_at>=%d AND status IN ('sent','delivered','read') LIMIT 20000", $since));
        $days = array();
        for ($i = 6; $i >= 0; $i--) { $days[wp_date('Y-m-d', time() - $i * 86400)] = 0; }
        foreach ($sent as $ts) { $d = wp_date('Y-m-d', (int) $ts); if (isset($days[$d])) { $days[$d]++; } }
        $cfg = sgb_imp_settings();
        return array('by_rule' => $by_rule, 'daily' => $days, 'import' => array('mode' => $cfg['mode'], 'require_consent' => (int) $cfg['require_consent'], 'per_contact_cap' => (int) $cfg['per_contact_cap'], 'last' => get_option('sgb_import_last', null)));
    }));
    register_rest_route('sgb/v1', '/import-run', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function () { return sgb_import_run(); }));
    register_rest_route('sgb/v1', '/import-settings', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = (array) $req->get_json_params();
        $cfg = sgb_imp_settings();
        if (isset($b['mode']) && in_array($b['mode'], array('off', 'auto'), true)) { $cfg['mode'] = $b['mode']; }
        if (isset($b['require_consent'])) { $cfg['require_consent'] = $b['require_consent'] ? 1 : 0; }
        if (isset($b['per_contact_cap'])) { $cfg['per_contact_cap'] = max(1, min(20, (int) $b['per_contact_cap'])); }
        update_option('sgb_import', $cfg, false);
        return array('ok' => true, 'import' => $cfg);
    }));
});

/* صفحة الإعدادات */
add_action('admin_menu', function () {
    add_submenu_page('sgb', 'استيراد المساعد', 'استيراد المساعد', 'manage_options', 'sgb-import', 'sgb_import_page');
});
function sgb_import_page() {
    if (!current_user_can('manage_options')) { return; }
    $cfg = sgb_imp_settings();
    $out = '';
    if (isset($_POST['sgb_imp_save']) && check_admin_referer('sgb_imp')) {
        $cfg['mode'] = (isset($_POST['mode']) && $_POST['mode'] === 'auto') ? 'auto' : 'off';
        $cfg['require_consent'] = empty($_POST['require_consent']) ? 0 : 1;
        $cfg['per_contact_cap'] = max(1, min(20, (int) $_POST['per_contact_cap']));
        $cfg['templates'] = sanitize_textarea_field(wp_unslash($_POST['templates']));
        update_option('sgb_import', $cfg, false);
        $out = 'تم الحفظ ✅';
    }
    if (isset($_POST['sgb_imp_run']) && check_admin_referer('sgb_imp')) {
        $r = sgb_import_run();
        $out = 'تم الاستيراد: ' . (int) $r['queued'] . ' رسالة أُضيفت من ' . (int) $r['seen'] . ' — المتجاهَل: ' . esc_html(wp_json_encode($r['skipped'], JSON_UNESCAPED_UNICODE)) . (isset($r['error']) ? ' — خطأ: ' . esc_html($r['error']) : '');
    }
    $last = get_option('sgb_import_last', null);
    echo '<div class="wrap" dir="rtl"><h1>استيراد رسائل المساعد إلى الواتساب</h1>';
    if ($out) { echo '<div class="notice notice-info"><p>' . $out . '</p></div>'; }
    echo '<p>يسحب الرسائل الجاهزة من إضافة المساعد القديمة ويضعها في طابور الإرسال. مغلق افتراضيًا. لا تُرسل الرسائل لمن لم تُسجَّل موافقته (consent) أو طلب الإيقاف.</p>';
    echo '<form method="post">'; wp_nonce_field('sgb_imp');
    echo '<table class="form-table"><tr><th>الوضع</th><td><select name="mode"><option value="off"' . selected($cfg['mode'], 'off', false) . '>متوقف</option><option value="auto"' . selected($cfg['mode'], 'auto', false) . '>تلقائي كل 5 دقائق</option></select></td></tr>';
    echo '<tr><th>اشتراط الموافقة</th><td><label><input type="checkbox" name="require_consent" value="1"' . checked($cfg['require_consent'], 1, false) . '> لا تُرسل إلا لمن موافقته مسجّلة (موصى به)</label></td></tr>';
    echo '<tr><th>أقصى رسائل لكل رقم يوميًا</th><td><input type="number" min="1" max="20" name="per_contact_cap" value="' . (int) $cfg['per_contact_cap'] . '"></td></tr>';
    echo '<tr><th>قوالب Meta حسب القاعدة</th><td><textarea name="templates" rows="6" class="large-text code" placeholder="renew_7=اسم_القالب_المعتمد&#10;shoot_24=اسم_قالب_آخر">' . esc_textarea($cfg['templates']) . '</textarea><p class="description">سطر لكل قاعدة: rule_key=template_name. تُمرَّر متغيرات الرسالة بالترتيب كمعاملات للقالب.</p></td></tr></table>';
    echo '<p><button class="button button-primary" name="sgb_imp_save" value="1">حفظ</button> <button class="button" name="sgb_imp_run" value="1">تشغيل الاستيراد الآن</button></p></form>';
    if ($last) { echo '<h2>آخر تشغيل</h2><pre dir="ltr">' . esc_html(wp_json_encode($last, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>'; }
    echo '</div>';
}
