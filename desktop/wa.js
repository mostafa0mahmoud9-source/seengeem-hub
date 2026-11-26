'use strict';
/**
 * محرك واتساب (QR) داخل برنامج الكمبيوتر — جلستان: students / staff.
 * بعد مسح الكود مرة واحدة تبقى الجلسة محفوظة، ويعمل الإرسال تلقائيًا في الخلفية:
 * يسحب الرسائل من الموقع (/pull)، يرسلها بفواصل عشوائية، ويرد التأكيد (/ack) والردود الواردة (/incoming).
 */
const { app, Notification } = require('electron');
const fs = require('fs');
const path = require('path');

const CHANNELS = ['students', 'staff'];
const LABEL = { students: 'رقم الطلاب وأولياء الأمور', staff: 'رقم المدرسين والمتابعة' };
const sleep = ms => new Promise(r => setTimeout(r, ms));
const rnd = (a, b) => a + Math.floor(Math.random() * (b - a + 1));

let cfg = { site: '', key: '', channels: { students: true, staff: true }, linked: {} };
let cfgPath = '', sessDir = '';
const S = {};          // حالة كل قناة
const RT = {};         // كائنات التشغيل (client, gen)
let onChange = () => {};
let QRCode = null, WW = null, started = false;

/**
 * ملف الأمان لكل خط: يتحدد تلقائيًا حسب عمر ربط الرقم بالبرنامج، ويخفّ تدريجيًا.
 * delay = فاصل عشوائي بين الرسائل (ثواني)، every = راحة بعد كل كذا رسالة، rest = مدة الراحة (ثواني).
 */
const PHASES = [
  { upto: 7,  name: 'مرحلة 1: رقم جديد (حذر عالي)',  delay: [20, 45], every: 10, rest: [180, 300] },
  { upto: 14, name: 'مرحلة 2: تسخين',               delay: [15, 35], every: 15, rest: [120, 240] },
  { upto: 30, name: 'مرحلة 3: نشاط متوسط',          delay: [10, 25], every: 20, rest: [120, 240] },
  { upto: 1e9, name: 'مرحلة 4: رقم مستقر',          delay: [7, 18],  every: 20, rest: [120, 240] },
];
function phaseOf(ch) {
  const t = cfg.linked && cfg.linked[ch];
  const days = t ? (Date.now() - t) / 86400000 : 0;
  return PHASES.find(p => days < p.upto);
}

function load() {
  try { cfg = Object.assign(cfg, JSON.parse(fs.readFileSync(cfgPath, 'utf8'))); } catch (e) { /* أول تشغيل */ }
  cfg.linked = cfg.linked || {};
  cfg.channels = Object.assign({ students: true, staff: true }, cfg.channels || {});
}
function save() { try { fs.writeFileSync(cfgPath, JSON.stringify(cfg)); } catch (e) { /* ignore */ } }

function findBrowser() {
  const pf = process.env['ProgramFiles'] || 'C:\\Program Files';
  const pf86 = process.env['ProgramFiles(x86)'] || 'C:\\Program Files (x86)';
  const la = process.env['LOCALAPPDATA'] || '';
  const list = [
    pf + '\\Google\\Chrome\\Application\\chrome.exe', pf86 + '\\Google\\Chrome\\Application\\chrome.exe', la + '\\Google\\Chrome\\Application\\chrome.exe',
    pf86 + '\\Microsoft\\Edge\\Application\\msedge.exe', pf + '\\Microsoft\\Edge\\Application\\msedge.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ];
  return list.find(p => { try { return p && fs.existsSync(p); } catch (e) { return false; } }) || '';
}

function setState(ch, patch) {
  S[ch] = Object.assign(S[ch] || { state: 'idle', qr: null, phone: '', error: '', enabled: true }, patch);
  onChange(getState());
  report(ch);
}
function getState() {
  const out = { configured: !!(cfg.site && cfg.key), channels: {} };
  CHANNELS.forEach(ch => { out.channels[ch] = Object.assign({ label: LABEL[ch], state: 'idle', qr: null, phone: '', error: '' }, S[ch] || {}, { enabled: cfg.channels[ch] !== false, safe: phaseOf(ch).name, ageDays: cfg.linked[ch] ? Math.floor((Date.now() - cfg.linked[ch]) / 86400000) : 0 }); });
  return out;
}

async function api(p, opt) {
  const o = Object.assign({ headers: { 'X-SGB-Key': cfg.key, 'Content-Type': 'application/json' }, signal: AbortSignal.timeout(25000) }, opt || {});
  const r = await fetch(cfg.site.replace(/\/$/, '') + '/wp-json/sgb/v1/' + p, o);
  if (!r.ok) throw new Error(p + ' → HTTP ' + r.status);
  return r.json();
}
function report(ch) {
  if (!cfg.site || !cfg.key || !S[ch]) return;
  api('gw-status', { method: 'POST', body: JSON.stringify({ channel: ch, state: S[ch].state, phone: S[ch].phone, since: cfg.linked[ch] ? Math.floor(cfg.linked[ch] / 1000) : 0 }) }).catch(() => {});
}
function notify(body) { try { new Notification({ title: 'مساعد سين جيم', body }).show(); } catch (e) { /* ignore */ } }

async function startChannel(ch) {
  if (cfg.channels[ch] === false || !cfg.site || !cfg.key) return;
  if (RT[ch] && RT[ch].client) return;
  if (!WW) { try { WW = require('whatsapp-web.js'); } catch (e) { setState(ch, { state: 'error', error: 'مكتبة واتساب غير مثبتة' }); return; } }
  if (!QRCode) { try { QRCode = require('qrcode'); } catch (e) { /* بدون QR صورة */ } }
  const exe = findBrowser();
  if (!exe) { setState(ch, { state: 'no_browser', error: 'ثبّت متصفح Google Chrome أو Microsoft Edge ثم أعد المحاولة' }); return; }
  const gen = ((RT[ch] && RT[ch].gen) || 0) + 1;
  const client = new WW.Client({
    authStrategy: new WW.LocalAuth({ clientId: ch, dataPath: sessDir }),
    puppeteer: { headless: true, executablePath: exe, args: ['--no-first-run', '--no-default-browser-check', '--disable-gpu', '--disable-dev-shm-usage'] },
  });
  RT[ch] = { client, gen };
  setState(ch, { state: 'starting', qr: null, error: '' });
  client.on('qr', async qr => {
    let img = null;
    try { img = QRCode ? await QRCode.toDataURL(qr, { width: 300, margin: 1 }) : null; } catch (e) { /* ignore */ }
    setState(ch, { state: 'qr', qr: img, error: '' });
  });
  client.on('authenticated', () => setState(ch, { state: 'starting', qr: null }));
  client.on('auth_failure', m => setState(ch, { state: 'error', qr: null, error: 'فشل التوثيق: ' + m }));
  client.on('ready', () => {
    const ph = client.info && client.info.wid ? client.info.wid.user : '';
    if (!cfg.linked[ch]) { cfg.linked[ch] = Date.now(); save(); } // بداية عدّ عمر الخط
    setState(ch, { state: 'ready', qr: null, phone: ph, error: '' });
    notify('تم ربط ' + LABEL[ch] + (ph ? ' (' + ph + ')' : '') + ' ✅');
    loop(ch, gen);
  });
  client.on('disconnected', reason => {
    setState(ch, { state: 'disconnected', qr: null, error: String(reason || '') });
    notify('انفصل ' + LABEL[ch] + '، افتح البرنامج وأعد الربط إن لزم');
    restartLater(ch, gen);
  });
  client.on('message', async m => {
    try {
      if (m.fromMe || m.isStatus || (m.from || '').endsWith('@g.us') || (m.from || '').endsWith('@broadcast')) return;
      await api('incoming', { method: 'POST', body: JSON.stringify({ channel: ch, from: (m.from || '').split('@')[0], body: m.body || '' }) });
    } catch (e) { /* ignore */ }
  });
  client.initialize().catch(e => { setState(ch, { state: 'error', error: String(e && e.message || e) }); restartLater(ch, gen); });
}

function restartLater(ch, gen) {
  setTimeout(async () => {
    if (!RT[ch] || RT[ch].gen !== gen) return;
    await stopChannel(ch, false);
    if (cfg.channels[ch] !== false) startChannel(ch);
  }, 30000);
}

async function stopChannel(ch, keepState) {
  const r = RT[ch];
  if (r && r.client) { r.gen++; const c = r.client; r.client = null; try { await c.destroy(); } catch (e) { /* ignore */ } }
  if (!keepState && S[ch]) { /* الحالة تبقى حتى إعادة التشغيل */ }
}

async function loop(ch, gen) {
  let sentRun = 0, fails = 0;
  while (RT[ch] && RT[ch].gen === gen && RT[ch].client && S[ch] && S[ch].state === 'ready') {
    try {
      const items = await api('pull?channel=' + ch);
      for (const it of items) {
        if (!RT[ch] || RT[ch].gen !== gen) return;
        const ph = phaseOf(ch);
        let slow = fails >= 2 ? 2 : 1;               // تبطيء تلقائي بعد فشلين متتاليين
        try {
          const num = await RT[ch].client.getNumberId(it.to_phone);
          if (!num) throw new Error('الرقم غير مسجل على واتساب');
          // محاكاة بشرية: «يكتب...» لمدة قصيرة قبل الإرسال
          try { const chat = await RT[ch].client.getChatById(num._serialized); await chat.sendStateTyping(); await sleep(rnd(1500, 4000)); } catch (e) { /* ليست أساسية */ }
          const msg = await RT[ch].client.sendMessage(num._serialized, it.body);
          await api('ack', { method: 'POST', body: JSON.stringify({ id: it.id, ok: true, wa_id: msg && msg.id && msg.id._serialized }) });
          sentRun++; fails = 0;
        } catch (e) {
          const m = String(e && e.message || e);
          if (m.indexOf('غير مسجل') < 0) fails++;     // رقم غير موجود ليس مؤشر مشكلة في الخط
          await api('ack', { method: 'POST', body: JSON.stringify({ id: it.id, ok: false, error: m.slice(0, 200) }) }).catch(() => {});
        }
        if (fails >= 4) {                              // فشل متكرر: وقف نصف ساعة لحماية الرقم
          notify('توقف الإرسال على ' + LABEL[ch] + ' نصف ساعة لتكرار الفشل. راجع الاتصال.');
          setState(ch, { error: 'توقف مؤقت لتكرار الفشل (نصف ساعة)' });
          fails = 0; await sleep(30 * 60000); setState(ch, { error: '' });
          break;
        }
        await sleep(rnd(ph.delay[0], ph.delay[1]) * 1000 * slow);
        if (sentRun > 0 && sentRun % ph.every === 0) { await sleep(rnd(ph.rest[0], ph.rest[1]) * 1000); }
      }
    } catch (e) { /* مشكلة اتصال مؤقتة */ }
    await sleep(10000);
  }
}

function init(opts) {
  onChange = opts.onChange || onChange;
  cfgPath = path.join(app.getPath('userData'), 'wa-config.json');
  sessDir = path.join(app.getPath('userData'), 'wa-sessions');
  load();
  CHANNELS.forEach(ch => { S[ch] = { state: 'idle', qr: null, phone: '', error: '' }; });
  started = true;
  if (cfg.site && cfg.key) CHANNELS.forEach((ch, i) => setTimeout(() => startChannel(ch), 3000 + i * 6000));
  setInterval(() => CHANNELS.forEach(ch => { if (S[ch] && S[ch].state !== 'idle') report(ch); }), 60000);
}

async function setConfig(c) {
  if (!c || typeof c.site !== 'string' || typeof c.key !== 'string') return getState();
  const changed = c.site !== cfg.site || c.key !== cfg.key;
  cfg.site = c.site.trim(); cfg.key = c.key.trim();
  if (c.channels) cfg.channels = Object.assign(cfg.channels, c.channels);
  save();
  if (started && changed) CHANNELS.forEach((ch, i) => setTimeout(() => startChannel(ch), 500 + i * 6000));
  return getState();
}
async function connect(ch) {
  if (!CHANNELS.includes(ch)) return getState();
  cfg.channels[ch] = true; save();
  await stopChannel(ch, true);
  await startChannel(ch);
  return getState();
}
async function disable(ch) {
  if (!CHANNELS.includes(ch)) return getState();
  cfg.channels[ch] = false; save();
  await stopChannel(ch, true);
  setState(ch, { state: 'idle', qr: null, phone: '' });
  return getState();
}
async function logout(ch) {
  if (!CHANNELS.includes(ch)) return getState();
  const r = RT[ch];
  if (r && r.client) { try { await r.client.logout(); } catch (e) { /* ignore */ } }
  await stopChannel(ch, true);
  try { fs.rmSync(path.join(sessDir, 'session-' + ch), { recursive: true, force: true }); } catch (e) { /* ignore */ }
  setState(ch, { state: 'idle', qr: null, phone: '' });
  if (cfg.channels[ch] !== false) startChannel(ch);
  return getState();
}
async function setAge(ch, days) {
  if (!CHANNELS.includes(ch)) return getState();
  const d = Math.max(0, Math.min(3650, Number(days) || 0));
  cfg.linked[ch] = Date.now() - d * 86400000; save();
  setState(ch, {});
  return getState();
}
async function shutdown() { for (const ch of CHANNELS) { await stopChannel(ch, true); } }
function summary() {
  const st = getState().channels;
  return CHANNELS.map(ch => (ch === 'students' ? 'الطلاب' : 'المدرسين') + ': ' + ({ ready: 'متصل', qr: 'بانتظار الكود', starting: 'جاري التشغيل', idle: 'غير مربوط', disconnected: 'منفصل', error: 'خطأ', no_browser: 'يحتاج Chrome' }[st[ch].state] || st[ch].state)).join(' | ');
}

module.exports = { init, setConfig, getState, connect, disable, logout, setAge, shutdown, summary };
