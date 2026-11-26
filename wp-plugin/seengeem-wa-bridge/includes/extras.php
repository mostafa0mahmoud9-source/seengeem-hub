<?php
/**
 * إضافات الإصدار 2.3: حماية الرقم (تسخين + إيقاف تلقائي)، حالة بوابة QR، ملخص الإدارة الأسبوعي،
 * تقرير تأخير المدرسين، ونسخ احتياطي يومي.
 */
if (!defined('ABSPATH')) { exit; }

/* ───────── حماية الرقم ───────── */

/** مراحل الحد اليومي لكل خط حسب عمره (أول إرسال ناجح عليه). */
function sgb_qr_first($ch) {
    $f = get_option('sgb_qr_first', array());
    if (!is_array($f)) { $f = array('students' => (int) $f, 'staff' => (int) $f); } // ترحيل من النسخة السابقة
    return isset($f[$ch]) ? (int) $f[$ch] : 0;
}
function sgb_qr_cap($ch = 'students') {
    $s = sgb_settings();
    $max = (int) $s['daily_cap'];
    if (!get_option('sgb_warmup', 1)) { return $max; }
    $first = sgb_qr_first($ch);
    $days = $first ? (time() - $first) / 86400 : 0;
    $cap = $days < 7 ? 100 : ($days < 14 ? 200 : ($days < 30 ? 400 : $max));
    return min($max, $cap);
}

function sgb_paused_info() {
    $p = get_option('sgb_pause', array());
    if (is_array($p) && !empty($p['until']) && (int) $p['until'] > time()) { return $p; }
    return null;
}

/** سحب الرسائل بواسطة بوابة QR (برنامج الكمبيوتر). */
function sgb_pull($req) {
    global $wpdb;
    $t = $wpdb->prefix . 'sgb_outbox';
    $ch = $req->get_param('channel') === 'staff' ? 'staff' : 'students';
    if (sgb_paused_info()) { return array(); }
    // رسائل علقت في حالة «جاري الإرسال» أكثر من 10 دقائق تعود للطابور
    $wpdb->query($wpdb->prepare("UPDATE $t SET status='qr_pending' WHERE status='sending' AND channel=%s AND next_try<%d", $ch, time() - 600));
    $midnight = (new DateTime('today', wp_timezone()))->getTimestamp();
    $done = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE channel=%s AND ((sent_at>=%d AND status IN ('sent','delivered','read')) OR status='sending')", $ch, $midnight));
    $left = max(0, sgb_qr_cap($ch) - $done);
    $minp = sgb_in_quiet_hours() ? 1 : 0;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,to_phone,body,priority FROM $t WHERE status='qr_pending' AND channel=%s AND priority>=%d AND (priority>=2 OR %d>0) ORDER BY priority DESC, id ASC LIMIT 12", $ch, $minp, $left));
    $out = array(); $n = 0;
    foreach ($rows as $r) {
        if ((int) $r->priority < 2) { if ($n >= $left) { continue; } $n++; }
        $wpdb->update($t, array('status' => 'sending', 'next_try' => time()), array('id' => $r->id));
        $out[] = array('id' => $r->id, 'to_phone' => $r->to_phone, 'body' => $r->body);
    }
    return $out;
}

/** بعد كل تأكيد من البوابة: تسجيل أول إرسال، وإيقاف تلقائي لو ارتفع الفشل. */
function sgb_after_ack($id, $ok, $error) {
    global $wpdb;
    $t = $wpdb->prefix . 'sgb_outbox';
    $ch = $wpdb->get_var($wpdb->prepare("SELECT channel FROM $t WHERE id=%d", $id));
    if (!$ch) { return; }
    if ($ok) {
        if (!sgb_qr_first($ch)) {
            $f = get_option('sgb_qr_first', array());
            $f = is_array($f) ? $f : array('students' => (int) $f, 'staff' => (int) $f);
            $f[$ch] = time();
            update_option('sgb_qr_first', $f, false);
        }
        return;
    }
    if (strpos($error, 'غير مسجل') !== false) { return; } // رقم غير موجود على واتساب ليس مؤشر حظر
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT status FROM $t WHERE channel=%s AND status IN ('sent','delivered','read','failed') AND (error IS NULL OR error NOT LIKE %s) ORDER BY id DESC LIMIT 20", $ch, '%غير مسجل%'));
    if (count($rows) >= 10) {
        $bad = count(array_filter($rows, function ($x) { return $x === 'failed'; }));
        if ($bad / count($rows) >= 0.6) {
            update_option('sgb_pause', array('until' => time() + 7200, 'channel' => $ch, 'reason' => 'ارتفعت نسبة فشل الإرسال (' . $bad . ' من آخر ' . count($rows) . ')، تم الإيقاف ساعتين لحماية الرقم'), false);
        }
    }
}

/* ───────── مسارات REST ───────── */
add_action('rest_api_init', function () {
    $ns = 'sgb/v1';
    register_rest_route($ns, '/gw-status', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = (array) $req->get_json_params();
        $ch = (isset($b['channel']) && $b['channel'] === 'staff') ? 'staff' : 'students';
        $gw = (array) get_option('sgb_gw', array());
        $gw[$ch] = array('state' => sanitize_key(isset($b['state']) ? $b['state'] : ''), 'phone' => sanitize_text_field(isset($b['phone']) ? $b['phone'] : ''), 'ts' => time());
        update_option('sgb_gw', $gw, false);
        // عمر الخط كما يحدده البرنامج (يضبط مرحلة الحد اليومي)
        if (!empty($b['since']) && (int) $b['since'] > time() - 3650 * 86400 && (int) $b['since'] <= time()) {
            $f = get_option('sgb_qr_first', array());
            $f = is_array($f) ? $f : array('students' => (int) $f, 'staff' => (int) $f);
            if (!isset($f[$ch]) || (int) $f[$ch] !== (int) $b['since']) { $f[$ch] = (int) $b['since']; update_option('sgb_qr_first', $f, false); }
        }
        return array('ok' => true);
    }));
    register_rest_route($ns, '/resume', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        delete_option('sgb_pause');
        return array('ok' => true);
    }));
    register_rest_route($ns, '/warmup', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = (array) $req->get_json_params();
        update_option('sgb_warmup', empty($b['on']) ? 0 : 1, false);
        return array('ok' => true, 'cap' => array('students' => sgb_qr_cap('students'), 'staff' => sgb_qr_cap('staff')));
    }));
    register_rest_route($ns, '/digest', array('methods' => 'GET,POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $d = array_replace(array('enabled' => 0, 'phone' => ''), (array) get_option('sgb_digest', array()));
        if ($req->get_method() === 'POST') {
            $b = (array) $req->get_json_params();
            if (isset($b['enabled'])) { $d['enabled'] = $b['enabled'] ? 1 : 0; }
            if (isset($b['phone'])) { $d['phone'] = sgb_phone($b['phone']); }
            update_option('sgb_digest', $d, false);
        }
        return $d;
    }));
    register_rest_route($ns, '/digest-now', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        $r = sgb_send_digest(true);
        return is_wp_error($r) ? new WP_Error($r->get_error_code(), $r->get_error_message(), array('status' => 400)) : array('ok' => true);
    }));
    register_rest_route($ns, '/backup-now', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        return array('ok' => true, 'file' => basename(sgb_backup()));
    }));
});

/* ───────── ملخص الإدارة الأسبوعي ───────── */
function sgb_rule_label($k) {
    $m = array('success' => 'تهنئة النجاح', 'renew_7' => 'تجديد قبل 7 أيام', 'renew_2' => 'تجديد قبل يومين', 'expired' => 'انتهاء الاشتراك', 'winback' => 'عرض العودة',
        'lead_welcome' => 'ترحيب بالمسجّل', 'lead_help' => 'مساعدة الاشتراك', 'lead_offer' => 'عرض أول اشتراك', 'inactive' => 'غياب', 'unwatched' => 'درس لم يُبدأ',
        'incomplete' => 'درس لم يكتمل', 'quiz_due' => 'امتحان لم يُحل', 'low_score' => 'دعم بعد نتيجة ضعيفة', 'parent_followup' => 'متابعة ولي الأمر', 'weekly' => 'تقرير أسبوعي',
        'shoot_24' => 'تذكير تصوير 24س', 'shoot_2' => 'تذكير تصوير ساعتين', 'teacher_late' => 'تأخير تصوير', 'motivation' => 'تشجيع', 'manual' => 'يدوي');
    return isset($m[$k]) ? $m[$k] : $k;
}
function sgb_send_digest($force = false) {
    global $wpdb;
    $d = array_replace(array('enabled' => 0, 'phone' => ''), (array) get_option('sgb_digest', array()));
    if (!$force && !$d['enabled']) { return false; }
    if (!$d['phone']) { return new WP_Error('no_phone', 'ضع رقم الإدارة أولًا'); }
    $t = $wpdb->prefix . 'sgb_outbox';
    $since = time() - 7 * 86400;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(rule_key,'manual') rk, status, COUNT(*) n FROM $t WHERE created>=%d GROUP BY rk, status", $since), ARRAY_A);
    $sent = 0; $ok = 0; $bad = 0; $by = array(); $late = 0;
    foreach ($rows as $r) {
        $n = (int) $r['n'];
        if (in_array($r['status'], array('sent', 'delivered', 'read'), true)) { $sent += $n; $by[$r['rk']] = (isset($by[$r['rk']]) ? $by[$r['rk']] : 0) + $n; }
        if (in_array($r['status'], array('delivered', 'read'), true)) { $ok += $n; }
        if ($r['status'] === 'failed') { $bad += $n; }
        if ($r['rk'] === 'teacher_late') { $late += $n; }
    }
    arsort($by);
    $top = array();
    foreach (array_slice($by, 0, 4, true) as $k => $n) { $top[] = '• ' . sgb_rule_label($k) . ': ' . $n; }
    $txt = "📊 ملخص الأسبوع — مساعد سين جيم\n\n• رسائل أُرسلت: $sent\n• وصلت للمستلمين: $ok\n• فشلت: $bad\n• تنبيهات تأخير التصوير: $late\n"
        . ($top ? "\nأكثر الرسائل:\n" . implode("\n", $top) . "\n" : '') . "\nيوم موفّق 💜";
    return sgb_enqueue('staff', $d['phone'], $txt, array('rule' => 'manager_digest', 'priority' => 1, 'dedupe' => 'digest:' . wp_date('o-W'), 'dedupe_hours' => $force ? 1 : 150));
}
add_action('sgb_cron', function () {
    if ((int) wp_date('w') === 5 && (int) wp_date('G') >= 10) { sgb_send_digest(false); }
}, 30);

/* ───────── تقرير تأخير المدرسين (الشهر الحالي) ───────── */
add_filter('sgb_report', function ($r) {
    global $wpdb;
    $t = $wpdb->prefix . 'sgb_outbox';
    $month = (new DateTime('first day of this month 00:00:00', wp_timezone()))->getTimestamp();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT to_phone, COUNT(*) n FROM $t WHERE rule_key='teacher_late' AND created>=%d AND status<>'failed' GROUP BY to_phone ORDER BY n DESC LIMIT 10", $month), ARRAY_A);
    $names = get_transient('sgb_names');
    if ($names === false) {
        $names = array();
        $st = function_exists('sgb_import_fetch_state') ? sgb_import_fetch_state() : null;
        if (is_array($st) && !empty($st['contacts'])) {
            foreach ($st['contacts'] as $c) {
                $nm = '';
                foreach (array('name', 'display_name', 'full_name') as $f) { if (!empty($c[$f])) { $nm = (string) $c[$f]; break; } }
                if (!empty($c['phone'])) { $names[sgb_phone($c['phone'])] = $nm; }
            }
        }
        set_transient('sgb_names', $names, 600);
    }
    $late = array();
    foreach ($rows as $x) { $late[] = array('name' => isset($names[$x['to_phone']]) ? $names[$x['to_phone']] : '', 'phone' => sgb_mask($x['to_phone']), 'n' => (int) $x['n']); }
    $r['late'] = $late;
    $r['digest'] = array_replace(array('enabled' => 0, 'phone' => ''), (array) get_option('sgb_digest', array()));
    if ($r['digest']['phone']) { $r['digest']['phone'] = sgb_mask($r['digest']['phone']); }
    $r['warmup'] = (int) get_option('sgb_warmup', 1);
    $r['last_backup'] = (int) get_option('sgb_last_backup', 0);
    return $r;
});

/* ───────── نسخة احتياطية يومية ───────── */
function sgb_backup() {
    global $wpdb;
    $u = wp_upload_dir();
    $dir = $u['basedir'] . '/sgb-backup';
    wp_mkdir_p($dir);
    @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    @file_put_contents($dir . '/index.php', "<?php\n// silence\n");
    $s = sgb_settings();
    unset($s['api_key'], $s['app_secret'], $s['verify_token']);
    foreach ($s['channels'] as $k => $c) { $s['channels'][$k]['token'] = $c['token'] ? '[محفوظ في الموقع]' : ''; }
    $t = $wpdb->prefix . 'sgb_outbox';
    $data = array(
        'time' => time(), 'version' => SGB_VER, 'settings' => $s,
        'import' => get_option('sgb_import', array()), 'optout' => get_option('sgb_optout', array()), 'digest' => get_option('sgb_digest', array()),
        'outbox_30d' => $wpdb->get_results($wpdb->prepare("SELECT id,channel,to_phone,rule_key,status,error,created,sent_at,body FROM $t WHERE created>=%d ORDER BY id DESC LIMIT 20000", time() - 30 * 86400), ARRAY_A),
    );
    $name = $dir . '/backup-' . wp_date('Y-m-d') . '-' . substr(md5(wp_salt() . $dir), 0, 8) . '.json';
    file_put_contents($name, wp_json_encode($data, JSON_UNESCAPED_UNICODE));
    $files = glob($dir . '/backup-*.json');
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - 14)) as $old) { @unlink($old); }
    update_option('sgb_last_backup', time(), false);
    return $name;
}
add_action('sgb_cron', function () {
    if ((int) get_option('sgb_last_backup', 0) < time() - 86400) { sgb_backup(); }
}, 40);
