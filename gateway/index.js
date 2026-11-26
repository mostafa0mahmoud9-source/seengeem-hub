'use strict';
/**
 * بوابة واتساب QR الاحتياطية — جلستان (students / staff)
 * تسحب الرسائل بحالة qr_pending من الموقع وترسلها، وتعيد الردود الواردة للموقع.
 * الإعداد عبر متغيرات البيئة أو ملف config.json بجانب هذا الملف:
 *   SITE_URL, SGB_KEY, CHANNELS (مثال: students,staff)
 */
const fs = require('fs');
const path = require('path');
const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');

let cfg = {};
try { cfg = JSON.parse(fs.readFileSync(path.join(__dirname, 'config.json'), 'utf8')); } catch (e) { /* اختياري */ }
const SITE = (process.env.SITE_URL || cfg.site_url || '').replace(/\/$/, '');
const KEY = process.env.SGB_KEY || cfg.key || '';
const CHANNELS = (process.env.CHANNELS || (cfg.channels || ['students', 'staff']).join(',')).split(',').map(s => s.trim()).filter(Boolean);
if (!SITE || !KEY) { console.error('لازم تضبط SITE_URL و SGB_KEY (أو config.json)'); process.exit(1); }

async function api(p, opt = {}) {
  const r = await fetch(`${SITE}/wp-json/sgb/v1/${p}`, {
    ...opt, headers: { 'X-SGB-Key': KEY, 'Content-Type': 'application/json' },
  });
  if (!r.ok) throw new Error(`${p} → HTTP ${r.status}`);
  return r.json();
}
const sleep = ms => new Promise(r => setTimeout(r, ms));
const state = {};

function start(channel) {
  const client = new Client({
    authStrategy: new LocalAuth({ clientId: channel, dataPath: path.join(__dirname, '.sessions') }),
    puppeteer: { headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] },
  });
  state[channel] = { ready: false };
  client.on('qr', qr => { console.log(`\n[${channel}] امسح الكود بواتساب الرقم الخاص بهذه القناة:`); qrcode.generate(qr, { small: true }); });
  client.on('ready', () => { state[channel].ready = true; console.log(`[${channel}] متصل ✅`); loop(channel, client); });
  client.on('disconnected', r => { state[channel].ready = false; console.log(`[${channel}] انقطع الاتصال: ${r}`); setTimeout(() => client.initialize().catch(console.error), 15000); });
  client.on('message', async m => {
    if (m.fromMe || m.isStatus || (m.from || '').endsWith('@g.us')) return;
    try { await api('incoming', { method: 'POST', body: JSON.stringify({ channel, from: (m.from || '').split('@')[0], body: m.body }) }); } catch (e) { console.error(e.message); }
  });
  client.initialize().catch(console.error);
}

async function loop(channel, client) {
  while (state[channel].ready) {
    try {
      const items = await api(`pull?channel=${channel}`);
      for (const it of items) {
        try {
          const id = `${it.to_phone}@c.us`;
          if (!(await client.isRegisteredUser(id))) throw new Error('الرقم غير مسجل على واتساب');
          const msg = await client.sendMessage(id, it.body);
          await api('ack', { method: 'POST', body: JSON.stringify({ id: it.id, ok: true, wa_id: msg.id && msg.id._serialized }) });
          console.log(`[${channel}] ✓ ${it.id}`);
        } catch (e) {
          await api('ack', { method: 'POST', body: JSON.stringify({ id: it.id, ok: false, error: e.message }) }).catch(() => {});
          console.error(`[${channel}] ✗ ${it.id}: ${e.message}`);
        }
        await sleep(4000 + Math.floor(Math.random() * 6000)); // فواصل عشوائية لتقليل خطر الحظر
      }
    } catch (e) { console.error(`[${channel}] ${e.message}`); }
    await sleep(15000);
  }
}

CHANNELS.forEach(start);
