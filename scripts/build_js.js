#!/usr/bin/env node
/**
 * v5.33.0：把 public/static/js/*.jsx 预编译成同名 .js
 *
 * 用站内自带的那份 Babel（public/static/vendor/babel.min.js）在 Node 里编译，
 * 不装任何 npm 包、不联网。preset 只开 react、runtime 固定 classic ——
 * 浏览器里原来就是 data-presets="react" 那套，产物照样是 React.createElement，
 * 换的只是「谁在什么时候编译」：从每个访客的手机挪到打包这一次。
 *
 * 用法：node scripts/build_js.js          编译全部
 *       node scripts/build_js.js --check  只核对现有产物是否与源码一致（打包门用，不动文件）
 */
'use strict';
const fs = require('fs');
const path = require('path');
global.self = global;
global.window = global;

const ROOT = path.resolve(__dirname, '..');
const BABEL = path.join(ROOT, 'public/static/vendor/babel.min.js');
const SRC = path.join(ROOT, 'public/static/js');
const CHECK = process.argv.indexOf('--check') >= 0;

function fail(msg) { console.error('FAIL ' + msg); process.exit(1); }

if (!fs.existsSync(BABEL)) { fail('缺 public/static/vendor/babel.min.js：编译用的就是站内这份'); }
const B = require(BABEL);
if (!B || typeof B.transform !== 'function') { fail('babel.min.js 起不来（拿不到 transform）'); }

// 谁引用了哪个脚本（view/*.html 是唯一入口）。改完之后页面引的是 .js，
// 这里两种后缀都记，报告才看得出「这个源码被哪个页面用着」。
const views = fs.readdirSync(path.join(ROOT, 'view')).filter(function (f) { return f.endsWith('.html'); });
const refs = {}, jsxRefs = [];
views.forEach(function (f) {
  const s = fs.readFileSync(path.join(ROOT, 'view', f), 'utf8').replace(/\r\n/g, '\n');
  (s.match(/\/static\/js\/[A-Za-z0-9_-]+\.jsx?/g) || []).forEach(function (m) {
    const base = m.replace('/static/js/', '').replace(/\.jsx?$/, '');
    refs[base] = (refs[base] || []).concat(f);
    if (/\.jsx$/.test(m)) { jsxRefs.push(f + ' → ' + m.replace('/static/js/', '')); }
  });
});

const sources = fs.readdirSync(SRC).filter(function (f) { return f.endsWith('.jsx'); });
if (!sources.length) { fail('public/static/js 下一个 .jsx 都没有'); }

let total = 0, rows = [];
sources.forEach(function (name) {
  const srcPath = path.join(SRC, name);
  const outPath = srcPath.replace(/\.jsx$/, '.js');
  const code = fs.readFileSync(srcPath, 'utf8').replace(/\r\n/g, '\n');
  let out;
  try {
    out = B.transform(code, { presets: [['react', { runtime: 'classic' }]], filename: name }).code;
  } catch (e) { fail(name + ' 编译不过：' + e.message); }
  // 产物里不该再有 JSX 尖括号语法，也不该走 automatic runtime 的 import
  if (/^\s*import\s+.*from\s+['"]react['"]/m.test(out)) { fail(name + ' 产物里出现了 import react（runtime 要 classic）'); }
  if (out.indexOf('React.createElement') < 0) { fail(name + ' 产物里没有 React.createElement，页面会白屏'); }
  total += Buffer.byteLength(out);
  if (CHECK) {
    if (!fs.existsSync(outPath)) { fail('缺产物：' + name.replace(/\.jsx$/, '.js')); }
    const onDisk = fs.readFileSync(outPath, 'utf8').replace(/\r\n/g, '\n');
    if (onDisk !== out) { fail('产物比源码旧：' + name.replace(/\.jsx$/, '.js') + '（跑一次 node scripts/build_js.js）'); }
  } else {
    fs.writeFileSync(outPath, out, 'utf8');
  }
  rows.push([name.replace(/\.jsx$/, '.js'), Buffer.byteLength(code), Buffer.byteLength(out),
    (refs[name.replace(/\.jsx$/, '')] || []).join(' ') || '(没页面引用)']);
});

// 引用核对：view 里还写着 .jsx 就是没换干净（浏览器不再加载 babel，那些文件不会被执行）
if (jsxRefs.length) { fail('这些页面还在引用 .jsx，要改成 .js：' + jsxRefs.join('、')); }

rows.forEach(function (r) {
  console.log((CHECK ? '一致   ' : '已编译 ') + r[0].padEnd(22) + ' 源 ' +
    String(r[1]).padStart(7) + ' → 产物 ' + String(r[2]).padStart(7) + '   被引用于 ' + r[3]);
});
console.log((CHECK ? '产物核对通过' : '编译完成') + '：' + rows.length + ' 个文件，合计 ' +
  total.toLocaleString() + ' 字节。');
