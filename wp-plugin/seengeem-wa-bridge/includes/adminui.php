<?php
/**
 * تنسيق موحّد لصفحات «منصة سين جيم» (sgp-*) في لوحة ووردبريس:
 * عرض كامل بدل العمود الضيق، شبكة منتظمة، كروت بنفس الحجم، مسافات ثابتة.
 * لا يغيّر أي بيانات، تنسيق فقط (CSS) — يمكن تعطيله بإضافة define('SGB_NO_ADMIN_UI', true) في wp-config.php.
 */
if (!defined('ABSPATH')) { exit; }

add_action('admin_head', function () {
    if (defined('SGB_NO_ADMIN_UI') && SGB_NO_ADMIN_UI) { return; }
    $pg = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if (strpos($pg, 'sgp-') !== 0) { return; }
    $f = __DIR__ . '/adminui.css';
    if (is_readable($f)) { echo '<style id="sgb-admin-ui">' . file_get_contents($f) . '</style>'; }   // phpcs:ignore
});
