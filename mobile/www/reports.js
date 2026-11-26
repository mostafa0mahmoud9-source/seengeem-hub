/* تبويب التقارير والاستيراد — يُحمَّل بعد index.html */
(function () {
  const REASON = { no_consent: 'بدون موافقة مسجّلة', opted_out: 'طلب الإيقاف', paused: 'موقوف مؤقتًا', not_due: 'لم يحن موعده', no_phone: 'بدون رقم', bad_phone: 'رقم غير صالح', contact_cap: 'تجاوز حد الرقم اليومي', status: 'حالة غير مؤهلة', no_contact: 'جهة غير معروفة', no_marketing: 'بدون موافقة تسويق', empty: 'نص فارغ', duplicate: 'مكررة', optout: 'إيقاف' };
  const nav = document.getElementById('nav');
  const btn = document.createElement('button');
  btn.dataset.t = 'rep'; btn.textContent = 'التقارير';
  nav.insertBefore(btn, nav.querySelector('[data-t="assist"]'));
  btn.onclick = () => show('rep');
  const sec = document.createElement('section');
  sec.id = 'rep'; sec.className = 'hide';
  sec.innerHTML = '<div class="card" id="imp"></div><div class="card"><b>الإرسال آخر 7 أيام</b><div id="bars" style="display:flex;gap:6px;align-items:flex-end;height:120px;margin-top:10px"></div></div><div class="card"><b>حسب نوع الرسالة (آخر 7 أيام)</b><div id="rules"></div></div>';
  document.querySelector('main').appendChild(sec);

  async function post(p, body) { return api(p, { method: 'POST', body: JSON.stringify(body || {}) }); }
  window.impSet = async (o) => { try { await post('import-settings', o); loadRep(); } catch (e) { alert(e.message); } };
  window.impRun = async () => { try { const r = await post('import-run'); alert('أُضيف ' + r.queued + ' من ' + r.seen + (r.error ? '\nخطأ: ' + r.error : '')); loadRep(); load(); } catch (e) { alert(e.message); } };

  async function loadRep() {
    if (!S.url || !S.key) return;
    try {
      const d = await api('report'), im = d.import, last = im.last;
      const sk = last && last.skipped ? Object.entries(last.skipped).map(([k, n]) => '<div class="row"><span>' + esc(REASON[k] || k) + '</span><b>' + n + '</b></div>').join('') : '';
      $('imp').innerHTML = '<b>استيراد رسائل المساعد</b>' +
        '<div class="row"><span>الإرسال التلقائي</span><span class="' + (im.mode === 'auto' ? 'ok' : 'err') + '">' + (im.mode === 'auto' ? 'مفعّل' : 'متوقف') + '</span></div>' +
        '<div class="row"><span>اشتراط الموافقة</span><span>' + (im.require_consent ? 'نعم' : 'لا') + '</span></div>' +
        '<div class="row"><span>أقصى رسائل لكل رقم يوميًا</span><span>' + im.per_contact_cap + '</span></div>' +
        '<div style="margin:10px 0"><button class="btn" onclick="impSet({mode:\'' + (im.mode === 'auto' ? 'off' : 'auto') + '\'})">' + (im.mode === 'auto' ? 'إيقاف التلقائي' : 'تفعيل التلقائي') + '</button> ' +
        '<button class="btn alt" onclick="impSet({require_consent:' + (im.require_consent ? 'false' : 'true') + '})">' + (im.require_consent ? 'إلغاء اشتراط الموافقة' : 'اشتراط الموافقة') + '</button> ' +
        '<button class="btn alt" onclick="impRun()">استيراد الآن</button></div>' +
        (last ? '<div class="lbl">آخر تشغيل: ' + new Date(last.time * 1000).toLocaleString('ar-EG') + ' — فُحص ' + last.seen + ' وأُضيف ' + last.queued + (last.error ? ' — <span class="err">' + esc(last.error) + '</span>' : '') + '</div>' + sk : '<div class="lbl">لم يُشغَّل بعد</div>');
      const vals = Object.values(d.daily), mx = Math.max(1, ...vals);
      $('bars').innerHTML = Object.entries(d.daily).map(([day, n]) => '<div style="flex:1;text-align:center;font-size:11px"><div style="background:var(--p2);height:' + Math.round(n / mx * 90) + 'px;border-radius:6px 6px 0 0;min-height:2px"></div>' + n + '<br>' + day.slice(5) + '</div>').join('');
      const g = {};
      d.by_rule.forEach(r => { const o = g[r.rule_key] = g[r.rule_key] || { ok: 0, bad: 0, wait: 0 }; const n = +r.n; if (['sent', 'delivered', 'read'].includes(r.status)) o.ok += n; else if (r.status === 'failed') o.bad += n; else o.wait += n; });
      $('rules').innerHTML = Object.entries(g).map(([k, o]) => '<div class="row"><span>' + esc(k) + '</span><span><span class="ok">' + o.ok + ' أُرسلت</span> · <span class="err">' + o.bad + ' فشلت</span> · ' + o.wait + ' منتظرة</span></div>').join('') || '<div class="lbl">لا توجد بيانات</div>';
    } catch (e) { $('imp').innerHTML = '<span class="err">' + esc(e.message) + '</span>'; }
  }
  const baseLoad = window.load;
  window.load = function () { baseLoad(); loadRep(); };
  window.loadRep = loadRep;
  loadRep();
})();
