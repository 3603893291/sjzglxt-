/* v5.10.0：Cookie 同意横幅组件（平台与战队站点通用）
 * 挂载点：<div id="cookie-consent-root"></div>
 * 逻辑：
 *   1. 加载时 GET /api/cookie-consent 拿横幅配置 + 当前访客已做的选择
 *   2. 未选择 → 显示横幅；已选择且 policy_version 未变 → 不显示
 *   3. 用户点击「同意」/「拒绝」/「仅必要」 → POST /api/cookie-consent 存后端 + 写 localStorage
 *   4. localStorage 里存 policy_version；下次访问若后端 version 更新则重新弹
 * 依赖：React / ReactDOM / MaterialUI（页面已加载）
 */
(function () {
  'use strict';

  if (!window.React || !window.ReactDOM || !window.MaterialUI) return;

  const { useState, useEffect } = React;
  const { Box, Button, Paper, Typography, Stack, Link } = MaterialUI;

  const SITE_BASE = window.SITE_BASE || '';
  const LS_KEY = 'cookie_consent_v5100';

  async function fetchJson(url, opts) {
    const init = Object.assign({ method: 'GET', credentials: 'same-origin' }, opts || {});
    if (init.body && typeof init.body === 'object' && !init.form) {
      init.headers = Object.assign({ 'Content-Type': 'application/json' }, init.headers || {});
      init.body = JSON.stringify(init.body);
    }
    const res = await fetch(url, init);
    try { return await res.json(); } catch (e) { return null; }
  }

  function readLocal() {
    try {
      const raw = localStorage.getItem(LS_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }
  function writeLocal(obj) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(obj)); } catch (e) { /* 隐私模式忽略 */ }
  }

  function CookieBanner() {
    const [cfg, setCfg] = useState(null);
    const [visible, setVisible] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      let cancelled = false;
      (async function () {
        const json = await fetchJson(SITE_BASE + '/api/cookie-consent');
        if (cancelled || !json || json.code !== 0 || !json.data) return;
        const d = json.data;
        setCfg(d);
        if (!d.enabled) return;

        // 本地已有选择 & 政策版本一致 → 不打扰
        const local = readLocal();
        if (local && local.policy_version === d.policy_version && local.choice) return;
        // 后端记录的选择（换设备/清缓存但登录状态在）也认
        if (d.choice && d.policy_version && local && local.policy_version === d.policy_version) return;

        setVisible(true);
      })();
      return function () { cancelled = true; };
    }, []);

    const submit = async function (choice) {
      if (!cfg || busy) return;
      setBusy(true);
      try {
        await fetchJson(SITE_BASE + '/api/cookie-consent', { method: 'POST', body: { choice: choice } });
      } catch (e) { /* 后端失败也写本地，避免每次都弹 */ }
      writeLocal({ choice: choice, policy_version: cfg.policy_version, at: Date.now() });
      setVisible(false);
      setBusy(false);
      // 触发一次自定义事件，业务代码可监听以启用/停用统计脚本
      try {
        window.dispatchEvent(new CustomEvent('cookie-consent', { detail: { choice: choice } }));
      } catch (e) { /* IE 无 CustomEvent */ }
    };

    if (!visible || !cfg) return null;

    return (
      <Paper elevation={6} sx={{
        m: { xs: 1, sm: 2 }, p: { xs: 2, sm: 2.5 },
        borderRadius: '16px', border: '1px solid #e8e8ed',
        bgcolor: 'rgba(255,255,255,0.98)', backdropFilter: 'blur(10px)',
        boxShadow: '0 -4px 24px rgba(0,0,0,0.08)',
      }}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems={{ xs: 'stretch', sm: 'center' }}>
          <Box sx={{ flex: 1, minWidth: 0 }}>
            <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>Cookie 与隐私说明</Typography>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', lineHeight: 1.6 }}>
              {cfg.text}
              {' '}
              <Link href={cfg.privacy_url} underline="hover" sx={{ fontSize: 'inherit' }}>隐私政策</Link>
              {' · '}
              <Link href={cfg.terms_url} underline="hover" sx={{ fontSize: 'inherit' }}>服务条款</Link>
            </Typography>
          </Box>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ minWidth: { sm: 320 } }}>
            <Button size="small" variant="contained" disabled={busy}
              onClick={() => submit('accepted')} sx={{ borderRadius: 999 }}>
              全部同意
            </Button>
            <Button size="small" variant="outlined" disabled={busy}
              onClick={() => submit('necessary_only')} sx={{ borderRadius: 999 }}>
              仅必要
            </Button>
            <Button size="small" disabled={busy}
              onClick={() => submit('rejected')} sx={{ borderRadius: 999, color: 'text.secondary' }}>
              拒绝
            </Button>
          </Stack>
        </Stack>
      </Paper>
    );
  }

  const mount = document.getElementById('cookie-consent-root');
  if (mount) {
    ReactDOM.createRoot(mount).render(<CookieBanner />);
  }
})();
