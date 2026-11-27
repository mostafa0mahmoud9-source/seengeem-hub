<?php
/**
 * الإصدار 2.7: «رحلة الطالب الجديد» —
 *   1) ترحيب بالطالب وولي أمره عند التسجيل.
 *   2) بعد يومين بلا اشتراك: كوبون خصم 15% لمدة 3 أيام باسم الطالب وحده (WooCommerce حقيقي + تطبيق تلقائي).
 *   3) بعد أسبوع بلا اشتراك: كوبون 30% لمدة 24 ساعة (ويُلغى كوبون الـ15%).
 * تذكير قبل الانتهاء، رسالة منفصلة لولي الأمر، عرض واحد لكل رقم، نافذة إرسال نهارية، وإيقاف تلقائي عند الشراء.
 * مغلق افتراضيًا ويعمل فقط على من يسجّل بعد وقت التفعيل.
 */
if (!defined('ABSPATH')) { exit; }

function sgb_jr_defaults() {
    return array('enabled' => 0, 'start_from' => 0, 'welcome' => 1, 'parent' => 1, 'remind' => 1,
        'o1_on' => 1, 'o1_after' => 48, 'o1_pct' => 15, 'o1_days' => 3,
        'o2_on' => 1, 'o2_after' => 168, 'o2_pct' => 30, 'o2_hours' => 24,
        'win_start' => 10, 'win_end' => 21, 'parent_meta' => '');
}
function sgb_jr_cfg() { return array_replace(sgb_jr_defaults(), (array) get_option('sgb_journey', array())); }

function sgb_jr_texts() {
    return array(
        'jr_welcome_s' => "أهلاً {name} في منصة سين جيم 💜\nسعداء بانضمامك! ابدأ من هنا: {site}\nلو محتاج مساعدة في اختيار المادة المناسبة ابعتلنا هنا وفريقنا يرد عليك.",
        'jr_welcome_p' => "أهلاً بحضرتك 🌷 تم تسجيل {name} في منصة سين جيم التعليمية. هنتابع معاه وهنبلغ حضرتك بأي جديد، ولو عندك أي استفسار ابعتلنا هنا.",
        'jr_o1_s' => "{name}، عندك هدية من سين جيم 🎁\nخصم {percent}% على أي كورس لمدة {days} أيام — ليك إنت وبس.\nالكود: {code}\nفعّله بضغطة واحدة: {link}\nالعرض ينتهي {expiry}",
        'jr_o1_p' => "ولي أمر {name} 🌷 عندنا عرض خاص لابنكم/ابنتكم: خصم {percent}% على أي كورس لمدة {days} أيام.\nالكود: {code}\nيتفعّل من هنا: {link}\nينتهي {expiry}",
        'jr_o1r_s' => "{name}، فاضل أقل من 24 ساعة على خصم {percent}% ⏰\nالكود: {code}\n{link}",
        'jr_o1r_p' => "تذكير لولي أمر {name} ⏰ خصم {percent}% على الكورسات بينتهي {expiry}.\nالكود: {code}\n{link}",
        'jr_o2_s' => "{name}، آخر فرصة 🔥 خصم {percent}% لمدة 24 ساعة بس!\nالكود: {code}\nفعّله دلوقتي: {link}\nينتهي {expiry}",
        'jr_o2_p' => "ولي أمر {name} 🔥 عرض لمدة 24 ساعة فقط: خصم {percent}% على أي كورس.\nالكود: {code}\n{link}\nينتهي {expiry}",
        'jr_o2r_s' => "{name}، فاضل {hours} ساعات بس على خصم {percent}% ⏳\nالكود: {code}\n{link}",
        'jr_o2r_p' => "تذكير أخير لولي أمر {name} ⏳ خصم {percent}% بينتهي {expiry}.\nالكود: {code}\n{link}",
    );
}
function sgb_jr_labels() {
    return array('jr_welcome_s' => 'رحلة: ترحيب بالطالب', 'jr_welcome_p' => 'رحلة: ترحيب بولي الأمر', 'jr_o1_s' => 'رحلة: عرض 15% للطالب', 'jr_o1_p' => 'رحلة: عرض 15% لولي الأمر',
        'jr_o1r_s' => 'رحلة: تذكير 15% للطالب', 'jr_o1r_p' => 'رحلة: تذكير 15% لولي الأمر', 'jr_o2_s' => 'رحلة: عرض 30% للطالب', 'jr_o2_p' => 'رحلة: عرض 30% لولي الأمر',
        'jr_o2r_s' => 'رحلة: تذكير 30% للطالب', 'jr_o2r_p' => 'رحلة: تذكير 30% لولي الأمر');
}
function sgb_jr_text($key, $vars) {
    $ro = sgb_rules_get();
    if (!empty($ro[$key]['off'])) { return ''; }
    $d = sgb_jr_texts();
    $t = (isset($ro[$key]['text']) && trim((string) $ro[$key]['text']) !== '') ? $ro[$key]['text'] : $d[$key];
    return sgb_render($t, $vars);
}
function sgb_jr_learn() {
    foreach (sgb_jr_texts() as $k => $t) { sgb_rules_learn($k, $t, array('name' => 1, 'percent' => 1, 'days' => 1, 'hours' => 1, 'code' => 1, 'link' => 1, 'expiry' => 1, 'site' => 1)); }
}

/* ───────── أدوات ───────── */
function sgb_jr_get($uid) { $j = get_user_meta($uid, 'sgb_jr', true); return is_array($j) ? $j : array(); }
function sgb_jr_save($uid, $j) { update_user_meta($uid, 'sgb_jr', $j); }

add_action('user_register', function ($uid) {
    update_user_meta($uid, 'sgb_jr', array('start' => time()));
    update_user_meta($uid, 'sgb_jr_open', 1);
});

function sgb_jr_student_phone($uid) {
    if (function_exists('sgb_tm_phone_of')) { return sgb_tm_phone_of($uid); }
    foreach (array('billing_phone', 'phone_number', 'phone', 'mobile', 'whatsapp') as $k) { $p = sgb_phone((string) get_user_meta($uid, $k, true)); if ($p) { return $p; } }
    return '';
}
/** رقم ولي الأمر: مفتاح محدد في الإعدادات، ثم مفاتيح شائعة، ثم أي حقل اسمه يدل على ولي الأمر ورقم. */
function sgb_jr_parent_phone($uid, $own = '') {
    $cfg = sgb_jr_cfg();
    $keys = array_filter(array_merge(array($cfg['parent_meta']), array('sg_parent_phone', 'parent_phone', 'parent_mobile', 'parent_whatsapp', 'guardian_phone', 'father_phone', 'mother_phone', 'parent_number', 'phone_parent', 'billing_parent_phone')));
    foreach ($keys as $k) {
        $p = sgb_phone((string) get_user_meta($uid, $k, true));
        if ($p && $p !== $own) { return $p; }
    }
    foreach ((array) get_user_meta($uid) as $k => $v) {
        if (preg_match('/(parent|guardian|father|mother|ولي|والد)/iu', $k) && preg_match('/(phone|mobile|whats|tel|num|هاتف|موبايل|رقم|واتس)/iu', $k)) {
            $p = sgb_phone((string) (is_array($v) ? reset($v) : $v));
            if ($p && $p !== $own) { return $p; }
        }
    }
    return '';
}
function sgb_jr_is_student($uid) {
    $u = get_userdata($uid);
    if (!$u) { return false; }
    $bad = array('administrator', 'sg_teacher', 'tutor_instructor', 'shop_manager', 'editor', 'author', 'sg_supervisor');
    return !array_intersect($bad, (array) $u->roles);
}
function sgb_jr_purchased($uid) {
    global $wpdb;
    if (function_exists('wc_get_orders')) {
        $o = wc_get_orders(array('customer_id' => $uid, 'status' => array('wc-processing', 'wc-completed'), 'limit' => 1, 'return' => 'ids'));
        if (!empty($o)) { return true; }
    }
    $e = $wpdb->get_var($wpdb->prepare("SELECT e.ID FROM {$wpdb->posts} e JOIN {$wpdb->postmeta} m ON m.post_id=e.post_parent AND m.meta_key='_tutor_course_price_type' AND m.meta_value='paid'
        WHERE e.post_type='tutor_enrolled' AND e.post_author=%d AND e.post_status='completed' LIMIT 1", $uid));
    return !empty($e);
}
function sgb_jr_in_window($cfg = null) {
    $cfg = $cfg ? $cfg : sgb_jr_cfg();
    $h = (int) wp_date('G');
    return $h >= (int) $cfg['win_start'] && $h < (int) $cfg['win_end'];
}
/** أقرب وقت داخل نافذة الإرسال قبل $ts (لا يتجاوز آخر ساعة قبل الإغلاق). */
function sgb_jr_clamp($ts, $cfg) {
    $h = (int) wp_date('G', $ts); $m = (int) wp_date('i', $ts);
    $a = (int) $cfg['win_start']; $b = (int) $cfg['win_end'];
    if ($h >= $a && $h < $b) { return $ts; }
    $diff = ($h < $a) ? ($h + 24 - ($b - 1)) : ($h - ($b - 1));
    return $ts - $diff * 3600 - $m * 60;
}

/* ───────── الكوبونات ───────── */
function sgb_jr_make_coupon($uid, $pct, $exp_ts, $tag) {
    if (!class_exists('WC_Coupon')) { return ''; }
    $u = get_userdata($uid);
    $code = 'SG' . (int) $pct . '-' . strtoupper(wp_generate_password(6, false, false));
    $c = new WC_Coupon();
    $c->set_code($code);
    $c->set_discount_type('percent');
    $c->set_amount((float) $pct);
    $c->set_individual_use(true);
    $c->set_usage_limit(1);
    $c->set_usage_limit_per_user(1);
    $c->set_email_restrictions(array($u->user_email));
    $c->set_date_expires($exp_ts);
    $c->set_description('رحلة الطالب الجديد (' . $tag . ') — مستخدم #' . $uid);
    $c->update_meta_data('_sgb_journey', $uid);
    $c->save();
    return $c->get_id() ? $code : '';
}
function sgb_jr_expire_coupon($code) {
    if (!$code || !class_exists('WC_Coupon')) { return; }
    $c = new WC_Coupon($code);
    if ($c->get_id() && (!$c->get_date_expires() || $c->get_date_expires()->getTimestamp() > time())) { $c->set_date_expires(time() - 60); $c->save(); }
}
function sgb_jr_link($code) { return add_query_arg('sgb_coupon', rawurlencode($code), home_url('/')); }
function sgb_jr_active_code($uid) {
    $j = sgb_jr_get($uid); $now = time();
    if (!empty($j['c2']) && $now < (int) $j['e2']) { return $j['c2']; }
    if (!empty($j['c1']) && $now < (int) $j['e1']) { return $j['c1']; }
    return '';
}
function sgb_jr_apply_code($code) {
    if (!function_exists('WC') || !WC()->cart || !$code) { return false; }
    $c = new WC_Coupon($code);
    if (!$c->get_id() || $c->get_usage_count() > 0) { return false; }
    $e = $c->get_date_expires();
    if ($e && $e->getTimestamp() < time()) { return false; }
    if (WC()->cart->has_discount($code)) { return true; }
    foreach (WC()->cart->get_applied_coupons() as $old) { if (strpos($old, 'SG') === 0) { WC()->cart->remove_coupon($old); } }
    $ok = WC()->cart->add_discount($code);
    if (function_exists('wc_clear_notices') && !$ok) { wc_clear_notices(); }
    return $ok;
}
/** رابط الكوبون: يسجّل الدخول عند الحاجة ثم يطبّق الكوبون ويحوّل لصفحة الكورسات. */
add_action('wp', function () {
    if (empty($_GET['sgb_coupon']) || !function_exists('WC')) { return; }
    $code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code(wp_unslash($_GET['sgb_coupon'])) : sanitize_text_field(wp_unslash($_GET['sgb_coupon']));
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(add_query_arg('sgb_coupon', rawurlencode($code), home_url('/'))));
        exit;
    }
    if (WC()->session && !WC()->session->has_session()) { WC()->session->set_customer_session_cookie(true); }
    $c = new WC_Coupon($code);
    if ($c->get_id() && (int) $c->get_meta('_sgb_journey') === get_current_user_id()) { sgb_jr_apply_code($code); }
    $to = (WC()->cart && !WC()->cart->is_empty()) ? wc_get_cart_url() : (get_post_type_archive_link('courses') ?: home_url('/'));
    wp_safe_redirect($to);
    exit;
}, 20);
/** تطبيق تلقائي لأحدث كوبون فعّال عند الإضافة للسلة وفتح السلة/الدفع. */
function sgb_jr_autoapply() {
    if (!is_user_logged_in() || !function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) { return; }
    $code = sgb_jr_active_code(get_current_user_id());
    if ($code) { sgb_jr_apply_code($code); }
}
add_action('woocommerce_add_to_cart', 'sgb_jr_autoapply', 20);
add_action('woocommerce_before_cart', 'sgb_jr_autoapply');
add_action('woocommerce_before_checkout_form', 'sgb_jr_autoapply', 5);

/* ───────── الإرسال ───────── */
function sgb_jr_stage_send($uid, $stage, $vars, $own, $par, $cfg) {
    $n = 0;
    $targets = array(array($own, $stage . '_s'));
    if ($cfg['parent'] && $par && $par !== $own) { $targets[] = array($par, $stage . '_p'); }
    foreach ($targets as $t) {
        if (!$t[0]) { continue; }
        $body = sgb_jr_text('jr_' . $t[1], $vars);
        if ($body === '') { continue; }
        $r = sgb_enqueue('students', $t[0], $body, array('rule' => 'jr_' . $t[1], 'priority' => 1, 'dedupe' => 'jr:' . $stage . ':' . $uid . ':' . $t[0], 'dedupe_hours' => 24 * 30));
        if (!is_wp_error($r)) { $n++; }
    }
    return $n;
}
function sgb_jr_stopped_phone($p) { return $p && in_array($p, (array) get_option('sgb_jr_stopphones', array()), true); }

function sgb_jr_process($uid, $cfg) {
    $j = sgb_jr_get($uid);
    if (empty($j['start'])) { $j['start'] = time(); }
    $now = time();
    $closed = function ($why) use ($uid, &$j) { $j['stopped'] = $why; sgb_jr_save($uid, $j); update_user_meta($uid, 'sgb_jr_open', 0); };
    if (!sgb_jr_is_student($uid)) { return $closed('not_student'); }
    if (get_user_meta($uid, 'sgb_jr_stop', true)) { return $closed('manual'); }
    if (sgb_jr_purchased($uid)) {
        $j['conv'] = !empty($j['o2']) ? 'o2' : (!empty($j['o1']) ? 'o1' : 'pre');
        sgb_jr_expire_coupon(isset($j['c1']) ? $j['c1'] : ''); sgb_jr_expire_coupon(isset($j['c2']) ? $j['c2'] : '');
        return $closed('purchased');
    }
    $own = sgb_jr_student_phone($uid);
    $par = sgb_jr_parent_phone($uid, $own);
    if (sgb_jr_stopped_phone($own) || sgb_jr_stopped_phone($par)) { return $closed('opted_out'); }
    $u = get_userdata($uid);
    $name = $u->display_name ? $u->display_name : $u->user_login;
    $base = array('name' => $name, 'site' => home_url('/'));

    // 1) ترحيب (لا يتكرر لو وصل الرقم نفسه ترحيب «lead_welcome» خلال آخر يومين)
    if ($cfg['welcome'] && empty($j['w']) && $now >= (int) $j['start'] + 900) {
        $j['w'] = $now;
        global $wpdb;
        $t = $wpdb->prefix . 'sgb_outbox';
        $dup = $own ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE to_phone=%s AND rule_key='lead_welcome' AND created>%d AND status<>'failed' LIMIT 1", $own, $now - 2 * 86400)) : 0;
        $targets_own = $dup ? '' : $own;
        sgb_jr_stage_send($uid, 'welcome', $base, $targets_own, $par, $cfg);
        sgb_jr_save($uid, $j);
    }
    if (!$own && !$par) { return; }

    // 2) كوبون 15%
    $o2_at = (int) $j['start'] + (int) $cfg['o2_after'] * 3600;
    if ($cfg['o1_on'] && empty($j['o1']) && empty($j['o2']) && $now >= (int) $j['start'] + (int) $cfg['o1_after'] * 3600 && $now < $o2_at && sgb_jr_in_window($cfg)) {
        global $wpdb;
        $ot = $wpdb->prefix . 'sgb_outbox';
        $recent = $own ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $ot WHERE to_phone=%s AND rule_key LIKE 'lead\\_%%' AND created>%d AND status<>'failed' LIMIT 1", $own, $now - 6 * 3600)) : 0;
        if ($recent) { return; }
        $map = (array) get_option('sgb_jr_phones', array());
        $key = $own ? $own : $par;
        if (isset($map[$key]) && (int) $map[$key] !== (int) $uid) { return $closed('dup_phone'); }
        $exp = $now + (int) $cfg['o1_days'] * 86400;
        $code = sgb_jr_make_coupon($uid, $cfg['o1_pct'], $exp, '15');
        if ($code) {
            $map[$key] = $uid; update_option('sgb_jr_phones', $map, false);
            $j['o1'] = $now; $j['c1'] = $code; $j['e1'] = $exp;
            $rt = max(sgb_jr_clamp($exp - 86400, $cfg), $now + 7200);
            $j['r1'] = ($cfg['remind'] && $rt < $exp - 3600) ? $rt : 0;
            sgb_jr_save($uid, $j);
            sgb_jr_stage_send($uid, 'o1', $base + array('percent' => (int) $cfg['o1_pct'], 'days' => (int) $cfg['o1_days'], 'code' => $code, 'link' => sgb_jr_link($code), 'expiry' => wp_date('j/n \ا\ل\س\ا\ع\ة g:i A', $exp)), $own, $par, $cfg);
        }
    }
    if (!empty($j['o1']) && empty($j['o1r']) && !empty($j['r1']) && $now >= (int) $j['r1'] && $now < (int) $j['e1'] - 1800 && empty($j['o2']) && sgb_jr_in_window($cfg)) {
        $j['o1r'] = $now; sgb_jr_save($uid, $j);
        sgb_jr_stage_send($uid, 'o1r', $base + array('percent' => (int) $cfg['o1_pct'], 'code' => $j['c1'], 'link' => sgb_jr_link($j['c1']), 'expiry' => wp_date('j/n \ا\ل\س\ا\ع\ة g:i A', (int) $j['e1'])), $own, $par, $cfg);
    }

    // 3) كوبون 30% لـ24 ساعة (ويلغي كوبون 15%)
    if ($cfg['o2_on'] && empty($j['o2']) && $now >= $o2_at && sgb_jr_in_window($cfg)) {
        $map = (array) get_option('sgb_jr_phones', array());
        $key = $own ? $own : $par;
        if (isset($map[$key]) && (int) $map[$key] !== (int) $uid) { return $closed('dup_phone'); }
        sgb_jr_expire_coupon(isset($j['c1']) ? $j['c1'] : '');
        $exp = $now + (int) $cfg['o2_hours'] * 3600;
        $code = sgb_jr_make_coupon($uid, $cfg['o2_pct'], $exp, '30');
        if ($code) {
            $map[$key] = $uid; update_option('sgb_jr_phones', $map, false);
            $j['o2'] = $now; $j['c2'] = $code; $j['e2'] = $exp;
            $rt = max(sgb_jr_clamp($exp - 6 * 3600, $cfg), $now + 7200);
            $j['r2'] = ($cfg['remind'] && $rt < $exp - 3600) ? $rt : 0;
            sgb_jr_save($uid, $j);
            sgb_jr_stage_send($uid, 'o2', $base + array('percent' => (int) $cfg['o2_pct'], 'days' => 1, 'code' => $code, 'link' => sgb_jr_link($code), 'expiry' => wp_date('j/n \ا\ل\س\ا\ع\ة g:i A', $exp)), $own, $par, $cfg);
        }
    }
    if (!empty($j['o2']) && empty($j['o2r']) && !empty($j['r2']) && $now >= (int) $j['r2'] && $now < (int) $j['e2'] - 1800 && sgb_jr_in_window($cfg)) {
        $j['o2r'] = $now; sgb_jr_save($uid, $j);
        sgb_jr_stage_send($uid, 'o2r', $base + array('percent' => (int) $cfg['o2_pct'], 'hours' => max(1, (int) round(((int) $j['e2'] - $now) / 3600)), 'code' => $j['c2'], 'link' => sgb_jr_link($j['c2']), 'expiry' => wp_date('j/n \ا\ل\س\ا\ع\ة g:i A', (int) $j['e2'])), $own, $par, $cfg);
    }
    // انتهت الرحلة
    $end = !empty($j['o2']) ? (int) $j['e2'] : (!$cfg['o2_on'] && !empty($j['o1']) ? (int) $j['e1'] : 0);
    if ($end && $now > $end) { return $closed('expired'); }
    if (!$cfg['o1_on'] && !$cfg['o2_on'] && !empty($j['w'])) { return $closed('welcome_only'); }
}

function sgb_jr_tick() {
    $cfg = sgb_jr_cfg();
    if (empty($cfg['enabled'])) { return; }
    $last = (int) get_option('sgb_jr_last', 0);
    if (time() - $last < 240) { return; }
    update_option('sgb_jr_last', time(), false);
    $ids = get_users(array('meta_key' => 'sgb_jr_open', 'meta_value' => 1, 'fields' => 'ID', 'number' => 300, 'orderby' => 'ID'));
    $from = (int) $cfg['start_from'];
    foreach ($ids as $uid) {
        $u = get_userdata($uid);
        if (!$u) { continue; }
        if ($from && strtotime($u->user_registered . ' UTC') < $from) { continue; }
        try { sgb_jr_process((int) $uid, $cfg); } catch (Throwable $e) { /* لا نوقف باقي الطلاب */ }
    }
}
add_action('sgb_cron', 'sgb_jr_tick', 35);

/** رد «إيقاف» من الطالب/ولي الأمر يوقف الرحلة لهذا الرقم. */
add_action('sgb_incoming_message', function ($channel, $phone, $body) {
    if (preg_match('/^\s*(stop|إيقاف|ايقاف|الغاء|إلغاء|لا ترسل)\s*$/iu', (string) $body)) {
        $l = (array) get_option('sgb_jr_stopphones', array());
        $l[] = $phone;
        update_option('sgb_jr_stopphones', array_values(array_unique($l)), false);
    } elseif (preg_match('/^\s*(start|اشتراك|تشغيل)\s*$/iu', (string) $body)) {
        update_option('sgb_jr_stopphones', array_values(array_diff((array) get_option('sgb_jr_stopphones', array()), array($phone))), false);
    }
}, 10, 3);

/* ───────── إحصاءات ───────── */
function sgb_jr_stats() {
    global $wpdb;
    $cfg = sgb_jr_cfg(); $from = (int) $cfg['start_from'];
    $ids = get_users(array('meta_key' => 'sgb_jr', 'fields' => 'ID', 'number' => 5000));
    $s = array('started' => 0, 'welcomed' => 0, 'o1' => 0, 'o2' => 0, 'bought' => 0, 'bought_pre' => 0, 'bought_o1' => 0, 'bought_o2' => 0, 'open' => 0, 'stopped' => 0);
    foreach ($ids as $uid) {
        $u = get_userdata($uid);
        if (!$u || ($from && strtotime($u->user_registered . ' UTC') < $from)) { continue; }
        $j = sgb_jr_get($uid);
        $s['started']++;
        if (!empty($j['w'])) { $s['welcomed']++; }
        if (!empty($j['o1'])) { $s['o1']++; }
        if (!empty($j['o2'])) { $s['o2']++; }
        if (isset($j['stopped']) && $j['stopped'] === 'purchased') { $s['bought']++; $s['bought_' . (isset($j['conv']) ? $j['conv'] : 'pre')]++; }
        elseif (get_user_meta($uid, 'sgb_jr_open', true)) { $s['open']++; }
        else { $s['stopped']++; }
    }
    $s['coupons_used'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} a JOIN {$wpdb->postmeta} b ON a.post_id=b.post_id WHERE a.meta_key='_sgb_journey' AND b.meta_key='usage_count' AND b.meta_value+0>0");
    return $s;
}

/* ───────── REST ───────── */
add_action('rest_api_init', function () {
    $ns = 'sgb/v1';
    register_rest_route($ns, '/journey', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        sgb_jr_learn();
        return array('cfg' => sgb_jr_cfg(), 'stats' => sgb_jr_stats(), 'texts' => sgb_jr_texts());
    }));
    register_rest_route($ns, '/journey-settings', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        return array('ok' => true, 'cfg' => sgb_jr_save_cfg((array) $req->get_json_params()));
    }));
    register_rest_route($ns, '/journey-stop', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $uid = (int) $req->get_param('uid');
        if (!get_userdata($uid)) { return new WP_Error('no_user', 'مستخدم غير موجود', array('status' => 404)); }
        if ($req->get_param('on') === false || $req->get_param('on') === 0 || $req->get_param('on') === '0') { delete_user_meta($uid, 'sgb_jr_stop'); update_user_meta($uid, 'sgb_jr_open', 1); }
        else { update_user_meta($uid, 'sgb_jr_stop', 1); update_user_meta($uid, 'sgb_jr_open', 0); }
        return array('ok' => true);
    }));
});
function sgb_jr_save_cfg($b) {
    $c = sgb_jr_cfg();
    $was = !empty($c['enabled']);
    foreach (array('enabled', 'welcome', 'parent', 'remind', 'o1_on', 'o2_on') as $k) { if (isset($b[$k])) { $c[$k] = $b[$k] ? 1 : 0; } }
    foreach (array('o1_after' => array(1, 720), 'o1_pct' => array(1, 90), 'o1_days' => array(1, 30), 'o2_after' => array(1, 1440), 'o2_pct' => array(1, 90), 'o2_hours' => array(1, 240), 'win_start' => array(0, 22), 'win_end' => array(1, 24)) as $k => $r) {
        if (isset($b[$k])) { $c[$k] = max($r[0], min($r[1], (int) $b[$k])); }
    }
    if (isset($b['parent_meta'])) { $c['parent_meta'] = sanitize_key($b['parent_meta']); }
    if (!empty($c['enabled']) && !$was) { $c['start_from'] = time(); }   // يعمل فقط على من يسجّل بعد التفعيل
    update_option('sgb_journey', $c, false);
    return $c;
}

/* ───────── صفحة الإدارة ───────── */
add_action('admin_menu', function () {
    $cb = 'sgb_jr_page';
    if (function_exists('sgb_menu_parent') && sgb_menu_parent() === 'sgh-assistant') { add_submenu_page('sgh-assistant', 'رحلة الطالب الجديد', '🎁 رحلة الطالب', 'manage_options', 'sgb-journey', $cb); }
    else { add_submenu_page('sgb', 'رحلة الطالب الجديد', '🎁 رحلة الطالب', 'manage_options', 'sgb-journey', $cb); }
}, 100);
function sgb_jr_page() {
    if (!current_user_can('manage_options')) { return; }
    $msg = '';
    if (isset($_POST['sgb_jr_save']) && check_admin_referer('sgb_jr_save')) {
        $b = array();
        foreach (array('enabled', 'welcome', 'parent', 'remind', 'o1_on', 'o2_on') as $k) { $b[$k] = !empty($_POST[$k]) ? 1 : 0; }
        foreach (array('o1_after', 'o1_pct', 'o1_days', 'o2_after', 'o2_pct', 'o2_hours', 'win_start', 'win_end') as $k) { if (isset($_POST[$k])) { $b[$k] = (int) $_POST[$k]; } }
        $b['parent_meta'] = isset($_POST['parent_meta']) ? wp_unslash($_POST['parent_meta']) : '';
        sgb_jr_save_cfg($b);
        $ro = sgb_rules_get();
        foreach (sgb_jr_texts() as $k => $def) {
            $v = isset($_POST['t_' . $k]) ? sanitize_textarea_field(wp_unslash($_POST['t_' . $k])) : '';
            if (trim($v) === trim($def)) { $v = ''; }
            if (!isset($ro[$k])) { $ro[$k] = array('off' => 0, 'text' => ''); }
            $ro[$k]['text'] = $v;
            if (empty($ro[$k]['off']) && $v === '') { unset($ro[$k]); }
        }
        update_option('sgb_rules', $ro, false);
        $msg = 'تم الحفظ ✅';
    }
    if (isset($_POST['sgb_jr_stop']) && check_admin_referer('sgb_jr_stop')) {
        $id = trim((string) wp_unslash($_POST['stop_user']));
        $u = is_numeric($id) ? get_userdata((int) $id) : (is_email($id) ? get_user_by('email', $id) : get_user_by('login', $id));
        if ($u) { update_user_meta($u->ID, 'sgb_jr_stop', 1); update_user_meta($u->ID, 'sgb_jr_open', 0); $msg = 'تم إيقاف الرحلة للطالب ' . $u->display_name; } else { $msg = 'الطالب غير موجود'; }
    }
    sgb_jr_learn();
    $c = sgb_jr_cfg(); $s = sgb_jr_stats(); $ro = sgb_rules_get();
    $lab = sgb_jr_labels(); $def = sgb_jr_texts();
    $ck = function ($k) use ($c) { return !empty($c[$k]) ? ' checked' : ''; };
    $num = function ($k, $w = 80) use ($c) { return '<input type="number" name="' . esc_attr($k) . '" value="' . (int) $c[$k] . '" style="width:' . (int) $w . 'px">'; };
    echo '<div class="wrap" dir="rtl"><h1>🎁 رحلة الطالب الجديد</h1>';
    if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
    echo '<h2>الأداء</h2><table class="widefat striped" style="max-width:640px"><tbody>';
    foreach (array('started' => 'طلاب دخلوا الرحلة', 'welcomed' => 'وصلهم الترحيب', 'o1' => 'وصلهم عرض 15%', 'o2' => 'وصلهم عرض 30%', 'bought' => 'اشتركوا (إجمالي)', 'bought_pre' => '— قبل أي عرض', 'bought_o1' => '— بعد عرض 15%', 'bought_o2' => '— بعد عرض 30%', 'coupons_used' => 'كوبونات استُخدمت فعليًا', 'open' => 'ما زالوا في الرحلة') as $k => $l) {
        echo '<tr><td>' . esc_html($l) . '</td><td><b>' . (int) $s[$k] . '</b></td></tr>';
    }
    echo '</tbody></table><p style="color:#666">تُحسب فقط من سجّل بعد تفعيل الرحلة.</p>';
    echo '<form method="post">'; wp_nonce_field('sgb_jr_save');
    echo '<h2>الإعدادات</h2><table class="form-table">';
    echo '<tr><th>تشغيل الرحلة</th><td><label><input type="checkbox" name="enabled" value="1"' . $ck('enabled') . '> مفعّلة (تعمل على من يسجّل بعد التفعيل فقط)</label></td></tr>';
    echo '<tr><th>المراحل</th><td><label><input type="checkbox" name="welcome" value="1"' . $ck('welcome') . '> ترحيب</label> &nbsp; <label><input type="checkbox" name="o1_on" value="1"' . $ck('o1_on') . '> العرض الأول</label> &nbsp; <label><input type="checkbox" name="o2_on" value="1"' . $ck('o2_on') . '> العرض الثاني</label> &nbsp; <label><input type="checkbox" name="remind" value="1"' . $ck('remind') . '> تذكير قبل الانتهاء</label> &nbsp; <label><input type="checkbox" name="parent" value="1"' . $ck('parent') . '> إرسال لولي الأمر</label></td></tr>';
    echo '<tr><th>العرض الأول</th><td>بعد ' . $num('o1_after') . ' ساعة من التسجيل — خصم ' . $num('o1_pct', 60) . '% — لمدة ' . $num('o1_days', 60) . ' أيام</td></tr>';
    echo '<tr><th>العرض الثاني</th><td>بعد ' . $num('o2_after') . ' ساعة من التسجيل — خصم ' . $num('o2_pct', 60) . '% — لمدة ' . $num('o2_hours', 60) . ' ساعة</td></tr>';
    echo '<tr><th>نافذة الإرسال</th><td>من ' . $num('win_start', 60) . ' إلى ' . $num('win_end', 60) . ' (العروض والتذكيرات فقط تُرسل داخلها)</td></tr>';
    echo '<tr><th>حقل رقم ولي الأمر</th><td><input name="parent_meta" value="' . esc_attr($c['parent_meta']) . '" class="regular-text" placeholder="اتركه فارغًا للاكتشاف التلقائي"></td></tr></table>';
    echo '<h2>نصوص الرسائل</h2><p style="color:#666">المتغيرات: {name} {percent} {days} {hours} {code} {link} {expiry} {site}</p><table class="form-table">';
    foreach ($def as $k => $d) {
        $cur = (isset($ro[$k]['text']) && trim((string) $ro[$k]['text']) !== '') ? $ro[$k]['text'] : $d;
        echo '<tr><th>' . esc_html($lab[$k]) . '</th><td><textarea name="t_' . esc_attr($k) . '" rows="4" style="width:100%;max-width:640px">' . esc_textarea($cur) . '</textarea></td></tr>';
    }
    echo '</table><p><button class="button button-primary" name="sgb_jr_save" value="1">حفظ</button></p></form>';
    echo '<hr><h2>إيقاف الرحلة لطالب</h2><form method="post">'; wp_nonce_field('sgb_jr_stop');
    echo '<input name="stop_user" class="regular-text" placeholder="رقم المستخدم أو الإيميل أو اسم الدخول"> <button class="button" name="sgb_jr_stop" value="1">إيقاف</button></form></div>';
}
