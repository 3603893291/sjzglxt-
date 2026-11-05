/* 战队入驻平台（v5.0.0：独立注册登录体系，面向入驻战队 + 平台超管）
 * 页面由 view/platform.html 承载，PLATFORM_MODE = login / register / app
 * 平台运行在根路径（无目录前缀），所有接口走 /api/platform/...
 */
(function () {
  'use strict';

  const { useState, useEffect, useCallback, useRef } = React;
  const {
    ThemeProvider, createTheme, CssBaseline,
    Box, Paper, Typography, TextField, Button, Alert, Link, Tabs, Tab,
    Table, TableBody, TableCell, TableHead, TableRow, TableContainer,
    Dialog, DialogTitle, DialogContent, DialogActions, Chip, Stack,
    Snackbar, CircularProgress, LinearProgress, Divider, FormControlLabel, Switch, Checkbox,
    Select, MenuItem, InputLabel,
    AppBar, Toolbar, Drawer, List, ListItemButton, ListItemIcon, ListItemText,
    IconButton, Tooltip, useMediaQuery, alpha,
  } = MaterialUI;

  const MODE = window.PLATFORM_MODE || 'app';

  // v5.27.0：入驻页与战队内部登录页共用一套令牌（见 auth.jsx），两栏版式靠这几个值对齐
  const APPLE_BLUE = '#0066cc';
  const BG_GRAY = '#f5f5f7';
  const TEXT_PRIMARY = '#1d1d1f';
  const TEXT_SECONDARY = '#86868b';
  const BORDER = '#d2d2d7';
  const ASIDE_DARK = '#14181d';

  // v6.0 Apple 风格重构：与主站统一的设计语言（Apple 蓝 + 浅灰底 + 大圆角卡片）
  const theme = createTheme({
    palette: {
      primary: { main: '#0066cc' },
      error: { main: '#ff3b30' },
      background: { default: '#f5f5f7' },
      text: { primary: '#1d1d1f', secondary: '#86868b' },
    },
    shape: { borderRadius: 12 },
    typography: {
      fontFamily: '-apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif',
    },
    components: {
      MuiButton: {
        defaultProps: { disableElevation: true },
        styleOverrides: {
          root: { textTransform: 'none', borderRadius: 999, fontWeight: 500 },
          contained: { boxShadow: 'none' },
          outlined: { borderColor: APPLE_BLUE },
          outlinedError: { borderColor: '#ff3b30', '&:hover': { borderColor: '#ff3b30' } },
        },
      },
      MuiPaper: {
        styleOverrides: {
          root: { backgroundImage: 'none' },
          outlined: { borderColor: '#e8e8ed', borderRadius: 16 },
          elevation1: { boxShadow: 'none', border: '1px solid #e8e8ed', borderRadius: 16 },
        },
      },
      MuiOutlinedInput: {
        styleOverrides: {
          root: {
            borderRadius: 12,
            bgcolor: '#fff',
            '& fieldset': { borderColor: '#d2d2d7' },
            '&:hover fieldset': { borderColor: '#a1a1a6' },
          },
        },
      },
      MuiChip: { styleOverrides: { root: { borderRadius: 999 } } },
      MuiDialog: { styleOverrides: { paper: { borderRadius: 18 } } },
    },
  });

  // ==================== 请求工具 ====================
  async function api(url, options) {
    const opts = options || {};
    const init = { method: opts.method || 'GET' };
    if (opts.form) {
      init.body = opts.form;
    } else if (opts.body !== undefined && opts.body !== null) {
      init.headers = { 'Content-Type': 'application/json' };
      init.body = JSON.stringify(opts.body);
    }
    const res = await fetch(url, init);
    let json = null;
    try { json = await res.json(); } catch (e) { /* 非JSON响应 */ }
    if (res.status === 401) { location.href = '/login'; throw new Error('unauthorized'); }
    if (!json) throw new Error('服务器响应异常');
    return json;
  }

  let _toast = function () {};
  function toast(msg, sev) { _toast(msg, sev); }
  // v5.10.0：向外射一个全局 hook，便于 compliance-admin.jsx 之类拆分子模块复用同一个 Snackbar
  window.__toast = function (msg, sev) { _toast(msg, sev); };

  // v5.2.2：带上传进度条的 POST（fetch 拿不到上传进度，大数据包用 XHR）
  // v5.2.4：加超时保护——服务器无响应/被代理掐断时报错退出，不再无限卡在"校验中"
  function uploadJson(url, formData, onProgress) {
    return new Promise(function (resolve, reject) {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.timeout = 600000;   // 10 分钟：覆盖慢速上传大包的场景
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable && onProgress) onProgress(Math.round(e.loaded * 100 / e.total));
      };
      xhr.onload = function () {
        if (xhr.status === 401) { location.href = '/login'; reject(new Error('unauthorized')); return; }
        let json = null;
        try { json = JSON.parse(xhr.responseText); } catch (e) { /* 非JSON响应 */ }
        if (!json) { reject(new Error('服务器响应异常（文件可能超过服务器上传上限）')); return; }
        resolve(json);
      };
      xhr.ontimeout = function () { reject(new Error('服务器响应超时，本次上传未成功，请重试')); };
      xhr.onabort = function () { reject(new Error('上传已中断')); };
      xhr.onerror = function () { reject(new Error('网络异常，上传未成功')); };
      xhr.send(formData);
    });
  }

  function fmtTime(s) {
    if (!s) return '-';
    return String(s).replace('T', ' ').slice(0, 16);
  }

  // v5.1.0：字节数人性化（存储配额展示）
  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n >= 1073741824) return (n / 1073741824).toFixed(2) + ' GB';
    if (n >= 1048576) return (n / 1048576).toFixed(2) + ' MB';
    if (n >= 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
  }

  // ==================== 入驻页左栏（v5.27.0）====================
  // 战队侧左栏是一张海报，平台侧没有图可挂，就压一块深色信息栏：品牌、一句主张、三条要点。
  // 登录与注册各说一套，这是他要的「细致的区分」；手机上只留品牌与主张，要点整条收起，
  // 否则 375 宽的屏上表单要被推到两屏之后。
  function AuthAside({ mode }) {
    const isRegister = mode === 'register';
    const bullets = isRegister ? [
      ['一支战队一个平台账号', '用队长 QQ 号注册就行，不用邮箱，也不用下载任何东西。'],
      ['注册后提交入驻资料', '站点目录名、战队简介，再附一张队长身份证明截图。'],
      ['平台核对身份后开通', '通过后你会有独立站点 /你的战队名/，成员在那里各自注册。'],
    ] : [
      ['登录后即可提交入驻申请', '资料齐全当场提交，平台核对身份后开通站点。'],
      ['每支战队一个独立站点', '数据互相隔离，登录态也互不串，成员看不到别队的事。'],
      ['枪码、轮换地图、战绩、约战', '开完站在自己站点里管，平台这边只做治理与审核。'],
    ];
    return (
      <Box sx={{
        width: { xs: '100%', md: '50%' }, flexShrink: 0, minHeight: { xs: 0, md: '100vh' },
        bgcolor: ASIDE_DARK, color: '#fff', boxSizing: 'border-box',
        px: { xs: 3, md: 6 }, py: { xs: 3, md: 6 },
        display: 'flex', flexDirection: 'column', justifyContent: 'center',
        backgroundImage: 'linear-gradient(160deg, rgba(0,102,204,0.34) 0%, rgba(20,24,29,0) 58%)',
      }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: { xs: 1.2, md: 4 } }}>
          <Box sx={{ width: 34, height: 34, borderRadius: '9px', bgcolor: APPLE_BLUE, flexShrink: 0,
            display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <svg viewBox="0 0 24 24" width="19" height="19" fill="#fff">
              <path d="M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm0 2.2L6 6.4V11c0 3.9 2.6 7.6 6 8.9 3.4-1.3 6-5 6-8.9V6.4l-6-2.2zM11 7h2v4h4v2h-6V7z"/>
            </svg>
          </Box>
          <Typography sx={{ fontSize: 15, fontWeight: 700, color: '#fff', letterSpacing: '0.2px' }}>战队入驻平台</Typography>
        </Box>
        <Typography sx={{ fontSize: { xs: 19, md: 30 }, fontWeight: 700, lineHeight: 1.32, letterSpacing: '-0.5px', color: '#fff' }}>
          {isRegister ? '注册一个平台账号，把战队搬进自己的站点' : '一个战队，一套自己的系统'}
        </Typography>
        <Typography sx={{ mt: 1.5, fontSize: { xs: 13, md: 15 }, lineHeight: 1.7, color: 'rgba(255,255,255,0.72)', maxWidth: '24em' }}>
          {isRegister ? '成员管理、轮换地图、枪码库、战绩上传、跨队约战、聊天室，一次配齐。' : '入驻战队的队长和平台管理员从这里进。'}
        </Typography>
        <Box sx={{ display: { xs: 'none', md: 'block' }, mt: 4 }}>
          {bullets.map(function (b) {
            return (
              <Box key={b[0]} sx={{ display: 'flex', gap: 1.5, mb: 2.5 }}>
                <Box sx={{ width: 6, height: 6, borderRadius: '50%', bgcolor: APPLE_BLUE, mt: '9px', flexShrink: 0 }} />
                <Box sx={{ minWidth: 0 }}>
                  <Typography sx={{ fontSize: 15, fontWeight: 600, color: '#fff' }}>{b[0]}</Typography>
                  <Typography sx={{ mt: 0.3, fontSize: 13.5, lineHeight: 1.65, color: 'rgba(255,255,255,0.66)' }}>{b[1]}</Typography>
                </Box>
              </Box>
            );
          })}
        </Box>
      </Box>
    );
  }

  // 两栏外壳：桌面左栏右表单，手机上下叠（与 auth.jsx 的 Root 同一套断点）
  function AuthFrame({ mode, children }) {
    return (
      <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: { xs: 'column', md: 'row' }, bgcolor: BG_GRAY }}>
        <AuthAside mode={mode} />
        <Box sx={{ flex: 1, display: 'flex', alignItems: { xs: 'flex-start', md: 'center' },
          justifyContent: 'center', px: 3, py: { xs: 4, md: 6 }, minHeight: 0 }}>
          <Box sx={{ width: '100%', maxWidth: 400 }}>{children}</Box>
        </Box>
      </Box>
    );
  }

  // ==================== 页面底部：隐私政策 / 服务条款（v5.10.0）====================
  // 平台根路径 BASE=''，直接指向 /privacy 与 /terms（Compliance 控制器）
  function PolicyFooter({ verb }) {
    const action = verb || '登录';
    return (
      <Box sx={{ mt: 3, pt: 2, borderTop: '1px solid ' + BORDER, textAlign: 'center' }}>
        <Typography variant="caption" color="text.secondary" sx={{ lineHeight: 1.7, fontSize: 12 }}>
          {action}即代表您同意本站的{' '}
          <Link href="/terms" target="_blank" rel="noopener" underline="hover"
            sx={{ color: '#0066cc', fontSize: 12 }}>服务条款</Link>
          {' '}与{' '}
          <Link href="/privacy" target="_blank" rel="noopener" underline="hover"
            sx={{ color: '#0066cc', fontSize: 12 }}>隐私政策</Link>
        </Typography>
      </Box>
    );
  }

  // ==================== 未晞通行证（v5.36.8：入驻平台一键登录） ====================
  // 复用「通行证 → 战队超管 → 平台入驻账号」这层绑定；平台账号体系本身不改。
  const PLATFORM_PASSPORT_NOTICES = {
    admin_needed: {
      tone: 'warning',
      title: '这个通行证还没有绑定战队超管',
      message: '平台一键登录只对「战队超管账号绑过通行证」对应的入驻平台账号生效；平台超管账号请直接用密码登录。',
    },
    admin_multi: {
      tone: 'warning',
      title: '无法确定要进入哪个入驻账号',
      message: '这个通行证绑定在多个战队的超管账号上，请先用平台账号密码登录。',
    },
    disabled: {
      tone: 'error',
      title: '平台账号已被停用',
      message: '这个通行证对应的入驻账号已停用，请联系平台管理员。',
    },
    session_lost: {
      tone: 'warning',
      title: '登录状态没有保存',
      message: '浏览器没有保住登录 Cookie。请允许本站 Cookie 后重新点一次通行证登录，或改用账号密码登录。',
    },
    denied: {
      tone: 'warning',
      title: '已取消授权',
      message: '你取消了未晞通行证授权，还没有登录。',
    },
    state: {
      tone: 'warning',
      title: '登录请求已过期',
      message: '请重新点一次「使用未晞通行证登录」。',
    },
    config: {
      tone: 'error',
      title: '通行证尚未配置',
      message: '平台管理员还没有完成未晞通行证对接。',
    },
    failed: {
      tone: 'error',
      title: '登录失败',
      message: '未晞通行证校验没有通过，请稍后重试或联系平台管理员。',
    },
  };

  function LockIcon() {
    return (
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="5" y="10" width="14" height="10" rx="2.2" stroke="currentColor" strokeWidth="1.8" />
        <path d="M8 10V7.5a4 4 0 1 1 8 0V10" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
      </svg>
    );
  }

  function platformPassportNotice() {
    try {
      const code = new URLSearchParams(window.location.search).get('pn') || '';
      return PLATFORM_PASSPORT_NOTICES[code] || null;
    } catch (e) {
      return null;
    }
  }

  function PlatformPassportButton() {
    const [cfg, setCfg] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      let alive = true;
      fetch('/api/passport/config', { headers: { Accept: 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (json) { if (alive) setCfg(json && json.code === 0 ? (json.data || {}) : null); })
        .catch(function () { if (alive) setCfg(null); })
        .then(function () { if (alive) setLoading(false); });
      return function () { alive = false; };
    }, []);

    // 从通行证页按浏览器「后退」回来时会走 bfcache，不复位就会卡在「正在前往…」
    useEffect(function () {
      const onShow = function (e) { if (e.persisted) setBusy(false); };
      window.addEventListener('pageshow', onShow);
      return function () { window.removeEventListener('pageshow', onShow); };
    }, []);

    if (loading || !cfg || !cfg.enabled) return null;
    const ready = !!cfg.ready;

    const start = function () {
      if (!ready || busy) return;
      setBusy(true);
      location.href = cfg.start_url || '/passport/platform/start';
    };

    return (
      <Box sx={{ mt: 2.5 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: 2.5 }}>
          <Box sx={{ flex: 1, height: 1, bgcolor: BORDER }} />
          <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY }}>或</Typography>
          <Box sx={{ flex: 1, height: 1, bgcolor: BORDER }} />
        </Box>
        <Button type="button" variant="outlined" fullWidth size="large" disabled={!ready || busy}
          onClick={start}
          startIcon={!busy ? <LockIcon /> : null}
          sx={{
            height: 48, fontSize: 15, fontWeight: 600, borderRadius: 999,
            color: APPLE_BLUE, borderColor: APPLE_BLUE, borderWidth: '1.5px', bgcolor: '#fff',
            '&:hover': {
              borderColor: '#0055aa', borderWidth: '1.5px', bgcolor: alpha(APPLE_BLUE, 0.05),
            },
            '&.Mui-disabled': { borderColor: BORDER, color: '#9b9ba1', bgcolor: '#fff' },
          }}>
          {busy ? '正在前往通行证…' : '使用未晞通行证登录'}
        </Button>
        {!ready && (
          <Typography align="center" sx={{ mt: 1, fontSize: 12, color: TEXT_SECONDARY }}>
            通行证登录暂不可用
          </Typography>
        )}
      </Box>
    );
  }

  // ==================== 登录页 ====================
  function LoginPage() {
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [notice] = useState(platformPassportNotice);

    const submit = async function (e) {
      e.preventDefault();
      if (busy) return;
      setError('');
      setBusy(true);
      try {
        const json = await api('/api/platform/auth/login', {
          method: 'POST', body: { username: username.trim(), password: password },
        });
        if (json.code === 0) {
          location.href = '/app';
          return;
        }
        setError(json.msg || '登录失败');
      } catch (err) {
        setError('网络异常，请稍后再试');
      } finally {
        setBusy(false);
      }
    };

    return (
      <Box component="form" onSubmit={submit} sx={{ width: '100%' }}>
        <Typography sx={{ fontSize: { xs: 28, md: 32 }, fontWeight: 700, color: TEXT_PRIMARY, letterSpacing: '-0.5px' }}>
          平台登录
        </Typography>
        <Typography sx={{ mt: 1, mb: 3.5, fontSize: 15, color: TEXT_SECONDARY }}>
          入驻战队与平台管理员登录入口
        </Typography>

        {notice && (
          <Alert severity={notice.tone} sx={{ mb: 2, borderRadius: 3 }}>
            <Typography sx={{ fontSize: 13.5, fontWeight: 600 }}>{notice.title}</Typography>
            <Typography sx={{ fontSize: 13, mt: 0.3, lineHeight: 1.6 }}>{notice.message}</Typography>
          </Alert>
        )}
        {error && <Alert severity="error" sx={{ mb: 2, borderRadius: 3 }}>{error}</Alert>}

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>账号</Typography>
        <TextField fullWidth required sx={{ mb: 2 }}
          value={username} onChange={(e) => setUsername(e.target.value)}
          autoComplete="username" placeholder="注册时填写的账号" />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 2.5 }}
          value={password} onChange={(e) => setPassword(e.target.value)}
          autoComplete="current-password" placeholder="请输入密码" />

        <Button type="submit" variant="contained" fullWidth size="large" disabled={busy}
          sx={{ height: 48, fontSize: 16 }}>
          {busy ? '登录中...' : '登 录'}
        </Button>

        <PlatformPassportButton />

        <Typography align="center" sx={{ mt: 2.5, fontSize: 14, color: TEXT_SECONDARY }}>
          想入驻战队？{' '}
          <Link href="/register" underline="none" sx={{ color: APPLE_BLUE, fontWeight: 600 }}>注册平台账号</Link>
        </Typography>
        <Typography align="center" sx={{ mt: 1.5, fontSize: 12.5, color: TEXT_SECONDARY }}>
          战队成员请勿在此注册，请到所属战队站点注册
        </Typography>

        <PolicyFooter verb="登录" />
      </Box>
    );
  }

  // ==================== 注册页 ====================
  function RegisterPage() {
    const [username, setUsername] = useState('');
    const [nickname, setNickname] = useState('');
    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState('');
    const [ok, setOk] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = async function (e) {
      e.preventDefault();
      if (busy) return;
      setError(''); setOk('');
      setBusy(true);
      try {
        const json = await api('/api/platform/auth/register', {
          method: 'POST',
          body: { username: username.trim(), nickname: nickname.trim(), password: password, confirm: confirm },
        });
        if (json.code === 0) {
          setUsername(''); setNickname(''); setPassword(''); setConfirm('');
          setOk((json.msg || '注册成功') + '，即将跳转登录...');
          window.setTimeout(function () { location.href = '/login'; }, 1200);
        } else {
          setError(json.msg || '注册失败');
        }
      } catch (err) {
        setError('网络异常，请稍后再试');
      } finally {
        setBusy(false);
      }
    };

    return (
      <Box component="form" onSubmit={submit} sx={{ width: '100%' }}>
        <Typography sx={{ fontSize: { xs: 28, md: 32 }, fontWeight: 700, color: TEXT_PRIMARY, letterSpacing: '-0.5px' }}>
          注册平台账号
        </Typography>
        <Typography sx={{ mt: 1, mb: 3.5, fontSize: 15, color: TEXT_SECONDARY }}>
          注册后即可提交战队入驻申请
        </Typography>

        {error && <Alert severity="error" sx={{ mb: 2, borderRadius: 3 }}>{error}</Alert>}
        {ok && <Alert severity="success" sx={{ mb: 2, borderRadius: 3 }}>{ok}</Alert>}

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>账号</Typography>
        <TextField fullWidth required sx={{ mb: 0.5 }}
          value={username} onChange={(e) => setUsername(e.target.value.trim())}
          placeholder="3-20 位字母 / 数字 / 下划线" autoComplete="username" />
        <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, display: 'block', mb: 2 }}>
          建议直接用队长 QQ 号，和以后站点里的超管账号对得上
        </Typography>

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>昵称 / 战队称呼</Typography>
        <TextField fullWidth required sx={{ mb: 2 }}
          value={nickname} onChange={(e) => setNickname(e.target.value)}
          placeholder="用于平台展示与审核联系" />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 2 }}
          value={password} onChange={(e) => setPassword(e.target.value)}
          placeholder="8-64 位，需包含字母和数字" autoComplete="new-password" />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>确认密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 3 }}
          value={confirm} onChange={(e) => setConfirm(e.target.value)}
          placeholder="再次输入密码" autoComplete="new-password" />

        <Button type="submit" variant="contained" fullWidth size="large" disabled={busy}
          sx={{ height: 48, fontSize: 16 }}>
          {busy ? '提交中...' : '注 册'}
        </Button>

        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, my: 2.5 }}>
          <Box sx={{ flex: 1, height: 1, bgcolor: BORDER }} />
          <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY }}>或</Typography>
          <Box sx={{ flex: 1, height: 1, bgcolor: BORDER }} />
        </Box>

        <Typography align="center" sx={{ fontSize: 14, color: TEXT_SECONDARY }}>
          已有账号？{' '}
          <Link href="/login" underline="none" sx={{ color: APPLE_BLUE, fontWeight: 600 }}>直接登录</Link>
        </Typography>

        <PolicyFooter verb="注册" />
      </Box>
    );
  }

  // ==================== 入驻申请表单（战队账号） ====================
  function ApplyForm({ onDone }) {
    const [name, setName] = useState('');
    const [slug, setSlug] = useState('');
    const [slugMsg, setSlugMsg] = useState('');
    const [slugOk, setSlugOk] = useState(false);
    const [description, setDescription] = useState('');
    const [adminUser, setAdminUser] = useState('');
    const [adminPass, setAdminPass] = useState('');
    const [adminConfirm, setAdminConfirm] = useState('');
    const [file, setFile] = useState(null);
    const [logoFile, setLogoFile] = useState(null);
    const [busy, setBusy] = useState(false);

    // slug 实时查重（500ms 防抖）
    useEffect(function () {
      if (!slug) { setSlugMsg(''); setSlugOk(false); return; }
      const h = window.setTimeout(async function () {
        try {
          const res = await fetch('/api/platform/check-slug?slug=' + encodeURIComponent(slug));
          const json = await res.json();
          setSlugOk(json.code === 0);
          setSlugMsg(json.msg || '');
        } catch (e) { /* 网络异常忽略 */ }
      }, 500);
      return function () { window.clearTimeout(h); };
    }, [slug]);

    const submit = async function () {
      if (busy) return;
      if (!name.trim()) { toast('请填写战队名称', 'error'); return; }
      if (!slug || !slugOk) { toast('请填写可用的站点目录', 'error'); return; }
      if (!adminUser.trim()) { toast('请填写战队系统超管账号', 'error'); return; }
      if (adminPass.length < 8) { toast('超管密码至少 8 位', 'error'); return; }
      if (adminPass !== adminConfirm) { toast('两次输入的超管密码不一致', 'error'); return; }
      if (!file) { toast('请上传战队队长证明截图', 'error'); return; }
      setBusy(true);
      const fd = new FormData();
      fd.append('name', name.trim());
      fd.append('slug', slug);
      fd.append('description', description.trim());
      fd.append('admin_username', adminUser.trim());
      fd.append('admin_password', adminPass);
      fd.append('admin_confirm', adminConfirm);
      fd.append('file', file);
      if (logoFile) fd.append('logo', logoFile);
      try {
        const json = await api('/api/platform/apply', { method: 'POST', form: fd });
        if (json.code === 0) {
          toast(json.msg, 'success');
          if (onDone) onDone();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('提交失败，请稍后再试', 'error');
      } finally {
        setBusy(false);
      }
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Typography variant="subtitle1" fontWeight={700} sx={{ mb: 0.4 }}>提交入驻申请</Typography>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>
          审核通过后即可获得专属战队站点（地址 = 平台域名 + 你填写的目录）
        </Typography>
        <Stack spacing={2}>
          <TextField label="战队名称" value={name} onChange={e => setName(e.target.value)}
            inputProps={{ maxLength: 64 }} fullWidth size="small" required />
          <TextField label="站点目录（如 delta → 平台域名/delta/）" value={slug}
            onChange={e => setSlug(e.target.value.toLowerCase().trim())}
            helperText={slug ? slugMsg : '2-30 位小写字母/数字/连字符，首尾须为字母或数字'}
            FormHelperTextProps={{ sx: { color: slug ? (slugOk ? 'success.main' : 'error.main') : 'text.secondary' } }}
            fullWidth size="small" required />
          <TextField label="战队简介（选填）" value={description} onChange={e => setDescription(e.target.value)}
            inputProps={{ maxLength: 500 }} multiline minRows={2} fullWidth size="small" />
          <TextField label="战队系统超管账号（建议用队长 QQ 号）" value={adminUser}
            onChange={e => setAdminUser(e.target.value.trim())}
            helperText="开通后用此账号登录你的战队系统，拥有全部管理权限"
            fullWidth size="small" required />
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="超管密码（8-64位，含字母和数字）" type="password" value={adminPass}
              onChange={e => setAdminPass(e.target.value)} fullWidth size="small" required />
            <TextField label="确认超管密码" type="password" value={adminConfirm}
              onChange={e => setAdminConfirm(e.target.value)} fullWidth size="small" required />
          </Stack>
          <Stack direction="row" spacing={2}>
            <Button component="label" variant="outlined" size="small">
              {file ? '重选队长证明截图' : '战队队长证明截图（必填）'}
              <input type="file" accept="image/*" hidden onChange={e => setFile(e.target.files[0] || null)} />
            </Button>
            <Button component="label" variant="outlined" size="small" color="inherit">
              {logoFile ? '重选logo' : '战队logo（选填）'}
              <input type="file" accept="image/*" hidden onChange={e => setLogoFile(e.target.files[0] || null)} />
            </Button>
          </Stack>
          <Typography variant="caption" color="text.secondary">
            队长证明截图：上传能证明你是战队队长的截图（如游戏内战队管理页、队长身份页等），供平台审核
          </Typography>
          {(file || logoFile) && (
            <Stack direction="row" spacing={1.5}>
              {file && <Box component="img" src={URL.createObjectURL(file)} alt="队长证明预览"
                sx={{ width: 96, height: 72, objectFit: 'cover', borderRadius: '6px' }} />}
              {logoFile && <Box component="img" src={URL.createObjectURL(logoFile)} alt="logo预览"
                sx={{ width: 72, height: 72, objectFit: 'cover', borderRadius: '6px' }} />}
            </Stack>
          )}
          <Button variant="contained" size="large" disabled={busy} onClick={submit}>
            {busy ? <CircularProgress size={22} /> : '提交入驻申请'}
          </Button>
        </Stack>
      </Paper>
    );
  }

  // ==================== 战队控制台（我的站点） ====================
  const TENANT_CHIP = {
    approved: ['已开通', 'success'], paused: ['已暂停', 'warning'],
    banned: ['已封禁', 'error'], transferring: ['转移冷静期', 'info'],
  };

  // ==================== 数据导入导出（v5.2.0：提交审核制） ====================
  const DATA_TASK_CHIP = {
    pending: ['待审核', 'warning'], approved: ['已通过', 'success'], rejected: ['已驳回', 'error'],
    done: ['已完成', 'success'], failed: ['执行失败', 'error'], cancelled: ['已撤回', 'default'],
  };

  function DataTaskPanel({ tenantOk }) {
    const [data, setData] = useState(null);
    const [upload, setUpload] = useState(null);   // v5.2.2：上传中 { pct }，非 null 时显示进度条
    const fileRef = React.useRef(null);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/my/data/tasks');
        if (json.code === 0) setData(json.data);
      } catch (e) { /* 忽略 */ }
    }, []);
    useEffect(function () { load(); }, [load]);

    const reqExport = async function () {
      if (!window.confirm('申请导出本站全部数据（含图片，不含轮换地图）？\n平台管理员审核通过后即可下载。')) return;
      try {
        const json = await api('/api/platform/my/data/export', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const reqImport = async function (e) {
      const f = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!f) return;
      if (upload) return;
      // v5.2.4：超过服务器上传上限的文件直接在浏览器拦下（传上去也会被丢弃，
      // 旧版本会卡在"服务器校验中"无任何反馈）
      if (data.max_upload_bytes > 0 && f.size > data.max_upload_bytes) {
        toast('数据包太大（' + (f.size / 1048576).toFixed(1) + 'MB），服务器上限 '
          + (data.max_upload_bytes / 1048576).toFixed(0) + 'MB，请精简图片后重新导出', 'error');
        return;
      }
      if (!window.confirm('上传数据包并申请导入？\n审核通过后当前站点数据将被数据包整体覆盖（不可恢复）。')) return;
      const form = new FormData();
      form.append('file', f);
      setUpload({ pct: 0 });
      try {
        const json = await uploadJson('/api/platform/my/data/import', form, function (pct) {
          setUpload({ pct: pct });
        });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (err) { toast(err && err.message ? err.message : '上传失败（文件可能过大或网络中断）', 'error'); }
      setUpload(null);
    };

    const cancelTask = async function (t) {
      if (!window.confirm('撤回该' + (t.type === 'export' ? '导出' : '导入') + '申请？')) return;
      try {
        const json = await api('/api/platform/my/data/' + t.id + '/cancel', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (!tenantOk) return null;
    if (data === null) return <Box sx={{ textAlign: 'center', py: 2 }}><CircularProgress size={22} /></Box>;

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Typography variant="subtitle1" fontWeight={700} sx={{ mb: 0.4 }}>数据导入 / 导出</Typography>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
          方便从开源版（如 v3.9.6.1）迁移数据或备份本站数据：含成员/图片/聊天/公告等，不含今日轮换地图；
          每天各 1 次机会（被驳回、撤回或执行失败不消耗机会，可重新提交）；站点满 {data.min_export_members} 人可导出，
          新建站点只可导入；均需平台管理员审核同意后执行。
        </Typography>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.2} sx={{ mb: 1.5 }}>
          <Button variant="contained" disabled={!!upload || !data.can_export || data.export_left <= 0} onClick={reqExport}>
            申请导出{data.can_export ? (data.export_left <= 0 ? '（今日已用）' : '') : '（成员不足 ' + data.min_export_members + ' 人）'}
          </Button>
          <Button variant="outlined" component="label" disabled={!!upload || data.import_left <= 0}>
            {data.import_left <= 0 ? '上传数据包（今日已用）' : '上传数据包申请导入'}
            <input type="file" accept=".zip" hidden ref={fileRef} onChange={reqImport} />
          </Button>
        </Stack>
        {upload && (
          <Box sx={{ mb: 1.5 }}>
            <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5 }}>
              <Typography variant="caption" color="text.secondary">
                {upload.pct < 100 ? '数据包上传中 ' + upload.pct + '%' : '上传完成，服务器校验中…'}
              </Typography>
            </Stack>
            <LinearProgress variant="determinate" value={upload.pct} sx={{ height: 8, borderRadius: 4 }} />
          </Box>
        )}
        {data.list.length === 0 ? <Alert severity="info">暂无导入导出记录</Alert> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>类型</TableCell><TableCell>状态</TableCell>
                  <TableCell>提交时间</TableCell><TableCell>备注</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.list.map(function (t) {
                  const chip = DATA_TASK_CHIP[t.status] || [t.status, 'default'];
                  return (
                    <TableRow key={t.id}>
                      <TableCell>{t.type === 'export' ? '导出' : '导入'}</TableCell>
                      <TableCell><Chip size="small" label={chip[0]} color={chip[1]} variant="outlined" /></TableCell>
                      <TableCell>{fmtTime(t.created_at)}</TableCell>
                      <TableCell><Typography variant="caption" color="text.secondary">{t.note || '-'}</Typography></TableCell>
                      <TableCell>
                        {t.status === 'pending' && (
                          <Button size="small" onClick={() => cancelTask(t)}>撤回</Button>
                        )}
                        {t.type === 'export' && (t.status === 'approved' || t.status === 'done') && (
                          <Button size="small" variant="contained"
                            onClick={function () { window.location.href = '/api/platform/my/data/' + t.id + '/download'; }}>
                            下载
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}
      </Paper>
    );
  }

  function TeamConsole() {
    const [my, setMy] = useState(null);
    const [loadErr, setLoadErr] = useState(false);
    // 改超管账号 / 密码
    const [newUser, setNewUser] = useState('');
    const [newPass, setNewPass] = useState('');
    const [newConfirm, setNewConfirm] = useState('');
    // v5.1.0：转移 / 登录日志
    const [transferTo, setTransferTo] = useState('');
    const [logs, setLogs] = useState(null);
    const [logsOpen, setLogsOpen] = useState(false);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/my');
        setMy(json.data);
      } catch (e) { setLoadErr(true); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const saveAccount = async function () {
      if (!newUser.trim()) { toast('请输入新账号', 'error'); return; }
      try {
        const json = await api('/api/platform/my/admin-account', { method: 'POST', body: { username: newUser.trim() } });
        if (json.code === 0) { toast(json.msg, 'success'); setNewUser(''); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const savePassword = async function () {
      if (newPass.length < 8) { toast('密码至少 8 位', 'error'); return; }
      if (newPass !== newConfirm) { toast('两次输入的密码不一致', 'error'); return; }
      try {
        const json = await api('/api/platform/my/admin-password', { method: 'POST', body: { password: newPass, confirm: newConfirm } });
        if (json.code === 0) { toast(json.msg, 'success'); setNewPass(''); setNewConfirm(''); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.1.0：自助暂停 / 恢复站点
    const setStatus = async function (action) {
      const tip = action === 'pause'
        ? '暂停后站点目录将无法访问，成员不能登录，确定暂停吗？'
        : '恢复后站点立即可正常访问，确定恢复吗？';
      if (!window.confirm(tip)) return;
      try {
        const json = await api('/api/platform/my/status', { method: 'POST', body: { action: action } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.1.0：发起战队转移
    const startTransfer = async function () {
      if (!transferTo.trim()) { toast('请输入接收方的平台账号', 'error'); return; }
      if (!window.confirm('确认把战队转移给「' + transferTo.trim() + '」？\n对方同意后进入 7 天冷静期，期间站点无法访问，冷静期结束后站点归对方所有。')) return;
      try {
        const json = await api('/api/platform/transfer/start', { method: 'POST', body: { to_username: transferTo.trim() } });
        if (json.code === 0) { toast(json.msg, 'success'); setTransferTo(''); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.1.0：撤回进行中的转移
    const cancelTransfer = async function (id) {
      if (!window.confirm('撤回后转移作废，站点恢复正常，确定撤回吗？')) return;
      try {
        const json = await api('/api/platform/transfer/' + id + '/cancel', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.1.0：接收 / 拒绝别人转过来的战队
    const handleTransferIn = async function (id, act) {
      if (act === 'accept' && !window.confirm('确认接收「' + my.transfer_in.tenant_name + '」？\n接收后进入 7 天冷静期（站点暂停访问），冷静期结束战队正式归你。')) return;
      try {
        const json = await api('/api/platform/transfer/' + id + '/' + act, { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.1.0：登录日志
    const openLogs = async function () {
      setLogsOpen(true);
      if (logs !== null) return;
      try {
        const json = await api('/api/platform/my/login-logs');
        setLogs(json.data.list || []);
      } catch (e) { setLogs([]); }
    };

    if (loadErr) return <Alert severity="error">加载失败，请刷新重试</Alert>;
    if (my === null) return <Box sx={{ textAlign: 'center', py: 6 }}><CircularProgress /></Box>;

    // 登录日志弹窗
    const logsDialog = (
      <Dialog open={logsOpen} onClose={() => setLogsOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>登录日志（最近 50 条）</DialogTitle>
        <DialogContent dividers>
          {logs === null ? <Box sx={{ textAlign: 'center', py: 3 }}><CircularProgress size={26} /></Box>
            : logs.length === 0 ? <Alert severity="info">暂无登录记录</Alert> : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>时间</TableCell><TableCell>IP</TableCell>
                    <TableCell>归属地</TableCell><TableCell>结果</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {logs.map(function (l) {
                    return (
                      <TableRow key={l.id}>
                        <TableCell>{fmtTime(l.created_at)}</TableCell>
                        <TableCell>{l.ip}</TableCell>
                        <TableCell>{l.region || '-'}</TableCell>
                        <TableCell>
                          <Chip size="small" label={l.result} color={l.result === '成功' ? 'success' : 'error'} variant="outlined" />
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        </DialogContent>
        <DialogActions><Button onClick={() => setLogsOpen(false)}>关闭</Button></DialogActions>
      </Dialog>
    );

    // 有待接收的转移单（名下无站点时展示）
    const transferInCard = my.transfer_in ? (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Typography variant="subtitle1" fontWeight={700} sx={{ mb: 0.4 }}>待接收的战队转移</Typography>
        <Typography variant="body2" sx={{ mb: 1 }}>
          「{my.transfer_in.from_name}」要把战队「<b>{my.transfer_in.tenant_name}</b>」转移给你。
          同意后进入 7 天冷静期（期间站点暂停访问），冷静期结束战队正式归你。
        </Typography>
        <Stack direction="row" spacing={1.2}>
          <Button variant="contained" onClick={() => handleTransferIn(my.transfer_in.id, 'accept')}>同意接收</Button>
          <Button variant="outlined" color="error" onClick={() => handleTransferIn(my.transfer_in.id, 'reject')}>拒绝</Button>
        </Stack>
      </Paper>
    ) : null;

    // 已开通站点：站点信息 + 超管账号管理
    if (my.tenant) {
      const t = my.tenant;
      const chip = TENANT_CHIP[t.status] || [t.status, 'default'];
      const usedPct = t.storage_quota > 0 ? Math.min(100, Math.round(t.storage_used / t.storage_quota * 100)) : 0;
      const tr = my.transfer_out;
      return (
        <Stack spacing={2}>
          <Paper sx={{ p: { xs: 2, md: 3 } }}>
            <Stack direction="row" alignItems="center" spacing={1.2} sx={{ mb: 1.5 }}>
              <Typography variant="subtitle1" fontWeight={700} sx={{ flex: 1 }}>我的战队站点</Typography>
              <Chip size="small" color={chip[1]} label={chip[0]} />
              <Button size="small" variant="outlined" onClick={openLogs}>登录日志</Button>
            </Stack>
            <Typography variant="body2" sx={{ mb: 0.8 }}>
              战队名称：<b>{t.name}</b>
            </Typography>
            <Typography variant="body2" sx={{ mb: 0.8 }}>
              访问地址：<Link href={t.url} target="_blank" underline="hover">{location.origin}{t.url}</Link>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                把此地址发给战队成员，成员在该站点注册加入
              </Typography>
            </Typography>
            <Typography variant="body2" sx={{ mb: 1.2 }}>
              超管账号：<b>{t.admin_username}</b>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                用此账号 + 你设置的密码登录战队系统，即为站点管理员
              </Typography>
            </Typography>
            {/* v5.1.0：存储空间用量（清理事请到战队系统管理中心） */}
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>
              存储空间：{fmtBytes(t.storage_used)} / {fmtBytes(t.storage_quota)}
            </Typography>
            <Box sx={{ height: 8, borderRadius: 4, bgcolor: '#e9e5f5', overflow: 'hidden', mb: 1.5 }}>
              <Box sx={{
                height: '100%', width: usedPct + '%',
                bgcolor: usedPct >= 90 ? '#dc2626' : (usedPct >= 70 ? '#f59e0b' : '#7c3aed'),
              }} />
            </Box>
            <Stack direction="row" spacing={1.2}>
              {t.status === 'approved'
                ? <Button variant="outlined" color="warning" onClick={() => setStatus('pause')}>暂停站点</Button>
                : (t.status === 'paused'
                  ? <Button variant="outlined" color="success" onClick={() => setStatus('resume')}>恢复站点</Button>
                  : null)}
            </Stack>
            {t.status === 'paused' && (
              <Alert severity="warning" sx={{ mt: 1.5 }}>站点已暂停，成员暂时无法访问，点击上方"恢复站点"即可重新开放</Alert>
            )}
          </Paper>

          {/* v5.1.0：战队转移 */}
          <Paper sx={{ p: { xs: 2, md: 3 } }}>
            <Typography variant="subtitle1" fontWeight={700} sx={{ mb: 0.4 }}>战队转移</Typography>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>
              把战队转给其他平台账号：对方同意后进入 7 天冷静期（期间站点无法访问），冷静期结束后站点归对方，你不再拥有该战队
            </Typography>
            {tr ? (
              <Alert severity={tr.status === 'cooling' ? 'warning' : 'info'} sx={{ mb: 1.5 }}>
                {tr.status === 'cooling'
                  ? '转移冷静期中：站点暂停访问，' + fmtTime(tr.cooling_until) + ' 后正式交接给 ' + tr.to_name
                  : '等待 ' + tr.to_name + ' 确认接收'}
              </Alert>
            ) : null}
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
              <TextField label="接收方平台账号" value={transferTo} onChange={e => setTransferTo(e.target.value.trim())}
                placeholder="对方注册平台时用的账号" size="small" sx={{ flex: 1 }} disabled={!!tr} />
              <Button variant="contained" color="warning" disabled={!!tr || t.status !== 'approved'} onClick={startTransfer}>
                发起转移
              </Button>
              {tr && <Button variant="outlined" color="error" onClick={() => cancelTransfer(tr.id)}>撤回转移</Button>}
            </Stack>
          </Paper>

          {/* v5.2.0：数据导入导出（提交审核制） */}
          {t.status === 'approved' && <DataTaskPanel tenantOk={true} />}

          <Paper sx={{ p: { xs: 2, md: 3 } }}>
            <Typography variant="subtitle1" fontWeight={700} sx={{ mb: 0.4 }}>更改超管账号</Typography>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>
              修改后立即生效，请使用新账号登录战队系统
            </Typography>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
              <TextField label="新超管账号" value={newUser} onChange={e => setNewUser(e.target.value.trim())}
                placeholder="3-20 位字母/数字/下划线" size="small" sx={{ flex: 1 }} />
              <Button variant="contained" onClick={saveAccount}>保存账号</Button>
            </Stack>
            <Divider sx={{ my: 2.5 }} />
            <Typography variant="subtitle1" fontWeight={700} sx={{ mb: 0.4 }}>更改超管密码</Typography>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
              <TextField label="新密码" type="password" value={newPass} onChange={e => setNewPass(e.target.value)}
                placeholder="8-64 位，含字母和数字" size="small" sx={{ flex: 1 }} />
              <TextField label="确认新密码" type="password" value={newConfirm} onChange={e => setNewConfirm(e.target.value)}
                size="small" sx={{ flex: 1 }} />
              <Button variant="contained" onClick={savePassword}>保存密码</Button>
            </Stack>
          </Paper>
          {transferInCard}
          {logsDialog}
        </Stack>
      );
    }

    // 有申请记录：展示状态；被驳回可重新提交
    if (my.application) {
      const a = my.application;
      if (a.status === 'pending') {
        return (
          <Stack spacing={2}>
            <Paper sx={{ p: { xs: 2, md: 3 } }}>
              <Alert severity="info" sx={{ mb: 1.5 }}>你的入驻申请正在审核中，请耐心等待</Alert>
              <Typography variant="body2">战队名称：<b>{a.name}</b></Typography>
              <Typography variant="body2">站点目录：<b>{a.slug}</b>（审核通过后生效）</Typography>
              <Typography variant="body2">提交时间：{fmtTime(a.created_at)}</Typography>
            </Paper>
            <Stack direction="row"><Button variant="outlined" onClick={openLogs}>登录日志</Button></Stack>
            {transferInCard}
            {logsDialog}
          </Stack>
        );
      }
      if (a.status === 'rejected') {
        return (
          <Stack spacing={2}>
            <Alert severity="warning">
              你的入驻申请被驳回{a.reject_reason ? ('，原因：' + a.reject_reason) : ''}。可修改资料后重新提交。
            </Alert>
            {transferInCard}
            <ApplyForm onDone={load} />
            {logsDialog}
            <Stack direction="row"><Button variant="outlined" onClick={openLogs}>登录日志</Button></Stack>
          </Stack>
        );
      }
    }

    // 无记录：入驻申请表单
    return (
      <Stack spacing={2}>
        {transferInCard}
        <ApplyForm onDone={load} />
        <Stack direction="row"><Button variant="outlined" onClick={openLogs}>登录日志</Button></Stack>
        {logsDialog}
      </Stack>
    );
  }

  // ==================== 轮换地图统一管理（v5.1.0：原战队系统后台收归平台，全战队共用） ====================
  function PlatformRotation() {
    const [data, setData] = useState(null);
    const [enabled, setEnabled] = useState(false);
    const [start, setStart] = useState('');
    // 地图弹窗
    const [mapDlg, setMapDlg] = useState(null);   // {id, name} 或 null
    const [mapName, setMapName] = useState('');
    const [mapFile, setMapFile] = useState(null);
    // 组合弹窗
    const [dayDlg, setDayDlg] = useState(null);   // {id, name, map_ids} 或 null
    const [dayName, setDayName] = useState('');
    const [dayMaps, setDayMaps] = useState([]);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/rotation');
        if (json.code === 0) {
          setData(json.data);
          setEnabled(json.data.enabled);
          setStart((json.data.start || '').slice(0, 10));
        }
      } catch (e) { /* ignore */ }
    }, []);
    useEffect(function () { load(); }, [load]);

    const saveSetting = async function () {
      try {
        const json = await api('/api/platform/admin/rotation/save', {
          method: 'POST', body: { enabled: enabled ? 1 : 0, start: start },
        });
        if (json.code === 0) toast(json.msg, 'success'); else toast(json.msg, 'error');
      } catch (e) { toast('操作失败', 'error'); }
    };

    const openMap = function (m) {
      setMapDlg(m || { id: 0 });
      setMapName(m ? m.name : '');
      setMapFile(null);
    };

    const saveMap = async function () {
      if (!mapName.trim()) { toast('请填写地图名称', 'error'); return; }
      const fd = new FormData();
      if (mapDlg.id > 0) fd.append('id', mapDlg.id);
      fd.append('name', mapName.trim());
      if (mapFile) fd.append('file', mapFile);
      try {
        const json = await api('/api/platform/admin/rotation/maps/save', { method: 'POST', form: fd });
        if (json.code === 0) { toast(json.msg, 'success'); setMapDlg(null); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const removeMap = async function (m) {
      if (!window.confirm('删除地图「' + m.name + '」？相关每日组合中的该地图也会移除')) return;
      try {
        const json = await api('/api/platform/admin/rotation/maps/' + m.id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const openDay = function (d) {
      setDayDlg(d || { id: 0 });
      setDayName(d ? d.name : '');
      setDayMaps(d ? (d.map_ids || []).slice() : []);
    };

    const toggleDayMap = function (mid) {
      setDayMaps(function (prev) {
        return prev.indexOf(mid) >= 0 ? prev.filter(function (x) { return x !== mid; }) : prev.concat([mid]);
      });
    };

    const saveDay = async function () {
      if (dayMaps.length === 0) { toast('请至少选择一张地图', 'error'); return; }
      try {
        const json = await api('/api/platform/admin/rotation/days/save', {
          method: 'POST', body: { id: dayDlg.id || 0, name: dayName.trim(), map_ids: dayMaps },
        });
        if (json.code === 0) { toast(json.msg, 'success'); setDayDlg(null); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const removeDay = async function (d) {
      if (!window.confirm('删除每日组合「' + (d.name || '#' + d.id) + '」？')) return;
      try {
        const json = await api('/api/platform/admin/rotation/days/' + d.id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (data === null) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;
    const mapById = {};
    data.maps.forEach(function (m) { mapById[m.id] = m; });

    return (
      <Stack spacing={2}>
        <Alert severity="info">此配置为全平台统一，保存后所有战队站点立即生效（战队系统内只展示，不可再修改）</Alert>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 1.5 }}>轮换设置</Typography>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }}>
            <FormControlLabel control={<Switch checked={enabled} onChange={e => setEnabled(e.target.checked)} />}
              label={enabled ? '功能已开启' : '功能已关闭'} />
            <TextField label="锚点日期（第 1 个组合对应的日期）" type="date" size="small"
              value={start} onChange={e => setStart(e.target.value)}
              InputLabelProps={{ shrink: true }} sx={{ width: 260 }} />
            <Button variant="contained" onClick={saveSetting}>保存设置</Button>
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            每天 9:00 切换到下一个组合循环轮换；凌晨 0-9 点展示当天预告
          </Typography>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction="row" alignItems="center" sx={{ mb: 1.5 }}>
            <Typography variant="subtitle2" fontWeight={700} sx={{ flex: 1 }}>地图池（{data.maps.length}）</Typography>
            <Button size="small" variant="contained" onClick={() => openMap(null)}>添加地图</Button>
          </Stack>
          {data.maps.length === 0 ? <Typography variant="body2" color="text.secondary">还没有地图，先添加几张</Typography> : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>图片</TableCell><TableCell>名称</TableCell><TableCell>操作</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {data.maps.map(function (m) {
                    return (
                      <TableRow key={m.id}>
                        <TableCell>
                          {m.image
                            ? <Box component="img" src={m.image} alt={m.name}
                                sx={{ width: 64, height: 40, objectFit: 'cover', borderRadius: '6px', cursor: 'zoom-in' }}
                                onClick={() => window.open(m.image, '_blank')} />
                            : <Typography variant="caption" color="text.secondary">无图</Typography>}
                        </TableCell>
                        <TableCell>{m.name}</TableCell>
                        <TableCell>
                          <Stack direction="row" spacing={0.8}>
                            <Button size="small" onClick={() => openMap(m)}>编辑</Button>
                            <Button size="small" color="error" onClick={() => removeMap(m)}>删除</Button>
                          </Stack>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        </Paper>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction="row" alignItems="center" sx={{ mb: 1.5 }}>
            <Typography variant="subtitle2" fontWeight={700} sx={{ flex: 1 }}>每日组合（{data.days.length}，按顺序循环）</Typography>
            <Button size="small" variant="contained" onClick={() => openDay(null)} disabled={data.maps.length === 0}>添加组合</Button>
          </Stack>
          {data.days.length === 0 ? <Typography variant="body2" color="text.secondary">还没有每日组合</Typography> : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>顺序</TableCell><TableCell>名称</TableCell><TableCell>地图</TableCell><TableCell>操作</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {data.days.map(function (d, i) {
                    return (
                      <TableRow key={d.id}>
                        <TableCell>{i + 1}</TableCell>
                        <TableCell>{d.name || ('#' + d.id)}</TableCell>
                        <TableCell>
                          <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                            {(d.map_ids || []).map(function (mid) {
                              return <Chip key={mid} size="small" label={mapById[mid] ? mapById[mid].name : ('#' + mid)} />;
                            })}
                          </Stack>
                        </TableCell>
                        <TableCell>
                          <Stack direction="row" spacing={0.8}>
                            <Button size="small" onClick={() => openDay(d)}>编辑</Button>
                            <Button size="small" color="error" onClick={() => removeDay(d)}>删除</Button>
                          </Stack>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        </Paper>

        {/* 地图编辑弹窗 */}
        <Dialog open={!!mapDlg} onClose={() => setMapDlg(null)} fullWidth maxWidth="xs">
          <DialogTitle>{mapDlg && mapDlg.id > 0 ? '编辑地图' : '添加地图'}</DialogTitle>
          <DialogContent>
            <TextField label="地图名称" value={mapName} onChange={e => setMapName(e.target.value)}
              inputProps={{ maxLength: 64 }} fullWidth size="small" sx={{ mb: 2, mt: 1 }} autoFocus />
            <Button component="label" variant="outlined" size="small">
              {mapFile ? '重选图片' : '选择地图图片（选填）'}
              <input type="file" accept="image/*" hidden onChange={e => setMapFile(e.target.files[0] || null)} />
            </Button>
            {mapFile && <Box component="img" src={URL.createObjectURL(mapFile)} alt="预览"
              sx={{ width: 120, height: 75, objectFit: 'cover', borderRadius: '6px', mt: 1.5, display: 'block' }} />}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setMapDlg(null)}>取消</Button>
            <Button variant="contained" onClick={saveMap}>保存</Button>
          </DialogActions>
        </Dialog>

        {/* 每日组合弹窗 */}
        <Dialog open={!!dayDlg} onClose={() => setDayDlg(null)} fullWidth maxWidth="xs">
          <DialogTitle>{dayDlg && dayDlg.id > 0 ? '编辑每日组合' : '添加每日组合'}</DialogTitle>
          <DialogContent>
            <TextField label="组合名称（选填）" value={dayName} onChange={e => setDayName(e.target.value)}
              inputProps={{ maxLength: 32 }} fullWidth size="small" sx={{ mb: 1.5, mt: 1 }} autoFocus />
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>
              当天轮换的地图（可多选）：
            </Typography>
            {data.maps.map(function (m) {
              return (
                <FormControlLabel key={m.id} sx={{ display: 'block' }}
                  control={<Checkbox size="small" checked={dayMaps.indexOf(m.id) >= 0} onChange={() => toggleDayMap(m.id)} />}
                  label={m.name} />
              );
            })}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setDayDlg(null)}>取消</Button>
            <Button variant="contained" onClick={saveDay}>保存</Button>
          </DialogActions>
        </Dialog>
      </Stack>
    );
  }

  // ==================== 平台超管控制台 ====================
  // ==================== QQ 机器人（v5.28.0：平台侧只剩敏感词与调用日志） ====================
  // 机器人凭据、接口环境、测试连接、群指令面板都在各战队的「管理中心 → QQ机器人」那一页；
  // 平台不再持有任何机器人的 AppID / AppSecret，也不再决定哪支战队能用——配了自己的机器人就都能用。
  function PlatformBot() {
    const [cfg, setCfg] = useState(null);
    const [form, setForm] = useState({ words: '', mask_char: '*#' });

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/bot-words');
        if (json.code === 0) {
          setCfg(json.data);
          setForm({ words: json.data.words || '', mask_char: json.data.mask_char || '*#' });
        }
      } catch (e) { /* 忽略 */ }
    }, []);
    useEffect(function () { load(); }, [load]);

    const save = async function () {
      try {
        const json = await api('/api/platform/admin/bot-words', {
          method: 'POST', body: { words: form.words, mask_char: form.mask_char },
        });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); }
    };

    if (cfg === null) {
      return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;
    }
    return (
      <Stack spacing={2}>
        <Alert severity="info" sx={{ whiteSpace: 'pre-line' }}>
          {'机器人由各战队自己在「管理中心 → QQ机器人」里接入：AppID / AppSecret / 专属回调地址 / 测试连接 / 群指令面板都在那一页，所有战队都能用，不用平台逐个开通。\n'
            + '平台侧只留两件全站的事：下面这份出站敏感词（任何机器人回复都要过一遍），以及各战队的调用日志。'}
        </Alert>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>出站文本风控屏蔽</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            机器人发出的消息会先过滤敏感词（命中逐字替换为屏蔽字符，避免整条被风控吞掉）。
            内置默认词库之外，可在此追加（换行或逗号分隔）。
          </Typography>
          <TextField label="自定义敏感词" fullWidth multiline minRows={3} size="small" sx={{ mb: 2 }}
            value={form.words} onChange={(e) => setForm(Object.assign({}, form, { words: e.target.value }))} />
          <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap>
            <TextField label="屏蔽字符" size="small" value={form.mask_char} sx={{ width: 200 }}
              inputProps={{ maxLength: 4 }}
              onChange={(e) => setForm(Object.assign({}, form, { mask_char: e.target.value }))} />
            <Button variant="contained" onClick={save}>保存设置</Button>
          </Stack>
        </Paper>

        <BotLogsCard />
      </Stack>
    );
  }
  // ---- v5.8.3：机器人调用日志（平台超管可查看 / 一键清空） ----
  function BotLogsCard() {
    const [data, setData] = useState(null);
    const [page, setPage] = useState(1);
    const load = useCallback(async function (p) {
      try {
        const j = await api('/api/platform/admin/bot-logs?page=' + p);
        if (j.code === 0) setData(j.data);
      } catch (e) { /* 忽略 */ }
    }, []);
    useEffect(function () { load(1); }, [load]);

    const clear = async function () {
      if (!window.confirm('确定清空全部机器人调用日志吗？此操作不可恢复。')) return;
      try {
        const j = await api('/api/platform/admin/bot-logs/clear', { method: 'POST' });
        toast(j.msg, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load(1);
      } catch (e) { toast('清空失败', 'error'); }
    };

    const totalPages = data ? Math.max(1, Math.ceil(data.total / 50)) : 1;
    return (
      <Paper variant="outlined" sx={{ p: 2.5 }}>
        <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 1.5 }}>
          <Box>
            <Typography variant="subtitle1" fontWeight={600}>调用日志</Typography>
            <Typography variant="caption" color="text.secondary">
              记录每条群指令（时间 / 战队 / 指令 / 原文），自动清理 60 天前记录。
            </Typography>
          </Box>
          <Stack direction="row" spacing={1}>
            <Button size="small" onClick={function () { load(page); }}>刷新</Button>
            <Button size="small" color="error" onClick={clear}>清空日志</Button>
          </Stack>
        </Stack>
        {data === null ? (
          <Box sx={{ textAlign: 'center', py: 2 }}><CircularProgress /></Box>
        ) : data.list.length === 0 ? (
          <Typography variant="body2" color="text.secondary">暂无调用日志</Typography>
        ) : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>时间</TableCell>
                  <TableCell>战队</TableCell>
                  <TableCell>指令</TableCell>
                  <TableCell>原始消息</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.list.map(function (r) {
                  return (
                    <TableRow key={r.id}>
                      <TableCell sx={{ whiteSpace: 'nowrap' }}>{r.created_at}</TableCell>
                      <TableCell>{r.tenant_name}</TableCell>
                      <TableCell><Chip size="small" label={r.command} /></TableCell>
                      <TableCell>{r.content}</TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}
        {data !== null && totalPages > 1 ? (
          <Stack direction="row" spacing={1} justifyContent="center" sx={{ mt: 1.5 }}>
            <Button size="small" disabled={page <= 1}
              onClick={function () { setPage(page - 1); load(page - 1); }}>上一页</Button>
            <Typography variant="caption" sx={{ alignSelf: 'center' }}>{page} / {totalPages}</Typography>
            <Button size="small" disabled={page >= totalPages}
              onClick={function () { setPage(page + 1); load(page + 1); }}>下一页</Button>
          </Stack>
        ) : null}
      </Paper>
    );
  }

  // ==================== 数据导入导出审核（v5.2.0：平台超管） ====================
  function PlatformDataTasks() {
    const [list, setList] = useState(null);
    const [rejectT, setRejectT] = useState(null);
    const [reason, setReason] = useState('');

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/data/tasks');
        setList(json.code === 0 ? json.data.list : []);
      } catch (e) { setList([]); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const approve = async function (t) {
      const tip = t.type === 'export'
        ? '同意「' + t.tenant_name + '」的导出申请？将打包该站全部数据（含图片，不含轮换地图）供其下载'
        : '同意「' + t.tenant_name + '」的导入申请？\n该站现有数据将被上传的数据包整体覆盖，请确认数据包来源可信';
      if (!window.confirm(tip)) return;
      try {
        const json = await api('/api/platform/admin/data/' + t.id + '/approve', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const doReject = async function () {
      try {
        const json = await api('/api/platform/admin/data/' + rejectT.id + '/reject',
          { method: 'POST', body: { reason: reason.trim() } });
        if (json.code === 0) { toast(json.msg, 'success'); setRejectT(null); setReason(''); load(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (list === null) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;
    if (list.length === 0) return <Alert severity="info">暂无数据导入导出任务</Alert>;

    return (
      <Box>
        <TableContainer>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>ID</TableCell><TableCell>站点</TableCell><TableCell>类型</TableCell>
                <TableCell>状态</TableCell><TableCell>包大小</TableCell>
                <TableCell>提交时间</TableCell><TableCell>备注</TableCell><TableCell>操作</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {list.map(function (t) {
                const chip = DATA_TASK_CHIP[t.status] || [t.status, 'default'];
                return (
                  <TableRow key={t.id}>
                    <TableCell>{t.id}</TableCell>
                    <TableCell>
                      <Typography variant="body2" fontWeight={600}>{t.tenant_name}</Typography>
                      {t.tenant_slug && <Typography variant="caption" color="text.secondary">/{t.tenant_slug}/</Typography>}
                    </TableCell>
                    <TableCell>{t.type === 'export' ? '导出' : '导入'}</TableCell>
                    <TableCell><Chip size="small" label={chip[0]} color={chip[1]} variant={t.status === 'pending' ? 'filled' : 'outlined'} /></TableCell>
                    <TableCell>{t.file_size > 0 ? fmtBytes(t.file_size) : '-'}</TableCell>
                    <TableCell>{fmtTime(t.created_at)}</TableCell>
                    <TableCell><Typography variant="caption" color="text.secondary" sx={{ maxWidth: 160, display: 'block' }}>{t.note || '-'}</Typography></TableCell>
                    <TableCell>
                      <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                        {t.status === 'pending' && (
                          <React.Fragment>
                            <Button size="small" variant="contained" onClick={() => approve(t)}>同意</Button>
                            <Button size="small" variant="outlined" color="error" onClick={() => { setRejectT(t); setReason(''); }}>驳回</Button>
                          </React.Fragment>
                        )}
                        {t.file_size > 0 && (
                          <Button size="small" onClick={function () { window.location.href = '/api/platform/admin/data/' + t.id + '/download'; }}>
                            查看包
                          </Button>
                        )}
                      </Stack>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>

        <Dialog open={!!rejectT} onClose={() => setRejectT(null)} fullWidth maxWidth="xs">
          <DialogTitle>驳回数据任务</DialogTitle>
          <DialogContent>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
              驳回后战队方可看到原因并可重新提交
            </Typography>
            <TextField label="驳回原因（可选）" value={reason} onChange={e => setReason(e.target.value)}
              inputProps={{ maxLength: 200 }} fullWidth size="small" autoFocus />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setRejectT(null)}>取消</Button>
            <Button variant="contained" color="error" onClick={doReject}>确认驳回</Button>
          </DialogActions>
        </Dialog>
      </Box>
    );
  }

  // ==================== 赛事约战（v5.4.0：平台超管为最高控制者） ====================
  const PB_STATUS = {
    pending: ['待接受', 'warning'], accepted: ['进行中', 'primary'],
    finished: ['已完赛', 'success'], rejected: ['已拒绝', 'default'],
    canceled: ['已取消', 'default'], expired: ['已过期', 'default'],
  };
  function pbChip(s) {
    const m = PB_STATUS[s] || [s, 'default'];
    return <Chip size="small" color={m[1]} label={m[0]} variant={m[1] === 'default' ? 'outlined' : 'filled'} />;
  }

  function PlatformBattles() {
    const [sub, setSub] = useState('battles');
    const [data, setData] = useState(null);            // {list, map_pool, tenants, externals}
    const [openId, setOpenId] = useState(0);
    const [detail, setDetail] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [rosterId, setRosterId] = useState(0);
    const [rform, setRform] = useState({ side: 'a', kind: 'name', tenant_id: '', external_team_id: '', content: '' });
    const [teams, setTeams] = useState(null);
    const [teamForm, setTeamForm] = useState(null);    // null=关闭；{id, name, contact_name, remark, status}
    const [keys, setKeys] = useState(null);
    const [issueForm, setIssueForm] = useState(null);  // {scope, external_team_id, name}
    const [plain, setPlain] = useState('');
    const [cf, setCf] = useState({ title: '', challenger: '', defender: '', format: 'bo3', btype: 'official', time: '', mode: '', limit: 20, room: '', remark: '' });
    const [rec, setRec] = useState({ battleId: 0, roundId: 0, result: '', secs: '', count: '', ratio: '' });
    const [dec, setDec] = useState({ battleId: 0, choice: 'pick_map', mapId: '', att: 'a' });

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/battles');
        if (json.code === 0) setData(json.data);
      } catch (e) { setData({ list: [], map_pool: [], tenants: [], externals: [] }); }
    }, []);
    const loadTeams = useCallback(async function () {
      try { const j = await api('/api/platform/admin/external-teams'); if (j.code === 0) setTeams(j.data || []); }
      catch (e) { setTeams([]); }
    }, []);
    const loadKeys = useCallback(async function () {
      try { const j = await api('/api/platform/admin/api-keys'); if (j.code === 0) setKeys(j.data || []); }
      catch (e) { setKeys([]); }
    }, []);
    useEffect(function () { load(); }, [load]);
    useEffect(function () {
      if (sub === 'teams') loadTeams();
      if (sub === 'keys') loadKeys();
    }, [sub, loadTeams, loadKeys]);

    const refreshDetail = async function (id) {
      try { const j = await api('/api/platform/admin/battles/' + id); if (j.code === 0) setDetail(j.data); }
      catch (e) { /* 忽略 */ }
    };
    const toggle = function (id) {
      if (openId === id) { setOpenId(0); setDetail(null); return; }
      setOpenId(id); setDetail(null); refreshDetail(id);
    };

    const draw = async function (b) {
      const tip = b.drawn ? '已抽签，重新抽签将重建全部小局（仅未录胜负时允许），确定重抽吗？'
        : '执行抽签：从地图池剔除双方禁用图后随机抽各局地图与攻守方，确定吗？';
      if (!window.confirm(tip)) return;
      try {
        const j = await api('/api/platform/admin/battles/' + b.id + '/draw', { method: 'POST' });
        if (j.code === 0) { toast(j.msg, 'success'); load(); if (openId === b.id) refreshDetail(b.id); }
        else { toast(j.msg, 'error'); }
      } catch (e) { toast('抽签失败', 'error'); }
    };

    // 逐局录胜负（含可选决胜统计：进攻用时/占领区域/占点比例）
    const submitRecord = async function () {
      if (!rec.result) { toast('请选择获胜方', 'error'); return; }
      const body = { result: rec.result };
      if (rec.secs !== '') body.attack_secs = rec.secs;
      if (rec.count !== '') body.occupy_count = rec.count;
      if (rec.ratio !== '') body.occupy_ratio = rec.ratio;
      const bid = rec.battleId;
      try {
        const j = await api('/api/platform/admin/battles/' + bid + '/round/' + rec.roundId + '/result',
          { method: 'POST', body: body });
        if (j.code === 0) { toast(j.msg, 'success'); setRec({ battleId: 0, roundId: 0, result: '', secs: '', count: '', ratio: '' }); load(); refreshDetail(bid); }
        else { toast(j.msg, 'error'); }
      } catch (e) { toast('录入失败', 'error'); }
    };

    // 手动指定决胜局优势方（正式赛赛事组裁决入口，本版本平台超管代管）
    const setAdv = async function (bid, side) {
      if (!window.confirm('指定 ' + (side === 'a' ? 'A 方' : 'B 方') + ' 为决胜局优势方？')) return;
      try {
        const j = await api('/api/platform/admin/battles/' + bid + '/advantage', { method: 'POST', body: { side: side } });
        if (j.code === 0) { toast(j.msg, 'success'); load(); refreshDetail(bid); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // 代优势方完成决胜局选图/选边（平台侧直接指定攻方 a/b）
    const submitDecider = async function () {
      const body = { choice: dec.choice };
      if (dec.choice === 'pick_map') {
        if (!dec.mapId) { toast('请选择决胜局地图', 'error'); return; }
        body.map_id = parseInt(dec.mapId, 10);
      } else { body.attacker_side = dec.att; }
      const bid = dec.battleId;
      try {
        const j = await api('/api/platform/admin/battles/' + bid + '/decider', { method: 'POST', body: body });
        if (j.code === 0) { toast(j.msg, 'success'); setDec({ battleId: 0, choice: 'pick_map', mapId: '', att: 'a' }); load(); refreshDetail(bid); }
        else { toast(j.msg, 'error'); }
      } catch (e) { toast('提交失败', 'error'); }
    };
    // 可选新图：平台池剔除本场已用与双方禁图（决胜局地图不可与前序地图相同）
    const deciderMaps = function () {
      if (!detail || !data) return [];
      const used = (detail.rounds || []).filter(function (r) { return r.map_id; }).map(function (r) { return r.map_id; });
      return data.map_pool.filter(function (m) {
        return used.indexOf(m.id) < 0
          && !(detail.ban_a && !detail.ban_a.passed && detail.ban_a.name === m.name)
          && !(detail.ban_b && !detail.ban_b.passed && detail.ban_b.name === m.name);
      });
    };

    const cancel = async function (id) {
      if (!window.confirm('强制取消该约战？')) return;
      try {
        const j = await api('/api/platform/admin/battles/' + id + '/cancel', { method: 'POST' });
        if (j.code === 0) { toast(j.msg, 'success'); load(); if (openId === id) refreshDetail(id); }
        else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const submitCreate = async function () {
      if (!cf.challenger || !cf.defender) { toast('请选择双方战队', 'error'); return; }
      const c = cf.challenger.split(':'), d = cf.defender.split(':');
      try {
        const j = await api('/api/platform/admin/battles', { method: 'POST', body: {
          title: cf.title, challenger_type: c[0], challenger_id: parseInt(c[1], 10),
          defender_type: d[0], defender_id: parseInt(d[1], 10),
          series_format: cf.format, battle_type: cf.btype, battle_time: cf.time.replace('T', ' '),
          game_mode: cf.mode, roster_limit: parseInt(cf.limit, 10) || 0, room_info: cf.room, remark: cf.remark,
        } });
        if (j.code === 0) {
          toast(j.msg, 'success'); setCreateOpen(false);
          setCf({ title: '', challenger: '', defender: '', format: 'bo3', btype: 'official', time: '', mode: '', limit: 20, room: '', remark: '' });
          load();
        } else { toast(j.msg, 'error'); }
      } catch (e) { toast('发起失败', 'error'); }
    };

    const submitRoster = async function () {
      const body = { side: rform.side };
      if (rform.kind === 'user') {
        const tid = parseInt(rform.tenant_id, 10);
        if (!tid) { toast('请填写用户所属租户 id', 'error'); return; }
        body.add_user_ids = rform.content.split(/[,，\s]+/).filter(Boolean).map(Number);
        body.tenant_id = tid;
      } else {
        const eid = parseInt(rform.external_team_id, 10);
        if (!eid) { toast('请填写外部战队 id', 'error'); return; }
        body.add_names = rform.content.split(/[,，]/).map(function (s) { return s.trim(); }).filter(Boolean);
        body.external_team_id = eid;
      }
      try {
        const j = await api('/api/platform/admin/battles/' + rosterId + '/roster', { method: 'POST', body: body });
        if (j.code === 0) { toast(j.msg, 'success'); setRosterId(0); load(); if (openId === rosterId) refreshDetail(rosterId); }
        else { toast(j.msg, 'error'); }
      } catch (e) { toast('提交失败', 'error'); }
    };
    const removeSignup = async function (bid, sid) {
      try {
        const j = await api('/api/platform/admin/battles/' + bid + '/roster', { method: 'POST', body: { remove_id: sid } });
        if (j.code === 0) { toast(j.msg, 'success'); refreshDetail(bid); load(); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // 外部战队保存 / 密钥签发与状态切换 / 删除复用同一套 api
    const saveTeam = async function () {
      if (!teamForm.name.trim()) { toast('请填写战队名称', 'error'); return; }
      try {
        const j = await api('/api/platform/admin/external-teams/save', { method: 'POST', body: teamForm });
        if (j.code === 0) { toast(j.msg, 'success'); setTeamForm(null); loadTeams(); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); }
    };
    const issueKey = async function () {
      try {
        const j = await api('/api/platform/admin/api-keys/issue', { method: 'POST', body: {
          scope: issueForm.scope, external_team_id: parseInt(issueForm.external_team_id, 10) || 0, name: issueForm.name,
        } });
        if (j.code === 0) { setIssueForm(null); setPlain(j.data.key); loadKeys(); loadTeams(); }
        else { toast(j.msg, 'error'); }
      } catch (e) { toast('签发失败', 'error'); }
    };
    const toggleKey = async function (id) {
      try {
        const j = await api('/api/platform/admin/api-keys/' + id + '/status', { method: 'POST' });
        if (j.code === 0) { toast(j.msg, 'success'); loadKeys(); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };
    const removeKey = async function (id) {
      if (!window.confirm('删除后该密钥立即失效，确定吗？')) return;
      try {
        const j = await api('/api/platform/admin/api-keys/' + id, { method: 'DELETE' });
        if (j.code === 0) { toast(j.msg, 'success'); loadKeys(); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const partyOptions = function () {
      const opts = [];
      ((data && data.tenants) || []).forEach(function (t) { opts.push({ v: 'tenant:' + t.id, l: t.name + '（租户）' }); });
      ((data && data.externals) || []).forEach(function (t) { opts.push({ v: 'external:' + t.id, l: t.name + '（外部）' }); });
      return opts;
    };

    const banText = function (ban) {
      if (ban === null || ban === undefined) return '未提交';
      return ban.passed ? '放弃禁用' : '禁用：' + ban.name;
    };

    const battleCard = function (b) {
      const expanded = openId === b.id;
      return (
        <Paper key={b.id} variant="outlined" sx={{ p: 2, mb: 1.5 }}>
          <Stack direction="row" spacing={1.5} alignItems="center">
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Stack direction="row" spacing={1} alignItems="center" sx={{ flexWrap: 'wrap' }}>
                <Typography fontWeight={600}>{b.challenger.name}</Typography>
                <Chip size="small" label="VS" />
                <Typography fontWeight={600}>{b.defender.name}</Typography>
                <Chip size="small" variant="outlined" color="primary" label={(b.series_format || 'bo3').toUpperCase()} />
                <Chip size="small" variant="outlined" color={b.battle_type === 'training' ? 'warning' : 'info'}
                  label={b.battle_type === 'training' ? '训练赛' : '正式赛事'} />
                {pbChip(b.status)}
                {b.drawn && <Chip size="small" variant="outlined" label="已抽签" />}
              </Stack>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.4 }}>
                {b.title}{b.game_mode ? ' · ' + b.game_mode : ''} · 开赛：{(b.battle_time || '').slice(0, 16)}
              </Typography>
            </Box>
            {b.drawn && (
              <Typography sx={{ fontFamily: 'Consolas, monospace', fontWeight: 700, color: 'primary.main' }}>
                {b.score_a} : {b.score_b}
              </Typography>
            )}
            {b.status === 'accepted' && (
              <Stack direction="row" spacing={0.8}>
                {b.battle_type !== 'training' && (
                  <Button size="small" variant="contained" onClick={() => draw(b)}>{b.drawn ? '重抽' : '抽签'}</Button>
                )}
                <Button size="small" variant="outlined" onClick={() => { setRosterId(b.id); setRform({ side: 'a', kind: 'name', tenant_id: '', external_team_id: '', content: '' }); }}>名单调配</Button>
                <Button size="small" color="error" onClick={() => cancel(b.id)}>强制取消</Button>
              </Stack>
            )}
            <Button size="small" onClick={() => toggle(b.id)}>{expanded ? '收起' : '详情'}</Button>
          </Stack>

          {expanded && (detail ? (
            <Box sx={{ mt: 1.5, pt: 1.5, borderTop: '1px dashed', borderColor: 'divider' }}>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
                禁用地图：{detail.challenger.name} {banText(detail.ban_a)} · {detail.defender.name} {banText(detail.ban_b)}
                {detail.result_note ? ' · ' + detail.result_note : ''}
              </Typography>
              {(detail.series_format === 'bo3' || detail.series_format === 'bo5') && detail.status === 'accepted' && (
                <Stack direction="row" spacing={1} alignItems="center" sx={{ flexWrap: 'wrap', mb: 1 }}>
                  {detail.adv_side ? (
                    <Chip size="small" color="warning" label={'决胜优先权：' + (detail.adv_side === 'a' ? detail.challenger.name : detail.defender.name)} />
                  ) : (
                    <Stack direction="row" spacing={0.8} alignItems="center">
                      <Typography variant="caption" color="text.secondary">{detail.adv_reason || '决胜局优势方待指定'}</Typography>
                      <Button size="small" variant="outlined" onClick={() => setAdv(b.id, 'a')}>指定 A 方优势</Button>
                      <Button size="small" variant="outlined" onClick={() => setAdv(b.id, 'b')}>指定 B 方优势</Button>
                    </Stack>
                  )}
                  {detail.adv_reason && detail.adv_side && <Typography variant="caption" color="text.secondary">{detail.adv_reason}</Typography>}
                  {detail.adv_role && <Chip size="small" variant="outlined" label={detail.adv_role === 'pick_map' ? '已选图' : '已选边'} />}
                  {detail.adv_side && !detail.adv_role && (
                    <Button size="small" variant="contained"
                      onClick={() => setDec({ battleId: b.id, choice: 'pick_map', mapId: '', att: 'a' })}>代选决胜（选图/选边）</Button>
                  )}
                </Stack>
              )}
              <Stack direction="row" spacing={2} sx={{ mb: 1.5, flexWrap: 'wrap' }}>
                {[['a', detail.challenger.name, detail.signups_a], ['b', detail.defender.name, detail.signups_b]].map(function (s) {
                  return (
                    <Box key={s[0]} sx={{ flex: 1, minWidth: 220 }}>
                      <Typography variant="body2" fontWeight={600} sx={{ mb: 0.5 }}>{s[1]}（{s[2].length} 人）</Typography>
                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                        {s[2].length === 0 ? <Typography variant="caption" color="text.secondary">暂无</Typography>
                          : s[2].map(function (su) {
                            return <Chip key={su.id} size="small" label={su.name} onDelete={() => removeSignup(b.id, su.id)} />;
                          })}
                      </Box>
                    </Box>
                  );
                })}
              </Stack>
              {detail.rounds.length === 0 ? (
                <Typography variant="caption" color="text.secondary">
                  {detail.battle_type === 'training' ? '训练赛：等待双方提交禁图后自动抽签' : '尚未抽签，点击右上「抽签」生成各局地图与攻守'}
                </Typography>
              ) : detail.rounds.map(function (r) {
                const winner = r.result === 'a_win' ? detail.challenger.name : r.result === 'b_win' ? detail.defender.name : null;
                const stats = [];
                if (r.attack_secs !== null) stats.push('进攻用时 ' + Math.floor(r.attack_secs / 60) + '分' + (r.attack_secs % 60) + '秒');
                if (r.occupy_count !== null) stats.push('占领区域 ' + r.occupy_count);
                if (r.occupy_ratio !== null) stats.push('占点 ' + r.occupy_ratio + '%');
                return (
                  <Paper key={r.round_no} variant="outlined" sx={{ p: 1.2, mb: 1, display: 'flex', alignItems: 'center', gap: 1.5 }}>
                    <Typography variant="body2" sx={{ width: 60 }}>第 {r.round_no} 局</Typography>
                    {r.is_decider && <Chip size="small" color="warning" label="决胜" />}
                    <Typography variant="body2" fontWeight={600} sx={{ width: 130 }}>{r.map_name || '待定（选图/选边）'}</Typography>
                    <Typography variant="caption" color="text.secondary" sx={{ flex: 1 }}>
                      攻：{r.attacker_name || '待定'} · 守：{r.defender_name || '待定'}
                      {stats.length > 0 ? ' · ' + stats.join(' · ') : ''}
                    </Typography>
                    {r.evidence && <Link href={r.evidence} target="_blank" rel="noopener" variant="caption">截图</Link>}
                    {winner ? <Chip size="small" color="success" label={winner + ' 胜'} /> : (
                      b.status === 'accepted' ? (
                        r.is_decider && r.map_id === null
                          ? <Chip size="small" variant="outlined" label="待选图/选边" />
                          : <Button size="small" variant="outlined"
                              onClick={() => setRec({ battleId: b.id, roundId: r.id, result: '', secs: '', count: '', ratio: '' })}>录胜负</Button>
                      ) : <Chip size="small" variant="outlined" label="未开赛" />
                    )}
                  </Paper>
                );
              })}
            </Box>
          ) : <Box sx={{ mt: 1.5 }}><CircularProgress size={22} /></Box>)}
        </Paper>
      );
    };

    return (
      <Box>
        <Tabs value={sub} onChange={(e, v) => setSub(v)} sx={{ borderBottom: 1, borderColor: 'divider', mb: 2 }}>
          <Tab value="battles" label="约战总览" />
          <Tab value="teams" label="外部战队" />
          <Tab value="keys" label="API 密钥" />
        </Tabs>

        {sub === 'battles' && (
          data === null ? <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box> : (
            <Box>
              <Stack direction="row" spacing={1} sx={{ mb: 2 }}>
                <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>
                  地图池 {data.map_pool.length} 张（轮换地图）· 抽签排除双 Ban 后随机抽取，逐局录入胜负自动结算
                </Typography>
                <Button variant="outlined" onClick={load}>刷新</Button>
                <Button variant="contained" onClick={() => setCreateOpen(true)}>代发起约战</Button>
              </Stack>
              {data.list.length === 0 ? <Alert severity="info">暂无约战</Alert> : data.list.map(battleCard)}
            </Box>
          )
        )}

        {sub === 'teams' && (
          teams === null ? <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box> : (
            <Box>
              <Stack direction="row" spacing={1} sx={{ mb: 2 }}>
                <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>
                  开源单租户站接入约战总线：登记后签发 external Key，对方在站点设置中填入即可接入
                </Typography>
                <Button variant="contained" onClick={() => setTeamForm({ id: 0, name: '', contact_name: '', remark: '', status: 1 })}>登记外部战队</Button>
              </Stack>
              {teams.length === 0 ? <Alert severity="info">暂无外部战队</Alert> : (
                <TableContainer>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell>ID</TableCell><TableCell>队名</TableCell><TableCell>联系人</TableCell>
                        <TableCell>备注</TableCell><TableCell>状态</TableCell><TableCell>密钥</TableCell><TableCell>操作</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {teams.map(function (t) {
                        return (
                          <TableRow key={t.id}>
                            <TableCell>{t.id}</TableCell>
                            <TableCell>{t.name}</TableCell>
                            <TableCell>{t.contact_name || '-'}</TableCell>
                            <TableCell>{t.remark || '-'}</TableCell>
                            <TableCell>{parseInt(t.status, 10) === 1 ? <Chip size="small" color="success" label="正常" /> : <Chip size="small" label="停用" />}</TableCell>
                            <TableCell>
                              <Button size="small" onClick={() => setIssueForm({ scope: 'external', external_team_id: String(t.id), name: t.name })}>签发 Key</Button>
                            </TableCell>
                            <TableCell>
                              <Button size="small" onClick={() => setTeamForm({ id: t.id, name: t.name, contact_name: t.contact_name, remark: t.remark, status: parseInt(t.status, 10) })}>编辑</Button>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </TableContainer>
              )}
            </Box>
          )
        )}

        {sub === 'keys' && (
          keys === null ? <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box> : (
            <Box>
              <Stack direction="row" spacing={1} sx={{ mb: 2 }}>
                <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>
                  platform 键等同平台超管（抽签/录入胜负）；租户自建键在各战队管理中心维护，此处仅列平台侧密钥
                </Typography>
                <Button variant="contained" onClick={() => setIssueForm({ scope: 'platform', external_team_id: '', name: '' })}>签发密钥</Button>
              </Stack>
              {keys.length === 0 ? <Alert severity="info">暂无平台侧密钥</Alert> : (
                <TableContainer>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell>前缀</TableCell><TableCell>备注</TableCell><TableCell>类型</TableCell>
                        <TableCell>外部战队</TableCell><TableCell>状态</TableCell><TableCell>最后使用</TableCell><TableCell>操作</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {keys.map(function (k) {
                        return (
                          <TableRow key={k.id}>
                            <TableCell sx={{ fontFamily: 'Consolas, monospace' }}>{k.key_prefix}…</TableCell>
                            <TableCell>{k.name}</TableCell>
                            <TableCell>{k.scope === 'platform' ? '平台' : '外部战队'}</TableCell>
                            <TableCell>{k.team_name || '-'}</TableCell>
                            <TableCell>{parseInt(k.status, 10) === 1 ? <Chip size="small" color="success" label="启用" /> : <Chip size="small" label="停用" />}</TableCell>
                            <TableCell>{(k.last_used_at || '').slice(0, 16) || '-'}</TableCell>
                            <TableCell>
                              <Button size="small" onClick={() => toggleKey(k.id)}>{parseInt(k.status, 10) === 1 ? '停用' : '启用'}</Button>
                              <Button size="small" color="error" onClick={() => removeKey(k.id)}>删除</Button>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </TableContainer>
              )}
            </Box>
          )
        )}

        {/* 代发起 */}
        <Dialog open={createOpen} onClose={() => setCreateOpen(false)} fullWidth maxWidth="sm">
          <DialogTitle>代发起约战</DialogTitle>
          <DialogContent dividers>
            <TextField label="标题" fullWidth sx={{ mb: 2, mt: 1 }} inputProps={{ maxLength: 50 }}
              value={cf.title} onChange={(e) => setCf(Object.assign({}, cf, { title: e.target.value }))} />
            <Stack direction="row" spacing={2} sx={{ mb: 2 }}>
              <Box sx={{ flex: 1 }}>
                <InputLabel shrink>发起方（A）</InputLabel>
                <Select fullWidth value={cf.challenger} displayEmpty onChange={(e) => setCf(Object.assign({}, cf, { challenger: e.target.value }))}>
                  <MenuItem value="">请选择</MenuItem>
                  {partyOptions().map(function (o) { return <MenuItem key={o.v} value={o.v}>{o.l}</MenuItem>; })}
                </Select>
              </Box>
              <Box sx={{ flex: 1 }}>
                <InputLabel shrink>对方（B）</InputLabel>
                <Select fullWidth value={cf.defender} displayEmpty onChange={(e) => setCf(Object.assign({}, cf, { defender: e.target.value }))}>
                  <MenuItem value="">请选择</MenuItem>
                  {partyOptions().map(function (o) { return <MenuItem key={o.v} value={o.v}>{o.l}</MenuItem>; })}
                </Select>
              </Box>
            </Stack>
            <Stack direction="row" spacing={2} sx={{ mb: 2 }}>
              <TextField select label="赛事类型" sx={{ width: 200 }} value={cf.btype}
                onChange={(e) => setCf(Object.assign({}, cf, { btype: e.target.value }))}>
                <MenuItem value="official">正式赛事（平台裁决）</MenuItem>
                <MenuItem value="training">训练赛（自动抽签自裁决）</MenuItem>
              </TextField>
              <TextField select label="赛制" sx={{ width: 240 }} value={cf.format}
                onChange={(e) => setCf(Object.assign({}, cf, { format: e.target.value }))}>
                <MenuItem value="bo1">BO1 单局</MenuItem>
                <MenuItem value="bo3">BO3 三局两胜</MenuItem>
                <MenuItem value="bo5">BO5 五局三胜</MenuItem>
                <MenuItem value="r2">R2 一图攻防转换两把</MenuItem>
                <MenuItem value="r4">R4 两图攻防转换四把</MenuItem>
              </TextField>
              <TextField label="每方人数上限（0=不限）" sx={{ flex: 1 }} type="number" inputProps={{ min: 0, max: 100 }}
                value={cf.limit} onChange={(e) => setCf(Object.assign({}, cf, { limit: e.target.value }))} />
            </Stack>
            <TextField label="开赛时间" type="datetime-local" fullWidth sx={{ mb: 2 }} InputLabelProps={{ shrink: true }}
              value={cf.time} onChange={(e) => setCf(Object.assign({}, cf, { time: e.target.value }))} />
            <TextField label="模式说明" fullWidth sx={{ mb: 2 }} inputProps={{ maxLength: 50 }}
              value={cf.mode} onChange={(e) => setCf(Object.assign({}, cf, { mode: e.target.value }))} />
            <TextField label="房间/地点" fullWidth sx={{ mb: 2 }} inputProps={{ maxLength: 200 }}
              value={cf.room} onChange={(e) => setCf(Object.assign({}, cf, { room: e.target.value }))} />
            <TextField label="备注" fullWidth multiline minRows={2} inputProps={{ maxLength: 500 }}
              value={cf.remark} onChange={(e) => setCf(Object.assign({}, cf, { remark: e.target.value }))} />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setCreateOpen(false)}>取消</Button>
            <Button variant="contained" onClick={submitCreate}>发起</Button>
          </DialogActions>
        </Dialog>

        {/* 逐局录胜负（含可选决胜统计） */}
        <Dialog open={rec.battleId > 0} onClose={() => setRec({ battleId: 0, roundId: 0, result: '', secs: '', count: '', ratio: '' })} fullWidth maxWidth="xs">
          <DialogTitle>录入小局胜负</DialogTitle>
          <DialogContent dividers>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              BO3 战成 1:1 时的决胜判定依据：双攻胜比进攻用时，双守胜比占领区域，相同比占点比例。
            </Typography>
            <TextField select label="获胜方" fullWidth sx={{ mb: 2 }} value={rec.result}
              onChange={(e) => setRec(Object.assign({}, rec, { result: e.target.value }))}>
              <MenuItem value="">请选择</MenuItem>
              {detail && <MenuItem value="a_win">A 方：{detail.challenger.name}</MenuItem>}
              {detail && <MenuItem value="b_win">B 方：{detail.defender.name}</MenuItem>}
            </TextField>
            <Stack spacing={1.5}>
              <TextField label="进攻用时（秒）" type="number" fullWidth inputProps={{ min: 0 }} value={rec.secs}
                onChange={(e) => setRec(Object.assign({}, rec, { secs: e.target.value }))} />
              <TextField label="占领区域数" type="number" fullWidth inputProps={{ min: 0 }} value={rec.count}
                onChange={(e) => setRec(Object.assign({}, rec, { count: e.target.value }))} />
              <TextField label="占点比例（%）" type="number" fullWidth inputProps={{ min: 0, max: 100 }} value={rec.ratio}
                onChange={(e) => setRec(Object.assign({}, rec, { ratio: e.target.value }))} />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setRec({ battleId: 0, roundId: 0, result: '', secs: '', count: '', ratio: '' })}>取消</Button>
            <Button variant="contained" onClick={submitRecord}>提交</Button>
          </DialogActions>
        </Dialog>

        {/* 代优势方选图/选边 */}
        <Dialog open={dec.battleId > 0} onClose={() => setDec({ battleId: 0, choice: 'pick_map', mapId: '', att: 'a' })} fullWidth maxWidth="xs">
          <DialogTitle>决胜局：代优势方选图/选边</DialogTitle>
          <DialogContent dividers>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              选图：指定地图，攻防随机；选边：直接指定攻方（a=发起方 / b=应战方），地图从剩余新图随机抽取。
            </Typography>
            <TextField select label="决胜优势用法" fullWidth sx={{ mb: 2 }} value={dec.choice}
              onChange={(e) => setDec(Object.assign({}, dec, { choice: e.target.value }))}>
              <MenuItem value="pick_map">选图（指定地图）</MenuItem>
              <MenuItem value="pick_side">选边（指定攻方）</MenuItem>
            </TextField>
            {dec.choice === 'pick_map' ? (
              <Select fullWidth value={dec.mapId} displayEmpty onChange={(e) => setDec(Object.assign({}, dec, { mapId: e.target.value }))}>
                <MenuItem value="">请选择决胜局地图</MenuItem>
                {deciderMaps().map(function (m) { return <MenuItem key={m.id} value={String(m.id)}>{m.name}</MenuItem>; })}
              </Select>
            ) : (
              <Select fullWidth value={dec.att} onChange={(e) => setDec(Object.assign({}, dec, { att: e.target.value }))}>
                {detail && <MenuItem value="a">攻方：{detail.challenger.name}（A）</MenuItem>}
                {detail && <MenuItem value="b">攻方：{detail.defender.name}（B）</MenuItem>}
              </Select>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setDec({ battleId: 0, choice: 'pick_map', mapId: '', att: 'a' })}>取消</Button>
            <Button variant="contained" onClick={submitDecider}>提交</Button>
          </DialogActions>
        </Dialog>

        {/* 名单调配 */}
        <Dialog open={rosterId > 0} onClose={() => setRosterId(0)} fullWidth maxWidth="xs">
          <DialogTitle>名单调配（超管不受上限约束）</DialogTitle>
          <DialogContent dividers>
            <Stack spacing={2} sx={{ mt: 1 }}>
              <TextField select label="调配哪一方" value={rform.side}
                onChange={(e) => setRform(Object.assign({}, rform, { side: e.target.value }))}>
                <MenuItem value="a">A 方（发起方）</MenuItem>
                <MenuItem value="b">B 方（应战方）</MenuItem>
              </TextField>
              <TextField select label="添加类型" value={rform.kind}
                onChange={(e) => setRform(Object.assign({}, rform, { kind: e.target.value }))}>
                <MenuItem value="name">昵称（外部战队名单）</MenuItem>
                <MenuItem value="user">平台用户 id</MenuItem>
              </TextField>
              {rform.kind === 'user' ? (
                <TextField label="所属租户 id" value={rform.tenant_id}
                  onChange={(e) => setRform(Object.assign({}, rform, { tenant_id: e.target.value }))} />
              ) : (
                <TextField label="外部战队 id" value={rform.external_team_id}
                  onChange={(e) => setRform(Object.assign({}, rform, { external_team_id: e.target.value }))} />
              )}
              <TextField label={rform.kind === 'user' ? '用户 id（逗号分隔）' : '昵称（逗号分隔）'} fullWidth
                value={rform.content} onChange={(e) => setRform(Object.assign({}, rform, { content: e.target.value }))} />
              <Typography variant="caption" color="text.secondary">移除名单：在展开的详情里点击名单标签的 ×</Typography>
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setRosterId(0)}>取消</Button>
            <Button variant="contained" onClick={submitRoster}>添加</Button>
          </DialogActions>
        </Dialog>

        {/* 外部战队登记/编辑 */}
        <Dialog open={teamForm !== null} onClose={() => setTeamForm(null)} fullWidth maxWidth="xs">
          <DialogTitle>{teamForm && teamForm.id ? '编辑外部战队' : '登记外部战队'}</DialogTitle>
          <DialogContent dividers>
            {teamForm && (
              <Stack spacing={2} sx={{ mt: 1 }}>
                <TextField label="队名" fullWidth inputProps={{ maxLength: 50 }}
                  value={teamForm.name} onChange={(e) => setTeamForm(Object.assign({}, teamForm, { name: e.target.value }))} />
                <TextField label="联系人" fullWidth inputProps={{ maxLength: 50 }}
                  value={teamForm.contact_name} onChange={(e) => setTeamForm(Object.assign({}, teamForm, { contact_name: e.target.value }))} />
                <TextField label="备注" fullWidth multiline minRows={2} inputProps={{ maxLength: 200 }}
                  value={teamForm.remark} onChange={(e) => setTeamForm(Object.assign({}, teamForm, { remark: e.target.value }))} />
                <FormControlLabel control={<Switch checked={teamForm.status === 1}
                  onChange={(e) => setTeamForm(Object.assign({}, teamForm, { status: e.target.checked ? 1 : 0 }))} />}
                  label={teamForm.status === 1 ? '正常' : '停用（其 Key 将不可用）'} />
              </Stack>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setTeamForm(null)}>取消</Button>
            <Button variant="contained" onClick={saveTeam}>保存</Button>
          </DialogActions>
        </Dialog>

        {/* 签发密钥 */}
        <Dialog open={issueForm !== null} onClose={() => setIssueForm(null)} fullWidth maxWidth="xs">
          <DialogTitle>签发 API 密钥</DialogTitle>
          <DialogContent dividers>
            {issueForm && (
              <Stack spacing={2} sx={{ mt: 1 }}>
                <TextField select label="类型" value={issueForm.scope}
                  onChange={(e) => setIssueForm(Object.assign({}, issueForm, { scope: e.target.value }))}>
                  <MenuItem value="platform">platform（等同平台超管）</MenuItem>
                  <MenuItem value="external">external（外部战队接入）</MenuItem>
                </TextField>
                {issueForm.scope === 'external' && (
                  <TextField select label="外部战队" value={issueForm.external_team_id}
                    onChange={(e) => setIssueForm(Object.assign({}, issueForm, { external_team_id: e.target.value }))}>
                    <MenuItem value="">请选择</MenuItem>
                    {(teams || []).map(function (t) { return <MenuItem key={t.id} value={String(t.id)}>{t.name}</MenuItem>; })}
                  </TextField>
                )}
                <TextField label="备注" fullWidth value={issueForm.name}
                  onChange={(e) => setIssueForm(Object.assign({}, issueForm, { name: e.target.value }))} />
              </Stack>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setIssueForm(null)}>取消</Button>
            <Button variant="contained" onClick={issueKey}>签发</Button>
          </DialogActions>
        </Dialog>

        {/* 密钥明文一次性展示 */}
        <Dialog open={plain !== ''} onClose={() => setPlain('')} fullWidth maxWidth="sm">
          <DialogTitle>密钥已生成</DialogTitle>
          <DialogContent dividers>
            <Alert severity="warning" sx={{ mb: 1.5 }}>密钥仅本次显示，请立即保存！遗失只能删除重发。</Alert>
            <TextField fullWidth value={plain} InputProps={{ readOnly: true, sx: { fontFamily: 'Consolas, monospace' } }} />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => { try { navigator.clipboard.writeText(plain); toast('已复制', 'success'); } catch (e) { toast('复制失败', 'error'); } }}>复制</Button>
            <Button variant="contained" onClick={() => setPlain('')}>我已保存</Button>
          </DialogActions>
        </Dialog>
      </Box>
    );
  }

  // ==================== 赛事中心互通配置（v5.5.0）+ 多站点绑定（v5.5.1） ====================
  function PlatformTournament() {
    const [cfg, setCfg] = useState(null);
    const [url, setUrl] = useState('');
    const [key, setKey] = useState('');
    // v5.5.1：平台共享库多站点
    const [binds, setBinds] = useState(null);
    const [editB, setEditB] = useState(null);
    const [bForm, setBForm] = useState({ name: '', url: '', key: '', channel: 'external', enabled: 1 });
    const [confirmDel, setConfirmDel] = useState(null);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/tournament-config');
        if (json.code === 0) { setCfg(json.data); setUrl(json.data.url || ''); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('加载失败', 'error'); }
    }, []);
    const loadBinds = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/tournament-bindings');
        if (json.code === 0) setBinds(json.data.bindings || []);
      } catch (e) { setBinds([]); }
    }, []);
    useEffect(function () { load(); loadBinds(); }, [load, loadBinds]);

    const save = async function () {
      try {
        const json = await api('/api/platform/admin/tournament-config',
          { method: 'POST', body: { url: url.trim(), key: key.trim() } });
        if (json.code === 0) { toast(json.msg, 'success'); setKey(''); load(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); }
    };

    const openAdd = function () { setEditB({ __new: true }); setBForm({ name: '', url: '', key: '', channel: 'external', enabled: 1 }); };
    const openEdit = function (b) {
      setEditB(b);
      setBForm({ name: b.name, url: b.base_url, key: '', channel: b.channel, enabled: b.enabled ? 1 : 0 });
    };
    const saveBinding = async function () {
      const body = { name: bForm.name.trim(), base_url: bForm.url.trim(), key: bForm.key.trim(), channel: bForm.channel, enabled: bForm.enabled };
      const id = editB && !editB.__new ? editB.id : 0;
      try {
        const path = '/api/platform/admin/tournament-bindings' + (id ? '/' + id : '');
        const json = await api(path, { method: 'POST', body: body });
        if (json.code === 0) { toast(json.msg, 'success'); setEditB(null); loadBinds(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); }
    };
    const delBinding = async function () {
      try {
        const json = await api('/api/platform/admin/tournament-bindings/' + confirmDel.id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); setConfirmDel(null); loadBinds(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('删除失败', 'error'); }
    };

    if (!cfg) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          赛事中心是独立部署的网站（自带报名审核/双败对阵/对局引擎）。平台可维护<b>多个赛事中心</b>（共享库）供所有战队接入；
          战队也可自行添加未收录的赛事中心并获授权后启用。反向：赛事站 .env 需配置本站地址与一枚本平台签发的 platform Key 做身份核验。
        </Alert>

        <Stack spacing={1.5} sx={{ maxWidth: 560, mb: 3 }}>
          <Typography variant="subtitle1" fontWeight={600}>历史单站点连接（v5.5.0，兼容保留）</Typography>
          <TextField label="赛事中心地址" size="small" value={url} onChange={e => setUrl(e.target.value)}
            helperText="赛事站完整访问地址（http/https 开头），清空即断开互通" placeholder="https://tournament.example.com" />
          <TextField label="赛事站 API Key" size="small" value={key} onChange={e => setKey(e.target.value)}
            helperText={cfg.key_set ? '已设置（' + cfg.key_masked + '），留空保持不变' : '在赛事站赛事组工作台「账号与密钥」签发'}
            placeholder={cfg.key_set ? '••••••••（已设置，留空保持）' : '粘贴赛事站签发的 Key'} />
          <Stack direction="row" spacing={1}>
            <Button variant="contained" onClick={save}>保存配置</Button>
            {cfg.url ? <Button variant="outlined" onClick={() => window.open(cfg.url, '_blank')}>打开赛事站门户</Button> : null}
          </Stack>
        </Stack>

        <Divider sx={{ my: 2.5 }} />

        <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1.5 }}>
          <Typography variant="subtitle1" fontWeight={600}>赛事中心共享库（多站点，v5.5.1）</Typography>
          <Button size="small" variant="contained" onClick={openAdd}>新增赛事中心</Button>
        </Stack>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
          共享库中的赛事中心对所有战队可见、可直接加入使用（无需逐一确认）。Key 只存服务端、打码回显。
        </Typography>
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>名称</TableCell>
                <TableCell>地址</TableCell>
                <TableCell>身份通道</TableCell>
                <TableCell>状态</TableCell>
                <TableCell align="right">操作</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {!binds ? (
                <TableRow><TableCell colSpan={5} align="center"><CircularProgress size={22} /></TableCell></TableRow>
              ) : binds.length === 0 ? (
                <TableRow><TableCell colSpan={5} align="center" sx={{ color: 'text.secondary' }}>暂无共享赛事中心，点击右上角新增</TableCell></TableRow>
              ) : binds.map(function (b) {
                return (
                  <TableRow key={b.id} hover>
                    <TableCell>{b.name}</TableCell>
                    <TableCell sx={{ color: 'text.secondary', maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{b.base_url || b.url}</TableCell>
                    <TableCell>{b.channel === 'tenant' ? '租户身份' : '外部身份'}</TableCell>
                    <TableCell><Chip size="small" label={b.enabled ? '启用' : '停用'} color={b.enabled ? 'success' : 'default'} /></TableCell>
                    <TableCell align="right">
                      <Button size="small" onClick={() => openEdit(b)}>编辑</Button>
                      <Button size="small" color="error" onClick={() => setConfirmDel(b)}>删除</Button>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>

        {/* 新增/编辑对话框 */}
        <Dialog open={!!editB} onClose={() => setEditB(null)} fullWidth maxWidth="sm">
          <DialogTitle>{editB && editB.__new ? '新增赛事中心' : '编辑赛事中心'}</DialogTitle>
          <DialogContent>
            <Stack spacing={1.5} sx={{ mt: 1 }}>
              <TextField label="名称" size="small" value={bForm.name} onChange={e => setBForm({ ...bForm, name: e.target.value })} helperText="如：三角洲邀请赛·华东区" />
              <TextField label="赛事中心地址" size="small" value={bForm.url} onChange={e => setBForm({ ...bForm, url: e.target.value })} placeholder="https://tournament.example.com" />
              <TextField label="API Key" size="small" value={bForm.key} onChange={e => setBForm({ ...bForm, key: e.target.value })}
                helperText={editB && !editB.__new && editB.key_set ? '已设置（' + editB.key_masked + '），留空保持不变' : '在赛事站赛事组工作台「账号与密钥」签发'} />
              <TextField select label="身份通道" size="small" value={bForm.channel} onChange={e => setBForm({ ...bForm, channel: e.target.value })}
                helperText="外部身份=通用第三方站用我方为该战队维护的外部战队身份；租户身份=仅对接能回调核验租户的站">
                <MenuItem value="external">外部战队身份（推荐，通用）</MenuItem>
                <MenuItem value="tenant">租户身份（高信任站点）</MenuItem>
              </TextField>
              <FormControlLabel control={
                <Checkbox size="small" checked={!!bForm.enabled} onChange={e => setBForm({ ...bForm, enabled: e.target.checked ? 1 : 0 })} />
              } label="启用（战队可加入使用）" />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setEditB(null)}>取消</Button>
            <Button variant="contained" onClick={saveBinding}>保存</Button>
          </DialogActions>
        </Dialog>

        {/* 删除确认 */}
        <Dialog open={!!confirmDel} onClose={() => setConfirmDel(null)} fullWidth maxWidth="xs">
          <DialogTitle>删除赛事中心</DialogTitle>
          <DialogContent>
            <Typography variant="body2" color="text.secondary">删除后战队将无法再使用「{confirmDel && confirmDel.name}」，确定删除？</Typography>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setConfirmDel(null)}>取消</Button>
            <Button variant="contained" color="error" onClick={delBinding}>确认删除</Button>
          </DialogActions>
        </Dialog>
      </Box>
    );
  }

  // ==================== v5.16.0：隐身巡查台账 ====================
  // 平台超管看战队后台数据只有这一条通道：/{slug}/api/inspect/*（白名单只读接口）。
  // 一次性令牌只用在第一条请求上——兑票后换 30 分钟 HttpOnly 巡查 cookie，之后各页签凭 cookie 读；
  // 每一次读取都会写入该站点的操作日志（站长可见），所以这里给的是全量字段而不是打码名单。
  const INSPECT_TABS = [
    ['overview', '站点概览'], ['members', '成员全量资料'], ['pending', '待审核注册'],
    ['blacklist', '黑名单'], ['password-resets', '找回密码申请'], ['logs', '站长操作日志'],
    ['images', '图片台账'], ['player-stats', '战绩台账'], ['battles', '约战台账'],
  ];

  function inspectCell(v) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? '是' : '否';
    if (typeof v === 'object') {
      const s = Object.keys(v).map(function (k) { return k + ':' + v[k]; }).join(' ');
      return s.length > 90 ? s.slice(0, 90) + '…' : s;
    }
    const s = String(v);
    return s.length > 64 ? s.slice(0, 64) + '…' : s;
  }

  function PlatformInspectDialog(props) {
    const t = props.tenant;
    const base = '/' + t.slug + '/api/inspect/';
    const [view, setView] = useState('overview');
    const [data, setData] = useState(null);
    const [err, setErr] = useState('');
    const [busy, setBusy] = useState(true);
    const [page, setPage] = useState(1);
    const tokRef = useRef(props.token);   // 一次性：兑票后立即置空，绝不重复带

    const load = useCallback(async function (name, pg) {
      setBusy(true); setErr(''); setData(null);
      let url = base + name;
      if (pg > 1) url += '?page=' + pg;
      if (tokRef.current) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + '_sv=' + encodeURIComponent(tokRef.current);
        tokRef.current = '';
      }
      try {
        const json = await api(url);
        if (json.code !== 0) { setErr((json.msg || '读取失败') + '（巡查会话 30 分钟有效、可被平台随时撤销；需要时重新点「巡查台账」签发入口）'); return; }
        setData(json.data);
      } catch (e) {
        setErr('读取失败：巡查会话可能已过期（30 分钟）或已被撤销，请重新签发入口');
      } finally {
        setBusy(false);
      }
    }, [base]);

    useEffect(function () { load(view, page); }, [view, page, load]);

    const paged = (view === 'members' || view === 'player-stats') && data && data.total > data.size;
    const cols = (data && data.list && data.list.length) ? Object.keys(data.list[0]) : [];

    return (
      <Dialog open onClose={props.onClose} fullWidth maxWidth="lg">
        <DialogTitle>
          巡查台账 · {t.name}
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
            只读通道 /{t.slug}/api/inspect/* · 30 分钟内有效 · 每次读取都写入该站点操作日志
            {typeof props.quota === 'number' ? '（本小时剩余签发额度 ' + props.quota + '）' : ''}
          </Typography>
        </DialogTitle>
        <DialogContent sx={{ minHeight: 320 }}>
          <Alert severity="warning" sx={{ mb: 1.5 }}>
            你在巡查期间读取到的每一份成员资料（完整 QQ / 邮箱 / 注册 IP）都会逐条留痕，站长能在后台日志里看到
          </Alert>
          <Tabs value={view} onChange={(e, v) => { setPage(1); setView(v); }} variant="scrollable"
            scrollButtons="auto" sx={{ minHeight: 38, borderBottom: 1, borderColor: 'divider', mb: 1.5 }}>
            {INSPECT_TABS.map(function (it) {
              return <Tab key={it[0]} value={it[0]} label={it[1]} sx={{ minHeight: 38, py: 0.5 }} />;
            })}
          </Tabs>

          {busy ? <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>
            : err ? <Alert severity="error">{err}</Alert>
            : view === 'overview' ? (
              <Box>
                {data && data.notice && <Alert severity="info" sx={{ mb: 1.5 }}>{data.notice}</Alert>}
                <TableContainer>
                  <Table size="small">
                    <TableBody>
                      {data && Object.keys(data).filter(k => k !== 'notice').map(function (k) {
                        return (
                          <TableRow key={k}>
                            <TableCell sx={{ width: 240 }}>{k}</TableCell>
                            <TableCell>{inspectCell(data[k])}</TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </TableContainer>
              </Box>
            ) : (
              <Box>
                {data && (data.list || []).length === 0
                  ? <Alert severity="info">该台账暂无记录</Alert>
                  : (
                    <TableContainer sx={{ maxHeight: 420 }}>
                      <Table size="small" stickyHeader>
                        <TableHead>
                          <TableRow>{cols.map(function (c) { return <TableCell key={c}>{c}</TableCell>; })}</TableRow>
                        </TableHead>
                        <TableBody>
                          {(data ? data.list : []).map(function (row, i) {
                            return (
                              <TableRow key={i}>
                                {cols.map(function (c) {
                                  return <TableCell key={c} sx={{ whiteSpace: 'normal', wordBreak: 'break-all' }}>{inspectCell(row[c])}</TableCell>;
                                })}
                              </TableRow>
                            );
                          })}
                        </TableBody>
                      </Table>
                    </TableContainer>
                  )}
                {data && (data.total !== undefined || data.count !== undefined) && (
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                    {paged ? ('共 ' + data.total + ' 条') : ('本次返回 ' + (data.total !== undefined ? data.total : data.count) + ' 条')}
                  </Typography>
                )}
              </Box>
            )}
        </DialogContent>
        <DialogActions>
          {paged && (
            <Stack direction="row" spacing={1} sx={{ mr: 'auto' }}>
              <Button size="small" disabled={page <= 1 || busy} onClick={() => setPage(page - 1)}>上一页</Button>
              <Button size="small" disabled={busy} onClick={() => setPage(page + 1)}>下一页</Button>
            </Stack>
          )}
          <Button onClick={() => load(view, page)} disabled={busy}>重新读取</Button>
          <Button onClick={() => window.open('/' + t.slug + '/app', '_blank')}>前台浏览</Button>
          <Button variant="contained" onClick={props.onClose}>关闭</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // 巡查记录：/api/inspect/* 的逐次读取留痕 + 签发/撤销动作，跨站点汇总（数据源就是各站点的操作日志）
  function PlatformStealthAudits() {
    const [rows, setRows] = useState(null);
    const [tid, setTid] = useState(0);
    const [tenants, setTenants] = useState([]);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/stealth-audits?limit=200' + (tid ? '&tenant_id=' + tid : ''));
        setRows(json.data.list || []);
      } catch (e) { setRows([]); }
    }, [tid]);
    useEffect(function () { load(); }, [load]);
    useEffect(function () {
      (async function () {
        try { const json = await api('/api/platform/admin/tenants'); setTenants(json.data.list || []); }
        catch (e) { /* 非超管拿不到列表，筛选框留空即可 */ }
      })();
    }, []);

    return (
      <Box>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5 }}>
          <Typography variant="body2" color="text.secondary">隐身巡查每一次读取都会写一行日志，这里是全平台汇总</Typography>
          <Box sx={{ minWidth: 180 }}>
            <Select size="small" value={tid} onChange={e => setTid(Number(e.target.value))}>
              <MenuItem value={0}>全部站点</MenuItem>
              {tenants.map(function (x) { return <MenuItem key={x.id} value={x.id}>{x.name}</MenuItem>; })}
            </Select>
          </Box>
          <Button size="small" variant="outlined" onClick={load}>刷新</Button>
        </Stack>
        {rows === null ? <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>
          : rows.length === 0 ? <Alert severity="info">暂无巡查记录（平台还没人用过巡查通道）</Alert>
          : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>时间</TableCell><TableCell>站点</TableCell><TableCell>巡查者</TableCell><TableCell>动作</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rows.map(function (r) {
                    return (
                      <TableRow key={r.id}>
                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{fmtTime(r.created_at)}</TableCell>
                        <TableCell>{r.tenant_name}</TableCell>
                        <TableCell>{r.admin_name}</TableCell>
                        <TableCell sx={{ whiteSpace: 'normal', wordBreak: 'break-all' }}>{r.detail}</TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
      </Box>
    );
  }

  // v5.17.0：登录日志（平台超管自己的账号 + 各战队的管理员登录）
  // 战队侧只记 role=admin 的登录（队长与副管理员），成员登录不入表；
  // flagged=1 表示该次成功登录的归属地与上一条成功记录不同——这是盗号/令牌被拷走最常见的信号
  function PlatformLoginLogs() {
    const [mine, setMine] = useState(null);
    const [rows, setRows] = useState(null);
    const [tenants, setTenants] = useState([]);
    const [tid, setTid] = useState(0);
    const [flt, setFlt] = useState('');
    const [marking, setMarking] = useState(false);

    const loadMine = useCallback(async function () {
      try { const json = await api('/api/platform/my/login-logs'); setMine(json.data); }
      catch (e) { setMine({ list: [], unread: 0 }); }
    }, []);
    const loadTeam = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/login-logs?limit=200'
          + (tid ? '&tenant_id=' + tid : '') + (flt ? '&filter=' + flt : ''));
        setRows(json.data.list || []);
      } catch (e) { setRows([]); }
    }, [tid, flt]);

    useEffect(function () { loadMine(); }, [loadMine]);
    useEffect(function () { loadTeam(); }, [loadTeam]);
    useEffect(function () {
      (async function () {
        try { const json = await api('/api/platform/admin/tenants'); setTenants(json.data.list || []); }
        catch (e) { /* 忽略 */ }
      })();
    }, []);

    const markRead = async function () {
      setMarking(true);
      try {
        const json = await api('/api/platform/my/login-alerts/read', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); setMine(function (p) { return p ? Object.assign({}, p, { unread: 0 }) : p; }); }
        else toast(json.msg, 'error');
      } catch (e) { toast('操作失败', 'error'); } finally { setMarking(false); }
    };

    const okChip = function (r) {
      const isOk = r === '成功' || r === 'success';
      return <Chip size="small" variant="outlined" color={isOk ? 'success' : 'error'} label={isOk ? '成功' : '密码错误'} />;
    };

    return (
      <Box>
        <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
            <Typography variant="subtitle2">我这个平台账号的登录</Typography>
            {mine && mine.unread > 0 && <Chip size="small" color="error" label={'新归属地未读 ' + mine.unread} />}
            <Box sx={{ flexGrow: 1 }} />
            {mine && mine.unread > 0 && (
              <Button size="small" variant="outlined" disabled={marking} onClick={markRead}>我已核对</Button>
            )}
          </Stack>
          {mine && mine.unread > 0 && (
            <Alert severity="warning" sx={{ mb: 1 }}>
              最近有来自新归属地的成功登录。若不是你本人，请立即修改平台超管密码——改密后旧会话会被强制下线
            </Alert>
          )}
          {!mine ? <CircularProgress size={22} /> : (mine.list || []).length === 0
            ? <Typography variant="body2" color="text.secondary">暂无登录记录</Typography>
            : (
              <TableContainer sx={{ maxHeight: 220 }}>
                <Table size="small" stickyHeader>
                  <TableHead>
                    <TableRow>
                      <TableCell>时间</TableCell><TableCell>IP</TableCell><TableCell>归属地</TableCell>
                      <TableCell>方式</TableCell><TableCell>结果</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {mine.list.map(function (l) {
                      return (
                        <TableRow key={l.id} sx={{ bgcolor: l.flagged ? 'rgba(255,59,48,0.06)' : 'inherit' }}>
                          <TableCell sx={{ whiteSpace: 'nowrap' }}>{fmtTime(l.created_at)}</TableCell>
                          <TableCell>{l.ip}</TableCell>
                          <TableCell>{l.region || '—'}{l.flagged ? ' · 新归属地' : ''}</TableCell>
                          <TableCell>{l.via === 'cookie' ? '免登录恢复' : (l.via === 'passport' ? '通行证登录' : '密码登录')}</TableCell>
                          <TableCell><Chip size="small" variant="outlined" color={l.result === '成功' ? 'success' : 'error'} label={l.result} /></TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
        </Paper>

        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5 }} flexWrap="wrap" useFlexGap>
          <Typography variant="subtitle2">各战队的管理员登录</Typography>
          <Box sx={{ minWidth: 180 }}>
            <Select size="small" value={tid} onChange={e => setTid(Number(e.target.value))}>
              <MenuItem value={0}>全部站点</MenuItem>
              {tenants.map(function (x) { return <MenuItem key={x.id} value={x.id}>{x.name}</MenuItem>; })}
            </Select>
          </Box>
          {[['', '全部'], ['flagged', '仅新归属地'], ['fail', '仅失败']].map(function (f) {
            return (
              <Button key={f[0]} size="small" variant={flt === f[0] ? 'contained' : 'outlined'}
                onClick={() => setFlt(f[0])}>{f[1]}</Button>
            );
          })}
          <Button size="small" onClick={loadTeam}>刷新</Button>
        </Stack>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
          只记各战队的队长与副管理员账号；成员登录不入这张表，防撞库仍由 IP 封禁与账号锁定负责
        </Typography>
        {rows === null ? <CircularProgress size={22} /> : rows.length === 0
          ? <Alert severity="info">暂无登录记录</Alert>
          : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>时间</TableCell><TableCell>站点</TableCell><TableCell>账号</TableCell>
                    <TableCell>IP</TableCell><TableCell>归属地</TableCell><TableCell>方式</TableCell><TableCell>结果</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rows.map(function (r) {
                    return (
                      <TableRow key={r.id} sx={{ bgcolor: r.flagged ? 'rgba(255,59,48,0.06)' : 'inherit' }}>
                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{fmtTime(r.created_at)}</TableCell>
                        <TableCell>{r.tenant_name}</TableCell>
                        <TableCell>{r.qq}</TableCell>
                        <TableCell>{r.ip}</TableCell>
                        <TableCell>{r.region || '—'}{r.flagged ? <Chip size="small" color="warning" sx={{ ml: 0.5 }} label="新" /> : ''}</TableCell>
                        <TableCell>{r.via === 'cookie' ? '免登录恢复' : (r.via === 'passport' ? '通行证登录' : '密码登录')}</TableCell>
                        <TableCell>{okChip(r.result)}</TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
      </Box>
    );
  }

  // v5.17.0：日志清理——平台超管按截止日期清各战队的日志
  // 原先平台只有「清空机器人日志 / 清空 API 日志」两根整表按钮，碰不到各战队的操作日志与登录日志；
  // 这里做成：统计 ⇒ 预览条数（apply=0，不写库）⇒ 确认后才真删（apply=1）
  // 安全默认：admin_logs 里 action=隐身巡查 的巡查审计不会被随手删掉，必须显式勾选
  // ==================== v5.31.0 平台邮件群发（只平台超管能用） ====================
  // 口径：发信用平台自己配的那一套账号（settings 里 p_mail_*，租户号 0），与各战队的 smtp_* 完全分开；
  // 正式发送前必须先给自己发一封预览，而且预览的就是这一份标题+正文 —— 改一个字门禁作废；
  // 队列一次 HTTP 只推进一步（一批几封、每封隔一会儿），页面关了只是暂停，回来接着走。
  const MAIL_GROUPS = [['owner', '各战队的站长与管理员'], ['member', '全部已上线成员']];
  const MAIL_SECURE = [
    ['', '按端口猜（465 走 SSL，其它端口走 STARTTLS）'],
    ['ssl', 'SSL（一般是 465）'],
    ['tls', 'STARTTLS（一般是 587 或 25）'],
    ['none', '不加密（本机中继、自测时才用）'],
  ];
  const MAIL_ITEM_STATUS = { pending: '待发', sending: '在飞', sent: '已发出', failed: '被退回', skipped: '没地址' };

  function MailStatusChip({ s }) {
    const tone = s === 'sent' ? 'success' : (s === 'failed' ? 'error' : (s === 'skipped' ? 'warning' : 'default'));
    return <Chip size="small" label={MAIL_ITEM_STATUS[s] || s} color={tone} variant={tone === 'default' ? 'outlined' : 'filled'}
      sx={{ height: 20, fontSize: 11 }} />;
  }

  const MAIL_BLAST_STATUS = { queued: '待发送', running: '发送中', stopped: '已暂停', done: '已发完' };

  function BlastStatusChip({ s }) {
    const tone = s === 'done' ? 'success' : (s === 'stopped' ? 'warning' : (s === 'running' ? 'primary' : 'default'));
    return <Chip size="small" label={MAIL_BLAST_STATUS[s] || s} color={tone} variant={tone === 'default' ? 'outlined' : 'filled'}
      sx={{ height: 20, fontSize: 11 }} />;
  }

  /** 配置卡：平台那一套发信账号 + 限速的三个数 */
  function MailConfig({ cfg, onSaved }) {
    const [f, setF] = useState(Object.assign({}, cfg));
    const [busy, setBusy] = useState(false);
    useEffect(function () { setF(Object.assign({}, cfg)); }, [cfg]);
    const set = function (k, v) { setF(Object.assign({}, f, (function () { const o = {}; o[k] = v; return o; })())); };

    const save = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/config', { method: 'POST', body: {
          enabled: f.enabled ? 1 : 0, host: f.host, port: f.port, secure: f.secure || '', user: f.user,
          pass: f.pass, from: f.from, preview: f.preview, batch: f.batch, gap: f.gap, max: f.max,
        } });
        if (j.code === 0) { toast('已保存平台发信账号', 'success'); setF(Object.assign({}, j.data.config, { pass: '' })); onSaved(); }
        else { toast(j.msg || '保存失败', 'error'); }
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    const num = { width: { xs: '100%', sm: 130 } };
    return (
      <Paper elevation={1} sx={{ p: { xs: 1.5, md: 2 }, mb: 1.5, borderRadius: '16px' }}>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1, flexWrap: 'wrap', gap: 1 }}>
          <Typography sx={{ fontSize: 14, fontWeight: 700, color: TEXT_PRIMARY }}>平台发信账号</Typography>
          <Chip size="small" label={cfg.ready ? '已配好' : '还没配好'} color={cfg.ready ? 'success' : 'warning'}
            variant="outlined" sx={{ height: 20, fontSize: 11 }} />
          <Box sx={{ flex: '1 1 auto' }} />
          <FormControlLabel control={<Switch checked={Number(f.enabled) === 1} onChange={e => set('enabled', e.target.checked ? 1 : 0)} />}
            label={<Typography sx={{ fontSize: 12 }}>开放群发</Typography>} />
        </Stack>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mb: 1 }}>
          <TextField size="small" label="SMTP 服务器" value={f.host} onChange={e => set('host', e.target.value)}
            sx={{ flex: '1 1 220px', minWidth: 0 }} placeholder="如 smtp.qq.com" />
          <TextField size="small" label="端口" type="number" value={f.port} onChange={e => set('port', e.target.value)} sx={num} />
          <Box sx={{ flex: '1 1 220px', minWidth: 0 }}>
            <Typography sx={{ fontSize: 11, color: TEXT_SECONDARY, mb: 0.5 }}>加密方式</Typography>
            <Select size="small" value={f.secure || ''} onChange={e => set('secure', e.target.value)} sx={{ width: '100%' }}>
              {MAIL_SECURE.map(function (o) { return <MenuItem key={o[0]} value={o[0]}>{o[1]}</MenuItem>; })}
            </Select>
          </Box>
        </Stack>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mb: 1 }}>
          <TextField size="small" label="邮箱账号（完整地址）" value={f.user} onChange={e => set('user', e.target.value)}
            sx={{ flex: '1 1 200px', minWidth: 0 }} placeholder="123456@qq.com" />
          <TextField size="small" label="授权码" type="password" value={f.pass || ''} onChange={e => set('pass', e.target.value)}
            sx={{ flex: '1 1 200px', minWidth: 0 }} autoComplete="new-password"
            placeholder={cfg.has_pass ? '不填＝保持原样（填 CLEAR 是清空）' : '邮箱授权码，不是登录密码'}
            helperText="QQ / 163 要先在邮箱设置里开启 SMTP 服务并拿授权码" />
        </Stack>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mb: 1 }}>
          <TextField size="small" label="发件人显示名" value={f.from} onChange={e => set('from', e.target.value)}
            sx={{ flex: '1 1 200px', minWidth: 0 }} placeholder="只填名称就行，如 平台运营"
            helperText="信封地址恒等于上面那个邮箱账号，这里只影响收件人看到的名字" />
          <TextField size="small" label="预览收件地址" value={f.preview} onChange={e => set('preview', e.target.value)}
            sx={{ flex: '1 1 200px', minWidth: 0 }} placeholder="留空＝发到自己那个发信邮箱"
            helperText="「先发一封给我看」就发到这个地址" />
        </Stack>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mb: 1 }}>
          <TextField size="small" label="每批封数" type="number" value={f.batch} onChange={e => set('batch', e.target.value)} sx={num}
            helperText="一次点击发几封（1-20）" />
          <TextField size="small" label="每封间隔（毫秒）" type="number" value={f.gap} onChange={e => set('gap', e.target.value)}
            sx={{ width: { xs: '100%', sm: 150 } }} helperText="逐封限速，别把邮箱卡进灰名单" />
          <TextField size="small" label="单批最多人数" type="number" value={f.max} onChange={e => set('max', e.target.value)} sx={num}
            helperText="超过就要分开发" />
        </Stack>
        <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
          <Button size="small" variant="contained" disabled={busy} onClick={save}>保存</Button>
          <Button size="small" onClick={onSaved} disabled={busy}>重新读取</Button>
          {!cfg.ready && <Typography sx={{ fontSize: 12, color: '#b25000' }}>服务器 / 邮箱账号 / 授权码三项齐了才能发信</Typography>}
        </Stack>
      </Paper>
    );
  }

  /** 写作卡：标题 + 正文 + 收件分组 + 实时人数 + 预览 + 正式发送 */
  function MailCompose({ cfg, draft, setDraft, onCreated }) {
    const [stat, setStat] = useState(null);
    const [busy, setBusy] = useState(false);
    const [okKey, setOkKey] = useState('');
    const [ask, setAsk] = useState(false);
    const [fmt, setFmt] = useState('');
    // v5.36.31：{team} 这类变量的两个入口——点一下插到光标处，以及发之前先看渲染成什么样子
    const subjRef = useRef(null);
    const bodyRef = useRef(null);
    const lastRef = useRef(null);
    const [vars, setVars] = useState([]);
    const [samples, setSamples] = useState(null);
    const [showVars, setShowVars] = useState(false);
    const key = draft.subject + '\n' + draft.body;
    const ready = okKey === key;

    useEffect(function () {
      if (cfg && cfg.vars) { setVars(Object.keys(cfg.vars).map(function (k) { return [k, cfg.vars[k]]; })); }
    }, [cfg]);

    const hasVar = function () {
      return vars.some(function (v) { return (draft.subject + draft.body).indexOf(v[0]) >= 0; });
    };

    const insertVar = function (token) {
      const onSubj = lastRef.current && lastRef.current === subjRef.current;
      const field = onSubj ? 'subject' : 'body';
      const el = onSubj ? subjRef.current : bodyRef.current;
      const cur = onSubj ? draft.subject : draft.body;
      const at = el && typeof el.selectionStart === 'number' ? el.selectionStart : cur.length;
      const to = el && typeof el.selectionEnd === 'number' ? el.selectionEnd : at;
      const next = cur.slice(0, at) + token + cur.slice(to);
      setDraft(Object.assign({}, draft, (function () { var o = {}; o[field] = next; return o; })()));
      // 受控输入框一改值光标会跳到末尾，等这一帧写完再把它放回插入位置后面
      setTimeout(function () {
        try { if (el) { el.focus(); el.setSelectionRange(at + token.length, at + token.length); } } catch (e) { /* 拿不到光标就算了 */ }
      }, 0);
    };

    const lookRender = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/render', { method: 'POST', body: {
          subject: draft.subject, body: draft.body, groups: draft.groups.join(','),
          include_banned: draft.banned ? '1' : '0',
        } });
        if (j.code === 0) { setSamples(j.data.list || []); setShowVars(true); }
        else { toast(j.msg || '拼不出示例', 'error'); }
      } catch (e) { toast('拼不出示例', 'error'); }
      setBusy(false);
    };

    const toggle = function (g) {
      const has = draft.groups.indexOf(g) >= 0;
      const next = has ? draft.groups.filter(function (x) { return x !== g; }) : draft.groups.concat([g]);
      setDraft(Object.assign({}, draft, { groups: next }));
    };

    // 人数只在「分组变了」时才要重算，标题正文改字不影响名单
    useEffect(function () {
      if (!draft.groups.length) { setStat(null); return; }
      let dead = false;
      const t = setTimeout(function () {
        api('/api/platform/admin/mail/recipients', { method: 'POST', body: {
          groups: draft.groups.join(','), include_banned: draft.banned ? '1' : '0',
        } })
          .then(function (j) { if (!dead) { setStat(j.code === 0 ? j.data : null); } })
          .catch(function () { if (!dead) { setStat(null); } });
      }, 350);
      return function () { dead = true; clearTimeout(t); };
    }, [draft.groups.join(','), draft.banned]);   // eslint-disable-line

    const preview = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/preview', { method: 'POST', body: {
          subject: draft.subject, body: draft.body, groups: draft.groups.join(','),
          include_banned: draft.banned ? '1' : '0',
          host: cfg.host, port: cfg.port, secure: cfg.secure || '', user: cfg.user, pass: '', from: cfg.from,
        } });
        if (j.code === 0) {
          setOkKey(key);
          setFmt((j.data && j.data.format) || '');
          toast(j.msg || '预览已发出', 'success');
        } else { setOkKey(''); setFmt(''); toast(j.msg || '预览没发出去', 'error'); }
      } catch (e) { toast('预览失败', 'error'); } finally { setBusy(false); }
    };

    const send = async function () {
      setAsk(false); setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/blast', { method: 'POST', body: {
          subject: draft.subject, body: draft.body, groups: draft.groups.join(','),
          include_banned: draft.banned ? '1' : '0',
        } });
        if (j.code === 0) {
          setOkKey('');
          const id = Number(j.data.blast_id || 0);
          toast('批次 #' + id + ' 已建好，点「开始发送」就按限速一封封走', 'success');
          setFmt('');
          onCreated(id);
        } else { toast(j.msg || '建批失败', 'error'); }
      } catch (e) { toast('建批失败', 'error'); } finally { setBusy(false); }
    };

    const over = stat && Number(stat.over) === 1;
    return (
      <Paper elevation={1} sx={{ p: { xs: 1.5, md: 2 }, mb: 1.5, borderRadius: '16px' }}>
        <Typography sx={{ fontSize: 14, fontWeight: 700, color: TEXT_PRIMARY, mb: 1 }}>写这封通知</Typography>
        <TextField size="small" fullWidth label="邮件标题" value={draft.subject} sx={{ mb: 1 }}
          inputRef={subjRef} onFocus={function () { lastRef.current = subjRef.current; }}
          onChange={e => setDraft(Object.assign({}, draft, { subject: e.target.value }))}
          inputProps={{ maxLength: cfg.subject_max }}
          helperText={(draft.subject.length + '') + ' / ' + cfg.subject_max + '　｜　可以写 {team} {name} 这些变量'} />
        <TextField size="small" fullWidth multiline minRows={9} maxRows={22} label="正文（你自己写）" value={draft.body}
          inputRef={bodyRef} onFocus={function () { lastRef.current = bodyRef.current; }}
          onChange={e => setDraft(Object.assign({}, draft, { body: e.target.value }))}
          inputProps={{ maxLength: cfg.body_max }}
          helperText={draft.body.length + ' / ' + cfg.body_max + '　｜　标签写一半会被当纯文本发（尖括号会露出来），要排版就整篇写成一封 HTML'} />
        <Box sx={{ p: 1, mb: 1, borderRadius: '12px', bgcolor: BG_GRAY, border: '1px solid ' + CARD_BORDER }}>
          <Stack direction="row" alignItems="center" sx={{ gap: 1, flexWrap: 'wrap', useFlexGap: true }}>
            <Typography sx={{ fontSize: 12.5, fontWeight: 600, color: TEXT_PRIMARY }}>
              每个人收到自己那一份（点一下插到光标那里）
            </Typography>
            <Box sx={{ flex: '1 1 auto' }} />
            <Button size="small" variant="outlined" disabled={busy || !draft.subject || !draft.body.trim()}
              onClick={lookRender}>看渲染效果</Button>
          </Stack>
          <Stack direction="row" spacing={0.5} sx={{ mt: 0.5 }} flexWrap="wrap" useFlexGap>
            {vars.map(function (v) {
              return <Tooltip key={v[0]} title={v[1]}>
                <Chip size="small" label={v[0]} onClick={function () { insertVar(v[0]); }}
                  sx={{ height: 20, fontSize: 11.5, fontFamily: 'ui-monospace, Menlo, monospace',
                    bgcolor: draft.body.indexOf(v[0]) >= 0 || draft.subject.indexOf(v[0]) >= 0 ? 'primary.main' : '#fff',
                    color: draft.body.indexOf(v[0]) >= 0 || draft.subject.indexOf(v[0]) >= 0 ? '#fff' : TEXT_PRIMARY,
                    border: '1px solid ' + CARD_BORDER, cursor: 'pointer' }} />
              </Tooltip>;
            })}
          </Stack>
          <Typography sx={{ fontSize: 11.5, lineHeight: '17px', color: TEXT_SECONDARY, mt: 0.5 }}>
            标题与正文都替换，发的那一刻按收件人自己那一行现拼；库里存的还是你写的这一份模板。
            蓝掉的表示这条已经用上了。{hasVar() ? '' : ' 现在这条还没用变量，发出去人人都一样。'}
          </Typography>
        </Box>
        <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 1, mb: 0.5 }}>发给谁（可多选）</Typography>
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mb: 1 }}>
          {MAIL_GROUPS.map(function (g) {
            return <FormControlLabel key={g[0]} sx={{ mr: 0 }}
              control={<Checkbox size="small" checked={draft.groups.indexOf(g[0]) >= 0} onChange={() => toggle(g[0])} />}
              label={<Typography sx={{ fontSize: 13 }}>{g[1]}</Typography>} />;
          })}
        </Stack>
        {/* v5.36.32：被封禁的站点默认整队不发；销毁前那封告知信要显式勾这一颗 */}
        <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap sx={{ mb: 1 }}>
          <FormControlLabel sx={{ mr: 0 }}
            control={<Checkbox size="small" checked={draft.banned === 1}
              onChange={function (e) { setDraft(Object.assign({}, draft, { banned: e.target.checked ? 1 : 0 })); }} />}
            label={<Typography sx={{ fontSize: 13 }}>带上被封禁的站点（销毁前的告知）</Typography>} />
          {stat && Number(stat.banned_people) > 0 && (
            <Typography sx={{ fontSize: 12, color: draft.banned ? '#b25000' : TEXT_SECONDARY }}>
              现在被封的站点里有 {stat.banned_teams} 支、按上面勾选的分组能算到 {stat.banned_people} 人
              {draft.banned ? '，这一批已经带上他们' : '，不勾这一颗就一封都不发'}
            </Typography>
          )}
          {stat && Number(stat.banned_people) === 0 && (
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>现在没有被封禁的站点，这一颗勾不勾都一样</Typography>
          )}
        </Stack>
        {stat && (
          <Box sx={{ p: 1, mb: 1, borderRadius: '12px', bgcolor: '#fafafc', border: '1px solid ' + CARD_BORDER }}>
            <Typography sx={{ fontSize: 13, color: TEXT_PRIMARY }}>
              这一批 {stat.total} 人：站长与管理员 {stat.owner} + 成员 {stat.member}，覆盖 {stat.teams} 支队
              {draft.banned === 1 && Number(stat.banned_people) > 0
                ? '（含被封禁的 ' + stat.banned_teams + ' 支 ' + stat.banned_people + ' 人）' : ''}
            </Typography>
            <Typography sx={{ fontSize: 12, color: stat.no_mail > 0 ? '#b25000' : TEXT_SECONDARY, mt: 0.25 }}>
              其中 {stat.no_mail} 人没有可用邮箱 —— 那几封会记成「没地址」发不出去，但站内信照样补给他们
            </Typography>
            {(stat.sample || []).length > 0 && (
              <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap sx={{ mt: 0.5 }}>
                <Typography sx={{ fontSize: 11, color: TEXT_SECONDARY, mr: 0.5 }}>地址样例（已打码）</Typography>
                {stat.sample.map(function (s) { return <Chip key={s} size="small" label={s} sx={{ height: 18, fontSize: 11 }} />; })}
              </Stack>
            )}
            {over && <Alert severity="warning" sx={{ mt: 1, p: 0.75 }}>超过单批上限 {stat.max} 人：请调低上限或分开发。</Alert>}
          </Box>
        )}
        <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
          <Button size="small" variant="outlined" disabled={busy || !draft.groups.length} onClick={preview}>先发一封给我看</Button>
          <Button size="small" variant="contained" disabled={busy || !ready || !draft.groups.length || over}
            onClick={() => setAsk(true)}>正式发送</Button>
          <Typography sx={{ fontSize: 12, color: ready ? '#1b7a3d' : TEXT_SECONDARY }}>
            {ready ? '这一份已预览过，可以正式发送' : '没预览过的正文发不出去；改一个字要重新预览'}
          </Typography>
          {fmt && <Chip size="small" label={'预览那封按' + fmt + '发'} color={fmt === 'HTML' ? 'primary' : 'default'}
            variant="outlined" sx={{ height: 20, fontSize: 11 }} />}
        </Stack>
        <Dialog open={ask} onClose={() => setAsk(false)} maxWidth="sm" fullWidth>
          <DialogTitle>确认群发这一封？</DialogTitle>
          <DialogContent>
            <Typography sx={{ fontSize: 13, lineHeight: '22px' }}>
              发件：{cfg.user || '（还没配账号）'}<br />
              标题：{draft.subject}<br />
              收件：{stat ? stat.total : '—'} 人（站长与管理员 {stat ? stat.owner : '—'} + 成员 {stat ? stat.member : '—'}）<br />
              {draft.banned === 1 && stat && Number(stat.banned_people) > 0 ? (
                <React.Fragment>
                  <Box component="span" sx={{ color: '#b25000', fontWeight: 600 }}>
                    这一批带上了被封禁的 {stat.banned_teams} 支队（{stat.banned_people} 人）
                  </Box>
                  ——他们的站点打不开，站内信要等解封后才看得到，邮箱这一路是唯一能送达的。<br />
                </React.Fragment>
              ) : null}
              每一封同时补一条站内信；发出去收不回，只能再发一封更正通知。
            </Typography>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setAsk(false)}>再看看</Button>
            <Button variant="contained" onClick={send}>确认发送</Button>
          </DialogActions>
        </Dialog>
        {/* v5.36.31：把 {team} 这类变量换成真人真队名，发之前先看一眼 */}
        <Dialog open={showVars} onClose={function () { setShowVars(false); }} fullWidth maxWidth="sm">
          <DialogTitle>发出去就是这个样子</DialogTitle>
          <DialogContent dividers>
            <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mb: 1 }}>
              从勾的分组里各取一个人做样本（下面这两份只是给你看渲染效果，没有真的发出去）。
              昵称与队名里的尖括号会被去掉，别人自己填的东西不会在你的 HTML 里塞标签。
            </Typography>
            {samples === null && <Typography sx={{ fontSize: 13 }}>正在拼…</Typography>}
            {samples !== null && samples.length === 0 && (
              <Alert severity="warning">这两组里一个人都没有，拼不出示例。</Alert>
            )}
            {samples !== null && samples.map(function (s, i) {
              return (
                <Box key={i} sx={{ p: 1.25, mb: 1, borderRadius: '12px', border: '1px solid ' + CARD_BORDER }}>
                  <Stack direction="row" spacing={0.75} alignItems="center" sx={{ flexWrap: 'wrap', useFlexGap: true, mb: 0.5 }}>
                    <Chip size="small" label={s.who === 'owner' ? '站长与管理员' : '普通成员'} sx={{ height: 20, fontSize: 11 }} />
                    <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY }}>
                      {s.team} · {s.name}
                    </Typography>
                  </Stack>
                  <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: TEXT_PRIMARY }}>{s.subject}</Typography>
                  <Typography component="pre" sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_PRIMARY,
                    whiteSpace: 'pre-wrap', wordBreak: 'break-word', m: 0.5, fontFamily: 'inherit',
                    maxHeight: 240, overflow: 'auto' }}>{s.body}</Typography>
                </Box>
              );
            })}
          </DialogContent>
          <DialogActions>
            <Button onClick={function () { setShowVars(false); }}>关掉</Button>
          </DialogActions>
        </Dialog>
      </Paper>
    );
  }

  /** 队列面板：进度 + 逐封结果 + 开始/暂停/继续/重排 */
  function MailQueue({ bid, onRefresh }) {
    const [p, setP] = useState(null);
    const [run, setRun] = useState(false);
    const runRef = useRef(false);
    const epRef = useRef(0);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/mail/blast?blast_id=' + bid + '&size=1');
        if (j.code === 0) { setP(j.data.progress); return j.data.progress; }
      } catch (e) { /* 读不到进度就留着上一份，别把面板清空 */ }
      return null;
    }, [bid]);
    useEffect(function () { setP(null); setRun(false); load(); }, [load]);

    const oneStep = async function () {
      try {
        const j = await api('/api/platform/admin/mail/step', { method: 'POST', body: { blast_id: bid } });
        if (j.code !== 0) { toast(j.msg || '这一步没跑成', 'error'); return null; }
        setP(j.data);
        return j.data;
      } catch (e) { toast('发送请求没走通，队列停了', 'error'); return null; }
    };

    // 一句一句推：每点一次「开始」换一个新的序号，上一轮循环下一轮发现自己过期就退出，
    // 所以任何时刻最多只有一条驱动在飞（服务端本来就是先认领再发送，这里只是不让两个进度互相盖）。
    useEffect(function () {
      if (!run) { runRef.current = false; return; }
      const ep = ++epRef.current;
      runRef.current = true;
      (async function () {
        let halted = false;
        while (ep === epRef.current && runRef.current) {
          const d = await oneStep();
          if (!d) { halted = true; break; }
          if (Number(d.left || 0) === 0) { break; }
          // 一封都没认领到＝这一批被暂停了/账号没配齐，再问下去只会空转
          if (Number(d.tried || 0) === 0) { halted = true; break; }
          await new Promise(function (r) { setTimeout(r, 200); });
        }
        if (ep !== epRef.current) { return; }
        if (halted) { setRun(false); return; }
        const p2 = await load();
        if (!p2 || Number(p2.left || 0) === 0) { setRun(false); }
      })();
      return function () { runRef.current = false; };
    }, [run]);   // eslint-disable-line

    const act = async function (path, askText) {
      if (askText && !window.confirm(askText)) { return; }
      try {
        const j = await api('/api/platform/admin/mail/' + path, { method: 'POST', body: { blast_id: bid } });
        if (j.code === 0) { toast(j.msg || '好了', 'success'); if (j.data && j.data.progress) { setP(j.data.progress); } else { load(); } onRefresh(); }
        else { toast(j.msg || '没改成', 'error'); }
      } catch (e) { toast('请求失败', 'error'); }
    };

    if (!p) { return <Paper elevation={1} sx={{ p: 2, mb: 1.5, borderRadius: '16px' }}><LinearProgress /></Paper>; }
    const doneN = Number(p.sent || 0) + Number(p.failed || 0) + Number(p.skipped || 0);
    const pct = p.target > 0 ? Math.round(doneN * 100 / p.target) : 0;
    return (
      <Paper elevation={1} sx={{ p: { xs: 1.5, md: 2 }, mb: 1.5, borderRadius: '16px' }}>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1, flexWrap: 'wrap', gap: 1 }}>
          <Typography sx={{ fontSize: 14, fontWeight: 700, color: TEXT_PRIMARY }}>批次 #{bid} · {p.subject}</Typography>
          <BlastStatusChip s={p.status} />
        </Stack>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1 }}>
          <Box sx={{ flex: '1 1 auto', minWidth: 0 }}><LinearProgress variant="determinate" value={pct} sx={{ height: 8, borderRadius: 4 }} /></Box>
          <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, whiteSpace: 'nowrap' }}>{doneN} / {p.target}</Typography>
        </Box>
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mb: 1 }}>
          <Chip size="small" label={'已发出 ' + p.sent} color="success" variant="outlined" sx={{ fontSize: 11, height: 20 }} />
          <Chip size="small" label={'被退回 ' + p.failed} color="error" variant="outlined" sx={{ fontSize: 11, height: 20 }} />
          <Chip size="small" label={'没地址 ' + p.skipped} color="warning" variant="outlined" sx={{ fontSize: 11, height: 20 }} />
          <Chip size="small" label={'待发 ' + p.pending} variant="outlined" sx={{ fontSize: 11, height: 20 }} />
          {Number(p.sending) > 0 && <Chip size="small" label={'卡在「已认领」 ' + p.sending} color="warning" sx={{ fontSize: 11, height: 20 }} />}
          <Chip size="small" label={'站内信 ' + p.notices} variant="outlined" sx={{ fontSize: 11, height: 20 }} />
        </Stack>
        <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mb: 1 }}>{p.msg || ''}</Typography>
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
          {Number(p.left) > 0 && !run && p.status !== 'stopped' && (
            <Button size="small" variant="contained" onClick={() => setRun(true)}>开始发送</Button>)}
          {Number(p.left) > 0 && p.status === 'stopped' && (
            <Button size="small" variant="outlined" onClick={function () { act('resume'); }}>先解除暂停</Button>)}
          {Number(p.left) > 0 && run && (
            <Button size="small" color="warning" variant="contained" onClick={function () { setRun(false); act('stop'); }}>暂停</Button>)}
          {Number(p.sending) > 0 && !run && (
            <Button size="small" onClick={() => act('requeue', '把卡在「已认领」的 ' + p.sending + ' 封重新排回待发？\n\n它们可能已经发出去了，重排会再发一次。')}>重排在飞的</Button>)}
          <Button size="small" onClick={load}>刷新进度</Button>
        </Stack>
      </Paper>
    );
  }

  /** 记录 + 逐封明细（整站唯一能看到完整收件地址的一页，服务端每次读都记一行日志） */
  function MailHistory({ onPick, picked }) {
    const [rows, setRows] = useState(null);
    const [det, setDet] = useState(null);
    const [flt, setFlt] = useState('all'); // 「全部」不能拿空串当值：Select 对空串不出字，看着像个空框
    const [page, setPage] = useState(1);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/mail/blasts');
        if (j.code === 0) { setRows(j.data.list || []); } else { setRows([]); }
      } catch (e) { setRows([]); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const loadDet = useCallback(async function (id, st, pg) {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/blast?blast_id=' + id + '&status=' + (st === 'all' ? '' : (st || '')) + '&page=' + (pg || 1) + '&size=20');
        if (j.code === 0) { setDet(j.data); } else { toast(j.msg || '读不到明细', 'error'); setDet(null); }
      } catch (e) { setDet(null); } finally { setBusy(false); }
    }, []);
    useEffect(function () { if (picked > 0) { loadDet(picked, flt, page); } }, [picked, flt, page, loadDet]);

    const cell = { fontSize: 12, whiteSpace: 'nowrap', px: 0.75, py: 0.5 };
    const head = { fontSize: 11, whiteSpace: 'normal', lineHeight: '14px', minWidth: 30, px: 0.75, py: 0.5, fontWeight: 700 };
    return (
      <Paper elevation={1} sx={{ p: { xs: 1.5, md: 2 }, mb: 1.5, borderRadius: '16px' }}>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
          <Typography sx={{ fontSize: 14, fontWeight: 700, color: TEXT_PRIMARY }}>发送记录</Typography>
          <Box sx={{ flex: '1 1 auto' }} />
          <Button size="small" onClick={function () { load(); if (picked) { loadDet(picked, flt, page); } }}>刷新</Button>
        </Stack>
        {rows === null && <LinearProgress sx={{ mb: 1 }} />}
        {rows && rows.length === 0 && (
          <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, py: 0.5 }}>还没有发过：上面写好正文、预览过，再点正式发送。</Typography>
        )}
        {rows && rows.length > 0 && (
          <TableContainer sx={{ mb: 1 }}>
            <Table size="small">
              <TableHead><TableRow>
                {['#', '标题', '发给', '计划', '已发出', '退回', '没地址', '站内信', '状态', '谁点的', '时间', ''].map(function (h) {
                  return <TableCell key={h} sx={head}>{h}</TableCell>;
                })}
              </TableRow></TableHead>
              <TableBody>
                {rows.map(function (r) {
                  return (
                    <TableRow key={r.id} hover selected={Number(picked) === Number(r.id)}>
                      <TableCell sx={cell}>{r.id}</TableCell>
                      <TableCell sx={{ fontSize: 12, maxWidth: 170, overflow: 'hidden', textOverflow: 'ellipsis', px: 0.75, py: 0.5 }}>{r.subject}</TableCell>
                      <TableCell sx={cell}>{r.groupNames}</TableCell>
                      <TableCell sx={cell}>{r.target}</TableCell>
                      <TableCell sx={cell}>{r.n_sent}</TableCell>
                      <TableCell sx={cell}>{r.n_failed}</TableCell>
                      <TableCell sx={cell}>{r.n_skipped}</TableCell>
                      <TableCell sx={cell}>{r.n_notice}</TableCell>
                      <TableCell sx={cell}><BlastStatusChip s={r.status} /></TableCell>
                      <TableCell sx={cell}>{r.actor}</TableCell>
                      <TableCell sx={cell}>{fmtTime(r.created_at)}</TableCell>
                      <TableCell sx={cell}>
                        <Button size="small" onClick={function () { onPick(Number(r.id)); }}>{Number(picked) === Number(r.id) ? '收起明细' : '看明细'}</Button>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        {picked > 0 && det && (
          <Box sx={{ pt: 1, borderTop: '1px solid ' + CARD_BORDER }}>
            <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }} flexWrap="wrap" useFlexGap>
              <Typography sx={{ fontSize: 13, fontWeight: 700, color: TEXT_PRIMARY }}>批次 #{picked} 明细</Typography>
              <Box sx={{ minWidth: 130 }}>
                <Select size="small" value={flt} onChange={e => { setFlt(e.target.value); setPage(1); }} sx={{ width: '100%' }}>
                  <MenuItem value="all">全部</MenuItem>
                  {Object.keys(MAIL_ITEM_STATUS).map(function (k) {
                    return <MenuItem key={k} value={k}>{MAIL_ITEM_STATUS[k]}</MenuItem>;
                  })}
                </Select>
              </Box>
              <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>共 {det.total} 行 · 第 {det.page} 页</Typography>
              <Button size="small" disabled={busy || Number(det.page) <= 1} onClick={() => setPage(Number(det.page) - 1)}>上一页</Button>
              <Button size="small" disabled={busy || Number(det.page) * Number(det.size) >= Number(det.total)} onClick={() => setPage(Number(det.page) + 1)}>下一页</Button>
            </Stack>
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mb: 0.5 }}>
              原文（{Number(det.blast.is_html) === 1 ? '这封按 HTML 发' : '这封按纯文本发'}；站内信里给成员看的是剥掉标签的可读文本）
            </Typography>
            <Box component="pre" sx={{
              m: 0, mb: 1, p: 1, borderRadius: '12px', bgcolor: '#fafafc', border: '1px solid ' + CARD_BORDER,
              fontSize: 12, lineHeight: '20px', whiteSpace: 'pre-wrap', wordBreak: 'break-word', maxHeight: 220, overflow: 'auto',
              fontFamily: 'inherit',
            }}>{det.blast.body}</Box>
            <TableContainer>
              <Table size="small">
                <TableHead><TableRow>
                  {['战队', '显示名', '收件地址', '身份', '结果', '站内信', '原因', '时间'].map(function (h) {
                    return <TableCell key={h} sx={head}>{h}</TableCell>;
                  })}
                </TableRow></TableHead>
                <TableBody>
                  {(det.list || []).map(function (r) {
                    return (
                      <TableRow key={r.uid} hover>
                        <TableCell sx={cell}>{r.team}</TableCell>
                        <TableCell sx={cell}>{r.name}</TableCell>
                        <TableCell sx={{ fontSize: 12, wordBreak: 'break-all' }}>{r.email || '（没有可用地址）'}</TableCell>
                        <TableCell sx={cell}>{r.who === 'owner' ? '站长/管理员' : '成员'}</TableCell>
                        <TableCell sx={cell}><MailStatusChip s={r.status} /></TableCell>
                        <TableCell sx={cell}>{Number(r.notice) === 1 ? '已补' : '没补'}</TableCell>
                        <TableCell sx={{ fontSize: 11, color: '#8a2b22', maxWidth: 240, whiteSpace: 'normal', wordBreak: 'break-word' }}>{r.err || ''}</TableCell>
                        <TableCell sx={cell}>{r.sent_at ? fmtTime(r.sent_at) : ''}</TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
            {(det.list || []).length === 0 && !busy && (
              <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, py: 1 }}>这一档现在没有行。</Typography>
            )}
          </Box>
        )}
      </Paper>
    );
  }

  function PlatformMailBlast() {
    const [cfg, setCfg] = useState(null);
    // banned=1 只在这一次算进被封禁站点（销毁前的告知用），默认关
    const [draft, setDraft] = useState({ subject: '', body: '', groups: ['owner', 'member'], banned: 0 });
    const [bid, setBid] = useState(0);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/mail/config');
        if (j.code === 0) { setCfg(j.data); }
        else { toast(j.msg || '读不到配置', 'error'); }
      } catch (e) { toast('读不到配置', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    if (!cfg) { return <Paper elevation={1} sx={{ p: 2, borderRadius: '16px' }}><LinearProgress /></Paper>; }
    return (
      <Box>
        <Alert severity="info" sx={{ mb: 1.5 }}>
          这一页只有平台超管能用：运营自己写标题与正文，一次发给各战队的站长与管理员、或全部已上线成员。
          发信用的是<b>平台自己配的那一套邮箱</b>，与各战队管理中心里的 smtp_* 各管各的，战队读不到这里的凭据。
          收件地址就是注册时那个 QQ号@qq.com（这版不给成员加「自己填邮箱」），没邮箱的人改由<b>站内信</b>看到同一份通知。
          正式发送前必须先点【先发一封给我看】—— 预览的就是这一份标题+正文，改一个字门禁就作废。
          标题与正文里可以写 <b>{'{team} {name} {qq} {role} {date} {site} {slug}'}</b>，发的那一刻按每个人自己替换（预览那封末尾会附一段渲染后的样子）。
        </Alert>
        {!Number(cfg.enabled) && (
          <Alert severity="warning" sx={{ mb: 1.5 }}>群发目前是<b>关闭</b>的：现在只能看记录，预览与正式发送都会被退回。要在下面那张卡里打开。</Alert>
        )}
        <MailConfig cfg={cfg} onSaved={load} />
        <MailCompose cfg={cfg} draft={draft} setDraft={setDraft} onCreated={function (id) { setBid(id); }} />
        {bid > 0 && <MailQueue bid={bid} onRefresh={load} />}
        <MailHistory onPick={function (id) { setBid(function (b) { return b === id ? 0 : id; }); }} picked={bid} />
      </Box>
    );
  }

  /**
   * 强制平台公告（v5.36.31）：写一条，全体已上线成员登录战队就弹，不点「我已知晓」走不掉。
   * 阅读计数：应读是「现在」还在册的人数（战队被封的不算），公告发出去之后新注册的人也进分母。
   */
  function PlatNotice() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const [draft, setDraft] = useState({ id: 0, title: '', content: '', status: 1 });
    const [detail, setDetail] = useState(null);
    const [askDel, setAskDel] = useState(null);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/announcements');
        if (j.code === 0) { setD(j.data); } else { toast(j.msg || '公告读不到', 'error'); }
      } catch (e) { toast('公告读不到', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const post = async function (url, body, fb) {
      if (busy) { return; }
      setBusy(true);
      try {
        const j = await api(url, { method: 'POST', body: body || {} });
        toast(j.msg || fb, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
        setBusy(false);
        return j;
      } catch (e) { toast('操作失败', 'error'); setBusy(false); return null; }
    };

    const save = async function () {
      const j = await post('/api/platform/admin/announcement/save', {
        id: draft.id, title: draft.title, content: draft.content, status: draft.status,
      }, '已保存');
      if (j && j.code === 0) { setDraft({ id: 0, title: '', content: '', status: 1 }); }
    };

    const edit = function (r) {
      setDraft({ id: r.id, title: r.title, content: (r.content_full || ''), status: Number(r.status) });
      if (!r.content_full) {
        api('/api/platform/admin/announcement?id=' + r.id).then(function (j) {
          if (j.code === 0) { setDraft({ id: r.id, title: j.data.title, content: j.data.content, status: Number(j.data.status) }); }
        }).catch(function () { /* 拿不到就留着列表里那句开头 */ });
      }
    };

    const openDetail = async function (r) {
      try {
        const j = await api('/api/platform/admin/announcement?id=' + r.id);
        if (j.code === 0) { setDetail(j.data); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('取这一条失败', 'error'); }
    };

    if (!d) { return <Paper elevation={1} sx={{ p: 2, borderRadius: '16px' }}><LinearProgress /></Paper>; }

    return (
      <Box>
        {!Number(d.available) && (
          <Alert severity="warning" sx={{ mb: 1.5 }}>
            这一版要的两张表还没建：在服务器上执行 <b>database/upgrade_v5.36.30_to_v5.36.31.sql</b>，
            或者用浏览器（登录着平台超管的同一个浏览器）打开一次 <b>/notice_install_v53631.php</b> 点那个按钮。
            补完刷新这一页就能写了。
          </Alert>
        )}
        <Paper elevation={1} sx={{ p: { xs: 1.5, md: 2 }, mb: 1.5, borderRadius: '16px' }}>
          <Typography sx={{ fontSize: 14, fontWeight: 700, color: TEXT_PRIMARY, mb: 1 }}>写一条强制公告</Typography>
          <TextField size="small" fullWidth label="公告标题" value={draft.title} sx={{ mb: 1 }}
            onChange={e => setDraft(Object.assign({}, draft, { title: e.target.value }))}
            inputProps={{ maxLength: d.title_max }}
            helperText={draft.title.length + ' / ' + d.title_max + '　｜　弹窗最上面那一句'} />
          <TextField size="small" fullWidth multiline minRows={5} maxRows={16} label="公告正文" value={draft.content}
            onChange={e => setDraft(Object.assign({}, draft, { content: e.target.value }))}
            inputProps={{ maxLength: d.content_max }}
            helperText={draft.content.length + ' / ' + d.content_max + '　｜　纯文本，换行原样显示，不认 HTML'} />
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 1, flexWrap: 'wrap', useFlexGap: true }}>
            <FormControlLabel control={<Checkbox size="small" checked={Number(draft.status) === 1}
              onChange={function (e) { setDraft(Object.assign({}, draft, { status: e.target.checked ? 1 : 0 })); }} />}
              label={<Typography sx={{ fontSize: 13 }}>保存后就在线弹出</Typography>} />
            <Button size="small" variant="contained" disabled={busy || !draft.title.trim() || !draft.content.trim()} onClick={save}>
              {draft.id > 0 ? '保存修改' : (Number(draft.status) === 1 ? '发布并弹出' : '先存着不弹')}
            </Button>
            {draft.id > 0 && (
              <Button size="small" color="inherit" disabled={busy}
                onClick={function () { setDraft({ id: 0, title: '', content: '', status: 1 }); }}>不改了</Button>
            )}
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
              {draft.id > 0 ? '改标题正文算同一条，已读人数不清零' : '同时在线最多 ' + d.online_max + ' 条；成员一次只弹最久没处理的那一条'}
            </Typography>
          </Stack>
        </Paper>

        <Paper elevation={1} sx={{ p: { xs: 1.5, md: 2 }, mb: 1.5, borderRadius: '16px' }}>
          <Stack direction="row" alignItems="center" sx={{ gap: 1, flexWrap: 'wrap', useFlexGap: true }}>
            <Typography sx={{ fontSize: 14, fontWeight: 700, color: TEXT_PRIMARY }}>成员能不能关掉</Typography>
            <Box sx={{ flex: '1 1 auto' }} />
            <FormControlLabel control={<Switch size="small" checked={Number(d.allow_mute) === 1} disabled={busy}
              onChange={function (e) { post('/api/platform/admin/announcement/mute', { allow: e.target.checked ? 1 : 0 }, '已保存'); }} />}
              label={<Typography sx={{ fontSize: 13 }}>允许「不再提示」</Typography>} />
          </Stack>
          <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.5 }}>
            「我已知晓」只停这一条，「不再提示」以后所有强制公告都不弹给那个人。
            当前有 <b>{d.global_muted}</b> 人点了不再提示{Number(d.allow_mute) === 1 ? '。' : '；关掉这一档之后，新的人点不了，已经点过的要他自己回来点才行。'}
          </Typography>
        </Paper>

        <Paper elevation={1} sx={{ p: { xs: 1.5, md: 2 }, borderRadius: '16px' }}>
          <Stack direction="row" alignItems="center" sx={{ gap: 1 }}>
            <Typography sx={{ fontSize: 14, fontWeight: 700, color: TEXT_PRIMARY }}>发过的公告</Typography>
            <Box sx={{ flex: '1 1 auto' }} />
            <Button size="small" variant="outlined" disabled={busy} onClick={load}>刷新</Button>
          </Stack>
          {d.list.length === 0 && <EmptyBox text="还没写过强制公告" />}
          {d.list.map(function (r) {
            return (
              <Box key={r.id} sx={{ p: 1.25, mt: 1, borderRadius: '12px', border: '1px solid ' + CARD_BORDER }}>
                <Stack direction="row" alignItems="center" sx={{ gap: 1, flexWrap: 'wrap', useFlexGap: true }}>
                  <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: TEXT_PRIMARY,
                    wordBreak: 'break-word' }}>{r.title}</Typography>
                  <Chip size="small" label={Number(r.status) === 1 ? '在线弹出' : '已下线'}
                    color={Number(r.status) === 1 ? 'success' : 'default'}
                    variant={Number(r.status) === 1 ? 'filled' : 'outlined'}
                    sx={{ height: 20, fontSize: 11 }} />
                  <Box sx={{ flex: '1 1 auto' }} />
                  <Typography sx={{ fontSize: 11.5, color: TEXT_SECONDARY }}>{r.created_at} · {r.actor}</Typography>
                </Stack>
                <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.5,
                  wordBreak: 'break-word' }}>{r.content_head}{r.content_len > 80 ? '…（共 ' + r.content_len + ' 字）' : ''}</Typography>
                <Stack direction="row" spacing={0.5} alignItems="center" sx={{ mt: 0.75, flexWrap: 'wrap', useFlexGap: true }}>
                  <Chip size="small" label={'应读 ' + r.need} sx={{ height: 20, fontSize: 11, bgcolor: BG_GRAY }} />
                  <Chip size="small" label={'已读 ' + r.read + '（知晓 ' + r.ack + ' · 静音 ' + r.muted + '）'}
                    sx={{ height: 20, fontSize: 11, bgcolor: r.read > 0 ? 'success.main' : BG_GRAY, color: r.read > 0 ? '#fff' : TEXT_PRIMARY }} />
                  <Chip size="small" label={'未读 ' + r.unread} sx={{ height: 20, fontSize: 11,
                    bgcolor: r.unread > 0 ? '#fff3e0' : BG_GRAY, color: r.unread > 0 ? '#b25000' : TEXT_PRIMARY }} />
                  <Chip size="small" label={r.rate + '%'} sx={{ height: 20, fontSize: 11, bgcolor: BG_GRAY }} />
                  <Box sx={{ flex: '1 1 auto' }} />
                  <Button size="small" onClick={function () { openDetail(r); }}>按战队看</Button>
                  <Button size="small" onClick={function () { edit(r); }}>改</Button>
                  <Button size="small" disabled={busy} onClick={function () {
                    post('/api/platform/admin/announcement/status', { id: r.id, status: Number(r.status) === 1 ? 0 : 1 }, '已保存');
                  }}>{Number(r.status) === 1 ? '下线' : '上线'}</Button>
                  <Button size="small" color="error" disabled={busy} onClick={function () { setAskDel(r); }}>删</Button>
                </Stack>
              </Box>
            );
          })}
          <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY, mt: 1 }}>
            应读人数按「现在还在册的已上线成员」现算，战队被平台封掉的整队不算；公告发出去之后新注册的人会进分母，
            所以这个数会随时间变大 —— 强制公告本来就该让每个活着的人看到。
          </Typography>
        </Paper>

        <Dialog open={!!detail} onClose={function () { setDetail(null); }} fullWidth maxWidth="sm">
          <DialogTitle>{detail ? detail.title : ''}</DialogTitle>
          <DialogContent dividers>
            {detail && (
              <Box>
                <Typography sx={{ fontSize: 13, lineHeight: '20px', color: TEXT_PRIMARY, whiteSpace: 'pre-wrap',
                  wordBreak: 'break-word', mb: 1 }}>{detail.content}</Typography>
                <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', useFlexGap: true, mb: 1 }}>
                  <Chip size="small" label={'应读 ' + detail.stats.need} sx={{ bgcolor: BG_GRAY }} />
                  <Chip size="small" label={'已读 ' + detail.stats.read} sx={{ bgcolor: 'success.main', color: '#fff' }} />
                  <Chip size="small" label={'未读 ' + detail.stats.unread} sx={{ bgcolor: '#fff3e0', color: '#b25000' }} />
                </Stack>
                <TableContainer sx={{ border: '1px solid ' + CARD_BORDER, borderRadius: '12px' }}>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell sx={{ fontSize: 12 }}>战队</TableCell>
                        <TableCell align="right" sx={{ fontSize: 12 }}>应读</TableCell>
                        <TableCell align="right" sx={{ fontSize: 12 }}>已读</TableCell>
                        <TableCell align="right" sx={{ fontSize: 12 }}>未读</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {detail.stats.by_team.map(function (t) {
                        return (
                          <TableRow key={t.tenant_id}>
                            <TableCell sx={{ fontSize: 12.5 }}>
                              {t.name}
                              <Typography sx={{ fontSize: 11, color: TEXT_SECONDARY,
                                fontFamily: 'ui-monospace, Menlo, monospace' }}>{t.slug}</Typography>
                            </TableCell>
                            <TableCell align="right" sx={{ fontSize: 12.5 }}>{t.need}</TableCell>
                            <TableCell align="right" sx={{ fontSize: 12.5 }}>{t.read}</TableCell>
                            <TableCell align="right" sx={{ fontSize: 12.5, color: t.unread > 0 ? '#b25000' : TEXT_SECONDARY }}>{t.unread}</TableCell>
                          </TableRow>
                        );
                      })}
                      {detail.stats.by_team.length === 0 && (
                        <TableRow><TableCell colSpan={4} sx={{ fontSize: 12.5, color: TEXT_SECONDARY, textAlign: 'center' }}>
                          现在一个应读的人都没有（站点上都还没人，或者全被封了）
                        </TableCell></TableRow>
                      )}
                    </TableBody>
                  </Table>
                </TableContainer>
                <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 1 }}>
                  按未读人数多的排在上面，该去催谁一眼看出来。
                </Typography>
              </Box>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={function () { setDetail(null); }}>关掉</Button>
          </DialogActions>
        </Dialog>

        <Dialog open={!!askDel} onClose={function () { setAskDel(null); }} fullWidth maxWidth="xs">
          <DialogTitle>删掉这条公告？</DialogTitle>
          <DialogContent>
            <Typography sx={{ fontSize: 13, lineHeight: '20px' }}>
              连「谁点过知晓」一起删，删了就对不上账了。只是不想再弹的话，点「下线」就够了 ——
              下线不删记录，已读人数也留着。
            </Typography>
          </DialogContent>
          <DialogActions>
            <Button onClick={function () { setAskDel(null); }}>取消</Button>
            <Button color="error" variant="contained" disabled={busy} onClick={async function () {
              const r = askDel; setAskDel(null);
              await post('/api/platform/admin/announcement/delete', { id: r.id }, '已删除');
            }}>确认删除</Button>
          </DialogActions>
        </Dialog>
      </Box>
    );
  }

  function PlatformLogCleanup() {
    const [stats, setStats] = useState(null);
    const [tid, setTid] = useState(0);
    const [type, setType] = useState('all');
    const [before, setBefore] = useState('');
    const [inspect, setInspect] = useState(false);
    const [plan, setPlan] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      setBusy(true);
      try {
        const json = await api('/api/platform/admin/log-stats');
        if (json.code === 0) { setStats(json.data); } else { toast(json.msg, 'error'); setStats({ list: [], tables: {} }); }
      } catch (e) { setStats({ list: [], tables: {} }); } finally { setBusy(false); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const post = async function (apply) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(before)) { toast('请填写截止日期（YYYY-MM-DD）', 'error'); return; }
      if (apply && !window.confirm(
        '确定清理吗？此操作不可恢复。\n\n站点：' + (tid > 0 ? ('ID ' + tid) : '全部站点')
        + '\n类型：' + (type === 'all' ? '全部日志' : type)
        + '\n早于：' + before
        + (inspect ? '\n★ 已勾选连巡查审计一起删' : '\n（巡查审计会被保留）'))) return;
      setBusy(true);
      try {
        const json = await api('/api/platform/admin/log-cleanup', {
          method: 'POST',
          body: { tenant_id: tid, type: type, before: before, include_inspect: inspect ? 1 : 0, apply: apply ? 1 : 0 },
        });
        if (json.code === 0) {
          setPlan(json.data.plan || null);
          if (apply) { toast(json.msg, 'success'); setPlan(null); load(); }
        } else { toast(json.msg, 'error'); setPlan(null); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    const tblKeys = stats ? Object.keys(stats.tables || {}) : [];

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 1.5 }}>
          按截止日期清理各战队的日志（删除早于该日期的记录）。默认保留 v5.16.0 的「隐身巡查」审计行，
          那是「平台读过哪些站点数据」的唯一凭证；确需一起清再勾选。清理动作本身会写入被清理站点的操作日志。
        </Alert>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5 }} flexWrap="wrap" useFlexGap>
          <Box sx={{ minWidth: 180 }}>
            <Select size="small" value={tid} onChange={e => setTid(Number(e.target.value))}>
              <MenuItem value={0}>全部站点</MenuItem>
              {(stats ? stats.list : []).map(function (x) {
                return <MenuItem key={x.tenant_id} value={x.tenant_id}>{x.name}</MenuItem>;
              })}
            </Select>
          </Box>
          <Box sx={{ minWidth: 160 }}>
            <Select size="small" value={type} onChange={e => setType(e.target.value)}>
              <MenuItem value="all">全部日志类型</MenuItem>
              {tblKeys.map(function (k) {
                return <MenuItem key={k} value={k}>{stats.tables[k]}</MenuItem>;
              })}
            </Select>
          </Box>
          <TextField size="small" placeholder="YYYY-MM-DD" value={before}
            onChange={e => setBefore(e.target.value)} sx={{ width: 150 }}
            inputProps={{ maxLength: 10 }} helperText="早于此日期" />
          <FormControlLabel control={<Checkbox checked={inspect} onChange={e => setInspect(e.target.checked)} />}
            label="含巡查审计" />
          <Button size="small" variant="outlined" disabled={busy} onClick={() => post(false)}>预览条数</Button>
          <Button size="small" variant="contained" color="error" disabled={busy} onClick={() => post(true)}>执行清理</Button>
          <Button size="small" onClick={load}>刷新统计</Button>
        </Stack>

        {plan && (
          <Alert severity="warning" sx={{ mb: 1.5 }}>
            {Object.keys(plan).map(function (k) {
              const p = plan[k];
              return (
                <Typography variant="body2" key={k}>
                  {stats.tables[k] || k}：将删 {p.total} 条
                  {p.inspect > 0 ? ('（其中巡查审计 ' + p.inspect + ' 条，未勾选则保留）') : ''}
                  {p.total > 0 ? (' · 涉及 ' + Object.keys(p.tenants).length + ' 个站点') : ''}
                </Typography>
              );
            })}
          </Alert>
        )}

        {stats === null || busy && !stats.list ? <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>
          : stats.list.length === 0 ? <Alert severity="info">暂无站点</Alert>
          : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>站点</TableCell>
                    {tblKeys.map(function (k) { return <TableCell key={k}>{stats.tables[k]}</TableCell>; })}
                    <TableCell>合计</TableCell><TableCell>巡查审计</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {stats.list.map(function (r) {
                    return (
                      <TableRow key={r.tenant_id}>
                        <TableCell>
                          <Typography variant="body2">{r.name}</Typography>
                          <Typography variant="caption" color="text.secondary">/{r.slug}</Typography>
                        </TableCell>
                        {tblKeys.map(function (k) {
                          const c = r.tables[k] || { n: 0, oldest: '' };
                          return (
                            <TableCell key={k}>
                              <Typography variant="body2">{c.n}</Typography>
                              {c.oldest && (
                                <Typography variant="caption" color="text.secondary">最早 {fmtTime(c.oldest)}</Typography>
                              )}
                            </TableCell>
                          );
                        })}
                        <TableCell>{r.total}</TableCell>
                        <TableCell>{r.inspect > 0
                          ? <Chip size="small" variant="outlined" color="warning" label={r.inspect + ' 条受保护'} />
                          : <Typography variant="caption" color="text.secondary">—</Typography>}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        {stats && stats.platform_login_logs > 0 && (
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            另有平台账号登录日志 {stats.platform_login_logs} 条（在「登录日志」页签上半段查看；
            机器人调用日志与 API 日志的整表清空仍在各自页签）
          </Typography>
        )}
      </Box>
    );
  }

  // ==================== 对外展示页与公示大厅（v5.20.0，平台侧治理） ====================
  // 平台在这层只有三件事：开不开大厅、把哪几支放进焦点区、哪一支的内容违规要先下架。
  // 单队页面的正文由那支战队自己维护，平台侧不代管，也看不到访客投递的内容。
  function PlatShowcase() {
    const [data, setData] = useState(null);
    const [featured, setFeatured] = useState([]);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/showcase');
        if (j.code === 0) { setData(j.data); setFeatured(j.data.featured || []); }
        else toast(j.msg || '读取失败', 'error');
      } catch (e) { toast('读取失败', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const post = async function (url, body, msg) {
      setBusy(true);
      try {
        const j = await api(url, { method: 'POST', body: body });
        if (j.code === 0) { toast(j.msg || msg, 'success'); load(); return j; }
        toast(j.msg || '操作失败', 'error');
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
      return null;
    };

    if (!data) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    const open = data.list.filter(function (x) { return x.visible === 1; });
    const toggle = function (tid) {
      const has = featured.indexOf(tid) >= 0;
      if (!has && featured.length >= 3) { toast('焦点区最多 3 支', 'error'); return; }
      setFeatured(has ? featured.filter(function (x) { return x !== tid; }) : featured.concat([tid]));
    };

    return (
      <Stack spacing={2}>
        <Alert severity="info">
          战队对外展示页（/{'{'}目录{'}'}/show）由各自战队编辑。平台侧这三件事优先于战队自己的设置：
          大厅总开关、焦点区精选、以及"一键下架"——下架后即使队长那边仍是开启，访客也打不开，
          而且这条会写进那支战队自己的操作日志，他们查得到是谁下的。
        </Alert>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ md: 'center' }}>
            <FormControlLabel
              control={<Switch checked={data.hall_open === 1} disabled={busy}
                onChange={(e) => post('/api/platform/admin/showcase/hall', { enabled: e.target.checked ? 1 : 0 }, '已切换')} />}
              label="开放公示大厅（/hall）" />
            <Button size="small" variant="outlined" onClick={() => window.open('/hall', '_blank')}>打开大厅看看</Button>
            <Chip size="small" label={'已开主页 ' + data.shown + ' / ' + data.total + ' 站'} />
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography fontWeight={700}>大厅焦点区（最多 3 支）</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
            只有已开启展示页且没被下架的站点能被选进来。
          </Typography>
          {open.length === 0 && <Typography variant="body2" color="text.secondary">当前没有站点开着主页。</Typography>}
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
            {open.map(function (x) {
              return <FormControlLabel key={x.tid} control={<Checkbox size="small" checked={featured.indexOf(x.tid) >= 0}
                disabled={busy} onChange={() => toggle(x.tid)} />}
                label={<Typography variant="body2">{x.name}</Typography>} />;
            })}
          </Stack>
          <Button size="small" variant="contained" sx={{ mt: 1 }} disabled={busy}
            onClick={() => post('/api/platform/admin/showcase/featured', { tenant_ids: featured.join(',') }, '焦点区已保存')}>
            保存焦点区
          </Button>
        </Paper>

        <TableContainer>
          <Table size="small">
            <TableHead><TableRow>
              <TableCell>战队</TableCell><TableCell>目录</TableCell><TableCell>站点状态</TableCell>
              <TableCell align="center">战队开启</TableCell><TableCell align="center">平台下架</TableCell>
              <TableCell align="center">访客可见</TableCell><TableCell align="right">成员</TableCell>
              <TableCell align="right">待处理投递</TableCell><TableCell>主页</TableCell><TableCell align="right">操作</TableCell>
            </TableRow></TableHead>
            <TableBody>
              {data.list.map(function (x) {
                return (
                  <TableRow key={x.tid}>
                    <TableCell>{x.name}</TableCell>
                    <TableCell>/{x.slug}/</TableCell>
                    <TableCell><Chip size="small" label={x.site_status} color={x.site_status === 'approved' ? 'success' : 'default'} /></TableCell>
                    <TableCell align="center">{x.team_open === 1 ? '已开' : '未开'}</TableCell>
                    <TableCell align="center">{x.force_off === 1 ? <Chip size="small" color="error" label="已下架" /> : '—'}</TableCell>
                    <TableCell align="center">{x.visible === 1 ? <Chip size="small" color="success" label="可访问" /> : '不可访问'}</TableCell>
                    <TableCell align="right">{x.members}</TableCell>
                    <TableCell align="right">{x.pending > 0 ? x.pending : '—'}</TableCell>
                    <TableCell><Link href={x.show_url} target="_blank" rel="noopener">{x.show_url}</Link></TableCell>
                    <TableCell align="right">
                      <Button size="small" disabled={busy}
                        onClick={() => post('/api/platform/admin/showcase/force',
                          { tenant_id: x.tid, off: x.force_off === 1 ? 0 : 1 }, x.force_off === 1 ? '已恢复' : '已下架')}>
                        {x.force_off === 1 ? '恢复上架' : '一键下架'}
                      </Button>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      </Stack>
    );
  }
  // 私聊·平台治理（v5.30.1 把举报与禁言从战队侧整体搬到这里；v5.32.0 全站大厅下线，这一页只剩私聊）
  // 四条口径写在页面上，别只写在代码里：
  //   ① 私聊正文只有顺着当事人那条举报才读得到，每读一次往平台操作日志记一行；
  //   ② 结案必须写一句结果说明，这句原样回给举报人；
  //   ③ 平台下的禁言（muted_by=platform）队长解不掉，队长自设的那条仍由本队自己解；
  //   ④ 「谁还被平台禁着、怎么放出来」原先顺路挂在大厅消息那张表上，大厅没了就补一个「平台禁言」页签，
  //      不然只剩下一只按人的手，放人得回去改库。
  // ---------- 社区论坛对接（v5.35.0，平台侧）----------
  // 这一格存在的理由跟邮件群发那把总闸一样：数据是要出站的。哪天那个论坛被拖了、
  // 或者某队的 Key 漏了，得有一个不用挨个通知队长的动作 —— 所以「一把按住全站」和「只停这一队」两条都要有。
  // 这里只写停不停，不动队长自己那一格：放回去就照他原来的设置继续生效。
  function PlatForumApi() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState('');

    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/forum-api');
        if (j.code === 0) { setD(j.data); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('取论坛对接状态失败', 'error'); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const setMaster = async function (on) {
      setBusy('master');
      try {
        const j = await api('/api/platform/admin/forum-api/master', { method: 'POST', body: { on: on ? '1' : '0' } });
        toast(j.msg, j.code === 0 ? 'success' : 'error');
        // 关总闸改的是「每一队还读不读得到」，只把开关那格抹掉不够：浏览器里量到过开关写着「已停用全站」、
        // 下面汇总与每行还挂着「正在给读数：1」，那就是自相矛盾。重取一次让两者同一个口径。
        if (j.code === 0) { load(); }
      } catch (e) { toast('保存失败', 'error'); }
      setBusy('');
    };

    const setOne = async function (t, off) {
      setBusy('t' + t.id);
      try {
        const j = await api('/api/platform/admin/forum-api/toggle', {
          method: 'POST', body: { tenant_id: t.id, off: off ? '1' : '0' },
        });
        toast(j.msg, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('操作失败', 'error'); }
      setBusy('');
    };

    // 正在给读数的排前面：出事的时候要一眼看到那几队，而不是从列表第一行开始找
    const rows = (d && d.list ? d.list.slice() : []).sort(function (a, b) {
      if (a.serving !== b.serving) { return Number(b.serving) - Number(a.serving); }
      return Number(b.tenant_on) - Number(a.tenant_on) || a.id - b.id;
    });

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>社区论坛对接</Typography>
        <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: '#6e6e73', mt: 0.5, mb: 1.25 }}>
          各战队把本队数据只读交给自己那个社区论坛；这一页管的是要不要让它出站。
        </Typography>
        <Stack direction="row" alignItems="center" sx={{ gap: 1.5, flexWrap: 'wrap', useFlexGap: true, mb: 1.25 }}>
          <FormControlLabel control={
            <Switch checked={!!(d && d.master_on)} disabled={busy === 'master'}
              onChange={function (e) { setMaster(e.target.checked); }} />
          } label={d && d.master_on ? '全站可用' : '已停用全站'} />
          <Chip size="small" label={'正在给读数：' + (d ? d.serving : '…')} sx={{ bgcolor: BG_GRAY }} />
          <Chip size="small" label={'已开通站点：' + (d ? d.listed : '…')} sx={{ bgcolor: BG_GRAY }} />
        </Stack>
        <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mb: 1 }}>
          关掉总闸是所有队立刻读不到（战队那边会看到「平台已停用」这句话，不是对着 403 猜）；
          下面每一行只停那一队。两种停法都不改队长自己那一格的设置。
        </Typography>

        {!d && <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY, py: 2 }}>正在取…</Typography>}
        {d && rows.length === 0 && <EmptyBox text="还没有已开通的站点" />}

        {d && (
          <Stack spacing={0.75}>
            {rows.map(function (t) {
              return (
                <Box key={t.id} sx={{ p: 1.25, borderRadius: 2, border: '1px solid ' + CARD_BORDER }}>
                  <Stack direction="row" alignItems="center"
                    sx={{ gap: 1, flexWrap: 'wrap', useFlexGap: true }}>
                    <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: TEXT_PRIMARY }}>{t.name}</Typography>
                    <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY,
                      fontFamily: 'ui-monospace, Menlo, monospace' }}>{t.slug}</Typography>
                    <Chip size="small" label={Number(t.serving) === 1 ? '正在给读数'
                      : (Number(t.force_off) === 1 ? '平台已停用' : (Number(t.tenant_on) === 1 ? '本队开着但总闸关着' : '本队没打开'))}
                      sx={{ height: 20, fontSize: 11, bgcolor: BG_GRAY }} />
                    <Box sx={{ flex: '1 1 auto' }} />
                    <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
                      {Number(t.calls_24h) > 0 ? ('近 24 小时调用 ' + t.calls_24h + ' 次 · 最近 ' + t.last_call) : '近 24 小时没人调用'}
                    </Typography>
                    <Button size="small" variant={Number(t.force_off) === 1 ? 'contained' : 'outlined'}
                      disabled={busy === ('t' + t.id)}
                      onClick={function () { setOne(t, Number(t.force_off) !== 1); }}>
                      {Number(t.force_off) === 1 ? '放出来' : '停用这一队'}
                    </Button>
                  </Stack>
                </Box>
              );
            })}
          </Stack>
        )}
      </Paper>
    );
  }

  // ---------- 站点活跃（v5.35.1）----------
  // 他问的是「哪个战队还在真的用」，所以这里只数「有人连上来过」，不数内容产出。
  // 逐日历史没存（users 只保留最后一次活跃），所以这一页不画趋势图 —— 画一条假的比画一条真的更糟。
  function PlatActive() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/active');
        if (j.code === 0) { setD(j.data); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('取站点活跃失败', 'error'); }
      setBusy(false);
    }, []);
    useEffect(function () { load(); }, [load]);

    const label = function (t) {
      if (!t.last_at) { return t.members > 0 ? '还没人用过' : '还没有成员'; }
      if (t.today > 0) { return '今天有动静'; }
      if (t.d7 > 0) { return '7 天内有动静'; }
      if (t.d30 > 0) { return '30 天内有动静'; }
      return '一个月没动静';
    };
    const ago = function (t) {
      if (!t.last_at) { return '还没人用过'; }
      if (!t.idle_days) { return '今天有人用过 · ' + t.last_at; }
      return t.idle_days + ' 天前有人用过 · ' + t.last_at;
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Stack direction="row" alignItems="center" sx={{ gap: 1, flexWrap: 'wrap', useFlexGap: true }}>
          <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>站点活跃</Typography>
          <Box sx={{ flex: '1 1 auto' }} />
          <Button size="small" variant="outlined" disabled={busy} onClick={load}>刷新</Button>
        </Stack>
        <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.5 }}>
          哪个战队还在真的用这个站点：按账号最后一次带登录态请求的时间算，今日 / 7 天 / 30 天三个数。
          只给每队的数字，不列成员昵称。
        </Typography>

        {!d && <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY, py: 2 }}>正在取…</Typography>}

        {d && (
          <Stack direction="row" alignItems="center" sx={{ gap: 1, flexWrap: 'wrap', useFlexGap: true, my: 1.25 }}>
            <Chip size="small" label={'今天有动静：' + d.live.today + ' 队'}
              sx={{ bgcolor: d.live.today > 0 ? 'success.main' : BG_GRAY, color: d.live.today > 0 ? '#fff' : TEXT_PRIMARY }} />
            <Chip size="small" label={'7 天内有：' + d.live.d7 + ' 队'} sx={{ bgcolor: BG_GRAY }} />
            <Chip size="small" label={'30 天内有：' + d.live.d30 + ' 队'} sx={{ bgcolor: BG_GRAY }} />
            <Chip size="small" label={'站点共 ' + d.sites + ' 个'} sx={{ bgcolor: BG_GRAY }} />
          </Stack>
        )}

        {d && d.list.length === 0 && <EmptyBox text="还没有已开通的站点" />}

        {d && d.list.map(function (t) {
          return (
            <Box key={t.id} sx={{ p: 1.25, mb: 0.75, borderRadius: 2, border: '1px solid ' + CARD_BORDER }}>
              <Stack direction="row" alignItems="center" sx={{ gap: 1, flexWrap: 'wrap', useFlexGap: true }}>
                <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: TEXT_PRIMARY }}>{t.name}</Typography>
                <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY,
                  fontFamily: 'ui-monospace, Menlo, monospace' }}>{t.slug}</Typography>
                <Chip size="small" label={label(t)}
                  sx={{ height: 20, fontSize: 11, bgcolor: t.today > 0 ? 'success.main' : BG_GRAY,
                    color: t.today > 0 ? '#fff' : TEXT_PRIMARY }} />
                <Box sx={{ flex: '1 1 auto' }} />
                <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>成员 {t.members} 人</Typography>
              </Stack>
              <Stack direction="row" alignItems="baseline" sx={{ gap: 1.25, flexWrap: 'wrap', useFlexGap: true, mt: 0.5 }}>
                <Typography sx={{ fontSize: 12.5, color: TEXT_PRIMARY }}>
                  今天 <b>{t.today}</b> 人 · 7 天 <b>{t.d7}</b> 人 · 30 天 <b>{t.d30}</b> 人
                </Typography>
                <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>{ago(t)}</Typography>
              </Stack>
            </Box>
          );
        })}

        {d && (
          <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY, mt: 1 }}>{d.note}</Typography>
        )}
      </Paper>
    );
  }

  // ---------- 赛前提醒（v5.33.0，平台侧总闸）----------
  // 这一页存在的理由：提醒会往每个战队的公告里写东西，出问题（时间算错、某天推太多、文案不合口味）
  // 时得有一把能一次按住的手，而不是让几十个队长各自去关自己那一格。
  // 关掉的只是「推」这个动作，队长设的提前量原样留着，重新打开就照原样继续。
  function PlatRemind() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/remind/config');
        if (j.code === 0) { setD(j.data); } else { toast(j.msg || '提醒设置读不到', 'error'); }
      } catch (e) { toast('提醒设置读不到', 'error'); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const post = async function (url, body, fb) {
      if (busy) { return; }
      setBusy(true);
      try {
        const j = await api(url, { method: 'POST', body: body || {} });
        toast(j.msg || fb, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('操作失败', 'error'); }
      setBusy(false);
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>赛前提醒</Typography>
        <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: '#6e6e73', mt: 0.5 }}>
          各战队的训练赛、队内日程、跨队约战 到开打前会自动给本队发一条公告，报了名的人再各收到一条通知。
          提前几分钟由各战队队长自己选，这里这一把是全站总闸。
        </Typography>

        {!d && <Typography sx={{ fontSize: 13, color: '#6e6e73', py: 2 }}>正在取…</Typography>}
        {d && (
          <Box sx={{ mt: 1.5 }}>
            <FormControlLabel
              control={<Switch checked={Number(d.enabled) === 1} disabled={busy}
                onChange={function (e) { post('/api/platform/admin/remind/config', { enabled: e.target.checked ? 1 : 0 }, '已保存'); }} />}
              label={<Typography sx={{ fontSize: 13.5, color: TEXT_PRIMARY }}>
                {Number(d.enabled) === 1 ? '正在推（所有队照自己设的提前量推）' : '已停用（一条都不推，队长设的提前量保留）'}
              </Typography>} />

            <Stack direction="row" spacing={1} sx={{ mt: 1.5, flexWrap: 'wrap', useFlexGap: true }}>
              <Chip size="small" label={'已上线站点 ' + d.tenants + ' 个'} sx={{ bgcolor: BG_GRAY, fontSize: 12 }} />
              <Chip size="small" label={'今天推出去 ' + d.sent_today + ' 条'} sx={{ bgcolor: BG_GRAY, fontSize: 12 }} />
              <Chip size="small" label={'默认提前 ' + d.default_lead + ' 分钟'} sx={{ bgcolor: BG_GRAY, fontSize: 12 }} />
            </Stack>

            <Box sx={{ mt: 1.25 }}>
              <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.5 }}>
                有多少队把哪一类关了
              </Typography>
              {Object.keys(d.closed).map(function (k) {
                return (
                  <Typography key={k} sx={{ fontSize: 12.5, lineHeight: '20px', color: '#6e6e73' }}>
                    {d.closed[k].name}：{Number(d.closed[k].off) === 0 ? '没有队关掉' : d.closed[k].off + ' 个队选了不提醒'}
                  </Typography>
                );
              })}
              <Typography sx={{ fontSize: 12, lineHeight: '18px', color: '#6e6e73', mt: 0.5 }}>
                没在这份名单里的队走默认 30 分钟；它们没写过设置，所以库里查不到这一行。
              </Typography>
            </Box>

            <Button size="small" variant="outlined" sx={{ mt: 1.5 }} disabled={busy}
              onClick={function () { post('/api/platform/admin/remind/tick', {}, '已检查'); }}>
              全站点检查一次
            </Button>
            <Typography sx={{ fontSize: 12, lineHeight: '18px', color: '#6e6e73', mt: 0.5 }}>
              只推到点的那些，已经推过的不会重复推。成员平时打开站点时也会顺路查一次（一队一分钟最多一次），
              所以不配计划任务照样能用；要更准时，就在计划任务里每分钟打一次
              POST /api/open/remind/tick（带平台作用域的 X-Api-Key）。
            </Typography>
            <Alert severity="info" sx={{ mt: 1.5, fontSize: 12.5 }}>
              {d.note}
            </Alert>
          </Box>
        )}
      </Paper>
    );
  }

  function PlatChatGov() {
    const [view, setView] = useState('dm');           // dm | mutes
    const [busy, setBusy] = useState(false);
    const [dm, setDm] = useState(null);
    const [dmStatus, setDmStatus] = useState('pending');
    const [dmNote, setDmNote] = useState({});
    const [open, setOpen] = useState({});            // 举报 id => 展开的那段私聊正文
    const [ask, setAsk] = useState(null);            // {mode:'mute', id, name, reason}
    const [mutes, setMutes] = useState(null);

    const loadDm = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/chat/reports?status=' + dmStatus + '&size=20');
        if (j.code === 0) setDm(j.data); else toast(j.msg || '私聊举报读不到', 'error');
      } catch (e) { toast('私聊举报读不到', 'error'); }
    }, [dmStatus]);

    const loadMutes = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/mutes');
        if (j.code === 0) setMutes(j.data); else toast(j.msg || '禁言名单读不到', 'error');
      } catch (e) { toast('禁言名单读不到', 'error'); }
    }, []);

    useEffect(function () { loadDm(); }, [loadDm]);
    useEffect(function () { loadMutes(); }, [loadMutes]);

    const reloadAll = function () {
      loadDm(); loadMutes();
      // 侧栏那颗红点算的是同一份数，这一页结完案要让它一起重取
      try { window.dispatchEvent(new Event('yys-gov-badge')); } catch (e) { /* 广播失败不影响本页 */ }
    };

    const post = async function (url, body, msg) {
      if (busy) { return null; }
      setBusy(true);
      try {
        const j = await api(url, { method: 'POST', body: body });
        if (j.code === 0) { toast(j.msg || msg, 'success'); reloadAll(); return j; }
        toast(j.msg || '操作失败', 'error');
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
      return null;
    };

    const openThread = async function (r) {
      if (open[r.id]) {
        const off = Object.assign({}, open); delete off[r.id]; setOpen(off); return;
      }
      if (busy) { return; }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/chat/report/open', { method: 'POST', body: { id: r.id } });
        if (j.code === 0) {
          const next = Object.assign({}, open); next[r.id] = j.data; setOpen(next);
          toast(j.msg || '已展开这段对话', 'success');
          // 展开会把这条从「待处理」挪到「已展开待结案」，筛选档跟着挪，刚展开的那段才不会被筛掉
          if (dmStatus === 'pending') { setDmStatus('opened'); } else { loadDm(); }
        } else { toast(j.msg || '展开失败', 'error'); }
      } catch (e) { toast('展开失败', 'error'); } finally { setBusy(false); }
    };

    const submitAsk = async function () {
      if (!ask) { return; }
      if (!ask.reason.trim()) { toast('理由不能空着', 'error'); return; }
      const j = await post('/api/platform/admin/mute', { user_id: ask.id, reason: ask.reason.trim() }, '已禁言');
      if (j) setAsk(null);
    };

    const DM_STATUS = { pending: '待处理', opened: '已展开待结案', handled: '已处理', dismissed: '不成立' };
    const dmCount = dm ? (((dm.counts && dm.counts.pending) || 0) + ((dm.counts && dm.counts.opened) || 0)) : 0;
    const muteCount = mutes ? Number(mutes.total || 0) : 0;

    // 当事人当前被谁禁着：平台这条给「解除」，本队那条只标出来（这一页解不了，也不该解）
    const muteTag = function (p) {
      if (!p || Number(p.muted) !== 1) { return null; }
      const mine = p.mby === 'platform';
      return (
        <Chip size="small" color={mine ? 'warning' : 'default'}
          label={mine ? '平台已禁言' : '本队已禁言'} sx={{ height: 18, fontSize: 11 }} />
      );
    };

    return (
      <Stack spacing={2}>
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction="row" spacing={1.5} alignItems="center" sx={{ flexWrap: 'wrap', gap: 1.5 }}>
            <Typography sx={{ fontSize: 15, fontWeight: 700 }}>私聊·平台治理</Typography>
            <Box sx={{ flex: '1 1 auto' }} />
            <Stack direction="row" spacing={0.75} sx={{ flexWrap: 'wrap', gap: 0.75 }}>
              <Chip size="small" color={dmCount ? 'warning' : 'default'} label={'私聊举报 ' + dmCount} sx={{ height: 22, fontSize: 11 }} />
              <Chip size="small" color={muteCount ? 'warning' : 'default'} label={'平台禁言 ' + muteCount} sx={{ height: 22, fontSize: 11 }} />
            </Stack>
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            私聊的举报与禁言都在这一页处理，战队队长那边只剩「本站要不要开私聊」这一个开关。
            正文只有顺着当事人的一条举报才读得到，每读一次都记进平台操作日志；平台给的禁言本队队长解不掉。
          </Typography>
        </Paper>

        <Tabs value={view} onChange={(e, v) => setView(v)} variant="scrollable" scrollButtons="auto"
          sx={{ borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="dm" label={'私聊举报' + (dmCount ? ('（' + dmCount + '）') : '')} />
          <Tab value="mutes" label={'平台禁言' + (muteCount ? ('（' + muteCount + '）') : '')} />
        </Tabs>

        {view === 'dm' && (
          <Paper variant="outlined" sx={{ p: 2 }}>
            <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap sx={{ mb: 1 }}>
              <Typography sx={{ fontSize: 14, fontWeight: 700 }}>私聊举报</Typography>
              <Select size="small" value={dmStatus} onChange={(e) => setDmStatus(e.target.value)} sx={{ minWidth: 130 }}>
                <MenuItem value="pending">待处理</MenuItem>
                <MenuItem value="opened">已展开待结案</MenuItem>
                <MenuItem value="handled">已处理</MenuItem>
                <MenuItem value="dismissed">不成立</MenuItem>
                <MenuItem value="all">全部</MenuItem>
              </Select>
              <Box sx={{ flex: '1 1 auto' }} />
              <Button size="small" onClick={loadDm}>刷新</Button>
            </Stack>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
              {dm && dm.notice ? dm.notice : '私聊正文只有顺着一条举报才读得到，每次读取都会往平台操作日志记一行。'}
            </Typography>
            {!dm && <Typography variant="body2" color="text.secondary">正在取…</Typography>}
            {dm && dm.list.length === 0 && <Alert severity="success" sx={{ borderRadius: '10px' }}>这一档没有私聊举报。</Alert>}
            {dm && dm.list.map(function (r) {
              const th = open[r.id];
              return (
                <Box key={r.id} sx={{ py: 1, borderTop: '1px solid rgba(0,0,0,.08)' }}>
                  <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                    <Chip size="small" color={r.status === 'pending' || r.status === 'opened' ? 'warning' : 'default'}
                      label={DM_STATUS[r.status] || r.status} sx={{ height: 20, fontSize: 11 }} />
                    {r.cross_team === 1 && <Chip size="small" label="跨战队" sx={{ height: 18, fontSize: 11 }} />}
                    {muteTag(r.peer)}
                    <Typography sx={{ fontSize: 12 }}>
                      {(r.reporter && r.reporter.name) || '已注销'}（{(r.reporter && r.reporter.team) || '—'}）
                      举报
                      {(r.peer && r.peer.name) || '已注销'}（{(r.peer && r.peer.team) || '—'}）
                    </Typography>
                    <Box sx={{ flex: '1 1 auto' }} />
                    <Typography variant="caption" color="text.secondary">{r.created_at}</Typography>
                  </Stack>
                  <Typography variant="body2" sx={{ mt: 0.5, wordBreak: 'break-word' }}>举报理由：{r.reason}</Typography>
                  {r.msg_alive === 0 && (
                    <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>
                      被举报的那条消息已经不在了，下面展开的是这段会话剩下的部分。
                    </Typography>
                  )}
                  <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap sx={{ mt: 1 }}>
                    <Button size="small" variant="outlined" disabled={busy} onClick={() => openThread(r)}>
                      {th ? '收起这段对话' : '展开正文'}
                    </Button>
                    {th && (
                      <Typography variant="caption" color="text.secondary">共 {th.list.length} 条（最多取最近 100 条）</Typography>
                    )}
                  </Stack>
                  {th && (
                    <Box sx={{
                      mt: 1, p: 1.5, borderRadius: '10px', bgcolor: 'rgba(0,0,0,.03)',
                      maxHeight: 280, overflowY: 'auto',
                    }}>
                      {th.list.length === 0 && (
                        <Typography variant="body2" color="text.secondary">这段会话里没有可读的消息。</Typography>
                      )}
                      {th.list.map(function (m) {
                        return (
                          <Box key={m.id} sx={{
                            py: 0.5, px: m.reported === 1 ? 1 : 0, mb: 0.25, borderRadius: '8px',
                            bgcolor: m.reported === 1 ? 'rgba(255,149,0,.16)' : 'transparent',
                          }}>
                            <Typography variant="caption" color="text.secondary">
                              {m.created_at} · {m.from_name}{m.from_team ? ('（' + m.from_team + '）') : ''}
                              {m.reported === 1 ? ' · 被举报的那条' : ''}
                            </Typography>
                            <Typography sx={{ fontSize: 13, wordBreak: 'break-word' }}>
                              {m.recalled === 1 ? <em>（这条已被发送方撤回）</em> : m.content}
                            </Typography>
                          </Box>
                        );
                      })}
                    </Box>
                  )}
                  {(r.status === 'pending' || r.status === 'opened') && (
                    <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap sx={{ mt: 1 }}>
                      <TextField size="small" placeholder="结果说明（会回给举报人）" value={dmNote[r.id] || ''}
                        onChange={(e) => setDmNote(Object.assign({}, dmNote, { [r.id]: e.target.value }))}
                        inputProps={{ maxLength: 200 }} sx={{ flex: '1 1 220px', maxWidth: 360 }} />
                      <Button size="small" variant="outlined" color="error" disabled={busy}
                        onClick={() => post('/api/platform/admin/chat/report/handle',
                          { id: r.id, action: 'handled', note: (dmNote[r.id] || '').trim() }, '已结案')}>
                        已处理
                      </Button>
                      <Button size="small" variant="outlined" disabled={busy}
                        onClick={() => post('/api/platform/admin/chat/report/handle',
                          { id: r.id, action: 'dismissed', note: (dmNote[r.id] || '').trim() }, '已驳回')}>
                        不成立
                      </Button>
                      {r.peer && Number(r.peer.muted) === 1 && r.peer.mby === 'platform' ? (
                        <Button size="small" variant="outlined" disabled={busy}
                          onClick={() => post('/api/platform/admin/unmute', { user_id: r.peer.id }, '已解除禁言')}>
                          解除禁言
                        </Button>
                      ) : (
                        <Button size="small" variant="outlined" disabled={busy}
                          onClick={() => setAsk({ mode: 'mute', id: (r.peer && r.peer.id) || 0, name: (r.peer && r.peer.name) || '', reason: '你违反用户协议，根据用户协议禁言' })}>
                          禁言被举报人
                        </Button>
                      )}
                    </Stack>
                  )}
                  {(r.status === 'handled' || r.status === 'dismissed') && !!r.result_note && (
                    <Typography variant="caption" sx={{ display: 'block', mt: 0.5, color: 'success.main' }}>
                      结果：{r.result_note}
                    </Typography>
                  )}
                </Box>
              );
            })}
          </Paper>
        )}

        {view === 'mutes' && (
          <Paper variant="outlined" sx={{ p: 2 }}>
            <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap sx={{ mb: 1 }}>
              <Typography sx={{ fontSize: 14, fontWeight: 700 }}>平台禁言中</Typography>
              <Box sx={{ flex: '1 1 auto' }} />
              <Button size="small" onClick={loadMutes}>刷新</Button>
            </Stack>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
              这里只有平台自己下的那批禁言（muted_by=platform）；本队队长自设的那条不在列，也只能由本队自己解。
              禁言理由与操作人记在「巡查记录」那一份平台操作日志里，这一页只负责看得见与放得掉。
            </Typography>
            {!mutes && <LoadingBox />}
            {mutes && mutes.list.length === 0 && (
              <Alert severity="success" sx={{ borderRadius: '10px' }}>平台这边没有人在禁言中。</Alert>
            )}
            {mutes && mutes.list.length > 0 && (
              <TableContainer>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>成员</TableCell><TableCell>战队</TableCell><TableCell>账号状态</TableCell><TableCell>操作</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {mutes.list.map(function (u) {
                      return (
                        <TableRow key={u.id} sx={{ verticalAlign: 'top' }}>
                          <TableCell sx={{ fontSize: 12, whiteSpace: 'nowrap' }}>{u.name}</TableCell>
                          <TableCell sx={{ fontSize: 12, whiteSpace: 'nowrap' }}>{u.team || '—'}</TableCell>
                          <TableCell sx={{ fontSize: 12 }}>{u.state === 'approved' ? '正常' : u.state}</TableCell>
                          <TableCell>
                            <Button size="small" variant="outlined" disabled={busy}
                              onClick={() => post('/api/platform/admin/unmute', { user_id: u.id }, '已解除禁言')}>
                              解除禁言
                            </Button>
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
            {mutes && Number(mutes.capped) === 1 && (
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                名单到这里截了一刀（最多列 {muteCount} 人）；真被禁到这么多人，靠的就不该是这一页。
              </Typography>
            )}
          </Paper>
        )}

        <Dialog open={!!ask} onClose={() => setAsk(null)} fullWidth maxWidth="xs">
          <DialogTitle sx={{ pb: 0.5 }}>{ask ? ('禁言 ' + (ask.name || '')) : ''}</DialogTitle>
          <DialogContent>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
              理由会记进平台操作日志。禁言后他在自己队的战队群里说不了话、私聊与枪码评论也发不出去，而且本队队长解不掉这一条。
            </Typography>
            <TextField fullWidth multiline minRows={2} size="small" label="理由"
              value={ask ? ask.reason : ''} onChange={(e) => setAsk({ ...ask, reason: e.target.value })}
              inputProps={{ maxLength: 200 }} margin="normal" />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setAsk(null)}>取消</Button>
            <Button variant="contained" color="error" disabled={busy} onClick={submitAsk}>确定</Button>
          </DialogActions>
        </Dialog>
      </Stack>
    );
  }

  // ==================== 平台侧 AI 聊天（v5.36.14）====================
  // 平台只管三件事：首选模型、统一提示词、给战队发 Token。
  // 刻意不展示任何群聊正文：用量页只有模型名与 Token 数字。
  function PlatAi() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState(null);
    const [grantT, setGrantT] = useState(null);
    const [grantAmount, setGrantAmount] = useState('5000');
    const [grantNote, setGrantNote] = useState('');
    // v5.36.29：模型名自动获取 / 人设库 / 表情包库
    const [models, setModels] = useState([]);
    const [promptEdit, setPromptEdit] = useState(null);
    const [stickerName, setStickerName] = useState('');
    const [stickerNote, setStickerNote] = useState('');
    const [stickerFile, setStickerFile] = useState(null);
    const [stickerEdit, setStickerEdit] = useState(null);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/ai');
        if (j.code === 0) {
          setD(j.data);
          setForm(Object.assign({}, j.data.config, { api_key: '' }));
        } else { toast(j.msg || 'AI 配置读不到', 'error'); }
      } catch (e) { toast('AI 配置读不到', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const set = function (k) { return function (e) { setForm(Object.assign({}, form, { [k]: e.target.value })); }; };
    const setBool = function (k) { return function (e) { setForm(Object.assign({}, form, { [k]: e.target.checked ? 1 : 0 })); }; };

    const save = async function (extra) {
      if (!form || busy) { return; }
      setBusy(true);
      try {
        const body = Object.assign({
          enabled: form.enabled, base_url: form.base_url, api_key: form.api_key,
          model: form.model, max_tokens: form.max_tokens, temperature: form.temperature,
          prompt: form.prompt, proactive_prompt: form.proactive_prompt,
          allow_links: form.allow_links, max_proactive_per_day: form.max_proactive_per_day,
          sticker_enabled: form.sticker_enabled,
        }, extra || {});
        const j = await api('/api/platform/admin/ai/save', { method: 'POST', body: body });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('保存失败', 'error'); }
      setBusy(false);
    };

    const test = async function () {
      if (!form || busy) { return; }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/test', { method: 'POST', body: {
          base_url: form.base_url, api_key: form.api_key, model: form.model,
        } });
        toast(j.msg || '测试完成', j.code === 0 ? 'success' : 'error');
      } catch (e) { toast('测试失败', 'error'); }
      setBusy(false);
    };

    const doGrant = async function () {
      const n = parseInt(grantAmount, 10);
      if (!grantT || isNaN(n) || n <= 0) { toast('请输入正整数 Token 数', 'error'); return; }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/tenants/' + grantT.id + '/grant',
          { method: 'POST', body: { amount: n, note: grantNote } });
        toast(j.msg || '已充值', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { setGrantT(null); setGrantNote(''); load(); }
      } catch (e) { toast('充值失败', 'error'); }
      setBusy(false);
    };

    const doReset = async function (t) {
      if (!window.confirm('清零「' + t.name + '」的平台 Token 余额？\n历史消耗记录保留，只把余额归零')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/tenants/' + t.id + '/reset', { method: 'POST', body: { note: '平台清零' } });
        toast(j.msg || '已清零', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('清零失败', 'error'); }
      setBusy(false);
    };

    const doDisable = async function (t) {
      const off = Number(t.platform_disabled) === 1 ? 0 : 1;
      if (!window.confirm(off
        ? '停用「' + t.name + '」的 AI 聊天？\n停用后平台模型和自有模型都不能用。'
        : '恢复「' + t.name + '」的 AI 聊天？')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/tenants/' + t.id + '/disable', { method: 'POST', body: { disabled: off } });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('操作失败', 'error'); }
      setBusy(false);
    };

    const tick = async function () {
      if (busy) { return; }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/tick', { method: 'POST', body: {} });
        toast(j.msg || '已检查', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('检查失败', 'error'); }
      setBusy(false);
    };

    // ---- v5.36.29：从 Base URL 拉模型名 ----
    const fetchModels = async function () {
      if (!form) { return; }
      if (!form.base_url) { toast('先填 Base URL', 'error'); return; }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/models', { method: 'POST', body: {
          base_url: form.base_url, api_key: form.api_key,
        } });
        if (j.code !== 0) { toast(j.msg || '没拿到模型列表', 'error'); return; }
        const list = (j.data && j.data.models) || [];
        setModels(list);
        if (list.length === 1) { setForm(Object.assign({}, form, { model: list[0] })); }
        toast('已获取 ' + list.length + ' 个模型', 'success');
      } catch (e) { toast('获取失败', 'error'); }
      setBusy(false);
    };

    // ---- 人设库 ----
    const openPrompt = function (row) {
      setPromptEdit(row ? Object.assign({}, row) : { id: 0, name: '', body: '', note: '', enabled: 1, sort: 0 });
    };
    const savePrompt = async function () {
      const p = promptEdit;
      if (!p) { return; }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/prompts/save', { method: 'POST', body: {
          id: p.id, name: p.name, body: p.body, note: p.note, enabled: p.enabled, sort: p.sort,
        } });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { setPromptEdit(null); load(); }
      } catch (e) { toast('保存失败', 'error'); }
      setBusy(false);
    };
    const delPrompt = async function (row) {
      if (!window.confirm('删除人设「' + row.name + '」？\n正在用这条人设的战队会自动回落到平台默认模板，不会哑掉。')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/prompts/' + row.id + '/delete', { method: 'POST', body: {} });
        toast(j.msg || '已删除', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('删除失败', 'error'); }
      setBusy(false);
    };

    // ---- 表情包库 ----
    const uploadSticker = async function () {
      if (!stickerFile) { toast('先选一张图片', 'error'); return; }
      if (!stickerName.trim()) { toast('给这张图起个名字，AI 靠名字挑图', 'error'); return; }
      setBusy(true);
      try {
        const fd = new FormData();
        fd.append('image', stickerFile);
        fd.append('name', stickerName.trim());
        fd.append('note', stickerNote.trim());
        const j = await api('/api/platform/admin/ai/stickers/upload', { method: 'POST', form: fd });
        toast(j.msg || '已上传', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { setStickerName(''); setStickerNote(''); setStickerFile(null); load(); }
      } catch (e) { toast('上传失败', 'error'); }
      setBusy(false);
    };
    const saveSticker = async function () {
      const s = stickerEdit;
      if (!s) { return; }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/stickers/save', { method: 'POST', body: {
          id: s.id, name: s.name, note: s.note, enabled: s.enabled ? 1 : 0, sort: s.sort,
        } });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { setStickerEdit(null); load(); }
      } catch (e) { toast('保存失败', 'error'); }
      setBusy(false);
    };
    const delSticker = async function (row) {
      if (!window.confirm('删除表情包「' + row.name + '」？图片文件也会一起删掉。')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/stickers/' + row.id + '/delete', { method: 'POST', body: {} });
        toast(j.msg || '已删除', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('删除失败', 'error'); }
      setBusy(false);
    };

    const MODE_LABEL = { off: '未开启', platform: '平台模型', own: '自有模型' };
    const num = function (v) { return Number(v || 0).toLocaleString(); };

    return (
      <Box>
        <Paper sx={{ p: { xs: 2, md: 3 } }}>
          <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>QQ 机器人 · AI 聊天</Typography>
          <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: '#6e6e73', mt: 0.5 }}>
            平台提供首选模型和统一的人设提示词，并给战队发 Token；队长也可以改成自己的模型。
            AI 只聊天，不执行任何机器人指令。平台看不到战队群的聊天正文。
          </Typography>

          {!form && <Typography sx={{ fontSize: 13, color: '#6e6e73', py: 2 }}>正在取…</Typography>}
          {form && (
            <Box sx={{ mt: 2 }}>
              <FormControlLabel disabled={busy}
                control={<Switch checked={Number(form.enabled) === 1} onChange={setBool('enabled')} />}
                label={<Typography sx={{ fontSize: 13.5, color: TEXT_PRIMARY }}>
                  {Number(form.enabled) === 1 ? '平台首选模型已开放' : '平台首选模型已关闭（战队仍可用自有模型）'}
                </Typography>} />

              <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 1.5 }}>
                <TextField label="Base URL" size="small" fullWidth value={form.base_url} onChange={set('base_url')}
                  placeholder="https://api.example.com/v1" helperText="OpenAI 兼容地址，服务端会自动补 /chat/completions" />
                <TextField label="API Key" size="small" fullWidth type="password" value={form.api_key} onChange={set('api_key')}
                  placeholder={form.has_key ? '留空 = 不修改（当前 ' + form.api_key_mask + '）' : '尚未填写'} />
              </Stack>
              <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 1.5 }} alignItems={{ md: 'flex-start' }}>
                <TextField label="模型名" size="small" fullWidth value={form.model} onChange={set('model')}
                  placeholder="点右边「获取模型列表」，或直接手填"
                  helperText="大部分中转站填了 Base URL 就能自动列出来，不用记模型名" />
                <Button size="small" variant="outlined" disabled={busy} onClick={fetchModels}
                  sx={{ height: 40, flexShrink: 0, whiteSpace: 'nowrap' }}>获取模型列表</Button>
              </Stack>
              {models.length > 0 && (
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ mt: 1 }} alignItems="center">
                  <Select size="small" fullWidth displayEmpty
                    value={models.indexOf(form.model) >= 0 ? form.model : ''}
                    onChange={function (e) { setForm(Object.assign({}, form, { model: e.target.value })); }}>
                    <MenuItem value="" disabled>从拿到的 {models.length} 个模型里选一个</MenuItem>
                    {models.map(function (m) { return <MenuItem key={m} value={m}>{m}</MenuItem>; })}
                  </Select>
                  <Button size="small" variant="text" sx={{ flexShrink: 0 }}
                    onClick={function () { setModels([]); }}>收起列表</Button>
                </Stack>
              )}
              <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 1.5 }}>
                <TextField label="回复上限" size="small" type="number" sx={{ width: { xs: '100%', md: 140 } }}
                  value={form.max_tokens} onChange={set('max_tokens')} inputProps={{ min: 32, max: 300 }}
                  helperText="Token，32-300" />
                <TextField label="温度" size="small" type="number" sx={{ width: { xs: '100%', md: 120 } }}
                  value={form.temperature} onChange={set('temperature')} inputProps={{ min: 0, max: 2, step: 0.1 }} />
                <TextField label="主动上限/天" size="small" type="number" sx={{ width: { xs: '100%', md: 150 } }}
                  value={form.max_proactive_per_day} onChange={set('max_proactive_per_day')} inputProps={{ min: 0, max: 10 }}
                  helperText="0 = 全站不许主动" />
              </Stack>

              <TextField label="角色扮演提示词（平台统一，战队只读）" size="small" fullWidth multiline minRows={4}
                sx={{ mt: 1.5 }} value={form.prompt} onChange={set('prompt')}
                placeholder="留空使用内置人设" helperText="可用 {team} 战队名、{alias} 机器人称呼；保存后服务端始终追加安全硬规则" />
              <TextField label="主动发言附加提示" size="small" fullWidth multiline minRows={2}
                sx={{ mt: 1.5 }} value={form.proactive_prompt} onChange={set('proactive_prompt')}
                placeholder="仅用于定时主动发言" />

              <FormControlLabel sx={{ mt: 1 }} disabled={busy}
                control={<Switch checked={Number(form.allow_links) === 1} onChange={setBool('allow_links')} />}
                label={<Typography sx={{ fontSize: 13, color: TEXT_PRIMARY }}>允许模型回复里带链接（默认关；关掉会替换成「链接已省略」）</Typography>} />

              <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
                <Button variant="contained" size="small" disabled={busy} onClick={function () { save(); }}>保存</Button>
                <Button variant="outlined" size="small" disabled={busy} onClick={test}>测试首选模型</Button>
                <Button variant="text" size="small" disabled={busy} onClick={tick}>检查到点的主动发言</Button>
              </Stack>

              <Alert severity="info" sx={{ mt: 1.5, fontSize: 12.5 }}>
                安全硬规则：{d && d.defaults.safety}
              </Alert>
            </Box>
          )}
        </Paper>

        {d && (
          <Paper sx={{ p: { xs: 2, md: 3 }, mt: 2 }}>
            <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
              <Box sx={{ flex: 1 }}>
                <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>角色扮演人设库</Typography>
                <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: '#6e6e73', mt: 0.3 }}>
                  这里放多套人设（比如「正经队长」「沙雕网友」「战术分析师」）。站长在战队后台只能从里面挑一条，
                  挑不了上面那条统一人设以外的内容。安全硬规则永远由服务端追加，人设改不掉它。
                </Typography>
              </Box>
              <Button size="small" variant="contained" disabled={busy} onClick={function () { openPrompt(null); }}>新增人设</Button>
            </Stack>
            {d.prompts.length === 0 ? (
              <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mt: 1.5 }}>
                还没有额外人设，所有战队都用上面那条统一人设。点「新增人设」加一条，战队后台就会出现下拉。
              </Typography>
            ) : (
              <TableContainer sx={{ mt: 1.5 }}>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>名称</TableCell><TableCell>说明</TableCell>
                      <TableCell>状态</TableCell><TableCell align="right">操作</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {d.prompts.map(function (p) {
                      return (
                        <TableRow key={p.id}>
                          <TableCell><Typography variant="body2" fontWeight={600}>{p.name}</Typography></TableCell>
                          <TableCell><Typography variant="caption" color="text.secondary">{p.note || '—'}</Typography></TableCell>
                          <TableCell>
                            <Chip size="small" variant="outlined" color={Number(p.enabled) === 1 ? 'success' : 'default'}
                              label={Number(p.enabled) === 1 ? '可选用' : '已下架'} />
                          </TableCell>
                          <TableCell align="right">
                            <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                              <Button size="small" variant="outlined" disabled={busy}
                                onClick={function () { openPrompt(p); }}>编辑</Button>
                              <Button size="small" variant="outlined" color="error" disabled={busy}
                                onClick={function () { delPrompt(p); }}>删除</Button>
                            </Stack>
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
          </Paper>
        )}

        {d && (
          <Paper sx={{ p: { xs: 2, md: 3 }, mt: 2 }}>
            <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
              <Box sx={{ flex: 1 }}>
                <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>表情包库</Typography>
                <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: '#6e6e73', mt: 0.3 }}>
                  AI 回复完文字后，按战队自己设的概率追发一张。命中时把这里的「名称 + 适用场景」交给模型挑，
                  模型没挑就说不出编号，服务端随机补一张。图片只在服务端上传，QQ 群里看到的是一张真图。
                </Typography>
              </Box>
              <FormControlLabel disabled={busy} sx={{ mr: 0 }}
                control={<Switch checked={Number(form.sticker_enabled) === 1}
                  onChange={setBool('sticker_enabled')} />}
                label={<Typography sx={{ fontSize: 13, color: TEXT_PRIMARY }}>表情包总闸</Typography>} />
            </Stack>

            <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 1.5 }} alignItems={{ md: 'flex-start' }}>
              <TextField label="表情包名称" size="small" sx={{ width: { xs: '100%', md: 200 } }}
                value={stickerName} onChange={function (e) { setStickerName(e.target.value); }}
                placeholder="例如 点赞 / 无语" helperText="AI 就是看这个名字挑图的" />
              <TextField label="适用场景（可选）" size="small" fullWidth
                value={stickerNote} onChange={function (e) { setStickerNote(e.target.value); }}
                placeholder="例如 夸人时用 / 对方说了离谱的话时用" />
              <Button variant="outlined" size="small" component="label" disabled={busy}
                sx={{ height: 40, flexShrink: 0, whiteSpace: 'nowrap' }}>
                {stickerFile ? '已选：' + stickerFile.name : '选择图片'}
                <input type="file" hidden accept="image/jpeg,image/png,image/gif,image/webp"
                  onChange={function (e) {
                    setStickerFile(e.target.files && e.target.files[0] ? e.target.files[0] : null);
                  }} />
              </Button>
              <Button variant="contained" size="small" disabled={busy} sx={{ height: 40, flexShrink: 0 }}
                onClick={uploadSticker}>上传</Button>
            </Stack>
            <Typography sx={{ fontSize: 11.5, color: TEXT_SECONDARY, mt: 0.5 }}>
              jpg / png / gif / webp，单张不超过 2MB。建议用正方形小图。
            </Typography>

            {d.stickers.length === 0 ? (
              <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mt: 1.5 }}>
                库里还没有表情包。战队把概率调多高都不会有图，先传几张。
              </Typography>
            ) : (
              <TableContainer sx={{ mt: 1.5 }}>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>预览</TableCell><TableCell>名称</TableCell><TableCell>适用场景</TableCell>
                      <TableCell>已发</TableCell><TableCell>状态</TableCell><TableCell align="right">操作</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {d.stickers.map(function (s) {
                      return (
                        <TableRow key={s.id}>
                          <TableCell sx={{ width: 72 }}>
                            <Box component="img" src={s.path} alt={s.name}
                              sx={{ width: 56, height: 56, objectFit: 'cover', borderRadius: 1.5,
                                border: '1px solid ' + BORDER, display: 'block' }} />
                          </TableCell>
                          <TableCell><Typography variant="body2" fontWeight={600}>{s.name}</Typography></TableCell>
                          <TableCell><Typography variant="caption" color="text.secondary">{s.note || '—'}</Typography></TableCell>
                          <TableCell><Typography variant="caption">{num(s.sent_count)}</Typography></TableCell>
                          <TableCell>
                            <Chip size="small" variant="outlined" color={Number(s.enabled) === 1 ? 'success' : 'default'}
                              label={Number(s.enabled) === 1 ? '可发' : '停用'} />
                          </TableCell>
                          <TableCell align="right">
                            <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                              <Button size="small" variant="outlined" disabled={busy}
                                onClick={function () { setStickerEdit(Object.assign({}, s)); }}>编辑</Button>
                              <Button size="small" variant="outlined" color="error" disabled={busy}
                                onClick={function () { delSticker(s); }}>删除</Button>
                            </Stack>
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
            <Typography sx={{ fontSize: 11.5, color: TEXT_SECONDARY, mt: 1 }}>
              改了总闸要记得点上面那张卡片的「保存」才会生效。
            </Typography>
          </Paper>
        )}

        {d && (
          <Paper sx={{ p: { xs: 2, md: 3 }, mt: 2 }}>
            <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>各战队余额与用量</Typography>
            <Stack direction="row" spacing={1} sx={{ mt: 1, flexWrap: 'wrap', useFlexGap: true }}>
              <Chip size="small" label={'近 7 天调用 ' + num(d.stats.calls_7d)} sx={{ bgcolor: BG_GRAY, fontSize: 12 }} />
              <Chip size="small" label={'近 7 天 Token ' + num(d.stats.tokens_7d)} sx={{ bgcolor: BG_GRAY, fontSize: 12 }} />
              <Chip size="small" label={'平台模型 ' + num(d.stats.platform_tokens_7d)} sx={{ bgcolor: BG_GRAY, fontSize: 12 }} />
              <Chip size="small" label={'自有模型 ' + num(d.stats.own_tokens_7d)} sx={{ bgcolor: BG_GRAY, fontSize: 12 }} />
              <Chip size="small" label={'失败 ' + num(d.stats.fail_7d)} color={d.stats.fail_7d > 0 ? 'warning' : 'default'} variant="outlined" />
              <Chip size="small" label={'排队 ' + num(d.stats.pending_jobs)} variant="outlined" />
            </Stack>
            <TableContainer sx={{ mt: 1.5 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>战队</TableCell><TableCell>模式</TableCell><TableCell>余额</TableCell>
                    <TableCell>今日</TableCell><TableCell>状态</TableCell><TableCell>操作</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {d.tenants.map(function (t) {
                    return (
                      <TableRow key={t.id}>
                        <TableCell>
                          <Typography variant="body2" fontWeight={600}>{t.name}</Typography>
                          <Typography variant="caption" color="text.secondary">/{t.slug}/ · {t.status === 'approved' ? '已上线' : t.status}</Typography>
                        </TableCell>
                        <TableCell><Chip size="small" variant="outlined" label={MODE_LABEL[t.mode] || t.mode} /></TableCell>
                        <TableCell>
                          <Typography variant="body2">{num(t.balance)}</Typography>
                          <Typography variant="caption" color="text.secondary">累计发 {num(t.token_granted)} / 用 {num(t.token_used)}</Typography>
                        </TableCell>
                        <TableCell>
                          <Typography variant="caption">{num(t.daily_used)}{t.daily_limit > 0 ? ' / ' + num(t.daily_limit) : '（不限）'}</Typography>
                        </TableCell>
                        <TableCell>
                          {Number(t.platform_disabled) === 1
                            ? <Chip size="small" color="error" variant="outlined" label="平台停用" />
                            : (t.last_error
                              ? <Tooltip title={t.last_error}><Chip size="small" color="warning" variant="outlined" label="最近失败" /></Tooltip>
                              : <Chip size="small" color="success" variant="outlined" label="正常" />)}
                        </TableCell>
                        <TableCell>
                          <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                            <Button size="small" variant="outlined" disabled={busy}
                              onClick={function () { setGrantT(t); setGrantAmount('5000'); setGrantNote(''); }}>充值</Button>
                            <Button size="small" variant="outlined" disabled={busy} onClick={function () { doReset(t); }}>清零</Button>
                            <Button size="small" variant="outlined" color={Number(t.platform_disabled) === 1 ? 'primary' : 'error'}
                              disabled={busy} onClick={function () { doDisable(t); }}>
                              {Number(t.platform_disabled) === 1 ? '恢复' : '停用'}
                            </Button>
                          </Stack>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          </Paper>
        )}

        {d && d.usage.length > 0 && (
          <Paper sx={{ p: { xs: 2, md: 3 }, mt: 2 }}>
            <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>最近调用（不含聊天正文）</Typography>
            <TableContainer sx={{ mt: 1 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>时间</TableCell><TableCell>战队</TableCell><TableCell>类型</TableCell>
                    <TableCell>来源</TableCell><TableCell>模型</TableCell><TableCell>Token</TableCell><TableCell>结果</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {d.usage.map(function (u, i) {
                    return (
                      <TableRow key={i}>
                        <TableCell>{fmtTime(u.created_at)}</TableCell>
                        <TableCell>{u.tenant_name}</TableCell>
                        <TableCell>{u.kind}</TableCell>
                        <TableCell>{u.source === 'platform' ? '平台' : '自有'}</TableCell>
                        <TableCell>{u.model}</TableCell>
                        <TableCell>{num(u.total_tokens)}</TableCell>
                        <TableCell>
                          <Chip size="small" variant="outlined" color={u.status === 'ok' ? 'success' : 'warning'} label={u.status} />
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          </Paper>
        )}

        <Dialog open={!!grantT} onClose={function () { setGrantT(null); }} fullWidth maxWidth="xs">
          <DialogTitle>给「{grantT && grantT.name}」充值 Token</DialogTitle>
          <DialogContent>
            <Typography sx={{ fontSize: 12.5, color: '#6e6e73', mb: 1.5 }}>
              当前余额 {grantT ? num(grantT.balance) : '-'}。一次性余额，用完不会自动续；
              清零只归零余额，历史消耗记录保留。队长用自有模型时不扣这里的余额。
            </Typography>
            <TextField label="充值数量" type="number" size="small" fullWidth value={grantAmount}
              onChange={function (e) { setGrantAmount(e.target.value); }} inputProps={{ min: 1 }} autoFocus />
            <TextField label="备注（可选）" size="small" fullWidth sx={{ mt: 1.5 }} value={grantNote}
              onChange={function (e) { setGrantNote(e.target.value); }} inputProps={{ maxLength: 200 }} />
          </DialogContent>
          <DialogActions>
            <Button onClick={function () { setGrantT(null); }}>取消</Button>
            <Button variant="contained" disabled={busy} onClick={doGrant}>确认充值</Button>
          </DialogActions>
        </Dialog>
        <Dialog open={!!promptEdit} onClose={function () { setPromptEdit(null); }} fullWidth maxWidth="sm">
          <DialogTitle>{promptEdit && promptEdit.id ? '编辑人设' : '新增人设'}</DialogTitle>
          <DialogContent>
            <TextField label="名称" size="small" fullWidth sx={{ mt: 1 }}
              value={(promptEdit && promptEdit.name) || ''}
              onChange={function (e) { setPromptEdit(Object.assign({}, promptEdit, { name: e.target.value })); }}
              placeholder="例如 沙雕网友" inputProps={{ maxLength: 32 }} autoFocus />
            <TextField label="提示词正文" size="small" fullWidth multiline minRows={7} sx={{ mt: 1.5 }}
              value={(promptEdit && promptEdit.body) || ''}
              onChange={function (e) { setPromptEdit(Object.assign({}, promptEdit, { body: e.target.value })); }}
              placeholder="你是「{team}」战队群里的群助手「{alias}」…"
              helperText="可用 {team} 战队名、{alias} 机器人称呼；最多 4000 字，安全硬规则由服务端自动追加" />
            <TextField label="给队长看的一句说明（可选）" size="small" fullWidth sx={{ mt: 1.5 }}
              value={(promptEdit && promptEdit.note) || ''}
              onChange={function (e) { setPromptEdit(Object.assign({}, promptEdit, { note: e.target.value })); }}
              placeholder="例如 说话像老油条，爱开玩笑"
              inputProps={{ maxLength: 200 }} />
            <Stack direction="row" spacing={1.5} sx={{ mt: 1.5 }} alignItems="center">
              <TextField label="排序" size="small" type="number" sx={{ width: 120 }}
                value={(promptEdit && promptEdit.sort) || 0}
                onChange={function (e) { setPromptEdit(Object.assign({}, promptEdit, { sort: e.target.value })); }} />
              <FormControlLabel
                control={<Switch checked={!!promptEdit && Number(promptEdit.enabled) === 1}
                  onChange={function (e) { setPromptEdit(Object.assign({}, promptEdit, { enabled: e.target.checked ? 1 : 0 })); }} />}
                label={<Typography sx={{ fontSize: 13 }}>让战队可选</Typography>} />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={function () { setPromptEdit(null); }}>取消</Button>
            <Button variant="contained" disabled={busy} onClick={savePrompt}>保存人设</Button>
          </DialogActions>
        </Dialog>

        <Dialog open={!!stickerEdit} onClose={function () { setStickerEdit(null); }} fullWidth maxWidth="xs">
          <DialogTitle>编辑表情包</DialogTitle>
          <DialogContent>
            {stickerEdit && (
              <Box component="img" src={stickerEdit.path} alt={stickerEdit.name}
                sx={{ width: 96, height: 96, objectFit: 'cover', borderRadius: 2,
                  border: '1px solid ' + BORDER, display: 'block', mt: 1 }} />
            )}
            <TextField label="名称" size="small" fullWidth sx={{ mt: 1.5 }}
              value={(stickerEdit && stickerEdit.name) || ''}
              onChange={function (e) { setStickerEdit(Object.assign({}, stickerEdit, { name: e.target.value })); }}
              inputProps={{ maxLength: 32 }} />
            <TextField label="适用场景" size="small" fullWidth sx={{ mt: 1.5 }}
              value={(stickerEdit && stickerEdit.note) || ''}
              onChange={function (e) { setStickerEdit(Object.assign({}, stickerEdit, { note: e.target.value })); }}
              inputProps={{ maxLength: 200 }} />
            <Stack direction="row" spacing={1.5} sx={{ mt: 1.5 }} alignItems="center">
              <TextField label="排序" size="small" type="number" sx={{ width: 120 }}
                value={(stickerEdit && stickerEdit.sort) || 0}
                onChange={function (e) { setStickerEdit(Object.assign({}, stickerEdit, { sort: e.target.value })); }} />
              <FormControlLabel
                control={<Switch checked={!!stickerEdit && Number(stickerEdit.enabled) === 1}
                  onChange={function (e) { setStickerEdit(Object.assign({}, stickerEdit, { enabled: e.target.checked ? 1 : 0 })); }} />}
                label={<Typography sx={{ fontSize: 13 }}>可以发</Typography>} />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={function () { setStickerEdit(null); }}>取消</Button>
            <Button variant="contained" disabled={busy} onClick={saveSticker}>保存</Button>
          </DialogActions>
        </Dialog>
      </Box>
    );
  }

  /**
   * v5.36.32：已销毁站点登记（tenant_purges）+ 备份包下载。
   * 这一页只列「已经删掉的」，不提供任何再删一次的入口；备份包不在就如实说不在了。
   */
  function PlatformPurged() {
    const [list, setList] = useState(null);
    const [tip, setTip] = useState('');

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/purged');
        if (json.code !== 0) { setList([]); setTip(json.msg || '读不到销毁登记'); return; }
        setTip(json.data.available ? '' : '这一版的库还没补（缺 tenant_purges 表），先执行升级脚本');
        setList(json.data.list || []);
      } catch (e) { setList([]); setTip('这一页没读通：刷新再看一次'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    if (list === null) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;
    return (
      <Box>
        {tip && <Alert severity="warning" sx={{ mb: 1 }}>{tip}</Alert>}
        <Alert severity="info" sx={{ mb: 1 }}>
          备份包里是那一轮的整份 SQL、图片与站点配置，要恢复就照包里的 MANIFEST 手动导回。
          包存在服务器的 runtime/data_tasks 里，站点本身已经删干净了，这里只是台账。
        </Alert>
        {list.length === 0 ? <EmptyBox text="还没有站点被销毁过" /> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>站点</TableCell><TableCell>销毁时间</TableCell><TableCell>当时成员</TableCell>
                  <TableCell>删了多少行</TableCell><TableCell>删了多少图</TableCell>
                  <TableCell>经手人</TableCell><TableCell>备份包</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {list.map(function (r) {
                  return (
                    <TableRow key={r.id}>
                      <TableCell>
                        <Typography variant="body2" fontWeight={600}>{r.name}</Typography>
                        <Typography variant="caption" color="text.secondary">/{r.slug}/ · ID {r.tenant_id}</Typography>
                      </TableCell>
                      <TableCell>{fmtTime(r.created_at)}</TableCell>
                      <TableCell>{r.member_count}</TableCell>
                      <TableCell>{r.row_count}</TableCell>
                      <TableCell>{r.file_count} 张 / {fmtBytes(r.bytes)}</TableCell>
                      <TableCell>
                        {r.actor_name || '-'}
                        {Number(r.banned_days) > 0 && (
                          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                            封了 {r.banned_days} 天{r.banned_at ? '（' + fmtTime(r.banned_at) + ' 起）' : ''}
                          </Typography>
                        )}
                      </TableCell>
                      <TableCell>
                        {!r.backup_file ? <Typography variant="caption" color="text.secondary">没登记包名</Typography>
                          : (
                            <Stack spacing={0.2}>
                              <Typography variant="caption" sx={{ fontFamily: 'ui-monospace, Menlo, monospace', maxWidth: 170, display: 'block', wordBreak: 'break-all' }}>
                                {r.backup_file}
                              </Typography>
                              <Typography variant="caption" color="text.secondary">{fmtBytes(r.backup_bytes)}</Typography>
                              {r.backup_alive
                                ? <Button size="small" variant="outlined" sx={{ alignSelf: 'flex-start', minWidth: 0 }}
                                    onClick={function () {
                                      window.location.href = '/api/platform/admin/purged/download?file='
                                        + encodeURIComponent(r.backup_file);
                                    }}>下载备份</Button>
                                : <Chip size="small" variant="outlined" color="warning" label="包已经不在了"
                                    sx={{ height: 20, fontSize: 11, alignSelf: 'flex-start' }} />}
                            </Stack>
                          )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}
      </Box>
    );
  }

  function SuperConsole({ tab, onPending, onGov }) {
    const [apps, setApps] = useState(null);
    const [tenants, setTenants] = useState(null);
    const [rejectApp, setRejectApp] = useState(null);
    const [reason, setReason] = useState('');
    // v5.1.0：配额修改 / 发通知 弹窗
    const [quotaT, setQuotaT] = useState(null);
    const [quotaGb, setQuotaGb] = useState('');
    const [notifyT, setNotifyT] = useState(null);
    const [noticeText, setNoticeText] = useState('');
    // v5.16.0：巡查台账弹窗（{t, token, quota}），令牌一次性，只用于首条请求兑票
    const [inspectBox, setInspectBox] = useState(null);
    // v5.36.32：销毁站点弹窗。{t, asking, pre, token, left, slug, typed, busy, err, done}
    // 确认码只能由「预览」这一步发（服务端绑死这个会话），所以没走到那一步就没有删这条路。
    const [purgeBox, setPurgeBox] = useState(null);

    const loadApps = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/applications');
        setApps(json.data.list);
      } catch (e) { setApps([]); }
    }, []);
    const loadTenants = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/tenants');
        setTenants(json.data.list);
      } catch (e) { setTenants([]); }
    }, []);
    useEffect(function () { loadApps(); loadTenants(); }, [loadApps, loadTenants]);
    // 待审数交给外壳，侧栏「入驻申请」那行才亮得上红点
    useEffect(function () {
      if (apps && onPending) {
        onPending(apps.filter(function (a) { return a.status === 'pending'; }).length);
      }
    }, [apps, onPending]);
    // v5.32.0：侧栏「私聊治理」那行的红点 = 待结案的私聊举报（大厅举报那一半跟着大厅一起没了）
    // 数是从举报接口自己带的 counts 取的，与切进这一页看到的列表同一个口径，不会两处不一样
    const [gov, setGov] = useState(0);
    const loadGov = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/chat/reports?status=pending&size=1');
        if (json.code === 0) {
          const c = (json.data && json.data.counts) || {};
          setGov((c.pending || 0) + (c.opened || 0));
        }
      } catch (e) { /* 红点亮不起来不挡治理 */ }
    }, []);
    useEffect(function () { loadGov(); }, [tab, loadGov]);
    useEffect(function () {
      window.addEventListener('yys-gov-badge', loadGov);
      return function () { window.removeEventListener('yys-gov-badge', loadGov); };
    }, [loadGov]);
    useEffect(function () { if (onGov) { onGov(gov); } }, [gov, onGov]);

    const approve = async function (a) {
      if (!window.confirm('通过「' + a.name + '」的入驻申请？\n将开通站点目录 /' + a.slug + '/ 并创建战队超管账号')) return;
      try {
        const json = await api('/api/platform/admin/applications/' + a.id + '/approve', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); loadApps(); loadTenants(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const doReject = async function () {
      if (!reason.trim()) { toast('请填写驳回原因', 'error'); return; }
      try {
        const json = await api('/api/platform/admin/applications/' + rejectApp.id + '/reject',
          { method: 'POST', body: { reason: reason.trim() } });
        if (json.code === 0) { toast(json.msg, 'success'); setRejectApp(null); setReason(''); loadApps(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // 封禁 / 恢复租户
    const toggleTenant = async function (t) {
      const next = t.status === 'approved' ? 'banned' : 'approved';
      const tip = next === 'banned'
        ? '封禁「' + t.name + '」？封禁后该站点目录访问将显示封禁提示页'
        : '恢复「' + t.name + '」？恢复后该站点可正常访问';
      if (!window.confirm(tip)) return;
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/status', { method: 'POST', body: { status: next } });
        if (json.code === 0) { toast(json.msg, 'success'); loadTenants(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.1.0：修改存储配额
    const saveQuota = async function () {
      const gb = parseFloat(quotaGb);
      if (isNaN(gb) || gb < 0) { toast('请输入合法的 GB 数（0 = 恢复默认 2GB）', 'error'); return; }
      try {
        const json = await api('/api/platform/admin/tenants/' + quotaT.id + '/quota', { method: 'POST', body: { quota_gb: gb } });
        if (json.code === 0) { toast(json.msg, 'success'); setQuotaT(null); loadTenants(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.1.0：隐身巡查——拿签名入口直接新窗口打开（只读）
    // v5.16.0：入口有效期收到 30 分钟，且巡查期间的每一次读取都会写入该站点操作日志
    const stealthView = async function (t) {
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/stealth', { method: 'POST' });
        if (json.code === 0) {
          toast('隐身入口已生成（30 分钟内有效、仅一次）。巡查期间的读取会写入该站点操作日志，站长可见', 'info');
          window.open(json.data.url, '_blank');
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.16.0：巡查台账——就地读取该站点的后台台账（成员全量资料 / 待审核 / 黑名单 / 找回申请 /
    // 操作日志 / 图片 / 战绩 / 约战）。凭据是同一次签发的巡查会话 cookie，逐次落审计。
    const openInspect = async function (t) {
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/stealth', { method: 'POST' });
        if (json.code !== 0) { toast(json.msg, 'error'); return; }
        setInspectBox({ t: t, token: json.data.token, quota: json.data.quota });
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.16.0：一键撤销——清掉该站点全部巡查登记（未兑票的链接 + 正在用的会话）
    const revokeStealth = async function (t) {
      if (!window.confirm('撤销「' + t.name + '」的全部隐身巡查入口与在用巡查会话？\n已签发的链接立即失效，正在巡查的浏览器下一步读取就会被拒')) return;
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/stealth-revoke', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); loadTenants(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.36.13：进入平台协作——队长授权后才可用，一次性票据 5 分钟有效
    const enterSupport = async function (t) {
      if (!t.support_granted) {
        toast('该战队队长尚未授权平台协作，请让队长登录后在弹窗点「同意授权」', 'warning');
        return;
      }
      if (!window.confirm('以「' + t.name + '」战队超管身份进入管理中心？\n\n'
        + '· 协作期间只能操作管理中心\n'
        + '· 每一步管理操作都会写入该站操作日志\n'
        + '· 队长可随时撤销授权，撤销后立即失效')) return;
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/support', { method: 'POST' });
        if (json.code === 0) {
          location.href = json.data.url;
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.36.32：确认码是一枚 5 分钟就过期的东西，页面上要跟着数，别让人拿着作废的码反复点
    useEffect(function () {
      if (!purgeBox || !purgeBox.token) { return; }
      const timer = setInterval(function () {
        setPurgeBox(function (p) {
          if (!p || !p.token) { return p; }
          return Object.assign({}, p, { left: Math.max(0, Number(p.left || 0) - 1) });
        });
      }, 1000);
      return function () { clearInterval(timer); };
    }, [purgeBox && purgeBox.token]);

    const openPurge = async function (t) {
      setPurgeBox({ t: t, asking: true });
      let json = null;
      try {
        json = await api('/api/platform/admin/tenants/' + t.id + '/purge-preview', { method: 'POST' });
      } catch (e) {
        setPurgeBox({ t: t, asking: false, err: '预览这一步没走通：刷新这一页再试一次（没有确认码本来就删不了）' });
        return;
      }
      if (!json || json.code !== 0) {
        setPurgeBox({ t: t, asking: false, err: (json && json.msg) || '预览失败，销毁中止' });
        return;
      }
      const d = json.data || {};
      setPurgeBox({
        t: t, asking: false, pre: d.preview || {}, token: String(d.token || ''),
        left: Number(d.expires_in || 0), slug: String(d.confirm_slug || ''),
        days: Number(d.days || 0), after: Number(d.after_days || 15),
        typed: '', busy: false, err: '',
      });
    };

    const doPurge = async function () {
      const b = purgeBox;
      if (!b || !b.token || b.busy) { return; }
      if (b.typed !== b.slug) { toast('要把站点目录名逐字打一遍：' + b.slug, 'error'); return; }
      setPurgeBox(Object.assign({}, b, { busy: true }));
      let json = null;
      try {
        json = await api('/api/platform/admin/tenants/' + b.t.id + '/purge', {
          method: 'POST', body: { token: b.token, confirm_slug: b.typed },
        });
      } catch (e) {
        // 请求没回来不等于没删：先把列表拉一遍，别让这一页停在一个已经不存在的站点上
        setPurgeBox(null);
        loadTenants();
        toast('这一步没走通。列表已刷新——上面那行里还有这个站点，就是没删成（确认码已作废，要重来一遍预览）', 'error');
        return;
      }
      if (!json || json.code !== 0) {
        setPurgeBox(Object.assign({}, b, { busy: false, token: '', left: 0, typed: '',
          err: (json && json.msg) || '销毁中止（确认码过期或现状变了，重新预览一次再看）' }));
        loadTenants();
        return;
      }
      const st = json.data || {};
      setPurgeBox({ t: b.t, asking: false, done: json.msg || '已销毁',
        stat: { rows: st.rows, tables: st.tables, files: st.files, bytes: st.file_bytes, backup: st.backup } });
      loadTenants();
      toast(json.msg || '已销毁', 'success');
    };

    // v5.1.0：给战队管理员发通知
    const sendNotice = async function () {
      if (!noticeText.trim()) { toast('请填写通知内容', 'error'); return; }
      try {
        const json = await api('/api/platform/admin/tenants/' + notifyT.id + '/notify',
          { method: 'POST', body: { content: noticeText.trim() } });
        if (json.code === 0) { toast(json.msg, 'success'); setNotifyT(null); setNoticeText(''); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const STATUS_CHIP = {
      pending: ['审核中', 'warning'], approved: ['已开通', 'success'],
      rejected: ['已驳回', 'error'], banned: ['已封禁', 'error'],
      paused: ['已暂停', 'warning'], transferring: ['转移中', 'info'],
    };

    return (
      <Box>
        {tab === 'showcase' && <PlatShowcase />}
        {tab === 'chat' && <PlatChatGov />}
        {tab === 'apps' && (
          apps === null ? <LoadingBox />
          : apps.length === 0 ? <EmptyBox text="暂无入驻申请" /> : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>战队</TableCell><TableCell>申请人</TableCell><TableCell>站点目录</TableCell>
                    <TableCell>超管账号</TableCell><TableCell>截图 / logo</TableCell>
                    <TableCell>状态</TableCell><TableCell>申请时间</TableCell><TableCell>操作</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {apps.map(function (a) {
                    const chip = STATUS_CHIP[a.status] || [a.status, 'default'];
                    return (
                      <TableRow key={a.id}>
                        <TableCell>
                          <Typography variant="body2" fontWeight={600}>{a.name}</Typography>
                          {a.description && <Typography variant="caption" color="text.secondary" sx={{ display: 'block', maxWidth: 180 }}>{a.description}</Typography>}
                        </TableCell>
                        <TableCell>{a.applicant_name}</TableCell>
                        <TableCell>/{a.slug}/</TableCell>
                        <TableCell>{a.admin_username}</TableCell>
                        <TableCell>
                          <Stack direction="row" spacing={0.8}>
                            {a.screenshot && <Box component="img" src={a.screenshot} alt="截图"
                              sx={{ width: 64, height: 48, objectFit: 'cover', borderRadius: '6px', cursor: 'zoom-in' }}
                              onClick={() => window.open(a.screenshot, '_blank')} />}
                            {a.logo && <Box component="img" src={a.logo} alt="logo"
                              sx={{ width: 48, height: 48, objectFit: 'cover', borderRadius: '6px', cursor: 'zoom-in' }}
                              onClick={() => window.open(a.logo, '_blank')} />}
                          </Stack>
                        </TableCell>
                        <TableCell>
                          <Chip size="small" label={chip[0]} color={chip[1]} variant={a.status === 'pending' ? 'filled' : 'outlined'} />
                          {a.status === 'rejected' && a.reject_reason && (
                            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', maxWidth: 140 }}>{a.reject_reason}</Typography>
                          )}
                        </TableCell>
                        <TableCell>{fmtTime(a.created_at)}</TableCell>
                        <TableCell>
                          {a.status === 'pending' ? (
                            <Stack direction="row" spacing={0.8}>
                              <Button size="small" variant="contained" onClick={() => approve(a)}>通过</Button>
                              <Button size="small" variant="outlined" color="error" onClick={() => { setRejectApp(a); setReason(''); }}>驳回</Button>
                            </Stack>
                          ) : '-'}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )
        )}

        {tab === 'tenants' && (
          tenants === null ? <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>
          : tenants.length === 0 ? <Alert severity="info">暂无已开通站点</Alert> : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>ID</TableCell><TableCell>战队</TableCell><TableCell>站点目录</TableCell>
                    <TableCell>申请人</TableCell><TableCell>成员</TableCell><TableCell>存储</TableCell>
                    <TableCell>状态</TableCell><TableCell>封禁与销毁</TableCell><TableCell>巡查</TableCell><TableCell>平台协作</TableCell><TableCell>操作</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {tenants.map(function (t) {
                    const chip = STATUS_CHIP[t.status] || [t.status, 'default'];
                    const pct = t.storage_quota > 0 ? Math.round(t.storage_used / t.storage_quota * 100) : 0;
                    return (
                      <TableRow key={t.id}>
                        <TableCell>{t.id}</TableCell>
                        <TableCell>
                          <Typography variant="body2" fontWeight={600}>{t.name}</Typography>
                          <Typography variant="caption" color="text.secondary">超管：{t.admin_username}</Typography>
                          {t.transfer && (
                            <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>
                              {t.transfer.status === 'cooling'
                                ? '转移冷静期至 ' + fmtTime(t.transfer.cooling_until)
                                : '转移待 ' + t.transfer.to_name + ' 确认'}
                            </Typography>
                          )}
                        </TableCell>
                        <TableCell><Link href={t.url} target="_blank" underline="hover">{t.url}</Link></TableCell>
                        <TableCell>{t.applicant_name}</TableCell>
                        <TableCell>{t.member_count}</TableCell>
                        <TableCell>
                          <Typography variant="caption">
                            {fmtBytes(t.storage_used)} / {fmtBytes(t.storage_quota)}（{pct}%）
                          </Typography>
                          <Button size="small" sx={{ display: 'block', p: 0, minWidth: 0 }}
                            onClick={() => { setQuotaT(t); setQuotaGb(t.storage_quota > 0 ? (t.storage_quota / 1073741824).toString() : ''); }}>
                            修改配额
                          </Button>
                        </TableCell>
                        <TableCell><Chip size="small" label={chip[0]} color={chip[1]} variant="outlined" /></TableCell>
                        {/* v5.36.32：封禁起点与销毁倒计时。这一列只有平台超管这一页读得到，战队侧看不到，也不往外给对面显示 */}
                        <TableCell>
                          {!t.purge ? <Typography variant="caption" color="text.secondary">这一版还没这一项</Typography>
                            : !t.purge.banned ? <Typography variant="caption" color="text.secondary">未封禁</Typography>
                            : (
                              <Stack spacing={0.2}>
                                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', whiteSpace: 'nowrap' }}>
                                  {t.purge.banned_at ? '封于 ' + t.purge.banned_at : '没有封禁起点'}
                                </Typography>
                                {t.purge.ready
                                  ? <Chip size="small" color="error" variant="outlined"
                                      label={'满 ' + t.purge.days + ' 天，可销毁'}
                                      sx={{ height: 20, fontSize: 11, alignSelf: 'flex-start' }} />
                                  : <Typography variant="caption" color="warning.main" sx={{ display: 'block', maxWidth: 130 }}>
                                      {t.purge.reason}
                                    </Typography>}
                              </Stack>
                            )}
                        </TableCell>
                        {/* v5.16.0：活跃巡查登记 = 未兑票的入口 + 正在使用的巡查会话，>0 时建议撤销一次 */}
                        <TableCell>
                          {t.stealth_active > 0
                            ? <Chip size="small" color="warning" variant="outlined" label={'活跃登记 ' + t.stealth_active} />
                            : <Typography variant="caption" color="text.secondary">无</Typography>}
                        </TableCell>
                        {/* v5.36.13：队长授权状态 + 在用协作会话数 */}
                        <TableCell>
                          {t.support_granted ? (
                            <Stack spacing={0.3}>
                              <Chip size="small" color="success" variant="outlined" label="已授权" />
                              {t.support_granted_at && (
                                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', maxWidth: 130 }}>
                                  {fmtTime(t.support_granted_at)}
                                </Typography>
                              )}
                              {t.support_sessions > 0 && (
                                <Typography variant="caption" color="warning.main">
                                  在用 {t.support_sessions} 个会话
                                </Typography>
                              )}
                            </Stack>
                          ) : (
                            <Chip size="small" variant="outlined" label="未授权" />
                          )}
                        </TableCell>
                        <TableCell>
                          <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                            {t.status === 'approved'
                              ? <Button size="small" variant="outlined" color="error" onClick={() => toggleTenant(t)}>封禁</Button>
                              : <Button size="small" variant="outlined" onClick={() => toggleTenant(t)}>恢复</Button>}
                            <Button size="small" variant="outlined" disabled={!t.support_granted}
                              onClick={() => enterSupport(t)}>进入协作</Button>
                            <Button size="small" variant="outlined" onClick={() => stealthView(t)}>前台浏览</Button>
                            <Button size="small" variant="outlined" onClick={() => openInspect(t)}>巡查台账</Button>
                            <Button size="small" variant="outlined" color="warning" onClick={() => revokeStealth(t)}>撤销巡查</Button>
                            <Button size="small" variant="outlined" onClick={() => { setNotifyT(t); setNoticeText(''); }}>发通知</Button>
                            {/* v5.36.32：销毁入口一直摆在这儿，不够 15 天就点不动，禁用态写清为什么（不把人挡在外面还不知道为什么） */}
                            <Tooltip title={t.purge ? (t.purge.ready ? '封满 15 天，可以销毁' : t.purge.reason) : '这一版的库还没补，先执行升级脚本'}>
                              <Box component="span" sx={{ display: 'inline-block' }}>
                                <Button size="small" variant="outlined" color="error"
                                  disabled={!(t.purge && t.purge.ready)}
                                  onClick={() => openPurge(t)}>销毁站点</Button>
                              </Box>
                            </Tooltip>
                          </Stack>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )
        )}

        {tab === 'rotation' && <PlatformRotation />}

        {tab === 'battles' && <PlatformBattles />}

        {tab === 'tournament' && <PlatformTournament />}

        {tab === 'datatasks' && <PlatformDataTasks />}

        {tab === 'bot' && <PlatformBot />}
        {tab === 'ai' && <PlatAi />}

        {/* v5.10.0：SEO 与合规（拆分模块，由 compliance-admin.jsx 注入 window.PlatformCompliance）*/}
        {tab === 'compliance' && (
          typeof window.PlatformCompliance === 'function'
            ? React.createElement(window.PlatformCompliance)
            : <Alert severity="warning">合规面板未加载，请确认 compliance-admin.jsx 已部署并强制刷新（Ctrl+F5）</Alert>
        )}

        {/* v5.16.0：巡查记录——隐身巡查通道（/api/inspect/*）的逐次读取留痕，跨站点汇总 */}
        {tab === 'audits' && <PlatformStealthAudits />}

        {/* v5.17.0：登录日志（我自己 + 各战队管理员） */}
        {tab === 'loginlogs' && <PlatformLoginLogs />}

        {/* v5.17.0：日志清理（按截止日期清各战队日志，巡查审计默认保留） */}
        {tab === 'logclean' && <PlatformLogCleanup />}


        {/* v5.36.6：未晞通行证（额外登录方式；密码登录保留） */}
        {tab === 'passport' && <PlatPassport />}
        {tab === 'mail' && <PlatformMailBlast />}
        {/* v5.36.31：强制平台公告（登录后挡在界面最前面那一条） */}
        {tab === 'notice' && <PlatNotice />}
        {tab === 'remind' && <PlatRemind />}
        {/* v5.35.0：社区论坛对接（总闸 + 按站点停用 + 谁在调） */}
        {tab === 'forumapi' && <PlatForumApi />}
        {tab === 'active' && <PlatActive />}
        {/* v5.36.32：已销毁站点台账与备份包 */}
        {tab === 'purged' && <PlatformPurged />}

        {/* v5.36.32：销毁战队站点。第二步要逐字打回目录名，且确认码是上一步刚发的 */}
        <Dialog open={!!purgeBox} onClose={function () { if (!purgeBox || !purgeBox.busy) { setPurgeBox(null); } }}
          fullWidth maxWidth="sm">
          <DialogTitle>销毁站点「{purgeBox && purgeBox.t ? purgeBox.t.name : ''}」？</DialogTitle>
          <DialogContent dividers>
            {purgeBox && purgeBox.asking && (
              <Box sx={{ textAlign: 'center', py: 3 }}><CircularProgress /></Box>
            )}
            {purgeBox && purgeBox.done && (
              <Box>
                <Alert severity="success" sx={{ mb: 1 }}>{purgeBox.done}</Alert>
                <Typography sx={{ fontSize: 13, lineHeight: '22px' }}>
                  删掉 {purgeBox.stat.rows} 行（{purgeBox.stat.tables} 张表）、图片 {purgeBox.stat.files} 张（{fmtBytes(purgeBox.stat.bytes)}）。<br />
                  备份包 <Box component="span" sx={{ fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 12 }}>{purgeBox.stat.backup}</Box> 已经存好，
                  在左边「已销毁站点」那一页能下载。<br />
                  跟别队的约战留着，对面那一侧的队名显示成「已注销战队」。
                </Typography>
              </Box>
            )}
            {purgeBox && purgeBox.err && !purgeBox.pre && (
              <Box>
                <Alert severity="warning">{purgeBox.err}</Alert>
                <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mt: 1 }}>
                  没走到「预览」这一步就没有确认码，也就删不了——这一颗钮按下去不会伤到任何数据。
                </Typography>
              </Box>
            )}
            {purgeBox && purgeBox.pre && (
              <Box>
                {purgeBox.err && <Alert severity="error" sx={{ mb: 1 }}>{purgeBox.err}</Alert>}
                <Typography sx={{ fontSize: 13, color: TEXT_PRIMARY, mb: 1 }}>
                  已经封了 {purgeBox.days} 天（满 {purgeBox.after} 天才让删）。下面这些是全都要没的东西：
                </Typography>
                <Box sx={{ p: 1.25, mb: 1, borderRadius: '12px', bgcolor: BG_GRAY, border: '1px solid ' + CARD_BORDER }}>
                  <Stack spacing={0.4}>
                    <Typography sx={{ fontSize: 13 }}>成员 {purgeBox.pre.member_count} 人</Typography>
                    <Typography sx={{ fontSize: 13 }}>数据 {purgeBox.pre.row_count} 行，摊在 {purgeBox.pre.table_count} 张表上</Typography>
                    <Typography sx={{ fontSize: 13 }}>
                      图片 {purgeBox.pre.file_count} 张（盘上还在的 {purgeBox.pre.file_present} 张，共 {fmtBytes(purgeBox.pre.bytes)}）
                    </Typography>
                    {Number(purgeBox.pre.battles_kept) > 0 && (
                      <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY }}>
                        另有 {purgeBox.pre.battles_kept} 场跨队约战留在平台台账里不删，对面看到的队名是「已注销战队」
                      </Typography>
                    )}
                  </Stack>
                </Box>
                <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mb: 1 }}>
                  删之前先打一份备份包（打不出来就一条都不删），然后连图片一起物理删干净——这一步不能撤销。
                  还没给队员发告知信的话，现在去「邮件群发」勾上「带上被封禁的站点」发一封。
                </Typography>
                <TextField size="small" fullWidth label={'逐字输入站点目录名（/' + purgeBox.slug + '/）'}
                  value={purgeBox.typed} disabled={purgeBox.busy} autoFocus
                  onChange={function (e) { setPurgeBox(Object.assign({}, purgeBox, { typed: e.target.value })); }}
                  helperText={purgeBox.token
                    ? '确认码 ' + purgeBox.left + ' 秒后过期，过期就得重新点一次预览'
                    : '确认码已经不在了：点下面「重新预览」拿一枚新的'} />
              </Box>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={function () { setPurgeBox(null); }} disabled={purgeBox && purgeBox.busy}>
              {purgeBox && purgeBox.done ? '知道了' : '取消'}
            </Button>
            {purgeBox && purgeBox.pre && !purgeBox.done && (
              <Button variant="outlined" disabled={purgeBox.busy} onClick={function () { openPurge(purgeBox.t); }}>
                {purgeBox.token ? '重新预览' : '重新预览拿新码'}
              </Button>
            )}
            {purgeBox && purgeBox.pre && !purgeBox.done && (
              <Button variant="contained" color="error"
                disabled={purgeBox.busy || !purgeBox.token || purgeBox.left <= 0 || purgeBox.typed !== purgeBox.slug}
                onClick={doPurge}>
                {purgeBox.busy ? '正在销毁…' : '确认销毁'}
              </Button>
            )}
          </DialogActions>
        </Dialog>

        <Dialog open={!!rejectApp} onClose={() => setRejectApp(null)} fullWidth maxWidth="xs">
          <DialogTitle>驳回入驻申请</DialogTitle>
          <DialogContent>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
              驳回「{rejectApp && rejectApp.name}」后，申请人登录平台可看到驳回原因
            </Typography>
            <TextField label="驳回原因" value={reason} onChange={e => setReason(e.target.value)}
              inputProps={{ maxLength: 200 }} fullWidth size="small" autoFocus />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setRejectApp(null)}>取消</Button>
            <Button variant="contained" color="error" onClick={doReject}>确认驳回</Button>
          </DialogActions>
        </Dialog>

        {/* v5.1.0：存储配额修改 */}
        <Dialog open={!!quotaT} onClose={() => setQuotaT(null)} fullWidth maxWidth="xs">
          <DialogTitle>修改存储配额</DialogTitle>
          <DialogContent>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
              站点「{quotaT && quotaT.name}」当前已用 {quotaT ? fmtBytes(quotaT.storage_used) : '-'}
            </Typography>
            <TextField label="配额（GB）" value={quotaGb} onChange={e => setQuotaGb(e.target.value)}
              helperText="留空或填 0 = 恢复默认 2GB" fullWidth size="small" autoFocus />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setQuotaT(null)}>取消</Button>
            <Button variant="contained" onClick={saveQuota}>保存</Button>
          </DialogActions>
        </Dialog>

        {/* v5.1.0：给战队管理员发通知 */}
        <Dialog open={!!notifyT} onClose={() => setNotifyT(null)} fullWidth maxWidth="xs">
          <DialogTitle>发通知给战队管理员</DialogTitle>
          <DialogContent>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
              通知将展示在「{notifyT && notifyT.name}」战队系统管理中心的"平台通知"栏
            </Typography>
            <TextField label="通知内容" value={noticeText} onChange={e => setNoticeText(e.target.value)}
              inputProps={{ maxLength: 500 }} multiline minRows={3} fullWidth size="small" autoFocus />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setNotifyT(null)}>取消</Button>
            <Button variant="contained" onClick={sendNotice}>发送</Button>
          </DialogActions>
        </Dialog>

        {/* v5.16.0：巡查台账弹窗 */}
        {inspectBox && (
          <PlatformInspectDialog tenant={inspectBox.t} token={inspectBox.token}
            quota={inspectBox.quota} onClose={() => setInspectBox(null)} />
        )}
      </Box>
    );
  }

  // ==================== 控制台外壳（v5.27.0：与战队内部管理系统同一套）====================
  // 平台端原来是「60px 顶栏 + 960 居中单列 + 14 格横滚页签」，跟战队侧不像一家人。
  // 这里照 app.jsx 的主框架搬：白底顶栏 + 232 分组侧栏（可折叠、记本地）+ 手机走临时抽屉，
  // 激活态、描边、次色、空态用的都是战队侧那几个值。
  const DRAWER_WIDTH = 232;
  const CARD_BORDER = '#e8e8ed';
  const SUB_TEXT = '#5f6b7f';
  const NAV_SHUT = 'yys_platform_nav_collapsed';
  function getCollapsed() {
    try {
      const v = JSON.parse(localStorage.getItem(NAV_SHUT) || '{}');
      return (v && typeof v === 'object' && !Array.isArray(v)) ? v : {};
    } catch (e) { return {}; }
  }
  function storeCollapsed(v) {
    try { localStorage.setItem(NAV_SHUT, JSON.stringify(v)); } catch (e) { /* 存不下就每次全展开 */ }
  }

  // 图标全部沿用 app.jsx 侧栏里那几个 path，不另画一套
  const NAV_ICONS = {
    apps: 'M18 13h-.68l-2 2h1.91L19 17H5l1.78-2h2.05l-2-2H6l-3 3v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4zm-1-5.05l-4.49-4.49a2 2 0 0 0-2.83 0L5 8.11a2 2 0 0 0 0 2.83l4.49 4.49a2 2 0 0 0 2.83 0l4.68-4.68a2 2 0 0 0 0-2.5z',
    tenants: 'M4 4h7v7H4V4zm9 0h7v4h-7V4zM4 13h7v7H4v-7zm9-3h7v10h-7V10z',
    showcase: 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 2v9.2l4.2-4.2 3 3L15 12l5 5V7H5zm3.5 1A1.5 1.5 0 1 1 8.5 11 1.5 1.5 0 0 1 8.5 8z',
    rotation: 'M12 4V1L8 5l4 4V6a6 6 0 0 1 5.66 8l1.92.64A8 8 0 0 0 12 4zm0 14a6 6 0 0 1-5.66-4l-1.92-.64A8 8 0 0 0 12 20v3l4-4-4-4v3z',
    battles: 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm0-14a6 6 0 1 0 6 6 6 6 0 0 0-6-6zm0 10a4 4 0 1 1 4-4 4 4 0 0 1-4 4zm0-6a2 2 0 1 0 2 2 2 2 0 0 0-2-2z',
    tournament: 'M19 3h-1V1h-2v2H8V1H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 16H5V9h14v10zM5 7V5h14v2H5zm2 4h5v5H7v-5z',
    datatasks: 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 1.5V8h4.5L14 3.5zM8 12h8v2H8v-2zm0 4h8v2H8v-2z',
    audits: 'M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm-1 5v6h2V7h-2zm0 8v2h2v-2h-2z',
    chat: 'M4 3h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H8.4L4 21.6A.6.6 0 0 1 3 21V4a1 1 0 0 1 1-1zm3 6v2h10V9H7zm0 4v2h6v-2H7z',
    loginlogs: 'M4 20h3V10H4v10zm6.5 0h3V4h-3v16zM17 20h3v-7h-3v7z',
    mail: 'M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
    // v5.36.31：强制公告（一只扩音筒，跟「赛前提醒」那个钟分得开）
    notice: 'M18 11v2h4v-2h-4zm-2-5v10c0 1.1.9 2 2 2h2V4h-2c-1.1 0-2 .9-2 2zM8 6.5C8 4 10 2 12.5 2S17 4 17 6.5V12c0 1.1-.9 2-2 2h-1v5c0 1.66-1.34 3-3 3s-3-1.34-3-3v-5H4c-1.1 0-2-.9-2-2V9c0-1.1.9-2 2-2h4z',
    remind: 'M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z',
    logclean: 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z',
    // v5.36.7：未晞通行证（一把钥匙）
    passport: 'M12 2a5 5 0 0 0-5 5v2H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5zm0 2a3 3 0 0 1 3 3v2H9V7a3 3 0 0 1 3-3zm0 10a2 2 0 0 1 1 3.7V20h-2v-2.3A2 2 0 0 1 12 14z',
    bot: 'M4 3h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H8.4L4 21.6A.6.6 0 0 1 3 21V4a1 1 0 0 1 1-1zm3 6v2h10V9H7zm0 4v2h6v-2H7z',
    // v5.36.14：AI 聊天（一颗星，跟 QQ机器人 的对话气泡区分开）
    ai: 'M12 2l2.4 5.2L20 9.6l-4.1 3.9.9 5.5L12 16.4 7.2 19l.9-5.5L4 9.6l5.6-2.4z',
    compliance: 'M12 1l9 4v6c0 5.6-3.8 10.7-9 12-5.2-1.3-9-6.4-9-12V5l9-4zm0 10.9a2.1 2.1 0 1 0 0-4.2 2.1 2.1 0 0 0 0 4.2zM12 22c3.8-1 6.9-5.1 6.9-9.1-.5.5-1.2.8-1.9.8a4.2 4.2 0 0 1-8-.9 2.4 2.4 0 0 1-2-.9c0 4 3.1 8.1 7 9.1h2z',
    shield: 'M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm0 2.2L6 6.4V11c0 3.9 2.6 7.6 6 8.9 3.4-1.3 6-5 6-8.9V6.4l-6-2.2zM11 7h2v4h4v2h-6V7z',
    my: 'M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12zm0 2c-4 0-8 2-8 5v2h16v-2c0-3-4-5-8-5z',
    // v5.35.0：论坛对接（数据出站，用「分享」那个形状，跟站内其它治理页一眼分得开）
    forumapi: 'M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.66C7.5 9.16 6.79 8.85 6.03 8.85c-1.66 0-3 1.34-3 3s1.34 3 3 3c.76 0 1.46-.3 2.01-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z',
    // v5.35.1：站点活跃（一条向上的折线，跟「日志清理」那根柱子分得开）
    active: 'M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z',
    // v5.36.32：已销毁站点（一只垃圾桶轮廓，和「日志清理」那根柱状图分得开）
    purged: 'M18 7H6v12c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7zm-1.5 12h-9V9h9v10zM15.5 4H13L12 3h-1L10 4H8.5L7 5.5V7h10V5.5L15.5 4z',
  };

  const SUPER_NAV = [
    ['apps', '入驻申请'], ['tenants', '已开通站点'], ['purged', '已销毁站点'], ['active', '站点活跃'], ['showcase', '展示页治理'], ['rotation', '轮换地图'],
    ['battles', '赛事约战'], ['tournament', '赛事中心'], ['datatasks', '数据审核'],
    ['chat', '私聊治理'], ['audits', '巡查记录'],
    ['bot', 'QQ机器人'], ['ai', 'AI 聊天'], ['compliance', 'SEO与合规'], ['passport', '未晞通行证'],
    ['loginlogs', '登录日志'], ['logclean', '日志清理'], ['mail', '邮件群发'], ['notice', '强制公告'], ['remind', '赛前提醒'],
    ['forumapi', '论坛对接'],
  ].map(function (it) { return [it[0], it[1], NAV_ICONS[it[0]]]; });

  const SUPER_GROUPS = [
    ['apply', '入驻与站点', ['apps', 'tenants', 'purged', 'active', 'showcase', 'rotation']],
    ['data', '赛事与数据', ['battles', 'tournament', 'datatasks']],
    ['gov', '社区治理', ['chat', 'audits']],
    ['set', '平台设置', ['bot', 'ai', 'compliance', 'passport', 'loginlogs', 'logclean', 'mail', 'notice', 'remind', 'forumapi']],
  ];

  const MY_NAV = [['my', '我的入驻与站点', NAV_ICONS.my]];
  const MY_GROUPS = [['own', '我的战队', ['my']]];

  function NavIcon({ d }) {
    return <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d={d} /></svg>;
  }

  function NavRow({ it, active, badge, onPick }) {
    return (
      <ListItemButton onClick={function () { onPick(it[0]); }}
        sx={{
          borderRadius: '10px', mb: 0.4, color: active ? 'primary.main' : '#48484a',
          bgcolor: active ? (t => alpha(t.palette.primary.main, 0.1)) : 'transparent',
          '&:hover': { bgcolor: active ? (t => alpha(t.palette.primary.main, 0.14)) : '#f5f5f7' },
        }}>
        <ListItemIcon sx={{ minWidth: 34, color: 'inherit' }}><NavIcon d={it[2]} /></ListItemIcon>
        <ListItemText primary={it[1]} primaryTypographyProps={{ fontSize: 14, fontWeight: active ? 600 : 400 }} />
        {badge > 0 && (
          <Box sx={{
            ml: 'auto', minWidth: 20, height: 18, px: 0.6, flexShrink: 0,
            borderRadius: '999px', bgcolor: 'error.main', color: '#fff', boxSizing: 'border-box',
            display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700,
          }}>{badge > 99 ? '99+' : badge}</Box>
        )}
      </ListItemButton>
    );
  }

  function EmptyBox({ text }) {
    return (
      <Box sx={{ py: 5, textAlign: 'center', color: 'text.secondary', fontSize: 14 }}>{text}</Box>
    );
  }

  function LoadingBox() {
    return <Box sx={{ py: 5, textAlign: 'center' }}><CircularProgress size={28} /></Box>;
  }

  // ==================== v5.36.6：未晞通行证对接 ====================
  // 成员原有账号密码登录/注册全部保留；这里只控制「登录页/注册页上的通行证入口」开不开。
  function PlatPassport() {
    const [cfg, setCfg] = useState(null);
    const [form, setForm] = useState(null);
    const [secret, setSecret] = useState('');
    const [busy, setBusy] = useState(false);
    const [probe, setProbe] = useState(null);

    const fill = function (c) {
      setCfg(c);
      setForm({
        enabled: c.enabled === true || Number(c.enabled) === 1,
        issuer: c.issuer || '',
        client_id: c.client_id || '',
        redirect_uri: c.redirect_uri || '',
        scope: c.scope || '',
      });
    };

    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/passport');
        if (json.code === 0) fill(json.data);
        else toast(json.msg || '通行证配置加载失败', 'error');
      } catch (e) { toast('通行证配置加载失败', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const set = function (k, v) {
      setForm(function (f) { const n = Object.assign({}, f); n[k] = v; return n; });
    };

    const save = async function () {
      if (!form) return;
      setBusy(true);
      try {
        const json = await api('/api/platform/admin/passport/save', {
          method: 'POST',
          body: Object.assign({}, form, {
            enabled: form.enabled ? 1 : 0,
            client_secret: secret,
          }),
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setSecret('');
          setProbe(null);
          fill(json.data);
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    const test = async function () {
      setBusy(true);
      try {
        const json = await api('/api/platform/admin/passport/test', { method: 'POST' });
        if (json.code === 0) { setProbe(json.data); toast(json.data.message || '检测完成', 'success'); }
        else toast(json.msg, 'error');
      } catch (e) { toast('检测失败', 'error'); } finally { setBusy(false); }
    };

    const copy = function (text) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { toast('已复制', 'success'); });
      } else {
        toast('复制失败，请手动选中', 'warning');
      }
    };

    if (form === null || cfg === null) {
      return <Paper sx={{ p: 3, textAlign: 'center' }}><CircularProgress size={26} /></Paper>;
    }

    const probeRows = [
      ['discovery', 'OIDC 配置'],
      ['jwks', 'JWKS 公钥'],
      ['authorize', '授权端点'],
      ['userinfo', '用户信息端点'],
    ];

    return (
      <Box>
        <Paper sx={{ p: { xs: 2, md: 3 } }}>
          <Typography variant="h6" fontWeight={700}>未晞通行证</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.8, mb: 2, lineHeight: 1.7 }}>
            给成员和入驻队长多一种登录方式。开启后，战队登录页、注册页和入驻平台登录页都会多出一个
            「使用未晞通行证登录」按钮；平台一键登录只对已把通行证绑到战队超管账号的入驻账号生效。
            原有账号密码注册、登录、找回密码、改密全部保留。关闭后入口直接消失，不影响密码登录。
          </Typography>

          {!cfg.table_ready ? (
            <Alert severity="error" sx={{ mb: 2 }}>
              passport_links 表还没建，开启后成员无法完成首次绑定。请先在同一个浏览器里打开{' '}
              <Link href="/passport_install_v5366.php" target="_blank" rel="noopener">通行证补库工具</Link>
              点一次按钮（跑完会自动删除）。
            </Alert>
          ) : null}

          <Stack spacing={2} sx={{ maxWidth: 760 }}>
            <FormControlLabel
              control={<Switch checked={form.enabled === true}
                onChange={function (e) { set('enabled', e.target.checked); }} />}
              label="开启未晞通行证登录" />

            <TextField label="通行证站点地址" fullWidth value={form.issuer}
              onChange={function (e) { set('issuer', e.target.value); }}
              placeholder="https://你的通行证服务地址"
              helperText="只填站点根地址，不带路径；正式环境必须是 https" />

            <TextField label="Client ID" fullWidth value={form.client_id}
              onChange={function (e) { set('client_id', e.target.value); }} />

            <TextField label="Client Secret" fullWidth type="password" value={secret}
              onChange={function (e) { setSecret(e.target.value); }}
              placeholder={cfg.secret_set ? '已保存，留空表示不修改' : '第一次配置必须填写'}
              helperText={cfg.secret_set ? ('当前密钥：' + cfg.secret_hint + '（只显示末四位）') : '密钥只保存在服务端，页面永远不会回显完整值'} />

            <TextField label="回调地址" fullWidth value={form.redirect_uri}
              onChange={function (e) { set('redirect_uri', e.target.value); }}
              helperText="必须与通行证后台登记的地址逐字一致，路径固定为 /passport/callback" />

            <Box sx={{ p: 1.5, borderRadius: 2, bgcolor: 'action.hover' }}>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>
                当前站点建议填写的回调地址
              </Typography>
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ xs: 'stretch', sm: 'center' }}>
                <Typography variant="body2" sx={{ wordBreak: 'break-all', flex: 1, fontFamily: 'Consolas, monospace' }}>
                  {cfg.default_callback}
                </Typography>
                <Button size="small" variant="outlined"
                  onClick={function () { copy(cfg.default_callback); }}>复制</Button>
              </Stack>
            </Box>

            <TextField label="Scope" fullWidth value={form.scope}
              onChange={function (e) { set('scope', e.target.value); }}
              helperText="默认 openid profile email，必须包含 openid" />

            <Box sx={{ p: 1.5, borderRadius: 2, bgcolor: 'action.hover' }}>
              <Typography variant="body2" sx={{ lineHeight: 1.8 }}>
                第一次使用通行证的成员，会看到「绑定本站账号」页：输入自己的本站账号和密码确认身份后，
                这枚通行证才会绑到那个账号上。不会自动建号，也不会按邮箱猜测账号。
                成员也可以在「个人中心 → 未晞通行证」里主动绑定或解除绑定。
              </Typography>
              <Typography variant="body2" sx={{ mt: 1, lineHeight: 1.8 }}>
                入驻平台的一键登录复用这层绑定：通行证在某支战队绑成超管后，平台登录页可直接进入该战队对应的
                平台入驻账号；没绑过的通行证会提示先去战队个人中心绑定。平台超管账号不参与一键登录。
              </Typography>
            </Box>

            <Stack direction="row" spacing={1.2}>
              <Button variant="contained" disabled={busy} onClick={save}>
                {busy ? '处理中…' : '保存配置'}
              </Button>
              <Button variant="outlined" disabled={busy} onClick={test}>测试连接</Button>
            </Stack>
          </Stack>
        </Paper>

        {probe ? (
          <Paper sx={{ p: { xs: 2, md: 3 }, mt: 2 }}>
            <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>连通性检测</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2, lineHeight: 1.7 }}>
              {probe.message || ''}
            </Typography>
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow><TableCell>端点</TableCell><TableCell>HTTP 状态</TableCell><TableCell>结果</TableCell></TableRow>
                </TableHead>
                <TableBody>
                  {probeRows.map(function (row) {
                    const p = (probe.probe || {})[row[0]] || {};
                    const good = probe.summary && probe.summary[row[0]];
                    return (
                      <TableRow key={row[0]}>
                        <TableCell>{row[1]}</TableCell>
                        <TableCell>{p.error ? p.error : (p.status || 0)}</TableCell>
                        <TableCell>
                          <Chip size="small" color={good ? 'success' : 'warning'}
                            label={good ? '正常' : '需检查'} />
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          </Paper>
        ) : null}
      </Box>
    );
  }

  // 外壳：桌面固定侧栏，手机收成顶栏菜单按钮 + 临时抽屉（14 个入口塞不进底部格，底部栏会藏功能）
  function ConsoleFrame({ items, groups, tab, onTab, badgeOf, roleLabel, me, onLogout, children }) {
    const [collapsed, setCollapsed] = useState(getCollapsed);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const isMdUp = useMediaQuery('(min-width:900px)');

    const toggleGroup = function (k) {
      const next = Object.assign({}, collapsed);
      if (next[k]) { delete next[k]; } else { next[k] = 1; }
      setCollapsed(next); storeCollapsed(next);
    };
    const pick = function (keys) {
      const out = [];
      keys.forEach(function (k) {
        const hit = items.filter(function (it) { return it[0] === k; })[0];
        if (hit) { out.push(hit); }
      });
      return out;
    };
    const current = items.filter(function (it) { return it[0] === tab; })[0];
    const go = function (k) { onTab(k); setDrawerOpen(false); };

    const drawerContent = (
      <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%', bgcolor: '#fff' }}>
        <Box sx={{ px: 2.5, pt: 2.5, pb: 1.5 }}>
          <Box sx={{
            width: 36, height: 36, borderRadius: '10px', bgcolor: 'primary.main', color: '#fff',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d={NAV_ICONS.shield} /></svg>
          </Box>
          <Typography fontWeight={700} sx={{ mt: 1.2, fontSize: 16, color: TEXT_PRIMARY }} noWrap>战队入驻平台</Typography>
          <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 0.3 }}>{roleLabel}</Typography>
        </Box>
        <Box sx={{ flex: 1, px: 1.5, py: 1, overflowY: 'auto' }}>
          {groups.map(function (g) {
            const rows = pick(g[2]);
            if (rows.length === 0) { return null; }
            const shut = !!collapsed[g[0]];
            return (
              <Box key={g[0]} sx={{ mt: 1.25 }}>
                <Box onClick={function () { toggleGroup(g[0]); }}
                  sx={{ display: 'flex', alignItems: 'center', px: 1, py: 0.5, cursor: 'pointer', userSelect: 'none' }}>
                  <Typography sx={{ fontSize: 11, fontWeight: 700, letterSpacing: '.04em', color: SUB_TEXT }}>{g[1]}</Typography>
                  <Box sx={{ flex: '1 1 auto' }} />
                  <Typography sx={{
                    fontSize: 11, color: '#c7c7cc', lineHeight: '12px',
                    transform: shut ? 'rotate(-90deg)' : 'none', transition: 'transform .15s ease',
                  }}>⌄</Typography>
                </Box>
                {!shut && rows.map(function (it) {
                  return <NavRow key={it[0]} it={it} active={tab === it[0]} badge={badgeOf(it[0])} onPick={go} />;
                })}
              </Box>
            );
          })}
        </Box>
        {me && (
          <Box sx={{ m: 1.5, p: 1.2, borderRadius: '12px', bgcolor: BG_GRAY, display: 'flex', alignItems: 'center', gap: 1.2 }}>
            <Box sx={{
              width: 36, height: 36, borderRadius: '50%', bgcolor: 'primary.main', color: '#fff', flexShrink: 0,
              display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 15, fontWeight: 700,
            }}>{String(me.nickname || '?').slice(0, 1)}</Box>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Typography variant="body2" fontWeight={600} noWrap sx={{ color: TEXT_PRIMARY }}>{me.nickname}</Typography>
              <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>
                {me.role === 'admin' ? '平台超管' : '入驻战队'}
              </Typography>
            </Box>
            <Tooltip title="退出登录">
              <IconButton size="small" onClick={onLogout}>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="#86868b">
                  <path d="M10 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-8v-2h7V5h-7V3zm1 8v2H5.8l2.1 2.1-1.4 1.4L2 12l4.5-4.5 1.4 1.4L5.8 11H11z"/>
                </svg>
              </IconButton>
            </Tooltip>
          </Box>
        )}
      </Box>
    );

    return (
      <Box sx={{ minHeight: '100vh', bgcolor: BG_GRAY }}>
        <AppBar position="fixed" elevation={0} sx={{
          zIndex: function (t) { return t.zIndex.drawer + 1; },
          bgcolor: '#fff', color: TEXT_PRIMARY, borderBottom: '1px solid ' + CARD_BORDER,
        }}>
          <Toolbar sx={{ minHeight: '56px !important', gap: 1.5, px: { xs: 1.5, md: 3 } }}>
            {!isMdUp && (
              <IconButton size="small" edge="start" sx={{ color: TEXT_PRIMARY, ml: -1 }}
                onClick={function () { setDrawerOpen(true); }}>
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                  <path d="M4 6h16v2H4V6zm0 5h16v2H4v-2zm0 5h16v2H4v-2z"/>
                </svg>
              </IconButton>
            )}
            <Typography variant="h6" fontWeight={700}
              sx={{ fontSize: 18, color: TEXT_PRIMARY, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {current ? current[1] : '战队入驻平台'}
            </Typography>
            <Box sx={{ flex: 1 }} />
            {me && !isMdUp && (
              <Tooltip title="退出登录">
                <IconButton size="small" onClick={onLogout}>
                  <svg viewBox="0 0 24 24" width="20" height="20" fill="#86868b">
                    <path d="M10 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-8v-2h7V5h-7V3zm1 8v2H5.8l2.1 2.1-1.4 1.4L2 12l4.5-4.5 1.4 1.4L5.8 11H11z"/>
                  </svg>
                </IconButton>
              </Tooltip>
            )}
            {me && isMdUp && (
              <Typography variant="body2" color="text.secondary" noWrap>
                {me.nickname}
              </Typography>
            )}
          </Toolbar>
        </AppBar>

        {isMdUp && (
          <Drawer variant="permanent" sx={{
            width: DRAWER_WIDTH, flexShrink: 0,
            '& .MuiDrawer-paper': { width: DRAWER_WIDTH, boxSizing: 'border-box', borderRight: '1px solid ' + CARD_BORDER },
          }} open>
            {drawerContent}
          </Drawer>
        )}
        {!isMdUp && (
          <Drawer variant="temporary" open={drawerOpen} onClose={function () { setDrawerOpen(false); }}
            ModalProps={{ keepMounted: true }} sx={{
              '& .MuiDrawer-paper': { width: DRAWER_WIDTH, boxSizing: 'border-box', borderRight: '1px solid ' + CARD_BORDER },
            }}>
            {drawerContent}
          </Drawer>
        )}

        <Box component="main" sx={{
          px: { xs: 1.5, md: 2.5 },
          pt: { xs: 1.5, md: 2.5 },
          pb: { xs: 2.5, md: 2.5 },
          ml: isMdUp ? DRAWER_WIDTH + 'px' : 0,
          mt: '56px',
        }}>
          <Box sx={{ maxWidth: 1180, mx: 'auto', width: '100%' }}>{children}</Box>
        </Box>
      </Box>
    );
  }

  // ==================== 控制台主框架 ====================
  function Console() {
    const [me, setMe] = useState(null);
    const [loadErr, setLoadErr] = useState(false);
    const [tab, setTab] = useState('apps');
    const [pending, setPending] = useState(0);
    const [gov, setGov] = useState(0);

    useEffect(function () {
      (async function () {
        try {
          const json = await api('/api/platform/auth/me');
          setMe(json.data);
          if (json.data && json.data.role !== 'admin') { setTab('my'); }
        } catch (e) { setLoadErr(true); }
      })();
    }, []);

    const logout = async function () {
      try { await api('/api/platform/auth/logout', { method: 'POST' }); } catch (e) { /* ignore */ }
      location.href = '/login';
    };

    if (loadErr) {
      return <Box sx={{ p: 3 }}><Alert severity="error">加载失败，请刷新重试</Alert></Box>;
    }
    if (me === null) {
      return <Box sx={{ minHeight: '100vh', bgcolor: BG_GRAY }}><LoadingBox /></Box>;
    }
    const isAdmin = me.role === 'admin';

    return (
      <ConsoleFrame items={isAdmin ? SUPER_NAV : MY_NAV} groups={isAdmin ? SUPER_GROUPS : MY_GROUPS}
        tab={tab} onTab={setTab} badgeOf={isAdmin ? function (k) { return k === 'apps' ? pending : (k === 'chat' ? gov : 0); } : function () { return 0; }}
        roleLabel={isAdmin ? '平台管理控制台' : '入驻战队中心'} me={me} onLogout={logout}>
        {isAdmin
          ? <SuperConsole tab={tab} onPending={setPending} onGov={setGov} />
          : <TeamConsole />}
      </ConsoleFrame>
    );
  }

  // ==================== 根组件 ====================
  function Root() {
    const [snack, setSnack] = useState({ open: false, msg: '', sev: 'info' });

    useEffect(function () {
      _toast = function (msg, sev) { setSnack({ open: true, msg: msg, sev: sev || 'info' }); };
      return function () { _toast = function () {}; };
    }, []);

    const page = (MODE === 'login' || MODE === 'register') ? (
      <AuthFrame mode={MODE}>
        {MODE === 'login' ? <LoginPage /> : <RegisterPage />}
      </AuthFrame>
    ) : <Console />;

    return (
      <ThemeProvider theme={theme}>
        <CssBaseline />
        {page}
        <Snackbar open={snack.open} autoHideDuration={2200} anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
          onClose={() => setSnack(Object.assign({}, snack, { open: false }))}>
          <Alert severity={snack.sev} variant="filled" sx={{ minWidth: 240 }}>{snack.msg}</Alert>
        </Snackbar>
      </ThemeProvider>
    );
  }

  ReactDOM.createRoot(document.getElementById('root')).render(<Root />);
})();
