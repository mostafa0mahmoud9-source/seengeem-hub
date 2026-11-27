'use strict';
/**
 * محرك واتساب (QR) داخل برنامج الكمبيوتر — جلستان: students / staff.
 * بعد مسح الكود مرة واحدة تبقى الجلسة محفوظة، ويعمل الإرسال تلقائيًا في الخلفية:
 * يسحب الرسائل من الموقع (/pull)، يرسلها بفواصل عشوائية، ويرد التأكيد (/ack) والردود الواردة (/incoming).
 */
const { app, Notification } = require('electron');
const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');

const CHANNELS = ['students', 'staff'];
const LABEL = { students: 'رقم الطلاب وأولياء الأمور', staff: 'رقم المدرسين والمتابعة' };
const sleep = ms => new Promise(r => setTimeout(r, ms));
const rnd = (a, b) => a + Math.floor(Math.random() * (b - a + 1));

let cfg = { site: '', key: '', channels: { students: true, staff: true }, linked: {} };
let cfgPath = '', sessDir = '';
const S = {};          // حالة كل قناة
const RT = {};         // كائنات التشغيل (client, gen)
let onChange = () => {};
let QRCode = null, WW = null, started = false, forceNext = false;

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
  if (!cfg.inst) { cfg.inst = crypto.randomUUID(); try { fs.writeFileSync(cfgPath, JSON.stringify(cfg)); } catch (e) { /* ignore */ } }
  cfg.linked = cfg.linked || {};
  cfg.channels = Object.assign({ students: true, staff: true }, cfg.channels || {});
}
function save() { try { fs.writeFileSync(cfgPath, JSON.stringify(cfg)); } catch (e) { /* ignore */ } }

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
  const force = !!forceNext;
  api('gw-status', { method: 'POST', body: JSON.stringify({ channel: ch, state: S[ch].state, phone: S[ch].phone, qr: S[ch].state === 'qr' ? (S[ch].qr || '') : '', since: cfg.linked[ch] ? Math.floor(cfg.linked[ch] / 1000) : 0, inst: cfg.inst, host: os.hostname(), force: force ? 1 : 0 }) })
    .then(r => { if (force) forceNext = false; const st = !!(r && r.standby); if (S[ch] && (!!S[ch].standby !== st || (st && S[ch].holder !== (r.host || '')))) { S[ch].standby = st; S[ch].holder = st ? (r.host || '') : ''; onChange(getState()); } })
    .catch(() => {});
}
function notify(body) { try { new Notification({ title: 'مساعد سين جيم', body }).show(); } catch (e) { /* ignore */ } }

function wlog(m) { try { fs.appendFileSync(path.join(app.getPath('userData'), 'wa-log.txt'), new Date().toISOString() + ' ' + m + '\n'); } catch (e) { /* ignore */ } }
const ATT = {};
const quiet = { level: 'silent', trace() {}, debug() {}, info() {}, warn() {}, error() {}, fatal() {}, child() { return quiet; } };
async function diag(ch, err) {
  try {
    let tail = ''; try { tail = fs.readFileSync(path.join(app.getPath('userData'), 'wa-log.txt'), 'utf8').slice(-1500); } catch (e) { /* ignore */ }
    await api('diag', { method: 'POST', body: JSON.stringify({ ch, t: Date.now(), v: app.getVersion(), engine: 'baileys', err: String(err && err.message || err).slice(0, 300), log: tail }) });
  } catch (e) { /* ignore */ }
}
function digits(v) { return String(v || '').split('@')[0].split(':')[0].replace(/\D/g, ''); }

async function startChannel(ch) {
  if (cfg.channels[ch] === false) return;
  if (RT[ch] && RT[ch].client) return;
  if (!WW) {
    let e1 = null;
    try { WW = require('@whiskeysockets/baileys'); } catch (e) { e1 = e; }
    if (!WW) { try { WW = await import('@whiskeysockets/baileys'); } catch (e2) { const m = String((e1 && e1.message) || e1) + ' | ' + String(e2 && e2.message || e2); wlog('require: ' + m); diag(ch, new Error('load: ' + m)); setState(ch, { state: 'error', error: 'تعذر تحميل مكتبة واتساب: ' + m.slice(0, 160) }); RT[ch] = RT[ch] || { client: null, gen: 0 }; restartLater(ch, RT[ch].gen, 30000); return; } }
  }
  if (!QRCode) { try { QRCode = require('qrcode'); } catch (e) { /* بدون QR صورة */ } }
  const gen = ((RT[ch] && RT[ch].gen) || 0) + 1;
  const pairPhone = RT[ch] && RT[ch].pairPhone;
  RT[ch] = { client: null, gen, pairPhone };
  setState(ch, { state: 'starting', qr: null, error: '', pairCode: '' });
  try {
    const makeSock = WW.default || WW.makeWASocket;
    const dir = path.join(sessDir, 'bd-' + ch);
    fs.mkdirSync(dir, { recursive: true });
    const { state, saveCreds } = await WW.useMultiFileAuthState(dir);
    let version; try { version = (await WW.fetchLatestBaileysVersion()).version; } catch (e) { /* افتراضي المكتبة */ }
    const B = WW.Browsers;
    const sock = makeSock({
      auth: state, logger: quiet, printQRInTerminal: false, syncFullHistory: false, markOnlineOnConnect: false,
      generateHighQualityLinkPreview: false, connectTimeoutMs: 60000,
      browser: B && B.windows ? B.windows('Chrome') : ['Windows', 'Chrome', '10'],
      ...(version ? { version } : {}),
    });
    if (RT[ch].gen !== gen) { try { sock.end(undefined); } catch (e) { /* ignore */ } return; }
    RT[ch].client = sock;
    sock.ev.on('creds.update', saveCreds);
    let pairAsked = false;
    sock.ev.on('connection.update', async u => {
      if (!RT[ch] || RT[ch].gen !== gen) return;
      const { connection, lastDisconnect, qr } = u;
      if (qr) {
        if (RT[ch].pairPhone && !state.creds.registered) {
          if (!pairAsked) { pairAsked = true; try { const code = await sock.requestPairingCode(RT[ch].pairPhone); setState(ch, { state: 'qr', qr: null, pairCode: code, error: '' }); } catch (e) { setState(ch, { state: 'error', error: 'تعذر طلب كود الربط: ' + e.message }); } }
        } else {
          let img = null;
          try { img = QRCode ? await QRCode.toDataURL(qr, { width: 300, margin: 1 }) : null; } catch (e) { /* ignore */ }
          setState(ch, { state: 'qr', qr: img, pairCode: '', error: '' });
        }
      }
      if (connection === 'open') {
        const ph = digits(sock.user && sock.user.id);
        ATT[ch] = 0;
        if (!cfg.linked[ch]) { cfg.linked[ch] = Date.now(); save(); }
        setState(ch, { state: 'ready', qr: null, pairCode: '', phone: ph, error: '' });
        notify('تم ربط ' + LABEL[ch] + (ph ? ' (' + ph + ')' : '') + ' ✅');
        loop(ch, gen);
      }
      if (connection === 'close') {
        const code = lastDisconnect && lastDisconnect.error && lastDisconnect.error.output && lastDisconnect.error.output.statusCode;
        wlog(ch + ' close code=' + code + ' ' + String(lastDisconnect && lastDisconnect.error && lastDisconnect.error.message || ''));
        if (code === (WW.DisconnectReason && WW.DisconnectReason.loggedOut || 401)) {
          try { fs.rmSync(dir, { recursive: true, force: true }); } catch (e) { /* ignore */ }
          delete cfg.linked[ch]; save();
          setState(ch, { state: 'disconnected', qr: null, phone: '', error: 'تم فصل الرقم من الهاتف، أعد الربط' });
          notify('تم فصل ' + LABEL[ch] + ' من الهاتف، أعد الربط');
          restartLater(ch, gen, 3000);
        } else {
          ATT[ch] = (ATT[ch] || 0) + 1;
          if (S[ch] && S[ch].state === 'ready') setState(ch, { state: 'disconnected', error: 'انقطع الاتصال، جاري إعادة المحاولة' });
          restartLater(ch, gen, code === 515 || code === 428 ? 1000 : Math.min(30000, 3000 * ATT[ch]));
        }
      }
    });
    sock.ev.on('messages.upsert', async ev => {
      try {
        if (ev.type !== 'notify' || (S[ch] && S[ch].standby)) return;
        for (const m of ev.messages || []) {
          const jid = (m.key && m.key.remoteJid) || '';
          if (!m.message || m.key.fromMe || jid.endsWith('@g.us') || jid.endsWith('@broadcast') || jid === 'status@broadcast' || jid.endsWith('@newsletter')) continue;
          const body = m.message.conversation || (m.message.extendedTextMessage && m.message.extendedTextMessage.text) || '';
          const from = digits(m.key.senderPn || m.key.remoteJidAlt || jid);
          await api('incoming', { method: 'POST', body: JSON.stringify({ channel: ch, from, body }) });
        }
      } catch (e) { /* ignore */ }
    });
  } catch (e) {
    diag(ch, e); ATT[ch] = (ATT[ch] || 0) + 1;
    wlog(ch + ' خطأ: ' + String(e && (e.stack || e.message) || e).slice(0, 600));
    setState(ch, { state: 'error', error: String(e && e.message || e) + ' (محاولة ' + ATT[ch] + '، سيعاد التشغيل تلقائيًا)' });
    restartLater(ch, gen, 20000);
  }
}

function restartLater(ch, gen, ms) {
  setTimeout(async () => {
    if (!RT[ch] || RT[ch].gen !== gen) return;
    await stopChannel(ch, false);
    if (cfg.channels[ch] !== false) startChannel(ch);
  }, ms || 20000);
}

async function stopChannel(ch, keepState) {
  const r = RT[ch];
  if (r) { r.gen++; const c = r.client; r.client = null; if (c) { try { c.end(undefined); } catch (e) { /* ignore */ } } }
  if (!keepState && S[ch]) { /* الحالة تبقى حتى إعادة التشغيل */ }
}

/** تأكيد الإرسال للموقع مع إعادة المحاولة، وإن فشل نحفظه ونعيده لاحقًا (يمنع إرسال الرسالة مرتين). */
const ACKQ = [];
async function ackSent(id, wa) {
  for (let i = 0; i < 4; i++) {
    try { await api('ack', { method: 'POST', body: JSON.stringify({ id, ok: true, wa_id: wa }) }); return; } catch (e) { await sleep(2000 * (i + 1)); }
  }
  ACKQ.push({ id, wa });
}
async function flushAcks() {
  while (ACKQ.length) {
    const a = ACKQ[0];
    try { await api('ack', { method: 'POST', body: JSON.stringify({ id: a.id, ok: true, wa_id: a.wa }) }); ACKQ.shift(); } catch (e) { return; }
  }
}
async function loop(ch, gen) {
  let sentRun = 0, fails = 0;
  while (RT[ch] && RT[ch].gen === gen && RT[ch].client && S[ch] && S[ch].state === 'ready') {
    try {
      await flushAcks();
      const items = await api('pull?channel=' + ch + '&inst=' + encodeURIComponent(cfg.inst || ''));
      for (const it of items) {
        if (!RT[ch] || RT[ch].gen !== gen || !RT[ch].client) return;
        const sock = RT[ch].client;
        const ph = phaseOf(ch);
        let slow = fails >= 2 ? 2 : 1;               // تبطيء تلقائي بعد فشلين متتاليين
        try {
          const res = await sock.onWhatsApp(digits(it.to_phone));
          const hit = res && res[0];
          if (!hit || !hit.exists) throw new Error('الرقم غير مسجل على واتساب');
          const jid = hit.jid;
          // محاكاة بشرية: «يكتب...» لمدة قصيرة قبل الإرسال
          try { await sock.presenceSubscribe(jid); await sock.sendPresenceUpdate('composing', jid); await sleep(rnd(1500, 4000)); await sock.sendPresenceUpdate('paused', jid); } catch (e) { /* ليست أساسية */ }
          const msg = await sock.sendMessage(jid, { text: it.body });
          sentRun++; fails = 0;
          await ackSent(it.id, msg && msg.key && msg.key.id);   // الإرسال تم فعلًا: أي فشل في التأكيد لا يجعل الرسالة «فاشلة»
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
  CHANNELS.forEach((ch, i) => setTimeout(() => startChannel(ch), 3000 + i * 40000)); // الـ QR يظهر حتى قبل ربط الموقع
  setInterval(() => CHANNELS.forEach(ch => { if (S[ch] && S[ch].state !== 'idle') report(ch); }), 60000);
  // أوامر من صفحة ووردبريس (إعادة توليد الكود / فصل الرقم)
  setInterval(async () => {
    if (!cfg.site || !cfg.key) return;
    for (const ch of CHANNELS) {
      try { const r = await api('gw-cmd?channel=' + ch); if (r && r.cmd === 'logout') await logout(ch); else if (r && r.cmd === 'connect') await connect(ch); } catch (e) { /* ignore */ }
    }
  }, 8000);
}

async function setConfig(c) {
  if (!c || typeof c.site !== 'string' || typeof c.key !== 'string') return getState();
  const changed = c.site !== cfg.site || c.key !== cfg.key;
  cfg.site = c.site.trim(); cfg.key = c.key.trim();
  if (c.channels) cfg.channels = Object.assign(cfg.channels, c.channels);
  save();
  if (started && changed) CHANNELS.forEach((ch, i) => { if (!RT[ch] || !RT[ch].client) setTimeout(() => startChannel(ch), 500 + i * 40000); else report(ch); });
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
  try { fs.rmSync(path.join(sessDir, 'bd-' + ch), { recursive: true, force: true }); } catch (e) { /* ignore */ }
  delete cfg.linked[ch]; save();
  if (RT[ch]) RT[ch].pairPhone = '';
  setState(ch, { state: 'idle', qr: null, phone: '', pairCode: '' });
  if (cfg.channels[ch] !== false) startChannel(ch);
  return getState();
}
async function pair(ch, phone) {
  if (!CHANNELS.includes(ch)) return getState();
  const p = digits(phone); if (p.length < 8) { setState(ch, { error: 'اكتب رقم الهاتف بالصيغة الدولية، مثال 201001234567' }); return getState(); }
  cfg.channels[ch] = true; save();
  await stopChannel(ch, true);
  RT[ch] = Object.assign(RT[ch] || { gen: 0 }, { pairPhone: p });
  await startChannel(ch);
  return getState();
}
async function setAge(ch, days) {
  if (!CHANNELS.includes(ch)) return getState();
  const d = Math.max(0, Math.min(3650, Number(days) || 0));
  cfg.linked[ch] = Date.now() - d * 86400000; save();
  setState(ch, {});
  return getState();
}
async function takeover() { forceNext = true; CHANNELS.forEach(ch => { if (S[ch]) report(ch); }); return getState(); }
async function shutdown() { for (const ch of CHANNELS) { await stopChannel(ch, true); } }
function summary() {
  const st = getState().channels;
  return CHANNELS.map(ch => (ch === 'students' ? 'الطلاب' : 'المدرسين') + ': ' + ({ ready: 'متصل', qr: 'بانتظار الكود', starting: 'جاري التشغيل', idle: 'غير مربوط', disconnected: 'منفصل', error: 'خطأ', no_browser: 'يحتاج Chrome' }[st[ch].state] || st[ch].state)).join(' | ');
}

module.exports = { takeover, pair, init, setConfig, getState, connect, disable, logout, setAge, shutdown, summary };
