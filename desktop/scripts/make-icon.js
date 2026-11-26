// يولّد أيقونة التطبيق (PNG 512×512) بدون أي مكتبات خارجية
// لوجو المنصة: لو الملف assets/brand.png موجود (يُنزَّل وقت البناء) نستخدمه بدل الأيقونة المرسومة
{
  const f = require('fs'), pa = require('path'), b = pa.join(__dirname, '..', 'assets', 'brand.png');
  if (f.existsSync(b) && f.statSync(b).size > 5000) {
    for (const d of ['build', 'assets']) { f.mkdirSync(pa.join(__dirname, '..', d), { recursive: true }); f.copyFileSync(b, pa.join(__dirname, '..', d, 'icon.png')); }
    console.log('brand icon used'); process.exit(0);
  }
}
const fs = require('fs'), path = require('path'), zlib = require('zlib');
const N = 512;
const crcT = new Int32Array(256).map((_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c; });
const crc = b => { let c = -1; for (const x of b) c = crcT[(c ^ x) & 255] ^ (c >>> 8); return (c ^ -1) >>> 0; };
const chunk = (t, d) => { const l = Buffer.alloc(4); l.writeUInt32BE(d.length); const td = Buffer.concat([Buffer.from(t), d]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([l, td, c]); };
const rrect = (x, y, x0, y0, x1, y1, r) => { const cx = Math.min(Math.max(x, x0 + r), x1 - r), cy = Math.min(Math.max(y, y0 + r), y1 - r); return Math.hypot(x - cx, y - cy) <= r; };
const tri = (x, y, a, b, c) => { const s = (p, q, r) => (p[0] - r[0]) * (q[1] - r[1]) - (q[0] - r[0]) * (p[1] - r[1]); const p = [x, y], d1 = s(p, a, b), d2 = s(p, b, c), d3 = s(p, c, a); return !((d1 < 0 || d2 < 0 || d3 < 0) && (d1 > 0 || d2 > 0 || d3 > 0)); };
const seg = (x, y, a, b, w) => { const dx = b[0] - a[0], dy = b[1] - a[1]; let t = ((x - a[0]) * dx + (y - a[1]) * dy) / (dx * dx + dy * dy); t = Math.max(0, Math.min(1, t)); return Math.hypot(x - (a[0] + t * dx), y - (a[1] + t * dy)) <= w / 2; };
function px(x, y) {
  if (!rrect(x, y, 0, 0, N, N, 112)) return [0, 0, 0, 0];
  let c = [59 + (106 - 59) * y / N, 15 + (53 - 15) * y / N, 130 + (201 - 130) * y / N];
  const bubble = rrect(x, y, 96, 120, 416, 330, 56) || tri(x, y, [170, 320], [170, 410], [265, 325]);
  if (bubble) {
    c = [255, 255, 255];
    if (seg(x, y, [188, 232], [240, 284], 30) || seg(x, y, [240, 284], [340, 176], 30)) c = [59, 15, 130];
  }
  return [c[0], c[1], c[2], 255];
}
const raw = Buffer.alloc((N * 4 + 1) * N);
for (let y = 0; y < N; y++) {
  raw[y * (N * 4 + 1)] = 0;
  for (let x = 0; x < N; x++) {
    let r = 0, g = 0, b = 0, a = 0;
    for (const [ox, oy] of [[.25, .25], [.75, .25], [.25, .75], [.75, .75]]) { const p = px(x + ox, y + oy); r += p[0] * p[3]; g += p[1] * p[3]; b += p[2] * p[3]; a += p[3]; }
    const o = y * (N * 4 + 1) + 1 + x * 4;
    if (a) { raw[o] = r / a; raw[o + 1] = g / a; raw[o + 2] = b / a; raw[o + 3] = a / 4; }
  }
}
const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(N, 0); ihdr.writeUInt32BE(N, 4); ihdr[8] = 8; ihdr[9] = 6;
const png = Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
for (const d of ['build', 'assets']) { fs.mkdirSync(path.join(__dirname, '..', d), { recursive: true }); fs.writeFileSync(path.join(__dirname, '..', d, 'icon.png'), png); }
console.log('icon generated', png.length);
