const { app, BrowserWindow, Menu, Tray, Notification, shell, nativeImage } = require('electron');
const path = require('path');
const { autoUpdater } = require('electron-updater');

let win, tray;
const lock = app.requestSingleInstanceLock();
if (!lock) { app.quit(); }
app.on('second-instance', () => { if (win) { if (win.isMinimized()) win.restore(); win.show(); win.focus(); } });

function createWindow() {
  win = new BrowserWindow({
    width: 1100, height: 780, title: 'مساعد سين جيم', autoHideMenuBar: true,
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: true },
  });
  win.loadFile(path.join(__dirname, 'www', 'index.html'));
  win.webContents.setWindowOpenHandler(({ url }) => { shell.openExternal(url); return { action: 'deny' }; });
  win.on('close', e => { if (!app.isQuiting) { e.preventDefault(); win.hide(); } }); // يبقى شغال في الخلفية
}

function createTray() {
  tray = new Tray(nativeImage.createEmpty());
  tray.setToolTip('مساعد سين جيم');
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'فتح', click: () => win.show() },
    { label: 'خروج', click: () => { app.isQuiting = true; app.quit(); } },
  ]));
  tray.on('click', () => win.show());
}

app.whenReady().then(() => {
  createWindow();
  createTray();
  autoUpdater.autoDownload = true;
  autoUpdater.on('update-downloaded', () => {
    new Notification({ title: 'مساعد سين جيم', body: 'تم تنزيل تحديث جديد، سيتم تثبيته عند إغلاق البرنامج.' }).show();
  });
  autoUpdater.checkForUpdatesAndNotify().catch(() => {});
  setInterval(() => autoUpdater.checkForUpdatesAndNotify().catch(() => {}), 6 * 3600 * 1000);
});
app.on('window-all-closed', () => { /* يستمر في الـ tray */ });
