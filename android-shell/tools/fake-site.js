// Fake team site, used only to drive the APK's polling on an emulator.
// Field names and shapes are copied from the real controllers:
//   ChatDm::conversations -> data.list[].{peer_id,peer_nick,peer_name,unread,last_snip,same_team}
//   Chat::messages        -> data.{list[].{id,user_id,nickname,content}, me_id}
// State advances on every poll so an unread rise / new group row is guaranteed to appear.
const http = require('http');

let polls = 0;
let nextId = 100;
let dmUnread = 0;
let slowMs = 0;   // set via /setslow?ms=25000 to reproduce an overlapping poll round

const page = `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<style>body{margin:0;background:#f5f5f7;color:#1d1d1f;font-family:sans-serif}
header{position:fixed;top:0;left:0;right:0;height:56px;box-sizing:border-box;
  background:#fff;color:#1d1d1f;padding:18px 16px;font-size:17px;
  border-bottom:1px solid #e5e5ea;z-index:10}
main{padding-top:56px}
p{padding:14px 16px;margin:0}</style></head>
<body><header>战队首页第一行</header><main>
<p>本行必须完整可见，若顶部被状态栏压住这里会缺一角。</p>
<p id="tick">polls: 0</p>
</main>
<script>
fetch('/api/chat/dm/conversations').then(function(r){return r.json()}).then(function(j){
  document.getElementById('tick').textContent = 'dm unread=' + (j.data.list[0] ? j.data.list[0].unread : 'none');
});
</script></body></html>`;

function json(res, code, obj) {
  res.writeHead(code, {
    'Content-Type': 'application/json; charset=utf-8',
    'Set-Cookie': 'PHPSESSID=fake' + Date.now() + '; path=/; SameSite=Lax'
  });
  res.end(JSON.stringify(obj));
}

http.createServer((req, res) => {
  const u = new URL(req.url, 'http://localhost');
  console.log(new Date().toISOString().slice(11, 23), u.pathname + (u.search || ''));
  if (u.pathname === '/') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(page);
  }
  if (u.pathname === '/api/auth/me') {
    return json(res, 200, { code: 0, msg: 'ok', data: {
      id: 99, username: '10001', nickname: '测试号', role: 'user',
      team_id: 3, team_name: '暗夜战队' } });
  }
  if (u.pathname === '/setslow') {
    slowMs = parseInt(u.searchParams.get('ms') || '0', 10) || 0;
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    return res.end('slowMs=' + slowMs);
  }
  if (u.pathname === '/api/chat/dm/conversations') {
    polls++;
    dmUnread++;
    const unread = dmUnread;
    const body = { code: 0, msg: 'ok', data: { list: [
      { peer_id: 7, peer_nick: '张三', peer_name: '张三', peer_qq: '****1234',
        same_team: 1, unread: unread, msgs: 5,
        last_snip: unread ? '今晚八点集合打榜' : '', last_at: '2026-10-16 20:00:00', last_mine: 0 }
    ], online_count: 3, dm_enabled: 1 } };
    if (slowMs > 0) {
      return setTimeout(() => json(res, 200, body), slowMs);
    }
    return json(res, 200, body);
  }
  if (u.pathname === '/api/chat/messages') {
    const after = parseInt(u.searchParams.get('after_id') || '0', 10);
    const row = { id: nextId++, user_id: 42, nickname: '李四', qq: '****5678',
      content: '群里的新消息来了', created_at: '2026-10-16 20:01:00', role_tag: null };
    return json(res, 200, { code: 0, msg: 'ok', data: {
      list: row.id > after ? [row] : [], me_id: 99, is_admin: false, global_mute: false } });
  }
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('nope');
}).listen(3777, '127.0.0.1', () => console.log('fake site on 3777, polls will advance'));
