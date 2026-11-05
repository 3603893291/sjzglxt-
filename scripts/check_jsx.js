// 用项目自带的 @babel/standalone 校验 app.jsx / platform.jsx 语法
const fs = require('fs');
const path = require('path');

globalThis.window = undefined;
globalThis.self = globalThis;

const Babel = require(path.join(__dirname, '..', 'public', 'static', 'vendor', 'babel.min.js'));

const files = ['public/static/js/app.jsx', 'public/static/js/platform.jsx'];
let failed = false;
for (const f of files) {
  try {
    const src = fs.readFileSync(f, 'utf8');
    Babel.transform(src, { presets: [['react', { runtime: 'classic' }]], filename: f });
    console.log('OK   ' + f);
  } catch (e) {
    failed = true;
    console.error('FAIL ' + f + ' :: ' + e.message.split('\n')[0]);
  }
}
process.exit(failed ? 1 : 0);
