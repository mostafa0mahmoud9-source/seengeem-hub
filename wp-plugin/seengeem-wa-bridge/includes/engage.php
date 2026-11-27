<?php
/**
 * الإصدار 2.8: الرسائل الجماعية والتلقائية —
 *   • كتابة رسالة يدوية وإرسالها لفئات (طلاب / أولياء أمور / مدرسين / موظفين) مع فلتر الصف وجدولة وتجربة.
 *   • تهنئة المناسبات (قومية ودينية) + أعياد الميلاد + رسالة عطلة الأسبوع + دعم نفسي حسب الانتظام + نصائح أسرية يومية.
 *   • مكتبة ضخمة متجددة: لا يصل نفس النص لنفس الرقم مرتين (يُسجَّل في الطابور).
 *   • تقرير المدير اليومي 10م على واتساب.
 * كل الرسائل التلقائية مغلقة افتراضيًا ما عدا تقرير المدير. تُرسل على دفعات صغيرة وبأولوية أقل من رسائل العمل.
 */
if (!defined('ABSPATH')) { exit; }

/* المنطقة الزمنية: القاهرة دائمًا (بغض النظر عن توقيت الموقع) */
function sgb_eng_tz() { return new DateTimeZone('Africa/Cairo'); }
function sgb_eng_d($f, $ts = null) { return wp_date($f, $ts, sgb_eng_tz()); }

/* ───────── الإعدادات ───────── */
function sgb_eng_defaults() {
    return array(
        'report_on' => 1, 'report_phone' => '201099826061', 'report_hour' => 22,
        'occ_on' => 0, 'occ_hour' => 9, 'occ_aud' => array('students', 'parents', 'teachers', 'employees'),
        'weekend_on' => 0, 'weekend_hour' => 11, 'weekend_days' => array(5),
        'support_on' => 0, 'support_hour' => 16, 'support_days' => array(0, 2, 4),
        'tips_on' => 0, 'tips_hour' => 18, 'tips_days' => array(0, 1, 2, 3, 4, 5, 6),
        'bday_on' => 0, 'bday_hour' => 10,
        'teach_on' => 1, 'teach_hour' => 12, 'teach_days' => array(0, 3),
    );
}
function sgb_eng_cfg() { return array_replace(sgb_eng_defaults(), (array) get_option('sgb_eng', array())); }
function sgb_eng_save_cfg($b) {
    $c = sgb_eng_cfg();
    foreach (array('report_on', 'occ_on', 'weekend_on', 'support_on', 'tips_on', 'bday_on', 'teach_on') as $k) { if (isset($b[$k])) { $c[$k] = $b[$k] ? 1 : 0; } }
    foreach (array('report_hour' => array(0, 23), 'occ_hour' => array(6, 20), 'weekend_hour' => array(6, 20), 'support_hour' => array(6, 20), 'tips_hour' => array(6, 20), 'bday_hour' => array(6, 20), 'teach_hour' => array(6, 20)) as $k => $r) {
        if (isset($b[$k])) { $c[$k] = max($r[0], min($r[1], (int) $b[$k])); }
    }
    if (isset($b['report_phone'])) { $p = sgb_phone($b['report_phone']); if ($p) { $c['report_phone'] = $p; } }
    foreach (array('weekend_days', 'support_days', 'tips_days', 'teach_days') as $k) {
        if (isset($b[$k])) { $c[$k] = array_values(array_unique(array_filter(array_map('intval', (array) $b[$k]), function ($d) { return $d >= 0 && $d <= 6; }))); }
    }
    if (isset($b['occ_aud'])) { $c['occ_aud'] = array_values(array_intersect((array) $b['occ_aud'], array('students', 'parents', 'teachers', 'employees'))); }
    update_option('sgb_eng', $c, false);
    return $c;
}
function sgb_eng_labels() {
    return array('eng_manual' => 'جماعية: رسالة يدوية', 'eng_occ' => 'تلقائي: تهنئة مناسبة', 'eng_weekend' => 'تلقائي: عطلة الأسبوع', 'eng_support' => 'تلقائي: دعم نفسي للطلاب',
        'eng_tips' => 'تلقائي: نصيحة لولي الأمر', 'eng_teach' => 'تلقائي: حث المدرسين على التصوير', 'eng_bday_s' => 'تلقائي: عيد ميلاد طالب', 'eng_bday_p' => 'تلقائي: عيد ميلاد ولي أمر', 'eng_report' => 'تقرير المدير اليومي');
}

/* ───────── التقويم: هجري / قبطي / مناسبات ───────── */
function sgb_eng_hijri($y, $m, $d) {
    if (class_exists('IntlCalendar')) {
        try {
            $cal = IntlCalendar::createInstance('UTC', 'en@calendar=islamic-umalqura');
            $cal->setTime(gmmktime(12, 0, 0, $m, $d, $y) * 1000);
            return array((int) $cal->get(IntlCalendar::FIELD_YEAR), (int) $cal->get(IntlCalendar::FIELD_MONTH) + 1, (int) $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH));
        } catch (Exception $e) { /* نكمل بالحساب الجدولي */ }
    }
    $jd = gregoriantojd($m, $d, $y);
    $l = $jd - 1948440 + 10632;
    $n = (int) floor(($l - 1) / 10631);
    $l = $l - 10631 * $n + 354;
    $j = (int) (floor((10985 - $l) / 5316) * floor(50 * $l / 17719) + floor($l / 5670) * floor(43 * $l / 15238));
    $l = $l - (int) (floor((30 - $j) / 15) * floor(17719 * $j / 50)) - (int) (floor($j / 16) * floor(15238 * $j / 43)) + 29;
    $hm = (int) floor(24 * $l / 709);
    $hd = $l - (int) floor(709 * $hm / 24);
    return array(30 * $n + $j - 30, $hm, $hd);
}
/** عيد القيامة الأرثوذكسي (ميلادي) كـ Y-m-d. */
function sgb_eng_easter($y) {
    $a = $y % 4; $b = $y % 7; $c = $y % 19;
    $d = (19 * $c + 15) % 30;
    $e = (2 * $a + 4 * $b - $d + 34) % 7;
    $m = (int) floor(($d + $e + 114) / 31);
    $day = (($d + $e + 114) % 31) + 1;
    $t = gmmktime(0, 0, 0, $m, $day, $y) + 13 * 86400;   // من التقويم اليولياني إلى الميلادي (1900–2099)
    return gmdate('Y-m-d', $t);
}
function sgb_eng_force_map() {
    $out = array();
    foreach (preg_split('/\R/u', (string) get_option('sgb_eng_force', '')) as $ln) {
        if (preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s*[|,]\s*([a-z_0-9]+)\s*$/i', $ln, $m)) { $out[] = array($m[1], strtolower($m[2])); }
    }
    $w = (array) get_option('sgb_eng_web', array());
    if (!empty($w['force'])) { foreach ($w['force'] as $f) { $out[] = $f; } }
    return $out;
}
/** مفاتيح المناسبات في تاريخ معيّن (Y-m-d). */
function sgb_eng_occ_for($ymd) {
    list($y, $m, $d) = array_map('intval', explode('-', $ymd));
    $keys = sgb_eng_calc_for($ymd);
    $fixed = array('1-1' => 'newyear', '7-1' => 'coptic_xmas', '25-1' => 'revolution25', '21-3' => 'mothers', '25-4' => 'sinai', '1-5' => 'labour', '30-6' => 'june30', '23-7' => 'july23', '5-10' => 'teachers', '6-10' => 'oct6');
    if (isset($fixed[$d . '-' . $m])) { $keys[] = $fixed[$d . '-' . $m]; }
    // تصحيحات يدوية: لو في تاريخ مفروض لنفس المناسبة خلال ±3 أيام نُسقط المحسوب ونعتمد المفروض
    $force = sgb_eng_force_map();
    $ts = strtotime($ymd . ' UTC');
    foreach ($keys as $i => $k) {
        foreach ($force as $f) {
            if ($f[1] === $k && $f[0] !== $ymd && abs(strtotime($f[0] . ' UTC') - $ts) <= 3 * 86400) { unset($keys[$i]); }
        }
    }
    foreach ($force as $f) { if ($f[0] === $ymd) { $keys[] = $f[1]; } }
    foreach ((array) get_option('sgb_eng_custom_occ', array()) as $i => $c) {
        $dt = isset($c['date']) ? $c['date'] : '';
        if ($dt === $ymd || $dt === sprintf('%02d-%02d', $m, $d)) { $keys[] = 'c_' . $i; }
    }
    $all = sgb_eng_occasions();
    return array_values(array_filter(array_unique($keys), function ($k) use ($all) { return isset($all[$k]); }));
}
/** المناسبات القادمة خلال $days يوم (للعرض والتحديث الدوري). */
function sgb_eng_upcoming($days = 60) {
    $out = array();
    $all = sgb_eng_occasions();
    $t = strtotime(sgb_eng_d('Y-m-d') . ' UTC');
    for ($i = 0; $i <= $days; $i++) {
        $ymd = gmdate('Y-m-d', $t + $i * 86400);
        foreach (sgb_eng_occ_for($ymd) as $k) { $out[] = array('date' => $ymd, 'key' => $k, 'name' => $all[$k][0]); }
    }
    return $out;
}
/** مفاتيح المناسبات المحسوبة محليًا فقط (بدون تصحيحات). */
function sgb_eng_calc_for($ymd) {
    list($y, $m, $d) = array_map('intval', explode('-', $ymd));
    $keys = array();
    $east = sgb_eng_easter($y);
    if ($ymd === $east) { $keys[] = 'coptic_easter'; }
    if ($ymd === gmdate('Y-m-d', strtotime($east . ' UTC') + 86400)) { $keys[] = 'sham'; }
    $h = sgb_eng_hijri($y, $m, $d);
    $hm = array('1-1' => 'hijri_new', '3-12' => 'mawlid', '7-27' => 'isra', '9-1' => 'ramadan', '9-27' => 'qadr', '10-1' => 'eid_fitr', '12-9' => 'arafat', '12-10' => 'eid_adha');
    if (isset($hm[$h[1] . '-' . $h[2]])) { $keys[] = $hm[$h[1] . '-' . $h[2]]; }
    return $keys;
}
function sgb_eng_web_key($name) {
    $map = array('/Fitr/i' => 'eid_fitr', '/Adha/i' => 'eid_adha', '/Arafat/i' => 'arafat', '/Islamic New Year|Hijri/i' => 'hijri_new', '/Prophet|Mawlid/i' => 'mawlid',
        '/Easter Monday|Sham/i' => 'sham', '/Easter Sunday|Orthodox Easter/i' => 'coptic_easter', '/Isra|Mi.raj/i' => 'isra');
    foreach ($map as $re => $k) { if (preg_match($re, $name)) { return $k; } }
    return '';
}
/** بحث أسبوعي في الإنترنت عن مواعيد المناسبات الرسمية في مصر (Nager.Date)؛ يُعتمد التاريخ فقط لو قريب (±3 أيام) من المحسوب. */
function sgb_eng_refresh_occ($force = false) {
    $last = (int) get_option('sgb_eng_occ_ts', 0);
    if (!$force && time() - $last < 7 * 86400) { return; }
    update_option('sgb_eng_occ_ts', time(), false);
    $y0 = (int) sgb_eng_d('Y'); $found = array(); $status = 'ok'; $n = 0;
    foreach (array($y0, $y0 + 1) as $yy) {
        $r = wp_remote_get('https://date.nager.at/api/v3/PublicHolidays/' . $yy . '/EG', array('timeout' => 12));
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { $status = is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($r); continue; }
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($j)) { continue; }
        $n += count($j);
        $by = array();
        foreach ($j as $h) { $k = sgb_eng_web_key(isset($h['name']) ? $h['name'] : ''); if ($k && !empty($h['date'])) { $by[$k][] = $h['date']; } }
        foreach ($by as $k => $dates) {
            sort($dates);
            $prev = null;
            foreach ($dates as $dt) {
                $t = strtotime($dt . ' UTC');
                if ($prev !== null && $t - $prev <= 5 * 86400) { $prev = $t; continue; }   // اليوم الثاني/الثالث من نفس العيد
                $prev = $t;
                for ($o = -3; $o <= 3; $o++) {
                    $c = gmdate('Y-m-d', $t + $o * 86400);
                    if (in_array($k, sgb_eng_calc_for($c), true)) { if ($c !== $dt) { $found[] = array($dt, $k); } break; }
                }
            }
        }
    }
    update_option('sgb_eng_web', array('ts' => time(), 'status' => $status, 'count' => $n, 'force' => $found), false);
}
/* ───────── مستلمون ───────── */
function sgb_eng_first($n, $fallback) {
    $n = trim(preg_replace('/\s+/u', ' ', (string) $n));
    $n = preg_replace('/^(أ\.?|د\.?|الأستاذ[ةه]?|أستاذ[ةه]?|مستر|مس)\s+/u', '', $n);
    $w = explode(' ', $n);
    return ($w[0] !== '' && !preg_match('/^\d+$/', $w[0]) && mb_strlen($w[0]) <= 15) ? $w[0] : $fallback;
}
function sgb_eng_user_phone($uid, $u = null) {
    $p = sgb_tm_phone_of($uid);
    if (!$p) { $u = $u ? $u : get_userdata($uid); if ($u) { $p = sgb_phone($u->user_login); } }
    return $p;
}
function sgb_eng_roles($aud) {
    if ($aud === 'students') { return array('subscriber', 'customer'); }
    if ($aud === 'teachers') { return array('sg_teacher', 'tutor_instructor'); }
    $roles = array('administrator');
    foreach (wp_roles()->get_names() as $slug => $name) { if (preg_match('/مشرف|موظف|مدير|إدار|supervisor|employee|manager/iu', $name . ' ' . $slug)) { $roles[] = $slug; } }
    return array_values(array_unique($roles));
}
function sgb_eng_grades() {
    global $wpdb;
    $r = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key='sg_grade' AND meta_value<>'' LIMIT 80");
    return array_values($r ? $r : array());
}
function sgb_eng_stopped() { return array(); }   // الإيقاف من المدير فقط (من إعدادات الصفحة)
/** يُرجع قائمة مستلمين فريدة بالرقم. كل عنصر: phone,name,child,ch,uid,aud */
function sgb_eng_recipients($auds, $grades = array()) {
    $out = array(); $seen = array(); $stop = sgb_eng_stopped();
    $add = function ($phone, $name, $child, $ch, $uid, $aud) use (&$out, &$seen, $stop) {
        if (!$phone || isset($seen[$phone]) || in_array($phone, $stop, true)) { return; }
        $seen[$phone] = 1;
        $out[] = array('phone' => $phone, 'name' => $name, 'child' => $child, 'ch' => $ch, 'uid' => (int) $uid, 'aud' => $aud);
    };
    $auds = (array) $auds;
    foreach (array('teachers', 'employees') as $a) {
        if (!in_array($a, $auds, true)) { continue; }
        foreach (get_users(array('role__in' => sgb_eng_roles($a), 'fields' => 'all', 'number' => 2000)) as $u) {
            if ($a === 'employees' && array_intersect((array) $u->roles, array('sg_teacher', 'tutor_instructor')) && in_array('teachers', $auds, true)) { continue; }
            $add(sgb_eng_user_phone($u->ID, $u), sgb_eng_first($u->display_name ? $u->display_name : $u->first_name, 'أستاذنا'), '', 'staff', $u->ID, $a);
        }
    }
    if (in_array('students', $auds, true) || in_array('parents', $auds, true)) {
        foreach (get_users(array('role__in' => sgb_eng_roles('students'), 'fields' => 'all', 'number' => 20000)) as $u) {
            if ($grades && !in_array((string) get_user_meta($u->ID, 'sg_grade', true), (array) $grades, true)) { continue; }
            $nm = trim(get_user_meta($u->ID, 'billing_first_name', true) . ' ' . get_user_meta($u->ID, 'billing_last_name', true));
            $first = sgb_eng_first($nm !== '' ? $nm : $u->display_name, '');
            if (in_array('students', $auds, true)) { $add(sgb_eng_user_phone($u->ID, $u), $first, '', 'students', $u->ID, 'students'); }
            if (in_array('parents', $auds, true)) {
                $pp = sgb_phone((string) get_user_meta($u->ID, 'sg_parent_phone', true));
                $add($pp, sgb_eng_first(get_user_meta($u->ID, 'sg_parent_name', true), ''), $first, 'students', $u->ID, 'parents');
            }
        }
    }
    return $out;
}
function sgb_eng_fallback_name($aud) { return $aud === 'students' ? 'يا بطل' : ($aud === 'parents' ? 'يا فندم' : ($aud === 'teachers' ? 'أستاذنا' : 'يا فندم')); }

/* ───────── توليد النص ───────── */
function sgb_eng_spin($t) {
    for ($i = 0; $i < 6 && preg_match('/\{[^{}]*\|[^{}]*\}/u', $t); $i++) {
        $t = preg_replace_callback('/\{([^{}]*\|[^{}]*)\}/u', function ($m) { $p = explode('|', $m[1]); return $p[array_rand($p)]; }, $t);
    }
    return $t;
}
function sgb_eng_fill($t, $r, $extra = array()) {
    $fb = sgb_eng_fallback_name($r['aud']);
    $name = $r['name'] !== '' ? $r['name'] : $fb;
    $child = $r['child'] !== '' ? $r['child'] : 'ابنكم';
    $t = sgb_render($t, array_merge(array('name' => $name, 'child' => $child, 'parent' => $r['aud'] === 'parents' ? $name : ''), $extra));
    $t = preg_replace('/\bيا\s+يا\s+/u', 'يا ', $t);
    $t = preg_replace('/أستاذ\s+(?:مستر|ميستر|أستاذ|استاذ|الأستاذ|أ\.?)\s+/u', 'أستاذ ', $t);
    $t = str_replace('أستاذ أستاذنا', 'أستاذنا', $t);
    return trim(preg_replace("/[ \t]{2,}/u", ' ', $t));
}
function sgb_eng_footer() { return ''; }   // لا يوجد سطر إيقاف: الإيقاف بيد المدير فقط
/** إرسال رسالة من المكتبة: يجرّب تركيبات حتى يجد تركيبة لم تُرسل لهذا الرقم من قبل. */
function sgb_eng_send_lib($cat, $r, $extra = array(), $override_body = null, $tag = '') {
    $L = sgb_lib($cat);
    if (!$L) { return new WP_Error('nolib', 'فئة غير معروفة'); }
    $bodies = $override_body !== null ? $override_body : $L['body'];
    if (!$bodies) { return new WP_Error('nobody', 'لا متن'); }
    $no = count($L['open']); $nb = count($bodies); $nc = count($L['close']);
    $rule = 'eng_' . preg_replace('/_(active|mid|away)$/', '', $cat);
    $last = null;
    for ($try = 0; $try < 14; $try++) {
        $i = mt_rand(0, $no - 1); $j = mt_rand(0, $nb - 1); $k = mt_rand(0, $nc - 1);
        $txt = sgb_eng_fill($L['open'][$i] . "\n" . $bodies[$j] . "\n" . $L['close'][$k], $r, $extra);
        $last = $txt;
        $id = ($tag !== '' ? $tag : $cat) . ':' . $r['phone'] . ':' . $i . '.' . $j . '.' . $k;
        $res = sgb_enqueue($r['ch'], $r['phone'], $txt . sgb_eng_footer(), array('rule' => $rule, 'dedupe' => 'eng:' . $id, 'dedupe_hours' => 24 * 700, 'priority' => -10));
        if (!is_wp_error($res)) { return $res; }
        if ($res->get_error_code() !== 'duplicate') { return $res; }
    }
    // استنفدنا التركيبات الجديدة لهذا الرقم: نسمح بتكرار بعيد بدل التوقف
    return sgb_enqueue($r['ch'], $r['phone'], $last, array('rule' => $rule, 'dedupe' => 'eng:re:' . $cat . ':' . $r['phone'] . ':' . sgb_eng_d('Ymd'), 'dedupe_hours' => 20, 'priority' => -10));
}
function sgb_eng_support_cat($uid) {
    $ll = $uid ? sgb_tm_last_login($uid) : 0;
    if (!$ll) { return 'support_away'; }
    $days = (time() - $ll) / 86400;
    return $days <= 2 ? 'support_active' : ($days <= 7 ? 'support_mid' : 'support_away');
}
function sgb_eng_recent_mass($phone) {
    global $wpdb;
    $t = $wpdb->prefix . 'sgb_outbox';
    return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE to_phone=%s AND rule_key IN ('eng_support','eng_weekend','eng_tips','eng_teach') AND created>%d LIMIT 1", $phone, time() - 20 * 3600));
}

/* ───────── الدفعات (Jobs) ───────── */
function sgb_eng_jobs() { $j = get_option('sgb_eng_jobs', array()); return is_array($j) ? $j : array(); }
function sgb_eng_save_jobs($j) {
    // نحتفظ بآخر 40 مهمة، والمنتهية تُفرَّغ من قائمة المستلمين
    uasort($j, function ($a, $b) { return $b['created'] <=> $a['created']; });
    $j = array_slice($j, 0, 40, true);
    update_option('sgb_eng_jobs', $j, false);
}
/** $job: kind (manual|lib), cat, text, occ, label, recips, ts */
function sgb_eng_create_job($job) {
    $jobs = sgb_eng_jobs();
    $id = 'j' . time() . mt_rand(100, 999);
    $job = array_merge(array('label' => '', 'kind' => 'manual', 'cat' => '', 'text' => '', 'occ' => '', 'ts' => time(), 'recips' => array()), $job);
    $job['id'] = $id; $job['created'] = time(); $job['total'] = count($job['recips']); $job['sent'] = 0; $job['skipped'] = 0; $job['status'] = $job['total'] ? 'wait' : 'done';
    $jobs[$id] = $job;
    sgb_eng_save_jobs($jobs);
    return $id;
}
function sgb_eng_process_jobs() {
    $jobs = sgb_eng_jobs();
    $hh = (int) sgb_eng_d('G');
    if (!$jobs || $hh < 8 || $hh >= 23) { return; }
    $changed = false; $budget = 30;
    foreach ($jobs as $id => &$j) {
        if ($budget <= 0) { break; }
        if (!in_array($j['status'], array('wait', 'run'), true) || $j['ts'] > time()) { continue; }
        $j['status'] = 'run';
        while ($budget > 0 && $j['recips']) {
            $r = array_shift($j['recips']); $budget--;
            $res = sgb_eng_send_one($j, $r);
            if (is_wp_error($res)) { $j['skipped']++; } else { $j['sent']++; }
        }
        if (!$j['recips']) { $j['status'] = 'done'; }
        $changed = true;
    }
    unset($j);
    if ($changed) { update_option('sgb_eng_jobs', $jobs, false); }
}
function sgb_eng_send_one($j, $r) {
    if ($j['kind'] === 'manual') {
        $txt = sgb_eng_fill(sgb_eng_spin($j['text']), $r);
        return sgb_enqueue($r['ch'], $r['phone'], $txt, array('rule' => 'eng_manual', 'dedupe' => 'eng:m:' . $j['id'] . ':' . $r['phone'], 'dedupe_hours' => 24 * 30, 'priority' => -5));
    }
    $cat = $j['cat'];
    if ($cat === 'support') { $cat = sgb_eng_support_cat($r['uid']); }
    if (in_array($j['cat'], array('support', 'weekend', 'tips', 'teach'), true) && sgb_eng_recent_mass($r['phone'])) { return new WP_Error('skip', 'تلقائية أخرى اليوم'); }
    if ($j['cat'] === 'occ') {
        $all = sgb_eng_occasions();
        if (!isset($all[$j['occ']])) { return new WP_Error('skip', 'مناسبة'); }
        $o = $all[$j['occ']];
        $L = sgb_lib('occ');
        $wish = $o[2];
        $bodies = array();
        foreach ($wish as $w) { $bodies[] = $w; }
        return sgb_eng_send_lib('occ', $r, array('occ' => $o[0]), $bodies, 'occ.' . $j['occ']);
    }
    return sgb_eng_send_lib($cat, $r, array(), null, $j['cat'] === 'support' ? 'support' : '');
}

/* ───────── الجدولة التلقائية (تُستدعى من الكرون) ───────── */
function sgb_eng_birth_of($uid, $parent = false) {
    $keys = $parent ? array('sg_parent_birthdate') : array('sg_birthdate', 'birth_date', 'birthdate', 'dob', 'birthday', 'date_of_birth', 'sg_dob');
    foreach ($keys as $k) {
        $v = trim((string) get_user_meta($uid, $k, true));
        if ($v === '') { continue; }
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) { return sprintf('%02d-%02d', $m[2], $m[3]); }
        if (preg_match('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $v, $m)) { return sprintf('%02d-%02d', $m[2], $m[1]); }
    }
    return '';
}
function sgb_eng_auto() {
    $c = sgb_eng_cfg();
    $today = sgb_eng_d('Y-m-d'); $hour = (int) sgb_eng_d('G'); $dow = (int) sgb_eng_d('w');
    $done = (array) get_option('sgb_eng_done', array());
    $mark = function ($k) use (&$done) { $done[$k] = time(); update_option('sgb_eng_done', $done, false); };
    $ready = function ($k, $h, $span = 6) use (&$done, $hour, $today) { return empty($done[$k . ':' . $today]) && $hour >= $h && $hour < $h + $span; };
    foreach ($done as $k => $t) { if ($t < time() - 40 * 86400) { unset($done[$k]); } }

    if (!empty($c['report_on']) && $hour >= (int) $c['report_hour'] && empty($done['report:' . $today])) {
        $mark('report:' . $today);
        sgb_eng_send_report();
    }
    if (!empty($c['occ_on']) && $ready('occ', (int) $c['occ_hour'])) {
        $mark('occ:' . $today);
        $all = sgb_eng_occasions();
        foreach (sgb_eng_occ_for($today) as $key) {
            sgb_eng_create_job(array('kind' => 'lib', 'cat' => 'occ', 'occ' => $key, 'label' => 'تهنئة: ' . $all[$key][0], 'recips' => sgb_eng_recipients($c['occ_aud'])));
        }
    }
    if (!empty($c['weekend_on']) && in_array($dow, (array) $c['weekend_days'], true) && $ready('weekend', (int) $c['weekend_hour'])) {
        $mark('weekend:' . $today);
        sgb_eng_create_job(array('kind' => 'lib', 'cat' => 'weekend', 'label' => 'رسالة عطلة الأسبوع للطلاب', 'recips' => sgb_eng_recipients(array('students'))));
    }
    if (!empty($c['support_on']) && in_array($dow, (array) $c['support_days'], true) && $ready('support', (int) $c['support_hour'])) {
        $mark('support:' . $today);
        sgb_eng_create_job(array('kind' => 'lib', 'cat' => 'support', 'label' => 'دعم نفسي للطلاب', 'recips' => sgb_eng_recipients(array('students'))));
    }
    if (!empty($c['tips_on']) && in_array($dow, (array) $c['tips_days'], true) && $ready('tips', (int) $c['tips_hour'])) {
        $mark('tips:' . $today);
        sgb_eng_create_job(array('kind' => 'lib', 'cat' => 'tips', 'label' => 'نصيحة أسرية لأولياء الأمور', 'recips' => sgb_eng_recipients(array('parents'))));
    }
    if (!empty($c['teach_on']) && in_array($dow, (array) $c['teach_days'], true) && $ready('teach', (int) $c['teach_hour'])) {
        $mark('teach:' . $today);
        sgb_eng_create_job(array('kind' => 'lib', 'cat' => 'teach', 'label' => 'حث المدرسين على التصوير ومواكبة المنهج', 'recips' => sgb_eng_recipients(array('teachers'))));
    }
    if (!empty($c['bday_on']) && $ready('bday', (int) $c['bday_hour'])) {
        $mark('bday:' . $today);
        $md = sgb_eng_d('m-d'); $s = array(); $p = array();
        foreach (sgb_eng_recipients(array('students', 'parents')) as $r) {
            if ($r['aud'] === 'students' && sgb_eng_birth_of($r['uid']) === $md) { $s[] = $r; }
            if ($r['aud'] === 'parents' && sgb_eng_birth_of($r['uid'], true) === $md) { $p[] = $r; }
        }
        if ($s) { sgb_eng_create_job(array('kind' => 'lib', 'cat' => 'bday_s', 'label' => 'أعياد ميلاد الطلاب', 'recips' => $s)); }
        if ($p) { sgb_eng_create_job(array('kind' => 'lib', 'cat' => 'bday_p', 'label' => 'أعياد ميلاد أولياء الأمور', 'recips' => $p)); }
    }
    sgb_eng_refresh_occ(false);
}
add_action('sgb_cron', function () {
    if (get_transient('sgb_eng_tick')) { return; }
    set_transient('sgb_eng_tick', 1, 50);
    sgb_eng_auto();
    sgb_eng_process_jobs();
}, 45);

/* ───────── تقرير المدير اليومي ───────── */
function sgb_eng_report_text() {
    global $wpdb;
    $dt = new DateTime(sgb_eng_d('Y-m-d') . ' 00:00:00', sgb_eng_tz());
    $t0 = $dt->getTimestamp();
    $sql0 = sgb_eng_d('Y-m-d H:i:s', $t0);
    $new = count(get_users(array('role__in' => array('subscriber', 'customer'), 'fields' => 'ID', 'date_query' => array(array('after' => $sql0, 'inclusive' => true)))));
    $act = array();
    foreach (array('sgb_last_login', 'wc_last_active') as $mk) {
        foreach (get_users(array('meta_key' => $mk, 'meta_value' => $t0, 'meta_compare' => '>=', 'meta_type' => 'NUMERIC', 'fields' => 'ID', 'number' => 5000)) as $id) { $act[$id] = 1; }
    }
    $orders = 0; $rev = 0;
    if (function_exists('wc_get_orders')) {
        foreach (wc_get_orders(array('status' => array('processing', 'completed'), 'date_created' => '>=' . $t0, 'limit' => 500)) as $o) { $orders++; $rev += (float) $o->get_total(); }
    }
    $enr = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tutor_enrolled' AND post_status='completed' AND post_date>=%s", $sql0));
    $o = $wpdb->prefix . 'sgb_outbox';
    $st = array();
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) c FROM $o WHERE created>=%d GROUP BY status", $t0), ARRAY_A) as $r) { $st[$r['status']] = (int) $r['c']; }
    $pend = (int) $wpdb->get_var("SELECT COUNT(*) FROM $o WHERE status='queued'");
    $inc = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sgb_incoming WHERE created>=%d", $t0));
    $ch = function ($k) { return sgb_desktop_alive($k) ? 'متصل ✅' : 'غير متصل ⚠️'; };
    $js = function_exists('sgb_jr_stats') ? sgb_jr_stats() : array();
    $lines = array('📊 تقرير سين جيم — ' . sgb_eng_d('j/n/Y'));
    $lines[] = '👤 طلاب جدد اليوم: ' . $new;
    $lines[] = '🟢 طلاب دخلوا المنصة اليوم: ' . count($act);
    $lines[] = '🛒 اشتراكات/طلبات اليوم: ' . max($orders, $enr) . ($rev > 0 ? ' (إجمالي ' . round($rev) . ' ج)' : '');
    $lines[] = '💬 رسائل واتساب اليوم: أُرسلت ' . (isset($st['sent']) ? $st['sent'] : 0) . ' · فشلت ' . (isset($st['failed']) ? $st['failed'] : 0) . ' · بالانتظار ' . $pend;
    $lines[] = '📥 ردود واردة اليوم: ' . $inc;
    $lines[] = '📱 قناة الطلاب: ' . $ch('students') . ' | قناة المدرسين: ' . $ch('staff');
    if ($js && isset($js['coupons_used'])) { $lines[] = '🎁 كوبونات الرحلة المستخدمة (إجمالي): ' . (int) $js['coupons_used']; }
    $bad = (isset($st['failed']) ? $st['failed'] : 0);
    $lines[] = $bad > 5 || !sgb_desktop_alive('students') ? '⚠️ يحتاج متابعة: راجع الاتصال والرسائل الفاشلة.' : '✅ كل شيء يعمل بشكل طبيعي.';
    return implode("\n", $lines);
}
function sgb_eng_send_report($test = false) {
    $c = sgb_eng_cfg();
    $p = sgb_phone($c['report_phone']);
    if (!$p) { return new WP_Error('nophone', 'رقم المدير غير صالح'); }
    return sgb_enqueue('staff', $p, sgb_eng_report_text(), array('rule' => 'eng_report', 'dedupe' => $test ? null : 'eng:report:' . sgb_eng_d('Ymd'), 'dedupe_hours' => 20, 'priority' => 5));
}

/* ───────── حقول تاريخ الميلاد في ملف المستخدم ───────── */
function sgb_eng_profile_fields($u) {
    if (!current_user_can('edit_users')) { return; }
    echo '<h3>تاريخ الميلاد (للتهنئة التلقائية)</h3><table class="form-table"><tr><th>تاريخ ميلاد الطالب</th><td><input type="date" name="sg_birthdate" value="' . esc_attr(get_user_meta($u->ID, 'sg_birthdate', true)) . '"></td></tr>';
    echo '<tr><th>تاريخ ميلاد ولي الأمر</th><td><input type="date" name="sg_parent_birthdate" value="' . esc_attr(get_user_meta($u->ID, 'sg_parent_birthdate', true)) . '"></td></tr></table>';
}
add_action('show_user_profile', 'sgb_eng_profile_fields');
add_action('edit_user_profile', 'sgb_eng_profile_fields');
function sgb_eng_profile_save($uid) {
    if (!current_user_can('edit_user', $uid)) { return; }
    foreach (array('sg_birthdate', 'sg_parent_birthdate') as $k) {
        if (isset($_POST[$k])) { $v = sanitize_text_field(wp_unslash($_POST[$k])); update_user_meta($uid, $k, preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''); }
    }
}
add_action('personal_options_update', 'sgb_eng_profile_save');
add_action('edit_user_profile_update', 'sgb_eng_profile_save');
function sgb_eng_newuser_field() {
    echo '<table class="form-table"><tr class="form-field"><th>تاريخ ميلاد الطالب</th><td><input type="date" name="sg_birthdate"></td></tr><tr class="form-field"><th>تاريخ ميلاد ولي الأمر</th><td><input type="date" name="sg_parent_birthdate"></td></tr></table>';
}
add_action('user_new_form', 'sgb_eng_newuser_field');
add_action('user_register', function ($uid) {
    foreach (array('sg_birthdate', 'sg_parent_birthdate') as $k) {
        if (isset($_POST[$k])) { $v = sanitize_text_field(wp_unslash($_POST[$k])); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { update_user_meta($uid, $k, $v); } }
    }
}, 20);
/* نموذج التسجيل وحسابي في ووكومرس */
add_action('woocommerce_register_form', function () {
    echo '<p class="form-row form-row-wide"><label for="sg_birthdate">تاريخ ميلاد الطالب</label><input type="date" class="input-text" name="sg_birthdate" id="sg_birthdate"></p><p class="form-row form-row-wide"><label for="sg_parent_birthdate">تاريخ ميلاد ولي الأمر (اختياري)</label><input type="date" class="input-text" name="sg_parent_birthdate" id="sg_parent_birthdate"></p>';
});
add_action('woocommerce_edit_account_form', function () {
    $uid = get_current_user_id();
    echo '<p class="form-row form-row-wide"><label for="sg_birthdate">تاريخ ميلاد الطالب</label><input type="date" class="input-text" name="sg_birthdate" id="sg_birthdate" value="' . esc_attr(get_user_meta($uid, 'sg_birthdate', true)) . '"></p><p class="form-row form-row-wide"><label for="sg_parent_birthdate">تاريخ ميلاد ولي الأمر</label><input type="date" class="input-text" name="sg_parent_birthdate" id="sg_parent_birthdate" value="' . esc_attr(get_user_meta($uid, 'sg_parent_birthdate', true)) . '"></p>';
});
add_action('woocommerce_save_account_details', function ($uid) {
    foreach (array('sg_birthdate', 'sg_parent_birthdate') as $k) {
        if (isset($_POST[$k])) { $v = sanitize_text_field(wp_unslash($_POST[$k])); if ($v === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { update_user_meta($uid, $k, $v); } }
    }
});
function sgb_eng_user_by_phone($p) {
    $cands = array($p, '0' . substr($p, 2), substr($p, 2));
    foreach ($cands as $c) { $u = get_user_by('login', $c); if ($u) { return $u; } }
    $q = get_users(array('meta_query' => array(array('key' => 'billing_phone', 'value' => $cands, 'compare' => 'IN')), 'number' => 1));
    return $q ? $q[0] : null;
}

/* ───────── REST ───────── */
add_action('rest_api_init', function () {
    $ns = 'sgb/v1';
    register_rest_route($ns, '/engage', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        $jobs = array_map(function ($j) { unset($j['recips']); return $j; }, array_values(sgb_eng_jobs()));
        return array('cfg' => sgb_eng_cfg(), 'jobs' => $jobs, 'upcoming' => sgb_eng_upcoming(45), 'grades' => sgb_eng_grades());
    }));
    register_rest_route($ns, '/engage-settings', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        return sgb_eng_save_cfg((array) $req->get_json_params());
    }));
    register_rest_route($ns, '/engage-send', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $b = (array) $req->get_json_params();
        $text = isset($b['text']) ? trim((string) $b['text']) : '';
        if ($text === '') { return new WP_Error('empty', 'اكتب نص الرسالة', array('status' => 400)); }
        $auds = isset($b['aud']) ? (array) $b['aud'] : array();
        $r = sgb_eng_recipients($auds, isset($b['grades']) ? (array) $b['grades'] : array());
        if (!empty($b['count_only'])) { return array('count' => count($r)); }
        $ts = !empty($b['ts']) ? (int) $b['ts'] : time();
        $id = sgb_eng_create_job(array('kind' => 'manual', 'text' => $text, 'label' => 'رسالة يدوية', 'ts' => $ts, 'recips' => $r));
        return array('ok' => true, 'job' => $id, 'count' => count($r));
    }));
    register_rest_route($ns, '/engage-cancel', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $id = (string) $req->get_param('id'); $jobs = sgb_eng_jobs();
        if (isset($jobs[$id])) { $jobs[$id]['status'] = 'cancel'; $jobs[$id]['recips'] = array(); update_option('sgb_eng_jobs', $jobs, false); }
        return array('ok' => true);
    }));
    register_rest_route($ns, '/engage-report', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        $r = sgb_eng_send_report(true);
        return is_wp_error($r) ? $r : array('ok' => true, 'text' => sgb_eng_report_text());
    }));
});

/* ───────── صفحة الإدارة ───────── */
add_action('admin_menu', function () {
    $cb = 'sgb_eng_page';
    if (function_exists('sgb_menu_parent') && sgb_menu_parent() === 'sgh-assistant') { add_submenu_page('sgh-assistant', 'الرسائل الجماعية والتلقائية', '📣 رسائل جماعية وتلقائية', 'manage_options', 'sgb-engage', $cb); }
    else { add_submenu_page('sgb', 'الرسائل الجماعية والتلقائية', '📣 رسائل جماعية وتلقائية', 'manage_options', 'sgb-engage', $cb); }
}, 101);
function sgb_eng_page() {
    if (!current_user_can('manage_options')) { return; }
    $msg = ''; $preview = '';
    $audn = array('students' => 'الطلاب', 'parents' => 'أولياء الأمور', 'teachers' => 'المدرسين', 'employees' => 'الموظفين والمشرفين');
    $days = array(6 => 'السبت', 0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة');
    $draft = isset($_POST['text']) ? wp_unslash($_POST['text']) : '';
    $selA = isset($_POST['aud']) ? array_map('sanitize_key', (array) $_POST['aud']) : array();
    $selG = isset($_POST['grades']) ? array_map('sanitize_text_field', array_map('wp_unslash', (array) $_POST['grades'])) : array();
    if (isset($_POST['sgb_eng_send']) && check_admin_referer('sgb_eng_send')) {
        $text = trim(sanitize_textarea_field($draft));
        $recips = sgb_eng_recipients($selA, $selG);
        $act = sanitize_key($_POST['sgb_eng_send']);
        if ($text === '' || !$selA) { $msg = 'اكتب الرسالة واختر فئة واحدة على الأقل.'; }
        elseif ($act === 'preview') {
            $s = $recips ? $recips[array_rand($recips)] : array('phone' => '', 'name' => 'أحمد', 'child' => 'أحمد', 'aud' => $selA[0], 'ch' => 'students', 'uid' => 0);
            $preview = '<div class="notice notice-info"><p>عدد المستلمين: <b>' . count($recips) . '</b></p><pre style="white-space:pre-wrap;background:#fff;padding:10px;border:1px solid #ddd">' . esc_html(sgb_eng_fill(sgb_eng_spin($text), $s)) . '</pre></div>';
        } elseif ($act === 'test') {
            $tp = sgb_phone(isset($_POST['test_phone']) ? $_POST['test_phone'] : '');
            if (!$tp) { $msg = 'اكتب رقم صالح للتجربة.'; }
            else {
                $s = array('phone' => $tp, 'name' => 'تجربة', 'child' => 'أحمد', 'aud' => $selA[0], 'ch' => ($selA[0] === 'teachers' || $selA[0] === 'employees') ? 'staff' : 'students', 'uid' => 0);
                $res = sgb_enqueue($s['ch'], $tp, sgb_eng_fill(sgb_eng_spin($text), $s), array('rule' => 'eng_manual', 'priority' => 5));
                $msg = is_wp_error($res) ? 'تعذّر: ' . $res->get_error_message() : 'تم وضع رسالة التجربة في الطابور ✅';
            }
        } else {
            $ts = time();
            if (!empty($_POST['when'])) { try { $d = new DateTime(sanitize_text_field($_POST['when']), sgb_eng_tz()); $ts = max(time(), $d->getTimestamp()); } catch (Exception $e) { $ts = time(); } }
            if (!$recips) { $msg = 'لا يوجد مستلمون بهذه الاختيارات.'; }
            else { sgb_eng_create_job(array('kind' => 'manual', 'text' => $text, 'label' => 'رسالة يدوية', 'ts' => $ts, 'recips' => $recips)); $msg = 'تم إنشاء الإرسال لعدد ' . count($recips) . ' مستلم ✅ (يُرسل على دفعات صغيرة).'; $draft = ''; }
        }
    }
    if (isset($_POST['sgb_eng_cfg']) && check_admin_referer('sgb_eng_cfg')) {
        $b = array();
        foreach (array('report_on', 'occ_on', 'weekend_on', 'support_on', 'tips_on', 'bday_on', 'teach_on') as $k) { $b[$k] = !empty($_POST[$k]) ? 1 : 0; }
        foreach (array('report_hour', 'occ_hour', 'weekend_hour', 'support_hour', 'tips_hour', 'bday_hour', 'teach_hour') as $k) { if (isset($_POST[$k])) { $b[$k] = (int) $_POST[$k]; } }
        $b['report_phone'] = isset($_POST['report_phone']) ? sanitize_text_field(wp_unslash($_POST['report_phone'])) : '';
        foreach (array('weekend_days', 'support_days', 'tips_days', 'teach_days') as $k) { $b[$k] = isset($_POST[$k]) ? (array) $_POST[$k] : array(); }
        $b['occ_aud'] = isset($_POST['occ_aud']) ? array_map('sanitize_key', (array) $_POST['occ_aud']) : array();
        sgb_eng_save_cfg($b);
        $msg = 'تم حفظ الإعدادات ✅';
    }
    if (isset($_POST['sgb_eng_occ']) && check_admin_referer('sgb_eng_occ')) {
        update_option('sgb_eng_force', sanitize_textarea_field(wp_unslash($_POST['force'])), false);
        $cu = array();
        foreach (preg_split('/\R/u', sanitize_textarea_field(wp_unslash($_POST['custom']))) as $ln) {
            $p = array_map('trim', explode('|', $ln));
            if (count($p) >= 2 && preg_match('/^(\d{4}-)?\d{2}-\d{2}$/', $p[0]) && $p[1] !== '') { $cu[] = array('date' => $p[0], 'name' => $p[1], 'wish' => isset($p[2]) ? $p[2] : ''); }
        }
        update_option('sgb_eng_custom_occ', $cu, false);
        sgb_eng_refresh_occ(true);
        $msg = 'تم تحديث المناسبات ✅';
    }
    if (isset($_POST['sgb_eng_bday']) && check_admin_referer('sgb_eng_bday')) {
        $n = 0; $bad = 0;
        foreach (preg_split('/\R/u', (string) wp_unslash($_POST['bdays'])) as $ln) {
            $p = array_map('trim', preg_split('/[,\t;]/u', $ln));
            if (count($p) < 2) { continue; }
            $ph = sgb_phone($p[0]); $u = $ph ? sgb_eng_user_by_phone($ph) : null;
            if ($u && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p[1])) { update_user_meta($u->ID, 'sg_birthdate', $p[1]); if (!empty($p[2]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p[2])) { update_user_meta($u->ID, 'sg_parent_birthdate', $p[2]); } $n++; } else { $bad++; }
        }
        $msg = 'تم تسجيل ' . $n . ' تاريخ ميلاد' . ($bad ? ' — ' . $bad . ' سطر لم يُطابق' : '') . '.';
    }
    if (isset($_POST['sgb_eng_cancel']) && check_admin_referer('sgb_eng_cancel')) {
        $id = sanitize_text_field($_POST['sgb_eng_cancel']); $jobs = sgb_eng_jobs();
        if (isset($jobs[$id])) { $jobs[$id]['status'] = 'cancel'; $jobs[$id]['recips'] = array(); update_option('sgb_eng_jobs', $jobs, false); $msg = 'تم إلغاء الإرسال.'; }
    }
    if (isset($_POST['sgb_eng_rtest']) && check_admin_referer('sgb_eng_rtest')) {
        $r = sgb_eng_send_report(true); $msg = is_wp_error($r) ? 'تعذّر: ' . $r->get_error_message() : 'تم إرسال تقرير تجريبي لرقم المدير ✅';
    }
    $c = sgb_eng_cfg();
    $ck = function ($k) use ($c) { return !empty($c[$k]) ? ' checked' : ''; };
    $dayboxes = function ($name, $sel) use ($days) { $o = ''; foreach ($days as $d => $l) { $o .= '<label style="margin-left:8px"><input type="checkbox" name="' . $name . '[]" value="' . $d . '"' . (in_array($d, (array) $sel, true) ? ' checked' : '') . '> ' . $l . '</label>'; } return $o; };
    echo '<div class="wrap" dir="rtl"><h1>📣 الرسائل الجماعية والتلقائية</h1>';
    if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
    echo $preview;

    echo '<h2>✍️ أرسل رسالة بنفسك</h2><form method="post">'; wp_nonce_field('sgb_eng_send');
    echo '<p><b>إلى:</b> ';
    foreach ($audn as $k => $l) { echo '<label style="margin-left:14px"><input type="checkbox" name="aud[]" value="' . $k . '"' . (in_array($k, $selA, true) ? ' checked' : '') . '> ' . $l . '</label>'; }
    echo '</p>';
    $gr = sgb_eng_grades();
    if ($gr) { echo '<p><b>الصفوف (للطلاب وأولياء الأمور، اتركها فارغة للكل):</b> '; foreach ($gr as $g) { echo '<label style="margin-left:10px"><input type="checkbox" name="grades[]" value="' . esc_attr($g) . '"' . (in_array($g, $selG, true) ? ' checked' : '') . '> ' . esc_html($g) . '</label>'; } echo '</p>'; }
    echo '<p><textarea name="text" rows="6" style="width:100%;max-width:700px" placeholder="اكتب رسالتك. المتغيرات: {name} اسم المستلم، {child} اسم الطالب لولي الأمر. للتنويع: {صباح الخير|أهلاً|مساء النور}">' . esc_textarea($draft) . '</textarea></p>';
    echo '<p>موعد الإرسال (اختياري): <input type="datetime-local" name="when"> &nbsp; رقم للتجربة: <input name="test_phone" placeholder="01xxxxxxxxx"></p>';
    echo '<p><button class="button" name="sgb_eng_send" value="preview">معاينة وعدد المستلمين</button> <button class="button" name="sgb_eng_send" value="test">إرسال تجربة لرقمي</button> <button class="button button-primary" name="sgb_eng_send" value="go" onclick="return confirm(\'تأكيد الإرسال للفئات المختارة؟\')">إرسال / جدولة</button></p></form>';

    echo '<hr><h2>🤖 الرسائل التلقائية</h2><p style="color:#a00">يمكنك تشغيل أو إيقاف كل نوع من هنا. كل نوع يُرسل على دفعات صغيرة، ولا يصل نفس النص لنفس الرقم مرتين. لا يوجد خيار إيقاف للمستلمين: الإيقاف من هنا فقط.</p>';
    echo '<form method="post">'; wp_nonce_field('sgb_eng_cfg'); echo '<table class="form-table">';
    echo '<tr><th>تقرير المدير اليومي</th><td><label><input type="checkbox" name="report_on" value="1"' . $ck('report_on') . '> مفعّل</label> الساعة <input type="number" name="report_hour" value="' . (int) $c['report_hour'] . '" style="width:60px"> على رقم <input name="report_phone" value="' . esc_attr($c['report_phone']) . '"></td></tr>';
    echo '<tr><th>تهنئة المناسبات</th><td><label><input type="checkbox" name="occ_on" value="1"' . $ck('occ_on') . '> مفعّل</label> الساعة <input type="number" name="occ_hour" value="' . (int) $c['occ_hour'] . '" style="width:60px"> لـ: ';
    foreach ($audn as $k => $l) { echo '<label style="margin-left:8px"><input type="checkbox" name="occ_aud[]" value="' . $k . '"' . (in_array($k, (array) $c['occ_aud'], true) ? ' checked' : '') . '> ' . $l . '</label>'; }
    echo '</td></tr>';
    echo '<tr><th>حث المدرسين على التصوير</th><td><label><input type="checkbox" name="teach_on" value="1"' . $ck('teach_on') . '> مفعّل</label> الساعة <input type="number" name="teach_hour" value="' . (int) $c['teach_hour'] . '" style="width:60px"> ' . $dayboxes('teach_days', $c['teach_days']) . '</td></tr>';
    echo '<tr><th>أعياد الميلاد</th><td><label><input type="checkbox" name="bday_on" value="1"' . $ck('bday_on') . '> مفعّل</label> الساعة <input type="number" name="bday_hour" value="' . (int) $c['bday_hour'] . '" style="width:60px"> (للطالب ولولي الأمر عند توفر التاريخ)</td></tr>';
    echo '<tr><th>عطلة الأسبوع للطلاب</th><td><label><input type="checkbox" name="weekend_on" value="1"' . $ck('weekend_on') . '> مفعّل</label> الساعة <input type="number" name="weekend_hour" value="' . (int) $c['weekend_hour'] . '" style="width:60px"> ' . $dayboxes('weekend_days', $c['weekend_days']) . '</td></tr>';
    echo '<tr><th>دعم نفسي للطلاب (حسب الانتظام)</th><td><label><input type="checkbox" name="support_on" value="1"' . $ck('support_on') . '> مفعّل</label> الساعة <input type="number" name="support_hour" value="' . (int) $c['support_hour'] . '" style="width:60px"> ' . $dayboxes('support_days', $c['support_days']) . '</td></tr>';
    echo '<tr><th>نصيحة أسرية لأولياء الأمور</th><td><label><input type="checkbox" name="tips_on" value="1"' . $ck('tips_on') . '> مفعّل</label> الساعة <input type="number" name="tips_hour" value="' . (int) $c['tips_hour'] . '" style="width:60px"> ' . $dayboxes('tips_days', $c['tips_days']) . '</td></tr>';
    echo '</table>';
    echo '<p><button class="button button-primary" name="sgb_eng_cfg" value="1">حفظ</button></p></form>';
    echo '<form method="post" style="display:inline">'; wp_nonce_field('sgb_eng_rtest'); echo '<button class="button" name="sgb_eng_rtest" value="1">أرسل تقرير تجريبي لرقم المدير الآن</button></form>';

    echo '<hr><h2>🗓️ المناسبات القادمة (45 يومًا)</h2><table class="widefat striped" style="max-width:560px"><tbody>';
    $up = sgb_eng_upcoming(45);
    if (!$up) { echo '<tr><td>لا مناسبات قريبة.</td></tr>'; }
    foreach ($up as $o) { echo '<tr><td>' . esc_html($o['date']) . '</td><td>' . esc_html($o['name']) . '</td><td><code>' . esc_html($o['key']) . '</code></td></tr>'; }
    $w = (array) get_option('sgb_eng_web', array());
    echo '</tbody></table><p style="color:#666">التواريخ الهجرية تُحسب تلقائيًا ويبحث السيرفر عنها في الإنترنت كل أسبوع. ' . (!empty($w['ts']) ? 'آخر بحث: ' . esc_html(sgb_eng_d('j/n H:i', $w['ts'])) . ' — ' . esc_html($w['status']) . ' (' . (int) $w['count'] . ' عطلة رسمية، تعديلات: ' . count((array) $w['force']) . ')' : 'لم يتم البحث بعد.') . ' قد يختلف يوم حسب رؤية الهلال، فصحّحه يدويًا هنا.</p>';
    echo '<form method="post">'; wp_nonce_field('sgb_eng_occ');
    echo '<p><b>تصحيح تاريخ مناسبة</b> (سطر لكل مناسبة: <code>2027-03-10|eid_fitr</code>):<br><textarea name="force" rows="3" style="width:100%;max-width:560px">' . esc_textarea((string) get_option('sgb_eng_force', '')) . '</textarea></p>';
    $cu = ''; foreach ((array) get_option('sgb_eng_custom_occ', array()) as $x) { $cu .= $x['date'] . '|' . $x['name'] . (!empty($x['wish']) ? '|' . $x['wish'] : '') . "\n"; }
    echo '<p><b>مناسبات إضافية</b> (<code>MM-DD|الاسم|نص التهنئة</code> أو <code>YYYY-MM-DD|…</code>):<br><textarea name="custom" rows="3" style="width:100%;max-width:560px">' . esc_textarea($cu) . '</textarea></p>';
    echo '<p><button class="button" name="sgb_eng_occ" value="1">حفظ وتحديث المناسبات</button></p></form>';

    echo '<hr><h2>🎂 تواريخ الميلاد</h2><p>التاريخ يُسجَّل من صفحة تعديل المستخدم، أو الصق هنا أسطرًا بالشكل: <code>رقم الطالب,2010-05-23,2980-01-01(اختياري لولي الأمر)</code></p><form method="post">'; wp_nonce_field('sgb_eng_bday');
    echo '<textarea name="bdays" rows="4" style="width:100%;max-width:560px"></textarea><p><button class="button" name="sgb_eng_bday" value="1">تسجيل</button></p></form>';

    echo '<hr><h2>📋 الإرسالات</h2><table class="widefat striped"><thead><tr><th>الإرسال</th><th>الحالة</th><th>أُرسل</th><th>تم تخطيه</th><th>متبقي</th><th></th></tr></thead><tbody>';
    $jobs = sgb_eng_jobs();
    if (!$jobs) { echo '<tr><td colspan="6">لا توجد إرسالات بعد.</td></tr>'; }
    $stl = array('wait' => 'بانتظار الموعد', 'run' => 'جارٍ', 'done' => 'انتهى', 'cancel' => 'أُلغي');
    foreach ($jobs as $j) {
        echo '<tr><td>' . esc_html($j['label']) . '<br><small>' . esc_html(sgb_eng_d('j/n H:i', $j['created'])) . '</small></td><td>' . esc_html($stl[$j['status']]) . '</td><td>' . (int) $j['sent'] . '</td><td>' . (int) $j['skipped'] . '</td><td>' . count($j['recips']) . '</td><td>';
        if (in_array($j['status'], array('wait', 'run'), true)) { echo '<form method="post">'; wp_nonce_field('sgb_eng_cancel'); echo '<button class="button" name="sgb_eng_cancel" value="' . esc_attr($j['id']) . '">إلغاء</button></form>'; }
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<hr><h2>📚 عيّنات من المكتبة (تتغير مع كل تحديث للصفحة)</h2><table class="widefat striped"><tbody>';
    $samp = array('teach' => 'حث المدرسين', 'weekend' => 'عطلة الأسبوع', 'support_active' => 'دعم: منتظم', 'support_mid' => 'دعم: متأخر', 'support_away' => 'دعم: غائب', 'tips' => 'نصيحة لولي الأمر', 'bday_s' => 'ميلاد طالب');
    $tot = 0;
    foreach ($samp as $k => $l) {
        $L = sgb_lib($k); $n = count($L['open']) * count($L['body']) * count($L['close']); $tot += $n;
        $r = array('name' => 'محمد', 'child' => 'محمد', 'aud' => $k === 'tips' ? 'parents' : ($k === 'teach' ? 'teachers' : 'students'));
        $t = sgb_eng_fill($L['open'][array_rand($L['open'])] . "\n" . $L['body'][array_rand($L['body'])] . "\n" . $L['close'][array_rand($L['close'])], $r);
        echo '<tr><td style="width:150px"><b>' . esc_html($l) . '</b><br><small>' . number_format_i18n($n) . ' تركيبة</small></td><td style="white-space:pre-wrap">' . esc_html($t) . '</td></tr>';
    }
    echo '</tbody></table><p>إجمالي التركيبات المتاحة بدون المناسبات: <b>' . number_format_i18n($tot) . '</b>، والتركيبات تتجدد مع تكرار الاستخدام ولا يتكرر نص لنفس الرقم.</p></div>';
}
