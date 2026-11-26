const fs = require('fs'), path = require('path');
const src = path.join(__dirname, '..', '..', 'mobile', 'www'), dst = path.join(__dirname, '..', 'www');
fs.rmSync(dst, { recursive: true, force: true });
fs.cpSync(src, dst, { recursive: true });
console.log('copied www');
