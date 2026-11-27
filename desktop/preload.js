const { contextBridge, ipcRenderer } = require('electron');
// جسر آمن بين الواجهة وبرنامج الكمبيوتر (يُقبل فقط من واجهة التطبيق الرسمية)
contextBridge.exposeInMainWorld('sgDesktop', {
  isDesktop: true,
  getState: () => ipcRenderer.invoke('sg:state'),
  setConfig: c => ipcRenderer.invoke('sg:config', c),
  connect: ch => ipcRenderer.invoke('sg:connect', ch),
  disable: ch => ipcRenderer.invoke('sg:disable', ch),
  takeover: () => ipcRenderer.invoke('sg:takeover'),
  pair: (ch, ph) => ipcRenderer.invoke('sg:pair', ch, ph),
  logout: ch => ipcRenderer.invoke('sg:logout', ch),
  setAge: (ch, d) => ipcRenderer.invoke('sg:age', ch, d),
  getSetup: () => ipcRenderer.invoke('sg:setup-pending'),
  onSetup: cb => { ipcRenderer.on('sg:setup', (_e, c) => cb(c)); },
  onState: cb => { ipcRenderer.on('sg:state', (_e, s) => cb(s)); },
});
