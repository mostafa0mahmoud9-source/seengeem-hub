<?php
/**
 * الإصدار 2.4: إدارة الرسائل — تعديل نص كل نوع (دائم)، تشغيل/إيقاف كل نوع،
 * وتعديل/إلغاء رسالة منتظرة في الطابور (لمرة واحدة).
 */
if (!defined('ABSPATH')) { exit; }

function sgb_rules_get() {
    $r = get_option('sgb_rules', array());
    return is_array($r) ? $r : array();
}

/** تسجيل آخر نص ومتغيرات لكل قاعدة لعرضها في شاشة الرسائل. */
function sgb_rules_learn($rule, $body, $vars) {
    if (!$rule) { return; }
    $l = get_option('sgb_rules_seen', array());
    if (!is_array($l)) { $l = array(); }
    $l[$rule] = array('sample' => mb_substr((string) $body, 0, 400), 'vars' => array_values(array_map('strval', array_keys((array) $vars))));
    update_option('sgb_rules_seen', $l, false);
}

add_action('rest_api_init', function () {
    $ns = 'sgb/v1';
    register_rest_route($ns, '/rules', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        $rules = sgb_rules_get();
        $seen = get_option('sgb_rules_seen', array());
        $out = array();
        foreach (array_unique(array_merge(array_keys($rules), array_keys((array) $seen))) as $k) {
            $out[] = array(
                'key' => $k,
                'off' => !empty($rules[$k]['off']) ? 1 : 0,
                'text' => isset($rules[$k]['text']) ? (string) $rules[$k]['text'] : '',
                'sample' => isset($seen[$k]['sample']) ? $seen[$k]['sample'] : '',
                'vars' => isset($seen[$k]['vars']) ? $seen[$k]['vars'] : array(),
            );
        }
        return array('rules' => $out);
    }));
    register_rest_route($ns, '/rules', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $k = sanitize_key((string) $req->get_param('key'));
        if ($k === '') { return new WP_Error('bad_key', 'نوع غير صحيح', array('status' => 400)); }
        $rules = sgb_rules_get();
        if (!isset($rules[$k])) { $rules[$k] = array('off' => 0, 'text' => ''); }
        if ($req->get_param('off') !== null) { $rules[$k]['off'] = (int) (bool) $req->get_param('off'); }
        if ($req->get_param('text') !== null) { $rules[$k]['text'] = sanitize_textarea_field(wp_unslash((string) $req->get_param('text'))); }
        if (empty($rules[$k]['off']) && trim((string) $rules[$k]['text']) === '') { unset($rules[$k]); }
        update_option('sgb_rules', $rules, false);
        return array('ok' => true);
    }));
    register_rest_route($ns, '/queue', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        global $wpdb;
        $t = $wpdb->prefix . 'sgb_outbox';
        $rows = $wpdb->get_results("SELECT id, channel, to_phone, rule_key, body, status FROM $t WHERE status IN ('queued','qr_pending') ORDER BY priority DESC, id ASC LIMIT 100", ARRAY_A);
        return array('queue' => $rows ? $rows : array());
    }));
    register_rest_route($ns, '/queue-edit', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'sgb_outbox';
        $id = (int) $req->get_param('id');
        if ($req->get_param('cancel')) {
            $n = $wpdb->query($wpdb->prepare("UPDATE $t SET status='cancelled' WHERE id=%d AND status IN ('queued','qr_pending')", $id));
        } else {
            $body = sanitize_textarea_field(wp_unslash((string) $req->get_param('body')));
            if (trim($body) === '') { return new WP_Error('empty', 'النص فاضي', array('status' => 400)); }
            $n = $wpdb->query($wpdb->prepare("UPDATE $t SET body=%s WHERE id=%d AND status IN ('queued','qr_pending')", $body, $id));
        }
        if (!$n) { return new WP_Error('gone', 'الرسالة اتبعتت أو اتغيّرت حالتها', array('status' => 409)); }
        return array('ok' => true);
    }));
});
