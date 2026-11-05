/* v5.10.0：平台超管「SEO 与合规」面板
 * 通过 window.PlatformCompliance 暴露给 platform.jsx 的 SuperConsole 挂载
 * 子面板：SEO / 政策 / Cookie / Sitemap / 死链 / API 日志 / 图片压缩
 * 依赖：React / MaterialUI；接口全部走 /api/platform/admin/*
 */
(function () {
  'use strict';

  if (!window.React || !window.MaterialUI) return;

  const { useState, useEffect, useCallback } = React;
  const {
    Box, Paper, Typography, TextField, Button, Alert, Link, Tabs, Tab,
    Table, TableBody, TableCell, TableHead, TableRow, TableContainer,
    Dialog, DialogTitle, DialogContent, DialogActions, Chip, Stack,
    CircularProgress, Divider, FormControlLabel, Switch,
    Select, MenuItem, InputLabel, FormControl,
  } = MaterialUI;

  async function api(url, options) {
    const opts = options || {};
    const init = { method: opts.method || 'GET', credentials: 'same-origin' };
    if (opts.body !== undefined && opts.body !== null) {
      init.headers = { 'Content-Type': 'application/json' };
      init.body = JSON.stringify(opts.body);
    }
    const res = await fetch(url, init);
    if (res.status === 401) { location.href = '/login'; throw new Error('unauthorized'); }
    let json = null;
    try { json = await res.json(); } catch (e) {}
    if (!json) throw new Error('服务器响应异常');
    return json;
  }

  // 简易 toast：优先用 platform.jsx 全局注册的 window.__toast，退化为 alert
  function toast(msg, sev) {
    if (typeof window.__toast === 'function') { window.__toast(msg, sev); return; }
    if (sev === 'error') alert(msg);
  }

  function fmtTime(s) { return s ? String(s).replace('T', ' ').slice(0, 16) : '-'; }

  // ==================== SEO 子面板 ====================
  function SeoPanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try { const j = await api('/api/platform/admin/seo'); if (j.code === 0) setD(j.data); } catch (e) {}
    }, []);
    useEffect(function () { load(); }, [load]);
    if (!d) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    const save = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/seo', { method: 'POST', body: d });
        toast(j.msg || (j.code === 0 ? '已保存' : '失败'), j.code === 0 ? 'success' : 'error');
      } catch (e) { toast('网络异常', 'error'); } finally { setBusy(false); }
    };

    const upd = function (k) { return function (e) { setD(Object.assign({}, d, { [k]: e.target.value })); }; };

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          以下配置影响所有平台页面（根路径）的 &lt;title&gt; / meta description / Open Graph / Twitter Card。
          战队站点若未单独配置，则回落到这里的默认值。
        </Alert>
        <Stack spacing={2}>
          <TextField label="站点标题（title）" fullWidth required
            value={d.site_title} onChange={upd('site_title')}
            helperText="显示在浏览器标签与搜索结果标题，建议 ≤ 60 字符" />
          <TextField label="站点描述（meta description）" fullWidth multiline minRows={3}
            value={d.site_description} onChange={upd('site_description')}
            helperText={'搜索结果标题下方展示。建议 120-160 字符。当前 ' + (d.site_description || '').length + ' 字符'} />
          <TextField label="关键词（meta keywords）" fullWidth
            value={d.keywords} onChange={upd('keywords')}
            helperText="英文逗号分隔。Google 已忽略此字段，但百度/搜狗/360 仍会参考" />
          <TextField label="社交分享预览图（og:image）" fullWidth
            value={d.og_image} onChange={upd('og_image')}
            placeholder="https://your-domain.com/storage/uploads/xxx.jpg 或 /storage/uploads/xxx.jpg"
            helperText="QQ/微信/Facebook/LinkedIn 分享时显示的封面图，建议 1200×630" />
          <TextField label="canonical 基础 URL" fullWidth
            value={d.canonical_base} onChange={upd('canonical_base')}
            placeholder="https://your-domain.com"
            helperText="留空则按当前请求推导；配置后所有 canonical/og:url 都以此为前缀" />
          <Box>
            {d.og_image && (
              <Box sx={{ mt: 1 }}>
                <Typography variant="caption" color="text.secondary">预览：</Typography>
                <Box component="img" src={d.og_image} alt="og preview"
                  sx={{ maxWidth: 320, maxHeight: 180, display: 'block', mt: 0.5, borderRadius: '8px', border: '1px solid #e5e5ea' }}
                  onError={function (e) { e.target.style.display = 'none'; }} />
              </Box>
            )}
          </Box>
          <Box>
            <Button variant="contained" onClick={save} disabled={busy}>{busy ? '保存中...' : '保存 SEO 配置'}</Button>
            <Button sx={{ ml: 1 }} onClick={load} disabled={busy}>重新加载</Button>
          </Box>
        </Stack>
      </Box>
    );
  }

  // ==================== 隐私政策 / 服务条款 子面板 ====================
  function PoliciesPanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try { const j = await api('/api/platform/admin/policies'); if (j.code === 0) setD(j.data); } catch (e) {}
    }, []);
    useEffect(function () { load(); }, [load]);
    if (!d) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    const save = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/policies', { method: 'POST', body: { privacy: d.privacy, terms: d.terms } });
        toast(j.msg || (j.code === 0 ? '已保存' : '失败'), j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) { toast('网络异常', 'error'); } finally { setBusy(false); }
    };

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          公开访问：<Link href="/privacy" target="_blank" underline="hover">/privacy</Link>{' · '}
          <Link href="/terms" target="_blank" underline="hover">/terms</Link>。
          战队站点可通过自身后台的相同字段覆盖，未覆盖时使用这里的默认值。
          纯文本存储，前台输出会自动转义并保留换行。<b>保存后 policy_version 会更新，已同意用户会重新弹一次横幅。</b>
        </Alert>
        {d.updated_at && (
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
            最近保存：{fmtTime(d.updated_at)}
          </Typography>
        )}
        <Stack spacing={2}>
          <TextField label="隐私政策（Privacy Policy）" fullWidth multiline minRows={14}
            value={d.privacy}
            onChange={function (e) { setD(Object.assign({}, d, { privacy: e.target.value })); }}
            helperText={'当前 ' + (d.privacy || '').length + ' / 20000 字符'} />
          <TextField label="服务条款（Terms of Service）" fullWidth multiline minRows={14}
            value={d.terms}
            onChange={function (e) { setD(Object.assign({}, d, { terms: e.target.value })); }}
            helperText={'当前 ' + (d.terms || '').length + ' / 20000 字符'} />
          <Box>
            <Button variant="contained" onClick={save} disabled={busy}>{busy ? '保存中...' : '保存并更新版本号'}</Button>
            <Button sx={{ ml: 1 }} onClick={load} disabled={busy}>重新加载</Button>
          </Box>
        </Stack>
      </Box>
    );
  }

  // ==================== Cookie 同意 子面板 ====================
  function CookiePanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try { const j = await api('/api/platform/admin/cookie-consent'); if (j.code === 0) setD(j.data); } catch (e) {}
    }, []);
    useEffect(function () { load(); }, [load]);
    if (!d) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    const save = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/cookie-consent', {
          method: 'POST',
          body: { enabled: d.enabled ? '1' : '0', text: d.text },
        });
        toast(j.msg || (j.code === 0 ? '已保存' : '失败'), j.code === 0 ? 'success' : 'error');
      } catch (e) { toast('网络异常', 'error'); } finally { setBusy(false); }
    };

    const s = d.stats || {};
    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          启用后所有平台与战队站点页面底部会出现 Cookie 同意横幅；用户选择会记录到 <code>cookie_consent</code> 表用于合规审计。
        </Alert>
        <Stack spacing={2}>
          <FormControlLabel
            control={<Switch checked={!!d.enabled}
              onChange={function (e) { setD(Object.assign({}, d, { enabled: e.target.checked })); }} />}
            label="启用 Cookie 同意横幅" />
          <TextField label="横幅提示文本" fullWidth multiline minRows={3}
            value={d.text}
            onChange={function (e) { setD(Object.assign({}, d, { text: e.target.value })); }}
            helperText={'当前 ' + (d.text || '').length + ' / 500 字符。会自动追加「隐私政策」「服务条款」链接'} />
          <Box>
            <Button variant="contained" onClick={save} disabled={busy}>{busy ? '保存中...' : '保存配置'}</Button>
            <Button sx={{ ml: 1 }} onClick={load} disabled={busy}>重新加载</Button>
          </Box>
          <Divider />
          <Typography variant="subtitle2" fontWeight={600}>用户选择统计</Typography>
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
            <Chip label={'全部同意：' + (s.accepted || 0)} color="success" variant="outlined" />
            <Chip label={'仅必要：' + (s.necessary_only || 0)} color="info" variant="outlined" />
            <Chip label={'拒绝：' + (s.rejected || 0)} color="default" variant="outlined" />
            <Chip label={'合计：' + (s.total || 0)} color="primary" />
          </Stack>
        </Stack>
      </Box>
    );
  }

  // ==================== Sitemap 子面板 ====================
  function SitemapPanel() {
    const [d, setD] = useState(null);
    const [baseUrl, setBaseUrl] = useState('');
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/sitemap');
        if (j.code === 0) { setD(j.data); setBaseUrl(j.data.base_url || ''); }
      } catch (e) {}
    }, []);
    useEffect(function () { load(); }, [load]);
    if (!d) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    const generate = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/sitemap/generate', { method: 'POST', body: { base_url: baseUrl } });
        toast(j.msg || (j.code === 0 ? '已生成' : '失败'), j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) { toast('生成失败：' + e.message, 'error'); } finally { setBusy(false); }
    };

    const toggle = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/sitemap/toggle', { method: 'POST', body: { enabled: d.enabled ? '0' : '1' } });
        toast(j.msg, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          生成后写入 <code>public/sitemap.xml</code>（索引）、<code>public/sitemap-platform.xml</code>、<code>public/sitemap-{'{slug}'}.xml</code>（每个战队一份）。
          Nginx/Apache 会直接吐这些静态文件，不占 PHP 资源。
          建议：新战队入驻或政策变更后手动重新生成一次；也可用外部 cron 每周触发 <code>POST /api/platform/admin/sitemap/generate</code>（需 X-Api-Key）。
        </Alert>
        <Stack spacing={2}>
          <FormControlLabel
            control={<Switch checked={!!d.enabled} onChange={toggle} disabled={busy} />}
            label="启用 Sitemap 生成（关闭后仍可保留已生成文件）" />
          <TextField label="站点根 URL（可选，留空则用 SEO 里的 canonical_base 或当前请求 Host）" fullWidth
            value={baseUrl} onChange={function (e) { setBaseUrl(e.target.value); }}
            placeholder="https://your-domain.com" />
          <Stack direction="row" spacing={1}>
            <Button variant="contained" onClick={generate} disabled={busy}>{busy ? '生成中...' : '立即生成全部 Sitemap'}</Button>
            <Button onClick={load} disabled={busy}>刷新状态</Button>
            {d.index && <Button component="a" href="/sitemap.xml" target="_blank">查看 sitemap.xml</Button>}
          </Stack>
          <Divider />
          <Typography variant="subtitle2" fontWeight={600}>当前状态</Typography>
          <Stack spacing={0.5}>
            <Typography variant="body2">启用：<Chip size="small" label={d.enabled ? '是' : '否'} color={d.enabled ? 'success' : 'default'} /></Typography>
            <Typography variant="body2">最近生成：{d.last_generated ? fmtTime(d.last_generated) : '尚未生成'}</Typography>
            <Typography variant="body2">解析到的站点根 URL：<code>{d.base_url || '（未配置）'}</code></Typography>
            <Typography variant="body2">索引文件：{d.index ? (d.index.mtime + '（' + d.index.size + ' 字节）') : '不存在'}</Typography>
            <Typography variant="body2">平台子图：{d.platform ? (d.platform.mtime + '（' + d.platform.size + ' 字节）') : '不存在'}</Typography>
            <Typography variant="body2">战队子图数量：{d.tenant_files}</Typography>
          </Stack>
        </Stack>
      </Box>
    );
  }

  // ==================== 死链检测 子面板 ====================
  function BrokenLinksPanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const [filter, setFilter] = useState({ tenant_id: -1, resolved: '0', source_type: '', page: 1 });
    const [addUrl, setAddUrl] = useState('');
    const [addNote, setAddNote] = useState('');
    const [showAdd, setShowAdd] = useState(false);

    const load = useCallback(async function () {
      try {
        const q = new URLSearchParams();
        Object.keys(filter).forEach(function (k) { q.set(k, String(filter[k])); });
        const j = await api('/api/platform/admin/broken-links?' + q.toString());
        if (j.code === 0) setD(j.data);
      } catch (e) {}
    }, [filter]);
    useEffect(function () { load(); }, [load]);

    const scan = async function () {
      if (!window.confirm('立即扫描全站链接？单次最多 100 URL，可能需要 1-3 分钟')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/broken-links/scan', { method: 'POST', body: { sources: [] } });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) { toast('扫描失败：' + e.message, 'error'); } finally { setBusy(false); }
    };

    const addManual = async function () {
      if (!addUrl.trim()) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/broken-links/add', {
          method: 'POST', body: { url: addUrl.trim(), tenant_id: 0, note: addNote.trim() },
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { setShowAdd(false); setAddUrl(''); setAddNote(''); load(); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    const act = async function (id, action) {
      try {
        const url = action === 'remove'
          ? '/api/platform/admin/broken-links/' + id
          : '/api/platform/admin/broken-links/' + id + '/' + action;
        const j = await api(url, { method: action === 'remove' ? 'DELETE' : 'POST' });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (!d) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          数据源：枪码描述 / 公告正文 / 攻略正文（聊天消息默认不扫，量大）。
          单次扫描最多 100 URL，HEAD 探测超时 8 秒；405/501 时回落 GET。
          最近扫描：{d.last_scan ? fmtTime(d.last_scan) : '尚未扫描'}
        </Alert>
        <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
          <Button variant="contained" onClick={scan} disabled={busy}>{busy ? '扫描中...' : '立即扫描全站'}</Button>
          <Button onClick={function () { setShowAdd(true); }}>手动检查 URL</Button>
          <FormControl size="small" sx={{ minWidth: 130 }}>
            <InputLabel>租户</InputLabel>
            <Select label="租户" value={filter.tenant_id}
              onChange={function (e) { setFilter(Object.assign({}, filter, { tenant_id: Number(e.target.value), page: 1 })); }}>
              <MenuItem value={-1}>全部</MenuItem>
              <MenuItem value={0}>平台</MenuItem>
            </Select>
          </FormControl>
          <FormControl size="small" sx={{ minWidth: 130 }}>
            <InputLabel>状态</InputLabel>
            <Select label="状态" value={filter.resolved}
              onChange={function (e) { setFilter(Object.assign({}, filter, { resolved: e.target.value, page: 1 })); }}>
              <MenuItem value="">全部</MenuItem>
              <MenuItem value="0">未解决</MenuItem>
              <MenuItem value="1">已解决</MenuItem>
            </Select>
          </FormControl>
          <FormControl size="small" sx={{ minWidth: 140 }}>
            <InputLabel>来源类型</InputLabel>
            <Select label="来源类型" value={filter.source_type}
              onChange={function (e) { setFilter(Object.assign({}, filter, { source_type: e.target.value, page: 1 })); }}>
              <MenuItem value="">全部</MenuItem>
              <MenuItem value="gun_code">枪码</MenuItem>
              <MenuItem value="announcement">公告</MenuItem>
              <MenuItem value="guide">攻略</MenuItem>
              <MenuItem value="chat">聊天</MenuItem>
              <MenuItem value="manual">手动</MenuItem>
            </Select>
          </FormControl>
          <Typography variant="body2" sx={{ alignSelf: 'center' }}>共 {d.total} 条</Typography>
        </Stack>

        {d.list.length === 0 ? <Alert severity="success">当前筛选下没有死链记录</Alert> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>URL</TableCell><TableCell>来源</TableCell><TableCell>所属</TableCell>
                  <TableCell>状态码</TableCell><TableCell>失败次数</TableCell><TableCell>最近检查</TableCell>
                  <TableCell>标记</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {d.list.map(function (r) {
                  return (
                    <TableRow key={r.id}>
                      <TableCell sx={{ maxWidth: 320 }}>
                        <Link href={r.url} target="_blank" underline="hover"
                          sx={{ fontSize: 12, wordBreak: 'break-all' }}>{r.url}</Link>
                        {r.error_msg && <Typography variant="caption" color="error" sx={{ display: 'block' }}>{r.error_msg}</Typography>}
                      </TableCell>
                      <TableCell>
                        <Typography variant="caption">{r.source_type}#{r.source_id}</Typography>
                        {r.source_ref && <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{r.source_ref}</Typography>}
                      </TableCell>
                      <TableCell>{r.tenant_name}</TableCell>
                      <TableCell>
                        <Chip size="small" label={r.status_code || '连接失败'}
                          color={r.status_code >= 400 || r.status_code === 0 ? 'error' : 'success'} variant="outlined" />
                      </TableCell>
                      <TableCell>{r.hit_count}</TableCell>
                      <TableCell>{fmtTime(r.last_check_at)}</TableCell>
                      <TableCell>
                        {r.resolved ? <Chip size="small" label="已解决" color="success" /> : <Chip size="small" label="未解决" color="warning" variant="outlined" />}
                      </TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={0.5}>
                          {r.resolved
                            ? <Button size="small" onClick={function () { act(r.id, 'unresolve'); }}>撤销</Button>
                            : <Button size="small" onClick={function () { act(r.id, 'resolve'); }}>标为已解决</Button>}
                          <Button size="small" color="error" onClick={function () {
                            if (window.confirm('删除该死链记录？')) act(r.id, 'remove');
                          }}>删除</Button>
                        </Stack>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        {d.total > d.size && (
          <Stack direction="row" spacing={1} sx={{ mt: 2, justifyContent: 'center' }}>
            <Button size="small" disabled={d.page <= 1}
              onClick={function () { setFilter(Object.assign({}, filter, { page: d.page - 1 })); }}>上一页</Button>
            <Typography variant="body2" sx={{ alignSelf: 'center' }}>第 {d.page} / {Math.ceil(d.total / d.size)} 页</Typography>
            <Button size="small" disabled={d.page >= Math.ceil(d.total / d.size)}
              onClick={function () { setFilter(Object.assign({}, filter, { page: d.page + 1 })); }}>下一页</Button>
          </Stack>
        )}

        <Dialog open={showAdd} onClose={function () { setShowAdd(false); }} fullWidth maxWidth="sm">
          <DialogTitle>手动检查外部 URL</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ mt: 1 }}>
              <TextField label="URL" fullWidth required value={addUrl}
                onChange={function (e) { setAddUrl(e.target.value); }} placeholder="https://example.com" />
              <TextField label="备注（可选）" fullWidth value={addNote}
                onChange={function (e) { setAddNote(e.target.value); }} />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={function () { setShowAdd(false); }}>取消</Button>
            <Button variant="contained" onClick={addManual} disabled={busy || !addUrl.trim()}>检查并入库</Button>
          </DialogActions>
        </Dialog>
      </Box>
    );
  }

  // ==================== API 日志 子面板 ====================
  function ApiLogsPanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const [filter, setFilter] = useState({ hours: 24, result: '', scope: '', page: 1 });
    const load = useCallback(async function () {
      try {
        const q = new URLSearchParams();
        Object.keys(filter).forEach(function (k) { q.set(k, String(filter[k])); });
        const j = await api('/api/platform/admin/api-logs?' + q.toString());
        if (j.code === 0) setD(j.data);
      } catch (e) {}
    }, [filter]);
    useEffect(function () { load(); }, [load]);

    const clearOld = async function () {
      const days = window.prompt('删除多少天前的日志？（1-365）', '30');
      if (!days) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/api-logs/clear', { method: 'POST', body: { days: Number(days) } });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    if (!d) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;
    const s = d.stats || {};
    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          记录所有走 X-Api-Key 的私有 API 调用（成功与鉴权失败均记）。90 天以上的日志建议定期清理。
          近 {s.window_hours || 24} 小时：成功 {s.ok || 0} / 失败 {s.fail || 0}
        </Alert>
        <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
          <FormControl size="small" sx={{ minWidth: 130 }}>
            <InputLabel>时间窗口</InputLabel>
            <Select label="时间窗口" value={filter.hours}
              onChange={function (e) { setFilter(Object.assign({}, filter, { hours: Number(e.target.value), page: 1 })); }}>
              <MenuItem value={1}>近 1 小时</MenuItem>
              <MenuItem value={24}>近 24 小时</MenuItem>
              <MenuItem value={168}>近 7 天</MenuItem>
              <MenuItem value={720}>近 30 天</MenuItem>
            </Select>
          </FormControl>
          <FormControl size="small" sx={{ minWidth: 120 }}>
            <InputLabel>结果</InputLabel>
            <Select label="结果" value={filter.result}
              onChange={function (e) { setFilter(Object.assign({}, filter, { result: e.target.value, page: 1 })); }}>
              <MenuItem value="">全部</MenuItem>
              <MenuItem value="1">成功</MenuItem>
              <MenuItem value="0">失败</MenuItem>
            </Select>
          </FormControl>
          <FormControl size="small" sx={{ minWidth: 130 }}>
            <InputLabel>Scope</InputLabel>
            <Select label="Scope" value={filter.scope}
              onChange={function (e) { setFilter(Object.assign({}, filter, { scope: e.target.value, page: 1 })); }}>
              <MenuItem value="">全部</MenuItem>
              <MenuItem value="platform">platform</MenuItem>
              <MenuItem value="tenant">tenant</MenuItem>
              <MenuItem value="external">external</MenuItem>
            </Select>
          </FormControl>
          <Button onClick={clearOld} disabled={busy}>清理旧日志</Button>
          <Typography variant="body2" sx={{ alignSelf: 'center' }}>共 {d.total} 条</Typography>
        </Stack>

        {d.list.length === 0 ? <Alert severity="success">当前筛选下没有日志</Alert> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>时间</TableCell><TableCell>Key</TableCell><TableCell>Scope</TableCell>
                  <TableCell>方法</TableCell><TableCell>路径</TableCell><TableCell>状态</TableCell>
                  <TableCell>IP</TableCell><TableCell>耗时</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {d.list.map(function (r) {
                  return (
                    <TableRow key={r.id}>
                      <TableCell>{fmtTime(r.created_at)}</TableCell>
                      <TableCell>
                        <Typography variant="caption">#{r.api_key_id} {r.key_prefix}…</Typography>
                      </TableCell>
                      <TableCell><Chip size="small" label={r.scope} variant="outlined" /></TableCell>
                      <TableCell>{r.method}</TableCell>
                      <TableCell sx={{ maxWidth: 280 }}>
                        <Typography variant="caption" sx={{ wordBreak: 'break-all' }}>{r.path}</Typography>
                      </TableCell>
                      <TableCell>
                        <Chip size="small"
                          label={r.result ? r.status : (r.status + ' ' + r.fail_reason)}
                          color={r.result ? 'success' : 'error'} variant="outlined" />
                      </TableCell>
                      <TableCell><Typography variant="caption">{r.ip}</Typography></TableCell>
                      <TableCell>{r.duration_ms} ms</TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        {d.total > d.size && (
          <Stack direction="row" spacing={1} sx={{ mt: 2, justifyContent: 'center' }}>
            <Button size="small" disabled={d.page <= 1}
              onClick={function () { setFilter(Object.assign({}, filter, { page: d.page - 1 })); }}>上一页</Button>
            <Typography variant="body2" sx={{ alignSelf: 'center' }}>第 {d.page} / {Math.ceil(d.total / d.size)} 页</Typography>
            <Button size="small" disabled={d.page >= Math.ceil(d.total / d.size)}
              onClick={function () { setFilter(Object.assign({}, filter, { page: d.page + 1 })); }}>下一页</Button>
          </Stack>
        )}
      </Box>
    );
  }

  // ==================== API 限频 & 图片压缩 合并子面板 ====================
  function RuntimePanel() {
    const [rate, setRate] = useState(null);
    const [img, setImg] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const [r1, r2] = await Promise.all([
          api('/api/platform/admin/api-rate'),
          api('/api/platform/admin/image-compress'),
        ]);
        if (r1.code === 0) setRate(r1.data);
        if (r2.code === 0) setImg(r2.data);
      } catch (e) {}
    }, []);
    useEffect(function () { load(); }, [load]);

    if (!rate || !img) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    // API 限频与战绩限频走同一个接口、同一份 state：接口一次校验并落库全部字段，
    // 所以任一侧保存都提交整表，避免只传一半把另一半重置成后端默认值。
    const saveConfig = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/api-rate', {
          method: 'POST',
          body: Object.assign({}, rate, { stats_enabled: rate.stats_enabled ? '1' : '0' }),
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };
    const saveImg = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/image-compress', {
          method: 'POST',
          body: {
            enabled: img.enabled ? '1' : '0',
            quality: img.quality, max_width: img.max_width, max_height: img.max_height,
          },
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Stack spacing={3}>
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>API 限频（ApiKeyAuth）</Typography>
          <Alert severity="info" sx={{ mb: 2 }}>
            双层限频：每 Key 与每 IP 独立计数。5 分钟内累计 20 次认证失败的 IP 会被自动锁定 15 分钟。
          </Alert>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="窗口（秒）" type="number" size="small" sx={{ flex: 1 }}
              value={rate.rate_window}
              onChange={function (e) { setRate(Object.assign({}, rate, { rate_window: Number(e.target.value) })); }}
              helperText="5 - 3600" />
            <TextField label="每 Key 上限（次/窗口）" type="number" size="small" sx={{ flex: 1 }}
              value={rate.rate_limit}
              onChange={function (e) { setRate(Object.assign({}, rate, { rate_limit: Number(e.target.value) })); }}
              helperText="1 - 10000" />
            <TextField label="每 IP 上限（次/窗口）" type="number" size="small" sx={{ flex: 1 }}
              value={rate.ip_rate_limit}
              onChange={function (e) { setRate(Object.assign({}, rate, { ip_rate_limit: Number(e.target.value) })); }}
              helperText="1 - 100000" />
          </Stack>
          <Box sx={{ mt: 2 }}>
            <Button variant="contained" onClick={saveConfig} disabled={busy}>保存限频配置</Button>
          </Box>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>战绩上传限频（成员自助接口）</Typography>
          <Alert severity="info" sx={{ mb: 2 }}>
            成员用自己的账号密码登录后调 <code>POST /api/stats/upload</code> 上传战绩，无 API Key，
            因此按「登录账号 + 来源 IP」两个维度独立计数。配置对全站所有战队生效，各战队不能自行覆盖。
            关闭开关只挡写入，不影响成员与管理员查看已有战绩。
          </Alert>
          <FormControlLabel
            control={<Switch checked={!!rate.stats_enabled}
              onChange={function (e) { setRate(Object.assign({}, rate, { stats_enabled: e.target.checked })); }} />}
            label="允许成员上传战绩" />
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ mt: 1 }}>
            <TextField label="窗口（秒）" type="number" size="small" sx={{ flex: 1 }}
              value={rate.stats_window}
              onChange={function (e) { setRate(Object.assign({}, rate, { stats_window: Number(e.target.value) })); }}
              helperText="5 - 3600" />
            <TextField label="每账号上限（次/窗口）" type="number" size="small" sx={{ flex: 1 }}
              value={rate.stats_limit}
              onChange={function (e) { setRate(Object.assign({}, rate, { stats_limit: Number(e.target.value) })); }}
              helperText="1 - 1000" />
            <TextField label="每 IP 上限（次/窗口）" type="number" size="small" sx={{ flex: 1 }}
              value={rate.stats_ip_limit}
              onChange={function (e) { setRate(Object.assign({}, rate, { stats_ip_limit: Number(e.target.value) })); }}
              helperText="1 - 100000" />
          </Stack>
          <Box sx={{ mt: 2 }}>
            <Button variant="contained" onClick={saveConfig} disabled={busy}>保存战绩限频配置</Button>
          </Box>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>图片自动压缩</Typography>
          {!img.gd_loaded && (
            <Alert severity="warning" sx={{ mb: 2 }}>
              当前 PHP 未启用 GD 扩展，无法进行服务端压缩。请联系运维安装 <code>php-gd</code>。
            </Alert>
          )}
          <Alert severity="info" sx={{ mb: 2 }}>
            启用后所有走 <code>ImageUpload::save()</code> 的上传（游戏图片 / 注册截图 / 公告配图 / 攻略封面）都会自动压缩。
            已上传的图片不受影响；<code>?thumb=1</code> 参数可在下载时按需生成缩略图（不改磁盘文件）。
          </Alert>
          <FormControlLabel
            control={<Switch checked={!!img.enabled} disabled={!img.gd_loaded}
              onChange={function (e) { setImg(Object.assign({}, img, { enabled: e.target.checked })); }} />}
            label="启用图片自动压缩" />
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ mt: 1 }}>
            <TextField label="JPEG/WebP 质量（30-95）" type="number" size="small" sx={{ flex: 1 }}
              value={img.quality}
              onChange={function (e) { setImg(Object.assign({}, img, { quality: Number(e.target.value) })); }}
              helperText="推荐 80-85，肉眼几乎无差" />
            <TextField label="最大宽度（像素）" type="number" size="small" sx={{ flex: 1 }}
              value={img.max_width}
              onChange={function (e) { setImg(Object.assign({}, img, { max_width: Number(e.target.value) })); }}
              helperText="超出等比缩放；320-4096" />
            <TextField label="最大高度（像素）" type="number" size="small" sx={{ flex: 1 }}
              value={img.max_height}
              onChange={function (e) { setImg(Object.assign({}, img, { max_height: Number(e.target.value) })); }}
              helperText="320-4096" />
          </Stack>
          <Box sx={{ mt: 2 }}>
            <Button variant="contained" onClick={saveImg} disabled={busy || !img.gd_loaded}>保存压缩配置</Button>
          </Box>
        </Paper>
      </Stack>
    );
  }

  // ==================== 顶层组件 ====================
  function PlatformCompliance() {
    const [sub, setSub] = useState('seo');
    return (
      <Box>
        <Tabs value={sub} onChange={function (e, v) { setSub(v); }}
          sx={{ borderBottom: 1, borderColor: 'divider', mb: 2 }}
          variant="scrollable" scrollButtons="auto">
          <Tab value="seo" label="SEO 元数据" />
          <Tab value="policies" label="隐私/条款" />
          <Tab value="cookie" label="Cookie 同意" />
          <Tab value="sitemap" label="Sitemap" />
          <Tab value="broken" label="死链检测" />
          <Tab value="apilogs" label="API 日志" />
          <Tab value="runtime" label="限频 / 压缩" />
        </Tabs>
        {sub === 'seo'      && <SeoPanel />}
        {sub === 'policies' && <PoliciesPanel />}
        {sub === 'cookie'   && <CookiePanel />}
        {sub === 'sitemap'  && <SitemapPanel />}
        {sub === 'broken'   && <BrokenLinksPanel />}
        {sub === 'apilogs'  && <ApiLogsPanel />}
        {sub === 'runtime'  && <RuntimePanel />}
      </Box>
    );
  }

  window.PlatformCompliance = PlatformCompliance;
})();
