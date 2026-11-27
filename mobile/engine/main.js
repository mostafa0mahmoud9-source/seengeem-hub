'use strict';
/**
 * محرك واتساب داخل تطبيق الأندرويد (Node.js Mobile) — احتياطي للكمبيوتر.
 * يسحب الرسائل من الموقع (/pull?src=mobile) فقط حين يكون الكمبيوتر غير جاهز (الموقع هو من يقرر).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
let channel = null, bridgeApp = null;
try { const b = require('bridge'); channel = b.channel; bridgeApp = b.app || null; } catch (e) { /* خارج التطبيق */ }

const CHANNELS = ['students', 'staff'];
const LABEL = { students: 'رقم الطلاب وأولياء الأمور', staff: 'رقم المدرسين والمتابعة' };
const sleep = ms => new Promise(r => setTimeout(r, ms));
const rnd = (a, b) => a + Math.floor(Math.random() * (b - a + 1));
function digits(v) { return String(v || '').split('@')[0].split(':')[0].replace(/\D/g, ''); }

let base = '';
try { if (bridgeApp && bridgeApp.datadir) base = bridgeApp.datadir(); } catch (e) { /* ignore */ }
if (!base) base = process.env.DATADIR || process.env.NODEJS_MOBILE_DATADIR || path.join(os.homedir() || os.tmpdir(), '.seengeem');
const sessDir = path.join(base, 'wa-sessions');
const cfgPath = path.join(base, 'wa-config.json');
try { fs.mkdirSync(sessDir, { recursive: true }); } catch (e) { /* ignore */ }

let cfg = { site: '', key: '', channels: { students: true, staff: true }, linked: {} };
try { cfg = Object.assign(cfg, JSON.parse(fs.readFileSync(cfgPath, 'utf8'))); } catch (e) { /* أول تشغيل */ }
cfg.linked = cfg.linked || {}; cfg.channels = Object.assign({ students: true, staff: true }, cfg.channels || {});
const save = () => { try { fs.writeFileSync(cfgPath, JSON.stringify(cfg)); } catch (e) { /* ignore */ } };

const S = {}, RT = {}, ATT = {};
let WW = null, QRCode = null;
const quiet = { level: 'silent', trace() {}, debug() {}, info() {}, warn() {}, error() {}, fatal() {}, child() { return quiet; } };
const LOGP = path.join(base, 'engine-log.txt');
function wlog(m) { try { fs.appendFileSync(LOGP, new Date().toISOString() + ' ' + m + '\n'); } catch (e) { /* ignore */ } }
function emit() { try { channel && channel.send('state', JSON.stringify(snapshot())); } catch (e) { /* ignore */ } }
function snapshot() {
  const o = { configured: !!(cfg.site && cfg.key), dir: base, channels: {} };
  CHANNELS.forEach(ch => { o.channels[ch] = Object.assign({ label: LABEL[ch], state: 'idle', qr: null, phone: '', error: '', pairCode: '' }, S[ch] || {}); });
  return o;
}
function setState(ch, patch) { S[ch] = Object.assign(S[ch] || { state: 'idle' }, patch); emit(); report(ch); }

async function api(p, opt) {
  const sep = p.indexOf('?') < 0 ? '?' : '&';
  const o = Object.assign({ headers: opt && opt.body ? { 'Content-Type': 'text/plain' } : {}, signal: AbortSignal.timeout(25000) }, opt || {});
  const r = await fetch(cfg.site.replace(/\/$/, '') + '/wp-json/sgb/v1/' + p + sep + '_k=' + encodeURIComponent(cfg.key), o);
  if (!r.ok) throw new Error(p + ' → HTTP ' + r.status);
  return r.json();
}
function report(ch) {
  if (!cfg.site || !cfg.key || !S[ch]) return;
  api('gw-status', { method: 'POST', body: JSON.stringify({ src: 'mobile', channel: ch, state: S[ch].state, phone: S[ch].phone || '', error: S[ch].error || '' }) }).catch(() => {});
}

const PHASES = [
  { upto: 7, delay: [20, 45], every: 10, rest: [180, 300] }, { upto: 14, delay: [15, 35], every: 15, rest: [120, 240] },
  { upto: 30, delay: [10, 25], every: 20, rest: [120, 240] }, { upto: 1e9, delay: [7, 18], every: 20, rest: [120, 240] },
];
function phaseOf(ch) { const t = cfg.linked[ch]; const d = t ? (Date.now() - t) / 86400000 : 0; return PHASES.find(p => d < p.upto); }

async function startChannel(ch) {
  if (cfg.channels[ch] === false || (RT[ch] && RT[ch].client)) return;
  if (!WW) {
    let e1 = null;
    try { if (!globalThis.crypto) { globalThis.crypto = require('crypto').webcrypto; } } catch (e) { /* ignore */ }
    // النسخ الحديثة من Baileys بصيغة ESM: نستخدم import() أولًا (ثم require كاحتياطي للنسخ القديمة)
    try { WW = await import('@whiskeysockets/baileys'); } catch (e) { e1 = e; }
    if (!WW) { try { WW = require('@whiskeysockets/baileys'); } catch (e2) {
      const m1 = String((e1 && e1.message) || e1).slice(0, 230);
      const m2 = String((e2 && e2.message) || e2).slice(0, 80);
      wlog('load import: ' + (e1 && e1.stack || e1) + ' | require: ' + (e2 && e2.message || e2));
      setState(ch, { state: 'error', error: 'تعذر تحميل مكتبة واتساب (سيعاد المحاولة) [node ' + process.version + ']: ' + m1 + ' | ' + m2 });
      setTimeout(() => startChannel(ch), 30000);
      return;
    } }
  }
  if (!QRCode) { try { QRCode = require('qrcode'); } catch (e) { /* ignore */ } }
  const gen = ((RT[ch] && RT[ch].gen) || 0) + 1; const pairPhone = RT[ch] && RT[ch].pairPhone;
  RT[ch] = { client: null, gen, pairPhone };
  setState(ch, { state: 'starting', qr: null, error: '', pairCode: '' });
  try {
    const dir = path.join(sessDir, 'bd-' + ch); fs.mkdirSync(dir, { recursive: true });
    const { state, saveCreds } = await WW.useMultiFileAuthState(dir);
    let version; try { version = (await WW.fetchLatestBaileysVersion()).version; } catch (e) { /* default */ }
    const B = WW.Browsers;
    const sock = (WW.default || WW.makeWASocket)({
      auth: state, logger: quiet, printQRInTerminal: false, syncFullHistory: false, markOnlineOnConnect: false,
      connectTimeoutMs: 60000, browser: B && B.ubuntu ? B.ubuntu('Chrome') : ['Ubuntu', 'Chrome', '22.04'], ...(version ? { version } : {}),
    });
    if (RT[ch].gen !== gen) { try { sock.end(undefined); } catch (e) { /* ignore */ } return; }
    RT[ch].client = sock;
    sock.ev.on('creds.update', saveCreds);
    // الردود الواردة: الموقع يتجاهلها طالما الكمبيوتر شغّال (لا تكرار)
    sock.ev.on('messages.upsert', async ev => {
      try {
        if (ev.type !== 'notify') return;
        for (const m of ev.messages || []) {
          const jid = (m.key && m.key.remoteJid) || '';
          if (!m.message || m.key.fromMe || jid.endsWith('@g.us') || jid.endsWith('@broadcast') || jid.endsWith('@newsletter')) continue;
          const body = m.message.conversation || (m.message.extendedTextMessage && m.message.extendedTextMessage.text) || '';
          await api('incoming', { method: 'POST', body: JSON.stringify({ src: 'mobile', channel: ch, from: digits(m.key.senderPn || m.key.remoteJidAlt || jid), body }) });
        }
      } catch (e) { /* ignore */ }
    });
    let pairAsked = false;
    sock.ev.on('connection.update', async u => {
      if (!RT[ch] || RT[ch].gen !== gen) return;
      const { connection, lastDisconnect, qr } = u;
      if (qr) {
        if (RT[ch].pairPhone && !state.creds.registered) {
          if (!pairAsked) { pairAsked = true; try { const code = await sock.requestPairingCode(RT[ch].pairPhone); setState(ch, { state: 'qr', qr: null, pairCode: code, error: '' }); } catch (e) { setState(ch, { state: 'error', error: 'تعذر طلب كود الربط: ' + e.message }); } }
        } else {
          let img = null; try { img = QRCode ? await QRCode.toDataURL(qr, { width: 300, margin: 1 }) : null; } catch (e) { /* ignore */ }
          setState(ch, { state: 'qr', qr: img, pairCode: '', error: '' });
        }
      }
      if (connection === 'open') {
        const ph = digits(sock.user && sock.user.id); ATT[ch] = 0;
        if (!cfg.linked[ch]) { cfg.linked[ch] = Date.now(); save(); }
        setState(ch, { state: 'ready', qr: null, pairCode: '', phone: ph, error: '' });
        loop(ch, gen);
      }
      if (connection === 'close') {
        const code = lastDisconnect && lastDisconnect.error && lastDisconnect.error.output && lastDisconnect.error.output.statusCode;
        wlog(ch + ' close ' + code);
        if (code === ((WW.DisconnectReason && WW.DisconnectReason.loggedOut) || 401)) {
          try { fs.rmSync(dir, { recursive: true, force: true }); } catch (e) { /* ignore */ }
          delete cfg.linked[ch]; save();
          setState(ch, { state: 'disconnected', qr: null, phone: '', error: 'تم فصل الرقم من الهاتف، أعد الربط' }); restartLater(ch, gen, 3000);
        } else {
          ATT[ch] = (ATT[ch] || 0) + 1;
          if (S[ch] && S[ch].state === 'ready') setState(ch, { state: 'disconnected', error: 'انقطع الاتصال، جاري إعادة المحاولة' });
          restartLater(ch, gen, code === 515 || code === 428 ? 1000 : Math.min(30000, 3000 * ATT[ch]));
        }
      }
    });
  } catch (e) {
    ATT[ch] = (ATT[ch] || 0) + 1; wlog(ch + ' خطأ: ' + String(e && (e.stack || e.message) || e).slice(0, 500));
    setState(ch, { state: 'error', error: String(e && e.message || e).slice(0, 160) + ' (محاولة ' + ATT[ch] + ')' }); restartLater(ch, gen, 20000);
  }
}
function restartLater(ch, gen, ms) { setTimeout(async () => { if (!RT[ch] || RT[ch].gen !== gen) return; await stopChannel(ch); if (cfg.channels[ch] !== false) startChannel(ch); }, ms || 20000); }
async function stopChannel(ch) { const r = RT[ch]; if (r) { r.gen++; const c = r.client; r.client = null; if (c) { try { c.end(undefined); } catch (e) { /* ignore */ } } } }


/** سجل دائم على الجهاز لما اتبعت فعلًا: يمنع أي تكرار حتى لو الموقع سلّم نفس الرسالة تاني. */
let LEDGER = null, ledgerPath = '';
function ledgerLoad() {
  if (LEDGER) return LEDGER;
  LEDGER = { ids: {}, keys: {} };
  try { ledgerPath = path.join(base, 'sent-ledger.json'); LEDGER = Object.assign(LEDGER, JSON.parse(fs.readFileSync(ledgerPath, 'utf8'))); } catch (e) { /* أول مرة */ }
  return LEDGER;
}
function ledgerKey(it) { return digits(it.to_phone) + ':' + require('crypto').createHash('sha1').update(String(it.body || '')).digest('hex'); }
function ledgerSeen(it) {
  const L = ledgerLoad(), now = Date.now();
  if (L.ids[it.id] && now - L.ids[it.id] < 14 * 86400000) return true;
  const k = L.keys[ledgerKey(it)];
  return !!(k && now - k < 3 * 86400000);
}
function ledgerAdd(it) {
  const L = ledgerLoad(), now = Date.now();
  L.ids[it.id] = now; L.keys[ledgerKey(it)] = now;
  for (const m of ['ids', 'keys']) { for (const k of Object.keys(L[m])) { if (now - L[m][k] > 14 * 86400000) delete L[m][k]; } }
  try { fs.writeFileSync(ledgerPath, JSON.stringify(L)); } catch (e) { /* ignore */ }
}
const INFLIGHT = {};   // id -> وقت البدء (يمنع حلقتين من إرسال نفس الرسالة معًا)

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
      if (cfg.site && cfg.key) {
        await flushAcks();
        const items = await api('pull?channel=' + ch + '&src=mobile');
        for (const it of items) {
          if (INFLIGHT[it.id] && Date.now() - INFLIGHT[it.id] < 600000) { continue; }
          if (ledgerSeen(it)) { await ackSent(it.id, null); continue; }   // اتبعتت قبل كده: نؤكد للموقع ولا نرسل تاني
          INFLIGHT[it.id] = Date.now();
          if (!RT[ch] || RT[ch].gen !== gen || !RT[ch].client) return;
          const sock = RT[ch].client, ph = phaseOf(ch);
          try {
            const res = await sock.onWhatsApp(digits(it.to_phone)); const hit = res && res[0];
            if (!hit || !hit.exists) throw new Error('الرقم غير مسجل على واتساب');
            try { await sock.presenceSubscribe(hit.jid); await sock.sendPresenceUpdate('composing', hit.jid); await sleep(rnd(1500, 4000)); await sock.sendPresenceUpdate('paused', hit.jid); } catch (e) { /* optional */ }
            const msg = await sock.sendMessage(hit.jid, { text: it.body });
            sentRun++; fails = 0; ledgerAdd(it);
            await ackSent(it.id, msg && msg.key && msg.key.id);
          } catch (e) {
            const m = String(e && e.message || e); if (m.indexOf('غير مسجل') < 0) fails++;
            await api('ack', { method: 'POST', body: JSON.stringify({ id: it.id, ok: false, error: m.slice(0, 200) }) }).catch(() => {});
          }
          if (fails >= 4) { setState(ch, { error: 'توقف مؤقت لتكرار الفشل (نصف ساعة)' }); fails = 0; await sleep(30 * 60000); setState(ch, { error: '' }); break; }
          await sleep(rnd(ph.delay[0], ph.delay[1]) * 1000 * (fails >= 2 ? 2 : 1));
          if (sentRun > 0 && sentRun % ph.every === 0) await sleep(rnd(ph.rest[0], ph.rest[1]) * 1000);
        }
      }
    } catch (e) { /* مشكلة اتصال مؤقتة */ }
    await sleep(30000); // الموبايل احتياطي: فحص كل 30 ثانية
  }
}

async function logout(ch) {
  const r = RT[ch]; if (r && r.client) { try { await r.client.logout(); } catch (e) { /* ignore */ } }
  await stopChannel(ch);
  try { fs.rmSync(path.join(sessDir, 'bd-' + ch), { recursive: true, force: true }); } catch (e) { /* ignore */ }
  delete cfg.linked[ch]; save(); if (RT[ch]) RT[ch].pairPhone = '';
  setState(ch, { state: 'idle', qr: null, phone: '', pairCode: '' });
  if (cfg.channels[ch] !== false) startChannel(ch);
}
async function pair(ch, phone) {
  const p = digits(phone); if (p.length < 8) { setState(ch, { error: 'اكتب الرقم بالصيغة الدولية مثل 201001234567' }); return; }
  await stopChannel(ch); RT[ch] = Object.assign(RT[ch] || { gen: 0 }, { pairPhone: p }); await startChannel(ch);
}

function onMsg(raw) {
  let m = {}; try { m = typeof raw === 'string' ? JSON.parse(raw) : (raw || {}); } catch (e) { return; }
  const ch = CHANNELS.includes(m.ch) ? m.ch : '';
  if (m.cmd === 'config') {
    const changed = m.site !== cfg.site || m.key !== cfg.key; cfg.site = String(m.site || '').trim(); cfg.key = String(m.key || '').trim(); save();
    if (changed) CHANNELS.forEach((c, i) => { if (!RT[c] || !RT[c].client) setTimeout(() => startChannel(c), 500 + i * 15000); });
  } else if (m.cmd === 'status') emit();
  else if (m.cmd === 'connect' && ch) { cfg.channels[ch] = true; save(); stopChannel(ch).then(() => startChannel(ch)); }
  else if (m.cmd === 'logout' && ch) logout(ch);
  else if (m.cmd === 'pair' && ch) pair(ch, m.phone);
}
if (channel) channel.addListener('cmd', onMsg);
wlog('engine started, dir=' + base);
CHANNELS.forEach(c => { S[c] = { state: 'idle' }; });
if (cfg.site && cfg.key) CHANNELS.forEach((c, i) => setTimeout(() => startChannel(c), 3000 + i * 15000));
setInterval(() => CHANNELS.forEach(c => { if (S[c] && S[c].state !== 'idle') report(c); }), 60000);
setInterval(emit, 5000);
