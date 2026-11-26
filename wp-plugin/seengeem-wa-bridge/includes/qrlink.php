<?php
/**
 * الإصدار 2.6: ربط الواتساب بكود QR يظهر داخل ووردبريس.
 * برنامج الكمبيوتر يولّد الكود ويرسله للموقع، والموقع يعرضه ويتحدّث تلقائيًا، وعند المسح تتحول الحالة إلى «متصل».
 */
if (!defined('ABSPATH')) { exit; }

/** يحفظ صورة الكود القادمة من البرنامج (تُمسح عند تغيّر الحالة). */
function sgb_qr_store($ch, $state, $qr) {
    if ($state === 'qr' && is_string($qr) && strpos($qr, 'data:image/png;base64,') === 0 && strlen($qr) < 40000) { set_transient('sgb_qr_' . $ch, $qr, 120); }
    else { delete_transient('sgb_qr_' . $ch); }
}

add_action('rest_api_init', function () {
    $ns = 'sgb/v1';
    register_rest_route($ns, '/qr-state', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function () {
        $gw = (array) get_option('sgb_gw', array());
        $out = array(); $online = false;
        foreach (array('students', 'staff') as $ch) {
            $g = isset($gw[$ch]) ? (array) $gw[$ch] : array();
            $age = !empty($g['ts']) ? time() - (int) $g['ts'] : null;
            $fresh = $age !== null && $age < 200;
            $state = $fresh && !empty($g['state']) ? $g['state'] : 'offline';
            if ($fresh) { $online = true; }
            $qr = $state === 'qr' ? get_transient('sgb_qr_' . $ch) : '';
            $out[$ch] = array('state' => $state, 'phone' => isset($g['phone']) ? (string) $g['phone'] : '', 'qr' => $qr ? $qr : '');
        }
        return array('channels' => $out, 'online' => $online);
    }));
    register_rest_route($ns, '/qr-cmd', array('methods' => 'POST', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $ch = $req->get_param('channel') === 'staff' ? 'staff' : 'students';
        $cmd = $req->get_param('cmd');
        if (!in_array($cmd, array('connect', 'logout'), true)) { return new WP_Error('bad_cmd', 'أمر غير صحيح', array('status' => 400)); }
        $c = (array) get_option('sgb_gw_cmd', array());
        $c[$ch] = $cmd;
        update_option('sgb_gw_cmd', $c, false);
        return array('ok' => true);
    }));
    register_rest_route($ns, '/gw-cmd', array('methods' => 'GET', 'permission_callback' => 'sgb_auth', 'callback' => function ($req) {
        $ch = $req->get_param('channel') === 'staff' ? 'staff' : 'students';
        $c = (array) get_option('sgb_gw_cmd', array());
        $cmd = isset($c[$ch]) ? $c[$ch] : '';
        if ($cmd !== '') { unset($c[$ch]); update_option('sgb_gw_cmd', $c, false); }
        return array('cmd' => $cmd);
    }));
});

/** كود ربط قصير (6 أرقام) صالح 30 دقيقة: يكتبه المستخدم في البرنامج فيتربط بالموقع بدون نسخ مفاتيح. */
function sgb_pair_code($fresh = false) {
    $c = get_transient('sgb_pair');
    if ($fresh || !$c) { $c = str_pad((string) wp_rand(0, 999999), 6, '0', STR_PAD_LEFT); set_transient('sgb_pair', $c, 1800); }
    return $c;
}
add_action('rest_api_init', function () {
    register_rest_route('sgb/v1', '/pair', array('methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ($req) {
        $n = (int) get_transient('sgb_pair_try');
        if ($n >= 10) { return new WP_Error('too_many', 'محاولات كثيرة، انتظر 10 دقائق أو أنشئ كودًا جديدًا', array('status' => 429)); }
        set_transient('sgb_pair_try', $n + 1, 600);
        $c = get_transient('sgb_pair'); $p = preg_replace('/\D/', '', (string) $req->get_param('p'));
        if (!$c || strlen($p) !== 6 || !hash_equals($c, $p)) { return new WP_Error('bad_code', 'كود الربط غير صحيح أو انتهت صلاحيته، اعمل كود جديد من صفحة ووردبريس', array('status' => 403)); }
        $s = sgb_settings();
        delete_transient('sgb_pair'); delete_transient('sgb_pair_try');
        return array('u' => home_url(), 'k' => $s['api_key']);
    }));
});

/** كود إعداد البرنامج (رابط الموقع + مفتاح الربط) لربط البرنامج بالموقع بضغطة واحدة. */
function sgb_setup_code() {
    $s = sgb_settings();
    return rtrim(strtr(base64_encode(wp_json_encode(array('u' => home_url(), 'k' => $s['api_key']))), '+/', '-_'), '=');
}

/** واجهة الـ QR (تُستخدم في صفحة «ربط الواتساب» وفي إعدادات «مساعد سين جيم»). */
function sgb_qr_widget() {
    if (isset($_GET['sgb_newpair']) && current_user_can('manage_options')) { sgb_pair_code(true); }
    $code = sgb_setup_code();
    $rest = esc_url_raw(rest_url('sgb/v1/'));
    $nonce = wp_create_nonce('wp_rest');
    ?>
<div id="sgb-qr" dir="rtl" style="background:#fff;border:1px solid #d9d4ee;border-radius:14px;padding:18px;margin:14px 0;max-width:980px">
  <h2 style="margin-top:0">📱 ربط الواتساب بكود QR</h2>
  <p style="color:#555">افتح واتساب على موبايل الرقم ← <b>الأجهزة المرتبطة</b> ← <b>ربط جهاز</b> ← وجّه الكاميرا على الكود. الربط بيتم تلقائيًا ويتحدّث هنا لوحده.</p>
  <div id="sgb-off" style="display:none;background:#fff6e0;border:1px solid #f0d9a0;border-radius:10px;padding:12px;margin:10px 0">
    <b>برنامج الكمبيوتر غير متصل بالموقع.</b>
    <div style="margin:10px 0;text-align:center"><div style="font-size:13px;color:#555">أسهل طريقة: افتح البرنامج واكتب هذا الكود في «كود الربط»:</div>
      <div style="font-size:34px;letter-spacing:8px;font-weight:700;direction:ltr;color:#4b2a9a;margin:6px 0"><?php echo esc_html(sgb_pair_code()); ?></div>
      <a href="<?php echo esc_url(add_query_arg('sgb_newpair', '1')); ?>" style="font-size:12px">كود جديد</a> · <span style="font-size:12px;color:#888">صالح 30 دقيقة</span></div>
    <div style="margin-top:6px">أو: <a class="button button-primary" href="seengeem://setup?c=<?php echo esc_attr($code); ?>">🔗 ربط البرنامج بالموقع تلقائيًا</a> (لو البرنامج مثبّت) &nbsp; أو &nbsp; <a class="button" href="https://github.com/mostafa0mahmoud9-source/seengeem-hub/releases/latest" target="_blank" rel="noopener">⬇ تحميل البرنامج</a></div>
    <div style="margin-top:8px">2) أو الصق هذا الكود في البرنامج: «المزيد» ← «كود الإعداد السريع»:<br>
      <input type="text" readonly id="sgb-code" value="<?php echo esc_attr($code); ?>" style="width:100%;max-width:560px;direction:ltr" onclick="this.select()"></div>
  </div>
  <div id="sgb-cards" style="display:flex;gap:16px;flex-wrap:wrap"></div>
  <p style="color:#888;font-size:12px;margin-bottom:0">البرنامج لازم يفضل شغال على الكمبيوتر علشان الإرسال يستمر. ربط Meta Cloud API الرسمي اختياري ولاحقًا.</p>
</div>
<script>
(function(){
  var REST=<?php echo wp_json_encode($rest); ?>,NONCE=<?php echo wp_json_encode($nonce); ?>;
  var LBL={students:'واتساب الطلاب وأولياء الأمور',staff:'واتساب المدرسين والمتابعين'};
  var TXT={starting:'جاري التشغيل... (قد يأخذ دقيقة)',idle:'غير مربوط',disconnected:'انفصل، جاري إعادة الاتصال',error:'حدث خطأ',no_browser:'محتاج Google Chrome أو Edge على الكمبيوتر',offline:'البرنامج غير متصل'};
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
  function cmd(ch,c){if(c==='logout'&&!confirm('فصل الرقم الحالي وربط رقم آخر؟'))return;
    fetch(REST+'qr-cmd',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'},body:JSON.stringify({channel:ch,cmd:c})}).then(function(){setTimeout(tick,1500)})}
  window.sgbQrCmd=cmd;
  function card(ch,g){
    var h='<div style="flex:1;min-width:290px;border:1px solid #e3e0f2;border-radius:12px;padding:14px;text-align:center"><b>'+esc(LBL[ch])+'</b>';
    if(g.state==='ready')h+='<p style="color:#0a7d33;font-size:18px;margin:14px 0">متصل ✔<br><small>'+esc(g.phone)+'</small></p><button type="button" class="button" onclick="sgbQrCmd(\''+ch+'\',\'logout\')">فصل وربط رقم آخر</button>';
    else if(g.state==='qr'&&g.qr)h+='<div style="margin:10px 0"><img alt="QR" src="'+g.qr+'" style="width:240px;max-width:100%;border:1px solid #eee;border-radius:8px"></div><small style="color:#666">امسح الكود بواتساب الرقم (بيتجدد لوحده)</small>';
    else{h+='<p style="color:#b32d2e;margin:16px 0">'+esc(TXT[g.state]||g.state)+'</p>';if(g.state!=='offline'&&g.state!=='starting')h+='<button type="button" class="button button-primary" onclick="sgbQrCmd(\''+ch+'\',\'connect\')">إظهار كود الربط</button>'}
    return h+'</div>'}
  function tick(){
    fetch(REST+'qr-state',{credentials:'same-origin',headers:{'X-WP-Nonce':NONCE},cache:'no-store'}).then(function(r){return r.json()}).then(function(d){
      document.getElementById('sgb-off').style.display=d.online?'none':'block';
      document.getElementById('sgb-cards').innerHTML=['students','staff'].map(function(ch){return card(ch,d.channels[ch])}).join('')
    }).catch(function(){})}
  tick();setInterval(function(){if(!document.hidden)tick()},3000);
})();
</script>
    <?php
}

/** في إعدادات «مساعد سين جيم» القديمة: نخفي خانات Cloud API ونعرض الـ QR بدلها. */
add_action('admin_footer', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'sgh-assistant' || !current_user_can('manage_options')) { return; }
    echo '<div id="sgb-qr-holder" style="display:none">';
    sgb_qr_widget();
    echo '</div>';
    ?>
<script>
(function(){
  var holder=document.getElementById('sgb-qr-holder'),n=0;
  function findH(t){var els=document.querySelectorAll('h1,h2,h3,h4,h5,legend,strong,b,span,p,div,label');
    for(var i=0;i<els.length;i++){var x=els[i].textContent.trim();if(x.indexOf(t)===0&&x.length<t.length+14&&!holder.contains(els[i]))return els[i]}return null}
  function card(h,other){var e=h;while(e.parentElement&&!e.parentElement.contains(other))e=e.parentElement;return e}
  var iv=setInterval(function(){
    n++;var a=findH('واتساب الطلاب وأولياء الأمور'),b=findH('واتساب المدرسين والمتابعين');
    if(!(a&&b)&&n<15)return;
    clearInterval(iv);var w=holder.firstElementChild;
    if(a&&b){var ca=card(a,b),cb=card(b,a);ca.parentNode.insertBefore(w,ca);ca.style.display='none';cb.style.display='none';
      var t=document.createElement('p');t.innerHTML='<a href="#" style="font-size:12px">إظهار خانات Meta Cloud API القديمة</a>';
      t.firstChild.onclick=function(ev){ev.preventDefault();var s=ca.style.display==='none'?'':'none';ca.style.display=s;cb.style.display=s};w.parentNode.insertBefore(t,ca)}
    else{var wrap=document.querySelector('#wpbody-content .wrap')||document.querySelector('#wpbody-content');wrap.insertBefore(w,wrap.firstChild)}
  },400);
})();
</script>
    <?php
});
