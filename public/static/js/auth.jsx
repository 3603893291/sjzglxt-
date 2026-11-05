/* 登录 / 注册页（v6.0 Apple 风格重构：桌面左右分栏海报 + 手机上下布局） */
(function () {
  'use strict';

  const { useState, useRef, useEffect, useCallback } = React;
  const {
    ThemeProvider, createTheme, CssBaseline,
    Box, Typography, TextField, Button, Alert, Link, alpha,
    FormControlLabel, Checkbox,
  } = MaterialUI;

  const isRegister = window.AUTH_MODE === 'register';
  const isForgot = window.AUTH_MODE === 'forgot';   // v5.7.1(L1)：忘记密码（复用登录页外壳）
  // v5.0.0：目录制多租户 —— 战队系统在 /{slug}/ 下运行，请求/跳转统一带目录前缀
  const BASE = window.SITE_BASE || '';

  // ---------- Apple 风格设计令牌 ----------
  const APPLE_BLUE = '#0066cc';
  const BG_GRAY = '#f5f5f7';
  const TEXT_PRIMARY = '#1d1d1f';
  const TEXT_SECONDARY = '#86868b';
  const BORDER = '#d2d2d7';

  // v5.12.0：这张海报战队可以在管理中心「登录页配图」换成自己战队的图（模板注入 window.LOGIN_POSTER）；
  // 没设置或值不合法时后端回内置图，这里再兜一层，保证脱离模板单独渲染也不会空白。
  const POSTER_URL = window.LOGIN_POSTER || '/static/img/login-poster.webp';

  const theme = createTheme({
    palette: {
      primary: { main: APPLE_BLUE },
      error: { main: '#ff3b30' },
      background: { default: BG_GRAY },
      text: { primary: TEXT_PRIMARY, secondary: TEXT_SECONDARY },
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
        },
      },
      MuiOutlinedInput: {
        styleOverrides: {
          root: {
            borderRadius: 12,
            bgcolor: '#fff',
            '& fieldset': { borderColor: BORDER },
            '&:hover fieldset': { borderColor: '#a1a1a6' },
          },
          input: { padding: '13px 16px', fontSize: 15 },
        },
      },
    },
  });

  // ---- 请求工具（不依赖登录态，401 不跳转） ----
  async function parse(res) {
    let json = null;
    try { json = await res.json(); } catch (e) { /* ignore */ }
    if (!json) return { code: 1, msg: '服务器响应异常，请稍后再试' };
    return json;
  }
  async function postJSON(url, data) {
    const res = await fetch(BASE + url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    return parse(res);
  }
  async function postForm(url, formData) {
    const res = await fetch(BASE + url, { method: 'POST', body: formData });
    return parse(res);
  }

  // ==================== 未晞通行证（v5.36.7：登录/注册页只留一个描边按钮） ====================
  function LockIcon() {
    return (
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="5" y="10" width="14" height="10" rx="2.2" stroke="currentColor" strokeWidth="1.8" />
        <path d="M8 10V7.5a4 4 0 1 1 8 0V10" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
      </svg>
    );
  }


  function usePassportConfig() {
    const [cfg, setCfg] = useState(null);
    const [loading, setLoading] = useState(true);
    const load = useCallback(async function () {
      setLoading(true);
      try {
        const res = await fetch(BASE + '/api/passport/config', { headers: { Accept: 'application/json' } });
        const json = await res.json();
        setCfg(json && json.code === 0 ? (json.data || {}) : null);
      } catch (e) {
        setCfg(null);
      } finally {
        setLoading(false);
      }
    }, []);
    useEffect(function () { load(); }, [load]);
    return { cfg: cfg, loading: loading, reload: load };
  }

  function PassportButton() {
    const { cfg, loading } = usePassportConfig();
    const [busy, setBusy] = useState(false);

    // 从通行证页按浏览器「后退」回来时会走 bfcache，不复位就会卡在「正在前往…」
    useEffect(function () {
      const onShow = function (e) { if (e.persisted) setBusy(false); };
      window.addEventListener('pageshow', onShow);
      return function () { window.removeEventListener('pageshow', onShow); };
    }, []);

    // 未开启、还在检查、接口不可用：整块不出现。开启但配置不全：只给灰按钮，不铺说明。
    if (loading || !cfg || !cfg.enabled) return null;
    const ready = !!cfg.ready;

    const start = function () {
      if (!ready || busy) return;
      setBusy(true);
      location.href = cfg.start_url || (BASE + '/passport/start');
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
  // 通行证失败/取消回到登录页时，只按后端固定 code 显示提示（不反射外部错误原文）
  const PASSPORT_NOTICES = {
    denied: { tone: 'warning', title: '已取消授权', message: '你取消了未晞通行证授权，还没有登录。' },
    state: { tone: 'warning', title: '登录请求已过期', message: '请重新点一次「使用未晞通行证登录」。' },
    config: { tone: 'error', title: '通行证尚未配置', message: '站点管理员还没有完成未晞通行证对接，请联系平台管理员。' },
    banned: { tone: 'error', title: '账号已被封禁', message: '当前账号不能登录，请联系战队管理员。' },
    pending: { tone: 'info', title: '账号还在审核中', message: '队长审核通过后，再用未晞通行证登录即可进入战队。' },
    link_conflict: { tone: 'error', title: '绑定没有完成', message: '这个通行证账号或当前成员已经绑定了别的账号，请先在个人中心处理。' },
    login_first: { tone: 'info', title: '请先登录', message: '先用本站账号登录，再到个人中心绑定通行证。' },
    failed: { tone: 'error', title: '登录失败', message: '未晞通行证校验没有通过，请稍后重试或联系管理员。' },
  };

  function PassportNotice() {
    const [notice] = useState(function () {
      try {
        const code = new URLSearchParams(window.location.search).get('pn') || '';
        return PASSPORT_NOTICES[code] || null;
      } catch (e) {
        return null;
      }
    });
    if (!notice) return null;
    return (
      <Alert severity={notice.tone} sx={{ mb: 2, borderRadius: 3 }}>
        <Typography sx={{ fontSize: 13.5, fontWeight: 600 }}>{notice.title}</Typography>
        <Typography sx={{ fontSize: 13, mt: 0.3, lineHeight: 1.6 }}>{notice.message}</Typography>
      </Alert>
    );
  }

  // ==================== 登录表单 ====================
  function LoginForm() {
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(true);   // v5.3.0：7 天免登录，默认勾选
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = async function (e) {
      e.preventDefault();
      if (busy) return;   // 防连点
      setError('');
      setBusy(true);
      try {
        const json = await postJSON('/api/auth/login', {
          username: username.trim(), password: password, remember: remember ? 1 : 0,
        });
        if (json.code === 0) {
          location.href = BASE + '/app#/dashboard';
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
          欢迎回来
        </Typography>
        <Typography sx={{ mt: 1, mb: 3.5, fontSize: 15, color: TEXT_SECONDARY }}>
          登录以管理你的战队
        </Typography>

        <PassportNotice />
        {error && <Alert severity="error" sx={{ mb: 2, borderRadius: 3 }}>{error}</Alert>}

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>账号</Typography>
        <TextField fullWidth required sx={{ mb: 2 }}
          value={username} onChange={(e) => setUsername(e.target.value)}
          autoComplete="username" placeholder="QQ号 / 邮箱" />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 1.5 }}
          value={password} onChange={(e) => setPassword(e.target.value)}
          autoComplete="current-password" placeholder="请输入密码" />

        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2.5 }}>
          <FormControlLabel sx={{ m: 0 }}
            control={<Checkbox size="small" checked={remember} onChange={(e) => setRemember(e.target.checked)}
              sx={{ color: '#a1a1a6', '&.Mui-checked': { color: APPLE_BLUE } }} />}
            label={<Typography sx={{ fontSize: 14, color: TEXT_PRIMARY }}>记住我</Typography>} />
          <Link component="button" type="button" underline="none"
            onClick={() => { location.href = BASE + '/forgot'; }}
            sx={{ fontSize: 14, color: APPLE_BLUE }}>忘记密码?</Link>
        </Box>

        <Button type="submit" variant="contained" fullWidth size="large" disabled={busy}
          sx={{ height: 48, fontSize: 16 }}>
          {busy ? '登录中...' : '登 录'}
        </Button>

        <PassportButton />


        <Typography align="center" sx={{ mt: 2.5, fontSize: 14, color: TEXT_SECONDARY }}>
          还没有账号？{' '}
          <Link href={BASE + '/register'} underline="none" sx={{ color: APPLE_BLUE, fontWeight: 600 }}>立即注册</Link>
        </Typography>
      </Box>
    );
  }

  // ==================== 注册表单 ====================
  function RegisterForm() {
    const [qq, setQq] = useState('');
    const [nickname, setNickname] = useState('');
    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState('');
    const [error, setError] = useState('');
    const [ok, setOk] = useState('');
    const [busy, setBusy] = useState(false);
    const fileRef = useRef(null);

    const qqEmail = /^\d{5,11}$/.test(qq.trim()) ? qq.trim() + '@qq.com' : 'QQ号@qq.com';

    const onFile = function (e) {
      const f = e.target.files[0] || null;
      setFile(f);
      setPreview(f ? URL.createObjectURL(f) : '');
    };

    const submit = async function (e) {
      e.preventDefault();
      if (busy) return;   // 防连点
      setError(''); setOk('');
      if (!file) { setError('请选择游戏个人主页截图'); return; }
      setBusy(true);
      try {
        const fd = new FormData();
        fd.append('qq', qq.trim());
        fd.append('nickname', nickname.trim());
        fd.append('password', password);
        fd.append('confirm', confirm);
        fd.append('file', file);
        const json = await postForm('/api/auth/register', fd);
        if (json.code === 0) {
          // 注册成功 → 待审核，不自动登录
          setQq(''); setNickname(''); setPassword(''); setConfirm('');
          setFile(null); setPreview('');
          if (fileRef.current) fileRef.current.value = '';
          setOk(json.msg || '注册成功，请等待管理员审核');
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
          加入我们
        </Typography>
        <Typography sx={{ mt: 1, mb: 3.5, fontSize: 15, color: TEXT_SECONDARY }}>
          注册后需管理员审核通过才能登录
        </Typography>

        {error && <Alert severity="error" sx={{ mb: 2, borderRadius: 3 }}>{error}</Alert>}
        {ok && <Alert severity="success" sx={{ mb: 2, borderRadius: 3 }}>{ok}</Alert>}

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>QQ 号</Typography>
        <TextField fullWidth required sx={{ mb: 0.5 }}
          value={qq} onChange={(e) => setQq(e.target.value)}
          placeholder="5-11 位数字" inputProps={{ inputMode: 'numeric' }} />
        <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, display: 'block', mb: 2 }}>
          邮箱地址：{qqEmail}（按 QQ 号自动生成，仅作存档）
        </Typography>

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>昵称（选填）</Typography>
        <TextField fullWidth sx={{ mb: 2 }}
          value={nickname} onChange={(e) => setNickname(e.target.value)}
          placeholder="不填则默认为 玩家+QQ号" />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 2 }}
          value={password} onChange={(e) => setPassword(e.target.value)}
          placeholder="8-64 位密码，需包含字母和数字" autoComplete="new-password" />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>确认密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 2.5 }}
          value={confirm} onChange={(e) => setConfirm(e.target.value)}
          placeholder="再次输入密码" autoComplete="new-password" />

        <input
          ref={fileRef} type="file" onChange={onFile}
          accept="image/jpeg,image/png,image/gif,image/webp"
          style={{ display: 'none' }}
        />
        <Box onClick={() => { if (fileRef.current) fileRef.current.click(); }} sx={{
          border: '1.5px dashed', borderColor: file ? APPLE_BLUE : BORDER, borderRadius: 12,
          p: 2.5, textAlign: 'center', cursor: 'pointer', mb: 1,
          bgcolor: file ? alpha(APPLE_BLUE, 0.06) : 'transparent', transition: 'background-color .15s',
          '&:hover': { bgcolor: alpha(APPLE_BLUE, 0.1) },
        }}>
          <Typography sx={{ fontSize: 14, fontWeight: 500, color: file ? APPLE_BLUE : TEXT_SECONDARY }}>
            {file ? ('已选择：' + file.name) : '点击选择游戏个人主页截图 *'}
          </Typography>
          <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, display: 'block', mt: 0.4 }}>
            支持 jpg / png / gif / webp，不超过 5MB
          </Typography>
        </Box>

        {preview && (
          <Box component="img" src={preview} alt="截图预览" sx={{
            display: 'block', maxWidth: '100%', maxHeight: 180, borderRadius: 10,
            border: '1px solid #e2e2e7', mb: 2, mt: 1,
          }} />
        )}

        <Button type="submit" variant="contained" fullWidth size="large" disabled={busy}
          sx={{ height: 48, fontSize: 16, mt: 1.5 }}>
          {busy ? '提交中...' : '注 册'}
        </Button>
        <PassportButton />


        <Typography align="center" sx={{ mt: 2.5, fontSize: 14, color: TEXT_SECONDARY }}>
          已有账号？{' '}
          <Link href={BASE + '/login'} underline="none" sx={{ color: APPLE_BLUE, fontWeight: 600 }}>直接登录</Link>
        </Typography>
      </Box>
    );
  }

  // ==================== 忘记密码（v5.36.34：两条路，能自助的那条放前面） ====================
  //   路 A 邮箱验证码：邮箱是注册时按 QQ 号生成的 QQ号@qq.com，收得到码就等于自证身份，当场改密码；
  //   路 B 提交截图：v5.7.1 那条老路，重新交一张游戏主页截图等管理员审核。
  //   平台没配好发信邮箱时路 A 自动不可用（/api/auth/reset-options 回 email_ready=0），
  //   那时只剩路 B —— 成员不会被两扇都锁上的门挡在外面。
  function ForgotForm() {
    const [opt, setOpt] = useState(null);   // null=还没拿到；{email_ready, why, ttl_sec, again_sec}
    const [tab, setTab] = useState('shot');

    useEffect(function () {
      let alive = true;
      fetch(BASE + '/api/auth/reset-options', { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!alive) return;
          const d = (j && j.code === 0) ? (j.data || {}) : { email_ready: 0 };
          setOpt(d);
          if (d.email_ready) setTab('email');
        })
        .catch(function () { if (alive) setOpt({ email_ready: 0, why: '暂时问不到本站能不能发信，先用截图这条路' }); });
      return function () { alive = false; };
    }, []);

    const emailReady = !!(opt && opt.email_ready);
    const items = [];
    if (emailReady) items.push({ key: 'email', label: '邮箱验证码（自己改）' });
    items.push({ key: 'shot', label: '提交截图（等审核）' });

    return (
      <Box sx={{ width: '100%' }}>
        <Typography sx={{ fontSize: { xs: 28, md: 32 }, fontWeight: 700, color: TEXT_PRIMARY, letterSpacing: '-0.5px' }}>
          忘记密码
        </Typography>
        <Typography sx={{ mt: 1, mb: 2.5, fontSize: 15, color: TEXT_SECONDARY }}>
          {emailReady ? '收得到验证码就自己改好，不用等审核；不方便收信就交截图' : (opt ? (opt.why || '') : '')}
        </Typography>

        {!opt && <Alert severity="info" sx={{ mb: 2, borderRadius: 3 }}>正在确认本站能不能发验证码…</Alert>}
        {emailReady && (
          <Box sx={{
            display: 'flex', gap: 1, mb: 2.5, p: 0.5,
            border: '1px solid ' + BORDER, borderRadius: 999, bgcolor: BG_GRAY,
          }}>
            {items.map(function (it) {
              const on = tab === it.key;
              return (
                <Button key={it.key} type="button" size="small"
                  variant={on ? 'contained' : 'text'}
                  onClick={() => { setTab(it.key); }}
                  sx={{ flex: 1, minHeight: 36, fontSize: 13, borderRadius: 999, color: on ? '#fff' : TEXT_SECONDARY }}>
                  {it.label}
                </Button>
              );
            })}
          </Box>
        )}

        {emailReady && tab === 'email'
          ? <EmailResetForm ttlSec={(opt && opt.ttl_sec) || 600} againSec={(opt && opt.again_sec) || 60} />
          : <ScreenshotForm />}
      </Box>
    );
  }

  // ---------- 路 A：邮箱验证码自助改密码 ----------
  function EmailResetForm(props) {
    const [qq, setQq] = useState('');
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState('');
    const [ok, setOk] = useState('');
    const [busy, setBusy] = useState(false);
    const [sending, setSending] = useState(false);
    const [left, setLeft] = useState(0);          // 距下一次能索取验证码还剩几秒
    const done = ok !== '';
    const mins = Math.max(1, Math.round((props.ttlSec || 600) / 60));

    useEffect(function () {
      if (left <= 0) return undefined;
      const t = setTimeout(function () { setLeft(function (n) { return n > 0 ? n - 1 : 0; }); }, 1000);
      return function () { clearTimeout(t); };
    }, [left]);

    const send = async function () {
      if (sending || busy || left > 0 || done) return;
      setError(''); setOk('');
      if (!qq.trim()) { setError('请先填 QQ 号'); return; }
      setSending(true);
      try {
        const json = await postJSON('/api/auth/send-code', { qq: qq.trim() });
        if (json.code === 0) {
          setOk(json.msg || '验证码已发出，请查收邮箱');
          setLeft(props.againSec || 60);
        } else {
          setError(json.msg || '验证码没发出去，请稍后再试');
        }
      } catch (err) {
        setError('网络异常，请稍后再试');
      } finally {
        setSending(false);
      }
    };

    const submit = async function (e) {
      e.preventDefault();
      if (busy) return;   // 防连点
      setError('');
      if (!code.trim()) { setError('请输入邮箱里那 6 位验证码'); return; }
      setBusy(true);
      try {
        const json = await postJSON('/api/auth/reset-by-code', {
          qq: qq.trim(), code: code.trim(), password: password, confirm: confirm,
        });
        if (json.code === 0) {
          setCode(''); setPassword(''); setConfirm('');
          setOk((json.msg || '密码已重置') + '（这 6 位验证码已经用掉了）');
        } else {
          setError(json.msg || '重置失败，请稍后再试');
        }
      } catch (err) {
        setError('网络异常，请稍后再试');
      } finally {
        setBusy(false);
      }
    };

    return (
      <Box component="form" onSubmit={submit} sx={{ width: '100%' }}>
        {error && <Alert severity="error" sx={{ mb: 2, borderRadius: 3 }}>{error}</Alert>}
        {ok && <Alert severity="success" sx={{ mb: 2, borderRadius: 3 }}>{ok}</Alert>}

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>QQ 号</Typography>
        <TextField fullWidth required sx={{ mb: 1.5 }} disabled={done}
          value={qq} onChange={(e) => setQq(e.target.value)}
          placeholder="注册时使用的 QQ 号" inputProps={{ inputMode: 'numeric' }} autoComplete="username" />

        <Button type="button" formNoValidate variant="outlined" fullWidth
          disabled={done || sending || left > 0} onClick={send}
          sx={{ height: 42, mb: 2.5 }}>
          {done ? '验证码已用过' : (left > 0 ? ('请等 ' + left + ' 秒') : (sending ? '发送中...' : '获取邮箱验证码'))}
        </Button>

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>邮箱验证码</Typography>
        <TextField fullWidth sx={{ mb: 2 }} disabled={done}
          value={code} onChange={(e) => setCode(e.target.value)}
          placeholder={mins + ' 分钟内有效，只用一次'}
          inputProps={{ inputMode: 'numeric', maxLength: 8, autoComplete: 'one-time-code' }} />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>新密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 2 }} disabled={done}
          value={password} onChange={(e) => setPassword(e.target.value)}
          placeholder="8-64 位，需包含字母和数字" autoComplete="new-password" />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>确认新密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 2.5 }} disabled={done}
          value={confirm} onChange={(e) => setConfirm(e.target.value)}
          placeholder="再次输入新密码" autoComplete="new-password" />

        <Button type="submit" variant="contained" fullWidth size="large" disabled={busy || done}
          sx={{ height: 48, fontSize: 16, mt: 1.5 }}>
          {busy ? '提交中...' : (done ? '已重置，可以登录了' : '重置密码')}
        </Button>

        <Typography align="center" sx={{ mt: 2.5, fontSize: 14, color: TEXT_SECONDARY }}>
          想起密码了？{' '}
          <Link href={BASE + '/login'} underline="none" sx={{ color: APPLE_BLUE, fontWeight: 600 }}>返回登录</Link>
        </Typography>
      </Box>
    );
  }

  // ---------- 路 B：提交游戏主页截图，等管理员审核（v5.7.1 原样保留） ----------
  function ScreenshotForm() {
    const [qq, setQq] = useState('');
    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState('');
    const [error, setError] = useState('');
    const [ok, setOk] = useState('');
    const [busy, setBusy] = useState(false);
    const fileRef = useRef(null);

    const onFile = function (e) {
      const f = e.target.files[0] || null;
      setFile(f);
      setPreview(f ? URL.createObjectURL(f) : '');
    };

    const submit = async function (e) {
      e.preventDefault();
      if (busy) return;   // 防连点
      setError(''); setOk('');
      if (!file) { setError('请选择游戏个人主页截图'); return; }
      setBusy(true);
      try {
        const fd = new FormData();
        fd.append('qq', qq.trim());
        fd.append('password', password);
        fd.append('confirm', confirm);
        fd.append('file', file);
        const json = await postForm('/api/auth/forgot', fd);
        if (json.code === 0) {
          setQq(''); setPassword(''); setConfirm('');
          setFile(null); setPreview('');
          if (fileRef.current) fileRef.current.value = '';
          setOk(json.msg || '申请已受理，请等待管理员审核');
        } else {
          setError(json.msg || '提交失败，请稍后再试');
        }
      } catch (err) {
        setError('网络异常，请稍后再试');
      } finally {
        setBusy(false);
      }
    };

    return (
      <Box component="form" onSubmit={submit} sx={{ width: '100%' }}>
        {error && <Alert severity="error" sx={{ mb: 2, borderRadius: 3 }}>{error}</Alert>}
        {ok && <Alert severity="success" sx={{ mb: 2, borderRadius: 3 }}>{ok}</Alert>}

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>QQ 号</Typography>
        <TextField fullWidth required sx={{ mb: 2 }}
          value={qq} onChange={(e) => setQq(e.target.value)}
          placeholder="注册时使用的 QQ 号" inputProps={{ inputMode: 'numeric' }} />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>新密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 2 }}
          value={password} onChange={(e) => setPassword(e.target.value)}
          placeholder="8-64 位，需包含字母和数字" autoComplete="new-password" />

        <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.8 }}>确认新密码</Typography>
        <TextField fullWidth type="password" required sx={{ mb: 2.5 }}
          value={confirm} onChange={(e) => setConfirm(e.target.value)}
          placeholder="再次输入新密码" autoComplete="new-password" />

        <input
          ref={fileRef} type="file" onChange={onFile}
          accept="image/jpeg,image/png,image/gif,image/webp"
          style={{ display: 'none' }}
        />
        <Box onClick={() => { if (fileRef.current) fileRef.current.click(); }} sx={{
          border: '1.5px dashed', borderColor: file ? APPLE_BLUE : BORDER, borderRadius: 12,
          p: 2.5, textAlign: 'center', cursor: 'pointer', mb: 1,
          bgcolor: file ? alpha(APPLE_BLUE, 0.06) : 'transparent', transition: 'background-color .15s',
          '&:hover': { bgcolor: alpha(APPLE_BLUE, 0.1) },
        }}>
          <Typography sx={{ fontSize: 14, fontWeight: 500, color: file ? APPLE_BLUE : TEXT_SECONDARY }}>
            {file ? ('已选择：' + file.name) : '点击选择游戏个人主页截图 *'}
          </Typography>
          <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, display: 'block', mt: 0.4 }}>
            支持 jpg / png / gif / webp，不超过 5MB
          </Typography>
        </Box>

        {preview && (
          <Box component="img" src={preview} alt="截图预览" sx={{
            display: 'block', maxWidth: '100%', maxHeight: 180, borderRadius: 10,
            border: '1px solid #e2e2e7', mb: 2, mt: 1,
          }} />
        )}

        <Button type="submit" variant="contained" fullWidth size="large" disabled={busy}
          sx={{ height: 48, fontSize: 16, mt: 1.5 }}>
          {busy ? '提交中...' : '提交申请'}
        </Button>

        <Typography align="center" sx={{ mt: 2.5, fontSize: 14, color: TEXT_SECONDARY }}>
          想起密码了？{' '}
          <Link href={BASE + '/login'} underline="none" sx={{ color: APPLE_BLUE, fontWeight: 600 }}>返回登录</Link>
        </Typography>
      </Box>
    );
  }

  // ==================== 页面底部：隐私政策 / 服务条款（v5.10.0）====================
  // 平台与战队站点通用：BASE 为空时指向 /privacy /terms（平台），
  // 战队上下文时指向 /{slug}/privacy /{slug}/terms（Compliance 控制器同路由复用）
  function PageFooter() {
    const verb = isRegister ? '注册' : (isForgot ? '提交申请' : '登录');
    return (
      <Box sx={{ mt: 3, pt: 2, borderTop: '1px solid ' + BORDER, textAlign: 'center' }}>
        <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, lineHeight: 1.7 }}>
          {verb}即代表您同意本站的{' '}
          <Link href={BASE + '/terms'} target="_blank" rel="noopener" underline="hover"
            sx={{ color: APPLE_BLUE, fontSize: 12 }}>服务条款</Link>
          {' '}与{' '}
          <Link href={BASE + '/privacy'} target="_blank" rel="noopener" underline="hover"
            sx={{ color: APPLE_BLUE, fontSize: 12 }}>隐私政策</Link>
        </Typography>
      </Box>
    );
  }

  // ==================== 页面外壳：桌面左右分栏 / 手机上下布局 ====================
  function Root() {
    return (
      <ThemeProvider theme={theme}>
        <CssBaseline />
        <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: { xs: 'column', md: 'row' }, bgcolor: BG_GRAY }}>
          <Box sx={{
            height: { xs: '30vh', md: '100vh' },
            width: { xs: '100%', md: '50%' },
            flexShrink: 0,
            bgcolor: '#14181d',
            backgroundImage: 'linear-gradient(160deg, rgba(20,24,29,0.15) 0%, rgba(20,24,29,0.55) 100%), url(' + POSTER_URL + ')',
            backgroundSize: 'cover',
            backgroundPosition: 'center',
          }} />
          <Box sx={{
            flex: 1, display: 'flex', alignItems: { xs: 'flex-start', md: 'center' }, justifyContent: 'center',
            px: 3, py: { xs: 4, md: 6 }, minHeight: 0,
          }}>
            <Box sx={{ width: '100%', maxWidth: 400 }}>
              {isRegister ? <RegisterForm /> : (isForgot ? <ForgotForm /> : <LoginForm />)}
              <PageFooter />
            </Box>
          </Box>
        </Box>
      </ThemeProvider>
    );
  }
  ReactDOM.createRoot(document.getElementById('root')).render(<Root />);
})();
