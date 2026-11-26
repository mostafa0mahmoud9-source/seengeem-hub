const { contextBridge, ipcRenderer } = require('electron');
// جسر آمن بين الواجهة وبرنامج الكمبيوتر (يُقبل فقط من واجهة التطبيق الرسمية)
contextBridge.exposeInMainWorld('sgDesktop', {
  isDesktop: true,
  getState: () => ipcRenderer.invoke('sg:state'),
  setConfig: c => ipcRenderer.invoke('sg:config', c),
  connect: ch => ipcRenderer.invoke('sg:connect', ch),
  disable: ch => ipcRenderer.invoke('sg:disable', ch),
  logout: ch => ipcRenderer.invoke('sg:logout', ch),
  onState: cb => { ipcRenderer.on('sg:state', (_e, s) => cb(s)); },
});
