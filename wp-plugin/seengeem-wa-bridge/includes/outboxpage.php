<?php
/** صفحة «سجل الرسائل» في ووردبريس: عدّادات المرسل والمنتظر ونص كل رسالة ظاهر بالكامل. */
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function () {
    $cb = 'sgb_outbox_page';
    if (function_exists('sgb_menu_parent') && sgb_menu_parent() === 'sgh-assistant') { add_submenu_page('sgh-assistant', 'سجل الرسائل', '📨 سجل الرسائل', 'manage_options', 'sgb-outbox', $cb); }
    else { add_submenu_page('sgb', 'سجل الرسائل', '📨 سجل الرسائل', 'manage_options', 'sgb-outbox', $cb); }
}, 102);


/** آخر 10 أرقام لأرقام أولياء الأمور المسجلة (لتصنيف الرسائل: طالب / ولي أمر). مخزّنة 10 دقائق. */
function sgb_parent_tails() {
    $c = get_transient('sgb_ptail');
    if (is_array($c)) { return $c; }
    global $wpdb;
    $out = array();
    foreach ((array) $wpdb->get_col("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key='sg_parent_phone' AND meta_value<>'' LIMIT 40000") as $v) {
        $p = sgb_phone($v);
        if ($p) { $out[substr($p, -10)] = 1; }
    }
    $out = array_keys($out);
    set_transient('sgb_ptail', $out, 600);
    return $out;
}
/** شرط SQL لتبويب الفئة: all / students / parents / staff */
function sgb_aud_where($aud) {
    if ($aud === 'staff') { return "channel='staff'"; }
    if ($aud !== 'students' && $aud !== 'parents') { return '1=1'; }
    $tails = sgb_parent_tails();
    $in = $tails ? "'" . implode("','", array_map('esc_sql', $tails)) . "'" : '';
    if ($aud === 'parents') { return $in ? "channel='students' AND RIGHT(to_phone,10) IN ($in)" : '0=1'; }
    return $in ? "channel='students' AND RIGHT(to_phone,10) NOT IN ($in)" : "channel='students'";
}
/** من صاحب هذا الرقم؟ يرجع array(kind, label) */
function sgb_phone_owner($phone, $channel = '') {
    global $wpdb;
    $tail = substr($phone, -10);
    $like = '%' . $wpdb->esc_like($tail);
    $ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='sg_parent_phone' AND meta_value LIKE %s LIMIT 5", $like));
    if ($ids) {
        $u = get_userdata((int) $ids[0]);
        $pn = trim((string) get_user_meta((int) $ids[0], 'sg_parent_name', true));
        return array('parent', 'ولي أمر' . ($pn !== '' ? ' ' . $pn : '') . ($u ? ' (الطالب: ' . $u->display_name . ')' : ''));
    }
    $uid = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s LIMIT 1", $like));
    if (!$uid && function_exists('sgb_tm_phone_of')) {
        foreach (get_users(array('role__in' => array('sg_teacher', 'tutor_instructor', 'administrator'), 'fields' => 'ID', 'number' => 500)) as $i) { if (substr((string) sgb_tm_phone_of($i), -10) === $tail) { $uid = (int) $i; break; } }
    }
    if ($uid) {
        $u = get_userdata($uid);
        $roles = (array) $u->roles;
        $k = array_intersect($roles, array('sg_teacher', 'tutor_instructor')) ? 'teacher' : (array_intersect($roles, array('subscriber', 'customer')) ? 'student' : 'employee');
        $lab = array('teacher' => 'مدرس', 'student' => 'طالب', 'employee' => 'موظف');
        return array($k, $lab[$k] . ' ' . $u->display_name);
    }
    return array($channel === 'staff' ? 'employee' : 'student', 'رقم غير مسجّل في المنصة');
}

add_action('admin_post_sgb_msg_edit', function () {
    if (!current_user_can('manage_options')) { wp_die('غير مسموح'); }
    check_admin_referer('sgb_msg_edit');
    global $wpdb;
    $t = $wpdb->prefix . 'sgb_outbox';
    $id = (int) $_POST['id'];
    $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'save';
    $body = sanitize_textarea_field(wp_unslash((string) (isset($_POST['body']) ? $_POST['body'] : '')));
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
    $back = admin_url('admin.php?page=sgb-outbox&ph=' . ($row ? $row->to_phone : '') . '&done=');
    if (!$row) { wp_safe_redirect($back . 'gone'); exit; }
    if ($mode === 'cancel') {
        $n = $wpdb->query($wpdb->prepare("UPDATE $t SET status='cancelled', error='أُلغيت يدويًا' WHERE id=%d AND status IN ('queued','qr_pending')", $id));
        wp_safe_redirect($back . ($n ? 'cancel' : 'gone') . '#m' . $id); exit;
    }
    if (trim($body) === '') { wp_safe_redirect($back . 'empty#m' . $id); exit; }
    if ($mode === 'save') {
        $n = $wpdb->query($wpdb->prepare("UPDATE $t SET body=%s WHERE id=%d AND status IN ('queued','qr_pending')", $body, $id));
        wp_safe_redirect($back . ($n ? 'saved' : 'gone') . '#m' . $id); exit;
    }
    // resend: نسخة جديدة بالنص المعدّل
    $new = sgb_enqueue($row->channel, $row->to_phone, $body, array('rule' => 'manual', 'allow_repeat' => 1, 'priority' => 5));
    wp_safe_redirect($back . (is_wp_error($new) ? 'err' : 'resent') . ($new && !is_wp_error($new) ? '#m' . (int) $new : '')); exit;
});

/** لوحة رقم واحد: كل رسائله بالتاريخ مع السابقة/التالية والتعديل */
function sgb_outbox_phone_view($ph) {
    global $wpdb;
    $t = $wpdb->prefix . 'sgb_outbox';
    $ph = sgb_phone($ph);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id,channel,to_phone,body,rule_key,status,error,created,sent_at FROM $t WHERE to_phone=%s ORDER BY id DESC LIMIT 300", $ph));
    $own = sgb_phone_owner($ph, $rows ? $rows[0]->channel : '');
    $stl = array('queued' => 'بالانتظار', 'qr_pending' => 'بانتظار الإرسال', 'sending' => 'جاري الإرسال', 'sent' => 'أُرسلت', 'delivered' => 'وصلت', 'read' => 'قُرئت', 'failed' => 'فشلت', 'cancelled' => 'ملغاة');
    $col = array('sent' => '#1c7a3d', 'delivered' => '#1c7a3d', 'read' => '#1c7a3d', 'failed' => '#b32d2e', 'cancelled' => '#777', 'queued' => '#996800', 'qr_pending' => '#996800', 'sending' => '#996800');
    $msgs = array('saved' => '✅ اتحفظ التعديل', 'cancel' => '✅ اتلغت الرسالة', 'resent' => '✅ اتضافت نسخة جديدة للطابور', 'gone' => '⚠️ الرسالة اتبعتت أو اتغيّرت حالتها', 'empty' => '⚠️ النص فاضي', 'err' => '⚠️ ما اتضافتش (الرقم موقوف أو غير صالح)');
    $base = admin_url('admin.php?page=sgb-outbox');
    echo '<div class="wrap" dir="rtl"><h1>📞 سجل الرقم ' . esc_html($ph) . '</h1>';
    echo '<p><a class="button" href="' . esc_url($base) . '">← رجوع لكل الرسائل</a></p>';
    if (!empty($_GET['done']) && isset($msgs[$_GET['done']])) { echo '<div class="notice notice-info"><p>' . esc_html($msgs[$_GET['done']]) . '</p></div>'; }
    echo '<p style="font-size:15px">' . esc_html($own[1]) . ' &nbsp;·&nbsp; عدد الرسائل: <b>' . count($rows) . '</b></p>';
    if (!$rows) { echo '<p>لا توجد رسائل لهذا الرقم.</p></div>'; return; }
    $n = count($rows);
    foreach ($rows as $i => $r) {
        $ts = $r->sent_at ? (int) $r->sent_at : (int) $r->created;
        $lab = $r->rule_key ? sgb_rule_label($r->rule_key) : 'يدوية';
        $open = in_array($r->status, array('queued', 'qr_pending'), true);
        echo '<div id="m' . (int) $r->id . '" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;margin:10px 0;max-width:820px">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><span><b>' . esc_html(sgb_cairo('l j/n/Y — H:i', $ts)) . '</b> &nbsp; <span style="color:#555">' . esc_html($lab) . '</span></span><b style="color:' . (isset($col[$r->status]) ? $col[$r->status] : '#333') . '">' . esc_html(isset($stl[$r->status]) ? $stl[$r->status] : $r->status) . '</b></div>';
        if ($r->error) { echo '<small style="color:#b32d2e">' . esc_html($r->error) . '</small>'; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
        wp_nonce_field('sgb_msg_edit');
        echo '<input type="hidden" name="action" value="sgb_msg_edit"><input type="hidden" name="id" value="' . (int) $r->id . '">';
        echo '<textarea name="body" rows="' . max(3, min(10, substr_count((string) $r->body, "\n") + 2)) . '" style="width:100%;line-height:1.8"' . ($open ? '' : '') . '>' . esc_textarea($r->body) . '</textarea>';
        echo '<p style="margin:8px 0 0">';
        if ($open) {
            echo '<button class="button button-primary" name="mode" value="save">💾 حفظ التعديل (قبل الإرسال)</button> <button class="button" name="mode" value="cancel" onclick="return confirm(\'إلغاء الرسالة؟\')">🚫 إلغاء</button>';
        } else {
            echo '<button class="button" name="mode" value="resend" onclick="return confirm(\'هتتبعت نسخة جديدة بالنص ده لنفس الرقم. متأكد؟\')">✏️ تعديل وإعادة إرسال كنسخة جديدة</button> <small style="color:#777">الرسالة الأصلية بتفضل في السجل زي ما هي.</small>';
        }
        echo ' &nbsp; ';
        if ($i + 1 < $n) { echo '<a class="button" href="#m' . (int) $rows[$i + 1]->id . '">⬅ السابقة</a> '; }
        if ($i > 0) { echo '<a class="button" href="#m' . (int) $rows[$i - 1]->id . '">التالية ➡</a>'; }
        echo '</p></form></div>';
    }
    echo '</div>';
}

function sgb_outbox_page() {
    if (!current_user_can('manage_options')) { return; }
    if (!empty($_GET['ph'])) { sgb_outbox_phone_view(sanitize_text_field(wp_unslash($_GET['ph']))); return; }
    global $wpdb;
    $t = $wpdb->prefix . 'sgb_outbox';
    $groups = array(
        'all'  => array('الكل', null),
        'wait' => array('⏳ منتظرة', array('queued', 'sending', 'qr_pending')),
        'done' => array('✅ أُرسلت', array('sent', 'delivered', 'read')),
        'ok'   => array('📬 وصلت/قُرئت', array('delivered', 'read')),
        'bad'  => array('❌ فشلت', array('failed')),
        'skip' => array('🚫 ملغاة (مكررة)', array('cancelled')),
    );
    $f = isset($_GET['f']) && isset($groups[$_GET['f']]) ? sanitize_key($_GET['f']) : 'all';
    $q = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
    $auds = array('all' => 'الكل', 'students' => '🎓 الطلاب', 'parents' => '👪 أولياء الأمور', 'staff' => '👨‍🏫 المدرسين والموظفين');
    $aud = isset($_GET['aud']) && isset($auds[$_GET['aud']]) ? sanitize_key($_GET['aud']) : 'all';
    $audw = sgb_aud_where($aud);
    $pg = max(1, isset($_GET['pg']) ? (int) $_GET['pg'] : 1);
    $per = 50;
    $count = function ($sts) use ($wpdb, $t, $audw) {
        if ($sts === null) { return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $audw"); }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $audw AND status IN ('" . implode("','", array_map('esc_sql', $sts)) . "')");
    };
    $midnight = (new DateTime('today', sgb_tz()))->getTimestamp();
    $today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $audw AND sent_at>=%d AND status IN ('sent','delivered','read')", $midnight));
    $where = $audw; $args = array();
    if ($groups[$f][1] !== null) { $where .= " AND status IN ('" . implode("','", array_map('esc_sql', $groups[$f][1])) . "')"; }
    if ($q !== '') { $where .= ' AND (to_phone LIKE %s OR body LIKE %s)'; $like = '%' . $wpdb->esc_like($q) . '%'; $args[] = $like; $args[] = $like; }
    $sqlc = "SELECT COUNT(*) FROM $t WHERE $where";
    $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($sqlc, $args)) : $wpdb->get_var($sqlc));
    $sql = "SELECT id,channel,to_phone,body,rule_key,status,error,created,sent_at FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, array($per, ($pg - 1) * $per))));
    $stl = array('queued' => 'بالانتظار', 'qr_pending' => 'بانتظار الإرسال', 'sending' => 'جاري الإرسال', 'sent' => 'أُرسلت', 'delivered' => 'وصلت', 'read' => 'قُرئت', 'failed' => 'فشلت', 'cancelled' => 'ملغاة');
    $col = array('sent' => '#1c7a3d', 'delivered' => '#1c7a3d', 'read' => '#1c7a3d', 'failed' => '#b32d2e', 'cancelled' => '#777', 'queued' => '#996800', 'qr_pending' => '#996800', 'sending' => '#996800');
    $base = admin_url('admin.php?page=sgb-outbox');

    echo '<div class="wrap" dir="rtl"><h1>📨 سجل الرسائل</h1>';
    echo '<h2 class="nav-tab-wrapper" style="margin-bottom:12px">';
    foreach ($auds as $ak => $al) {
        $ac = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE " . sgb_aud_where($ak));
        echo '<a class="nav-tab' . ($ak === $aud ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(array('aud' => $ak, 'q' => $q), $base)) . '">' . esc_html($al) . ' (' . $ac . ')</a>';
    }
    echo '</h2>';
    echo '<p style="font-size:15px;line-height:2">✅ أُرسلت: <b>' . $count($groups['done'][1]) . '</b> &nbsp;·&nbsp; ⏳ منتظرة: <b>' . $count($groups['wait'][1]) . '</b> &nbsp;·&nbsp; ❌ فشلت: <b>' . $count($groups['bad'][1]) . '</b> &nbsp;·&nbsp; 🚫 ملغاة: <b>' . $count($groups['skip'][1]) . '</b> &nbsp;·&nbsp; أُرسلت اليوم: <b>' . $today . '</b></p>';
    echo '<p>';
    foreach ($groups as $k => $g) {
        $on = $k === $f;
        echo '<a class="button' . ($on ? ' button-primary' : '') . '" style="margin-left:6px" href="' . esc_url(add_query_arg(array('aud' => $aud, 'f' => $k, 'q' => $q), $base)) . '">' . esc_html($g[0]) . ' (' . $count($g[1]) . ')</a>';
    }
    echo '</p><form method="get" style="margin:10px 0"><input type="hidden" name="page" value="sgb-outbox"><input type="hidden" name="f" value="' . esc_attr($f) . '"><input type="hidden" name="aud" value="' . esc_attr($aud) . '"><input name="q" value="' . esc_attr($q) . '" placeholder="ابحث برقم أو بنص الرسالة" style="min-width:280px"> <button class="button">بحث</button></form>';
    echo '<table class="widefat striped"><thead><tr><th style="width:150px">الرقم</th><th style="width:150px">النوع</th><th style="width:150px">الوقت / الحالة</th><th>نص الرسالة</th></tr></thead><tbody>';
    if (!$rows) { echo '<tr><td colspan="4">لا توجد رسائل.</td></tr>'; }
    foreach ($rows as $r) {
        $ts = $r->sent_at ? (int) $r->sent_at : (int) $r->created;
        $lab = $r->rule_key ? sgb_rule_label($r->rule_key) : 'يدوية';
        echo '<tr><td><a href="' . esc_url(add_query_arg('ph', $r->to_phone, $base)) . '" title="سجل هذا الرقم"><b>' . esc_html($r->to_phone) . '</b></a><br><small>' . ($r->channel === 'staff' ? 'مدرس/موظف' : (in_array(substr($r->to_phone, -10), sgb_parent_tails(), true) ? 'ولي أمر' : 'طالب')) . '</small></td><td>' . esc_html($lab) . '</td><td>' . esc_html(sgb_cairo('j/n H:i', $ts)) . '<br><b style="color:' . (isset($col[$r->status]) ? $col[$r->status] : '#333') . '">' . esc_html(isset($stl[$r->status]) ? $stl[$r->status] : $r->status) . '</b>' . ($r->error ? '<br><small style="color:#b32d2e">' . esc_html($r->error) . '</small>' : '') . '</td><td style="white-space:pre-wrap;line-height:1.8">' . esc_html($r->body) . '</td></tr>';
    }
    echo '</tbody></table>';
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        echo '<p style="margin-top:12px">';
        for ($i = 1; $i <= min($pages, 40); $i++) { echo '<a class="button' . ($i === $pg ? ' button-primary' : '') . '" style="margin-left:4px" href="' . esc_url(add_query_arg(array('aud' => $aud, 'f' => $f, 'q' => $q, 'pg' => $i), $base)) . '">' . $i . '</a>'; }
        echo ' <span>(' . $total . ' رسالة)</span></p>';
    }
    echo '</div>';
}
