const { app, BrowserWindow, Menu, Tray, Notification, shell, nativeImage } = require('electron');
const path = require('path');
const { autoUpdater } = require('electron-updater');

let win = null, tray = null, quitting = false, lastCheck = 0;
if (!app.requestSingleInstanceLock()) { app.quit(); }
app.on('second-instance', showWin);
app.on('before-quit', () => { quitting = true; });

const icon = () => nativeImage.createFromPath(path.join(__dirname, 'assets', 'icon.png'));

function showWin() { if (!win) return; if (win.isMinimized()) win.restore(); win.show(); win.focus(); }

function createWindow() {
  win = new BrowserWindow({
    width: 1100, height: 800, minWidth: 380, title: 'مساعد سين جيم', icon: icon(), autoHideMenuBar: true,
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: true },
  });
  win.loadFile(path.join(__dirname, 'www', 'index.html'));
  win.webContents.setWindowOpenHandler(({ url }) => { shell.openExternal(url); return { action: 'deny' }; });
  win.on('close', e => { if (!quitting) { e.preventDefault(); win.hide(); } }); // يبقى شغال في الشريط
  win.on('focus', () => checkUpdates(false));
}

function createTray() {
  tray = new Tray(icon().resize({ width: 16, height: 16 }));
  tray.setToolTip('مساعد سين جيم');
  const build = () => Menu.buildFromTemplate([
    { label: 'فتح مساعد سين جيم', click: showWin },
    { label: 'تحقق من التحديثات الآن', click: () => checkUpdates(true) },
    { label: 'تشغيل مع ويندوز', type: 'checkbox', checked: app.getLoginItemSettings().openAtLogin, click: m => app.setLoginItemSettings({ openAtLogin: m.checked }) },
    { type: 'separator' },
    { label: 'خروج نهائي', click: () => { quitting = true; app.quit(); } },
  ]);
  tray.setContextMenu(build());
  tray.on('click', showWin);
}

function checkUpdates(force) {
  const now = Date.now();
  if (!force && now - lastCheck < 120000) return; // لا أكثر من مرة كل دقيقتين
  lastCheck = now;
  autoUpdater.checkForUpdates().catch(() => {});
}

app.whenReady().then(() => {
  createWindow();
  createTray();
  if (app.getLoginItemSettings().wasOpenedAtLogin) win.hide();
  autoUpdater.autoDownload = true;
  autoUpdater.autoInstallOnAppQuit = true;
  autoUpdater.on('update-downloaded', () => {
    new Notification({ title: 'مساعد سين جيم', body: 'تم تنزيل تحديث جديد، جاري التثبيت وإعادة التشغيل...' }).show();
    setTimeout(() => { quitting = true; autoUpdater.quitAndInstall(true, true); }, 4000);
  });
  autoUpdater.on('error', () => {});
  checkUpdates(true);
  setInterval(() => checkUpdates(true), 10 * 60 * 1000); // كل 10 دقائق
});
app.on('window-all-closed', () => { /* يستمر في الشريط */ });
