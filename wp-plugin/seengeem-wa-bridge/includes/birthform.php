<?php
/**
 * فورم تاريخ الميلاد (2.9): رابط خاص لكل شخص يصله على واتساب (طالب / ولي أمر / مدرس / موظف)،
 * يفتح صفحة بسيطة يملأ فيها التاريخ فيُحفظ تلقائيًا. لا يحتاج تسجيل دخول.
 * الطلب يُرسل مرتين كحد أقصى لكل رقم (بينهما 14 يومًا) وبحد يومي صغير حتى لا يزعج أحدًا.
 */
if (!defined('ABSPATH')) { exit; }

function sgb_bd_sig($who, $uid) { return substr(hash_hmac('sha256', $who . '|' . (int) $uid, wp_salt('auth')), 0, 16); }
function sgb_bd_link($uid, $who) { return home_url('/?sgb_bd=' . $who . (int) $uid . 'x' . sgb_bd_sig($who, $uid)); }
/** يُرجع array(user, who) أو null */
function sgb_bd_parse($t) {
    if (!preg_match('/^([sp])(\d+)x([a-f0-9]{16})$/', (string) $t, $m)) { return null; }
    if (!hash_equals(sgb_bd_sig($m[1], $m[2]), $m[3])) { return null; }
    $u = get_userdata((int) $m[2]);
    return $u ? array($u, $m[1]) : null;
}
function sgb_bd_missing($r) {
    if (empty($r['uid'])) { return false; }
    return sgb_eng_birth_of($r['uid'], $r['aud'] === 'parents') === '';
}
function sgb_bd_can_ask($phone) {
    $asked = (array) get_option('sgb_bd_asked', array());
    $a = isset($asked[$phone]) ? $asked[$phone] : array(0, 0);
    if ($a[0] >= 2) { return false; }
    if ($a[0] >= 1 && time() - $a[1] < 14 * 86400) { return false; }
    return true;
}
function sgb_bd_mark_asked($phone) {
    $asked = (array) get_option('sgb_bd_asked', array());
    $a = isset($asked[$phone]) ? $asked[$phone] : array(0, 0);
    $asked[$phone] = array($a[0] + 1, time());
    update_option('sgb_bd_asked', $asked, false);
    return $a[0] + 1;
}
/** من لم يسجّل تاريخ ميلاده ويحق لنا سؤاله الآن */
function sgb_bd_candidates($auds, $limit = 60) {
    $out = array();
    foreach (sgb_eng_recipients($auds) as $r) {
        if (!sgb_bd_missing($r) || !sgb_bd_can_ask($r['phone'])) { continue; }
        $out[] = $r;
        if (count($out) >= $limit) { break; }
    }
    return $out;
}
/** يُستدعى من sgb_eng_send_one */
function sgb_bd_send_one($r) {
    if (!sgb_bd_missing($r) || !sgb_bd_can_ask($r['phone'])) { return new WP_Error('skip', 'مسجَّل أو سبق السؤال'); }
    if (sgb_eng_recent_mass($r['phone'])) { return new WP_Error('skip', 'رسالة تلقائية أخرى اليوم'); }
    $parent = $r['aud'] === 'parents';
    $link = sgb_bd_link($r['uid'], $parent ? 'p' : 's');
    $res = sgb_eng_send_lib($parent ? 'bdform_p' : 'bdform_s', $r, array('link' => $link), null, 'bdf');
    if (!is_wp_error($res)) { sgb_bd_mark_asked($r['phone']); }
    return $res;
}

/* ───────── صفحة الفورم العامة ───────── */
add_action('template_redirect', function () {
    if (!isset($_GET['sgb_bd'])) { return; }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    $pr = sgb_bd_parse(sanitize_text_field(wp_unslash($_GET['sgb_bd'])));
    $title = 'تاريخ الميلاد — سين جيم';
    $msg = ''; $done = false; $ok = true;
    if (!$pr) { $ok = false; $msg = 'الرابط غير صحيح أو منتهي. اطلب رابطًا جديدًا من الإدارة.'; }
    else {
        list($u, $who) = $pr;
        $isParent = $who === 'p';
        $fields = $isParent ? array('sg_parent_birthdate' => 'تاريخ ميلاد حضرتك', 'sg_birthdate' => 'تاريخ ميلاد ' . sgb_bd_child_name($u)) : array('sg_birthdate' => 'تاريخ ميلادك');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $saved = 0; $bad = false;
            foreach (array_keys($fields) as $k) {
                $d = isset($_POST[$k . '_d']) ? (int) $_POST[$k . '_d'] : 0;
                $m = isset($_POST[$k . '_m']) ? (int) $_POST[$k . '_m'] : 0;
                $y = isset($_POST[$k . '_y']) ? (int) $_POST[$k . '_y'] : 0;
                if (!$d && !$m && !$y) { continue; }
                if (!checkdate($m, $d, $y) || $y < 1935 || $y > (int) gmdate('Y') - 3) { $bad = true; continue; }
                update_user_meta($u->ID, $k, sprintf('%04d-%02d-%02d', $y, $m, $d));
                $saved++;
            }
            update_user_meta($u->ID, 'sg_bd_form_at', time());
            if ($bad) { $msg = 'في تاريخ غير صحيح، راجعه من فضلك.'; }
            elseif (!$saved) { $msg = 'اختر اليوم والشهر والسنة أولًا.'; }
            else { $done = true; }
        }
    }
    $cur = gmdate('Y');
    $sel = function ($name, $from, $to, $ph, $val, $labels = null) {
        $o = '<select name="' . esc_attr($name) . '"><option value="">' . esc_html($ph) . '</option>';
        for ($i = $from; $i <= $to; $i++) { $o .= '<option value="' . $i . '"' . ((int) $val === $i ? ' selected' : '') . '>' . esc_html($labels ? $labels[$i] : $i) . '</option>'; }
        return $o . '</select>';
    };
    $months = array(1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر');
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html($title) . '</title>';
    echo '<style>body{margin:0;background:#f4f1fb;font-family:Tahoma,Arial,sans-serif;color:#2b2540}.c{max-width:440px;margin:30px auto;background:#fff;border-radius:16px;padding:26px;box-shadow:0 6px 24px rgba(80,50,160,.12)}h1{font-size:20px;margin:0 0 6px;color:#5b3fc4}p{line-height:1.8;margin:8px 0}label{display:block;margin:16px 0 6px;font-weight:bold}.r{display:flex;gap:8px}select{flex:1;padding:11px 6px;border:1px solid #cfc7ea;border-radius:10px;font-size:16px;background:#fff}button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:12px;background:#5b3fc4;color:#fff;font-size:17px;font-weight:bold}.m{background:#fdecec;color:#a22;padding:10px;border-radius:10px;margin-top:12px}.ok{text-align:center;font-size:18px;color:#1c7a3d}</style></head><body><div class="c">';
    if (!$ok) { echo '<h1>سين جيم</h1><div class="m">' . esc_html($msg) . '</div>'; }
    elseif ($done) { echo '<h1>شكراً ليك 💜</h1><p class="ok">اتسجّل تاريخ الميلاد بنجاح 🎂</p><p style="text-align:center;color:#6b6585">هنتمنالك كل سنة وإنت طيب في يومك.</p>'; }
    else {
        echo '<h1>🎂 تاريخ الميلاد</h1><p>أهلاً ' . esc_html(sgb_eng_first($u->display_name, '')) . '، سجّل ' . ($isParent ? 'التواريخ' : 'التاريخ') . ' عشان نهنّيك في يوم الميلاد.</p>';
        if ($msg) { echo '<div class="m">' . esc_html($msg) . '</div>'; }
        echo '<form method="post">';
        foreach ($fields as $k => $label) {
            $cv = trim((string) get_user_meta($u->ID, $k, true)); $cy = $cm = $cd = 0;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $cv, $mm)) { $cy = (int) $mm[1]; $cm = (int) $mm[2]; $cd = (int) $mm[3]; }
            echo '<label>' . esc_html($label) . '</label><div class="r">' . $sel($k . '_d', 1, 31, 'اليوم', $cd) . $sel($k . '_m', 1, 12, 'الشهر', $cm, $months) . $sel($k . '_y', 1935, (int) $cur - 3, 'السنة', $cy) . '</div>';
        }
        echo '<button type="submit">حفظ</button></form>';
    }
    echo '</div></body></html>';
    exit;
}, 1);
function sgb_bd_child_name($u) {
    $nm = trim(get_user_meta($u->ID, 'billing_first_name', true) . ' ' . get_user_meta($u->ID, 'billing_last_name', true));
    $f = sgb_eng_first($nm !== '' ? $nm : $u->display_name, '');
    return $f !== '' ? $f : 'ابنكم';
}
