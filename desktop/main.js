const { app, BrowserWindow, Menu, Tray, Notification, shell, nativeImage, ipcMain, powerSaveBlocker } = require('electron');
const path = require('path');
const fs = require('fs');
const { autoUpdater } = require('electron-updater');
const wa = require('./wa');

let win = null, tray = null, quitting = false, lastCheck = 0;
const gotLock = app.requestSingleInstanceLock();
if (!gotLock) { app.quit(); }
const LOGIN_ARGS = { args: ['--hidden'] };
const offFlag = () => path.join(app.getPath('userData'), 'autostart-off');
let pendingSetup = null;
app.setAsDefaultProtocolClient('seengeem');
function deepLink(u) {
  const m = String(u || '').match(/^seengeem:\/\/setup\?c=([A-Za-z0-9+\/=_-]+)/);
  if (!m) return;
  pendingSetup = m[1];
  if (win && !win.isDestroyed()) { showWin(); win.webContents.send('sg:setup', m[1]); }
}
app.on('second-instance', (_e, argv) => { if (!(argv || []).includes('--hidden')) { showWin(); } deepLink((argv || []).find(a => /^seengeem:\/\//.test(a))); });
app.on('open-url', (e, u) => { e.preventDefault(); deepLink(u); });
app.on('before-quit', () => { quitting = true; wa.shutdown().catch(() => {}); setTimeout(() => app.exit(0), 5000); });

const PAGES = 'https://mostafa0mahmoud9-source.github.io/';
const icon = () => nativeImage.createFromPath(path.join(__dirname, 'assets', 'icon.png'));
const trusted = e => { const u = (e && e.senderFrame && e.senderFrame.url) || ''; return u.startsWith('file://') || u.startsWith(PAGES); };

function showWin() {
  if (!win || win.isDestroyed()) { createWindow(false); return; }
  if (win.isMinimized()) win.restore();
  win.setSkipTaskbar(false); win.show(); win.focus();
  try { app.focus({ steal: true }); } catch (e) { /* ignore */ }
}

function createWindow(hidden) {
  win = new BrowserWindow({
    show: !hidden, width: 1100, height: 800, minWidth: 380, title: 'مساعد سين جيم', icon: icon(), autoHideMenuBar: true,
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: true },
  });
  win.loadFile(path.join(__dirname, 'www', 'index.html'));
  win.webContents.setWindowOpenHandler(({ url }) => { shell.openExternal(url); return { action: 'deny' }; });
  win.webContents.on('will-navigate', (e, url) => { if (!(url.startsWith('file://') || url.startsWith(PAGES))) { e.preventDefault(); shell.openExternal(url); } });
  win.on('close', e => { if (!quitting) { e.preventDefault(); win.hide(); } }); // يبقى شغال في الشريط
  win.on('focus', () => checkUpdates(false));
  win.webContents.on('render-process-gone', () => { try { win.reload(); } catch (e) { /* ignore */ } });
  win.on('unresponsive', () => { try { win.reload(); } catch (e) { /* ignore */ } });
}

function createTray() {
  tray = new Tray(icon().resize({ width: 16, height: 16 }));
  tray.setToolTip('مساعد سين جيم');
  tray.on('click', showWin);
  refreshTray();
}
function refreshTray() {
  if (!tray) return;
  tray.setToolTip('مساعد سين جيم — ' + wa.summary());
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'فتح مساعد سين جيم', click: showWin },
    { label: wa.summary(), enabled: false },
    { label: 'تحقق من التحديثات الآن', click: () => checkUpdates(true) },
    { label: 'تشغيل مع ويندوز', type: 'checkbox', checked: !fs.existsSync(offFlag()), click: m => { try { if (m.checked) { fs.rmSync(offFlag(), { force: true }); } else { fs.writeFileSync(offFlag(), '1'); } app.setLoginItemSettings({ openAtLogin: !!m.checked, args: ['--hidden'] }); } catch (e) { /* ignore */ } } },
    { type: 'separator' },
    { label: 'خروج نهائي', click: () => { quitting = true; app.quit(); } },
  ]));
}

function checkUpdates(force) {
  const now = Date.now();
  if (!force && now - lastCheck < 120000) return; // لا أكثر من مرة كل دقيقتين
  lastCheck = now;
  autoUpdater.checkForUpdates().catch(() => {});
}

// جسر الواجهة ↔ محرك الواتساب
ipcMain.handle('sg:state', e => (trusted(e) ? wa.getState() : null));
ipcMain.handle('sg:config', (e, c) => (trusted(e) ? wa.setConfig(c && { site: c.site || c.url, key: c.key }) : null));
ipcMain.handle('sg:connect', (e, ch) => (trusted(e) ? wa.connect(ch) : null));
ipcMain.handle('sg:disable', (e, ch) => (trusted(e) ? wa.disable(ch) : null));
ipcMain.handle('sg:age', (e, ch, d) => (trusted(e) ? wa.setAge(ch, d) : null));
ipcMain.handle('sg:setup-pending', e => { if (!trusted(e)) return null; const c = pendingSetup; pendingSetup = null; return c; });
ipcMain.handle('sg:takeover', e => (trusted(e) ? wa.takeover() : null));
ipcMain.handle('sg:pair', (e, ch, ph) => (trusted(e) ? wa.pair(ch, ph) : null));
ipcMain.handle('sg:logout', (e, ch) => (trusted(e) ? wa.logout(ch) : null));

app.whenReady().then(() => {
  if (!gotLock) return; // نسخة ثانية: لا نشغّل المحرك حتى لا يتعارض على نفس الجلسات
  const rf = path.join(app.getPath('userData'), 'relaunch-hidden');
  const afterUpdate = fs.existsSync(rf); if (afterUpdate) { try { fs.unlinkSync(rf); } catch (e) { /* ignore */ } }
  const hidden = process.argv.includes('--hidden') || afterUpdate;
  createWindow(hidden);
  createTray();
  deepLink(process.argv.find(a => /^seengeem:\/\//.test(a)));
  powerSaveBlocker.start('prevent-app-suspension'); // لا يدخل البرنامج في سكون أثناء الإرسال
  // التشغيل مع ويندوز: مفعّل دائمًا (ومُجدَّد بعد كل تحديث) إلا لو أوقفه المستخدم من أيقونة الشريط
  try { app.setLoginItemSettings({ openAtLogin: !fs.existsSync(offFlag()), args: ['--hidden'] }); } catch (e) { /* ignore */ }
  wa.init({ onChange: s => { refreshTray(); if (win && !win.isDestroyed()) win.webContents.send('sg:state', s); } });
  autoUpdater.autoDownload = true;
  autoUpdater.autoInstallOnAppQuit = true;
  autoUpdater.on('update-downloaded', () => {
    new Notification({ title: 'مساعد سين جيم', body: 'تم تنزيل تحديث جديد، جاري التثبيت وإعادة التشغيل...' }).show();
    setTimeout(() => { quitting = true; try { fs.writeFileSync(path.join(app.getPath('userData'), 'relaunch-hidden'), '1'); } catch (e) { /* ignore */ } autoUpdater.quitAndInstall(true, true); }, 4000);
  });
  autoUpdater.on('error', () => {});
  checkUpdates(true);
  setInterval(() => checkUpdates(true), 10 * 60 * 1000); // كل 10 دقائق
});
app.on('window-all-closed', () => { /* يستمر في الشريط */ });
