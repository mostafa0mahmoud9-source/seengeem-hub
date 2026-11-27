<?php
/** صفحة «سجل الرسائل» في ووردبريس: عدّادات المرسل والمنتظر ونص كل رسالة ظاهر بالكامل. */
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function () {
    $cb = 'sgb_outbox_page';
    if (function_exists('sgb_menu_parent') && sgb_menu_parent() === 'sgh-assistant') { add_submenu_page('sgh-assistant', 'سجل الرسائل', '📨 سجل الرسائل', 'manage_options', 'sgb-outbox', $cb); }
    else { add_submenu_page('sgb', 'سجل الرسائل', '📨 سجل الرسائل', 'manage_options', 'sgb-outbox', $cb); }
}, 102);

function sgb_outbox_page() {
    if (!current_user_can('manage_options')) { return; }
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
    $pg = max(1, isset($_GET['pg']) ? (int) $_GET['pg'] : 1);
    $per = 50;
    $count = function ($sts) use ($wpdb, $t) {
        if ($sts === null) { return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t"); }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status IN ('" . implode("','", array_map('esc_sql', $sts)) . "')");
    };
    $midnight = (new DateTime('today', sgb_tz()))->getTimestamp();
    $today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE sent_at>=%d AND status IN ('sent','delivered','read')", $midnight));
    $where = '1=1'; $args = array();
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
    echo '<p style="font-size:15px;line-height:2">✅ أُرسلت: <b>' . $count($groups['done'][1]) . '</b> &nbsp;·&nbsp; ⏳ منتظرة: <b>' . $count($groups['wait'][1]) . '</b> &nbsp;·&nbsp; ❌ فشلت: <b>' . $count($groups['bad'][1]) . '</b> &nbsp;·&nbsp; 🚫 ملغاة: <b>' . $count($groups['skip'][1]) . '</b> &nbsp;·&nbsp; أُرسلت اليوم: <b>' . $today . '</b></p>';
    echo '<p>';
    foreach ($groups as $k => $g) {
        $on = $k === $f;
        echo '<a class="button' . ($on ? ' button-primary' : '') . '" style="margin-left:6px" href="' . esc_url(add_query_arg(array('f' => $k, 'q' => $q), $base)) . '">' . esc_html($g[0]) . ' (' . $count($g[1]) . ')</a>';
    }
    echo '</p><form method="get" style="margin:10px 0"><input type="hidden" name="page" value="sgb-outbox"><input type="hidden" name="f" value="' . esc_attr($f) . '"><input name="q" value="' . esc_attr($q) . '" placeholder="ابحث برقم أو بنص الرسالة" style="min-width:280px"> <button class="button">بحث</button></form>';
    echo '<table class="widefat striped"><thead><tr><th style="width:150px">الرقم</th><th style="width:150px">النوع</th><th style="width:150px">الوقت / الحالة</th><th>نص الرسالة</th></tr></thead><tbody>';
    if (!$rows) { echo '<tr><td colspan="4">لا توجد رسائل.</td></tr>'; }
    foreach ($rows as $r) {
        $ts = $r->sent_at ? (int) $r->sent_at : (int) $r->created;
        $lab = $r->rule_key ? sgb_rule_label($r->rule_key) : 'يدوية';
        echo '<tr><td><b>' . esc_html($r->to_phone) . '</b><br><small>' . ($r->channel === 'staff' ? 'المدرسين' : 'الطلاب') . '</small></td><td>' . esc_html($lab) . '</td><td>' . esc_html(sgb_cairo('j/n H:i', $ts)) . '<br><b style="color:' . (isset($col[$r->status]) ? $col[$r->status] : '#333') . '">' . esc_html(isset($stl[$r->status]) ? $stl[$r->status] : $r->status) . '</b>' . ($r->error ? '<br><small style="color:#b32d2e">' . esc_html($r->error) . '</small>' : '') . '</td><td style="white-space:pre-wrap;line-height:1.8">' . esc_html($r->body) . '</td></tr>';
    }
    echo '</tbody></table>';
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        echo '<p style="margin-top:12px">';
        for ($i = 1; $i <= min($pages, 40); $i++) { echo '<a class="button' . ($i === $pg ? ' button-primary' : '') . '" style="margin-left:4px" href="' . esc_url(add_query_arg(array('f' => $f, 'q' => $q, 'pg' => $i), $base)) . '">' . $i . '</a>'; }
        echo ' <span>(' . $total . ' رسالة)</span></p>';
    }
    echo '</div>';
}
