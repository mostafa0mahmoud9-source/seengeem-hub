<?php
/**
 * الإصدار 2.5: متابعة المدرسين — دخولهم للمنصة، آخر درس، ردودهم على أسئلة الطلاب، تقييم الواجبات،
 * والدروس المعلّقة كمسودات. يرسل تنبيهات واتساب تلقائية للمدرس، ولمتابعيه ومدير المنصة.
 * مغلق افتراضيًا (enabled=0) ولا يرسل شيئًا قبل أن تراجع «المعاينة» وتفعّله.
 */
if (!defined('ABSPATH')) { exit; }

function sgb_tm_defaults() {
    return array('enabled' => 0, 'send_hour' => 9, 'inactive_days' => 3, 'monthly_target' => 3, 'cadence_from_day' => 8, 'cadence_msg' => 0, 'qa_hours' => 24, 'qa_escalate_hours' => 72,
        'draft_days' => 3, 'assign_hours' => 48, 'repeat_days' => 2, 'manager_phone' => '', 'follower_phones' => '', 'daily_summary' => 1, 'notify_followers' => 1);
}
function sgb_tm_cfg() { return array_replace(sgb_tm_defaults(), (array) get_option('sgb_teach', array())); }

function sgb_tm_texts() {
    return array(
        'tch_inactive' => 'أستاذ {name}، لاحظنا إنك ما دخلتش منصة سين جيم من {days} أيام. الطلاب في انتظارك، ادخل النهاردة وتابع كورساتك 🙏',
        'tch_cadence' => 'أستاذ {name}، متابعة الدروس الشهرية: {courses}. محتاجين درس جديد في أقرب وقت عشان الطلاب ما يتأخروش 🙏',
        'tch_qa' => 'أستاذ {name}، عندك {count} سؤال من الطلاب لسه من غير رد (أقدمهم من {hours} ساعة) في: {courses}. ادخل رد عليهم من المنصة 💬',
        'tch_drafts' => 'أستاذ {name}، عندك {count} درس لسه مسودة من أكتر من {days} أيام في: {courses}. انشرها عشان توصل للطلاب.',
        'tch_assign' => 'أستاذ {name}، عندك {count} تسليم واجب لسه ما اتقيّمش (أقدمهم من {hours} ساعة). راجعها من المنصة ✅',
        'mgr_followup' => "متابعة المدرسين — {date}\n{lines}",
        'mgr_daily' => "ملخص متابعة المدرسين اليومي — {date}\n{lines}",
    );
}
function sgb_tm_labels() {
    return array('tch_inactive' => 'مدرس: غياب عن المنصة', 'tch_cadence' => 'مدرس: تأخر دروس الشهر', 'tch_qa' => 'مدرس: أسئلة بلا رد',
        'tch_drafts' => 'مدرس: دروس مسودة', 'tch_assign' => 'مدرس: واجبات بلا تقييم', 'mgr_followup' => 'متابع: تنبيه المدرسين', 'mgr_daily' => 'مدير: ملخص يومي');
}
/** نص القاعدة بعد تطبيق تعديلات المستخدم (من شاشة الرسائل) وإيقاف النوع إن لزم. */
function sgb_tm_text($key, $vars) {
    $ro = sgb_rules_get();
    if (!empty($ro[$key]['off'])) { return ''; }
    $d = sgb_tm_texts();
    $t = (isset($ro[$key]['text']) && trim((string) $ro[$key]['text']) !== '') ? $ro[$key]['text'] : $d[$key];
    return sgb_render($t, $vars);
}

add_action('wp_login', function ($login, $user) { update_user_meta($user->ID, 'sgb_last_login', time()); }, 10, 2);

function sgb_tm_phone_of($uid) {
    $o = (array) get_option('sgb_tm_phones', array());
    if (!empty($o[$uid])) { return sgb_phone($o[$uid]); }
    foreach (array('billing_phone', 'phone_number', 'phone', 'mobile', 'sg_phone', 'whatsapp') as $k) {
        $p = sgb_phone((string) get_user_meta($uid, $k, true));
        if ($p) { return $p; }
    }
    return '';
}
function sgb_tm_last_login($uid) {
    $t = (int) get_user_meta($uid, 'sgb_last_login', true);
    $t = max($t, (int) get_user_meta($uid, 'wc_last_active', true));
    $s = get_user_meta($uid, 'session_tokens', true);
    if (is_array($s)) { foreach ($s as $x) { if (!empty($x['login'])) { $t = max($t, (int) $x['login']); } } }
    return $t;
}
function sgb_tm_course_title($post_id) {
    for ($i = 0; $i < 4 && $post_id; $i++) {
        $p = get_post($post_id);
        if (!$p) { return ''; }
        if ($p->post_type === 'courses') { return $p->post_title; }
        $post_id = $p->post_parent;
    }
    return '';
}
function sgb_tm_join($arr, $n = 3) {
    $arr = array_values(array_unique(array_filter($arr)));
    $more = count($arr) - $n;
    $s = implode('، ', array_slice($arr, 0, $n));
    return $s === '' ? 'كورساتك' : ($more > 0 ? $s . ' و' . $more . ' غيرها' : $s);
}

/** جمع حالة كل مدرس. */
function sgb_tm_collect($cfg = null) {
    global $wpdb;
    $cfg = $cfg ? $cfg : sgb_tm_cfg();
    $now = time();
    $users = get_users(array('role__in' => array('sg_teacher', 'tutor_instructor'), 'fields' => 'all'));
    $T = array();
    foreach ($users as $u) {
        $roles = (array) $u->roles;
        if (in_array('administrator', $roles, true) && !in_array('sg_teacher', $roles, true)) { continue; }
        $T[$u->ID] = array('uid' => $u->ID, 'name' => $u->display_name, 'phone' => sgb_tm_phone_of($u->ID), 'last_login' => sgb_tm_last_login($u->ID), 'last_lesson' => 0,
            'courses' => 0, 'late_courses' => array(), 'done_month' => 0, 'drafts' => 0, 'draft_titles' => array(), 'qa' => 0, 'qa_oldest' => 0, 'qa_titles' => array(), 'assign' => 0, 'assign_oldest' => 0);
    }
    if (!$T) { return array(); }
    $P = $wpdb->posts; $C = $wpdb->comments;
    foreach ($wpdb->get_results("SELECT post_author, COUNT(*) n FROM $P WHERE post_type='courses' AND post_status='publish' GROUP BY post_author", ARRAY_A) as $r) { if (isset($T[$r['post_author']])) { $T[$r['post_author']]['courses'] = (int) $r['n']; } }
    foreach ($wpdb->get_results("SELECT post_author, MAX(post_date_gmt) d FROM $P WHERE post_type='lesson' AND post_status='publish' GROUP BY post_author", ARRAY_A) as $r) { if (isset($T[$r['post_author']]) && $r['d']) { $T[$r['post_author']]['last_lesson'] = (int) strtotime($r['d'] . ' UTC'); } }
    // إيقاع الدروس الشهري لكل كورس (نفس منطق «متابعة دروس المدرسين» في المنصة): المطلوب target درس/شهر، والمفروض لحد النهارده بالتناسب
    $dim = (int) wp_date('t'); $day = (int) wp_date('j');
    $month_start = wp_date('Y-m-01 00:00:00');
    $expected = $cfg['monthly_target'] * $day / max(1, $dim);
    $cnt = array();
    foreach ($wpdb->get_results($wpdb->prepare("SELECT c.ID cid, COUNT(l.ID) n FROM $P l JOIN $P t ON t.ID=l.post_parent JOIN $P c ON c.ID=t.post_parent
        WHERE l.post_type='lesson' AND l.post_status='publish' AND l.post_date >= %s AND c.post_type='courses' GROUP BY c.ID", $month_start), ARRAY_A) as $r) { $cnt[(int) $r['cid']] = (int) $r['n']; }
    foreach ($wpdb->get_results("SELECT ID, post_author, post_title FROM $P WHERE post_type='courses' AND post_status='publish'", ARRAY_A) as $r) {
        $a = (int) $r['post_author'];
        if (!isset($T[$a])) { continue; }
        $n = isset($cnt[(int) $r['ID']]) ? $cnt[(int) $r['ID']] : 0;
        $T[$a]['done_month'] += $n;
        if ($day >= (int) $cfg['cadence_from_day'] && $n < $expected) { $T[$a]['late_courses'][] = array('title' => $r['post_title'], 'done' => $n, 'need' => (int) ceil($expected)); }
    }
    foreach ($wpdb->get_results("SELECT ID, post_author, post_parent FROM $P WHERE post_type='lesson' AND post_status IN ('draft','pending') AND post_modified_gmt < '" . gmdate('Y-m-d H:i:s', $now - $cfg['draft_days'] * 86400) . "'", ARRAY_A) as $r) {
        if (!isset($T[$r['post_author']])) { continue; }
        $T[$r['post_author']]['drafts']++; $T[$r['post_author']]['draft_titles'][] = sgb_tm_course_title($r['ID']);
    }
    // أسئلة الطلاب (Q&A) بدون أي رد
    $qa = $wpdb->get_results("SELECT c.comment_ID, c.comment_post_ID, c.user_id, c.comment_date_gmt d, p.post_author pa, p.post_title t FROM $C c JOIN $P p ON p.ID=c.comment_post_ID
        WHERE c.comment_type='tutor_q_and_a' AND c.comment_parent=0 AND NOT EXISTS (SELECT 1 FROM $C r WHERE r.comment_parent=c.comment_ID)", ARRAY_A);
    foreach ((array) $qa as $r) {
        $uid = (int) $r['pa'];
        if (!isset($T[$uid]) || (int) $r['user_id'] === $uid) { continue; }
        $age = $now - (int) strtotime($r['d'] . ' UTC');
        if ($age < $cfg['qa_hours'] * 3600) { continue; }
        $T[$uid]['qa']++; $T[$uid]['qa_titles'][] = $r['t'];
        $T[$uid]['qa_oldest'] = max($T[$uid]['qa_oldest'], $age);
    }
    // تسليمات الواجبات التي لم تُقيَّم
    $as = $wpdb->get_results("SELECT c.comment_ID, c.comment_date_gmt d, p.post_author pa FROM $C c JOIN $P p ON p.ID=c.comment_post_ID
        WHERE c.comment_type='tutor_assignment' AND NOT EXISTS (SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key='evaluate_time')", ARRAY_A);
    foreach ((array) $as as $r) {
        $uid = (int) $r['pa'];
        if (!isset($T[$uid])) { continue; }
        $age = $now - (int) strtotime($r['d'] . ' UTC');
        if ($age < $cfg['assign_hours'] * 3600) { continue; }
        $T[$uid]['assign']++; $T[$uid]['assign_oldest'] = max($T[$uid]['assign_oldest'], $age);
    }
    foreach ($T as $uid => $t) {
        $T[$uid]['login_days'] = $t['last_login'] ? (int) floor(($now - $t['last_login']) / 86400) : null;
        $T[$uid]['lesson_days'] = $t['last_lesson'] ? (int) floor(($now - $t['last_lesson']) / 86400) : null;
        $flags = array();
        if ($T[$uid]['login_days'] !== null && $T[$uid]['login_days'] >= $cfg['inactive_days']) { $flags[] = 'inactive'; }
        if ($t['late_courses']) { $flags[] = 'cadence'; }
        if ($t['qa'] > 0) { $flags[] = 'qa'; }
        if ($t['drafts'] > 0) { $flags[] = 'drafts'; }
        if ($t['assign'] > 0) { $flags[] = 'assign'; }
        $T[$uid]['flags'] = $flags;
    }
    return $T;
}

/** يبني الرسائل المطلوبة اليوم (بدون إرسال). */
function sgb_tm_plan($cfg = null) {
    $cfg = $cfg ? $cfg : sgb_tm_cfg();
    $T = sgb_tm_collect($cfg);
    $log = (array) get_option('sgb_tm_log', array());
    $now = time(); $date = wp_date('Y-m-d');
    $out = array(); $lines = array(); $esc = array();
    $X = (array) get_option('sgb_tm_excl', array());
    foreach ($T as $uid => $t) { $T[$uid]['excl'] = isset($X[$uid]) ? $X[$uid] : ''; }
    foreach ($T as $uid => $t) {
        if ($t['excl'] === 'full') { continue; }
        $v = array('name' => $t['name']);
        $items = array();
        if (in_array('inactive', $t['flags'], true)) { $items['tch_inactive'] = $v + array('days' => $t['login_days']); }
        if ($cfg['cadence_msg'] && in_array('cadence', $t['flags'], true)) { $items['tch_cadence'] = $v + array('courses' => sgb_tm_join(array_map(function ($c) { return $c['title'] . ' (' . $c['done'] . '/' . $c['need'] . ')'; }, $t['late_courses']))); }
        if ($t['qa'] > 0) { $items['tch_qa'] = $v + array('count' => $t['qa'], 'hours' => (int) floor($t['qa_oldest'] / 3600), 'courses' => sgb_tm_join($t['qa_titles'])); }
        if ($t['drafts'] > 0) { $items['tch_drafts'] = $v + array('count' => $t['drafts'], 'days' => $cfg['draft_days'], 'courses' => sgb_tm_join($t['draft_titles'])); }
        if ($t['assign'] > 0) { $items['tch_assign'] = $v + array('count' => $t['assign'], 'hours' => (int) floor($t['assign_oldest'] / 3600)); }
        if ($t['excl']) { $items = array(); }
        foreach ($items as $rule => $vars) {
            $body = sgb_tm_text($rule, $vars);
            if ($body === '') { continue; }
            $k = $uid . ':' . $rule;
            $skip = (!empty($log[$k]) && ($now - (int) $log[$k]) < $cfg['repeat_days'] * 86400) ? 'تم التنبيه مؤخرًا' : (!$t['phone'] ? 'لا يوجد رقم' : '');
            $out[] = array('to' => $t['phone'], 'role' => 'teacher', 'who' => $t['name'], 'rule' => $rule, 'uid' => $uid, 'body' => $body, 'skip' => $skip, 'key' => $k);
        }
        $bits = array();
        if ($t['login_days'] !== null && in_array('inactive', $t['flags'], true)) { $bits[] = 'غائب ' . $t['login_days'] . ' يوم'; }
        if ($t['late_courses']) { $bits[] = count($t['late_courses']) . ' كورس متأخر في الدروس'; }
        if ($t['qa'] > 0) { $bits[] = $t['qa'] . ' سؤال بلا رد'; }
        if ($t['drafts'] > 0) { $bits[] = $t['drafts'] . ' مسودة'; }
        if ($t['assign'] > 0) { $bits[] = $t['assign'] . ' واجب بلا تقييم'; }
        if ($bits) { $lines[] = '• أ. ' . $t['name'] . ': ' . implode(' | ', $bits); }
        if ($bits && (($t['login_days'] !== null && $t['login_days'] >= 2 * $cfg['inactive_days']) || $t['qa_oldest'] >= $cfg['qa_escalate_hours'] * 3600 || $t['assign_oldest'] >= 2 * $cfg['assign_hours'] * 3600)) { $esc[] = end($lines); }
    }
    $T2 = array_filter($T, function ($x) { return $x['excl'] !== 'full'; });
    $total = count($T2);
    $ok = $total - count(array_filter($T2, function ($x) { return !empty($x['flags']); }));
    $gv = array('date' => $date);
    // متابعو المدرسين: يصلهم المتأخرون الحرجون فقط
    if ($cfg['notify_followers'] && $esc) {
        $body = sgb_tm_text('mgr_followup', $gv + array('lines' => implode("\n", $esc)));
        if ($body !== '') { foreach (array_filter(array_map('sgb_phone', preg_split('/[\s,،;]+/', (string) $cfg['follower_phones']))) as $ph) { $out[] = array('to' => $ph, 'role' => 'follower', 'who' => 'متابع', 'rule' => 'mgr_followup', 'uid' => 0, 'body' => $body, 'skip' => '', 'key' => 'f:' . $ph); } }
    }
    // مدير المنصة: ملخص يومي دائمًا
    $mp = sgb_phone($cfg['manager_phone']);
    if (!$mp) { $d = (array) get_option('sgb_digest', array()); $mp = isset($d['phone']) ? sgb_phone($d['phone']) : ''; }
    if ($cfg['daily_summary'] && $mp) {
        $head = 'المدرسون المنتظمون: ' . $ok . ' من ' . $total;
        $body = sgb_tm_text('mgr_daily', $gv + array('lines' => $head . ($lines ? "\n" . implode("\n", $lines) : "\nكل المدرسين منتظمين 👏")));
        if ($body !== '') { $out[] = array('to' => $mp, 'role' => 'manager', 'who' => 'المدير', 'rule' => 'mgr_daily', 'uid' => 0, 'body' => $body, 'skip' => '', 'key' => 'm'); }
    }
    return array('teachers' => array_values($T), 'messages' => $out);
}

function sgb_tm_run($only_uid = 0) {
    $cfg = sgb_tm_cfg();
    $plan = sgb_tm_plan($cfg);
    $log = (array) get_option('sgb_tm_log', array());
    $n = 0; $date = wp_date('Y-m-d');
    foreach ($plan['messages'] as $m) {
        if ($m['skip'] !== '') { continue; }
        if ($only_uid && (int) $m['uid'] !== (int) $only_uid) { continue; }
        $r = sgb_enqueue('staff', $m['to'], $m['body'], array('rule' => $m['rule'], 'dedupe' => 'tm:' . $m['key'] . ':' . $date, 'dedupe_hours' => 20, 'priority' => $m['role'] === 'teacher' ? 0 : 1));
        if (!is_wp_error($r)) { $n++; if ($m['role'] === 'teacher') { $log[$m['key']] = time(); } }
    }
    update_option('sgb_tm_log', $log, false);
    return $n;
}

add_action('sgb_cron', function () {
    $cfg = sgb_tm_cfg();
    if (empty($cfg['enabled'])) { return; }
    $today = wp_date('Y-m-d');
    if (get_option('sgb_teach_last') === $today || (int) wp_date('G') < (int) $cfg['send_hour'] || sgb_in_quiet_hours()) { return; }
    update_option('sgb_teach_last', $today, false);
    sgb_tm_run();
}, 30);

add_action('rest_api_init', function () {
    $ns = 'sgb/v1';
    register_rest_route($ns, '/teach', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        $cfg = sgb_tm_cfg();
        $plan = sgb_tm_plan($cfg);
        foreach (sgb_tm_texts() as $k => $txt) { sgb_rules_learn($k, $txt, array('name' => 1, 'days' => 1, 'count' => 1, 'hours' => 1, 'courses' => 1, 'date' => 1, 'lines' => 1)); }
        global $wpdb;
        $types = $wpdb->get_results("SELECT comment_type t, COUNT(*) n FROM {$wpdb->comments} GROUP BY comment_type ORDER BY n DESC LIMIT 8", ARRAY_A);
        $msgs = array_map(function ($m) { unset($m['key']); return $m; }, $plan['messages']);
        return array('cfg' => $cfg, 'teachers' => $plan['teachers'], 'plan' => $msgs, 'last_run' => get_option('sgb_teach_last', ''), 'comment_types' => $types);
    }));
    register_rest_route($ns, '/teach-settings', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = (array) $req->get_json_params();
        $c = sgb_tm_cfg();
        foreach (array('enabled', 'daily_summary', 'notify_followers', 'cadence_msg') as $k) { if (isset($b[$k])) { $c[$k] = $b[$k] ? 1 : 0; } }
        foreach (array('send_hour' => array(0, 23), 'inactive_days' => array(1, 60), 'monthly_target' => array(1, 30), 'cadence_from_day' => array(1, 28), 'qa_hours' => array(1, 240), 'qa_escalate_hours' => array(1, 720), 'draft_days' => array(1, 60), 'assign_hours' => array(1, 480), 'repeat_days' => array(1, 30)) as $k => $r) {
            if (isset($b[$k])) { $c[$k] = max($r[0], min($r[1], (int) $b[$k])); }
        }
        if (isset($b['manager_phone'])) { $c['manager_phone'] = sgb_phone($b['manager_phone']); }
        if (isset($b['follower_phones'])) { $c['follower_phones'] = implode(', ', array_filter(array_map('sgb_phone', preg_split('/[\s,،;]+/', (string) $b['follower_phones'])))); }
        update_option('sgb_teach', $c, false);
        return array('ok' => true);
    }));
    register_rest_route($ns, '/teach-phone', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $uid = (int) $req->get_param('uid');
        $o = (array) get_option('sgb_tm_phones', array());
        $p = sgb_phone((string) $req->get_param('phone'));
        if ($p) { $o[$uid] = $p; } else { unset($o[$uid]); }
        update_option('sgb_tm_phones', $o, false);
        return array('ok' => true, 'phone' => $p);
    }));
    register_rest_route($ns, '/teach-excl', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $uid = (int) $req->get_param('uid');
        $mode = (string) $req->get_param('mode');
        $o = (array) get_option('sgb_tm_excl', array());
        if ($mode === 'mute' || $mode === 'full') { $o[$uid] = $mode; } else { unset($o[$uid]); }
        update_option('sgb_tm_excl', $o, false);
        return array('ok' => true, 'mode' => isset($o[$uid]) ? $o[$uid] : '');
    }));
    register_rest_route($ns, '/teach-run', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $uid = (int) $req->get_param('uid');
        return array('ok' => true, 'queued' => sgb_tm_run($uid));
    }));
});
