/* 主应用（v3.5：React + Material-UI 深度重写，逻辑与 app.js v3.4 1:1 对齐） */
(function () {
  'use strict';

  const { useState, useEffect, useRef, useCallback, useMemo } = React;
  const {
    ThemeProvider, createTheme, CssBaseline,
    AppBar, Toolbar, Drawer, Box, List, ListItemButton, ListItemText, ListItemIcon,
    Typography, Button, IconButton, TextField, Chip, Paper, Stack, Divider,
    Table, TableBody, TableCell, TableHead, TableRow, TableContainer,
    Dialog, DialogTitle, DialogContent, DialogActions,
    Snackbar, Alert, Avatar, Tabs, Tab, CircularProgress, InputLabel, Select, MenuItem,
    Tooltip, useMediaQuery, useTheme, alpha, FormControlLabel, Switch, Checkbox, Link,
  } = MaterialUI;

  // v6.0 Apple 风格重构：默认主色改为 Apple 蓝
  const PRIMARY = '#0066cc';
  // v5.0.0：目录制多租户 —— 战队系统运行在 /{slug}/ 目录下，
  // 后端模板注入 window.SITE_BASE（平台=''，租户='/slug'），请求/跳转统一带前缀
  const BASE = window.SITE_BASE || '';

  // ---------- Apple 风格设计令牌（v6.0） ----------
  const BG_GRAY = '#f5f5f7';
  const CARD_BORDER = '#e8e8ed';
  const TEXT_PRIMARY = '#1d1d1f';
  const TEXT_SECONDARY = '#86868b';

  // ---------- 主题色（v3.6：个人中心调色盘，仅存本地 localStorage，不同步云端） ----------
  const THEME_KEY = 'yys_theme_primary';
  const THEME_PRESETS = [
    ['#0066cc', 'Apple 蓝'], ['#1d4ed8', '深蓝'], ['#7c3aed', '紫罗兰'], ['#db2777', '玫瑰红'],
    ['#dc2626', '正红'], ['#ea580c', '活力橙'], ['#ca8a04', '琥珀'], ['#16a34a', '草绿'],
    ['#0891b2', '湖蓝'], ['#4338ca', '靛蓝'], ['#334155', '石墨灰'], ['#78350f', '咖啡棕'],
  ];
  function getStoredPrimary() {
    try {
      const c = localStorage.getItem(THEME_KEY);
      if (/^#[0-9a-fA-F]{6}$/.test(c || '')) return c;
    } catch (e) { /* 忽略存储异常 */ }
    return PRIMARY;
  }
  function storePrimary(c) {
    try { localStorage.setItem(THEME_KEY, c); } catch (e) { /* 忽略 */ }
  }
  function makeTheme(primary) {
    return createTheme({
      palette: {
        primary: { main: primary },
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
            outlined: { borderColor: primary },
            // v5.6.1：危险操作按钮（color="error"）统一红框，修复"蓝框红字"观感
            outlinedError: { borderColor: '#ff3b30', '&:hover': { borderColor: '#ff3b30' } },
          },
        },
        // v6.0：卡片统一 Apple 风格——大圆角 + 浅描边 + 无阴影
        MuiPaper: {
          styleOverrides: {
            root: { backgroundImage: 'none' },
            outlined: { borderColor: CARD_BORDER, borderRadius: 16 },
            elevation1: { boxShadow: 'none', border: '1px solid ' + CARD_BORDER, borderRadius: 16 },
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
        MuiChip: {
          styleOverrides: { root: { borderRadius: 999 } },
        },
        MuiDialog: {
          styleOverrides: { paper: { borderRadius: 18 } },
        },
      },
    });
  }
  const theme = makeTheme(PRIMARY);

  // ==================== 请求工具 ====================
  // v5.1.0：隐身巡查——页面 URL 带 _sv 令牌时，GET 请求自动携带（平台超管只读浏览）
  const _SV = (function () {
    const m = location.search.match(/[?&]_sv=([^&]+)/);
    return m ? m[1] : '';
  })();

  // v5.36.0：端手分离——当前专区放在模块级变量里，所有 api() 自动带上，
  // 这样几十个页面不用逐处改请求；切专区时页面整体重挂载（见 Root 的 key）。
  const GS_ZONE_KEY = 'yys_game_zone';
  let GS_ZONE = '';
  // v5.36.13：平台协作模式（会话失效时回平台首页，而不是把协作人丢到战队登录页）
  let SUPPORT_MODE = false;
  function getStoredZone() {
    try { const v = localStorage.getItem(GS_ZONE_KEY); return (v === 'pc' || v === 'mobile') ? v : ''; } catch (e) { return ''; }
  }
  function storeZone(z) {
    try { localStorage.setItem(GS_ZONE_KEY, z); } catch (e) { /* 隐身模式忽略 */ }
  }
  // 专区上下文：开关、当前专区、谁能看哪端、八块模块开关
  const GameSideCtx = React.createContext(null);
  function useGameSide() {
    return React.useContext(GameSideCtx) || { on: false, zone: '', modules: {}, allowed: [], mine: '' };
  }
  function gsLabel(s) { return s === 'pc' ? '端游' : (s === 'mobile' ? '手游' : '全队'); }

  async function api(url, options) {
    const opts = options || {};
    const init = { method: opts.method || 'GET' };
    const gsHeaders = GS_ZONE ? { 'X-Game-Side': GS_ZONE } : {};
    if (opts.form) {
      init.headers = gsHeaders;
      init.body = opts.form;
    } else if (opts.body !== undefined && opts.body !== null) {
      init.headers = Object.assign({ 'Content-Type': 'application/json' }, gsHeaders);
      init.body = JSON.stringify(opts.body);
    } else if (Object.keys(gsHeaders).length > 0) {
      init.headers = gsHeaders;
    }
    let full = BASE + url;
    if (_SV && init.method === 'GET') {
      full += (full.indexOf('?') >= 0 ? '&' : '?') + '_sv=' + encodeURIComponent(_SV);
    }
    const res = await fetch(full, init);
    let json = null;
    try { json = await res.json(); } catch (e) { /* 非JSON响应 */ }
    if (res.status === 401) { location.href = SUPPORT_MODE ? '/' : (BASE + '/login'); throw new Error('unauthorized'); }
    if (!json) throw new Error('服务器响应异常');
    return json;
  }

  // v5.1.0：资源 URL 统一处理——/api/ 动态代理地址补租户前缀（静态 /storage/ 文件根路径直出不用），隐身模式追加 _sv 令牌
  // 侧栏站名：队名里没有「战队」两个字就补上，纯粹显示层补齐，不动库里的真实队名
  function brandTitle(name, suffixOn) {
    const s = (name || '').trim();
    if (!s) return '游戏战队系统';
    // v5.30.1：以前这个「战队」是无条件补的，现在队长能关掉（me.team_name_suffix）
    if (suffixOn === false) return s;
    return s.indexOf('战队') >= 0 ? s : s + '战队';
  }

  function resUrl(p) {
    if (!p || typeof p !== 'string' || p.charAt(0) !== '/' || p.indexOf('/api/') !== 0) return p;
    var u = p;
    if (BASE && u.indexOf(BASE + '/') !== 0) { u = BASE + u; }
    if (_SV) { u += (u.indexOf('?') >= 0 ? '&' : '?') + '_sv=' + encodeURIComponent(_SV); }
    return u;
  }

  function fmtTime(t) { return t ? String(t).slice(0, 16) : ''; }

  // 聊天时间戳（仿微信：今天 HH:mm，今年 M月D日 HH:mm，更早带年份）
  function fmtChatTime(d) {
    const now = new Date();
    const pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    const hm = pad(d.getHours()) + ':' + pad(d.getMinutes());
    if (d.toDateString() === now.toDateString()) return hm;
    if (d.getFullYear() === now.getFullYear()) return (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + hm;
    return d.getFullYear() + '年' + (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + hm;
  }

  // 全局 toast / lightbox 总线（由 Root 注册实现）
  let _toast = function () {};
  let _lightbox = function () {};
  function toast(msg, sev) { _toast(msg, sev || 'info'); }
  function lightbox(src) { _lightbox(src); }

  // 剪贴板复制（clipboard API + execCommand 回退）
  function copyText(text) {
    const done = function () { toast('枪码已复制到剪贴板', 'success'); };
    const fallback = function () {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败', 'error'); }
      document.body.removeChild(ta);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else {
      fallback();
    }
  }

  // ==================== 通用小组件 ====================

  // 用户头像（v3.4 后端代理 /api/avatar/:uid，QQ 号不出前端；加载失败 MUI 自动回退首字）
  function UserAvatar({ user, size, sx }) {
    size = size || 36;
    const nick = String((user && (user.nickname || user.username)) || '?');
    let letter = (nick.charAt(0) || '?').toUpperCase();
    if (!/[一-龥a-zA-Z0-9]/.test(letter)) letter = '?';
    const base = { width: size, height: size, fontSize: size * 0.42, bgcolor: '#e2e8f0', color: '#334155' };
    if (user && user.id) {
      return <Avatar src={BASE + '/api/avatar/' + parseInt(user.id, 10) + (_SV ? '?_sv=' + encodeURIComponent(_SV) : '')} sx={Object.assign(base, sx || {})}>{letter}</Avatar>;
    }
    return <Avatar sx={Object.assign(base, sx || {})}>{letter}</Avatar>;
  }

  function PanelHead({ title, desc, children }) {
    return (
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ xs: 'flex-start', sm: 'center' }}
        justifyContent="space-between" sx={{ mb: 2 }}>
        <Box>
          <Typography variant="h6" fontWeight={600}>{title}</Typography>
          {desc && <Typography variant="body2" color="text.secondary">{desc}</Typography>}
        </Box>
        {children && <Stack direction="row" spacing={1} flexWrap="wrap">{children}</Stack>}
      </Stack>
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

  // 职责标签彩色 Chip（v5.3.0）：彩色底 + 白字小号，聊天/伪军/小队通用展示点渲染。
  // tag = { id, name, color }；为 null/空时不渲染。
  function RoleTagChip({ tag, sx }) {
    if (!tag || !tag.name) return null;
    return (
      <Chip size="small" label={tag.name} sx={Object.assign({
        bgcolor: tag.color || '#0f766e', color: '#fff', height: 20, fontSize: 11,
        fontWeight: 600, lineHeight: '20px', px: 0.4,
      }, sx || {})} />
    );
  }

  function Pager({ page, size, total, onChange }) {
    const pages = Math.max(1, Math.ceil(total / size));
    return (
      <Stack direction="row" spacing={1.5} alignItems="center" justifyContent="center" sx={{ mt: 2.5 }}>
        <Button size="small" variant="outlined" disabled={page <= 1} onClick={() => onChange(page - 1)}>上一页</Button>
        <Typography variant="body2" color="text.secondary">第 {page} / {pages} 页 · 共 {total} 条</Typography>
        <Button size="small" variant="outlined" disabled={page >= pages} onClick={() => onChange(page + 1)}>下一页</Button>
      </Stack>
    );
  }

  // 图片灯箱
  function LightboxDialog({ src, onClose }) {
    return (
      <Dialog open={!!src} onClose={onClose} maxWidth="lg"
        PaperProps={{ sx: { bgcolor: 'transparent', boxShadow: 'none', m: 2 } }}>
        <Box component="img" src={src} alt="预览" onClick={onClose}
          sx={{ maxWidth: '100%', maxHeight: '88vh', display: 'block', borderRadius: '8px', cursor: 'zoom-out' }} />
      </Dialog>
    );
  }

  const statusChip = function (s) {
    if (s === 'approved') return <Chip size="small" color="success" label="已通过" />;
    if (s === 'rejected') return <Chip size="small" color="error" label="已拒绝" />;
    if (s === 'banned') return <Chip size="small" color="error" label="已封禁" />;
    return <Chip size="small" color="warning" label="待审核" />;
  };

  // v5.14.0：在线成员卡片。人数拆「在线 / 管理员 / 成员」三块，下面列头像+昵称，
  // 管理员排在前面，超过 12 人折叠成「展开全部」。数据来自 /api/dashboard 本身，跟着 15 秒轮询走
  function OnlineMembersCard({ data, me }) {
    const [expanded, setExpanded] = useState(false);
    if (!data) {
      return <Paper variant="outlined" sx={{ p: 2 }}><LoadingBox /></Paper>;
    }
    const list = data.online_list || [];
    const mins = Math.max(1, Math.round((data.online_window || 300) / 60));
    const shown = expanded ? list : list.slice(0, 12);
    const nums = [
      { k: '在线', v: Number(data.online_count || 0), c: '#166534', bg: '#f0fdf4' },
      { k: '管理员', v: Number(data.online_admins || 0), c: '#1d4ed8', bg: '#eff6ff' },
      { k: '成员', v: Number(data.online_members || 0), c: '#7c3aed', bg: '#f5f3ff' },
    ];
    return (
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
          <Typography fontWeight={600} sx={{ flex: 1 }}>在线成员</Typography>
          <Box sx={{ width: 8, height: 8, borderRadius: '50%', mr: 0.8,
            bgcolor: list.length > 0 ? 'success.main' : '#cbd5e1' }} />
          <Typography variant="caption" color="text.secondary">{mins} 分钟内有操作</Typography>
        </Box>
        <Stack direction="row" spacing={1} sx={{ mb: 1.2 }}>
          {nums.map(function (n) {
            return (
              <Box key={n.k} sx={{ flex: 1, textAlign: 'center', py: 0.8, borderRadius: '10px', bgcolor: n.bg }}>
                <Typography variant="h6" fontWeight={700} sx={{ color: n.c, lineHeight: 1.3 }}>{n.v}</Typography>
                <Typography variant="caption" sx={{ color: n.c }}>{n.k}</Typography>
              </Box>
            );
          })}
        </Stack>
        {list.length === 0 ? <EmptyBox text="当前没有人在线" /> : (
          <Box>
            {shown.map(function (u, i) {
              const isMe = !!me && Number(u.id) === Number(me.id);
              return (
                <Box key={u.id} sx={{
                  display: 'flex', alignItems: 'center', gap: 1.2, py: 0.6,
                  borderTop: i === 0 ? 'none' : '1px solid #f1f5f9',
                }}>
                  <Box sx={{ position: 'relative', flexShrink: 0 }}>
                    <UserAvatar user={{ id: u.id, nickname: u.name }} size={30} />
                    <Box sx={{
                      position: 'absolute', right: -1, bottom: -1, width: 9, height: 9,
                      borderRadius: '50%', bgcolor: 'success.main', border: '1.5px solid #fff',
                    }} />
                  </Box>
                  <Typography variant="body2" noWrap sx={{ flex: 1, minWidth: 0, fontWeight: isMe ? 700 : 400 }}>
                    {u.name}
                  </Typography>
                  {u.is_admin && <Chip size="small" color="primary" variant="outlined" label="管理员" />}
                  {isMe && <Chip size="small" label="本人" sx={{ bgcolor: '#f5f5f7' }} />}
                </Box>
              );
            })}
            {(list.length > 12 || expanded) && (
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mt: 0.6 }}>
                <Button size="small" color="inherit" onClick={() => setExpanded(!expanded)}>
                  {expanded ? '收起' : '展开全部 ' + list.length + ' 人'}
                </Button>
                {data.online_truncated && !expanded && (
                  <Typography variant="caption" color="text.secondary">人数过多，这里只统计前 {list.length} 人</Typography>
                )}
              </Box>
            )}
          </Box>
        )}
      </Paper>
    );
  }

  // ==================== 仪表盘（v3.6：聚合现有功能的首页） ====================
  // 卡里没内容时只留这一行：原来空着也要铺一张 118–175px 的卡，手机首屏净是「暂无」
  function DashNote({ text }) {
    return (
      <Typography sx={{ fontSize: 12, color: '#5f6b7f', py: 0.75, lineHeight: '18px' }}>{text}</Typography>
    );
  }

  function DashboardPage({ me }) {
    const [data, setData] = useState(null);
    const [races, setRaces] = useState([]);
    const [imgs, setImgs] = useState(null);
    const [codes, setCodes] = useState(null);
    const [chat, setChat] = useState(null);

    // v5.6.0：公告送达确认——公告列表含未读/被提醒标记；点开详情即标记已读
    const [notices, setNotices] = useState(null);
    const [noticeOpen, setNoticeOpen] = useState(null);

    // v5.3.0：每日签到状态 + 今日运势弹窗
    const [ck, setCk] = useState(null);
    const [ckBusy, setCkBusy] = useState(false);
    const [fortune, setFortune] = useState('');   // 非空时弹今日运势对话框

    const loadCheckin = useCallback(async function () {
      try {
        const json = await api('/api/checkin');
        if (json.code === 0) setCk(json.data);
      } catch (e) { /* 静默：签到卡片加载失败不影响仪表盘 */ }
    }, []);
    useEffect(function () { loadCheckin(); }, [loadCheckin]);

    const doCheckin = async function () {
      if (ckBusy || (ck && ck.checked)) return;
      setCkBusy(true);
      try {
        const json = await api('/api/checkin', { method: 'POST' });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setFortune(json.data.fortune_img || '');
          loadCheckin();
        } else {
          toast(json.msg, 'error');
          loadCheckin();
        }
      } catch (e) { toast('签到失败，请稍后再试', 'error'); } finally { setCkBusy(false); }
    };

    // v5.6.0：公告（含未读标记/被提醒置顶）；打开详情即标记已读
    const loadNotices = useCallback(async function () {
      try {
        const json = await api('/api/notices');
        if (json.code === 0) setNotices(json.data);
      } catch (e) { /* 静默 */ }
    }, []);
    useEffect(function () { loadNotices(); }, [loadNotices]);

    const openNotice = function (a) {
      setNoticeOpen(a);
      if (!a.is_read) {
        api('/api/notices/' + a.id + '/read', { method: 'POST' })
          .then(function () { loadNotices(); })
          .catch(function () { /* 静默 */ });
      }
    };

    // 一次拉齐：仪表盘统计/撞车场次/最新图片/最新枪码/聊天动态（全部是已有接口）
    const load = useCallback(async function () {
      try {
        const rs = await Promise.all([
          api('/api/dashboard'),
          api('/api/races'),
          api('/api/images?page=1&size=4'),
          api('/api/guncodes?page=1&size=3'),
          api('/api/chat/messages'),
        ]);
        if (rs[0].code === 0) setData(rs[0].data);
        if (rs[1].code === 0) setRaces((rs[1].data && rs[1].data.list) || []);
        if (rs[2].code === 0) setImgs(rs[2].data);
        if (rs[3].code === 0) setCodes(rs[3].data);
        if (rs[4].code === 0) setChat((((rs[4].data || {}).list) || []).slice(-4));
      } catch (e) { /* 静默 */ }
    }, []);

    useEffect(function () {
      load();
      const t = setInterval(function () { load(); loadNotices(); }, 15000);
      return function () { clearInterval(t); };
    }, [load, loadNotices]);

    // 问候语 + 今日日期
    const hour = new Date().getHours();
    const greet = hour < 6 ? '夜深了' : hour < 12 ? '上午好' : hour < 14 ? '中午好' : hour < 18 ? '下午好' : '晚上好';
    const today = new Date();
    const dateText = today.getFullYear() + ' 年 ' + (today.getMonth() + 1) + ' 月 ' + today.getDate() + ' 日 · 星期' + '日一二三四五六'.charAt(today.getDay());

    // 撞车倒计时文案（粗粒度，15 秒轮询刷新）
    const raceLeft = function (rt) {
      const diff = new Date(String(rt).replace(/-/g, '/')).getTime() - Date.now();
      if (diff <= 0) return '已开赛';
      const d = Math.floor(diff / 86400000), h = Math.floor(diff % 86400000 / 3600000), m = Math.floor(diff % 3600000 / 60000);
      return (d > 0 ? d + ' 天 ' : '') + h + ' 小时 ' + m + ' 分钟后';
    };

    const stats = [
      { label: '注册人数', val: data ? data.user_count : '-', color: '#1d4ed8', bg: '#eff6ff',
        icon: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z' },
      { label: '在线人数', val: data ? data.online_count : '-', color: '#166534', bg: '#f0fdf4',
        icon: 'M7 2v11h3v9l7-12h-4l4-8z' },
      { label: '已通过图片', val: imgs ? imgs.total : '-', color: '#7c3aed', bg: '#f5f3ff',
        icon: 'M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z' },
      { label: '枪码数量', val: codes ? codes.total : '-', color: '#b45309', bg: '#fffbeb',
        icon: 'M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z' },
    ];

    return (
      <Box>
        {/* v5.25.0：欢迎横幅与每日签到合成一张「今天」卡（原来两张卡加起来手机上要占 373px） */}
        <Paper sx={{ p: { xs: 2, md: 2.5 }, mb: 2 }}>
          <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 2 }}>
            <Box sx={{ flex: '1 1 210px', minWidth: 0 }}>
              <Typography variant="h6" fontWeight={700} sx={{ color: TEXT_PRIMARY, letterSpacing: '-0.3px', fontSize: 17 }}>
                {greet}，{me.nickname}
              </Typography>
              <Typography sx={{ color: '#5f6b7f', mt: 0.3, display: 'block', fontSize: 12, lineHeight: '17px' }}>{dateText}</Typography>
              <Box sx={{ mt: 1, display: 'flex', gap: 1, flexWrap: 'wrap', alignItems: 'center' }}>
                <Chip size="small" label={me.role === 'admin' ? '管理员' : '队员'}
                  sx={{ bgcolor: '#f5f5f7', color: TEXT_PRIMARY, fontWeight: 500 }} />
                <Chip size="small" label={me.team_name || '未加入小队'}
                  sx={{ bgcolor: '#f5f5f7', color: TEXT_PRIMARY, fontWeight: 500 }} />
              </Box>
              <Typography sx={{ mt: 0.8, fontSize: 12, lineHeight: '17px', color: '#5f6b7f' }}>
                {ck === null ? '正在取签到…' : (ck.checked
                  ? ('已连续签到 ' + ck.streak + ' 天 · 本站 ' + ck.site_count + ' 人签到了')
                  : ('今天还没签到 · 本站已有 ' + ck.site_count + ' 人签到'
                    + (ck.streak > 0 ? '（你断了 ' + ck.streak + ' 天的纪录）' : '')))}
                {ck && ck.team_top && ck.team_top.length > 0 ? (' · 本队最早：' + ck.team_top.map(function (t) { return t.nickname; }).join('、')) : ''}
              </Typography>
            </Box>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.2 }}>
              {ck && ck.checked && ck.fortune_img && (
                <Box component="img" src={ck.fortune_img} alt="今日运势" onClick={() => setFortune(ck.fortune_img)}
                  sx={{ width: 44, height: 44, objectFit: 'cover', borderRadius: '10px', cursor: 'zoom-in',
                    border: '1px solid ' + CARD_BORDER, display: { xs: 'none', md: 'block' } }} />
              )}
              {ck === null ? <CircularProgress size={26} /> : (
                <Button variant={ck.checked ? 'outlined' : 'contained'} disabled={ck.checked || ckBusy}
                  onClick={doCheckin} sx={{ minWidth: 100 }}>
                  {ck.checked ? '今日已签到' : (ckBusy ? '签到中...' : '每日签到')}
                </Button>
              )}
            </Box>
          </Box>
          {ck && ck.saying ? (
            <Typography sx={{ mt: 1, fontSize: 12, lineHeight: '17px', color: '#5f6b7f' }}>
              每日一言：{ck.saying}
            </Typography>
          ) : null}
          {/* 这三个入口手机上 redundant（聊天室在底部、图库与撞车在功能页），只在电脑宽度出现 */}
          <Box sx={{ display: { xs: 'none', md: 'flex' }, gap: 1, mt: 1.2 }}>
            <Button component="a" href="#/chat" variant="outlined" size="small">进聊天室</Button>
            <Button component="a" href="#/images" variant="outlined" size="small">游戏图库</Button>
            <Button component="a" href="#/race" variant="outlined" size="small">撞车赛事</Button>
          </Box>
        </Paper>


        {/* 今日运势弹窗（v5.3.0）：签到后随机表情包，当天固定展示这张 */}
        <Dialog open={!!fortune} onClose={() => setFortune('')} maxWidth="xs" fullWidth>
          <DialogTitle sx={{ textAlign: 'center' }}>今日运势</DialogTitle>
          <DialogContent sx={{ textAlign: 'center', pb: 3 }}>
            <Box component="img" src={fortune} alt="今日运势表情包" sx={{ maxWidth: 220, borderRadius: '10px' }} />
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.5 }}>
              今天的运势表情包已保存，今天再打开签到卡片也展示这张哦~
            </Typography>
          </DialogContent>
          <DialogActions sx={{ justifyContent: 'center' }}>
            <Button onClick={() => setFortune('')}>关闭</Button>
          </DialogActions>
        </Dialog>

        {/* v5.6.0：今日值班卡片 + 换班发起/确认（未配置值班时自动不渲染） */}
        <DutyTodayCard />

        {/* v5.36.34：比赛日集合点名（今天与接下来有单才出现，没有就完全不占位） */}
        <RollCallCard />

        {/* 统计卡片 */}
        <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: { xs: 0.8, md: 2 }, mb: 2 }}>
          {stats.map(function (s) {
            // v5.25.1：手机上一格只有 79px 宽，图标+文字横着排时「已通过图片」会压到隔壁卡上 27px。
            // 改成两行网格：数字在左、色块图标在右、标签单独占一整行；桌面仍是图标在左的横排。
            return (
              <Paper key={s.label} variant="outlined" sx={{
                p: { xs: 1, md: 2 }, minHeight: { xs: 78, md: 90 }, minWidth: 0,
                display: 'grid', alignItems: 'center', alignContent: 'center',
                gap: { xs: 0.2, md: 1.2 },
                gridTemplateColumns: { xs: 'minmax(0, 1fr)', md: 'auto minmax(0, 1fr)' },
                gridTemplateAreas: { xs: '"icon" "num" "lab"', md: '"icon num" "icon lab"' },
              }}>
                <Box sx={{
                  gridArea: 'icon', justifySelf: { xs: 'start', md: 'stretch' }, alignSelf: 'center',
                  width: { xs: 24, md: 44 }, height: { xs: 24, md: 44 }, borderRadius: '10px', bgcolor: s.bg, color: s.color, flexShrink: 0,
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                }}>
                  <svg viewBox="0 0 24 24" width={18} height={18} fill="currentColor"><path d={s.icon} /></svg>
                </Box>
                <Typography variant="h5" fontWeight={700} sx={{ gridArea: 'num', minWidth: 0, fontSize: { xs: 17, md: 24 }, lineHeight: { xs: '20px', md: '32px' } }}>{s.val}</Typography>
                <Typography variant="caption" sx={{ gridArea: 'lab', minWidth: 0, fontSize: { xs: 11, md: 12 }, color: '#5f6b7f', lineHeight: 1.15, whiteSpace: { xs: 'normal', md: 'nowrap' } }}>{s.label}</Typography>
              </Paper>
            );
          })}
        </Box>

        {/* 中部：公告（左 2/3） + 撞车日程 & 聊天动态（右 1/3）
            v3.9.4：轨道必须用 minmax(0, …)——纯 fr 轨道的最小宽度=内容最小宽度，
            右列聊天 noWrap 长消息会把轨道撑爆、把左列公告挤成单字竖排 */}
        <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'minmax(0, 1fr)', md: 'minmax(0, 2fr) minmax(0, 1fr)' }, alignItems: 'start', gap: 2, mb: 2 }}>
          <Paper variant="outlined" sx={{ p: 2, minWidth: 0 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1.2 }}>
              <Typography fontWeight={600} sx={{ flex: 1 }}>系统公告</Typography>
              {/* v5.6.0：未读角标（红底数字） */}
              {notices && notices.unread_count > 0 && (
                <Chip size="small" color="error" label={notices.unread_count + ' 条未读'} />
              )}
              <Button size="small" onClick={loadNotices}>刷新</Button>
            </Box>
            {!notices ? <LoadingBox /> :
              !notices.list || notices.list.length === 0 ? <DashNote text="最近没有新公告" /> :
              notices.list.slice(0, 3).map(function (a) {
                return (
                  <Paper key={a.id} variant="outlined" onClick={() => openNotice(a)} sx={{
                    mb: 1.2, overflow: 'hidden', cursor: 'pointer', transition: 'border-color .15s',
                    borderColor: a.reminded ? 'warning.main' : undefined,
                    '&:hover': { borderColor: 'primary.main' },
                  }}>
                    {/* v5.6.0：被管理员提醒的公告置顶并强提示（列表顺序由服务端排定） */}
                    {a.reminded && (
                      <Alert severity="warning" sx={{ borderRadius: 0, py: 0.2 }}>管理员提醒你查看这条公告</Alert>
                    )}
                    {a.image && (
                      <Box component="img" src={a.image} alt=""
                        sx={{ width: '100%', maxHeight: 200, objectFit: 'cover', display: 'block' }} />
                    )}
                    <Box sx={{ p: 1.8 }}>
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        {!a.is_read && (
                          <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: 'error.main', flexShrink: 0 }} />
                        )}
                        <Typography fontWeight={600} sx={{ flex: 1 }}>{a.title}</Typography>
                        {a.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(a.game_side)} /> : null}
                        {!a.is_read && <Chip size="small" color="error" variant="outlined" label="未读" />}
                      </Box>
                      <Typography variant="body2" color="text.secondary" sx={{
                        whiteSpace: 'pre-wrap', mt: 0.4, overflow: 'hidden', display: '-webkit-box',
                        WebkitLineClamp: 2, WebkitBoxOrient: 'vertical',
                      }}>{a.content}</Typography>
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.8 }}>
                        {/* v5.36.34：定时公告显示真正发出来的那一刻，不是队长写好它的那一刻 */}
                        {fmtTime(a.publish_at || a.created_at)} · 点开查看详情
                      </Typography>
                    </Box>
                  </Paper>
                );
              })}
          </Paper>

          {/* v5.6.0：公告详情弹窗——打开即标记已读（未读角标消失，管理员端回执统计 +1） */}
          <Dialog open={!!noticeOpen} onClose={() => setNoticeOpen(null)} fullWidth maxWidth="sm">
            {noticeOpen && (
              <React.Fragment>
                <DialogTitle>{noticeOpen.title}</DialogTitle>
                <DialogContent dividers>
                  {noticeOpen.image && (
                    <Box component="img" src={noticeOpen.image} alt="" onClick={() => lightbox(noticeOpen.image)}
                      sx={{ width: '100%', borderRadius: '8px', mb: 1.5, cursor: 'zoom-in', display: 'block' }} />
                  )}
                  <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>{noticeOpen.content}</Typography>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.5 }}>
                    发布于 {fmtTime(noticeOpen.publish_at || noticeOpen.created_at)}
                  </Typography>
                </DialogContent>
                <DialogActions><Button onClick={() => setNoticeOpen(null)}>关闭</Button></DialogActions>
              </React.Fragment>
            )}
          </Dialog>

          <Stack spacing={2} sx={{ minWidth: 0 }}>
            {/* 撞车日程 */}
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <Typography fontWeight={600} sx={{ flex: 1 }}>撞车日程</Typography>
                <Box component="a" href="#/race" sx={{ fontSize: 13, color: 'primary.main', textDecoration: 'none' }}>查看全部 →</Box>
              </Box>
              {races.length === 0 ? <DashNote text="最近没有排撞车" /> :
                races.map(function (r) {
                  return (
                    <Box key={r.id} sx={{
                      display: 'flex', alignItems: 'center', gap: 1.2, py: 1,
                      borderBottom: '1px solid #f1f5f9', '&:last-child': { borderBottom: 'none' },
                    }}>
                      <Box sx={{
                        width: 8, height: 8, borderRadius: '50%', bgcolor: 'primary.main', flexShrink: 0,
                      }} />
                      <Box sx={{ flex: 1, minWidth: 0 }}>
                        <Typography variant="body2" fontWeight={600} noWrap>{r.title}</Typography>
                        <Typography variant="caption" color="text.secondary">{fmtTime(r.race_time)}</Typography>
                      </Box>
                      <Chip size="small" color="primary" variant="outlined" label={raceLeft(r.race_time)} />
                    </Box>
                  );
                })}
            </Paper>

            {/* 聊天动态 */}
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <Typography fontWeight={600} sx={{ flex: 1 }}>聊天动态</Typography>
                <Box component="a" href="#/chat" sx={{ fontSize: 13, color: 'primary.main', textDecoration: 'none' }}>进聊天室 →</Box>
              </Box>
              {!chat ? <LoadingBox /> : chat.length === 0 ? <DashNote text="群里还没人说话" /> :
                chat.map(function (m) {
                  return (
                    <Box key={m.id} sx={{ display: 'flex', gap: 1, py: 0.8, alignItems: 'flex-start' }}>
                      <UserAvatar user={{ id: m.user_id, nickname: m.nickname }} size={26} />
                      <Box sx={{ flex: 1, minWidth: 0 }}>
                        <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>
                          {m.nickname} · {fmtChatTime(new Date(String(m.created_at).replace(/-/g, '/')))}
                        </Typography>
                        <Typography variant="body2" noWrap>{m.content}</Typography>
                      </Box>
                    </Box>
                  );
                })}
            </Paper>

            {/* v5.14.0：在线成员卡片（与顶部「在线人数」同源，跟着 15 秒轮询刷新） */}
            <OnlineMembersCard data={data} me={me} />
          </Stack>
        </Box>

        {/* 最新图片 */}
        <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
          <Box sx={{ display: 'flex', alignItems: 'center', mb: 1.2 }}>
            <Typography fontWeight={600} sx={{ flex: 1 }}>最新图片</Typography>
            <Box component="a" href="#/images" sx={{ fontSize: 13, color: 'primary.main', textDecoration: 'none' }}>图片墙 →</Box>
          </Box>
          {!imgs ? <LoadingBox /> : (!imgs.list || imgs.list.length === 0) ? <DashNote text="还没有通过审核的图片" /> :
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, minmax(0, 1fr))', md: 'repeat(4, minmax(0, 1fr))' }, gap: 1.5 }}>
              {imgs.list.map(function (im) {
                return (
                  <Box key={im.id} onClick={() => lightbox(resUrl(im.img_url))} sx={{ cursor: 'zoom-in', minWidth: 0 }}>
                    <Box component="img" src={resUrl(im.img_url)} alt={im.title} loading="lazy" sx={{
                      width: '100%', height: 120, objectFit: 'cover', borderRadius: '8px', display: 'block',
                      border: '1px solid #e2e8f0',
                    }} />
                    <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block', mt: 0.5 }}>
                      {im.title || '未命名'} · {im.uploader}
                    </Typography>
                  </Box>
                );
              })}
            </Box>}
        </Paper>

        {/* 最新枪码 */}
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Box sx={{ display: 'flex', alignItems: 'center', mb: 0.6 }}>
            <Typography fontWeight={600} sx={{ flex: 1 }}>最新枪码</Typography>
            <Box component="a" href="#/guncodes" sx={{ fontSize: 13, color: 'primary.main', textDecoration: 'none' }}>全部枪码 →</Box>
          </Box>
          {!codes ? <LoadingBox /> : (!codes.list || codes.list.length === 0) ? <DashNote text="还没有人发枪码" /> :
            codes.list.map(function (g) {
              return (
                <Box key={g.id} sx={{
                  display: 'flex', alignItems: 'center', gap: 1.2, py: 1,
                  borderBottom: '1px solid #f1f5f9', '&:last-child': { borderBottom: 'none' },
                }}>
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography variant="body2" fontWeight={600} noWrap>{g.title}</Typography>
                    <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>
                      {g.author} · {fmtTime(g.created_at)}{g.comment_count ? ' · 评论 ' + g.comment_count : ''}
                    </Typography>
                  </Box>
                  <Button size="small" variant="outlined" onClick={() => copyText(g.code)}>复制枪码</Button>
                </Box>
              );
            })}
        </Paper>
      </Box>
    );
  }

  // ==================== 游戏图片 ====================
  function ImagesPage() {
    const [tab, setTab] = useState('gallery');
    const [list, setList] = useState(null);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [uploadOpen, setUploadOpen] = useState(false);
    const SIZE = 12;

    const loadGallery = useCallback(async function (p) {
      if (p) setPage(p);
      setList(null);
      try {
        const json = await api('/api/images?page=' + (p || page) + '&size=' + SIZE);
        setTotal(json.data.total);
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, [page]);

    const loadMy = useCallback(async function () {
      setList(null);
      try {
        const json = await api('/api/images/my');
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, []);

    const switchTab = function (t) {
      setTab(t);
      if (t === 'gallery') loadGallery(1); else loadMy();
    };

    useEffect(function () { loadGallery(1); }, []);  // eslint-disable-line

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="游戏图片展示">
          <Button variant={tab === 'gallery' ? 'contained' : 'outlined'} onClick={() => switchTab('gallery')}>图片墙</Button>
          <Button variant={tab === 'my' ? 'contained' : 'outlined'} onClick={() => switchTab('my')}>我的上传</Button>
          <Button variant="contained" onClick={() => setUploadOpen(true)}>上传图片</Button>
        </PanelHead>

        {list === null ? <LoadingBox /> : list.length === 0 ? (
          <EmptyBox text={tab === 'gallery' ? '暂无图片，来上传第一张吧' : '你还没有上传过图片'} />
        ) : (
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 2 }}>
            {list.map(function (img) {
              const src = resUrl(img.img_url || img.file);
              return (
                <Paper key={img.id} variant="outlined" sx={{ overflow: 'hidden' }}>
                  <Box component="img" src={src} alt="" loading="lazy" onClick={() => lightbox(src)}
                    sx={{ width: '100%', height: 160, objectFit: 'cover', display: 'block', cursor: 'zoom-in', bgcolor: '#f1f5f9' }} />
                  <Box sx={{ p: 1.5 }}>
                    <Typography variant="body2" fontWeight={600} noWrap>{img.title}</Typography>
                    <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between" sx={{ mt: 0.5 }}>
                      {tab === 'my' ? statusChip(img.status) : (
                        <Typography variant="caption" color="text.secondary" noWrap>{img.uploader}</Typography>
                      )}
                      <Typography variant="caption" color="text.secondary">{fmtTime(img.created_at)}</Typography>
                    </Stack>
                  </Box>
                </Paper>
              );
            })}
          </Box>
        )}

        {tab === 'gallery' && list && list.length > 0 && (
          <Pager page={page} size={SIZE} total={total} onChange={function (p) { loadGallery(p); }} />
        )}

        <UploadImgDialog open={uploadOpen} onClose={function () { setUploadOpen(false); }}
          onDone={function () { setUploadOpen(false); switchTab('my'); }} />
      </Paper>
    );
  }

  function UploadImgDialog({ open, onClose, onDone }) {
    const [title, setTitle] = useState('');
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const fileRef = useRef(null);

    const submit = async function () {
      if (busy) return;   // 防连点：慢响应时重复点击会产生多次上传请求
      if (!title.trim() || !file) { toast('请填写标题并选择图片', 'error'); return; }
      setBusy(true);
      const fd = new FormData();
      fd.append('title', title.trim());
      fd.append('file', file);
      try {
        const json = await api('/api/images/upload', { method: 'POST', form: fd });
        if (json.code === 0) {
          setTitle(''); setFile(null);
          if (fileRef.current) fileRef.current.value = '';
          toast(json.msg, 'success');
          onDone();
        } else {
          toast(json.msg, 'error');
        }
      } catch (err) {
        toast('上传失败，请稍后再试', 'error');
      } finally {
        setBusy(false);
      }
    };

    return (
      <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>上传游戏图片</DialogTitle>
        <DialogContent>
          <TextField label="图片标题" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={title} onChange={(e) => setTitle(e.target.value)}
            inputProps={{ maxLength: 128 }} placeholder="给图片起个名字" />
          <input
            ref={fileRef} type="file"
            onChange={(e) => setFile(e.target.files[0] || null)}
            accept="image/jpeg,image/png,image/gif,image/webp"
            style={{ display: 'none' }}
          />
          <Box onClick={() => { if (fileRef.current) fileRef.current.click(); }} sx={{
            border: '1.5px dashed', borderColor: file ? 'primary.main' : '#b6c4c1', borderRadius: '10px',
            p: 2.5, textAlign: 'center', cursor: 'pointer',
            bgcolor: file ? (t => alpha(t.palette.primary.main, 0.06)) : 'transparent', transition: 'background-color .15s',
            '&:hover': { bgcolor: (t => alpha(t.palette.primary.main, 0.1)) },
          }}>
            <Typography variant="body2" fontWeight={500} color={file ? 'primary' : 'text.secondary'}>
              {file ? ('已选择：' + file.name) : '点击选择图片 *'}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              支持 jpg / png / gif / webp，不超过 5MB；上传后需管理员审核
            </Typography>
          </Box>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={submit}>{busy ? '上传中...' : '上传'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ==================== 聊天：战队群 + 私聊（v5.19.0） ====================

  // 「在线 N / 共 M」两个页签共用一份：数据来自 /api/chat/online，与仪表盘同一张表同一个 5 分钟窗口
  function ChatOnlineChip({ info }) {
    if (!info) return null;
    return (
      <Chip size="small" label={'在线 ' + (info.count || 0) + ' / 共 ' + (info.total || 0)}
        sx={{ bgcolor: '#e8f5e9', color: '#166534', fontWeight: 600 }} />
    );
  }

  // v5.32.0：全站大厅下线，聊天室只剩战队群与私聊两个页签；还要能从群聊/成员主页直接落到某一段私聊上
  // hash 上带查询段（#/chat?dm=7&name=张三），Root 那边把查询切掉了，这里自己读
  function hashParam(key) {
    const q = String(location.hash || '');
    const at = q.indexOf('?');
    if (at < 0) return '';
    const pairs = q.slice(at + 1).split('&');
    for (let i = 0; i < pairs.length; i++) {
      const kv = pairs[i].split('=');
      if (decodeURIComponent(kv[0]) === key) return decodeURIComponent(kv[1] || '');
    }
    return '';
  }
  // 跳到某个人的私聊：名字一起带上，省得进来先是一片空
  function gotoDm(id, name) {
    if (!id) return;
    location.hash = '#/chat?dm=' + encodeURIComponent(id) + '&name=' + encodeURIComponent(name || '');
  }
  function gotoProfile(id) {
    if (!id) return;
    location.hash = '#/members?u=' + encodeURIComponent(id);
  }

  // 跳转工具：战队群、私聊、成员主页共用
  function ChatPage({ me, onDmUnread, onGroupSeen }) {
    // 带 ?dm= 进来就是要谈私聊，直接落在那一页签上（v5.32.0：大厅没了，那一档 ?tab= 也不再有人写）
    const [tab, setTab] = useState(function () { return Number(hashParam('dm')) > 0 ? 'dm' : 'group'; });
    const [online, setOnline] = useState(null);
    const [dmInfo, setDmInfo] = useState(null);      // /api/chat/dm/conversations 的整个 data
    const [dmWith, setDmWith] = useState(function () {   // 从群聊/成员主页发起的那个对象（支持 #/chat?dm=）
      const id = Number(hashParam('dm')) || 0;
      return id > 0 ? { id: id, name: hashParam('name'), at: Date.now() } : null;
    });
    const dmBusyRef = useRef(false);

    const loadDm = useCallback(async function () {
      if (dmBusyRef.current) return;
      dmBusyRef.current = true;
      try {
        const j = await api('/api/chat/dm/conversations');
        if (j.code === 0) setDmInfo(j.data);
      } catch (e) { /* 轮询失败静默 */ } finally { dmBusyRef.current = false; }
    }, []);

    useEffect(function () {
      const pull = async function () {
        try {
          const j = await api('/api/chat/online');
          if (j.code === 0) setOnline(j.data);
        } catch (e) { /* 静默 */ }
      };
      pull();
      const t = setInterval(pull, 20000);   // 私聊页签的「在线队友」用的就是这份，20 秒足够新
      return function () { clearInterval(t); };
    }, []);

    useEffect(function () {
      loadDm();
      const t = setInterval(loadDm, 5000);
      return function () { clearInterval(t); };
    }, [loadDm]);

    let unread = 0;
    if (dmInfo && dmInfo.list) {
      dmInfo.list.forEach(function (c) { unread += (c.unread || 0); });
    }
    useEffect(function () { if (onDmUnread) { onDmUnread(unread); } }, [unread]);   // eslint-disable-line

    const startDm = function (peer) {
      if (!peer || !peer.id) return;
      setDmWith({ id: peer.id, name: peer.name, at: Date.now() });
      setTab('dm');
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 }, display: 'flex', flexDirection: 'column', height: { xs: 'calc(100vh - 120px)', md: 'calc(100vh - 110px)' }, minHeight: 480 }}>
        <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1}
          sx={{ mb: 1, flexWrap: 'wrap', useFlexGap: true }}>
          <Tabs value={tab} onChange={(e, v) => setTab(v)} sx={{ '& .MuiTab-root': { minHeight: 42 } }}>
            <Tab value="group" label="战队群" />
            <Tab value="dm" label={'私聊' + (unread > 0 ? ' · ' + (unread > 99 ? '99+' : unread) : '')} />
          </Tabs>
          <ChatOnlineChip info={online} />
        </Stack>
        <Box sx={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
          {tab === 'group' && <GroupChatBody me={me} onDm={startDm} onSeen={onGroupSeen} />}
          {tab === 'dm' && <DmPanel me={me} dmInfo={dmInfo} onlineList={online} refresh={loadDm} initialPeer={dmWith} />}
        </Box>
      </Paper>
    );
  }

  // 战队群：原聊天室那套轮询/发送/撤回逻辑整体保留，只是换了个容器与标题
  function GroupChatBody({ me, onDm, onSeen }) {
    const [msgs, setMsgs] = useState([]);
    const [text, setText] = useState('');
    const [isAdmin, setIsAdmin] = useState(false);
    const [globalMute, setGlobalMute] = useState(false);
    const [sending, setSending] = useState(false);
    const [loaded, setLoaded] = useState(false);   // v5.1.0：首包是否已到——无消息时显示空态而不是一直转圈
    const lastIdRef = useRef(0);
    const listRef = useRef(null);
    const scrollFlagRef = useRef({ first: true });
    const sendingRef = useRef(false);
    const loadingRef = useRef(false);   // v3.9.6：并发防护——上一次拉取未完成时不发新请求

    const loadMessages = useCallback(async function () {
      if (loadingRef.current) return;
      loadingRef.current = true;
      try {
        const json = await api('/api/chat/messages?after_id=' + lastIdRef.current);
        const d = json.data;
        setIsAdmin(d.is_admin);
        setGlobalMute(d.global_mute);
        setLoaded(true);   // 首包到达：无消息时渲染空态，不再一直转圈

        const list = d.list;
        if (!list || list.length === 0) return;

        const box = listRef.current;
        const isFirst = lastIdRef.current === 0;
        // 只有"用户本来就在底部"或"新消息里有自己发的"才滚到底，往上翻历史时不强制跳底
        const nearBottom = box ? (box.scrollTop + box.clientHeight >= box.scrollHeight - 80) : true;
        let hasMine = false;
        list.forEach(function (m) {
          if (m.id > lastIdRef.current) lastIdRef.current = m.id;
          if (me && m.user_id === me.id) hasMine = true;
        });
        // 人在群里看到哪一条就记到哪一条，红点当场清掉
        if (onSeen && lastIdRef.current > 0) { onSeen(lastIdRef.current); }
        scrollFlagRef.current = { scroll: isFirst || hasMine || nearBottom };

        // v3.9.6：按 id 去重——发送后手动拉取与轮询可能短暂竞态带回同批消息，直接 concat 会重复显示
        setMsgs(function (prev) {
          if (isFirst) return list.slice();
          const seen = {};
          prev.forEach(function (m) { seen[m.id] = true; });
          const fresh = list.filter(function (m) { return !seen[m.id]; });
          return fresh.length > 0 ? prev.concat(fresh) : prev;
        });
      } catch (e) { setLoaded(true); /* 轮询失败静默，但首包失败也要退出转圈态 */ } finally {
        loadingRef.current = false;
      }
    }, [me]);

    useEffect(function () {
      loadMessages();
      const t = setInterval(loadMessages, 2000);
      return function () { clearInterval(t); };
    }, [loadMessages]);

    useEffect(function () {
      const box = listRef.current;
      if (box && scrollFlagRef.current.scroll) box.scrollTop = box.scrollHeight;
    }, [msgs]);

    const send = async function () {
      if (sendingRef.current) return;   // 防连点：上一次发送未完成时忽略重复点击/回车
      const val = text.trim();
      if (!val) return;
      sendingRef.current = true;
      setSending(true);
      try {
        const json = await api('/api/chat/send', { method: 'POST', body: { content: val } });
        if (json.code === 0) {
          setText('');
          scrollFlagRef.current = { scroll: true };
          loadMessages();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('发送失败，请稍后再试', 'error');
      } finally {
        sendingRef.current = false;
        setSending(false);
      }
    };

    const recall = async function (mid) {
      if (!window.confirm('确定撤回这条消息吗？')) return;
      try {
        const json = await api('/api/chat/recall/' + mid, { method: 'POST' });
        if (json.code === 0) {
          setMsgs(function (prev) { return prev.filter(function (m) { return m.id !== mid; }); });
          toast('已撤回', 'success');
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const toggleGlobalMute = async function () {
      try {
        const json = await api('/api/chat/global-mute', { method: 'POST' });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setGlobalMute(json.data.global_mute);
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // 渲染消息列表（含 ≥5 分钟时间戳分隔条，仿微信）
    const items = [];
    let prevTs = 0;
    msgs.forEach(function (m) {
      const ts = new Date(String(m.created_at).replace(/-/g, '/')).getTime();
      if (!isNaN(ts) && (!prevTs || ts - prevTs >= 5 * 60 * 1000)) {
        items.push({ type: 'time', key: 't-' + m.id, text: fmtChatTime(new Date(ts)) });
      }
      if (!isNaN(ts)) prevTs = ts;
      items.push({ type: 'msg', key: 'm-' + m.id, msg: m });
    });

    const muted = globalMute && me.role !== 'admin';

    return (
      <Box sx={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
        <PanelHead title="战队群">
          {isAdmin && ((me.admin_perms || []).indexOf('global_mute') >= 0) && (
            <Button variant="outlined" color={globalMute ? 'error' : 'primary'} onClick={toggleGlobalMute}>
              {globalMute ? '解除全员禁言' : '全员禁言'}
            </Button>
          )}
        </PanelHead>

        {muted && (
          <Alert severity="warning" sx={{ mb: 1.5 }}>当前为全员禁言状态，仅管理员可发言</Alert>
        )}

        <Box ref={listRef} sx={{ flex: 1, overflowY: 'auto', px: 1, py: 1 }}>
          {msgs.length === 0 && (loaded
            ? <EmptyBox text="还没有消息，来发第一条吧" />
            : <LoadingBox />)}
          {items.map(function (it) {
            if (it.type === 'time') {
              return (
                <Box key={it.key} sx={{ textAlign: 'center', my: 1.5 }}>
                  <Chip size="small" label={it.text} sx={{ bgcolor: '#e2e8f0', color: '#64748b', fontSize: 12 }} />
                </Box>
              );
            }
            const m = it.msg;
            const mine = me && m.user_id === me.id;
            return (
              <Stack key={it.key} direction="row" spacing={1.2} alignItems="flex-start"
                sx={{ mb: 1.8, flexDirection: mine ? 'row-reverse' : 'row' }}>
                <Tooltip title={mine ? '' : '发起私聊'}>
                  <Box sx={{ cursor: mine ? 'default' : 'pointer' }}
                    onClick={function () { if (!mine && onDm) onDm({ id: m.user_id, name: m.nickname }); }}>
                    <UserAvatar user={{ id: m.user_id, nickname: m.nickname }} size={36} />
                  </Box>
                </Tooltip>
                <Box sx={{ maxWidth: '72%', textAlign: mine ? 'right' : 'left' }}>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.3 }}>
                    {m.nickname}
                    {/* v5.3.0：昵称旁职责标签 */}
                    {m.role_tag && <RoleTagChip tag={m.role_tag} sx={{ mx: 0.6 }} />}
                    · {fmtTime(m.created_at)}
                    {/* v5.15.0(S-L3)：与后端同口径——撤回他人消息需要禁言或全局禁言权限，
                        零权限的挂名管理员不再看到这个按钮 */}
                    {isAdmin && ((me.admin_perms || []).indexOf('mute') >= 0
                      || (me.admin_perms || []).indexOf('global_mute') >= 0) && (
                      <Button size="small" color="error" sx={{ ml: 0.8, minWidth: 0, px: 0.5, py: 0, fontSize: 12 }}
                        onClick={() => recall(m.id)}>撤回</Button>
                    )}
                  </Typography>
                  <Box sx={{
                    display: 'inline-block', px: 1.6, py: 1, borderRadius: '10px', fontSize: 14, textAlign: 'left',
                    whiteSpace: 'pre-wrap', wordBreak: 'break-word',
                    bgcolor: mine ? 'primary.main' : '#f1f5f9',
                    color: mine ? '#fff' : '#1e293b',
                  }}>{m.content}</Box>
                </Box>
              </Stack>
            );
          })}
        </Box>

        <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
          <TextField fullWidth size="small" placeholder="输入消息，回车发送"
            inputProps={{ maxLength: 500 }}
            value={text} onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') send(); }} />
          <Button variant="contained" disabled={sending} onClick={send}>发送</Button>
        </Stack>
      </Box>
    );
  }

  // 私聊面板：左边会话列表、右边对话；窄屏两者二选一，标题栏「‹ 会话」返回
  function DmPanel({ me, dmInfo, onlineList, refresh, initialPeer }) {
    const isWide = useMediaQuery('(min-width:900px)');
    const [peerId, setPeerId] = useState(initialPeer ? (initialPeer.id || 0) : 0);
    const [msgs, setMsgs] = useState([]);
    const [env, setEnv] = useState(null);   // { enabled, mine, peerAllow, peerOnline, peerNick }
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [picker, setPicker] = useState(false);
    const [reportFor, setReportFor] = useState(null);
    const lastIdRef = useRef(0);
    const listRef = useRef(null);
    const scrollRef = useRef({ v: true });
    const loadingRef = useRef(false);

    const loadThread = useCallback(async function (pid, reset) {
      if (loadingRef.current && !reset) return;
      loadingRef.current = true;
      try {
        if (reset) lastIdRef.current = 0;
        const j = await api('/api/chat/dm/thread?with=' + pid + '&after_id=' + lastIdRef.current);
        if (j.code !== 0) {
          if (reset) { setMsgs([]); setEnv(null); toast(j.msg || '这段对话读不到', 'error'); }
          return;
        }
        const d = j.data || {};
        setEnv({
          enabled: d.dm_enabled, mine: d.me_allow,
          peerNick: d.peer ? (d.peer.name || d.peer.nickname) : '', peerOnline: d.peer ? d.peer.online : 0,
          peerLast: d.peer ? d.peer.last_active : null,   // 只有持 member_view 的管理员拿得到值
          peerAllow: d.peer ? d.peer.allow_dm : 1,
          peerSame: d.peer ? d.peer.same_team : 1,        // v5.30.1：私聊不再限于同队
          peerTeam: d.peer ? (d.peer.peer_team || '') : '',
          peerStranger: d.peer ? d.peer.allow_stranger : 1,
          blockedMe: d.peer ? d.peer.blocked_me : 0,
          left: typeof d.stranger_limit === 'number' ? d.stranger_limit : null,
        });
        const list = d.list || [];
        let hasMine = false;
        list.forEach(function (m) {
          if (m.id > lastIdRef.current) lastIdRef.current = m.id;
          if (m.mine) hasMine = true;
        });
        const box = listRef.current;
        const nearBottom = box ? (box.scrollTop + box.clientHeight >= box.scrollHeight - 80) : true;
        scrollRef.current = { v: list.length > 0 && (reset || hasMine || nearBottom) };
        setMsgs(function (prev) {
          if (reset || prev.length === 0) return list.slice();
          const seen = {};
          prev.forEach(function (m) { seen[m.id] = true; });
          const fresh = list.filter(function (m) { return !seen[m.id]; });
          return fresh.length > 0 ? prev.concat(fresh) : prev;
        });
      } catch (e) { /* 轮询失败静默 */ } finally {
        loadingRef.current = false;
      }
    }, []);

    // 群聊里点了头像 → 直接把那个会话打开（at 保证重复点同一个人也能刷新）
    useEffect(function () {
      if (initialPeer && initialPeer.id) setPeerId(initialPeer.id);
    }, [initialPeer]);

    useEffect(function () {
      if (peerId > 0) { setMsgs([]); setEnv(null); loadThread(peerId, true); }
      if (refresh) refresh();
    }, [peerId]);   // eslint-disable-line

    useEffect(function () {
      if (peerId <= 0) return;
      const t = setInterval(function () { loadThread(peerId, false); }, 2000);
      return function () { clearInterval(t); };
    }, [peerId, loadThread]);

    useEffect(function () {
      const box = listRef.current;
      if (box && scrollRef.current.v) box.scrollTop = box.scrollHeight;
    }, [msgs]);

    const send = async function () {
      const val = text.trim();
      if (!val || busy || peerId <= 0) return;
      setBusy(true);
      try {
        const j = await api('/api/chat/dm/send', { method: 'POST', body: { to: peerId, content: val } });
        if (j.code === 0) {
          setText('');
          if (j.data.id > lastIdRef.current) lastIdRef.current = j.data.id;
          setMsgs(function (prev) { return prev.concat([j.data]); });
          scrollRef.current = { v: true };
          if (refresh) refresh();
        } else toast(j.msg, 'error');
      } catch (e) { toast('发送失败，请稍后再试', 'error'); } finally { setBusy(false); }
    };

    const recall = async function (mid) {
      if (!window.confirm('撤回这条私聊？对方那边也会一起消失。')) return;
      try {
        const j = await api('/api/chat/dm/recall', { method: 'POST', body: { id: mid } });
        if (j.code === 0) {
          setMsgs(function (prev) { return prev.filter(function (m) { return m.id !== mid; }); });
          toast('已撤回', 'success');
          if (refresh) refresh();
        } else toast(j.msg, 'error');
      } catch (e) { toast('操作失败', 'error'); }
    };

    const items = [];
    let prevTs = 0;
    msgs.forEach(function (m) {
      const ts = new Date(String(m.created_at).replace(/-/g, '/')).getTime();
      if (!isNaN(ts) && (!prevTs || ts - prevTs >= 5 * 60 * 1000)) {
        items.push({ type: 'time', key: 't-' + m.id, text: fmtChatTime(new Date(ts)) });
      }
      if (!isNaN(ts)) prevTs = ts;
      items.push({ type: 'msg', key: 'm-' + m.id, msg: m });
    });

    const convs = (dmInfo && dmInfo.list) || [];
    // count 里含我自己，名单才是"能找谁聊"的依据；count-1 只当名单被 80 条截断时的下限看
    const onlineOthers = ((onlineList && onlineList.list) || []).filter(function (u) {
      return !me || u.id !== me.id;
    });
    const onlineTotal = Math.max(onlineOthers.length, ((onlineList && onlineList.count) || 0) - 1);
    const onlinePeers = onlineOthers.slice(0, 12);
    const extraOnline = Math.max(0, onlineTotal - onlinePeers.length);
    const showList = isWide || peerId === 0;
    const showThread = isWide || peerId > 0;
    const blocked = !!(env && (env.enabled === 0 || env.mine === 0 || env.peerAllow === 0 || env.blockedMe === 1));
    const blockedTip = !env ? '' : (env.enabled === 0 ? '本站私聊功能已被管理员关闭'
      : (env.mine === 0 ? '你在「个人中心」关掉了「接受私聊」，打开才能发'
        : (env.blockedMe === 1 ? '对方不接受你的消息'
          : (env.peerAllow === 0 ? '对方设置了不接受私聊' : ''))));
    // v5.30.1 防骚扰：外队的人，对方没回复你之前最多 1 条（内置，关不掉）
    const strangerTip = env && env.peerSame === 0 && env.left !== null && env.left === 0
      ? '对方还没回复你，这一轮的话已经用完了 —— 这条防骚扰是内置的，谁也关不掉' : '';
    const peerNick = (env && env.peerNick) || '';

    return (
      <Box sx={{ flex: 1, minHeight: 0, display: 'flex', gap: 2 }}>
        {showList && (
          <Box sx={{ width: { xs: '100%', md: 268 }, flexShrink: 0, minHeight: 0, display: 'flex', flexDirection: 'column',
            borderRight: { md: '1px solid' }, borderColor: { md: 'divider' }, pr: { md: 1.5 } }}>
            <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1 }}>
              <Typography variant="subtitle2" color="text.secondary">我的会话</Typography>
              <Button size="small" variant="outlined" onClick={() => setPicker(true)}>发起私聊</Button>
            </Stack>

            {onlinePeers.length > 0 && (
              <Box sx={{ mb: 1.5 }}>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.6 }}>
                  在线队友 {'（' + onlineTotal + '）'}
                </Typography>
                <Stack direction="row" spacing={0.6} sx={{ flexWrap: 'wrap', useFlexGap: true }}>
                  {onlinePeers.map(function (u) {
                    return (
                      <Tooltip key={u.id} title={u.name + (u.is_admin ? '（管理员）' : '') + ' · 点击进入对话'}>
                        <Box onClick={function () { setPeerId(u.id); }}
                          sx={{ position: 'relative', cursor: 'pointer', borderRadius: '50%' }}>
                          <UserAvatar user={{ id: u.id, nickname: u.name }} size={30} />
                          <Box sx={{ position: 'absolute', right: -1, bottom: -1, width: 9, height: 9,
                            borderRadius: '50%', border: '2px solid #fff', bgcolor: '#22c55e' }} />
                        </Box>
                      </Tooltip>
                    );
                  })}
                  {extraOnline > 0 && <Chip size="small" label={'+' + extraOnline} sx={{ height: 30, alignSelf: 'center' }} />}
                </Stack>
              </Box>
            )}
            <Box sx={{ flex: 1, minHeight: 0, overflowY: 'auto' }}>
              {!dmInfo ? <LoadingBox /> : (convs.length === 0
                ? <EmptyBox text="还没有私聊记录，点「发起私聊」找个队友" />
                : convs.map(function (c) {
                    return (
                      <Box key={c.peer_id} onClick={function () { setPeerId(c.peer_id); }}
                        sx={{ p: 1, mb: 0.5, borderRadius: 2, cursor: 'pointer',
                          bgcolor: c.peer_id === peerId ? 'action.selected' : 'transparent' }}>
                        <Stack direction="row" spacing={1.2} alignItems="center">
                          <Box sx={{ position: 'relative', flexShrink: 0 }}>
                            <UserAvatar user={{ id: c.peer_id, nickname: c.peer_nick }} size={34} />
                            <Box sx={{ position: 'absolute', right: -1, bottom: -1, width: 10, height: 10,
                              borderRadius: '50%', border: '2px solid #fff',
                              bgcolor: c.peer_online ? '#22c55e' : '#cbd5e1' }} />
                          </Box>
                          <Box sx={{ minWidth: 0, flex: 1 }}>
                            <Stack direction="row" spacing={0.8} alignItems="center">
                              <Typography variant="body2" fontWeight={600} noWrap sx={{ minWidth: 0, flex: 1 }}>
                                {c.peer_nick || '已注销用户'}
                              </Typography>
                              <Typography variant="caption" sx={{ flexShrink: 0, color: c.peer_online ? '#166534' : 'text.secondary' }}>
                                {c.peer_status !== 'approved' ? '不可用'
                                  : (c.peer_online ? '在线'
                                    : ('离线' + (c.peer_last_active ? ' · ' + fmtTime(c.peer_last_active).slice(5) : '')))}
                              </Typography>
                              {c.unread > 0 && (
                                <Chip size="small" color="error" label={c.unread > 99 ? '99+' : c.unread}
                                  sx={{ height: 18, fontSize: 11, fontWeight: 700 }} />
                              )}
                            </Stack>
                            <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }} noWrap>
                              {(c.last_snip ? ((c.last_mine ? '我: ' : '') + c.last_snip) : (c.msgs > 0 ? '（最后一条已撤回）' : '暂无消息'))
                                + (c.last_at ? ' · ' + fmtTime(c.last_at).slice(5) : '')}
                            </Typography>
                          </Box>
                        </Stack>
                      </Box>
                    );
                  }))}
            </Box>
          </Box>
        )}

        {showThread && (
          <Box sx={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
            {peerId === 0 ? <EmptyBox text="选一个会话，或点「发起私聊」找队友" /> : (
              <Box sx={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
                <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
                  {!isWide && <Button size="small" onClick={function () { setPeerId(0); }}>‹ 会话</Button>}
                  <UserAvatar user={{ id: peerId, nickname: peerNick }} size={30} />
                  <Typography fontWeight={600} noWrap sx={{ minWidth: 0 }}>{peerNick || '对方'}</Typography>
                  {env && <Chip size="small"
                    label={env.peerOnline ? '在线'
                      : ('离线' + (env.peerLast ? ' · 最后在线 ' + fmtTime(env.peerLast).slice(5) : ''))}
                    sx={{ bgcolor: env.peerOnline ? '#e8f5e9' : '#f1f5f9', color: env.peerOnline ? '#166534' : '#64748b' }} />}
                  {env && env.peerSame === 0 && !!env.peerTeam && (
                    <Chip size="small" label={env.peerTeam} sx={{ bgcolor: '#eef2ff', color: '#3730a3' }} />
                  )}
                  <Box sx={{ flex: 1 }} />
                  {/* v5.30.1：跨队才需要「拉黑」这一档；同队的事归队长和管理，不用私人名单切 */}
                  {env && env.peerSame === 0 && (
                    <Button size="small" color="error" sx={{ minWidth: 0, px: 0.8 }}
                      onClick={function () { blockPeer(); }}>拉黑</Button>
                  )}
                  <Button size="small" sx={{ minWidth: 0, px: 0.8 }}
                    onClick={function () { gotoProfile(peerId); }}>主页</Button>
                  {!isWide && <Button size="small" variant="outlined" onClick={() => setPicker(true)}>找人</Button>}
                </Stack>

                {blocked && <Alert severity="warning" sx={{ mb: 1 }}>{blockedTip}</Alert>}
                {!blocked && strangerTip && <Alert severity="info" sx={{ mb: 1 }}>{strangerTip}</Alert>}

                <Box ref={listRef} sx={{ flex: 1, minHeight: 0, overflowY: 'auto', px: 0.5 }}>
                  {items.map(function (it) {
                    if (it.type === 'time') {
                      return (
                        <Box key={it.key} sx={{ textAlign: 'center', my: 1.5 }}>
                          <Chip size="small" label={it.text} sx={{ bgcolor: '#e2e8f0', color: '#64748b', fontSize: 12 }} />
                        </Box>
                      );
                    }
                    const m = it.msg;
                    const mine = !!m.mine;
                    return (
                      <Stack key={it.key} direction="row" spacing={1} alignItems="flex-start"
                        sx={{ mb: 1.6, flexDirection: mine ? 'row-reverse' : 'row' }}>
                        <UserAvatar user={{ id: mine ? me.id : peerId, nickname: mine ? me.nickname : peerNick }} size={32} />
                        <Box sx={{ maxWidth: '74%', textAlign: mine ? 'right' : 'left' }}>
                          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.3 }}>
                            {fmtTime(m.created_at)}
                            {mine && (
                              <Button size="small" color="error" sx={{ ml: 0.8, minWidth: 0, px: 0.5, py: 0, fontSize: 12 }}
                                onClick={function () { recall(m.id); }}>撤回</Button>
                            )}
                            <Button size="small" sx={{ ml: 0.5, minWidth: 0, px: 0.5, py: 0, fontSize: 12 }}
                              onClick={function () { setReportFor(m); }}>举报</Button>
                          </Typography>
                          <Box sx={{
                            display: 'inline-block', px: 1.6, py: 1, borderRadius: '10px', fontSize: 14, textAlign: 'left',
                            whiteSpace: 'pre-wrap', wordBreak: 'break-word',
                            bgcolor: mine ? 'primary.main' : '#f1f5f9',
                            color: mine ? '#fff' : '#1e293b',
                          }}>{m.content}</Box>
                        </Box>
                      </Stack>
                    );
                  })}
                </Box>

                <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
                  <TextField fullWidth size="small" placeholder="私聊内容，回车发送（只有你和对方能看到）"
                    inputProps={{ maxLength: 500 }} disabled={blocked}
                    value={text} onChange={(e) => setText(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') send(); }} />
                  <Button variant="contained" disabled={busy || blocked} onClick={send}>发送</Button>
                </Stack>
              </Box>
            )}
          </Box>
        )}

        <DmPicker open={picker} onClose={function () { setPicker(false); }}
          onPick={function (pid) { setPicker(false); setPeerId(pid); }} />
        <DmReportDialog msg={reportFor} onClose={function () { setReportFor(null); }} />
      </Box>
    );
  }

  // 选人：只给本队已通过审核的其他成员，名单里连 QQ 号都不返回
  function DmPicker({ open, onClose, onPick }) {
    const [list, setList] = useState(null);
    const [kw, setKw] = useState('');
    const [off, setOff] = useState(false);
    const [finding, setFinding] = useState(false);   // v5.30.1：跨队搜索进行中
    const [cross, setCross] = useState(false);       // 当前这份列表是搜出来的（可能含别队的人）

    useEffect(function () {
      if (!open) return;
      setList(null); setKw(''); setOff(false);
      (async function () {
        try {
          const j = await api('/api/chat/dm/candidates');
          if (j.code === 0) { setList(j.data.list || []); setOff(j.data.dm_enabled === 0); }
          else { setList([]); toast(j.msg, 'error'); }
        } catch (e) { setList([]); toast('名单加载失败', 'error'); }
      })();
    }, [open]);

    // v5.30.1：私聊放开到跨队，「本队名单」装不下别队的人，所以搜字交给服务端（只搜昵称，至少两个字）
    const k = kw.trim();
    const search = function () {
      if (k.length < 2) { toast('搜索至少要两个字', 'error'); return; }
      setFinding(true);
      api('/api/chat/dm/candidates?q=' + encodeURIComponent(k)).then(function (j) {
        if (j.code === 0) { setList(j.data.list || []); setCross(!!j.data.searching); }
        else toast(j.msg || '没搜到', 'error');
      }).catch(function () { toast('搜索失败', 'error'); }).finally(function () { setFinding(false); });
    };
    const rows = list || [];

    return (
      <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>找谁聊</DialogTitle>
        <DialogContent>
          <Stack direction="row" spacing={1} sx={{ mt: 0.5, mb: 1.5 }}>
            <TextField size="small" fullWidth placeholder="本队直接列；搜别队的人请输入至少两个字"
              value={kw} onChange={(e) => setKw(e.target.value)}
              onKeyDown={function (e) { if (e.key === 'Enter') search(); }} />
            <Button size="small" variant="outlined" disabled={finding} onClick={search}>搜索</Button>
          </Stack>
          {cross && <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
            下面是按昵称搜到的其它战队成员；对方没回复你之前只能发 1 条，这是内置的防骚扰。
          </Typography>}
          {off && <Alert severity="warning" sx={{ mb: 1.5 }}>本站私聊已被管理员关闭，选人后也发不出去</Alert>}
          {!list ? <LoadingBox /> : (rows.length === 0
            ? <EmptyBox text={k ? '没有匹配的人' : '队里暂时没有其他可私聊的成员'} />
            : (
              <Box sx={{ maxHeight: 360, overflowY: 'auto' }}>
                {rows.map(function (c) {
                  return (
                    <Stack key={c.id} direction="row" spacing={1.2} alignItems="center"
                      onClick={function () { onPick(c.id); }}
                      sx={{ p: 1, borderRadius: 2, cursor: 'pointer', '&:hover': { bgcolor: 'action.hover' } }}>
                      <Box sx={{ position: 'relative' }}>
                        <UserAvatar user={{ id: c.id, nickname: c.name }} size={32} />
                        <Box sx={{ position: 'absolute', right: -1, bottom: -1, width: 10, height: 10,
                          borderRadius: '50%', border: '2px solid #fff',
                          bgcolor: c.online ? '#22c55e' : '#cbd5e1' }} />
                      </Box>
                      <Typography variant="body2" noWrap sx={{ minWidth: 0, flex: 1 }}>{c.name}</Typography>
                      {!!c.team && <Chip size="small" label={c.team} sx={{ height: 16, fontSize: 10, bgcolor: '#f5f5f7', color: '#5f6b7f' }} />}
                      <Typography variant="caption" color="text.secondary">{c.online ? '在线' : '离线'}</Typography>
                    </Stack>
                  );
                })}
              </Box>
            ))}
        </DialogContent>
        <DialogActions><Button onClick={onClose}>关闭</Button></DialogActions>
      </Dialog>
    );
  }

  // 举报：这是管理员能看到这段正文的唯一钥匙
  function DmReportDialog({ msg, onClose }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = async function () {
      if (!reason.trim()) { toast('请填写举报理由', 'error'); return; }
      setBusy(true);
      try {
        const j = await api('/api/chat/dm/report', { method: 'POST', body: { message_id: msg.id, reason: reason.trim() } });
        if (j.code === 0) { setReason(''); toast(j.msg || '已提交举报', 'success'); onClose(); }
        else toast(j.msg, 'error');
      } catch (e) { toast('提交失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Dialog open={!!msg} onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>举报这条私聊</DialogTitle>
        <DialogContent>
          <Alert severity="info" sx={{ mb: 1.5 }}>
            提交后管理员才能看到这段对话，且每次查看都会记进本队操作日志。只有对话的当事人能举报。
          </Alert>
          <TextField fullWidth size="small" multiline minRows={3} inputProps={{ maxLength: 200 }}
            placeholder="说明情况（最多 200 字）" value={reason} onChange={(e) => setReason(e.target.value)} />
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={submit}>{busy ? '提交中…' : '提交举报'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ==================== 改枪码 ====================
  function GunCodesPage({ me }) {
    const [keyword, setKeyword] = useState('');
    const [searchText, setSearchText] = useState('');
    const [cat, setCat] = useState('');          // v5.26.0：''=全部，其它是后端的类别键
    const [cats, setCats] = useState([]);        // 类别清单由接口下发，前端不写死
    const [list, setList] = useState(null);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [isAdmin, setIsAdmin] = useState(false);
    const [meId, setMeId] = useState(0);
    const [editData, setEditData] = useState(null);   // null=关, {}=新建, {id..}=编辑
    const [commentId, setCommentId] = useState(null);
    const SIZE = 8;

    const load = useCallback(async function (p, kw, c) {
      const realPage = p || page;
      const realKw = kw !== undefined ? kw : keyword;
      const realCat = c !== undefined ? c : cat;
      if (p) setPage(p);
      setList(null);
      try {
        const json = await api('/api/guncodes?page=' + realPage + '&size=' + SIZE +
          '&keyword=' + encodeURIComponent(realKw) +
          (realCat ? '&category=' + encodeURIComponent(realCat) : ''));
        const d = json.data;
        setTotal(d.total);
        setIsAdmin(d.is_admin);
        setMeId(d.me_id);
        setCats(d.categories || []);
        setList(d.list);
      } catch (e) { setList([]); }
    }, [page, keyword, cat]);

    useEffect(function () { load(1, keyword, cat); }, [cat]);  // eslint-disable-line

    const catName = function (k) {
      const hit = cats.find(function (x) { return x.key === k; });
      return hit ? hit.name : '未分类';
    };

    const doSearch = function () {
      setKeyword(searchText.trim());
      load(1, searchText.trim(), cat);
    };

    const toggle = async function (id) {
      try {
        const json = await api('/api/guncodes/' + id + '/toggle', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const del = async function (id) {
      if (!window.confirm('确定删除这个枪码吗？删除后不可恢复')) return;
      try {
        const json = await api('/api/guncodes/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast('已删除', 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('删除失败', 'error'); }
    };

    const commentGc = list ? (list.find(function (g) { return g.id === commentId; }) || null) : null;

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="改枪码">
          <TextField size="small" placeholder="按枪械名字搜索" value={searchText}
            onChange={(e) => setSearchText(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') doSearch(); }} />
          <Button variant="outlined" onClick={doSearch}>搜索</Button>
          <Button variant="contained" onClick={() => setEditData({})}>发布枪码</Button>
        </PanelHead>

        {/* v5.26.0：按武器类别筛。选项由接口下发；「全部分类」只在这条筛选条里出现，创建和编辑选不到它 */}
        {cats.length > 0 && (
          <Stack direction="row" alignItems="center" sx={{ mb: 1.5, gap: 1, flexWrap: 'wrap' }}>
            <Select size="small" displayEmpty value={cat}
              onChange={function (e) { setCat(e.target.value); }}
              sx={{ minWidth: 128 }}
              renderValue={function (v) { return v ? catName(v) : '全部分类'; }}>
              <MenuItem value="">全部分类</MenuItem>
              {cats.map(function (x) { return <MenuItem key={x.key} value={x.key}>{x.name}</MenuItem>; })}
            </Select>
            <Typography variant="caption" color="text.secondary">
              {cat ? (catName(cat) + ' · 共 ' + total + ' 条') : ('全部 ' + total + ' 条')}
            </Typography>
            {cat !== '' && (
              <Button size="small" variant="text" onClick={function () { setCat(''); }}>看全部</Button>
            )}
          </Stack>
        )}

        {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text={cat ? '这个类别还没有枪码，换个类别或点「看全部」' : '暂无枪码，来发布第一个吧'} /> :
          list.map(function (g) {
            const canEdit = isAdmin || g.user_id === meId;
            const offline = g.status === 'offline';
            return (
              <Paper key={g.id} variant="outlined" sx={{ p: 2, mb: 2 }}>
                <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
                  <Typography fontWeight={600}>{g.title}</Typography>
                  <Chip size="small" variant="outlined" label={catName(g.category)} sx={{ borderColor: '#c7c7cc' }} />
                  {offline
                    ? <Chip size="small" label="已下架" />
                    : <Chip size="small" color="success" label="已发布" />}
                </Stack>
                <Box component="pre" sx={{
                  m: 0, p: 1.5, bgcolor: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '8px',
                  fontSize: 13, whiteSpace: 'pre-wrap', wordBreak: 'break-all', fontFamily: 'Consolas, monospace',
                }}>{g.code}</Box>
                {g.description && (
                  <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>{g.description}</Typography>
                )}
                <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between"
                  flexWrap="wrap" useFlexGap sx={{ mt: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">{g.author} · {fmtTime(g.created_at)}</Typography>
                  <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                    <Button size="small" variant="outlined" onClick={() => copyText(g.code)}>复制枪码</Button>
                    <Button size="small" variant="outlined" onClick={() => setCommentId(g.id)}>
                      评论{Number(g.comment_count) > 0 ? ' (' + g.comment_count + ')' : ''}
                    </Button>
                    {canEdit && <Button size="small" variant="outlined" onClick={() => setEditData({ id: g.id, title: g.title, code: g.code, description: g.description, category: g.category })}>修改</Button>}
                    {canEdit && <Button size="small" variant="outlined" onClick={() => toggle(g.id)}>{offline ? '上架' : '下架'}</Button>}
                    {canEdit && <Button size="small" color="error" variant="outlined" onClick={() => del(g.id)}>删除</Button>}
                  </Stack>
                </Stack>
              </Paper>
            );
          })}

        {list && list.length > 0 && <Pager page={page} size={SIZE} total={total} onChange={function (p) { load(p); }} />}

        <GcEditDialog data={editData} cats={cats} onClose={() => setEditData(null)} onDone={function () { setEditData(null); load(); }} />
        {commentGc && <GcCommentDialog gc={commentGc} me={me} onClose={() => setCommentId(null)} onChanged={() => load()} />}
      </Paper>
    );
  }

  function GcEditDialog({ data, cats, onClose, onDone }) {
    const isEdit = !!(data && data.id);
    const [title, setTitle] = useState('');
    const [code, setCode] = useState('');
    const [description, setDescription] = useState('');
    const [category, setCategory] = useState('other');
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      if (data) {
        setTitle(data.title || '');
        setCode(data.code || '');
        setDescription(data.description || '');
        // 这里的下拉只有九个真类别，选不到「全部」；没类别的老枪码打开就是未分类
        setCategory(data.category || 'other');
        setBusy(false);
      }
    }, [data]);

    if (!data) return null;

    const submit = async function () {
      if (busy) return;   // 防连点：慢响应时重复点击只会产生重复请求
      setBusy(true);
      const payload = { title: title.trim(), code: code.trim(), description: description.trim(), category: category };
      try {
        const json = isEdit
          ? await api('/api/guncodes/' + data.id, { method: 'PUT', body: payload })
          : await api('/api/guncodes', { method: 'POST', body: payload });
        if (json.code === 0) {
          toast(json.msg, 'success');
          onDone();
        } else {
          toast(json.msg, 'error');
        }
      } catch (err) {
        toast('操作失败，请稍后再试', 'error');
      } finally {
        setBusy(false);
      }
    };

    return (
      <Dialog open={!!data} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>{isEdit ? '修改枪码' : '发布枪码'}</DialogTitle>
        <DialogContent>
          <TextField label="枪械名字" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={title} onChange={(e) => setTitle(e.target.value)}
            inputProps={{ maxLength: 128 }} placeholder="例如：AK-47 无后坐力版" />
          <TextField select label="武器类别" fullWidth required sx={{ mb: 2 }}
            value={category} onChange={function (e) { setCategory(e.target.value); }}>
            {(cats.length ? cats : [{ key: 'other', name: '未分类' }]).map(function (x) {
              return <MenuItem key={x.key} value={x.key}>{x.name}</MenuItem>;
            })}
          </TextField>
          <TextField label="枪码内容" fullWidth required multiline minRows={3} sx={{ mb: 2 }}
            value={code} onChange={(e) => setCode(e.target.value)} placeholder="粘贴改枪码内容" />
          <TextField label="说明（选填）" fullWidth multiline minRows={2}
            value={description} onChange={(e) => setDescription(e.target.value)} placeholder="补充说明，500 字以内" />
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={submit}>{isEdit ? '保存' : '发布'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  function GcCommentDialog({ gc, me, onClose, onChanged }) {
    const [list, setList] = useState(null);
    const [isAdmin, setIsAdmin] = useState(false);
    const [meId, setMeId] = useState(0);
    const [text, setText] = useState('');
    const sendingRef = useRef(false);
    const [sending, setSending] = useState(false);
    const muted = me && Number(me.muted) === 1;

    const load = useCallback(async function () {
      try {
        const json = await api('/api/guncodes/' + gc.id + '/comments');
        const d = json.data;
        setIsAdmin(d.is_admin);
        setMeId(d.me_id);
        setList(d.list);
      } catch (e) { setList([]); }
    }, [gc.id]);

    useEffect(function () { load(); }, [load]);

    const add = async function () {
      if (sendingRef.current) return;   // 防连点
      const val = text.trim();
      if (!val) return;
      sendingRef.current = true;
      setSending(true);
      try {
        const json = await api('/api/guncodes/' + gc.id + '/comments', { method: 'POST', body: { content: val } });
        if (json.code === 0) {
          setText('');
          load();
          onChanged();   // 刷新外层评论数
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('评论失败，请稍后再试', 'error');
      } finally {
        sendingRef.current = false;
        setSending(false);
      }
    };

    const del = async function (cid) {
      if (!window.confirm('确定删除这条评论吗？')) return;
      try {
        const json = await api('/api/guncodes/comments/' + cid, { method: 'DELETE' });
        if (json.code === 0) { toast('评论已删除', 'success'); load(); onChanged(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('删除失败', 'error'); }
    };

    return (
      <Dialog open onClose={onClose} fullWidth maxWidth="md">
        <DialogTitle>评论：{gc.title}</DialogTitle>
        <DialogContent dividers>
          <Box sx={{ maxHeight: 360, overflowY: 'auto' }}>
            {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="还没有评论，来抢沙发吧" /> :
              list.map(function (c) {
                const canDel = isAdmin || c.user_id === meId;
                return (
                  <Stack key={c.id} direction="row" spacing={1.2} alignItems="flex-start" sx={{ mb: 2 }}>
                    <UserAvatar user={{ id: c.user_id, nickname: c.nickname }} size={32} />
                    <Box sx={{ flex: 1 }}>
                      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                        <Typography variant="body2" fontWeight={600}>{c.nickname}</Typography>
                        {c.user_role === 'admin' && <Chip size="small" color="info" label="管理员" />}
                        <Typography variant="caption" color="text.secondary">{fmtTime(c.created_at)}</Typography>
                        {canDel && (
                          <Button size="small" color="error" sx={{ minWidth: 0, px: 0.5, py: 0, fontSize: 12 }}
                            onClick={() => del(c.id)}>删除</Button>
                        )}
                      </Stack>
                      <Typography variant="body2" sx={{ mt: 0.3, whiteSpace: 'pre-wrap' }}>{c.content}</Typography>
                    </Box>
                  </Stack>
                );
              })}
          </Box>
        </DialogContent>
        <DialogContent sx={{ pt: 1.5 }}>
          {muted ? (
            <Alert severity="warning">你已被管理员禁言，暂时不能评论</Alert>
          ) : (
            <Stack direction="row" spacing={1}>
              <TextField fullWidth size="small" placeholder="写下评论，回车发布（300 字以内）"
                inputProps={{ maxLength: 300 }}
                value={text} onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') add(); }} />
              <Button variant="contained" disabled={sending} onClick={add}>发布</Button>
            </Stack>
          )}
        </DialogContent>
      </Dialog>
    );
  }

  // ==================== 战队小队 ====================
  function TeamsPage({ refreshMe }) {
    const [myTeam, setMyTeam] = useState(null);
    const [teams, setTeams] = useState(null);
    const [myTeamId, setMyTeamId] = useState(null);

    const loadMyTeam = useCallback(async function () {
      try {
        const json = await api('/api/teams/my');
        setMyTeam(json.data.team || false);
      } catch (e) { setMyTeam(false); }
    }, []);

    const loadTeamList = useCallback(async function () {
      try {
        const json = await api('/api/teams');
        setMyTeamId(json.data.my_team_id);
        setTeams(json.data.list);
      } catch (e) { setTeams([]); }
    }, []);

    useEffect(function () { loadMyTeam(); loadTeamList(); }, [loadMyTeam, loadTeamList]);

    const afterChange = async function () {
      try { await refreshMe(); } catch (e) { /* 忽略界面刷新异常 */ }
      loadMyTeam(); loadTeamList();
    };

    const join = async function (teamId) {
      let json;
      try { json = await api('/api/teams/join', { method: 'POST', body: { team_id: teamId } }); }
      catch (e) { toast('操作失败', 'error'); return; }
      if (json.code === 0) { toast(json.msg, 'success'); afterChange(); } else { toast(json.msg, 'error'); }
    };

    const leave = async function () {
      if (!window.confirm('确定退出当前小队吗？')) return;
      let json;
      try { json = await api('/api/teams/leave', { method: 'POST' }); }
      catch (e) { toast('操作失败', 'error'); return; }
      if (json.code === 0) { toast(json.msg, 'success'); afterChange(); } else { toast(json.msg, 'error'); }
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="战队小队" desc="加入和退出都不需要审批，自由选择" />

        {myTeam === null ? <LoadingBox /> : !myTeam ? (
          <Paper variant="outlined" sx={{ p: 2 }}><EmptyBox text="你还没有加入小队，从下方列表选择一个加入吧" /></Paper>
        ) : (
          <Paper variant="outlined" sx={{ p: 2 }}>
            <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
              <Typography fontWeight={600}>{myTeam.name}</Typography>
              <Chip size="small" color="info" label="我的小队" />
              {myTeam.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(myTeam.game_side)} /> : null}
            </Stack>
            <Typography variant="body2" color="text.secondary">{myTeam.description || '暂无简介'}</Typography>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5, mb: 1.5 }}>
              队长：{myTeam.leader_name || '暂无'} · 成员 {myTeam.members.length} 人
            </Typography>
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>成员</TableCell><TableCell>QQ号</TableCell><TableCell>加入时间</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {myTeam.members.map(function (m) {
                    return (
                      <TableRow key={m.id}>
                        <TableCell>
                          {m.nickname}
                          {m.id === myTeam.leader_id && <Chip size="small" color="warning" label="队长" sx={{ ml: 1 }} />}
                          {/* v5.3.0：成员行职责标签 */}
                          {m.role_tag && <RoleTagChip tag={m.role_tag} sx={{ ml: 1 }} />}
                        </TableCell>
                        <TableCell>{m.username}</TableCell>
                        <TableCell>{fmtTime(m.created_at)}</TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
            <Box sx={{ mt: 1.5 }}>
              <Button variant="outlined" color="error" onClick={leave}>退出小队</Button>
            </Box>
          </Paper>
        )}

        <Typography variant="subtitle1" fontWeight={600} sx={{ mt: 3, mb: 1.5 }}>全部小队</Typography>
        {teams === null ? <LoadingBox /> : teams.length === 0 ? <EmptyBox text="暂无可加入的小队" /> :
          teams.map(function (t) {
            const joined = myTeamId === t.id;
            return (
              <Paper key={t.id} variant="outlined" sx={{ p: 2, mb: 1.5, display: 'flex', alignItems: 'center', gap: 2 }}>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography fontWeight={600}>
                    {t.name}
                    {t.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(t.game_side)} sx={{ ml: 1, height: 20, fontSize: 11 }} /> : null}
                  </Typography>
                  <Typography variant="body2" color="text.secondary" noWrap>
                    {t.description || '暂无简介'} · 队长 {t.leader_name || '暂无'} · {t.member_count} 人
                  </Typography>
                </Box>
                {joined
                  ? <Chip size="small" color="info" label="已加入" />
                  : <Button variant="contained" size="small" onClick={() => join(t.id)}>加入小队</Button>}
              </Paper>
            );
          })}
      </Paper>
    );
  }

  // ==================== 撞车比赛 ====================
  function RacePage() {
    const [events, setEvents] = useState(null);
    const [now, setNow] = useState(Date.now());
    const offsetRef = useRef(0);

    const loadRaces = useCallback(async function () {
      try {
        const json = await api('/api/races');
        const d = json.data;
        // 服务器时间校准
        if (d.server_time) {
          offsetRef.current = new Date(d.server_time.replace(/-/g, '/')).getTime() - Date.now();
        }
        setEvents(d.list || []);
      } catch (e) { setEvents([]); }
    }, []);

    useEffect(function () {
      loadRaces();
      const t1 = setInterval(loadRaces, 30000);
      const t2 = setInterval(function () { setNow(Date.now() + offsetRef.current); }, 47);   // 毫秒级时钟
      return function () { clearInterval(t1); clearInterval(t2); };
    }, [loadRaces]);

    const pad = function (n, w) { return String(n).padStart(w || 2, '0'); };
    const week = ['日', '一', '二', '三', '四', '五', '六'];
    const clock = new Date(now);

    const countdown = function (rt) {
      const target = new Date(String(rt).replace(/-/g, '/')).getTime();
      let diff = Math.floor((target - now) / 1000);
      if (diff <= 0) return { text: '已开始', expired: true };
      const d = Math.floor(diff / 86400); diff -= d * 86400;
      const h = Math.floor(diff / 3600); diff -= h * 3600;
      const m = Math.floor(diff / 60);
      const s = diff - m * 60;
      return { text: (d > 0 ? d + ' 天 ' : '') + h + ' 时 ' + m + ' 分 ' + s + ' 秒', expired: false };
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="撞车比赛" desc="以下为最近的撞车时间点，准时上线！" />

        <Paper variant="outlined" sx={{ p: 3, textAlign: 'center', bgcolor: '#0f172a', color: '#fff' }}>
          <Typography sx={{ fontSize: { xs: 34, md: 46 }, fontWeight: 700, fontFamily: 'Consolas, monospace', lineHeight: 1.2 }}>
            {pad(clock.getHours())}:{pad(clock.getMinutes())}:{pad(clock.getSeconds())}
            <Box component="span" sx={{ fontSize: '0.55em', color: '#94a3b8' }}>.{pad(clock.getMilliseconds(), 3)}</Box>
          </Typography>
          <Typography variant="body2" sx={{ color: '#94a3b8', mt: 0.5 }}>
            {clock.getFullYear()} 年 {clock.getMonth() + 1} 月 {clock.getDate()} 日 星期{week[clock.getDay()]}
          </Typography>
          <Button component="a" href="http://time.syiban.com/" target="_blank" rel="noopener"
            variant="outlined" size="small" sx={{ mt: 1.5, color: '#fff', borderColor: '#475569' }}>
            在新窗口打开北京时间（毫秒）
          </Button>
        </Paper>

        <Box sx={{ mt: 2 }}>
          {events === null ? <LoadingBox /> : events.length === 0 ? <EmptyBox text="暂无撞车时间安排，敬请期待" /> :
            events.map(function (r) {
              const cd = countdown(r.race_time);
              return (
                <Paper key={r.id} variant="outlined" sx={{ p: 2, mb: 1.5, display: 'flex', alignItems: 'center', gap: 2 }}>
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography fontWeight={600}>
                      {r.title}
                      {r.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(r.game_side)} sx={{ ml: 1, height: 20, fontSize: 11 }} /> : null}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">比赛时间：{r.race_time}</Typography>
                  </Box>
                  <Typography sx={{ fontFamily: 'Consolas, monospace', fontWeight: 700, color: cd.expired ? 'text.secondary' : 'primary.main' }}>
                    {cd.text}
                  </Typography>
                </Paper>
              );
            })}
        </Box>
      </Paper>
    );
  }

  // ==================== 自建房比赛日程（v3.9.6） ====================
  function CustomRacePage() {
    const [events, setEvents] = useState(null);
    const [now, setNow] = useState(Date.now());
    const offsetRef = useRef(0);

    const loadRaces = useCallback(async function () {
      try {
        const json = await api('/api/custom-races');
        const d = json.data;
        // 服务器时间校准
        if (d.server_time) {
          offsetRef.current = new Date(d.server_time.replace(/-/g, '/')).getTime() - Date.now();
        }
        setEvents(d.list || []);
      } catch (e) { setEvents([]); }
    }, []);

    useEffect(function () {
      loadRaces();
      const t1 = setInterval(loadRaces, 30000);
      const t2 = setInterval(function () { setNow(Date.now() + offsetRef.current); }, 47);   // 毫秒级时钟
      return function () { clearInterval(t1); clearInterval(t2); };
    }, [loadRaces]);

    const countdown = function (rt) {
      const target = new Date(String(rt).replace(/-/g, '/')).getTime();
      let diff = Math.floor((target - now) / 1000);
      if (diff <= 0) return { text: '已开始', expired: true };
      const d = Math.floor(diff / 86400); diff -= d * 86400;
      const h = Math.floor(diff / 3600); diff -= h * 3600;
      const m = Math.floor(diff / 60);
      const s = diff - m * 60;
      return { text: (d > 0 ? d + ' 天 ' : '') + h + ' 时 ' + m + ' 分 ' + s + ' 秒', expired: false };
    };

    return (<>
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="自建房比赛日程" desc="以下为最近的自建房比赛安排，准时上线！" />

        <Box>
          {events === null ? <LoadingBox /> : events.length === 0 ? <EmptyBox text="暂无自建房比赛安排，敬请期待" /> :
            events.map(function (r) {
              const cd = countdown(r.race_time);
              return (
                <Paper key={r.id} variant="outlined" sx={{ p: 2, mb: 1.5, display: 'flex', alignItems: 'center', gap: 2 }}>
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography fontWeight={600}>
                      {r.title}
                      {r.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(r.game_side)} sx={{ ml: 1, height: 20, fontSize: 11 }} /> : null}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">比赛时间：{r.race_time}</Typography>
                  </Box>
                  <Typography sx={{ fontFamily: 'Consolas, monospace', fontWeight: 700, color: cd.expired ? 'text.secondary' : 'primary.main' }}>
                    {cd.text}
                  </Typography>
                </Paper>
              );
            })}
        </Box>

        {/* v5.4.0：跨战队约战区块（Hub 与连接器共用组件，数据源自动切换，5 秒轮询） */}
      </Paper>
      <BattleBoard />
      {/* v5.5.0：赛事中心赛事动态区块（未接入时不占位） */}
      <TournamentBoard />
    </>);
  }

  // ==================== 个人中心 ====================
  // 接受本队成员私聊（v5.19.0）：成员自己的开关，与站长的全队开关两个都开才收得到
  function DmPrefCard() {
    const [allow, setAllow] = useState(null);
    const [stranger, setStranger] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      (async function () {
        try {
          // v5.30.1：改读自己的两个开关（原来蹭的是候选人接口里的 allow_me，那个键还在，但这里要的是两个值）
          const j = await api('/api/chat/dm/my-settings');
          if (j.code === 0) { setAllow(j.data.allow_dm === 1); setStranger(j.data.allow_stranger === 1); }
        } catch (e) { /* 静默 */ }
      })();
    }, []);

    const save = async function (key, next) {
      const body = {};
      body[key] = next ? 1 : 0;
      setBusy(true);
      try {
        const j = await api('/api/chat/dm/my-setting', { method: 'POST', body: body });
        if (j.code === 0) {
          if (key === 'allow_dm') setAllow(j.data.allow_dm === 1);
          if (key === 'allow_stranger') setStranger(j.data.allow_stranger === 1);
          toast(j.msg, 'success');
        } else toast(j.msg, 'error');
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    if (allow === null) return null;
    return (
      <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
        <FormControlLabel
          control={<Switch checked={allow} disabled={busy} onChange={(e) => save('allow_dm', e.target.checked)} />}
          label="接受私聊" />
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
          关掉后别人发不出私聊给你（已有的对话仍能看到）；队长也可以把本站私聊整体关闭，那种情况这里打开也没用。
        </Typography>
        <FormControlLabel
          control={<Switch checked={stranger === true} disabled={busy}
            onChange={(e) => save('allow_stranger', e.target.checked)} />}
          label="允许其它战队的人先开口" />
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
          关掉后只有本队成员能主动发起私聊；已经在跟你说话的人不受影响。
          另外有一条内置防骚扰关不掉：外队的人在对方回复你之前，最多只能发 1 条。
        </Typography>
      </Paper>
    );
  }

  // 未晞通行证绑定（v5.36.6）：绑定只是多一种登录方式，账号密码照旧可用
  function PassportBindCard() {
    const [st, setSt] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/passport/binding');
        if (json.code === 0) setSt(json.data || {});
        else setSt(null);
      } catch (e) { setSt(null); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const unbind = async function () {
      if (!window.confirm('解除绑定后，这个通行证将不能再登录当前账号；账号密码登录不受影响。确定解除吗？')) return;
      setBusy(true);
      try {
        const json = await api('/api/passport/unbind', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); }
        else toast(json.msg, 'error');
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    if (!st) return null;
    // 未绑定且平台没开启时，这一整块不出现，个人中心保持原样
    if (!st.bound && !st.enabled) return null;

    return (
      <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems={{ xs: 'stretch', sm: 'center' }}>
          <Box sx={{ flex: 1, minWidth: 0 }}>
            <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>未晞通行证</Typography>
            {st.bound ? (
              <Typography variant="body2" color="text.secondary">
                已绑定 <b>{st.sub_masked}</b>
                {st.created_at ? (' · ' + fmtTime(st.created_at)) : ''}
                {' '}；账号密码仍然可以登录。
                {!st.enabled && ' 平台当前已暂停通行证登录，绑定关系保留。'}
              </Typography>
            ) : (
              <Typography variant="body2" color="text.secondary">
                绑定后可以用通行证登录这个账号；账号密码仍然可以登录。
                {!st.ready && (st.table_ready ? ' 平台配置还没完成，暂不可绑定。' : ' 通行证补库还没完成，暂不可绑定。')}
              </Typography>
            )}
          </Box>
          {st.bound ? (
            <Button variant="outlined" color="error" disabled={busy} onClick={unbind}>解除绑定</Button>
          ) : (
            <Button variant="contained" disabled={!st.enabled || !st.ready}
              onClick={function () { location.href = st.link_url; }}>
              绑定通行证
            </Button>
          )}
        </Stack>
      </Paper>
    );
  }

  function ProfilePage({ me, refreshMe, primary, onThemeChange }) {
    const [nickname, setNickname] = useState(me.nickname || '');
    const [oldPwd, setOldPwd] = useState('');
    const [newPwd, setNewPwd] = useState('');
    const [confirmPwd, setConfirmPwd] = useState('');
    // v3.9.2：更换注册游戏截图（提交后回炉重新审核）
    const [shotFile, setShotFile] = useState(null);
    const [shotBusy, setShotBusy] = useState(false);
    const shotRef = useRef(null);
    const shotUrlRef = useRef(null);

    useEffect(function () { setNickname(me.nickname || ''); }, [me]);
    // 离开页面时回收本地预览地址
    useEffect(function () {
      return function () { if (shotUrlRef.current) URL.revokeObjectURL(shotUrlRef.current); };
    }, []);

    const pickShot = function (f) {
      if (shotUrlRef.current) URL.revokeObjectURL(shotUrlRef.current);
      shotUrlRef.current = f ? URL.createObjectURL(f) : null;
      setShotFile(f || null);
    };

    const submitShot = async function () {
      if (!shotFile || shotBusy) return;
      if (!window.confirm('更换截图后账号将立即重新进入管理员审核，期间无法登录，确定提交吗？')) return;
      setShotBusy(true);
      const fd = new FormData();
      fd.append('file', shotFile);
      try {
        const json = await api('/api/profile/screenshot', { method: 'POST', form: fd });
        if (json.code === 0) {
          toast(json.msg, 'success');
          window.setTimeout(function () { location.href = BASE + '/login'; }, 1800);
        } else {
          toast(json.msg, 'error');
          setShotBusy(false);
        }
      } catch (e) {
        toast('提交失败，请稍后再试', 'error');
        setShotBusy(false);
      }
    };

    const saveNick = async function () {
      try {
        const json = await api('/api/profile/nickname', { method: 'POST', body: { nickname: nickname.trim() } });
        if (json.code === 0) { toast(json.msg, 'success'); refreshMe(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const savePass = async function () {
      try {
        const json = await api('/api/profile/password', {
          method: 'POST',
          body: { old_password: oldPwd, new_password: newPwd, confirm: confirmPwd },
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setOldPwd(''); setNewPwd(''); setConfirmPwd('');
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="个人中心" />
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
          <Paper variant="outlined" sx={{ p: 2.5, flex: 1 }}>
            <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 2 }}>基本资料</Typography>
            <TextField label="QQ 号" fullWidth disabled sx={{ mb: 2 }} value={me.username || ''} />
            <TextField label="邮箱" fullWidth disabled sx={{ mb: 2 }} value={me.email || ((me.username || '') + '@qq.com')} />
            <TextField label="昵称" fullWidth sx={{ mb: 2 }} inputProps={{ maxLength: 32 }}
              value={nickname} onChange={(e) => setNickname(e.target.value)} />
            <Button variant="contained" onClick={saveNick}>保存昵称</Button>
          </Paper>
          <Paper variant="outlined" sx={{ p: 2.5, flex: 1 }}>
            <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 2 }}>修改密码</Typography>
            <TextField label="原密码" type="password" fullWidth sx={{ mb: 2 }}
              value={oldPwd} onChange={(e) => setOldPwd(e.target.value)} placeholder="请输入原密码" />
            <TextField label="新密码" type="password" fullWidth sx={{ mb: 2 }}
              value={newPwd} onChange={(e) => setNewPwd(e.target.value)} placeholder="8-64 位新密码，需包含字母和数字" />
            <TextField label="确认新密码" type="password" fullWidth sx={{ mb: 2 }}
              value={confirmPwd} onChange={(e) => setConfirmPwd(e.target.value)} placeholder="再次输入新密码" />
            <Button variant="contained" onClick={savePass}>修改密码</Button>
          </Paper>
        </Stack>

        {/* 职责标签自选（v5.3.0）：开关关闭时只读；多标签者可设默认 */}
        <ProfileRoleTags me={me} refreshMe={refreshMe} />

        {/* QQ 机器人绑定（v5.8.0）：个人中心取码，群内 @机器人 完成身份绑定 */}
        <BotBindCard />

        {/* v5.36.7：未晞通行证绑定（额外登录方式，账号密码不受影响） */}
        <PassportBindCard />

        {/* v5.19.0：私聊个人开关 */}
        <DmPrefCard />
        {/* v5.20.0：本人是否愿意出现在战队对外展示页（队长那边的模块开关是另一层） */}
        <ShowPublicCard />
        {/* v5.30.0：战绩要不要给本队成员看（对外展示页是另一档，两个别混） */}
        <StatsPublicCard me={me} />
        {/* v5.30.1：我的黑名单（每人最多 10 个，只拉得到其它战队的人） */}
        <BlockListCard />

        {/* 更换游戏截图（v3.9.2）：提交后回炉重新审核；超管账号无注册截图，不显示 */}
        {!me.is_super && (
          <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
            <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>更换游戏截图</Typography>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>
              更换注册时提交的游戏主页截图；提交后账号将立即重新进入管理员审核，审核通过前无法登录。
            </Typography>
            <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'flex-start' }}>
              {/* 当前截图 */}
              <Box sx={{ width: 150 }}>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>当前截图</Typography>
                {me.profile_image ? (
                  <Box onClick={() => lightbox(me.profile_image)} sx={{ cursor: 'zoom-in' }}>
                    <img src={me.profile_image} alt="当前游戏截图" style={{
                      width: 150, height: 90, objectFit: 'cover', borderRadius: 8,
                      border: '1px solid #dfe7e6', display: 'block',
                    }} />
                  </Box>
                ) : (
                  <Box sx={{
                    width: 150, height: 90, borderRadius: '8px', border: '1px dashed #b6c4c1',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                  }}>
                    <Typography variant="caption" color="text.secondary">暂无截图</Typography>
                  </Box>
                )}
              </Box>
              {/* 新截图上传 */}
              <Box sx={{ flex: 1, minWidth: 240 }}>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>新截图</Typography>
                <input
                  ref={shotRef} type="file"
                  onChange={(e) => pickShot(e.target.files[0] || null)}
                  accept="image/jpeg,image/png,image/gif,image/webp"
                  style={{ display: 'none' }}
                />
                <Box onClick={() => { if (shotRef.current) shotRef.current.click(); }} sx={{
                  border: '1.5px dashed', borderColor: shotFile ? 'primary.main' : '#b6c4c1', borderRadius: '10px',
                  p: 2, textAlign: 'center', cursor: 'pointer',
                  bgcolor: shotFile ? (t => alpha(t.palette.primary.main, 0.06)) : 'transparent', transition: 'background-color .15s',
                  '&:hover': { bgcolor: (t => alpha(t.palette.primary.main, 0.1)) },
                }}>
                  {shotUrlRef.current ? (
                    <img src={shotUrlRef.current} alt="新截图预览" style={{
                      maxWidth: '100%', maxHeight: 120, borderRadius: 6, display: 'block', margin: '0 auto',
                    }} />
                  ) : (
                    <Typography variant="body2" fontWeight={500} color="text.secondary">点击选择新截图 *</Typography>
                  )}
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                    支持 jpg / png / gif / webp，不超过 5MB
                  </Typography>
                </Box>
                <Button variant="contained" color="warning" disabled={!shotFile || shotBusy}
                  onClick={submitShot} sx={{ mt: 1.5 }}>
                  {shotBusy ? '提交中...' : '提交更换（需重新审核）'}
                </Button>
              </Box>
            </Box>
          </Paper>
        )}

        {/* 主题调色盘（v3.6）：仅存本地 localStorage，不同步云端 */}
        <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>主题调色盘</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>
            选一个喜欢的颜色，全站主题色即时切换；颜色仅保存在本浏览器，不同步云端。
          </Typography>
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.2, alignItems: 'center' }}>
            {THEME_PRESETS.map(function (p) {
              const active = primary === p[0];
              return (
                <Tooltip key={p[0]} title={p[1]}>
                  <Box onClick={() => onThemeChange(p[0])} sx={{
                    width: 36, height: 36, borderRadius: '50%', bgcolor: p[0], cursor: 'pointer',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    boxShadow: active ? '0 0 0 2px #fff inset, 0 0 0 2px ' + p[0] : 'none',
                    transform: active ? 'scale(1.1)' : 'none', transition: 'transform .12s',
                    '&:hover': { transform: 'scale(1.12)' },
                  }}>
                    {active && (
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff">
                        <path d="M9 16.2l-3.5-3.5L4 14.2l5 5 11-11-1.5-1.5z"/>
                      </svg>
                    )}
                  </Box>
                </Tooltip>
              );
            })}
            {/* 自定义颜色（彩虹圆块，点开系统取色器） */}
            <Tooltip title="自定义颜色">
              <Box component="label" sx={{
                width: 36, height: 36, borderRadius: '50%', cursor: 'pointer', position: 'relative',
                background: 'conic-gradient(#f43f5e, #f59e0b, #84cc16, #06b6d4, #6366f1, #d946ef, #f43f5e)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}>
                <input type="color" value={primary} onChange={(e) => onThemeChange(e.target.value)}
                  style={{ position: 'absolute', left: 0, top: 0, width: '100%', height: '100%', opacity: 0, cursor: 'pointer' }} />
                <Box sx={{ width: 14, height: 14, borderRadius: '50%', bgcolor: primary, border: '2px solid #fff' }} />
              </Box>
            </Tooltip>
            {primary !== PRIMARY && (
              <Button size="small" sx={{ ml: 0.5 }} onClick={() => onThemeChange(PRIMARY)}>恢复默认</Button>
            )}
          </Box>
        </Paper>
      </Paper>
    );
  }

  // ---------- 职责标签自选区（v5.3.0）：个人主页自由单选；多标签者可指定默认展示 ----------
  // ---------- QQ 机器人绑定（v5.8.0：个人中心取码 → 群内 @机器人 发「绑定 六位码」） ----------
  function BotBindCard() {
    const [st, setSt] = useState(null);
    const [code, setCode] = useState('');
    const [left, setLeft] = useState(0);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/bot/status');
        if (json.code === 0) setSt(json.data);
      } catch (e) { /* 忽略 */ }
    }, []);
    useEffect(function () { load(); }, [load]);

    // 验证码倒计时
    useEffect(function () {
      if (left <= 0) return;
      const t = window.setInterval(function () {
        setLeft(function (v) { return v > 0 ? v - 1 : 0; });
      }, 1000);
      return function () { window.clearInterval(t); };
    }, [left > 0]);

    const gen = async function () {
      try {
        const json = await api('/api/bot/bind-code', { method: 'POST' });
        if (json.code === 0) { setCode(json.data.code); setLeft(json.data.ttl || 600); load(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const unbind = async function () {
      if (!window.confirm('解绑后将无法在 QQ 群内使用签到等指令，确定吗？')) return;
      try {
        const json = await api('/api/bot/unbind', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); setCode(''); setLeft(0); load(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (st === null) return null;
    if (!st.bot_enabled && !st.bound) {
      return (
        <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>QQ机器人绑定</Typography>
          <Typography variant="caption" color="text.secondary">QQ 机器人尚未启用，暂不可绑定。</Typography>
        </Paper>
      );
    }
    return (
      <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
        <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>QQ机器人绑定</Typography>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
          绑定后可以在 QQ 群里 @机器人 签到、查日程、查改枪码等。
        </Typography>
        {st.bound ? (
          <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap>
            <Chip color="success" label="已绑定" size="small" />
            <Typography variant="caption" color="text.secondary">绑定时间：{fmtTime(st.bound_at)}</Typography>
            <Button size="small" color="error" variant="outlined" onClick={unbind}>解绑</Button>
          </Stack>
        ) : !st.group_bound ? (
          <Typography variant="body2" color="text.secondary">
            本战队尚未绑定 QQ 群，请联系管理员先在管理中心完成群绑定。
          </Typography>
        ) : (
          <Stack spacing={1.5}>
            {code !== '' && left > 0 ? (
              <Paper variant="outlined" sx={{ p: 2, textAlign: 'center', bgcolor: (t) => alpha(t.palette.primary.main, 0.05) }}>
                <Typography variant="h4" fontWeight={700} letterSpacing={8}>{code}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {Math.floor(left / 60)} 分 {left % 60} 秒后失效
                </Typography>
              </Paper>
            ) : (
              <Button variant="contained" onClick={gen} sx={{ alignSelf: 'flex-start' }}>获取绑定验证码</Button>
            )}
            <Typography variant="caption" color="text.secondary" sx={{ whiteSpace: 'pre-line' }}>
              {'使用方法：在已绑定的 QQ 群里 @机器人 发送「绑定 ' + (code || '六位码') + '」即可完成绑定。'}
            </Typography>
          </Stack>
        )}
      </Paper>
    );
  }

  function ProfileRoleTags({ me, refreshMe }) {
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      // v5.3.1：不清空旧数据再拉取，避免保存后卡片先消失再出现
      try {
        const json = await api('/api/role-tags');
        setData(json.code === 0 ? json.data : { list: [], editable: false, my_tags: [], is_admin: false });
      } catch (e) { setData({ list: [], editable: false, my_tags: [], is_admin: false }); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const save = async function (ids) {
      if (busy) return;
      setBusy(true);
      try {
        const json = await api('/api/profile/role-tags', { method: 'POST', body: { tag_ids: ids } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); try { refreshMe(); } catch (e) { /* 忽略 */ } }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    if (!data) return (
      <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
        <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>职责标签</Typography>
        {/* v5.3.1：卡片先占位渲染（与个人中心其他区块同时出现），内容拉齐后填入，避免整块迟到弹出 */}
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, color: 'text.secondary' }}>
          <CircularProgress size={18} />
          <Typography variant="caption">标签加载中...</Typography>
        </Box>
      </Paper>
    );
    const tags = data.list || [];
    const myTags = data.my_tags || [];
    const canEdit = !!data.editable || !!data.is_admin;   // 管理员不受开关限制
    const multi = myTags.length > 1;                       // 已被管理员分配多个标签
    const tagById = function (id) {
      for (let i = 0; i < tags.length; i++) { if (tags[i].id === id) return tags[i]; }
      return null;
    };

    return (
      <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
        <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>职责标签</Typography>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
          {multi
            ? '你已被管理员分配多个标签：点击标签可将其设为默认展示（对外只显示默认的 1 个）'
            : '选择一个标签展示在聊天室/小队/查询结果旁；再次点击已选标签可清除'}
        </Typography>

        {!canEdit && (
          <Alert severity="info" sx={{ mb: 1.5 }}>管理员已关闭用户自行修改标签，如需调整请联系管理员</Alert>
        )}

        {tags.length === 0 ? <EmptyBox text="管理员还没有创建任何标签" /> : (
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1 }}>
            {(multi ? myTags : tags.map(function (t) { return t.id; })).map(function (id) {
              const t = tagById(id);
              if (!t) return null;
              const isSel = myTags.indexOf(id) >= 0;
              const isDefault = myTags.length > 0 && myTags[0] === id;
              const onClick = !canEdit ? null : function () {
                if (multi) {
                  if (!isDefault) save([id].concat(myTags.filter(function (x) { return x !== id; })));
                } else {
                  save(isSel ? [] : [id]);   // 单选：点已选 = 清除，其余 = 换成这个（服务端强制只保留 1 个）
                }
              };
              return (
                <Box key={id} onClick={onClick} sx={{
                  display: 'flex', alignItems: 'center', gap: 0.6,
                  border: '1px solid', borderColor: isSel ? t.color : '#cbd5e1', borderRadius: '8px',
                  px: 1.2, py: 0.7, cursor: canEdit ? 'pointer' : 'default',
                  bgcolor: isSel ? alpha(t.color, 0.08) : 'transparent',
                }}>
                  <RoleTagChip tag={t} />
                  {isDefault && <Typography variant="caption" color="primary" fontWeight={600}>当前展示</Typography>}
                </Box>
              );
            })}
          </Box>
        )}
      </Paper>
    );
  }

  // ==================== 跨战队约战（v5.4.0：日程页区块 + 管理页签，Hub 与连接器共用组件） ====================
  const BATTLE_STATUS = {
    pending: ['待对方接受', 'warning'], accepted: ['报名中', 'primary'],
    finished: ['已完赛', 'success'], rejected: ['已拒绝', 'default'],
    canceled: ['已取消', 'default'], expired: ['已过期', 'default'],
  };
  function battleStatusChip(s) {
    const m = BATTLE_STATUS[s] || [s, 'default'];
    return <Chip size="small" color={m[1]} label={m[0]} variant={m[1] === 'default' ? 'outlined' : 'filled'} />;
  }

  // 详情展开体：双 Ban / 双方名单 / 逐局对局（赛事页与管理页签共用）
  // onRecord/onDecider：管理页签传入的操作回调（训练赛录胜负、决胜局选图/选边），赛事页为 null 不显示按钮
  function BattleDetailBody({ d, onRecord, onDecider }) {
    const banText = function (ban) {
      if (ban === null || ban === undefined) return '未提交';
      return ban.passed ? '放弃禁用' : '禁用：' + ban.name;
    };
    const sidePanel = function (title, names, count, limit, ready) {
      return (
        <Box sx={{ flex: 1, minWidth: 200 }}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5 }}>
            <Typography variant="body2" fontWeight={600}>{title}</Typography>
            <Typography variant="caption" color="text.secondary">{count}{limit > 0 ? '/' + limit : ''} 人</Typography>
            {ready && <Chip size="small" color="success" label="已报满" />}
          </Stack>
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
            {names.length === 0 ? <Typography variant="caption" color="text.secondary">暂无报名</Typography>
              : names.map(function (s) { return <Chip key={s.id} size="small" variant="outlined" label={s.name} />; })}
          </Box>
        </Box>
      );
    };
    const fmtSecs = function (s) { return Math.floor(s / 60) + '分' + (s % 60) + '秒'; };
    const canRec = onRecord && d.battle_type === 'training' && d.status === 'accepted';
    const deciderOpen = onDecider && d.status === 'accepted' && d.adv_side && !d.adv_role
      && d.my_side === d.adv_side
      && (d.rounds || []).some(function (r) { return r.is_decider; });
    return (
      <Box sx={{ mt: 1.5, pt: 1.5, borderTop: '1px dashed', borderColor: 'divider' }}>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
          禁用地图：{d.challenger.name} {banText(d.ban_a)} · {d.defender.name} {banText(d.ban_b)}
          {d.drawn_at ? ' · 已抽签' : (d.battle_type === 'training' ? ' · 双方提交禁图后系统自动抽签' : ' · 未抽签')}
        </Typography>
        {d.adv_side && (
          <Stack direction="row" spacing={1} alignItems="center" sx={{ flexWrap: 'wrap', mb: 1 }}>
            <Chip size="small" color="warning" label={'决胜优先权：' + (d.adv_side === 'a' ? d.challenger.name : d.defender.name)} />
            <Typography variant="caption" color="text.secondary">{d.adv_reason}</Typography>
            {d.adv_role && <Chip size="small" variant="outlined" label={d.adv_role === 'pick_map' ? '已选图' : '已选边'} />}
            {deciderOpen && <Button size="small" variant="contained" onClick={onDecider}>选图 / 选边</Button>}
          </Stack>
        )}
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ mb: 1.5 }}>
          {sidePanel(d.challenger.name, d.signups_a || [], d.count_a || 0, d.roster_limit, d.ready_a)}
          {sidePanel(d.defender.name, d.signups_b || [], d.count_b || 0, d.roster_limit, d.ready_b)}
        </Stack>
        {!d.rounds || d.rounds.length === 0 ? (
          <Typography variant="caption" color="text.secondary">
            {d.drawn_at ? '尚未生成对局' : (d.battle_type === 'training' ? '等待双方提交禁图后自动抽签' : '尚未抽签生成对局，等待平台超管抽签')}
          </Typography>
        ) : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>局序</TableCell><TableCell>地图</TableCell><TableCell>攻方</TableCell>
                  <TableCell>守方</TableCell><TableCell>结果</TableCell><TableCell>战报</TableCell>
                  {canRec && <TableCell>操作</TableCell>}
                </TableRow>
              </TableHead>
              <TableBody>
                {d.rounds.map(function (r) {
                  const winner = r.result === 'a_win' ? d.challenger.name
                    : r.result === 'b_win' ? d.defender.name : null;
                  const stats = [];
                  if (r.attack_secs !== null) stats.push('进攻用时 ' + fmtSecs(r.attack_secs));
                  if (r.occupy_count !== null) stats.push('占领区域 ' + r.occupy_count);
                  if (r.occupy_ratio !== null) stats.push('占点 ' + r.occupy_ratio + '%');
                  return (
                    <TableRow key={r.round_no}>
                      <TableCell>
                        第 {r.round_no} 局
                        {r.is_decider && <Chip size="small" color="warning" label="决胜" sx={{ ml: 0.5 }} />}
                      </TableCell>
                      <TableCell>{r.map_name || '待定（优势方选图/选边）'}</TableCell>
                      <TableCell>{r.attacker_name || '待定'}</TableCell>
                      <TableCell>{r.defender_name || '待定'}</TableCell>
                      <TableCell>{winner ? <Chip size="small" color="success" label={winner + ' 胜'} />
                        : (r.is_decider && r.map_id === null ? <Chip size="small" variant="outlined" label="待选图/选边" />
                        : <Chip size="small" variant="outlined" label="未开赛" />)}</TableCell>
                      <TableCell>
                        {stats.length > 0 && <Typography variant="caption" sx={{ display: 'block' }}>{stats.join(' · ')}</Typography>}
                        {r.evidence && <Link href={BASE + r.evidence} target="_blank" rel="noopener" variant="caption">结果截图</Link>}
                      </TableCell>
                      {canRec && (
                        <TableCell>
                          {!r.result && !r.is_decider && (
                            <Button size="small" variant="outlined" onClick={() => onRecord(r)}>录胜负</Button>
                          )}
                        </TableCell>
                      )}
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

  // 约战卡片（列表行 + 展开详情）
  function BattleCard({ b, expanded, onToggle, actions, detail, onRecord, onDecider }) {
    const scoreVisible = b.drawn || b.status === 'finished';
    const resultText = b.result === 'a_win' ? b.challenger.name + ' 获胜'
      : b.result === 'b_win' ? b.defender.name + ' 获胜' : '';
    return (
      <Paper variant="outlined" sx={{ p: 2, mb: 1.5 }}>
        <Stack direction="row" spacing={1.5} alignItems="center">
          <Box sx={{ flex: 1, minWidth: 0 }}>
            <Stack direction="row" spacing={1} alignItems="center" sx={{ flexWrap: 'wrap' }}>
              <Typography fontWeight={600}>{b.challenger.name}</Typography>
              <Chip size="small" label="VS" />
              <Typography fontWeight={600}>{b.defender.name}</Typography>
              <Chip size="small" variant="outlined" color="primary" label={(b.series_format || 'bo3').toUpperCase()} />
              <Chip size="small" variant="outlined" color={b.battle_type === 'training' ? 'warning' : 'info'}
                label={b.battle_type === 'training' ? '训练赛' : '正式赛事'} />
              {battleStatusChip(b.status)}
            </Stack>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.4 }}>
              {b.title}{b.game_mode ? ' · ' + b.game_mode : ''} · 开赛：{fmtTime(b.battle_time)}
              {b.roster_limit > 0 ? ' · 每方上限 ' + b.roster_limit + ' 人' : ''}
            </Typography>
          </Box>
          {scoreVisible && (
            <Box sx={{ textAlign: 'center' }}>
              <Typography sx={{ fontFamily: 'Consolas, monospace', fontWeight: 700, color: 'primary.main' }}>
                {b.score_a} : {b.score_b}
              </Typography>
              {resultText && <Typography variant="caption" color="text.secondary">{resultText}</Typography>}
            </Box>
          )}
          <Button size="small" onClick={onToggle}>{expanded ? '收起' : '详情'}</Button>
        </Stack>
        {actions}
        {expanded && (detail ? <BattleDetailBody d={detail} onRecord={onRecord || null} onDecider={onDecider || null} /> : <Box sx={{ mt: 1.5 }}><LoadingBox /></Box>)}
      </Paper>
    );
  }

  // 日程页「跨战队约战」区块：先探测连接器状态，再按模式轮询（5 秒，复用聊天室轮询模式）
  function BattleBoard() {
    const modeRef = useRef(null);            // 'local' | 'hub'
    const [mode, setMode] = useState(null);
    const [items, setItems] = useState([]);
    const [openId, setOpenId] = useState(0);
    const [detail, setDetail] = useState(null);
    const [joined, setJoined] = useState({});  // id -> 本人是否已报名（从详情/提示推断）

    const listUrl = function () { return modeRef.current === 'hub' ? '/api/battle-hub/battles' : '/api/battles'; };
    const detailUrl = function (id) { return (modeRef.current === 'hub' ? '/api/battle-hub/battles/' : '/api/battles/') + id; };

    const load = useCallback(async function () {
      try {
        const json = await api(listUrl());
        if (json.code === 0) setItems(json.data || []);
      } catch (e) { /* 忽略轮询抖动 */ }
    }, []);

    useEffect(function () {
      (async function () {
        let m = 'local';
        try {
          const j = await api('/api/battle-hub/status');
          if (j.code === 0 && j.data.enabled) m = 'hub';
        } catch (e) { /* 默认 Hub 站内模式 */ }
        modeRef.current = m; setMode(m); load();
      })();
      const t = setInterval(load, 5000);
      return function () { clearInterval(t); };
    }, [load]);

    const toggle = async function (id) {
      if (openId === id) { setOpenId(0); setDetail(null); return; }
      setOpenId(id); setDetail(null);
      try {
        const json = await api(detailUrl(id));
        if (json.code === 0) setDetail(json.data);
      } catch (e) { /* 忽略 */ }
    };

    const signup = async function (id, action) {
      try {
        const url = (modeRef.current === 'hub' ? '/api/battle-hub/battles/' : '/api/battles/') + id + '/signup';
        const json = await api(url, { method: 'POST', body: { action: action } });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setJoined(function (p) { const n = Object.assign({}, p); n[id] = action === 'join'; return n; });
          load();
          if (openId === id) { toggle(id); toggle(id); }   // 刷新展开详情（重拉）
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (mode === null) return null;   // 探测中不占位（有无约战未知，避免空块闪烁）
    return (
      <Paper sx={{ p: { xs: 2, md: 3 }, mt: 2 }}>
        <PanelHead title="跨战队约战"
          desc={mode === 'hub' ? '平台约战总线上的跨战队赛事，报名出战、准时上线！' : '已接入约战中心，与本站相关的跨战队赛事实时同步'} />
        {items.length === 0 ? <EmptyBox text="暂无与本队相关的跨战队约战" /> : items.map(function (b) {
          const canSignup = b.status === 'accepted' && b.my_side;
          const isJoined = !!joined[b.id];
          return (
            <BattleCard key={b.id} b={b} expanded={openId === b.id}
              onToggle={() => toggle(b.id)} detail={openId === b.id ? detail : null}
              actions={canSignup ? (
                <Box sx={{ mt: 1 }}>
                  <Button size="small" variant={isJoined ? 'outlined' : 'contained'}
                    onClick={() => signup(b.id, isJoined ? 'leave' : 'join')}>
                    {isJoined ? '退出出战' : '报名出战'}
                  </Button>
                </Box>
              ) : null}
            />
          );
        })}
      </Paper>
    );
  }

  // ==================== 赛事中心（v5.5.0：日程页区块 + 管理页签，经主站代理） ====================
  const T_ST_TXT = {
    signup: '报名中', bracket: '已生成对阵', running: '进行中', finished: '已结束', canceled: '已取消',
    pending: '待审核', approved: '已通过', rejected: '已驳回', withdrawn: '已撤回',
    waiting: '等待槽位', ready: '可开赛', squads_pending: '等待小队', in_battle: '对局中',
    done: '已完赛', walkover: '轮空晋级',
  };
  function tSt(s) { return T_ST_TXT[s] || s; }

  // 日程页「赛事动态」区块（未接入/无赛事时不占位）
  function TournamentBoard() {
    const [data, setData] = useState(null);
    const [openId, setOpenId] = useState(0);
    const [detail, setDetail] = useState(null);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/tournament-center');
        if (json.code === 0) setData(json.data);
      } catch (e) { /* 忽略 */ }
    }, []);
    useEffect(function () {
      load();
      const t = setInterval(load, 30000);
      return function () { clearInterval(t); };
    }, [load]);

    const toggle = async function (id) {
      if (openId === id) { setOpenId(0); setDetail(null); return; }
      setOpenId(id); setDetail(null);
      try {
        const json = await api('/api/tournament-center/' + id);
        if (json.code === 0) setDetail(json.data);
      } catch (e) { /* 忽略 */ }
    };

    if (!data || !data.enabled || (data.tournaments || []).length === 0) return null;

    return (
      <Paper sx={{ p: { xs: 2, md: 3 }, mt: 2 }}>
        <PanelHead title="赛事动态" desc="赛事中心双败邀请赛：报名经审核、首轮手动配对、BO3/BO5 对局决冠军" />
        {data.tournaments.map(function (t) {
          const expanded = openId === t.id;
          return (
            <Paper key={t.id} variant="outlined" sx={{ p: 1.5, mb: 1, cursor: 'pointer' }} onClick={() => toggle(t.id)}>
              <Stack direction="row" spacing={1} alignItems="center">
                <Typography variant="body2" fontWeight={600} sx={{ flex: 1 }}>{t.name}</Typography>
                <Chip size="small" label={tSt(t.status)}
                  color={t.status === 'signup' ? 'success' : (t.status === 'running' || t.status === 'bracket' ? 'warning' : 'default')} />
                <Typography variant="caption" color="text.secondary">
                  已通过 {t.entry_count} 队 · 截止 {t.signup_deadline}
                </Typography>
              </Stack>
              {expanded && (
                <Box sx={{ mt: 1.5 }} onClick={function (e) { e.stopPropagation(); }}>
                  {detail ? (
                    <Box>
                      {detail.standings && detail.standings.champion ? (
                        <Alert severity="success" sx={{ mb: 1 }}>
                          冠军：{detail.standings.champion}
                          {detail.standings.runner_up ? '　亚军：' + detail.standings.runner_up : ''}
                          {detail.standings.third ? '　季军：' + detail.standings.third : ''}
                        </Alert>
                      ) : null}
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>
                        参赛队伍：{detail.entries.map(function (e) { return e.team_name; }).join('、') || '暂无'}
                      </Typography>
                      {(detail.matches || []).length === 0 ? (
                        <Typography variant="caption" color="text.secondary">对阵表尚未生成</Typography>
                      ) : ['winners', 'losers', 'grand_final'].map(function (bk) {
                        const ms = detail.matches.filter(function (m) { return m.bracket === bk; });
                        if (ms.length === 0) return null;
                        const title = bk === 'winners' ? '胜者组' : (bk === 'losers' ? '败者组' : '总决赛');
                        return (
                          <Box key={bk} sx={{ mt: 1 }}>
                            <Typography variant="caption" fontWeight={600}>{title}</Typography>
                            {ms.map(function (m) {
                              return (
                                <Typography key={m.id} variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                                  {m.label}：{m.a_name || '待定'} vs {m.b_name || '待定'}（{tSt(m.status)}
                                  {m.game ? ' · ' + m.game.format.toUpperCase() + ' ' + m.game.score : ''}）
                                </Typography>
                              );
                            })}
                          </Box>
                        );
                      })}
                    </Box>
                  ) : <Box sx={{ mt: 1 }}><LoadingBox /></Box>}
                </Box>
              )}
            </Paper>
          );
        })}
      </Paper>
    );
  }

  // 管理页签主体（hub=false 站内总线模式；hub=true 连接器模式，数据源不同、交互一致）
  function BattleManage({ hub }) {
    const detailUrl = function (id) { return (hub ? '/api/battle-hub/battles/' : '/api/battles/') + id; };
    const actUrl = function (id, a) { return (hub ? '/api/battle-hub/battles/' : '/api/admin/battles/') + id + '/' + a; };
    const [list, setList] = useState(null);
    const [parties, setParties] = useState([]);
    const [openId, setOpenId] = useState(0);
    const [detail, setDetail] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [banId, setBanId] = useState(0);
    const [mapPool, setMapPool] = useState(null);
    const [banMapId, setBanMapId] = useState('');
    const [rosterId, setRosterId] = useState(0);
    const [members, setMembers] = useState([]);
    const [picked, setPicked] = useState([]);

    const load = useCallback(async function () {
      try {
        const json = await api(hub ? '/api/battle-hub/battles' : '/api/admin/battles');
        if (json.code === 0) {
          if (hub) { setList(json.data || []); setParties([]); }
          else { setList(json.data.list || []); setParties(json.data.parties || []); }
        }
      } catch (e) { setList([]); }
    }, [hub]);
    useEffect(function () { load(); }, [load]);

    const refreshDetail = async function (id) {
      try {
        const json = await api(detailUrl(id));
        if (json.code === 0) setDetail(json.data);
      } catch (e) { /* 忽略 */ }
    };
    const toggle = function (id) {
      if (openId === id) { setOpenId(0); setDetail(null); return; }
      setOpenId(id); setDetail(null); refreshDetail(id);
    };

    const act = async function (id, a, confirmMsg) {
      if (confirmMsg && !window.confirm(confirmMsg)) return;
      try {
        const json = await api(actUrl(id, a), { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); if (openId === id) refreshDetail(id); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.6.1：删除已过期/已完赛（含已录分）约战（仅站内总线模式；连接器数据在约战中心侧）
    const removeBattle = async function (id) {
      if (!window.confirm('删除后该约战及其报名、小局记录将无法恢复，确定删除吗？')) return;
      try {
        const json = await api('/api/admin/battles/' + id, { method: 'DELETE' });
        if (json.code === 0) {
          toast(json.msg, 'success');
          if (openId === id) { setOpenId(0); setDetail(null); }
          load();
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('删除失败', 'error'); }
    };

    // 发起表单（仅站内总线模式；连接器=外部战队不能发起）
    const [f, setF] = useState({ title: '', defender: '', format: 'bo3', btype: 'official', time: '', mode: '', limit: 20, room: '', remark: '' });
    const submitCreate = async function () {
      if (!f.defender) { toast('请选择对方战队', 'error'); return; }
      const parts = f.defender.split(':');
      try {
        const json = await api('/api/admin/battles', { method: 'POST', body: {
          title: f.title, defender_type: parts[0], defender_id: parseInt(parts[1], 10),
          series_format: f.format, battle_type: f.btype, battle_time: f.time.replace('T', ' '),
          game_mode: f.mode, roster_limit: parseInt(f.limit, 10) || 0, room_info: f.room, remark: f.remark,
        } });
        if (json.code === 0) {
          toast(json.msg, 'success'); setCreateOpen(false);
          setF({ title: '', defender: '', format: 'bo3', btype: 'official', time: '', mode: '', limit: 20, room: '', remark: '' });
          load();
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('发起失败', 'error'); }
    };

    // 提交禁图（打开时拉地图池）
    const openBan = async function (id) {
      setBanId(id); setBanMapId(''); setMapPool(null);
      try {
        const json = await api(hub ? '/api/battle-hub/map-pool' : '/api/battles/map-pool');
        if (json.code === 0) setMapPool(json.data || []);
      } catch (e) { setMapPool([]); }
    };
    const submitBan = async function () {
      try {
        const json = await api(actUrl(banId, 'ban'), { method: 'POST', body: { map_id: banMapId === '' ? 0 : parseInt(banMapId, 10) } });
        if (json.code === 0) { toast(json.msg, 'success'); setBanId(0); load(); if (openId === banId) refreshDetail(banId); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('提交失败', 'error'); }
    };

    // 名单管理（打开时拉本站成员；勾选上报昵称，服务端脱敏不传 QQ）
    const openRoster = async function (id) {
      setRosterId(id); setPicked([]);
      try {
        const json = await api('/api/admin/users', { });
        if (json.code === 0) {
          setMembers((json.data.list || []).filter(function (u) { return u.status === 'approved'; }));
        }
      } catch (e) { setMembers([]); }
      if (openId !== id) { setOpenId(id); setDetail(null); }
      refreshDetail(id);
    };
    const submitRoster = async function () {
      try {
        const json = await api(actUrl(rosterId, 'roster'), { method: 'POST', body: { add_user_ids: picked } });
        if (json.code === 0) { toast(json.msg, 'success'); setPicked([]); refreshDetail(rosterId); load(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('提交失败', 'error'); }
    };
    const removeSignup = async function (sid) {
      try {
        const json = await api(actUrl(rosterId, 'roster'), { method: 'POST', body: { remove_id: sid } });
        if (json.code === 0) { toast(json.msg, 'success'); refreshDetail(rosterId); load(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // 训练赛录胜负（双方管理员自裁决，可附结果截图与小局统计）
    const [rec, setRec] = useState({ battleId: 0, round: null, result: '', file: null, secs: '', count: '', ratio: '' });
    const openRecord = function (battleId, r) {
      setRec({ battleId: battleId, round: r, result: '', file: null, secs: '', count: '', ratio: '' });
    };
    const submitRecord = async function () {
      if (!rec.result) { toast('请选择获胜方', 'error'); return; }
      const fd = new FormData();
      fd.append('result', rec.result);
      if (rec.secs !== '') fd.append('attack_secs', rec.secs);
      if (rec.count !== '') fd.append('occupy_count', rec.count);
      if (rec.ratio !== '') fd.append('occupy_ratio', rec.ratio);
      if (rec.file) fd.append('file', rec.file);
      const bid = rec.battleId;
      try {
        const json = await api(actUrl(bid, 'round/' + rec.round.id + '/result'), { method: 'POST', form: fd });
        if (json.code === 0) { toast(json.msg, 'success'); setRec({ battleId: 0, round: null, result: '', file: null, secs: '', count: '', ratio: '' }); load(); refreshDetail(bid); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('提交失败', 'error'); }
    };

    // 决胜局选图/选边（优势方二选一；选图=指定地图攻防随机，选边=选进攻/防守由系统随机抽新图）
    const [dec, setDec] = useState({ battleId: 0, choice: 'pick_map', mapId: '', side: 'attack' });
    const openDecider = async function (battleId) {
      setDec({ battleId: battleId, choice: 'pick_map', mapId: '', side: 'attack' });
      if (!mapPool) {
        try {
          const json = await api(hub ? '/api/battle-hub/map-pool' : '/api/battles/map-pool');
          if (json.code === 0) setMapPool(json.data || []);
        } catch (e) { setMapPool([]); }
      }
    };
    // 可选新图：平台池剔除本场已用与双方禁图（决胜局地图不可与前序地图相同）
    const deciderMaps = function () {
      if (!mapPool || !detail) return [];
      const used = (detail.rounds || []).filter(function (r) { return r.map_id; }).map(function (r) { return r.map_id; });
      return mapPool.filter(function (m) {
        return used.indexOf(m.id) < 0
          && !(detail.ban_a && !detail.ban_a.passed && detail.ban_a.name === m.name)
          && !(detail.ban_b && !detail.ban_b.passed && detail.ban_b.name === m.name);
      });
    };
    const submitDecider = async function () {
      const body = { choice: dec.choice };
      if (dec.choice === 'pick_map') {
        if (!dec.mapId) { toast('请选择决胜局地图', 'error'); return; }
        body.map_id = parseInt(dec.mapId, 10);
      } else { body.side = dec.side; }
      const bid = dec.battleId;
      try {
        const json = await api(actUrl(bid, 'decider'), { method: 'POST', body: body });
        if (json.code === 0) { toast(json.msg, 'success'); setDec({ battleId: 0, choice: 'pick_map', mapId: '', side: 'attack' }); load(); refreshDetail(bid); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('提交失败', 'error'); }
    };

    if (list === null) return <LoadingBox />;
    const pendings = list.filter(function (b) { return b.status === 'pending'; });
    const actives  = list.filter(function (b) { return b.status === 'accepted'; });
    const history  = list.filter(function (b) { return ['finished', 'rejected', 'canceled', 'expired'].indexOf(b.status) >= 0; });

    const adminActions = function (b) {
      const btns = [];
      if (b.status === 'pending') {
        if (b.my_side === 'b') {
          btns.push(<Button key="acc" size="small" variant="contained" onClick={() => act(b.id, 'accept')}>接受约战</Button>);
          btns.push(<Button key="rej" size="small" color="error" variant="outlined" onClick={() => act(b.id, 'reject', '确定拒绝这场约战吗？')}>拒绝</Button>);
        }
        if (b.my_side === 'a') {
          btns.push(<Button key="can" size="small" color="error" variant="outlined" onClick={() => act(b.id, 'cancel', '确定撤回这场约战吗？')}>撤回</Button>);
        }
      }
      if (b.status === 'accepted') {
        if (!b.drawn) btns.push(<Button key="ban" size="small" variant="outlined" onClick={() => openBan(b.id)}>提交禁图</Button>);
        btns.push(<Button key="ros" size="small" variant="outlined" onClick={() => openRoster(b.id)}>名单管理</Button>);
        btns.push(<Button key="can" size="small" color="error" onClick={() => act(b.id, 'cancel', '确定取消这场约战吗？已报名的成员将一并移除')}>取消</Button>);
      }
      // v5.6.1：已过期/已完赛/已取消/被拒绝，或已录分（部分完赛）的约战可删除（仅站内总线模式）
      const dead = ['expired', 'finished', 'canceled', 'rejected'].indexOf(b.status) >= 0;
      const partial = b.status === 'accepted' && (Number(b.score_a || 0) + Number(b.score_b || 0)) > 0;
      if (!hub && (dead || partial)) {
        btns.push(<Button key="del" size="small" color="error" variant="outlined" onClick={() => removeBattle(b.id)}>删除</Button>);
      }
      if (btns.length === 0) return null;
      return <Stack direction="row" spacing={1} sx={{ mt: 1 }}>{btns}</Stack>;
    };

    const section = function (title, arr, empty) {
      if (arr.length === 0) return null;
      return (
        <Box sx={{ mb: 2 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1 }}>{title}</Typography>
          {arr.map(function (b) {
            return <BattleCard key={b.id} b={b} expanded={openId === b.id} onToggle={() => toggle(b.id)}
              detail={openId === b.id ? detail : null} actions={adminActions(b)}
              onRecord={openId === b.id && detail ? (function (r) { openRecord(b.id, r); }) : null}
              onDecider={openId === b.id && detail ? (() => openDecider(b.id)) : null} />;
          })}
        </Box>
      );
    };

    // 名单管理弹窗里的当前名单（取本方）
    const rosterSide = detail && detail.my_side ? detail.my_side : null;
    const rosterNames = detail && rosterSide ? (rosterSide === 'a' ? detail.signups_a : detail.signups_b) : [];

    return (
      <Box>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 2 }}>
          <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>
            {hub ? '连接器模式：本站以「外部战队」身份接入约战中心，可应战、禁图与上报出战名单（发起由平台侧战队操作）'
              : '发起跨战队约战、处理来战、提交禁用地图并管理出战名单'}
          </Typography>
          <Button variant="outlined" onClick={load}>刷新</Button>
          {!hub && <Button variant="contained" onClick={() => setCreateOpen(true)}>发起约战</Button>}
        </Stack>
        <BattleNoticeSwitch />

        {list.length === 0 ? <EmptyBox text="暂无与本队相关的约战" /> : (
          <Box>
            {section('待处理（' + pendings.length + '）', pendings)}
            {section('进行中（' + actives.length + '）', actives)}
            {section('历史记录（' + history.length + '）', history)}
          </Box>
        )}

        {/* 发起约战 */}
        <Dialog open={createOpen} onClose={() => setCreateOpen(false)} fullWidth maxWidth="sm">
          <DialogTitle>发起约战</DialogTitle>
          <DialogContent dividers>
            <TextField label="标题" fullWidth sx={{ mb: 2, mt: 1 }} inputProps={{ maxLength: 50 }}
              value={f.title} onChange={(e) => setF(Object.assign({}, f, { title: e.target.value }))} />
            <InputLabel shrink>对方战队</InputLabel>
            <Select fullWidth sx={{ mb: 2 }} value={f.defender} displayEmpty
              onChange={(e) => setF(Object.assign({}, f, { defender: e.target.value }))}>
              <MenuItem value="">请选择（平台租户 / 外部战队）</MenuItem>
              {parties.map(function (p) {
                return <MenuItem key={p.type + ':' + p.id} value={p.type + ':' + p.id}>
                  {p.name}{p.type === 'external' ? '（外部战队）' : ''}
                </MenuItem>;
              })}
            </Select>
            <Stack direction="row" spacing={2} sx={{ mb: 2 }}>
              <TextField select label="赛事类型" sx={{ width: 180 }} value={f.btype}
                onChange={(e) => setF(Object.assign({}, f, { btype: e.target.value }))}>
                <MenuItem value="official">正式赛事（平台裁决）</MenuItem>
                <MenuItem value="training">训练赛（自动抽签自裁决）</MenuItem>
              </TextField>
              <TextField select label="赛制" sx={{ width: 260 }} value={f.format}
                onChange={(e) => setF(Object.assign({}, f, { format: e.target.value }))}>
                <MenuItem value="bo1">BO1 单局定胜负</MenuItem>
                <MenuItem value="bo3">BO3 三局两胜</MenuItem>
                <MenuItem value="bo5">BO5 五局三胜</MenuItem>
                <MenuItem value="r2">R2 一图攻防转换两把（可平局）</MenuItem>
                <MenuItem value="r4">R4 两图攻防转换四把（可平局）</MenuItem>
              </TextField>
              <TextField label="每方人数上限（0=不限）" sx={{ width: 200 }} type="number"
                inputProps={{ min: 0, max: 100 }} value={f.limit}
                onChange={(e) => setF(Object.assign({}, f, { limit: e.target.value }))} />
            </Stack>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>
              BO3：前两局同图攻防互换，第三局决胜（双攻胜比进攻用时、双守胜比占领区域/占点比例）。
              BO5：前两局第一张图、第三四局第二张图重新抽签攻防，2:2 时率先两胜方选图或选边打决胜局（新图）。
              训练赛：双方提交禁图后系统自动抽签，胜负由双方管理员上传截图自裁决。
            </Typography>
            <TextField label="开赛时间" type="datetime-local" fullWidth sx={{ mb: 2 }}
              InputLabelProps={{ shrink: true }} value={f.time}
              onChange={(e) => setF(Object.assign({}, f, { time: e.target.value }))} />
            <TextField label="模式说明（如：全面战场 攻防模式）" fullWidth sx={{ mb: 2 }} inputProps={{ maxLength: 50 }}
              value={f.mode} onChange={(e) => setF(Object.assign({}, f, { mode: e.target.value }))} />
            <TextField label="房间/地点" fullWidth sx={{ mb: 2 }} inputProps={{ maxLength: 200 }}
              value={f.room} onChange={(e) => setF(Object.assign({}, f, { room: e.target.value }))} />
            <TextField label="备注" fullWidth multiline minRows={2} inputProps={{ maxLength: 500 }}
              value={f.remark} onChange={(e) => setF(Object.assign({}, f, { remark: e.target.value }))} />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setCreateOpen(false)}>取消</Button>
            <Button variant="contained" onClick={submitCreate}>发起</Button>
          </DialogActions>
        </Dialog>

        {/* 提交禁图 */}
        <Dialog open={banId > 0} onClose={() => setBanId(0)} fullWidth maxWidth="xs">
          <DialogTitle>提交禁用地图</DialogTitle>
          <DialogContent dividers>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              每方限一次；不选地图直接提交 = 放弃禁用权。抽签将从平台地图池剔除双方禁图后随机抽取。
            </Typography>
            {mapPool === null ? <LoadingBox /> : (
              <Select fullWidth value={banMapId} displayEmpty onChange={(e) => setBanMapId(e.target.value)}>
                <MenuItem value="">不禁用（放弃）</MenuItem>
                {mapPool.map(function (m) { return <MenuItem key={m.id} value={String(m.id)}>{m.name}</MenuItem>; })}
              </Select>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setBanId(0)}>取消</Button>
            <Button variant="contained" onClick={submitBan}>提交</Button>
          </DialogActions>
        </Dialog>

        {/* 训练赛录胜负（含可选统计与结果截图） */}
        <Dialog open={rec.battleId > 0} onClose={() => setRec({ battleId: 0, round: null, result: '', file: null, secs: '', count: '', ratio: '' })} fullWidth maxWidth="xs">
          <DialogTitle>录入小局胜负{rec.round ? '（第 ' + rec.round.round_no + ' 局 · ' + (rec.round.map_name || '') + '）' : ''}</DialogTitle>
          <DialogContent dividers>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              训练赛由双方管理员自行裁决；统计与截图用于 BO3 战成 1:1 时判定决胜局优势方（双攻胜比进攻用时，双守胜比占领区域/占点比例）。
            </Typography>
            <TextField select label="获胜方" fullWidth sx={{ mb: 2 }} value={rec.result}
              onChange={(e) => setRec(Object.assign({}, rec, { result: e.target.value }))}>
              <MenuItem value="">请选择</MenuItem>
              {detail && <MenuItem value="a_win">{detail.challenger.name} 获胜</MenuItem>}
              {detail && <MenuItem value="b_win">{detail.defender.name} 获胜</MenuItem>}
            </TextField>
            <Stack direction="row" spacing={1.5} sx={{ mb: 2 }}>
              <TextField label="进攻用时（秒）" type="number" fullWidth inputProps={{ min: 0 }} value={rec.secs}
                onChange={(e) => setRec(Object.assign({}, rec, { secs: e.target.value }))} />
              <TextField label="占领区域数" type="number" fullWidth inputProps={{ min: 0 }} value={rec.count}
                onChange={(e) => setRec(Object.assign({}, rec, { count: e.target.value }))} />
              <TextField label="占点比例（%）" type="number" fullWidth inputProps={{ min: 0, max: 100 }} value={rec.ratio}
                onChange={(e) => setRec(Object.assign({}, rec, { ratio: e.target.value }))} />
            </Stack>
            <Button component="label" variant="outlined" size="small" sx={{ mb: 1 }}>
              {rec.file ? '已选：' + rec.file.name : '选择结果截图（可选）'}
              <input type="file" accept="image/*" hidden onChange={(e) => setRec(Object.assign({}, rec, { file: e.target.files[0] || null }))} />
            </Button>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setRec({ battleId: 0, round: null, result: '', file: null, secs: '', count: '', ratio: '' })}>取消</Button>
            <Button variant="contained" onClick={submitRecord}>提交</Button>
          </DialogActions>
        </Dialog>

        {/* 决胜局选图/选边（优势方二选一） */}
        <Dialog open={dec.battleId > 0} onClose={() => setDec({ battleId: 0, choice: 'pick_map', mapId: '', side: 'attack' })} fullWidth maxWidth="xs">
          <DialogTitle>决胜局：选图或选边（二选一）</DialogTitle>
          <DialogContent dividers>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              选图：指定决胜局地图，攻防关系由系统随机；选边：选择本局进攻或防守，地图由系统从剩余新图随机抽取。决胜局地图不可与本场前序地图相同。
            </Typography>
            <TextField select label="决胜优势用法" fullWidth sx={{ mb: 2 }} value={dec.choice}
              onChange={(e) => setDec(Object.assign({}, dec, { choice: e.target.value }))}>
              <MenuItem value="pick_map">选图（指定地图）</MenuItem>
              <MenuItem value="pick_side">选边（选进攻/防守）</MenuItem>
            </TextField>
            {dec.choice === 'pick_map' ? (
              mapPool === null ? <LoadingBox /> : (
                <Select fullWidth value={dec.mapId} displayEmpty onChange={(e) => setDec(Object.assign({}, dec, { mapId: e.target.value }))}>
                  <MenuItem value="">请选择决胜局地图</MenuItem>
                  {deciderMaps().map(function (m) { return <MenuItem key={m.id} value={String(m.id)}>{m.name}</MenuItem>; })}
                </Select>
              )
            ) : (
              <Select fullWidth value={dec.side} onChange={(e) => setDec(Object.assign({}, dec, { side: e.target.value }))}>
                <MenuItem value="attack">本局我方进攻</MenuItem>
                <MenuItem value="defend">本局我方防守</MenuItem>
              </Select>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setDec({ battleId: 0, choice: 'pick_map', mapId: '', side: 'attack' })}>取消</Button>
            <Button variant="contained" onClick={submitDecider}>提交</Button>
          </DialogActions>
        </Dialog>

        {/* 名单管理 */}
        <Dialog open={rosterId > 0} onClose={() => setRosterId(0)} fullWidth maxWidth="sm">
          <DialogTitle>出战名单管理</DialogTitle>
          <DialogContent dividers>
            <Typography variant="subtitle2" sx={{ mb: 0.5 }}>当前本方名单</Typography>
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5, mb: 2 }}>
              {rosterNames.length === 0 ? <Typography variant="caption" color="text.secondary">暂无</Typography>
                : rosterNames.map(function (s) {
                  return <Chip key={s.id} label={s.name} onDelete={() => removeSignup(s.id)} />;
                })}
            </Box>
            <Typography variant="subtitle2" sx={{ mb: 0.5 }}>勾选本站成员添加（上报昵称，不报 QQ）</Typography>
            <Box sx={{ maxHeight: 260, overflow: 'auto' }}>
              {members.map(function (u) {
                const on = picked.indexOf(u.id) >= 0;
                return (
                  <Box key={u.id}>
                    <FormControlLabel
                      control={<Checkbox checked={on} onChange={() => {
                        setPicked(on ? picked.filter(function (x) { return x !== u.id; }) : picked.concat([u.id]));
                      }} />}
                      label={(u.nickname || '') + '（' + u.username + '）'} />
                  </Box>
                );
              })}
            </Box>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setRosterId(0)}>关闭</Button>
            <Button variant="contained" disabled={picked.length === 0} onClick={submitRoster}>添加所选（{picked.length}）</Button>
          </DialogActions>
        </Dialog>
      </Box>
    );
  }

  // 管理页签入口：探测连接器状态后决定数据源（两种模式互斥）
  function AdminBattles() {
    const [hub, setHub] = useState(null);
    useEffect(function () {
      (async function () {
        try {
          const j = await api('/api/battle-hub/status');
          setHub(j.code === 0 && !!j.data.enabled);
        } catch (e) { setHub(false); }
      })();
    }, []);
    if (hub === null) return <LoadingBox />;
    return <BattleManage hub={hub} />;
  }

  // v5.13.0：约战成立自动公告开关（本队级设置，默认开；两种模式都存本站）
  function BattleNoticeSwitch() {
    const [on, setOn] = useState(null);
    useEffect(function () {
      (async function () {
        try {
          const j = await api('/api/admin/battle-notice');
          if (j.code === 0) setOn(!!j.data.enabled);
        } catch (e) { /* 拿不到状态就不展示开关 */ }
      })();
    }, []);
    if (on === null) return null;
    const save = async function () {
      try {
        const j = await api('/api/admin/battle-notice', { method: 'POST', body: { enabled: on ? 0 : 1 } });
        if (j.code === 0) { setOn(!!j.data.enabled); toast(j.msg, 'success'); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); }
    };
    return (
      <Paper variant="outlined" sx={{ p: 1.5, mb: 2 }}>
        <FormControlLabel
          control={<Switch checked={on} onChange={save} />}
          label={<Typography variant="body2">约战成立后自动向本队发公告（要求已读回执）</Typography>} />
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', pl: 6 }}>
          开启时：管理员同意对方约战的那一刻，双方各自向本队成员推一条需确认的公告，成员进站即可看到未读提醒。
          关闭后只是不再自动发，管理员仍可自行发布公告。
        </Typography>
      </Paper>
    );
  }

  // 待同意约战行归一化（站内接口给 challenger 字符串，连接器列表给 { name } 对象）
  function normalizePending(b) {
    const c = b.challenger;
    return {
      id: b.id,
      title: b.title || '未命名约战',
      game_mode: b.game_mode || '',
      battle_time: b.battle_time || '',
      series_format: b.series_format || '',
      battle_type: b.battle_type || 'official',
      roster_limit: Number(b.roster_limit || 0),
      challenger: (c && c.name) ? c.name : (c || '对方战队'),
    };
  }

  // v5.13.0：来战待同意——本队管理员进站强制弹窗（无约战权限的管理员和普通成员都不请求、也看不到）
  function PendingBattleGuard({ me }) {
    const perms = me.admin_perms || [];
    const can = !!me.is_super || perms.indexOf('battle') >= 0;
    const [hub, setHub] = useState(false);
    const [rows, setRows] = useState([]);
    const [later, setLater] = useState({});   // 本次停留内选了「稍后处理」的单子
    const [busy, setBusy] = useState(false);

    const actUrl = function (id, a) {
      return (hub ? '/api/battle-hub/battles/' : '/api/admin/battles/') + id + '/' + a;
    };

    // 连接器模式本地没有 battles 表，数据源换成约战中心列表再筛本方待同意的
    useEffect(function () {
      if (!can) return;
      (async function () {
        try {
          const j = await api('/api/battle-hub/status');
          setHub(j.code === 0 && !!j.data.enabled);
        } catch (e) { /* 保持站内总线模式 */ }
      })();
    }, [can]);

    const load = useCallback(async function () {
      if (!can) return;
      try {
        let list = [];
        if (hub) {
          const j = await api('/api/battle-hub/battles');
          if (j.code === 0) list = (j.data || []).filter(function (b) { return b.status === 'pending' && b.my_side === 'b'; });
        } else {
          const j = await api('/api/admin/battles/pending');
          if (j.code === 0) list = j.data.list || [];
        }
        setRows(list.map(normalizePending));
      } catch (e) { /* 拉取失败不打扰管理员正常操作 */ }
    }, [can, hub]);

    useEffect(function () {
      if (!can) return;
      load();
      const t = setInterval(load, 60000);
      return function () { clearInterval(t); };
    }, [load]);

    if (!can) return null;

    const act = async function (id, a) {
      if (a === 'reject' && !window.confirm('确定拒绝这场约战吗？')) return;
      setBusy(true);
      try {
        const j = await api(actUrl(id, a), { method: 'POST' });
        if (j.code === 0) { toast(j.msg, 'success'); load(); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
      setBusy(false);
    };
    const putLater = function (id) {
      setLater(function (prev) { const n = Object.assign({}, prev); n[id] = 1; return n; });
    };

    const list = rows.filter(function (r) { return !later[r.id]; });
    if (list.length === 0) return null;

    return (
      <Dialog open disableEscapeKeyDown PaperProps={{ sx: { borderRadius: 3, width: '100%', maxWidth: 560 } }}>
        <DialogTitle>有约战等待你同意</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
            对方战队已发起约战，需要本队管理员同意才能成立；同意后会自动向本队发布需已读回执的公告。
          </Typography>
          {list.map(function (b) {
            return (
              <Paper key={b.id} variant="outlined" sx={{ p: 1.5, mb: 1.5, bgcolor: BG_GRAY }}>
                <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5 }}>
                  <Chip size="small" variant="outlined" color={b.battle_type === 'training' ? 'warning' : 'info'}
                    label={b.battle_type === 'training' ? '训练赛' : '正式赛事'} />
                  <Typography variant="subtitle2" sx={{ flex: 1 }} noWrap>{b.title}</Typography>
                </Stack>
                <Typography variant="body2">
                  {b.challenger} 约战本队 · {fmtTime(b.battle_time)} 开赛
                  {b.series_format ? ' · ' + b.series_format.toUpperCase() : ''}
                  {b.game_mode ? ' · ' + b.game_mode : ''}
                  {b.roster_limit > 0 ? ' · 每方上限 ' + b.roster_limit + ' 人' : ''}
                </Typography>
                <Stack direction="row" spacing={1} sx={{ mt: 1 }} alignItems="center">
                  <Button size="small" variant="contained" disabled={busy} onClick={() => act(b.id, 'accept')}>同意约战</Button>
                  <Button size="small" color="error" variant="outlined" disabled={busy} onClick={() => act(b.id, 'reject')}>拒绝</Button>
                  <Box sx={{ flex: 1 }} />
                  <Button size="small" color="inherit" onClick={() => putLater(b.id)}>稍后处理</Button>
                </Stack>
              </Paper>
            );
          })}
        </DialogContent>
        <DialogActions>
          <Typography variant="caption" color="text.secondary" sx={{ mr: 'auto', ml: 1 }}>
            选了稍后处理的话，刷新页面会再次提醒，直到本队同意或拒绝为止
          </Typography>
        </DialogActions>
      </Dialog>
    );
  }

  // API 密钥与约战中心配置（仅超管）
  function AdminApiKeys() {
    const [cfg, setCfg] = useState(null);           // 连接配置（含约战中心未接入标志）
    const [keys, setKeys] = useState(null);         // 本队密钥列表（连接器模式不加载）
    const [hubForm, setHubForm] = useState({ enabled: false, url: '', key: '' });
    const [createOpen, setCreateOpen] = useState(false);
    const [admins, setAdmins] = useState([]);
    const [bindUid, setBindUid] = useState('');
    const [keyName, setKeyName] = useState('');
    const [plain, setPlain] = useState('');

    const loadCfg = useCallback(async function () {
      try {
        const j = await api('/api/admin/battle-hub-config');
        if (j.code === 0) {
          setCfg(j.data);
          setHubForm({ enabled: j.data.enabled, url: j.data.url, key: '' });
        }
      } catch (e) { /* 忽略 */ }
    }, []);
    const loadKeys = useCallback(async function () {
      try {
        const j = await api('/api/admin/api-keys');
        if (j.code === 0) setKeys(j.data || []);
      } catch (e) { setKeys([]); }
    }, []);
    useEffect(function () { loadCfg(); loadKeys(); }, [loadCfg, loadKeys]);

    const saveHub = async function () {
      try {
        const j = await api('/api/admin/battle-hub-config', { method: 'POST', body: hubForm });
        if (j.code === 0) { toast(j.msg, 'success'); loadCfg(); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); }
    };

    const openCreate = async function () {
      setCreateOpen(true); setKeyName(''); setBindUid('');
      try {
        const j = await api('/api/admin/users');
        if (j.code === 0) setAdmins((j.data.list || []).filter(function (u) { return u.role === 'admin' && u.status === 'approved'; }));
      } catch (e) { setAdmins([]); }
    };
    const submitKey = async function () {
      try {
        const j = await api('/api/admin/api-keys', { method: 'POST', body: {
          name: keyName, bind_user_id: bindUid ? parseInt(bindUid, 10) : 0,
        } });
        if (j.code === 0) { setCreateOpen(false); setPlain(j.data.key); loadKeys(); }
        else { toast(j.msg, 'error'); }
      } catch (e) { toast('生成失败', 'error'); }
    };
    const toggleKey = async function (id) {
      try {
        const j = await api('/api/admin/api-keys/' + id + '/status', { method: 'POST' });
        if (j.code === 0) { toast(j.msg, 'success'); loadKeys(); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };
    const removeKey = async function (id) {
      if (!window.confirm('删除后该密钥立即失效且不可恢复，确定吗？')) return;
      try {
        const j = await api('/api/admin/api-keys/' + id, { method: 'DELETE' });
        if (j.code === 0) { toast(j.msg, 'success'); loadKeys(); } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (cfg === null) return <LoadingBox />;
    return (
      <Box>
        {/* 约战中心连接配置（连接器模式；未接入时同样在此开启） */}
        <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>约战中心连接（单租户开源部署接入平台约战总线）</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            开启后，本站以「外部战队」身份接入约战中心；密钥由平台超管登记本站后签发，只存服务端不出前端。
          </Typography>
          <Stack spacing={2}>
            <FormControlLabel control={<Switch checked={hubForm.enabled}
              onChange={(e) => setHubForm(Object.assign({}, hubForm, { enabled: e.target.checked }))} />}
              label={hubForm.enabled ? '已接入约战中心' : '未接入'} />
            <TextField label="约战中心地址" placeholder="https://hub.example.com" fullWidth
              value={hubForm.url} onChange={(e) => setHubForm(Object.assign({}, hubForm, { url: e.target.value }))} />
            <TextField label="External API Key" fullWidth
              placeholder={cfg.key_set ? '已配置（' + cfg.key_masked + '），留空保持不变' : '平台超管签发的 external Key'}
              value={hubForm.key} onChange={(e) => setHubForm(Object.assign({}, hubForm, { key: e.target.value }))} />
            <Box><Button variant="contained" onClick={saveHub}>保存配置</Button></Box>
          </Stack>
        </Paper>

        {/* 本队 API 密钥（供私有客户端调用开放接口） */}
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction="row" alignItems="center" sx={{ mb: 1 }}>
            <Typography variant="subtitle1" fontWeight={600} sx={{ flex: 1 }}>本队 API 密钥（私有客户端用）</Typography>
            <Button variant="contained" size="small" onClick={openCreate}>生成密钥</Button>
          </Stack>
          {keys === null ? <LoadingBox /> : keys.length === 0 ? <EmptyBox text="暂无密钥" /> : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>前缀</TableCell><TableCell>备注</TableCell><TableCell>绑定身份</TableCell>
                    <TableCell>状态</TableCell><TableCell>最后使用</TableCell><TableCell>操作</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {keys.map(function (k) {
                    return (
                      <TableRow key={k.id}>
                        <TableCell sx={{ fontFamily: 'Consolas, monospace' }}>{k.key_prefix}…</TableCell>
                        <TableCell>{k.name}</TableCell>
                        <TableCell>{k.bound_user}</TableCell>
                        <TableCell>{parseInt(k.status, 10) === 1
                          ? <Chip size="small" color="success" label="启用" />
                          : <Chip size="small" label="停用" />}</TableCell>
                        <TableCell>{fmtTime(k.last_used_at) || '-'}</TableCell>
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
        </Paper>

        {/* 生成密钥 */}
        <Dialog open={createOpen} onClose={() => setCreateOpen(false)} fullWidth maxWidth="xs">
          <DialogTitle>生成本队密钥</DialogTitle>
          <DialogContent dividers>
            <TextField label="备注" fullWidth sx={{ mb: 2, mt: 1 }} value={keyName}
              onChange={(e) => setKeyName(e.target.value)} placeholder="如：我的私有客户端" />
            <InputLabel shrink>绑定管理员身份（默认当前账号）</InputLabel>
            <Select fullWidth value={bindUid} displayEmpty onChange={(e) => setBindUid(e.target.value)}>
              <MenuItem value="">当前账号</MenuItem>
              {admins.map(function (u) {
                return <MenuItem key={u.id} value={String(u.id)}>{(u.nickname || '') + '（' + u.username + '）'}</MenuItem>;
              })}
            </Select>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setCreateOpen(false)}>取消</Button>
            <Button variant="contained" onClick={submitKey}>生成</Button>
          </DialogActions>
        </Dialog>

        {/* 明文一次性展示 */}
        <Dialog open={plain !== ''} onClose={() => setPlain('')} fullWidth maxWidth="sm">
          <DialogTitle>密钥已生成</DialogTitle>
          <DialogContent dividers>
            <Alert severity="warning" sx={{ mb: 1.5 }}>密钥仅本次显示，请立即保存！遗失只能删除重发。</Alert>
            <TextField fullWidth value={plain} InputProps={{ readOnly: true, sx: { fontFamily: 'Consolas, monospace' } }} />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => { copyText(plain); }}>复制</Button>
            <Button variant="contained" onClick={() => setPlain('')}>我已保存</Button>
          </DialogActions>
        </Dialog>
      </Box>
    );
  }

  // 单条报名卡片：名单（整表覆盖）/小队构建/上场勾选/撤回（经主站代理）
  // v5.5.1：多站点绑定支持——site 传入时给所有写操作附带 site_id
  function TEntryCard({ e, onChanged, onWithdraw, site }) {
    const [rosterText, setRosterText] = useState('');
    const [squads, setSquads] = useState([]);
    const [sqName, setSqName] = useState('');
    const [sqPicked, setSqPicked] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      setRosterText((e.roster || []).map(function (r) { return r.display_name; }).join('\n'));
      setSquads(e.squads || []);
    }, [e]);

    const rosterIds = e.roster || [];
    const withSite = function (body) { if (site) return Object.assign({ site_id: site }, body); return body; };
    const post = async function (path, body) {
      setBusy(true);
      try {
        const json = await api(path, { method: 'POST', body: withSite(body) });
        if (json.code === 0) { toast(json.msg, 'success'); onChanged(); } else { toast(json.msg, 'error'); }
      } catch (ex) { toast('操作失败', 'error'); }
      setBusy(false);
    };
    const saveRoster = function () {
      const names = rosterText.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
      post('/api/tournament-center/roster', { tournament_id: e.tournament_id, roster: names });
    };
    const addSquad = function () {
      if (!sqName.trim()) { toast('请填写小队名', 'error'); return; }
      if (sqPicked.length !== 4) { toast('每支小队必须恰好勾选 4 人', 'error'); return; }
      setSquads(squads.concat([{ name: sqName.trim(), members: sqPicked }]));
      setSqName(''); setSqPicked([]);
    };
    const saveSquads = function () {
      post('/api/tournament-center/squads', { tournament_id: e.tournament_id, squads: squads });
    };
    const submitMatchSquads = function (m, ids) {
      post('/api/tournament-center/match-squads', { tournament_id: e.tournament_id, match_id: m.id, squad_ids: ids });
    };

    const statusColor = e.status === 'approved' ? 'success' : (e.status === 'pending' ? 'warning' : 'default');

    return (
      <Paper sx={{ p: 2, mb: 2 }}>
        <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between">
          <Stack direction="row" spacing={1} alignItems="center">
            <Typography variant="subtitle1">{e.tournament_name}</Typography>
            <Chip label={tSt(e.status)} size="small" color={statusColor} />
          </Stack>
          {(e.status === 'pending' || e.status === 'approved') ? (
            <Button size="small" color="error" onClick={onWithdraw}>撤回报名</Button>
          ) : null}
        </Stack>
        {e.status === 'approved' ? (
          <Box sx={{ mt: 1 }}>
            <Divider sx={{ my: 1 }} />
            <Typography variant="subtitle2">参赛名单（每行一个昵称，最多 24 人；保存为整表覆盖）</Typography>
            <TextField multiline minRows={3} maxRows={10} fullWidth size="small" sx={{ mt: 0.5, fontFamily: 'monospace' }}
              value={rosterText} onChange={function (ev) { setRosterText(ev.target.value); }} />
            <Button size="small" variant="outlined" sx={{ mt: 1 }} disabled={busy} onClick={saveRoster}>保存名单</Button>

            <Divider sx={{ my: 1.5 }} />
            <Typography variant="subtitle2">战术小队（每队恰好 4 人，最多 6 队；保存名单会清空小队）</Typography>
            <Box sx={{ display: 'flex', flexWrap: 'wrap', my: 0.5 }}>
              {rosterIds.map(function (r) {
                const checked = sqPicked.indexOf(r.id) >= 0;
                return (
                  <FormControlLabel key={r.id} sx={{ width: 'auto' }} control={
                    <Checkbox size="small" checked={checked} onChange={function () {
                      setSqPicked(checked ? sqPicked.filter(function (x) { return x !== r.id; }) : sqPicked.concat([r.id]));
                    }} />
                  } label={r.display_name} />
                );
              })}
            </Box>
            <Stack direction="row" spacing={1} sx={{ mb: 1 }}>
              <TextField size="small" label="小队名" value={sqName}
                onChange={function (ev) { setSqName(ev.target.value); }} />
              <Button size="small" variant="outlined" onClick={addSquad}>加入待提交列表</Button>
              <Button size="small" variant="contained" disabled={busy} onClick={saveSquads}>保存全部小队</Button>
            </Stack>
            {squads.length > 0 ? (
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5, mb: 1 }}>
                {squads.map(function (s, i) {
                  return <Chip key={i} size="small" label={s.name + '（4人）'} onDelete={function () {
                    setSquads(squads.filter(function (x, j) { return j !== i; }));
                  }} />;
                })}
              </Box>
            ) : null}

            {(e.matches || []).length > 0 ? (
              <Box sx={{ mt: 1 }}>
                <Divider sx={{ my: 1 }} />
                <Typography variant="subtitle2">我的对阵</Typography>
                {e.matches.map(function (m) {
                  const mine = m.my_side === 'a' ? m.squads_a : m.squads_b;
                  return <TMatchRow key={m.id} m={m} squads={e.squads || []} picked={mine} onSubmit={function (ids) {
                    submitMatchSquads(m, ids);
                  }} />;
                })}
              </Box>
            ) : null}
          </Box>
        ) : (
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
            {e.status === 'pending' ? '等待赛事组审核，通过后可编辑名单与小队' : '当前状态不可操作'}
          </Typography>
        )}
      </Paper>
    );
  }

  // 对阵行：仅 manual 赛制且等待小队时可勾选上场小队（决胜前格子）
  function TMatchRow({ m, squads, picked, onSubmit }) {
    const [sel, setSel] = useState(picked || []);
    useEffect(function () { setSel(picked || []); }, [picked]);
    const editable = m.status === 'squads_pending';
    return (
      <Paper variant="outlined" sx={{ p: 1.2, mb: 1 }}>
        <Stack direction="row" spacing={1} alignItems="center">
          <Typography variant="body2" sx={{ flex: 1 }}>
            {m.label}：对阵 {m.opponent || '待定'}
          </Typography>
          <Chip size="small" label={tSt(m.status)} />
        </Stack>
        {editable && squads.length > 0 ? (
          <Box sx={{ mt: 0.8 }}>
            <Box sx={{ display: 'flex', flexWrap: 'wrap' }}>
              {squads.map(function (s) {
                const checked = sel.indexOf(s.id) >= 0;
                return (
                  <FormControlLabel key={s.id} sx={{ width: 'auto' }} control={
                    <Checkbox size="small" checked={checked} onChange={function () {
                      setSel(checked ? sel.filter(function (x) { return x !== s.id; }) : sel.concat([s.id]));
                    }} />
                  } label={s.name} />
                );
              })}
            </Box>
            <Button size="small" variant="contained" sx={{ mt: 0.5 }} onClick={() => onSubmit(sel)}>提交上场小队</Button>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
              上场规则由赛事站判定（默认每边 5 队），未按时勾选将由系统随机抽取
            </Typography>
          </Box>
        ) : editable ? (
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
            尚未提交小队，无法勾选上场（随机赛制由系统自动抽取）
          </Typography>
        ) : null}
      </Paper>
    );
  }

  // 管理中心「赛事中心」页签：多站点绑定聚合 + 报名/名单/小队（v5.5.0 单站 / v5.5.1 多站点）
  function AdminTournament() {
    const [binds, setBinds] = useState(null);        // 可用绑定（平台共享 + 已确认自增）
    const [mine, setMine] = useState(null);          // 自增绑定（含待确认）
    const [entriesBy, setEntriesBy] = useState({});  // bindingId -> entries
    const [applySel, setApplySel] = useState({});    // bindingId -> selected tid
    const [addForm, setAddForm] = useState({ name: '', url: '', key: '' });
    const [addCfm, setAddCfm] = useState(false);

    const loadBinds = useCallback(async function () {
      try {
        const j = await api('/api/tournament-center/bindings');
        if (j.code === 0) setBinds(j.data.bindings || []);
      } catch (e) { setBinds([]); }
    }, []);
    const loadMine = useCallback(async function () {
      try {
        const j = await api('/api/tournament-center/bindings-mine');
        if (j.code === 0) setMine(j.data.bindings || []);
      } catch (e) { setMine([]); }
    }, []);
    const loadEntries = useCallback(async function (id) {
      try {
        const j = await api('/api/tournament-center/my?site_id=' + id);
        if (j.code === 0) {
          setEntriesBy(function (prev) {
            const next = Object.assign({}, prev);
            next[id] = j.data.entries || [];
            return next;
          });
        }
      } catch (e) { /* 忽略 */ }
    }, []);
    useEffect(function () { loadBinds(); loadMine(); }, [loadBinds, loadMine]);

    if (binds === null || mine === null) return <LoadingBox />;

    const doApply = async function (id) {
      const tid = applySel[id];
      if (!tid) { toast('请先选择赛事', 'error'); return; }
      try {
        const json = await api('/api/tournament-center/apply', { method: 'POST', body: { tournament_id: Number(tid), site_id: id } });
        if (json.code === 0) { toast(json.msg, 'success'); loadEntries(id); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };
    const doWithdraw = async function (id, e) {
      try {
        const json = await api('/api/tournament-center/withdraw', { method: 'POST', body: { tournament_id: e.tournament_id, site_id: id } });
        if (json.code === 0) { toast(json.msg, 'success'); loadEntries(id); } else { toast(json.msg, 'error'); }
      } catch (ex) { toast('操作失败', 'error'); }
    };
    const doAdd = async function () {
      if (!addCfm) { toast('请勾选「已确认授权」后启用', 'error'); return; }
      if (!addForm.name.trim() || !addForm.url.trim() || !addForm.key.trim()) { toast('请填写名称/地址/Key', 'error'); return; }
      try {
        const json = await api('/api/tournament-center/bindings/add', { method: 'POST', body: addForm });
        if (json.code === 0) { toast(json.msg, 'success'); setAddForm({ name: '', url: '', key: '' }); setAddCfm(false); loadMine(); loadBinds(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };
    const doConfirm = async function (bid) {
      try {
        const json = await api('/api/tournament-center/bindings/confirm', { method: 'POST', body: { binding_id: bid } });
        if (json.code === 0) { toast(json.msg, 'success'); loadMine(); loadBinds(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };
    const doRemove = async function (bid) {
      if (!window.confirm('删除后将无法再使用该赛事中心，确定删除？')) return;
      try {
        const json = await api('/api/tournament-center/bindings/remove', { method: 'POST', body: { binding_id: bid } });
        if (json.code === 0) { toast(json.msg, 'success'); loadMine(); loadBinds(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          可接入<b>多个赛事中心</b>（独立部署的网站）。平台共享库中的赛事中心可直接使用；
          你也可以自行添加未收录的赛事中心并勾选授权后启用。报名/名单/小队均经本系统代理，比赛结果不回写本系统。
        </Alert>

        {/* 自增绑定管理 */}
        <Paper sx={{ p: 2, mb: 2 }}>
          <Typography variant="subtitle2" sx={{ mb: 1 }}>我自行接入的赛事中心</Typography>
          {mine.length === 0 ? <Typography variant="caption" color="text.secondary">还没有自行添加，可在下方添加</Typography> : mine.map(function (b) {
            return (
              <Stack key={b.id} direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5 }}>
                <Typography variant="body2" sx={{ flex: 1 }}>{b.name}</Typography>
                <Chip size="small" label={b.confirmed ? '已启用' : '待确认'} color={b.confirmed ? 'success' : 'warning'} />
                <Button size="small" color="primary"
                  disabled={!!b.confirmed}
                  onClick={function () {
                    if (!window.confirm('我确认已将本战队数据发往该外部赛事中心，允许其接入？')) return;
                    doConfirm(b.id);
                  }}>
                  {b.confirmed ? '已授权' : '确认授权'}
                </Button>
                <Button size="small" color="error" onClick={function () { doRemove(b.id); }}>删除</Button>
              </Stack>
            );
          })}
          <Divider sx={{ my: 1.5 }} />
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
            添加外部赛事中心：填写地址与其签发的 API Key，保存后需<b>勾选授权确认</b>才会启用（适用于平台未收录的第三方站点）
          </Typography>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1}>
            <TextField size="small" label="名称" value={addForm.name} sx={{ minWidth: 150 }}
              onChange={function (ev) { setAddForm({ ...addForm, name: ev.target.value }); }} />
            <TextField size="small" label="赛事中心地址" value={addForm.url} sx={{ minWidth: 220 }}
              placeholder="https://tournament.example.com"
              onChange={function (ev) { setAddForm({ ...addForm, url: ev.target.value }); }} />
            <TextField size="small" label="API Key" value={addForm.key} sx={{ minWidth: 200 }}
              onChange={function (ev) { setAddForm({ ...addForm, key: ev.target.value }); }} />
            <Button variant="contained" onClick={doAdd}>添加并保存</Button>
          </Stack>
          <FormControlLabel sx={{ mt: 1 }} control={
            <Checkbox size="small" checked={addCfm} onChange={function (ev) { setAddCfm(ev.target.checked); }} />
          } label="我已确认将该战队资格授权，允许数据发往该外部站点，确认后立即生效（平台已收录的赛事中心无需此确认）" />
        </Paper>

        {/* 可用赛事中心聚合 */}
        {binds.length === 0 ? <Alert severity="info">暂无可用赛事中心（联系平台超管添加，或在上方自行添加并授权启用）</Alert>
          : binds.map(function (b) {
            const signupPool = (b.tournaments || []).filter(function (t) { return t.status === 'signup'; });
            const entries = entriesBy[b.id] || [];
            return (
              <Paper key={b.id} sx={{ p: 2, mb: 1.5 }}>
                <Stack direction="row" spacing={1} alignItems="center">
                  <Typography variant="subtitle2" sx={{ flex: 1 }}>{b.name}</Typography>
                  <Chip size="small" label={b.source === 'platform' ? '平台共享' : '自行接入'} color={b.source === 'platform' ? 'primary' : 'default'} />
                  <Chip size="small" label={b.connected ? '已连通' : '连接失败'} color={b.connected ? 'success' : 'error'} />
                  {b.url ? <Button size="small" onClick={function () { window.open(b.url, '_blank'); }}>打开门户</Button> : null}
                  <Button size="small" variant="outlined" onClick={function () { loadEntries(b.id); }}>刷新报名</Button>
                </Stack>
                {!b.connected ? <Alert severity="warning" sx={{ mt: 1 }}>无法连接该赛事中心，请检查地址与 API Key</Alert> : (
                  <Box sx={{ mt: 1 }}>
                    {signupPool.length > 0 ? (
                      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
                        <Box sx={{ minWidth: 260 }}>
                          <Select fullWidth size="small" displayEmpty value={applySel[b.id] || ''}
                            onChange={function (ev) { setApplySel({ ...applySel, [b.id]: ev.target.value }); }}>
                            <MenuItem value="" disabled>选择报名赛事…</MenuItem>
                            {signupPool.map(function (t) {
                              const applied = entries.some(function (en) { return Number(en.tournament_id) === Number(t.id); });
                              return <MenuItem key={t.id} value={String(t.id)} disabled={applied}>
                                {t.name}（截止 {t.signup_deadline}）{applied ? '・已报名' : ''}
                              </MenuItem>;
                            })}
                          </Select>
                        </Box>
                        <Button variant="contained" onClick={function () { doApply(b.id); }}>报名</Button>
                      </Stack>
                    ) : <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>当前没有报名中的赛事</Typography>}
                    {entries.length === 0 ? <Typography variant="caption" color="text.secondary">本队在「{b.name}」还没有报名记录（展开后仍可点「刷新报名」）</Typography>
                      : entries.map(function (e) {
                        return <TEntryCard key={e.id} e={e} site={b.id}
                          onChanged={function () { loadEntries(b.id); }}
                          onWithdraw={function () { doWithdraw(b.id, e); }} />;
                      })}
                  </Box>
                )}
              </Paper>
            );
          })}
      </Box>
    );
  }

  // ==================== 管理中心 ====================
  // 第三列：所需权限键（'' = 所有管理员可见，'__super__' = 仅超管，数组 = 持有任一即可）v3.9 / v5.7.1
  const ADMIN_TABS = [
    ['review', '注册审核', 'approve'],
    // v5.7.1(H2)：用户管理不再对所有管理员开放——持有任一相关权限才可见
    ['users', '用户管理', ['member_view', 'approve', 'ban', 'role_manage', 'rename', 'mute', 'reset_pwd', 'delete_user', 'multi', 'team', 'lottery', 'duty', 'role_tag']],
    ['stats', '战绩信息', 'stats'],   // v5.11.0：成员各自上传的战绩（管理员只读，可按任意指标排序）
    ['teams', '战队小队', 'team'],
    ['roletags', '职责标签', 'role_tag'],   // v5.3.0：标签库维护 + 分配开关
    ['announcements', '公告管理', 'announce'],
    ['blacklist', '黑名单', 'blacklist'],   // v5.6.0：本队前科档案 + 注册审核预警
    ['customraces', '自建房日程', 'custom_race'],
    ['schedule', '日程活动', 'schedule'],   // v5.6.0：统一日程自定义活动 + 类别显隐
    ['duty', '值班表', 'duty'],   // v5.6.0：房主值班轮换 + 换班记录
    ['rollcall', '集合点名', ['race', 'schedule']],   // v5.36.34：比赛日开单点名，管比赛时间或管日程的人都行
    ['votes', '投票管理', 'vote'],   // v5.6.0：轻量投票（创建/结束/删除）
    ['lottery', '抽奖管理', 'lottery'],   // v5.7.0：奖池/奖品/机会发放/中奖核销
    ['bot', 'QQ机器人', 'bot'],   // v5.8.0：群绑定（验证码回填）/ 解绑 / 成员绑定名单
    ['aibot', 'AI 聊天', 'bot'],  // v5.36.14：平台/自有模型、冷却与主动发言
    ['battles', '约战管理', 'battle'],   // v5.4.0：跨战队约战（Hub 与连接器模式自动切换）
    ['tournament', '赛事中心', 'tournament'],   // v5.5.0：以本队名义报名赛事（经主站代理）
    ['apikeys', 'API 密钥', '__super__'],   // v5.4.0：本队密钥 + 约战中心连接配置
    ['races', '撞车时间', 'race'],
    ['images', '图片审核', 'image_review'],
    ['ipban', 'IP封禁', '__super__'],   // v5.15.0：blocked_ips 全平台一张表，封/解/看一律只认真超管
    ['multi', '多账户检测', 'multi'],   // v3.5：按注册 IP 查小号
    ['poster', '登录页配图', '__super__'],   // v5.12.0：仅站长/超管可换本队登录页海报
    ['brand', '站名显示', '__super__'],   // v5.30.1：左上角站名要不要自动补「战队」两个字
    ['support', '平台协作', '__super__'],   // v5.36.13：队长授权平台超管进入管理中心协助

    ['logs', '操作日志', '__super__'],      // v5.7.1(H2)：操作日志仅超管可见
    ['loginlog', '登录日志', 'login_log'],   // v5.17.0：管理员登录与新归属地提醒（队长恒可见，可授权副管理）
    ['dm', '私聊开关', 'chat_report'],   // v5.30.1：治理整体搬去平台侧，战队这边只剩「本站开不开私聊」
  ['showcase', '对外展示页', 'showcase'],   // v5.20.0：编辑本站对外主页 + 处理访客投稿/入队意向（队长恒可见，可授权副管理）
    // v5.34.0：一键导出表格。这一格里列的是「四张表里任何一张的权限」——一张都看不了的人不该看到这个页签，
    // 具体哪几张能点由于服务器那份 can 决定，两处不各算一遍
    ['export', '导出表格', ['member_view', 'approve', 'ban', 'role_manage', 'rename', 'mute', 'reset_pwd',
      'delete_user', 'multi', 'team', 'lottery', 'duty', 'role_tag', 'stats', 'training', 'battle', 'schedule']],
    // v5.35.0：社区论坛对接。数据要出站，所以与「API 密钥」「IP封禁」同一档——只给站长本人，不分配给副管
    ['forumapi', '论坛对接', '__super__'],
    // v5.36.0：端手游分离。改的是全队可见范围，与「API 密钥」「论坛对接」同一档——只给队长本人
    ['gameside', '端手游分离', '__super__'],
    ['storage', '存储空间', '__super__'],  // v5.1.0：配额查看 + 清理（地图轮换已收归入驻平台统一管理）
    ['perms', '管理员权限分配', '__super__'],  // v3.9：超管多选分配权限
  ];

  // 私聊治理（v5.19.0，权限键 chat_report）：
  // 会话列表只有条数与时间；正文必须顺着一条举报展开，而且每展开一次都记一行本队操作日志。
  // 私聊开关（v5.19.0 建的是「私聊治理」，v5.30.1 治理整体搬去平台侧，战队这边只剩这一个开关）
  // 会话条数、举报列表、正文展开这些都在 PlatformChat 里，队长这一侧一个字都读不到正文。
  function AdminDm() {
    const [on, setOn] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = async function () {
      try {
        const j = await api('/api/chat/dm/candidates');
        if (j.code === 0) setOn(j.data.dm_enabled === 1);
      } catch (e) { /* 读不到就不显示开关 */ }
    };
    useEffect(function () { load(); }, []);
    const save = async function (next) {
      setBusy(true);
      try {
        const j = await api('/api/admin/chat/dm-toggle', { method: 'POST', body: { enabled: next ? 1 : 0 } });
        if (j.code === 0) { setOn(next); toast(j.msg, 'success'); } else toast(j.msg, 'error');
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };
    if (on === null) return <Paper sx={{ p: 3 }}><LoadingBox /></Paper>;
    return (
      <Paper variant="outlined" sx={{ p: 2.5 }}>
        <FormControlLabel
          control={<Switch checked={on} disabled={busy} onChange={(e) => save(e.target.checked)} />}
          label="本站开放私聊" />
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
          关掉后本队成员之间、以及别队的人发私信进来都发不出去（已有的对话仍能看到）。
          每个人自己在「个人中心」里的两个开关是另一层，这里关掉是整体关闭。
        </Typography>
        <Alert severity="info" sx={{ mt: 1.5 }}>
          私聊的举报与禁言已改由平台超级管理员处理：成员在对话里点「举报」即可，战队侧看不到任何正文。
        </Alert>
      </Paper>
    );
  }

  // 站名显示（v5.30.1）：左上角那个名字要不要自动补「战队」两个字，队长说了算，默认补
  function AdminBrand() {
    const [on, setOn] = useState(null);
    const [busy, setBusy] = useState(false);
    useEffect(function () {
      (async function () {
        try {
          const j = await api('/api/auth/me');
          if (j.code === 0 && j.data && typeof j.data.team_name_suffix !== 'undefined') {
            setOn(j.data.team_name_suffix === 1);
          }
        } catch (e) { /* 静默 */ }
      })();
    }, []);
    const save = async function (next) {
      setBusy(true);
      try {
        const j = await api('/api/admin/brand-suffix', { method: 'POST', body: { suffix: next ? 1 : 0 } });
        if (j.code === 0) { setOn(next); toast(j.msg, 'success'); } else toast(j.msg, 'error');
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };
    if (on === null) return <Paper sx={{ p: 3 }}><LoadingBox /></Paper>;
    return (
      <Paper variant="outlined" sx={{ p: 2.5 }}>
        <FormControlLabel
          control={<Switch checked={on} disabled={busy} onChange={(e) => save(e.target.checked)} />}
          label="站名后面自动补「战队」两个字" />
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
          开启时：队名里没有「战队」二字就自动补上，纯显示层补齐，库里登记的真实队名不动。
          关掉后：左上角就按登记的原名显示。对外展示页与浏览器标题不受这里影响。
        </Typography>
      </Paper>
    );
  }
  // 展示页九块模块（与后端 Showcase::MODULES 同一份键；v5.30.1 合并时这段被误删，
  // 表现是点开「对外展示页」整页白屏，控制台报 SHOW_MODULES is not defined）
  const SHOW_MODULES = [
    ['stats', '战绩数据条', '成员数、约战胜负与成员上传胜率均值，全部自动取数，不用手填'],
    ['gallery', '图集', '队长上传的战队截图，访客可点开大图'],
    ['honor', '荣誉墙', '奖杯卡片：标题 + 说明 + 配图'],
    ['recruit', '招募岗位', '岗位名 + 要求 + 名额'],
    ['members', '成员风采', '只显示"本人也同意公开"的成员，且必须有昵称'],
    ['notices', '战队动态', '从公告里挑几条对外展示'],
    ['contents', '精选内容', '改枪码 / 攻略 / 已采纳的投稿'],
    ['post', '接受内容投稿', '访客可投稿，你采纳后才上墙'],
    ['intent', '接受入队意向', '访客留 QQ + 游戏昵称 + 意向岗位，你在收件箱里处理'],
  ];

  function AdminShowcase({ tenantName }) {
    const [tab, setTab] = useState('page');
    const [data, setData] = useState(null);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/admin/showcase');
        if (j.code === 0) setData(j.data); else toast(j.msg || '读取失败', 'error');
      } catch (e) { toast('读取失败', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    if (!data) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    return (
      <Box>
        {data.platform.force_off === 1 && (
          <Alert severity="warning" sx={{ mb: 2 }}>
            平台已下架本站对外展示页：这里无论怎么设置，访客都打不开。需要恢复请联系平台超管。
          </Alert>
        )}
        {data.site_status !== undefined && data.site_status !== 'approved' && (
          <Alert severity="info" sx={{ mb: 2 }}>站点当前处于「{data.site_status}」状态，停用期间展示页同样不对外开放。</Alert>
        )}
        <Tabs value={tab} onChange={(e, v) => setTab(v)} variant="scrollable"
          sx={{ borderBottom: 1, borderColor: 'divider', mb: 2 }}>
          <Tab value="page" label="页面" />
          <Tab value="gallery" label={'图集（' + data.items.gallery.length + '）'} />
          <Tab value="honor" label={'荣誉墙（' + data.items.honor.length + '）'} />
          <Tab value="post" label={'内容投稿' + (data.pending.post > 0 ? '（' + data.pending.post + ' 待处理）' : '')} />
          <Tab value="intent" label={'入队意向' + (data.pending.intent > 0 ? '（' + data.pending.intent + ' 待处理）' : '')} />
        </Tabs>
        {tab === 'page' && <ShowcaseEditor data={data} reload={load} teamName={tenantName || ''} />}
        {(tab === 'gallery' || tab === 'honor') && (
          <ShowcaseItems kind={tab} items={data.items[tab]} reload={load} />
        )}
        {(tab === 'post' || tab === 'intent') && <ShowcaseSubs type={tab} />}
      </Box>
    );
  }

  // ---------- v5.36.34：队徽与首屏背景图站内生成 ----------
  // 没有中文字体文件可用（服务端 imagettftext 这条路走不通），所以字由浏览器画：
  // 用 canvas 2D 直接铺底色、加暗、写字，再 toBlob 出 PNG 走现有的展示页上传接口。
  // 好处是不新建表、不新建接口、不占新的图片口径——配额、按引用删除那一套全都复用。
  const SHOW_SIZES = { logo: [512, 512], banner: [1280, 420] };
  const SHOW_PATTERNS = [['grad', '纯色渐变'], ['stripes', '斜纹'], ['grid', '网格'], ['glow', '光晕']];

  function hexRgb(h) {
    const s = String(h || '').replace('#', '');
    const v = s.length === 3 ? s.split('').map(function (c) { return c + c; }).join('') : s;
    if (!/^[0-9a-fA-F]{6}$/.test(v)) return [13, 148, 136];
    return [parseInt(v.slice(0, 2), 16), parseInt(v.slice(2, 4), 16), parseInt(v.slice(4, 6), 16)];
  }
  // t<0 往黑里走，t>0 往白里走
  function shadeHex(h, t) {
    const c = hexRgb(h).map(function (x) {
      const to = t < 0 ? 0 : 255;
      return Math.round(Math.max(0, Math.min(255, x + (to - x) * Math.abs(t))));
    });
    return 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')';
  }
  function lumOf(h) {
    const f = hexRgb(h).map(function (x) {
      const s = x / 255;
      return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
  }
  // 按实际铺出来的底色（渐变中段）算对比度，只保证字读得清，不猜色名
  function showTextColor(accent) {
    const mid = hexRgb(accent).map(function (x) { return Math.round(x * 0.62); });
    const L = 0.2126 * Math.pow(mid[0] / 255, 2.2) + 0.7152 * Math.pow(mid[1] / 255, 2.2) + 0.0722 * Math.pow(mid[2] / 255, 2.2);
    return L > 0.42 ? '#141414' : '#ffffff';
  }
  function fitFont(ctx, text, px, maxW, weight) {
    let p = px;
    ctx.font = weight + ' ' + p + 'px "PingFang SC","Microsoft YaHei","Noto Sans CJK SC",system-ui,sans-serif';
    while (p > 10 && ctx.measureText(text).width > maxW) {
      p -= Math.max(1, Math.round(p * 0.06));
      ctx.font = weight + ' ' + p + 'px "PingFang SC","Microsoft YaHei","Noto Sans CJK SC",system-ui,sans-serif';
    }
    return p;
  }
  function drawShowArt(ctx, kind, o) {
    const W = ctx.canvas.width, H = ctx.canvas.height;
    const acc = o.accent || '#0f766e';
    const txt = showTextColor(acc);
    const g = ctx.createLinearGradient(0, 0, W, H);
    g.addColorStop(0, shadeHex(acc, 0.1));
    g.addColorStop(1, shadeHex(acc, -0.5));
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);

    ctx.save();
    ctx.strokeStyle = 'rgba(255,255,255,.10)';
    ctx.lineWidth = Math.round(W / 64);
    if (o.pattern === 'stripes') {
      for (let i = -H; i < W + H; i += Math.round(W / 12)) {
        ctx.beginPath(); ctx.moveTo(i, 0); ctx.lineTo(i + H, H); ctx.stroke();
      }
    } else if (o.pattern === 'grid') {
      const step = Math.round(W / 16);
      for (let x = 0; x <= W; x += step) { ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke(); }
      ctx.strokeStyle = 'rgba(255,255,255,.06)';
      for (let y = 0; y <= H; y += step) { ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke(); }
    } else if (o.pattern === 'glow') {
      const rg = ctx.createRadialGradient(W / 2, H * 0.42, Math.min(W, H) * 0.08, W / 2, H * 0.42, Math.max(W, H) * 0.7);
      rg.addColorStop(0, 'rgba(255,255,255,.30)');
      rg.addColorStop(1, 'rgba(255,255,255,0)');
      ctx.fillStyle = rg; ctx.fillRect(0, 0, W, H);
    }
    ctx.restore();

    const name = String(o.text || '').trim();
    if (kind === 'logo') {
      // 六边形徽章框 + 首字缩写（两个汉字或两个字母），下面压一行小字队名
      const cx = W / 2, cy = H * 0.45, R = Math.min(W, H) * 0.33;
      ctx.beginPath();
      for (let i = 0; i < 6; i++) {
        const a = -Math.PI / 2 + i * Math.PI / 3;
        const x = cx + R * Math.cos(a), y = cy + R * Math.sin(a);
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      }
      ctx.closePath();
      ctx.fillStyle = 'rgba(0,0,0,.22)'; ctx.fill();
      ctx.lineWidth = Math.round(W / 46); ctx.strokeStyle = txt; ctx.globalAlpha = 0.85; ctx.stroke(); ctx.globalAlpha = 1;
      const mono = Array.from(name).slice(0, 2).join('');
      ctx.fillStyle = txt; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      fitFont(ctx, mono || '队', R * 0.82, R * 1.5, 700);
      ctx.fillText(mono || '队', cx, cy + R * 0.02);
      if (Array.from(name).length > 2) {
        ctx.globalAlpha = 0.9;
        fitFont(ctx, name, W * 0.075, W * 0.86, 600);
        ctx.fillText(name, cx, H * 0.87);
        ctx.globalAlpha = 1;
      }
    } else {
      // 背景图：左侧一条队色短杠 + 队名大字，下面一行口号
      ctx.fillStyle = txt;
      ctx.fillRect(W * 0.07, H * 0.3, Math.round(W * 0.012), Math.round(H * 0.4));
      ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
      const lx = W * 0.105;
      const np = fitFont(ctx, name || '战队', H * 0.3, W * 0.78, 700);
      ctx.fillText(name || '战队', lx, H * 0.42);
      const sub = String(o.sub || '').trim();
      if (sub !== '') {
        ctx.globalAlpha = 0.82;
        fitFont(ctx, sub, np * 0.34, W * 0.72, 500);
        ctx.fillText(sub, lx, H * 0.42 + np * 0.62);
        ctx.globalAlpha = 1;
      }
    }
  }

  function ShowcaseBadgeMaker({ kind, accent, teamName, slogan, onPick, onClose }) {
    const isLogo = kind === 'logo';
    const SIZE = SHOW_SIZES[kind] || SHOW_SIZES.logo;
    const [text, setText] = useState(teamName || '');
    const [sub, setSub] = useState(isLogo ? '' : (slogan || ''));
    const [color, setColor] = useState(accent || '#0066cc');
    const [pattern, setPattern] = useState(isLogo ? 'glow' : 'stripes');
    const [busy, setBusy] = useState(false);
    const cv = useRef(null);

    const paint = function (c) {
      if (!c) return;
      c.width = SIZE[0]; c.height = SIZE[1];
      drawShowArt(c.getContext('2d'), kind, { text: text, sub: sub, accent: color, pattern: pattern });
    };
    // 回调式 ref：MUI 的 Dialog 把内容挂在 portal 里，父组件那次 effect 跑的时候画布还不存在。
    // ref 是画布真正挂载那一刻被调用的，所以第一帧就一定有图；之后每次改字改色重画。
    const attach = function (el) { cv.current = el; paint(el); };
    useEffect(function () { paint(cv.current); }, [text, sub, color, pattern, kind]);

    const use = async function () {
      if (String(text).trim() === '') { toast('先写上要放的队名', 'warning'); return; }
      setBusy(true);
      try {
        const blob = await new Promise(function (res) { cv.current.toBlob(res, 'image/png'); });
        if (!blob) { toast('这张画不出来，换一个版式再试', 'error'); return; }
        const fd = new FormData();
        fd.append('image', blob, (isLogo ? 'logo.png' : 'banner.png'));
        const j = await api('/api/admin/showcase/image', { method: 'POST', form: fd });
        if (j.code === 0) { onPick(j.data.path); toast(isLogo ? '队徽已经换上了，记得点保存' : '背景图已经换上了，记得点保存', 'success'); }
        else toast(j.msg || '上传失败', 'error');
      } catch (e) { toast('上传失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Dialog open onClose={onClose} fullWidth maxWidth={isLogo ? 'xs' : 'sm'}>
        <DialogTitle>{isLogo ? '生成队徽' : '生成首屏背景图'}</DialogTitle>
        <DialogContent>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            字是浏览器里画的（{SIZE[0]}×{SIZE[1]} PNG），不占用你电脑上的图片文件；生成后仍然走展示页那一条上传与配额，
            删掉这张图时文件也会跟着清。
          </Typography>
          <Box sx={{ width: '100%', borderRadius: '10px', overflow: 'hidden', border: '1px solid ' + CARD_BORDER, mb: 2 }}>
            <canvas ref={attach} style={{ display: 'block', width: '100%', height: 'auto' }} />
          </Box>
          <Stack spacing={1.5}>
            <TextField label={isLogo ? '队名（取头两个字做徽章里的缩写）' : '队名'} fullWidth value={text}
              onChange={(e) => setText(e.target.value)} inputProps={{ maxLength: 24 }} />
            {!isLogo && (
              <TextField label="下面那行小字（口号，选填）" fullWidth value={sub}
                onChange={(e) => setSub(e.target.value)} inputProps={{ maxLength: 40 }} />
            )}
            <Box>
              <Typography variant="caption" color="text.secondary">底色（用你的队色，或从下面挑）</Typography>
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, mt: 0.75, alignItems: 'center' }}>
                {THEME_PRESETS.map(function (p) {
                  const on = color === p[0];
                  return (
                    <Box key={p[0]} onClick={() => setColor(p[0])} title={p[1]} sx={{
                      width: 26, height: 26, borderRadius: '7px', bgcolor: p[0], cursor: 'pointer', flexShrink: 0,
                      border: '1px solid rgba(0,0,0,.14)',
                      boxShadow: on ? '0 0 0 2px #fff inset, 0 0 0 2px ' + p[0] : 'none',
                    }} />
                  );
                })}
                <TextField size="small" label="#rrggbb" value={color} sx={{ width: 110 }}
                  onChange={(e) => { const v = e.target.value.trim(); if (/^#?[0-9a-fA-F]{6}$/.test(v)) setColor(v[0] === '#' ? v : '#' + v); }} />
              </Box>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary">版式</Typography>
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, mt: 0.75 }}>
                {SHOW_PATTERNS.map(function (p) {
                  return (
                    <Chip key={p[0]} size="small" label={p[1]} clickable color={pattern === p[0] ? 'primary' : 'default'}
                      variant={pattern === p[0] ? 'filled' : 'outlined'} onClick={() => setPattern(p[0])} />
                  );
                })}
              </Box>
            </Box>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={use}>{busy ? '生成中…' : (isLogo ? '用这张当队徽' : '用这张当背景')}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  function ShowcasePickImage({ onPick, label }) {
    const ref = useRef(null);
    const [busy, setBusy] = useState(false);
    return (
      <Box>
        <input ref={ref} type="file" accept="image/jpeg,image/png,image/gif,image/webp" style={{ display: 'none' }}
          onChange={async function (ev) {
            const f = ev.target.files && ev.target.files[0];
            ev.target.value = '';
            if (!f) return;
            setBusy(true);
            try {
              const fd = new FormData();
              fd.append('image', f);
              const j = await api('/api/admin/showcase/image', { method: 'POST', form: fd });
              if (j.code === 0) onPick(j.data.path); else toast(j.msg || '上传失败', 'error');
            } catch (e) { toast('上传失败', 'error'); } finally { setBusy(false); }
          }} />
        <Button size="small" onClick={() => ref.current && ref.current.click()} disabled={busy}>
          {busy ? '上传中…' : label}
        </Button>
      </Box>
    );
  }

  function ShowcaseEditor({ data, reload, teamName }) {
    const c = data.config;
    const [enabled, setEnabled] = useState(c.enabled === 1);
    const [slogan, setSlogan] = useState(c.slogan || '');
    const [maker, setMaker] = useState('');   // v5.36.34：'' | 'logo' | 'banner'（站内生成那张图）
    const [contact, setContact] = useState(c.contact || '');
    const [recruiting, setRecruiting] = useState(c.recruiting === 1);
    const [accent, setAccent] = useState(c.accent || '');
    const [mods, setMods] = useState(Object.assign({}, c.modules));
    const [blocks, setBlocks] = useState(c.blocks.map(function (b) { return { key: b.key, name: b.name, side: b.side }; }));
    const [positions, setPositions] = useState((c.positions || []).map(function (p) { return Object.assign({}, p); }));
    const [noticeIds, setNoticeIds] = useState(c.notice_ids || []);
    const [gunIds, setGunIds] = useState(c.guncode_ids || []);
    const [guideIds, setGuideIds] = useState(c.guide_ids || []);
    const [logo, setLogo] = useState(c.logo || '');
    const [banner, setBanner] = useState(c.banner || '');
    const [busy, setBusy] = useState(false);
    const dirty = useRef(false);

    const toggle = function (arr, setArr, id, max) {
      const has = arr.indexOf(id) >= 0;
      if (!has && arr.length >= max) { toast('这一类最多勾 ' + max + ' 条', 'error'); return; }
      setArr(has ? arr.filter(function (x) { return x !== id; }) : arr.concat([id]));
      dirty.current = true;
    };
    // 一律用函数式更新：连着点两下时两次 handler 会拿到同一份旧快照，直接 set 会把前一次盖掉
    const reorder = function (i, dir) {
      setBlocks(function (prev) {
        const nx = prev.slice();
        const j = i + dir;
        if (j < 0 || j >= nx.length) return prev;
        const t = nx[i]; nx[i] = nx[j]; nx[j] = t;
        return nx;
      });
      dirty.current = true;
    };
    const flipLane = function (i) {
      setBlocks(function (prev) {
        const nx = prev.slice();
        nx[i] = Object.assign({}, nx[i], { side: nx[i].side === 1 ? 0 : 1 });
        return nx;
      });
      dirty.current = true;
    };

    const save = async function () {
      const body = {
        enabled: enabled ? 1 : 0,
        slogan: slogan,
        contact: contact,
        recruiting: recruiting ? 1 : 0,
        accent: accent,
        block_order: blocks.map(function (b) { return b.key + ':' + b.side; }).join(','),
        positions: JSON.stringify(positions.filter(function (p) { return (p.name || '').trim() !== ''; })),
        notice_ids: noticeIds.join(','),
        guncode_ids: gunIds.join(','),
        guide_ids: guideIds.join(','),
        // 空值一律发 '-'：后端把 '-' 当作"清掉这张图"，不发键才是"保持原样"
        logo: logo === '' ? '-' : logo,
        banner: banner === '' ? '-' : banner,
      };
      SHOW_MODULES.forEach(function (m) { body['module_' + m[0]] = mods[m[0]] === 1 ? 1 : 0; });
      setBusy(true);
      try {
        const j = await api('/api/admin/showcase/save', { method: 'POST', body: body });
        if (j.code === 0) { toast(j.msg || '已保存', 'success'); dirty.current = false; reload(); }
        else toast(j.msg || '保存失败', 'error');
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    useEffect(function () {
      const warn = function (ev) {
        if (!dirty.current) return undefined;
        ev.preventDefault();
        ev.returnValue = '';
        return '';
      };
      window.addEventListener('beforeunload', warn);
      return function () { window.removeEventListener('beforeunload', warn); };
    }, []);

    const st = data.stats || {};
    return (
      <Stack spacing={2}>
        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <FormControlLabel
            control={<Switch checked={enabled} onChange={(e) => { setEnabled(e.target.checked); dirty.current = true; }} />}
            label={`开启对外展示页（${window.SITE_BASE}/show，访客无需登录即可访问）`} />
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
            关掉之后这一页对互联网直接 404，搜索引擎也会在下一次抓取时撤掉它。平台如果下架了本站展示页，这里开着也不会生效。
          </Typography>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ mt: 1.5 }}>
            <Button size="small" variant="outlined" onClick={() => window.open(data.url, '_blank')}>预览本页</Button>
            <Chip size="small" label={'在册成员 ' + (st.members || 0)} />
            <Chip size="small" label={'约战 ' + (st.wins || 0) + ' 胜 ' + (st.losses || 0) + ' 负'} />
            <Chip size="small" label={st.win_rate === null || st.win_rate === undefined ? '战绩均值：暂无上传样本' : ('成员上传胜率均值 ' + st.win_rate + '%（' + st.win_rate_sample + ' 人）')} />
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography fontWeight={700} gutterBottom>基本信息</Typography>
          <Stack spacing={1.5}>
            <TextField label="一句话口号" value={slogan} inputProps={{ maxLength: 40 }}
              onChange={(e) => { setSlogan(e.target.value); dirty.current = true; }} fullWidth
              helperText="留空则用入驻时填的战队简介" />
            <TextField label="对外联系方式（选填：QQ 群号 / 频道链接）" value={contact} inputProps={{ maxLength: 60 }}
              onChange={(e) => { setContact(e.target.value); dirty.current = true; }} fullWidth
              helperText="这里写的内容任何访客都能看到，不要填成员的个人 QQ" />
            <FormControlLabel
              control={<Switch checked={recruiting} onChange={(e) => { setRecruiting(e.target.checked); dirty.current = true; }} />}
              label="正在招募（大厅卡片上的「招募中」亮灯）" />
            <Stack direction="row" spacing={2} alignItems="center" flexWrap="wrap">
              <Box>
                <Typography variant="body2">队徽</Typography>
                {logo !== '' && <img src={BASE + logo} alt="队徽" style={{ width: 56, height: 56, objectFit: 'cover', borderRadius: 10 }} />}
                <Stack direction="row" spacing={1}>
                  <ShowcasePickImage label="换队徽" onPick={(p) => { setLogo(p); dirty.current = true; }} />
                  <Button size="small" variant="outlined" onClick={() => setMaker('logo')}>没有图？站内生成</Button>
                  <Button size="small" color="error" onClick={() => { setLogo(''); dirty.current = true; }}>清除</Button>
                </Stack>
              </Box>
              <Box>
                <Typography variant="body2">首屏背景图</Typography>
                {banner !== '' && <img src={BASE + banner} alt="Banner" style={{ width: 168, height: 62, objectFit: 'cover', borderRadius: 10 }} />}
                <Stack direction="row" spacing={1}>
                  <ShowcasePickImage label="换背景图" onPick={(p) => { setBanner(p); dirty.current = true; }} />
                  <Button size="small" variant="outlined" onClick={() => setMaker('banner')}>没有图？站内生成</Button>
                  <Button size="small" color="error" onClick={() => { setBanner(''); dirty.current = true; }}>清除</Button>
                </Stack>
              </Box>
            </Stack>
          </Stack>
          {maker && (
            <ShowcaseBadgeMaker kind={maker} accent={accent} teamName={teamName} slogan={slogan}
              onClose={() => setMaker('')}
              onPick={function (p) {
                if (maker === 'logo') setLogo(p); else setBanner(p);
                dirty.current = true;
                setMaker('');
              }} />
          )}
        </Paper>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography fontWeight={700} gutterBottom>战队队色</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.2 }}>
            主页上的按钮、队徽底、标题短杠都会跟着这颗色走。服务端按对比度挑能读的深浅档，选到浅色系也不会把字吃掉；再点一次已选中的色＝清除，回到站内默认青绿。
          </Typography>
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, alignItems: 'center' }}>
            {THEME_PRESETS.map(function (p) {
              const active = accent === p[0];
              return (
                <Tooltip key={p[0]} title={p[1]}>
                  <Box onClick={() => { setAccent(active ? '' : p[0]); dirty.current = true; }} sx={{
                    width: 30, height: 30, borderRadius: '8px', bgcolor: p[0], cursor: 'pointer',
                    border: '1px solid rgba(0,0,0,.14)',
                    boxShadow: active ? '0 0 0 2px #fff inset, 0 0 0 2px ' + p[0] : 'none',
                    transform: active ? 'scale(1.12)' : 'none', transition: 'transform .12s',
                    '&:hover': { transform: 'scale(1.16)' },
                  }} />
                </Tooltip>
              );
            })}
            <Button size="small" variant="outlined" sx={{ ml: 0.5 }}
              onClick={() => { setAccent(''); dirty.current = true; }}>
              {accent === '' ? '当前：默认青绿' : '清除，回到默认青绿'}
            </Button>
          </Box>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography fontWeight={700} gutterBottom>页面上放哪些块</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
            开了开关还得有内容：一条岗位都没挂、没荣誉、没人同意公开，那一块在访客页上就整块不出现，不会留一句「还没有…」。
          </Typography>
          <Stack spacing={0.5}>
            {SHOW_MODULES.map(function (m) {
              return (
                <FormControlLabel key={m[0]}
                  control={<Switch checked={mods[m[0]] === 1}
                    onChange={(e) => {
                      const nx = Object.assign({}, mods);
                      nx[m[0]] = e.target.checked ? 1 : 0;
                      setMods(nx); dirty.current = true;
                    }} />}
                  label={<Box sx={{ display: 'flex', flexDirection: 'column' }}>
                    <span>{m[1]}</span>
                    <Typography variant="caption" color="text.secondary">{m[2]}</Typography>
                  </Box>} />
              );
            })}
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography fontWeight={700} gutterBottom>块顺序与分栏</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.25 }}>
            ↑↓ 是排先后，「左栏 / 右栏」决定它在电脑上跟哪一列排（右栏是那条 300px 的窄栏，放荣誉、成员这类短块刚好）。
            手机上两栏会摊成一条，看到的就是下面这条顺序。首屏的战绩数据条和页尾的「联系战队」位置固定，不参与排序；
            已经关掉的块在这里还留着——等它哪天有了内容，就按这个位置出现。
          </Typography>
          {blocks.map(function (b, i) {
            const off = mods[b.key] !== 1;
            return (
              <Stack key={b.key} direction="row" spacing={1} alignItems="center" sx={{ mb: 0.75 }} flexWrap="wrap">
                <Typography variant="caption" color="text.secondary" sx={{ width: 20, textAlign: 'center' }}>{i + 1}</Typography>
                <Typography variant="body2" sx={{ minWidth: 88, fontWeight: 600 }}>{b.name}</Typography>
                {off && <Chip size="small" label="已关掉" />}
                <Box sx={{ flexGrow: 1 }} />
                <Button size="small" variant="outlined" sx={{ minWidth: 0, px: 1.1 }} disabled={i === 0} onClick={() => reorder(i, -1)}>↑</Button>
                <Button size="small" variant="outlined" sx={{ minWidth: 0, px: 1.1 }} disabled={i === blocks.length - 1} onClick={() => reorder(i, 1)}>↓</Button>
                <Button size="small" variant={b.side === 1 ? 'contained' : 'outlined'} sx={{ minWidth: 0, px: 1.2 }} onClick={() => flipLane(i)}>
                  {b.side === 1 ? '右栏' : '左栏'}
                </Button>
              </Stack>
            );
          })}
          <Button size="small" variant="text" sx={{ mt: 0.5 }}
            onClick={() => { setBlocks(c.blocks.map(function (b) { return { key: b.key, name: b.name, side: b.side }; })); dirty.current = true; }}>
            撤回为已保存的排法
          </Button>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography fontWeight={700}>招募岗位</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
            最多 8 个岗位。入队意向的「意向岗位」下拉框就是从这里来的，一个都不填时访客只能选「其他」。
          </Typography>
          {positions.map(function (p, i) {
            return (
              <Stack key={i} direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }} flexWrap="wrap">
                <TextField size="small" label="岗位名" value={p.name} inputProps={{ maxLength: 20 }}
                  onChange={(e) => { const nx = positions.slice(); nx[i].name = e.target.value; setPositions(nx); dirty.current = true; }} />
                <TextField size="small" label="要求" value={p.require} inputProps={{ maxLength: 60 }} sx={{ flexGrow: 1, minWidth: 180 }}
                  onChange={(e) => { const nx = positions.slice(); nx[i].require = e.target.value; setPositions(nx); dirty.current = true; }} />
                <TextField size="small" label="名额" type="number" value={p.seats} inputProps={{ min: 0, max: 99 }} sx={{ width: 92 }}
                  onChange={(e) => { const nx = positions.slice(); nx[i].seats = parseInt(e.target.value || '0', 10); setPositions(nx); dirty.current = true; }} />
                <Button size="small" color="error" onClick={() => { setPositions(positions.filter(function (x, xi) { return xi !== i; })); dirty.current = true; }}>删</Button>
              </Stack>
            );
          })}
          <Button size="small" disabled={positions.length >= 8}
            onClick={() => { setPositions(positions.concat([{ name: '', require: '', seats: 0 }])); dirty.current = true; }}>
            加一个岗位
          </Button>
        </Paper>

        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
          <Paper variant="outlined" sx={{ p: 2.5, flex: 1 }}>
            <Typography fontWeight={700} gutterBottom>对外动态（挑公告）</Typography>
            {data.notices.length === 0 && <Typography variant="caption" color="text.secondary">还没有公告。</Typography>}
            <Stack spacing={0.25}>
              {data.notices.map(function (n) {
                return <FormControlLabel key={n.id} control={<Checkbox size="small" checked={noticeIds.indexOf(n.id) >= 0}
                  onChange={() => toggle(noticeIds, setNoticeIds, n.id, 6)} />}
                  label={<Typography variant="body2">{n.title}
                    {/* v5.36.34：定时未到点的也能先勾上，到点自己上对外页；已撤回的不会出现在这份名单里 */}
                    {n.not_live_yet ? <Box component="span" sx={{ color: 'text.secondary', fontSize: 12 }}>（还没到发布时间）</Box> : null}
                  </Typography>} />;
              })}
            </Stack>
          </Paper>
          <Paper variant="outlined" sx={{ p: 2.5, flex: 1 }}>
            <Typography fontWeight={700} gutterBottom>精选内容（改枪码 / 攻略）</Typography>
            <Stack spacing={0.25}>
              {data.guncodes.map(function (g) {
                return <FormControlLabel key={'g' + g.id} control={<Checkbox size="small" checked={gunIds.indexOf(g.id) >= 0}
                  onChange={() => toggle(gunIds, setGunIds, g.id, 6)} />}
                  label={<Typography variant="body2">改枪码：{g.title}</Typography>} />;
              })}
              {data.guides.map(function (g) {
                return <FormControlLabel key={'d' + g.id} control={<Checkbox size="small" checked={guideIds.indexOf(g.id) >= 0}
                  onChange={() => toggle(guideIds, setGuideIds, g.id, 6)} />}
                  label={<Typography variant="body2">攻略：{g.title}</Typography>} />;
              })}
            </Stack>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
              只列当前仍然有效的内容；作者之后下架的，展示页会自动不再挂它。已采纳的访客投稿也会出现在这一块。
            </Typography>
          </Paper>
        </Stack>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography fontWeight={700} gutterBottom>成员风采</Typography>
          <Typography variant="body2" color="text.secondary">
            这一层由成员自己决定：勾了「愿意出现在战队主页」的人才会公开（且必须有昵称）。当前 {data.members.length} 人同意公开。
          </Typography>
          <Stack direction="row" spacing={1} flexWrap="wrap" sx={{ mt: 1 }}>
            {data.members.map(function (m) {
              return <Chip key={m.id} size="small" label={m.name + (m.tag ? ' · ' + m.tag : '')} />;
            })}
            {data.members.length === 0 && <Typography variant="caption" color="text.secondary">还没有人同意公开。</Typography>}
          </Stack>
        </Paper>

        <Box sx={{ position: 'sticky', bottom: 8, alignSelf: 'flex-start' }}>
          <Button variant="contained" onClick={save} disabled={busy}>{busy ? '保存中…' : '保存展示页设置'}</Button>
        </Box>
      </Stack>
    );
  }

  function ShowcaseItems({ kind, items, reload }) {
    const [title, setTitle] = useState('');
    const [note, setNote] = useState('');
    const [image, setImage] = useState('');
    const [busy, setBusy] = useState(false);
    const word = kind === 'honor' ? '荣誉' : '图集';

    const post = async function (url, body, msg) {
      setBusy(true);
      try {
        const j = await api(url, { method: 'POST', body: body });
        if (j.code === 0) { toast(j.msg || msg, 'success'); reload(); return j; }
        toast(j.msg || '操作失败', 'error');
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
      return null;
    };

    return (
      <Stack spacing={2}>
        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography fontWeight={700} gutterBottom>新增{word}条目</Typography>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} alignItems={{ md: 'flex-end' }}>
            <Box>
              <Typography variant="caption" color="text.secondary">图片</Typography>
              <ShowcasePickImage label="上传图片" onPick={setImage} />
              {image !== '' && <img src={BASE + image} alt="" style={{ width: 96, height: 62, objectFit: 'cover', borderRadius: 8, display: 'block', marginTop: 6 }} />}
            </Box>
            <TextField size="small" label={kind === 'honor' ? '荣誉名称' : '标题'} value={title} inputProps={{ maxLength: 64 }}
              onChange={(e) => setTitle(e.target.value)} />
            <TextField size="small" label="说明（选填）" value={note} inputProps={{ maxLength: 200 }} sx={{ flexGrow: 1, minWidth: 180 }}
              onChange={(e) => setNote(e.target.value)} />
            <Button variant="contained" size="small" disabled={busy || image === ''}
              onClick={async function () {
                const j = await post('/api/admin/showcase/item-add', { kind: kind, title: title, note: note, image: image }, '已添加');
                if (j) { setTitle(''); setNote(''); setImage(''); }
              }}>添加</Button>
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            每类最多 24 条。这些图片计入本站存储配额，在「存储空间」里能看到并删除。
          </Typography>
        </Paper>

        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead><TableRow>
              <TableCell>图</TableCell><TableCell>标题</TableCell><TableCell>说明</TableCell>
              <TableCell align="center">状态</TableCell><TableCell align="right">操作</TableCell>
            </TableRow></TableHead>
            <TableBody>
              {items.length === 0 && <TableRow><TableCell colSpan={5}><Typography variant="body2" color="text.secondary">还没有条目。</Typography></TableCell></TableRow>}
              {items.map(function (it, i) {
                return (
                  <TableRow key={it.id}>
                    <TableCell><img src={BASE + it.image} alt="" style={{ width: 72, height: 46, objectFit: 'cover', borderRadius: 6 }} /></TableCell>
                    <TableCell>{it.title}</TableCell>
                    <TableCell><Typography variant="body2" color="text.secondary">{it.note}</Typography></TableCell>
                    <TableCell align="center">{it.visible === 1 ? <Chip size="small" label="显示中" color="success" /> : <Chip size="small" label="已隐藏" />}</TableCell>
                    <TableCell align="right">
                      <Button size="small" disabled={busy || i === 0} onClick={() => post('/api/admin/showcase/item-move', { id: it.id, dir: -1 }, '已上移')}>↑</Button>
                      <Button size="small" disabled={busy || i === items.length - 1} onClick={() => post('/api/admin/showcase/item-move', { id: it.id, dir: 1 }, '已下移')}>↓</Button>
                      <Button size="small" disabled={busy}
                        onClick={() => post('/api/admin/showcase/item-save', { id: it.id, visible: it.visible === 1 ? 0 : 1 }, '已切换')}>
                        {it.visible === 1 ? '隐藏' : '显示'}
                      </Button>
                      <Button size="small" color="error" disabled={busy}
                        onClick={() => { if (window.confirm('删除这条' + word + '？图片文件会一起删掉。')) post('/api/admin/showcase/item-del', { id: it.id }, '已删除'); }}>
                        删除
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

  function ShowcaseSubs({ type }) {
    const isPost = type === 'post';
    const [status, setStatus] = useState('pending');
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [note, setNote] = useState('');
    const [target, setTarget] = useState(null);   // {id, action}
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/admin/showcase/subs?type=' + type + '&status=' + status + '&page=' + page);
        if (j.code === 0) setData(j.data); else toast(j.msg || '读取失败', 'error');
      } catch (e) { toast('读取失败', 'error'); }
    }, [type, status, page]);
    useEffect(function () { load(); }, [load]);

    const review = async function (id, action, memo) {
      setBusy(true);
      try {
        const j = await api('/api/admin/showcase/sub-review', { method: 'POST', body: { id: id, action: action, note: memo } });
        if (j.code === 0) { toast(j.msg || '已处理', 'success'); setTarget(null); setNote(''); load(); }
        else toast(j.msg || '操作失败', 'error');
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    if (!data) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    return (
      <Stack spacing={2}>
        <Alert severity="info">
          {isPost
            ? '内容投稿：采纳后这条会连同作者昵称出现在展示页的「精选内容」里；驳回会顺手删掉投稿附图。'
            : '入队意向：这只是对方留下的联系方式与自我介绍，不等于入队。同意了他仍需在本站注册并通过「注册审核」，这里的状态只表示你看过并联系过。'}
        </Alert>
        <Stack direction="row" spacing={1}>
          {['pending', 'approved', 'rejected'].map(function (st) {
            return <Chip key={st} size="small" label={st === 'pending' ? '待处理 ' + data.count.pending : st === 'approved' ? '已采纳 ' + data.count.approved : '已驳回 ' + data.count.rejected}
              color={status === st ? 'primary' : 'default'} onClick={() => { setStatus(st); setPage(1); }} />;
          })}
        </Stack>
        <TableContainer component={Paper} variant="outlined">
          <Table size="small">
            <TableHead><TableRow>
              <TableCell>时间</TableCell>
              {!isPost && <TableCell>QQ</TableCell>}
              <TableCell>游戏昵称</TableCell>
              {!isPost && <TableCell>意向岗位</TableCell>}
              <TableCell>内容</TableCell>
              <TableCell>来源</TableCell>
              <TableCell align="right">操作</TableCell>
            </TableRow></TableHead>
            <TableBody>
              {data.list.length === 0 && (
                <TableRow><TableCell colSpan={isPost ? 5 : 7}>
                  <Typography variant="body2" color="text.secondary">这个箱子里暂时没有{status === 'pending' ? '待处理' : ''}的{isPost ? '投稿' : '意向'}。</Typography>
                </TableCell></TableRow>
              )}
              {data.list.map(function (r) {
                return (
                  <TableRow key={r.id}>
                    <TableCell><Typography variant="caption">{fmtTime(r.created_at)}</Typography></TableCell>
                    {!isPost && <TableCell>{r.qq}</TableCell>}
                    <TableCell>{r.nick}</TableCell>
                    {!isPost && <TableCell>{r.position}</TableCell>}
                    <TableCell sx={{ maxWidth: 320 }}>
                      <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>{r.content}</Typography>
                      {r.image !== '' && <img src={BASE + r.image} alt="投稿附图" style={{ width: 132, marginTop: 6, borderRadius: 6, cursor: 'zoom-in' }}
                        onClick={() => window.open(BASE + r.image, '_blank')} />}
                      {r.result_note !== '' && <Typography variant="caption" color="text.secondary">处理备注：{r.result_note}</Typography>}
                    </TableCell>
                    <TableCell><Typography variant="caption" color="text.secondary">{r.region || '—'}</Typography></TableCell>
                    <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                      {r.status === 'pending' && (
                        <Button size="small" color="primary" disabled={busy} onClick={() => setTarget({ id: r.id, action: 'approve' })}>采纳</Button>
                      )}
                      {r.status === 'pending' && (
                        <Button size="small" disabled={busy} onClick={() => setTarget({ id: r.id, action: 'reject' })}>驳回</Button>
                      )}
                      <Button size="small" color="error" disabled={busy}
                        onClick={() => {
                          if (!window.confirm('删除这条记录？图片文件会一起删掉，操作日志仍会留下你删除过它的痕迹。')) {
                            (async function () {
                              setBusy(true);
                              try {
                                const j = await api('/api/admin/showcase/sub-del', { method: 'POST', body: { id: r.id } });
                                if (j.code === 0) { toast(j.msg || '已删除', 'success'); load(); } else toast(j.msg || '删除失败', 'error');
                              } catch (e) { toast('删除失败', 'error'); } finally { setBusy(false); }
                            })();
                          }
                        }}>删除</Button>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
        <Stack direction="row" spacing={1} alignItems="center">
          <Button size="small" disabled={page <= 1} onClick={() => setPage(page - 1)}>上一页</Button>
          <Typography variant="body2">第 {page} 页 / 共 {data.total} 条</Typography>
          <Button size="small" disabled={page * data.size >= data.total} onClick={() => setPage(page + 1)}>下一页</Button>
        </Stack>

        <Dialog open={target !== null} onClose={() => setTarget(null)}>
          <DialogTitle>{target && target.action === 'approve' ? (isPost ? '采纳这条投稿' : '标记为已联系') : '驳回这条' + (isPost ? '投稿' : '意向')}</DialogTitle>
          <DialogContent>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
              备注会写进本队操作日志（采纳投稿=把内容推到互联网上，所以逐次留痕）。
            </Typography>
            <TextField multiline minRows={2} fullWidth label="处理备注（选填）" value={note} inputProps={{ maxLength: 200 }}
              onChange={(e) => setNote(e.target.value)} />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setTarget(null)}>取消</Button>
            <Button variant="contained" disabled={busy}
              onClick={() => target && review(target.id, target.action, note)}>确定</Button>
          </DialogActions>
        </Dialog>
      </Stack>
    );
  }

  // v5.30.0：战绩对队友可见。与「出现在对外展示页」是两档——那一档给互联网看，这一档只给本队成员看。
  function StatsPublicCard({ me }) {
    const [on, setOn] = useState(me && typeof me.stats_public !== 'undefined' ? me.stats_public === 1 : null);
    const [busy, setBusy] = useState(false);
    const save = async function (next) {
      setBusy(true);
      try {
        const j = await api('/api/profile/stats-public', { method: 'POST', body: { stats_public: next ? 1 : 0 } });
        if (j.code === 0) { setOn(j.data.stats_public === 1); toast(j.msg, 'success'); }
        else toast(j.msg, 'error');
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };
    if (on === null) return null;
    return (
      <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
        <FormControlLabel
          control={<Switch checked={on} disabled={busy} onChange={(e) => save(e.target.checked)} />}
          label="战绩对队友可见" />
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
          打开后，本队成员点开你的主页能看到你上传的战绩数据；关掉就只有你自己和管理员看得到。
          这一档只对同一战队的人生效，跟「出现在对外展示页」互不影响。
        </Typography>
      </Paper>
    );
  }

  // v5.30.1：个人黑名单。拉黑只影响自己的眼睛——对方不会被告知，别人看到的还是原样。
  function BlockListCard() {
    const [rows, setRows] = useState(null);
    const [max, setMax] = useState(10);
    const [busy, setBusy] = useState(0);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/blocks');
        if (j.code === 0) { setRows(j.data.list || []); setMax(j.data.max || 10); }
      } catch (e) { /* 读不到就不显示这张卡 */ }
    }, []);
    useEffect(function () { load(); }, [load]);
    if (rows === null) return null;
    const unblock = async function (id) {
      setBusy(id);
      try {
        const j = await api('/api/blocks/remove', { method: 'POST', body: { user_id: id } });
        if (j.code === 0) { toast(j.msg, 'success'); load(); }
        else toast(j.msg, 'error');
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(0); }
    };
    return (
      <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
        <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 0.5 }}>我的黑名单</Typography>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
          已拉黑 {rows.length} / {max} 人。只能拉其它战队的人；拉黑之后你发不出、也收不到他的私聊，那段会话从你的列表里消失，
          对方不会被告知，别人看到的也还是原样。想拉黑谁，在 TA 的私聊里或 TA 的主页上点「拉黑」就行。
        </Typography>
        {rows.length === 0
          ? <Typography variant="body2" color="text.secondary">黑名单是空的。</Typography>
          : (
            <Stack spacing={0.5}>
              {rows.map(function (b) {
                return (
                  <Stack key={b.id} direction="row" spacing={1} alignItems="center">
                    <Box sx={{ position: 'relative' }}>
                      <UserAvatar user={{ id: b.id, nickname: b.name }} size={28} />
                      <OnlineDot on={b.online === 1} size={8} />
                    </Box>
                    <Typography variant="body2" noWrap sx={{ minWidth: 0, flex: 1 }}>{b.name}</Typography>
                    <Chip size="small" label={b.team} sx={{ height: 18, fontSize: 10, bgcolor: '#f5f5f7', color: '#5f6b7f' }} />
                    <Button size="small" disabled={busy === b.id} onClick={function () { unblock(b.id); }}>放出</Button>
                  </Stack>
                );
              })}
            </Stack>
          )}
      </Paper>
    );
  }
  function ShowPublicCard() {
    const [on, setOn] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      (async function () {
        try {
          const j = await api('/api/auth/me');
          if (j.code === 0 && j.data && typeof j.data.show_public !== 'undefined') setOn(j.data.show_public === 1);
        } catch (e) { /* 静默 */ }
      })();
    }, []);

    const save = async function (next) {
      setBusy(true);
      try {
        const j = await api('/api/profile/show-public', { method: 'POST', body: { show_public: next ? 1 : 0 } });
        if (j.code === 0) { setOn(j.data.show_public === 1); toast(j.msg, 'success'); }
        else toast(j.msg, 'error');
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    if (on === null) return null;
    return (
      <Paper variant="outlined" sx={{ p: 2.5, mt: 2 }}>
        <FormControlLabel
          control={<Switch checked={on} disabled={busy} onChange={(e) => save(e.target.checked)} />}
          label="愿意出现在战队对外展示页" />
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
          打开后，你的{on ? '游戏昵称与职责标签' : '昵称与职责标签'}会显示在战队的公开主页上（不显示 QQ 号、邮箱与登录 IP）。
          战队队长也可以整块关掉这个栏目，那样即使你打开也不会公开。没填昵称时不会上页。
        </Typography>
      </Paper>
    );
  }
  // ==================== 成员列表与个人主页（v5.30.0 上线，v5.30.1 起别队的人也能点开）====================
  // 一列自家战队的人：头像上带在线点，点进去是 TA 的主页。
  // 这里只有显示名 / 职责标签 / 在线与否 —— 带账号、IP、归属地的那份名单在管理中心，不在这儿。
  function MembersRoute({ me }) {
    // Root 把 hash 的查询段切掉了，而且 #/members?u=1 → ?u=2 不改变 route，
    // 所以这里自己盯 hashchange，不能指望外层重渲染
    const [uid, setUid] = useState(function () { return Number(hashParam('u')) || 0; });
    useEffect(function () {
      const onHash = function () { setUid(Number(hashParam('u')) || 0); };
      window.addEventListener('hashchange', onHash);
      return function () { window.removeEventListener('hashchange', onHash); };
    }, []);
    return uid > 0 ? <MemberProfile uid={uid} me={me} /> : <MembersList me={me} />;
  }

  function OnlineDot({ on, size }) {
    const d = size || 10;
    return (
      <Box sx={{ position: 'absolute', right: -1, bottom: -1, width: d, height: d, borderRadius: '50%',
        border: '2px solid #fff', bgcolor: on ? '#22c55e' : '#cbd5e1' }} />
    );
  }

  function MembersList({ me }) {
    const [data, setData] = useState(null);
    useEffect(function () {
      (async function () {
        try {
          const j = await api('/api/members');
          if (j.code === 0) setData(j.data); else toast(j.msg || '名单读不到', 'error');
        } catch (e) { toast('加载失败', 'error'); }
      })();
    }, []);
    if (!data) return <Paper sx={{ p: 3 }}><LoadingBox /></Paper>;
    const list = data.list || [];
    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="成员列表" desc={'本队已通过的成员共 ' + (data.total || 0) + ' 人 · 5 分钟内有操作算在线'} />
        {data.truncated === 1 && (
          <Alert severity="info" sx={{ mb: 1.5 }}>人比较多，这里只按最近活跃列出前面 {list.length} 位。</Alert>
        )}
        {list.length === 0 ? <EmptyBox text="队里还没有其它已通过的成员" /> : (
          <Stack spacing={0.5}>
            {list.map(function (u) {
              return (
                <Stack key={u.id} direction="row" spacing={1.4} alignItems="center"
                  onClick={function () { location.hash = '#/members?u=' + u.id; }}
                  sx={{ p: 1, borderRadius: 2, cursor: 'pointer', border: '1px solid #ededf2',
                    '&:hover': { bgcolor: 'action.hover' } }}>
                  <Box sx={{ position: 'relative' }}>
                    <UserAvatar user={{ id: u.id, nickname: u.name }} size={40} />
                    <OnlineDot on={u.online === 1} />
                  </Box>
                  <Box sx={{ minWidth: 0, flex: 1 }}>
                    <Stack direction="row" spacing={0.8} alignItems="center" sx={{ overflow: 'hidden' }}>
                      <Typography variant="body2" fontWeight={600} noWrap sx={{ minWidth: 0 }}>{u.name}</Typography>
                      {u.is_admin === 1 && <Chip size="small" label="管理员" sx={{ height: 18, fontSize: 10 }} />}
                      {u.is_me === 1 && <Chip size="small" label="我" sx={{ height: 18, fontSize: 10, bgcolor: '#eef2ff', color: '#3730a3' }} />}
                    </Stack>
                    {!!u.tag && <RoleTagChip tag={{ name: u.tag, color: u.color }} />}
                  </Box>
                  <Typography variant="caption" color="text.secondary">{u.online === 1 ? '在线' : '离线'}</Typography>
                </Stack>
              );
            })}
          </Stack>
        )}
      </Paper>
    );
  }

  function MemberProfile({ uid, me }) {
    const [d, setD] = useState(null);
    const [failed, setFailed] = useState('');
    const load = useCallback(async function () {
      setD(null); setFailed('');
      try {
        const j = await api('/api/members/' + uid);
        if (j.code === 0) setD(j.data); else setFailed(j.msg || '没有这位成员');
      } catch (e) { setFailed('加载失败'); }
    }, [uid]);
    useEffect(function () { load(); }, [load]);

    const block = async function (on) {
      const j = await api(on ? '/api/blocks/add' : '/api/blocks/remove', { method: 'POST', body: { user_id: uid } });
      if (j.code === 0) { toast(j.msg, 'success'); load(); } else toast(j.msg, 'error');
    };

    if (failed) return <Paper sx={{ p: 3 }}><EmptyBox text={failed} /></Paper>;
    if (!d) return <Paper sx={{ p: 3 }}><LoadingBox /></Paper>;
    const m = d.member || {};
    const isMe = d.is_me === 1;
    const tiles = [];
    if (d.fields && d.stat) {
      d.fields.forEach(function (f) {
        const v = d.stat[f.key];
        if (v === null || typeof v === 'undefined') return;
        tiles.push({ label: f.label, text: v + (f.suffix || '') });
      });
    }
    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5, flexWrap: 'wrap', useFlexGap: true }}>
          <Button size="small" onClick={function () { location.hash = '#/members'; }}>‹ 成员</Button>
          <Box sx={{ flex: 1 }} />
          {!isMe && m.can_block === 1 && (
            m.blocked_by_me === 1
              ? <Button size="small" variant="outlined" onClick={function () { block(false); }}>放出黑名单</Button>
              : <Button size="small" color="error" onClick={function () { block(true); }}>加入黑名单</Button>
          )}
          {!isMe && m.dm_open === 1 && (
            <Button size="small" variant="contained" onClick={function () { gotoDm(m.id, m.name); }}>私聊 TA</Button>
          )}
        </Stack>

        <Stack direction="row" spacing={2} alignItems="center" sx={{ mb: 2 }}>
          <Box sx={{ position: 'relative' }}>
            <UserAvatar user={{ id: m.id, nickname: m.name }} size={64} />
            <OnlineDot on={m.online === 1} size={12} />
          </Box>
          <Box sx={{ minWidth: 0 }}>
            <Stack direction="row" spacing={1} alignItems="center" sx={{ overflow: 'hidden' }}>
              <Typography variant="h6" fontWeight={600} noWrap sx={{ minWidth: 0 }}>{m.name}</Typography>
              {m.is_admin === 1 && <Chip size="small" label="管理员" />}
            </Stack>
            <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 0.5, flexWrap: 'wrap', useFlexGap: true }}>
              {!!m.tag && <RoleTagChip tag={{ name: m.tag, color: m.color }} />}
              {!!m.team && <Chip size="small" label={m.team} sx={{ height: 20, fontSize: 11, bgcolor: '#eef2ff', color: '#3730a3' }} />}
              <Typography variant="caption" color="text.secondary">
                {m.online === 1 ? '在线' : '离线'}{m.same_team === 0 ? ' · 其它战队' : ''}
                {m.joined_at ? ' · ' + m.joined_at + ' 加入' : ''}
              </Typography>
            </Stack>
          </Box>
        </Stack>

        <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>战绩</Typography>
        {tiles.length > 0 ? (
          <Box>
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 1, mb: 1 }}>
              {tiles.map(function (x) {
                return (
                  <Box key={x.label} sx={{ px: 1.2, py: 0.8, borderRadius: 2, bgcolor: '#f7f7fa', minWidth: 84 }}>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{x.label}</Typography>
                    <Typography variant="body2" fontWeight={600}>{x.text}</Typography>
                  </Box>
                );
              })}
            </Stack>
            {!!d.uploaded && (
              <Typography variant="caption" color="text.secondary">更新于 {String(d.uploaded).slice(0, 16)}</Typography>
            )}
          </Box>
        ) : (
          <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
            {isMe ? '还没有战绩记录，可在「我的战绩」里上传。' : 'TA 没有公开战绩（这是本人自己的开关，个人中心里可以开关）。'}
          </Typography>
        )}

      </Paper>
    );
  }

  // ==================== 平台协作（v5.36.13） ====================
  // 队长在这里授权/撤销；平台超管进来后看到的是“退出协作”，不能改授权本身。
  function AdminSupport({ me, onChange }) {
    const [info, setInfo] = useState(null);
    const [busy, setBusy] = useState('');
    const load = useCallback(async function () {
      try {
        const j = await api('/api/support/status');
        if (j.code === 0) setInfo(j.data);
      } catch (e) { /* 读不到就保持加载态 */ }
    }, []);
    useEffect(function () { load(); }, [load]);

    const act = async function (kind) {
      setBusy(kind);
      try {
        const j = await api('/api/support/' + kind, { method: 'POST' });
        if (j.code === 0) {
          toast(j.msg, 'success');
          if (j.data && j.data.available !== undefined) setInfo(j.data);
          else await load();
          if (onChange) onChange();
        } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
      setBusy('');
    };

    const exitSupport = async function () {
      setBusy('exit');
      try {
        const j = await api('/api/support/exit', { method: 'POST' });
        if (j.code === 0) { location.href = (j.data && j.data.url) || '/'; return; }
        toast(j.msg, 'error');
      } catch (e) { toast('操作失败', 'error'); }
      setBusy('');
    };

    if (!info) return <LoadingBox />;
    if (!info.available) {
      return <Alert severity="warning">平台协作数据表尚未安装，请先执行升级 SQL（database/upgrade_v5.36.12_to_v5.36.13.sql）。</Alert>;
    }

    if (info.mode) {
      return (
        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Stack spacing={1.5}>
            <Alert severity="info">
              当前正以平台协作身份操作本站管理中心{info.platform_name ? '（' + info.platform_name + '）' : ''}。
              协作会话 4 小时到期，队长撤销授权后也会立即断开；每一步管理操作都会写入本站操作日志。
            </Alert>
            <Box>
              <Button variant="contained" color="warning" onClick={exitSupport} disabled={busy !== ''}>
                退出平台协作
              </Button>
            </Box>
          </Stack>
        </Paper>
      );
    }

    const isOwner = !!info.owner;
    return (
      <Paper variant="outlined" sx={{ p: 2.5 }}>
        <Stack spacing={2}>
          <Box>
            <Typography variant="h6" fontWeight={700} sx={{ fontSize: 18 }}>平台协作</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
              授权后，平台超级管理员可以进入本站管理中心，协助处理设置与功能权限。
              授权长期有效，你可以随时撤销；协作期间只能操作管理中心，每一步都会写入操作日志。
            </Typography>
          </Box>

          {info.granted ? (
            <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap>
              <Chip size="small" color="success" label="已授权" />
              {info.granted_at && (
                <Typography variant="caption" color="text.secondary">授权时间：{fmtTime(info.granted_at)}</Typography>
              )}
              <Typography variant="caption" color="text.secondary">
                在用协作会话：{info.active_sessions || 0} 个
              </Typography>
            </Stack>
          ) : (
            <Typography variant="body2" color="text.secondary">当前未授权，平台超管无法进入本站管理中心。</Typography>
          )}

          {!isOwner ? (
            <Alert severity="info">只有战队队长本人可以授权或撤销平台协作。</Alert>
          ) : info.granted ? (
            <Box>
              <Button variant="outlined" color="error" disabled={busy !== ''}
                onClick={function () {
                  if (!window.confirm('撤销平台协作授权？\n正在协作的会话会立即失效，之后平台超管无法再进入。')) return;
                  act('revoke');
                }}>撤销授权</Button>
            </Box>
          ) : (
            <Box>
              <Button variant="contained" disabled={busy !== ''} onClick={function () { act('grant'); }}>
                同意授权
              </Button>
            </Box>
          )}
        </Stack>
      </Paper>
    );
  }

  // ==================== QQ 机器人 · AI 聊天（v5.36.14，bot 权限）====================
  // 队长在这里选「平台首选模型」或「自己的模型」，并调冷却/上限/主动发言。
  // 平台提示词只读；模型输出永远只当聊天文本，不会回到指令分发。
  function AdminAi() {
    const [d, setD] = useState(null);
    const [form, setForm] = useState(null);
    const [busy, setBusy] = useState(false);
    const [recent, setRecent] = useState([]);
    const [models, setModels] = useState([]);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/admin/ai');
        if (j.code !== 0) { toast(j.msg || 'AI 设置读不到', 'error'); return; }
        setD(j.data);
        setRecent((j.data.usage && j.data.usage.recent) || []);
        setForm(Object.assign({}, j.data.config, {
          own_base_url: j.data.own.base_url, own_model: j.data.own.model, own_api_key: '',
        }));
      } catch (e) { toast('AI 设置读不到', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const set = function (k) { return function (e) { setForm(Object.assign({}, form, { [k]: e.target.value })); }; };
    const setBool = function (k) { return function (e) { setForm(Object.assign({}, form, { [k]: e.target.checked ? 1 : 0 })); }; };

    const save = async function () {
      if (!form || busy) { return; }
      setBusy(true);
      try {
        const j = await api('/api/admin/ai/save', { method: 'POST', body: {
          mode: form.mode, auto_reply: form.auto_reply, reply_mode: form.reply_mode,
          bot_alias: form.bot_alias, cooldown_seconds: form.cooldown_seconds,
          hourly_limit: form.hourly_limit, daily_token_limit: form.daily_token_limit,
          chat_probability: form.chat_probability, sticker_probability: form.sticker_probability,
          prompt_preset_id: form.prompt_preset_id,
          proactive_enabled: form.proactive_enabled,
          proactive_interval_minutes: form.proactive_interval_minutes,
          proactive_daily_limit: form.proactive_daily_limit,
          proactive_start_hour: form.proactive_start_hour,
          proactive_end_hour: form.proactive_end_hour,
          own_base_url: form.own_base_url, own_model: form.own_model, own_api_key: form.own_api_key,
        } });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('保存失败', 'error'); }
      setBusy(false);
    };

    // v5.36.29：填了 Base URL 就不用再手打模型名
    const fetchModels = async function () {
      if (!form || busy) { return; }
      if (!form.own_base_url) { toast('先填 Base URL', 'error'); return; }
      setBusy(true);
      try {
        const j = await api('/api/admin/ai/models', { method: 'POST', body: {
          own_base_url: form.own_base_url, own_api_key: form.own_api_key,
        } });
        if (j.code !== 0) { toast(j.msg || '没拿到模型列表', 'error'); return; }
        const list = (j.data && j.data.models) || [];
        setModels(list);
        if (list.length === 1) { setForm(Object.assign({}, form, { own_model: list[0] })); }
        toast('已获取 ' + list.length + ' 个模型', 'success');
      } catch (e) { toast('获取失败', 'error'); }
      setBusy(false);
    };

    const testOwn = async function () {      if (!form || busy) { return; }
      setBusy(true);
      try {
        const j = await api('/api/admin/ai/test', { method: 'POST', body: {
          own_base_url: form.own_base_url, own_api_key: form.own_api_key, own_model: form.own_model,
        } });
        toast(j.msg || '测试完成', j.code === 0 ? 'success' : 'error');
      } catch (e) { toast('测试失败', 'error'); }
      setBusy(false);
    };

    // v5.36.30：表情包排障——立刻发一张 / 清空日志
    const stickerTest = async function () {
      if (busy) { return; }
      if (!window.confirm('立刻在绑定的 QQ 群里发一张表情包？\n这条是主动消息，不占用被动回复窗口；发不出去会在这里显示 QQ 的原话。')) return;
      setBusy(true);
      try {
        const j = await api('/api/admin/ai/sticker-test', { method: 'POST', body: {} });
        toast(j.msg || (j.code === 0 ? '已发出' : '发送失败'), j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('发送失败', 'error'); }
      setBusy(false);
    };

    const clearStickerLog = async function () {
      if (!window.confirm('清空表情包发送日志？\n只清这张日志表，表情包本身和聊天记录都不动。')) return;
      setBusy(true);
      try {
        const j = await api('/api/admin/ai/sticker-logs/clear', { method: 'POST', body: {} });
        toast(j.msg || '已清空', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('清空失败', 'error'); }
      setBusy(false);
    };

    const clearContext = async function () {      if (!window.confirm('清空本队 AI 的短期聊天记忆？\n只清最近几条上下文，不影响成员账号、指令和用量记录。')) return;
      setBusy(true);
      try {
        const j = await api('/api/admin/ai/clear-context', { method: 'POST', body: {} });
        toast(j.msg || '已清空', j.code === 0 ? 'success' : 'error');
      } catch (e) { toast('清空失败', 'error'); }
      setBusy(false);
    };

    const proactiveTest = async function () {
      if (!form || busy) { return; }
      if (!window.confirm('立刻在绑定的 QQ 群里发一条主动消息？\n会消耗一次 AI 调用和当天主动发言次数。')) return;
      setBusy(true);
      try {
        const j = await api('/api/admin/ai/proactive-test', { method: 'POST', body: {} });
        toast(j.msg || '已尝试', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) { load(); }
      } catch (e) { toast('试发失败', 'error'); }
      setBusy(false);
    };

    const num = function (v) { return Number(v || 0).toLocaleString(); };
    const MODE = { off: '关闭', platform: '平台首选模型', own: '自有模型' };

    if (!form || !d) return <Paper sx={{ p: 3 }}><LoadingBox /></Paper>;

    return (
      <Box>
        <Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 } }}>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} alignItems={{ md: 'center' }}>
            <Box sx={{ flex: 1 }}>
              <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>QQ 机器人 · AI 聊天</Typography>
              <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.3 }}>
                AI 只负责在群里聊天，不会执行签到、地图、管理这些指令。用平台首选模型要平台发 Token；
                没有 Token 可以换成自己的模型（自己的密钥只在本站保存，界面只回显末四位）。
                为了接得上话，服务端只保留最近几条上下文，最多 3 天；平台看不到群聊正文。
              </Typography>
            </Box>
            <Chip size="small" label={MODE[form.mode] || form.mode} color={form.mode === 'off' ? 'default' : 'primary'} variant="outlined" />
          </Stack>

          {Number(d.platform.platform_disabled) === 1 && (
            <Alert severity="error" sx={{ mt: 1.5 }}>平台已停用本队 AI 聊天（平台模型和自有模型都不可用）。</Alert>
          )}
          {!d.bot.enabled && (
            <Alert severity="warning" sx={{ mt: 1.5 }}>本队 QQ 机器人尚未启用，AI 配好了也不会回复；请先到「QQ机器人」页填凭据并打开启用。</Alert>
          )}
          {d.bot.enabled && !d.bot.bound && (
            <Alert severity="warning" sx={{ mt: 1.5 }}>本队还没有绑定 QQ 群，AI 没有可以说话的群；请先到「QQ机器人」页回填群绑定验证码。</Alert>
          )}
          {form.mode === 'platform' && d.quota.balance <= 0 && (
            <Alert severity="warning" sx={{ mt: 1.5 }}>
              平台 Token 余额为 0，现在用平台首选模型不会回复。可以让平台充 Token，或把模式改成「自有模型」。
            </Alert>
          )}
          {!d.platform.enabled && form.mode === 'platform' && (
            <Alert severity="warning" sx={{ mt: 1.5 }}>平台当前没有开放首选模型；请让平台管理员开启，或改用自有模型。</Alert>
          )}

          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 2 }}>
            <Box sx={{ width: { xs: '100%', md: 220 } }}>
              <InputLabel sx={{ fontSize: 12.5, mb: 0.5 }}>使用哪个模型</InputLabel>
              <Select size="small" fullWidth value={form.mode} onChange={set('mode')} disabled={busy}>
                <MenuItem value="off">关闭 AI 聊天</MenuItem>
                <MenuItem value="platform">平台模型（扣 Token）</MenuItem>
                <MenuItem value="own">自有模型（不扣 Token）</MenuItem>
              </Select>
            </Box>
            {form.mode === 'platform' && (
              <Box sx={{ flex: 1 }}>
                <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY }}>平台模型</Typography>
                <Typography sx={{ fontSize: 14, fontWeight: 600 }}>{d.platform.model || '平台未配置'}</Typography>
                <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
                  余额 {num(d.quota.balance)} / 累计发 {num(d.quota.granted)}
                  {d.quota.last_used_at ? ' · 最近使用 ' + fmtTime(d.quota.last_used_at) : ''}
                </Typography>
              </Box>
            )}
            {form.mode === 'own' && (
              <Box sx={{ flex: 1 }}>
                <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY }}>自有模型</Typography>
                <Typography sx={{ fontSize: 14, fontWeight: 600 }}>{form.own_model || '未填写'}</Typography>
                <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
                  {d.own.has_key ? '已保存密钥 ' + d.own.api_key_mask : '尚未保存密钥'}
                </Typography>
              </Box>
            )}
          </Stack>

          {form.mode === 'own' && (
            <Box sx={{ mt: 1.5, p: 1.5, bgcolor: BG_GRAY, borderRadius: 2 }}>
              <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5}>
                <TextField label="Base URL" size="small" fullWidth value={form.own_base_url} onChange={set('own_base_url')}
                  placeholder="https://api.example.com/v1" helperText="必须 https；只支持 OpenAI 兼容 /chat/completions" />
                <TextField label="API Key" size="small" type="password" fullWidth value={form.own_api_key} onChange={set('own_api_key')}
                  placeholder={d.own.has_key ? '留空 = 不修改' : '填写你的密钥'} />
              </Stack>
              <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 1.5 }} alignItems={{ md: 'flex-start' }}>
                <TextField label="模型名" size="small" fullWidth value={form.own_model} onChange={set('own_model')}
                  placeholder="点右边「获取模型列表」，或直接手填"
                  helperText="填了 Base URL 与 Key 后可以直接把上游的模型名列出来" />
                <Button size="small" variant="outlined" sx={{ height: 40, flexShrink: 0, whiteSpace: 'nowrap' }}
                  disabled={busy} onClick={fetchModels}>获取模型列表</Button>
              </Stack>
              {models.length > 0 && (
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ mt: 1 }} alignItems="center">
                  <Select size="small" fullWidth displayEmpty
                    value={models.indexOf(form.own_model) >= 0 ? form.own_model : ''}
                    onChange={function (e) { setForm(Object.assign({}, form, { own_model: e.target.value })); }}>
                    <MenuItem value="" disabled>从拿到的 {models.length} 个模型里选一个</MenuItem>
                    {models.map(function (m) { return <MenuItem key={m} value={m}>{m}</MenuItem>; })}
                  </Select>
                  <Button size="small" variant="text" sx={{ flexShrink: 0 }}
                    onClick={function () { setModels([]); }}>收起列表</Button>
                </Stack>
              )}
              <Button size="small" variant="outlined" sx={{ mt: 1 }} disabled={busy} onClick={testOwn}>测试自有模型</Button>
            </Box>
          )}

          <Paper variant="outlined" sx={{ mt: 1.5, p: 1.5, bgcolor: BG_GRAY }}>
            <Typography sx={{ fontSize: 12.5, fontWeight: 600, color: TEXT_PRIMARY }}>角色扮演人设（平台维护，只能选）</Typography>
            <Typography sx={{ fontSize: 11.5, lineHeight: '18px', color: TEXT_SECONDARY, mt: 0.3 }}>
              平台准备了这些人设。选一条，机器人就用这条的性格说话；选「平台默认人设」就用平台统一那条。
            </Typography>
            <Select size="small" fullWidth sx={{ mt: 1, bgcolor: '#fff' }}
              value={Number(form.prompt_preset_id) || 0} onChange={set('prompt_preset_id')} disabled={busy}>
              <MenuItem value={0}>平台默认人设</MenuItem>
              {(d.prompts || []).map(function (p) {
                return <MenuItem key={p.id} value={p.id}>{p.name}{p.note ? ' · ' + p.note : ''}</MenuItem>;
              })}
            </Select>
            <Typography sx={{ fontSize: 11.5, fontWeight: 600, color: TEXT_SECONDARY, mt: 1 }}>
              当前生效的人设正文
            </Typography>
            <Typography sx={{ fontSize: 12.5, lineHeight: '20px', color: TEXT_SECONDARY, whiteSpace: 'pre-wrap', mt: 0.3 }}>
              {d.platform.prompt || '（平台未填写，使用内置人设）'}
            </Typography>
            <Typography sx={{ fontSize: 11.5, lineHeight: '18px', color: TEXT_SECONDARY, mt: 0.5 }}>
              换人设后点下面「保存设置」生效。服务端始终追加：{d.platform.safety}
            </Typography>
          </Paper>
        </Paper>

        <Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 }, mt: 2 }}>
          <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>文字后面的表情包</Typography>
          <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mt: 0.3, lineHeight: '19px' }}>
            平台在库里准备了 {Number(d.platform.sticker_count || 0)} 张表情包。这里设的是「回复完文字后，再补发一张图」的概率；
            具体补哪张由 AI 按每张图的名称和适用场景挑，挑不出来就随机一张。
          </Typography>
          {Number(d.platform.sticker_enabled) !== 1 && (
            <Alert severity="info" sx={{ mt: 1.5, fontSize: 12.5 }}>平台已经把表情包总闸关掉了，这里设多少都不会发图。</Alert>
          )}
          {Number(d.platform.sticker_enabled) === 1 && Number(d.platform.sticker_count || 0) === 0 && (
            <Alert severity="info" sx={{ mt: 1.5, fontSize: 12.5 }}>平台的表情包库还是空的，命中概率也发不出图。</Alert>
          )}
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 1.5 }}>
            <TextField label="发表情包概率 %" size="small" type="number" sx={{ width: { xs: '100%', md: 200 } }}
              value={form.sticker_probability} onChange={set('sticker_probability')} disabled={busy}
              inputProps={{ min: 0, max: 100 }} helperText="0 = 只发文字；30 = 大约三成回复会带一张图" />
          </Stack>

          <Stack direction="row" spacing={1} sx={{ mt: 1 }} flexWrap="wrap" useFlexGap>
            <Button size="small" variant="contained" disabled={busy} onClick={stickerTest}>立刻发一张（试通道）</Button>
            <Button size="small" variant="outlined" disabled={busy} onClick={clearStickerLog}>清空日志</Button>
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, alignSelf: 'center' }}>
              日志只留最近 7 天
            </Typography>
          </Stack>

          {(d.sticker_log || []).length === 0 ? (
            <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mt: 1.5 }}>
              还没有记录。把概率调高后点「立刻发一张」，或直接在群里 @ 一下，这里就会出现一行；
              发不出去时「原因」那一列就是 QQ 返回的原话。
            </Typography>
          ) : (
            <TableContainer sx={{ mt: 1.5 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>时间</TableCell><TableCell>结果</TableCell>
                    <TableCell>哪张</TableCell><TableCell>原因</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {(d.sticker_log || []).map(function (r, i) {
                    const st = r.status === 'ok' ? 'success' : (r.status === 'fail' ? 'error' : 'warning');
                    const label = r.status === 'ok' ? '已发出' : (r.status === 'fail' ? '失败' : '没发');
                    const who = r.trigger_kind === 'test' ? '手动' : (r.trigger_kind === 'auto' ? '自动接话' : '@回复');
                    return (
                      <TableRow key={i}>
                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{fmtTime(r.created_at)}</TableCell>
                        <TableCell>
                          <Chip size="small" variant="outlined" color={st} label={label} />
                        </TableCell>
                        <TableCell>
                          <Typography variant="caption">
                            {r.sticker_name || '—'}
                            {r.sticker_name ? ' · ' + who + (r.pick_mode === 'ai' ? ' · AI 挑的' : '') : ''}
                          </Typography>
                        </TableCell>
                        <TableCell><Typography variant="caption" color="text.secondary">{r.error || '—'}</Typography></TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        </Paper>

        <Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 }, mt: 2 }}>
          <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>什么时候接话</Typography>
          <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mt: 0.3 }}>
            被 @ 时永远会走 AI（除非命中指令）；下面的开关管的是「群里没 @ 我」的全自动接话。
          </Typography>

          <FormControlLabel sx={{ mt: 1 }} disabled={busy || form.mode === 'off'}
            control={<Switch checked={Number(form.auto_reply) === 1} onChange={setBool('auto_reply')} />}
            label="群消息自动接话（无需 @）" />
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 0.5 }}>
            <Box sx={{ width: { xs: '100%', md: 230 } }}>
              <InputLabel sx={{ fontSize: 12.5, mb: 0.5 }}>自动接话范围</InputLabel>
              <Select size="small" fullWidth value={form.reply_mode} onChange={set('reply_mode')} disabled={busy || Number(form.auto_reply) !== 1}>
                <MenuItem value="at">叫到 / 问句时接</MenuItem>
                <MenuItem value="all">所有消息都判断</MenuItem>
              </Select>
            </Box>
            <TextField label="机器人称呼" size="small" sx={{ width: { xs: '100%', md: 200 } }} value={form.bot_alias}
              onChange={set('bot_alias')} disabled={busy} helperText="群友叫到这个名字也会接话，可留空" />
            <TextField label="参与概率 %" size="small" type="number" sx={{ width: { xs: '100%', md: 140 } }}
              value={form.chat_probability} onChange={set('chat_probability')} disabled={busy || form.reply_mode !== 'all'}
              inputProps={{ min: 0, max: 100 }} helperText="只对普通陈述生效" />
          </Stack>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 1.5 }}>
            <TextField label="冷却秒数" size="small" type="number" sx={{ width: { xs: '100%', md: 150 } }}
              value={form.cooldown_seconds} onChange={set('cooldown_seconds')} disabled={busy} inputProps={{ min: 0, max: 3600 }}
              helperText="同群两次自动接话的最小间隔" />
            <TextField label="每小时最多回复" size="small" type="number" sx={{ width: { xs: '100%', md: 170 } }}
              value={form.hourly_limit} onChange={set('hourly_limit')} disabled={busy} inputProps={{ min: 0, max: 1000 }}
              helperText="0 = 不限" />
            <TextField label="每日 Token 上限" size="small" type="number" sx={{ width: { xs: '100%', md: 180 } }}
              value={form.daily_token_limit} onChange={set('daily_token_limit')} disabled={busy} inputProps={{ min: 0, max: 10000000 }}
              helperText="平台/自有都计，0 = 不限" />
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 }, mt: 2 }}>
          <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>定时主动发言</Typography>
          <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mt: 0.3 }}>
            群里安静一段时间后，AI 主动开一个话题。需要 QQ 开放平台给机器人开通「主动消息」，
            否则接口会拒发；平台侧还有每日总上限。
          </Typography>
          <FormControlLabel sx={{ mt: 1 }} disabled={busy || form.mode === 'off'}
            control={<Switch checked={Number(form.proactive_enabled) === 1} onChange={setBool('proactive_enabled')} />}
            label="开启主动发言" />
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mt: 0.5 }}>
            <TextField label="最小间隔（分钟）" size="small" type="number" sx={{ width: { xs: '100%', md: 170 } }}
              value={form.proactive_interval_minutes} onChange={set('proactive_interval_minutes')} disabled={busy} inputProps={{ min: 10, max: 1440 }} />
            <TextField label="每天最多几条" size="small" type="number" sx={{ width: { xs: '100%', md: 150 } }}
              value={form.proactive_daily_limit} onChange={set('proactive_daily_limit')} disabled={busy} inputProps={{ min: 0, max: 10 }} />
            <TextField label="开始小时" size="small" type="number" sx={{ width: { xs: '100%', md: 120 } }}
              value={form.proactive_start_hour} onChange={set('proactive_start_hour')} disabled={busy} inputProps={{ min: 0, max: 23 }} />
            <TextField label="结束小时" size="small" type="number" sx={{ width: { xs: '100%', md: 120 } }}
              value={form.proactive_end_hour} onChange={set('proactive_end_hour')} disabled={busy} inputProps={{ min: 0, max: 24 }}
              helperText="24 = 到午夜" />
          </Stack>
          <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
            <Button size="small" variant="outlined" disabled={busy || Number(form.proactive_enabled) !== 1} onClick={proactiveTest}>
              立即试发一条
            </Button>
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, alignSelf: 'center' }}>
              {d.quota.last_proactive_at ? '上次尝试 ' + fmtTime(d.quota.last_proactive_at) : '还没有主动发言过'}
            </Typography>
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 }, mt: 2 }}>
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="center">
            <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY, flex: 1 }}>用量</Typography>
            <Chip size="small" variant="outlined" label={'今天 ' + num(d.usage.today_calls) + ' 次 / ' + num(d.usage.today_tokens) + ' Token'} />
            <Chip size="small" variant="outlined" label={'近 7 天 ' + num(d.usage.week_calls) + ' 次 / ' + num(d.usage.week_tokens) + ' Token'} />
            {d.quota.last_error && <Chip size="small" color="warning" variant="outlined" label={'最近失败：' + d.quota.last_error} />}
          </Stack>
          {recent.length === 0 ? (
            <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY, mt: 1 }}>还没有调用记录。AI 开启后，群里 @ 或自动接话会记在这里。</Typography>
          ) : (
            <TableContainer sx={{ mt: 1 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>时间</TableCell><TableCell>类型</TableCell><TableCell>来源</TableCell>
                    <TableCell>模型</TableCell><TableCell>Token</TableCell><TableCell>结果</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {recent.map(function (u, i) {
                    return (
                      <TableRow key={i}>
                        <TableCell>{fmtTime(u.created_at)}</TableCell>
                        <TableCell>{u.kind}</TableCell>
                        <TableCell>{u.source === 'platform' ? '平台' : '自有'}</TableCell>
                        <TableCell>{u.model}</TableCell>
                        <TableCell>{num(u.total_tokens)}</TableCell>
                        <TableCell><Chip size="small" variant="outlined" color={u.status === 'ok' ? 'success' : 'warning'} label={u.status} /></TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
          <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
            <Button variant="contained" size="small" disabled={busy} onClick={save}>保存设置</Button>
            <Button variant="outlined" size="small" disabled={busy} onClick={clearContext}>清空短期聊天记忆</Button>
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, alignSelf: 'center' }}>
              队列中 {num(d.jobs.pending)} 条 · 24 小时失败 {num(d.jobs.failed_24h)} 条
            </Typography>
          </Stack>
        </Paper>
      </Box>
    );
  }

  function AdminPage({ me, tenantName, refreshMe }) {
    const perms = me.admin_perms || [];
    const isSuper = !!me.is_super;
    const tabs = ADMIN_TABS.filter(function (t) {
      if (t[2] === '') return true;
      if (t[2] === '__super__') return isSuper;
      if (Array.isArray(t[2])) {
        if (isSuper) return true;
        return t[2].some(function (p) { return perms.indexOf(p) >= 0; });
      }
      return perms.indexOf(t[2]) >= 0;
    });
    const [tab, setTab] = useState(tabs.length > 0 ? tabs[0][0] : 'users');

    // 平台通知（v5.1.0 引入；v5.2.0：服务端已阅标记，未读时管理员有提醒）
    const [notices, setNotices] = useState([]);
    const [noticeOpen, setNoticeOpen] = useState(false);
    useEffect(function () {
      (async function () {
        try {
          const json = await api('/api/admin/platform-notices');
          if (json.code === 0) {
            const list = json.data.list || [];
            setNotices(list);
            // v5.2.0：有未读通知时提醒管理员
            if ((json.data.unread || 0) > 0) {
              toast('收到 ' + json.data.unread + ' 条未读平台通知，请及时查看', 'warning');
            }
          }
        } catch (e) { /* 忽略：通知拉取失败不影响管理功能 */ }
      })();
    }, []);
    const unread = notices.filter(function (n) { return !n.read; }).length;
    const openNotices = function () {
      setNoticeOpen(true);
      // v5.2.0：打开通知栏即标记已阅（服务端落库，换设备也保持已阅状态）
      if (unread > 0) {
        api('/api/admin/platform-notices/read', { method: 'POST' }).then(function () {
          setNotices(function (prev) {
            return prev.map(function (n) { return Object.assign({}, n, { read: true }); });
          });
        }).catch(function () { /* 忽略 */ });
      }
    };

    // v5.17.0：新归属地登录提醒。当事人一定看得到自己账号的那几条（不需要权限键）；
    // 持有「登录日志」权限时另外能在页签里看全队的
    const [alerts, setAlerts] = useState(null);
    const [alertOpen, setAlertOpen] = useState(false);
    const loadAlerts = useCallback(async function () {
      try {
        const json = await api('/api/admin/login-alerts');
        if (json.code === 0) {
          setAlerts(json.data);
          if ((json.data.unread || 0) > 0) {
            toast('你的管理账号出现新的登录归属地，请核对是不是本人', 'warning');
          }
        }
      } catch (e) { /* 忽略：提醒拉取失败不影响管理功能 */ }
    }, []);
    useEffect(function () { loadAlerts(); }, [loadAlerts]);
    const openAlerts = function () {
      setAlertOpen(true);
      if (alerts && (alerts.unread || 0) > 0) {
        api('/api/admin/login-alerts/read', { method: 'POST' }).then(function () {
          setAlerts(function (p) { return p ? Object.assign({}, p, { unread: 0, list: (p.list || []).map(function (x) { return Object.assign({}, x, { unread: false }); }) }) : p; });
        }).catch(function () { /* 忽略 */ });
      }
    };

    if (me.role !== 'admin') {
      return <Paper sx={{ p: 3 }}><EmptyBox text="无权限访问" /></Paper>;
    }
    // v5.7.1(H2)：一个权限都没分配的挂名管理员，不再展示"用户管理"等兜底页
    if (tabs.length === 0) {
      return <Paper sx={{ p: 3 }}><EmptyBox text="当前账号尚未分配任何管理权限，请联系超级管理员" /></Paper>;
    }
    // v5.1.0：战队名称展示在管理中心头部（个人中心只显示个人昵称）
    const headDesc = (tenantName ? '战队：' + tenantName + ' · ' : '')
      + (isSuper ? '管理员 · 全部权限' : '已分配 ' + perms.length + ' 项权限');
    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="管理中心" desc={headDesc}>
          {alerts && alerts.list && alerts.list.length > 0 && (
            <Button variant="outlined" color={alerts.unread > 0 ? 'error' : 'primary'} onClick={openAlerts}>
              安全提醒{alerts.unread > 0 ? '（' + alerts.unread + '）' : ''}
            </Button>
          )}
          {notices.length > 0 && (
            <Button variant="outlined" color={unread > 0 ? 'warning' : 'primary'} onClick={openNotices}>
              平台通知{unread > 0 ? '（' + unread + '）' : ''}
            </Button>
          )}
        </PanelHead>
        <Tabs value={tab} onChange={(e, v) => setTab(v)} variant="scrollable"
          sx={{ borderBottom: 1, borderColor: 'divider', mb: 2 }}>
          {tabs.map(function (t) { return <Tab key={t[0]} value={t[0]} label={t[1]} />; })}
        </Tabs>
        {tab === 'review' && <AdminReview />}
        {tab === 'users' && <AdminUsers me={me} />}
        {tab === 'stats' && <AdminPlayerStats />}
        {tab === 'poster' && <AdminPoster />}
        {tab === 'teams' && <AdminTeams />}
        {tab === 'roletags' && <AdminRoleTags />}
        {tab === 'announcements' && <AdminAnnouncements />}
        {tab === 'blacklist' && <AdminBlacklist />}
        {tab === 'customraces' && <AdminCustomRaces />}
        {tab === 'schedule' && <AdminScheduleEvents />}
        {tab === 'schedule' && <AdminRemind />}
        {tab === 'export' && <AdminExport />}
        {tab === 'forumapi' && <AdminForumApi />}
        {tab === 'gameside' && <AdminGameSide onChanged={refreshMe} />}
        {tab === 'duty' && <AdminDuty />}
        {tab === 'rollcall' && <AdminRollCall />}
        {tab === 'votes' && <AdminVotes />}
        {tab === 'lottery' && <AdminLottery />}
        {tab === 'bot' && <AdminBot />}
        {tab === 'aibot' && <AdminAi />}
        {tab === 'battles' && <AdminBattles />}
        {tab === 'tournament' && <AdminTournament />}
        {tab === 'apikeys' && <AdminApiKeys />}
        {tab === 'races' && <AdminRaces />}
        {tab === 'images' && <AdminImages />}
        {tab === 'ipban' && <AdminIpBan />}
        {tab === 'multi' && <AdminMulti />}
        {tab === 'logs' && <AdminLogs />}
        {tab === 'loginlog' && <AdminLoginLogs />}
        {tab === 'dm' && <AdminDm />}
        {tab === 'brand' && <AdminBrand />}
        {tab === 'support' && <AdminSupport me={me} onChange={refreshMe} />}
        {tab === 'showcase' && <AdminShowcase tenantName={tenantName} />}
        {tab === 'storage' && <AdminStorage />}
        {tab === 'perms' && <AdminPerms />}

        <Dialog open={noticeOpen} onClose={() => setNoticeOpen(false)} fullWidth maxWidth="sm">
          <DialogTitle>平台通知</DialogTitle>
          <DialogContent dividers>
            {notices.length === 0 ? <EmptyBox text="暂无平台通知" /> : (
              <Stack spacing={1.5}>
                {notices.map(function (n) {
                  return (
                    <Paper key={n.id} variant="outlined" sx={{ p: 1.5, borderColor: n.read ? 'divider' : '#f59e0b' }}>
                      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.4 }}>
                        {!n.read && <Chip size="small" color="warning" label="未读" />}
                        <Typography variant="caption" color="text.secondary">
                          入驻平台 · {fmtTime(n.created_at)}{n.read && n.read_at ? ' · 已阅' : ''}
                        </Typography>
                      </Stack>
                      <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>{n.content}</Typography>
                    </Paper>
                  );
                })}
              </Stack>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setNoticeOpen(false)}>关闭</Button>
          </DialogActions>
        </Dialog>

        {/* v5.17.0：我自己账号的新归属地登录提醒（不需要权限键） */}
        <Dialog open={alertOpen} onClose={() => setAlertOpen(false)} fullWidth maxWidth="sm">
          <DialogTitle>登录安全提醒</DialogTitle>
          <DialogContent dividers>
            <Alert severity="info" sx={{ mb: 1.5 }}>
              下面这些登录的 IP 归属地与你上一次成功登录时不同。若不是本人操作，请立刻改密码
              （改密后旧会话与免登录设备会被强制下线）。
            </Alert>
            {!alerts ? <LoadingBox /> : (alerts.list || []).length === 0
              ? <EmptyBox text="暂无新归属地登录记录" />
              : (
                <Stack spacing={1.5}>
                  {alerts.list.map(function (a) {
                    return (
                      <Paper key={a.id} variant="outlined" sx={{ p: 1.5, borderColor: a.unread ? '#ff3b30' : 'divider' }}>
                        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.4 }}>
                          {a.unread && <Chip size="small" color="error" label="未读" />}
                          <Typography variant="body2" fontWeight={600}>{a.region || '归属地未知'}</Typography>
                          <Typography variant="caption" color="text.secondary">{fmtTime(a.created_at)}</Typography>
                        </Stack>
                        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                          IP {a.ip} · {a.via === 'cookie' ? '免登录 cookie 自动恢复' : '账号密码登录'}
                        </Typography>
                        {a.ua && (
                          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', wordBreak: 'break-all' }}>
                            {a.ua}
                          </Typography>
                        )}
                      </Paper>
                    );
                  })}
                </Stack>
              )}
          </DialogContent>
          <DialogActions>
            {alerts && alerts.can_view && (
              <Button onClick={() => { setAlertOpen(false); setTab('loginlog'); }}>查看全队登录日志</Button>
            )}
            <Button onClick={() => setAlertOpen(false)}>关闭</Button>
          </DialogActions>
        </Dialog>
      </Paper>
    );
  }

  // ---------- 登录日志（v5.17.0：只记管理员账号的成功与失败登录） ----------
  function AdminLoginLogs() {
    const [list, setList] = useState(null);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [filter, setFilter] = useState('');
    const [unread, setUnread] = useState(0);
    const [keep, setKeep] = useState(0);
    const SIZE = 20;

    const load = useCallback(async function (p, f) {
      const realPage = p || 1;
      setList(null);
      try {
        const json = await api('/api/admin/login-logs?page=' + realPage + '&size=' + SIZE
          + (f ? '&filter=' + f : ''));
        if (json.code === 0) {
          setList(json.data.list || []);
          setTotal(json.data.total || 0);
          setUnread(json.data.unread || 0);
          setKeep(json.data.keep || 0);
          setPage(realPage);
        } else { toast(json.msg, 'error'); setList([]); }
      } catch (e) { setList([]); }
    }, []);

    useEffect(function () { load(1, filter); }, [filter]);  // eslint-disable-line

    const FILTERS = [
      ['', '全部登录'],
      ['flagged', '仅新归属地'],
      ['fail', '仅失败尝试'],
      ['cookie', '仅免登录恢复'],
    ];
    const pages = Math.max(1, Math.ceil(total / SIZE));

    return (
      <Box>
        <PanelHead title="登录日志" desc={'只记录管理员账号（队长与副管理员）的登录；成员登录不入此表，最多保留最近 ' + (keep || 5000) + ' 条'} />
        <Stack direction="row" spacing={1} sx={{ mb: 1.5 }} flexWrap="wrap" useFlexGap>
          {FILTERS.map(function (f) {
            return (
              <Button key={f[0]} size="small" variant={filter === f[0] ? 'contained' : 'outlined'}
                onClick={() => setFilter(f[0])}>{f[1]}</Button>
            );
          })}
          {unread > 0 && <Chip size="small" color="error" label={'未读提醒 ' + unread} />}
        </Stack>

        {!list ? <LoadingBox /> : list.length === 0
          ? <EmptyBox text={filter ? '该筛选下暂无记录' : '暂无管理员登录记录'} />
          : (
            <TableContainer component={Paper} variant="outlined">
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>时间</TableCell><TableCell>账号</TableCell><TableCell>结果</TableCell>
                    <TableCell>方式</TableCell><TableCell>IP / 归属地</TableCell><TableCell>设备</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {list.map(function (r) {
                    return (
                      <TableRow key={r.id} sx={{ bgcolor: r.flagged ? 'rgba(255,59,48,0.06)' : 'inherit' }}>
                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{fmtTime(r.created_at)}</TableCell>
                        <TableCell>
                          <Typography variant="body2">{r.nickname || '—'}</Typography>
                          <Typography variant="caption" color="text.secondary">{r.qq}</Typography>
                        </TableCell>
                        <TableCell>
                          {r.result === 'success'
                            ? <Chip size="small" color="success" variant="outlined" label="成功" />
                            : <Chip size="small" color="error" variant="outlined" label="密码错误" />}
                          {r.flagged && <Chip size="small" color="warning" sx={{ ml: 0.5 }} label="新归属地" />}
                        </TableCell>
                        <TableCell>
                          <Typography variant="caption">{r.via === 'cookie' ? '免登录恢复' : '密码登录'}</Typography>
                        </TableCell>
                        <TableCell>
                          <Typography variant="body2">{r.region || '归属地未知'}</Typography>
                          <Typography variant="caption" color="text.secondary">{r.ip}</Typography>
                        </TableCell>
                        <TableCell>
                          <Typography variant="caption" color="text.secondary"
                            sx={{ display: 'block', maxWidth: 220, wordBreak: 'break-all' }}>
                            {r.ua || '—'}
                          </Typography>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}

        {pages > 1 && (
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 1.5 }}>
            <Button size="small" disabled={page <= 1} onClick={() => load(page - 1, filter)}>上一页</Button>
            <Typography variant="caption" color="text.secondary">第 {page} / {pages} 页 · 共 {total} 条</Typography>
            <Button size="small" disabled={page >= pages} onClick={() => load(page + 1, filter)}>下一页</Button>
          </Stack>
        )}
      </Box>
    );
  }

  // ---------- 注册审核 ----------
  function AdminReview() {
    const [list, setList] = useState(null);
    const [resets, setResets] = useState([]);   // v5.7.1(L1)：忘记密码申请
    const gs = useGameSide();                   // v5.36.0：分端时通过前必须选端
    const [sides, setSides] = useState({});

    const loadResets = useCallback(async function () {
      try {
        const json = await api('/api/admin/pwd-resets');
        if (json.code === 0) setResets(json.data.list || []);
      } catch (e) { /* 忽略：拉取失败不影响注册审核 */ }
    }, []);

    const load = useCallback(async function () {
      setList(null);
      try {
        const json = await api('/api/admin/review');
        setList(json.data.list);
      } catch (e) { setList([]); }
      loadResets();
    }, [loadResets]);

    useEffect(function () { load(); }, [load]);

    const act = async function (id, action) {
      if (action === 'reject' && !window.confirm('拒绝将删除该注册申请，确定吗？')) return;
      const body = { action: action };
      if (action === 'approve' && gs.on) {
        if (!sides[id]) { toast('端手分离已打开，请先给这位成员选端游或手游', 'warning'); return; }
        body.game_side = sides[id];
      }
      try {
        const json = await api('/api/admin/review/' + id, { method: 'POST', body: body });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.7.1(L1)：通过 = 换成申请的新密码并踢掉该账号全部在线会话；驳回 = 删除申请与截图
    const actReset = async function (id, action) {
      if (action === 'reject' && !window.confirm('驳回后该申请及其截图将被删除，确定吗？')) return;
      if (action === 'approve' && !window.confirm('通过后该成员将改用其申请的新密码登录，确定吗？')) return;
      try {
        const json = await api('/api/admin/pwd-resets/' + id, { method: 'POST', body: { action: action } });
        if (json.code === 0) { toast(json.msg, 'success'); loadResets(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (list === null) return <LoadingBox />;
    return (
      <Box>
        {list.length === 0 ? <EmptyBox text="暂无待审核的注册申请" /> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>QQ号</TableCell><TableCell>昵称</TableCell><TableCell>邮箱</TableCell>
                  {gs.on && <TableCell>端别（必选）</TableCell>}
                  <TableCell>注册IP / 归属地</TableCell><TableCell>游戏主页截图</TableCell>
                  <TableCell>申请时间</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {list.map(function (u) {
                  return (
                    <TableRow key={u.id}>
                      <TableCell>{u.username}</TableCell>
                      <TableCell>{u.nickname}</TableCell>
                      <TableCell>{u.email}</TableCell>
                      {gs.on && (
                        <TableCell sx={{ minWidth: 110 }}>
                          <Select size="small" displayEmpty fullWidth value={sides[u.id] || ''}
                            onChange={function (e) { setSides(function (prev) { const n = Object.assign({}, prev); n[u.id] = e.target.value; return n; }); }}>
                            <MenuItem value="" disabled>选端</MenuItem>
                            <MenuItem value="pc">端游</MenuItem>
                            <MenuItem value="mobile">手游</MenuItem>
                          </Select>
                        </TableCell>
                      )}
                      <TableCell>
                        {u.reg_ip || '-'}
                        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{u.reg_region || '未知'}</Typography>
                      </TableCell>
                      <TableCell>
                        {u.profile_image ? (
                          <Box component="img" src={u.profile_image} alt="截图" onClick={() => lightbox(u.profile_image)}
                            sx={{ width: 64, height: 48, objectFit: 'cover', borderRadius: '6px', cursor: 'zoom-in', display: 'block' }} />
                        ) : '-'}
                      </TableCell>
                      <TableCell>
                        {fmtTime(u.created_at)}
                        {/* v5.6.0：黑名单自动预警（命中不自动拒绝，最终决定权在管理员） */}
                        {u.blacklist_reason && (
                          <Typography variant="caption" sx={{ display: 'block', color: '#ff3b30', fontWeight: 600 }}>
                            该 QQ 在本队黑名单，原因：{u.blacklist_reason}
                          </Typography>
                        )}
                      </TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={0.8}>
                          <Button size="small" variant="contained" onClick={() => act(u.id, 'approve')}>通过</Button>
                          <Button size="small" variant="outlined" color="error" onClick={() => act(u.id, 'reject')}>拒绝</Button>
                        </Stack>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        {/* v5.7.1(L1)：忘记密码申请审核（截图佐证，通过后换用新密码并踢出旧会话） */}
        <Typography sx={{ fontSize: 15, fontWeight: 600, mt: 3, mb: 1 }}>
          忘记密码申请{resets.length > 0 ? '（' + resets.length + '）' : ''}
        </Typography>
        {resets.length === 0 ? (
          <Typography variant="caption" color="text.secondary">暂无忘记密码申请</Typography>
        ) : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>QQ号</TableCell><TableCell>昵称</TableCell><TableCell>主页截图</TableCell>
                  <TableCell>申请IP</TableCell><TableCell>申请时间</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {resets.map(function (r) {
                  return (
                    <TableRow key={r.id}>
                      <TableCell>{r.username}</TableCell>
                      <TableCell>{r.nickname}</TableCell>
                      <TableCell>
                        {r.screenshot ? (
                          <Box component="img" src={r.screenshot} alt="截图" onClick={() => lightbox(r.screenshot)}
                            sx={{ width: 64, height: 48, objectFit: 'cover', borderRadius: '6px', cursor: 'zoom-in', display: 'block' }} />
                        ) : <Typography variant="caption" color="text.secondary">无截图权限</Typography>}
                      </TableCell>
                      <TableCell>{r.request_ip || '-'}</TableCell>
                      <TableCell>{fmtTime(r.created_at)}</TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={0.8}>
                          <Button size="small" variant="contained" onClick={() => actReset(r.id, 'approve')}>通过</Button>
                          <Button size="small" variant="outlined" color="error" onClick={() => actReset(r.id, 'reject')}>驳回</Button>
                        </Stack>
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

  // ---------- 用户管理 ----------
  function AdminUsers({ me }) {
    const [keyword, setKeyword] = useState('');
    const [searchText, setSearchText] = useState('');
    const [list, setList] = useState(null);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [pwdUser, setPwdUser] = useState(null);   // v3.7：重置密码目标用户
    const [renameUser, setRenameUser] = useState(null);   // v3.9：改名目标用户
    const [tagUser, setTagUser] = useState(null);   // v5.3.0：职责标签分配目标用户
    const [gsUser, setGsUser] = useState(null);   // v5.36.0：端别分类目标用户
    const [gsFilter, setGsFilter] = useState('');   // v5.36.0：按端别筛
    const perms = me.admin_perms || [];
    const can = function (p) { return perms.indexOf(p) >= 0; };   // v3.9：按权限显隐按钮
    const SIZE = 10;

    const load = useCallback(async function (p, kw, filterOverride) {
      const realPage = p || page;
      const realKw = kw !== undefined ? kw : keyword;
      const realFilter = filterOverride !== undefined ? filterOverride : gsFilter;
      if (p) setPage(p);
      setList(null);
      try {
        const json = await api('/api/admin/users?page=' + realPage + '&size=' + SIZE +
          '&keyword=' + encodeURIComponent(realKw) +
          (realFilter ? '&game_side=' + encodeURIComponent(realFilter) : ''));
        setTotal(json.data.total);
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, [page, keyword, gsFilter]);

    useEffect(function () { load(1, ''); }, []);  // eslint-disable-line

    const setStatus = async function (id, status) {
      try {
        const json = await api('/api/admin/users/' + id + '/status', { method: 'POST', body: { status: status } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const setRole = async function (id, role) {
      try {
        const json = await api('/api/admin/users/' + id + '/role', { method: 'POST', body: { role: role } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const setMute = async function (id, muted) {
      try {
        const json = await api('/api/admin/users/' + id + '/mute', { method: 'POST', body: { muted: muted } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const delUser = async function (id) {
      if (!window.confirm('确定删除该用户吗？此操作不可恢复')) return;
      try {
        const json = await api('/api/admin/users/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const userStatusChip = function (s) {
      if (s === 'approved') return <Chip size="small" color="success" label="正常" />;
      if (s === 'banned') return <Chip size="small" color="error" label="已封禁" />;
      return <Chip size="small" color="warning" label="待审核" />;
    };

    return (
      <Box>
        <Stack direction="row" spacing={1} sx={{ mb: 2, flexWrap: 'wrap', useFlexGap: true }}>
          <TextField size="small" placeholder="搜索 QQ号 / 昵称 / 邮箱" value={searchText}
            onChange={(e) => setSearchText(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') { setKeyword(searchText.trim()); load(1, searchText.trim()); } }} />
          <Button variant="outlined" onClick={() => { setKeyword(searchText.trim()); load(1, searchText.trim()); }}>搜索</Button>
          <Select size="small" value={gsFilter} displayEmpty sx={{ minWidth: 120 }}
            onChange={function (e) { setGsFilter(e.target.value); load(1, undefined, e.target.value); }}>
            <MenuItem value="">全部端别</MenuItem>
            <MenuItem value="pc">端游</MenuItem>
            <MenuItem value="mobile">手游</MenuItem>
            <MenuItem value="none">未分类（待分）</MenuItem>
          </Select>
        </Stack>

        {list === null ? <LoadingBox /> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>QQ号</TableCell><TableCell>昵称</TableCell>
                  <TableCell>端别</TableCell>
                  <TableCell>角色</TableCell>
                  <TableCell>状态</TableCell><TableCell>禁言</TableCell><TableCell>小队</TableCell>
                  <TableCell>IP / 归属地（登录刷新）</TableCell><TableCell>截图</TableCell>
                  <TableCell>注册时间</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {list.map(function (u) {
                  const isSelf = u.id === me.id;
                  return (
                    <TableRow key={u.id}>
                      <TableCell>{u.username}</TableCell>
                      <TableCell>
                        {u.nickname}
                        {/* v5.6.0：QQ 命中本队黑名单的成员打标记（历史追溯） */}
                        {u.in_blacklist && <Chip size="small" color="error" label="黑名单" sx={{ ml: 0.8 }} />}
                      </TableCell>
                      <TableCell>
                        {u.game_side
                          ? <Chip size="small" label={gsLabel(u.game_side)}
                              color={u.game_side === 'pc' ? 'info' : 'success'} />
                          : <Chip size="small" color="warning" variant="outlined" label="未分类" />}
                        {u.game_side && (u.game_side_lock === '1' || u.game_side_lock === '0') && (
                          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                            {u.game_side_lock === '1' ? '只能看本端' : '允许看两端'}
                          </Typography>
                        )}
                      </TableCell>
                      <TableCell>
                        {u.role === 'admin'
                          ? <Chip size="small" color="info" label="管理员" />
                          : <Chip size="small" label="用户" />}
                      </TableCell>
                      <TableCell>{userStatusChip(u.status)}</TableCell>
                      <TableCell>
                        {Number(u.muted) === 1
                          ? <Chip size="small" color="error" label="已禁言" />
                          : <Chip size="small" label="正常" />}
                      </TableCell>
                      <TableCell>{u.team_name || '-'}</TableCell>
                      <TableCell>
                        {u.reg_ip || '-'}
                        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{u.reg_region || ''}</Typography>
                      </TableCell>
                      <TableCell>
                        {u.profile_image ? (
                          <Box component="img" src={u.profile_image} alt="截图" onClick={() => lightbox(u.profile_image)}
                            sx={{ width: 56, height: 42, objectFit: 'cover', borderRadius: '6px', cursor: 'zoom-in', display: 'block' }} />
                        ) : '-'}
                      </TableCell>
                      <TableCell>{fmtTime(u.created_at)}</TableCell>
                      <TableCell>
                        {isSelf ? (
                          <Stack direction="row" spacing={0.5} alignItems="center" flexWrap="wrap" useFlexGap>
                            <Typography variant="caption" color="text.secondary">当前账号</Typography>
                            {/* v5.36.0：自己的端别也要能选——不选就开不了端手分离 */}
                            {can('member_view') && (
                              <Button size="small" variant="outlined" onClick={() => setGsUser(u)}>端别</Button>
                            )}
                          </Stack>
                        ) : (
                          <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                            {can('rename') && (
                              <Button size="small" variant="outlined" onClick={() => setRenameUser(u)}>改名</Button>
                            )}
                            {can('role_tag') && (
                              <Button size="small" variant="outlined" onClick={() => setTagUser(u)}>职责标签</Button>
                            )}
                            {can('member_view') && (
                              <Button size="small" variant="outlined" onClick={() => setGsUser(u)}>端别</Button>
                            )}
                            {can('mute') && (
                              <Button size="small" variant="outlined" onClick={() => setMute(u.id, Number(u.muted) === 1 ? 0 : 1)}>
                                {Number(u.muted) === 1 ? '解除禁言' : '禁言'}
                              </Button>
                            )}
                            {can('ban') && u.status !== 'pending' && (
                              <Button size="small" variant="outlined" color={u.status === 'banned' ? 'primary' : 'error'}
                                onClick={() => setStatus(u.id, u.status === 'banned' ? 'approved' : 'banned')}>
                                {u.status === 'banned' ? '解封' : '封禁'}
                              </Button>
                            )}
                            {can('role_manage') && (
                              <Button size="small" variant="outlined" onClick={() => setRole(u.id, u.role === 'admin' ? 'user' : 'admin')}>
                                {u.role === 'admin' ? '取消管理' : '设为管理'}
                              </Button>
                            )}
                            {can('reset_pwd') && u.role !== 'admin' && (
                              <Button size="small" variant="outlined" onClick={() => setPwdUser(u)}>重置密码</Button>
                            )}
                            {can('delete_user') && (
                              <Button size="small" variant="outlined" color="error" onClick={() => delUser(u.id)}>删除</Button>
                            )}
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

        {list && list.length > 0 && <Pager page={page} size={SIZE} total={total} onChange={function (p) { load(p); }} />}

        <ResetPwdDialog user={pwdUser} onClose={function () { setPwdUser(null); }} />
        <RenameDialog user={renameUser} onClose={function () { setRenameUser(null); }}
          onDone={function () { setRenameUser(null); load(); }} />
        <AssignTagsDialog user={tagUser} onClose={function () { setTagUser(null); }}
          onDone={function () { setTagUser(null); load(); }} />
        <GameSideUserDialog user={gsUser} onClose={function () { setGsUser(null); }}
          onDone={function () { setGsUser(null); load(); }} />
      </Box>
    );
  }

  // v5.36.0：单个成员的端别（端游/手游，必须选一端）与按人跨端例外
  function GameSideUserDialog({ user, onClose, onDone }) {
    const [side, setSide] = useState('');
    const [lock, setLock] = useState('');
    const [busy, setBusy] = useState(false);
    useEffect(function () {
      if (user) {
        setSide(user.game_side || '');
        setLock(user.game_side ? (user.game_side_lock || '') : '');
      }
    }, [user && user.id]);
    if (!user) return null;
    const save = async function () {
      if (busy) return;
      if (!side) { toast('请先选端游或手游', 'warning'); return; }
      setBusy(true);
      try {
        const j = await api('/api/admin/users/' + user.id + '/game-side', {
          method: 'POST', body: { game_side: side, lock: lock },
        });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) onDone();
      } catch (e) { toast('保存失败', 'error'); }
      setBusy(false);
    };
    return (
      <Dialog open onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>端别 · {user.nickname || user.username}</DialogTitle>
        <DialogContent dividers>
          <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mb: 1 }}>
            每位成员必须选端游或手游，没有「不分类」。选好之后，分端的内容只有本端与「全队共享」的能看到。
          </Typography>
          <InputLabel sx={{ fontSize: 12 }}>端别（必选）</InputLabel>
          <Select fullWidth size="small" displayEmpty value={side} onChange={function (e) { setSide(e.target.value); }} sx={{ mb: 2 }}>
            <MenuItem value="" disabled>请选择端游或手游</MenuItem>
            <MenuItem value="pc">端游</MenuItem>
            <MenuItem value="mobile">手游</MenuItem>
          </Select>
          <InputLabel sx={{ fontSize: 12 }}>跨端可见（按人例外，覆盖全队默认）</InputLabel>
          <Select fullWidth size="small" value={lock} disabled={!side}
            onChange={function (e) { setLock(e.target.value); }}>
            <MenuItem value="">跟随全队默认</MenuItem>
            <MenuItem value="1">只能看本端</MenuItem>
            <MenuItem value="0">允许看两端</MenuItem>
          </Select>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={busy}>取消</Button>
          <Button variant="contained" onClick={save} disabled={busy || !side}>保存</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 战队小队管理 ----------
  function AdminTeams() {
    const [list, setList] = useState(null);
    const [users, setUsers] = useState([]);          // v3.9.6：成员分配用已审核名单
    const [editData, setEditData] = useState(null);   // null=关, {}=新建, {id..}=编辑
    const [assignBusy, setAssignBusy] = useState(0);  // 正在分配中的用户 id

    const load = useCallback(async function () {
      setList(null);
      try {
        const json = await api('/api/admin/teams');
        setList(json.data.list);
        setUsers(json.data.users || []);
      } catch (e) { setList([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const delTeam = async function (id) {
      if (!window.confirm('确定解散该小队吗？小队成员将被移出')) return;
      try {
        const json = await api('/api/admin/teams/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v3.9.6：把成员分配到指定小队（0 = 移出小队）
    const assign = async function (userId, teamId) {
      if (assignBusy) return;
      setAssignBusy(userId);
      try {
        const json = await api('/api/admin/teams/assign', { method: 'POST', body: { user_id: userId, team_id: teamId } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setAssignBusy(0); }
    };

    const teamNameOf = function (tid) {
      if (!tid || !list) return '';
      const t = list.find(function (x) { return x.id === tid; });
      return t ? t.name : '';
    };

    return (
      <Box>
        <Button variant="contained" sx={{ mb: 2 }} onClick={() => setEditData({})}>新建小队</Button>
        {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="暂无小队，点击上方按钮创建" /> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>ID</TableCell><TableCell>小队名称</TableCell><TableCell>端别</TableCell><TableCell>简介</TableCell>
                  <TableCell>队长</TableCell><TableCell>成员数</TableCell><TableCell>创建时间</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {list.map(function (t) {
                  return (
                    <TableRow key={t.id}>
                      <TableCell>{t.id}</TableCell>
                      <TableCell>{t.name}</TableCell>
                      <TableCell>{t.game_side ? <Chip size="small" label={gsLabel(t.game_side)} sx={{ height: 20, fontSize: 11 }} /> : <Typography variant="caption" color="text.secondary">共享</Typography>}</TableCell>
                      <TableCell>{t.description || '-'}</TableCell>
                      <TableCell>{t.leader_name || '-'}</TableCell>
                      <TableCell>{t.member_count}</TableCell>
                      <TableCell>{fmtTime(t.created_at)}</TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={0.8}>
                          <Button size="small" variant="outlined"
                            onClick={() => setEditData({ id: t.id, name: t.name, description: t.description, leader_id: t.leader_id, game_side: t.game_side || '' })}>编辑</Button>
                          <Button size="small" variant="outlined" color="error" onClick={() => delTeam(t.id)}>解散</Button>
                        </Stack>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        {/* v3.9.6：成员分配——管理员直接把成员调去任意小队 */}
        <Typography variant="subtitle1" fontWeight={600} sx={{ mt: 3, mb: 1.5 }}>成员分配</Typography>
        {list !== null && list.length === 0 ? <EmptyBox text="先创建小队后才能分配成员" /> :
          users.length === 0 ? <EmptyBox text="暂无已审核成员" /> : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>ID</TableCell><TableCell>昵称</TableCell><TableCell>QQ号</TableCell>
                    <TableCell>当前小队</TableCell><TableCell>分配到小队</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {users.map(function (u) {
                    const cur = u.team_id ? Number(u.team_id) : 0;
                    return (
                      <TableRow key={u.id}>
                        <TableCell>{u.id}</TableCell>
                        <TableCell>{u.nickname}</TableCell>
                        <TableCell>{u.username}</TableCell>
                        <TableCell>{cur > 0 ? (teamNameOf(cur) || ('#' + cur)) : <Chip size="small" label="未分配" />}</TableCell>
                        <TableCell sx={{ minWidth: 150 }}>
                          <Select size="small" fullWidth value={cur} disabled={assignBusy === u.id}
                            onChange={(e) => { const v = Number(e.target.value); if (v !== cur) assign(u.id, v); }}>
                            <MenuItem value={0}>无小队</MenuItem>
                            {(list || []).map(function (t) {
                              return <MenuItem key={t.id} value={t.id}>{t.name}</MenuItem>;
                            })}
                          </Select>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}

        <TeamDialog data={editData} onClose={() => setEditData(null)} onDone={function () { setEditData(null); load(); }} />
      </Box>
    );
  }

  function TeamDialog({ data, onClose, onDone }) {
    const isEdit = !!(data && data.id);
    const gs = useGameSide();
    const split = !!(gs.on && gs.modules.team);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [leaderId, setLeaderId] = useState('');
    const [side, setSide] = useState('');

    useEffect(function () {
      if (data) {
        setName(data.name || '');
        setDescription(data.description || '');
        setLeaderId(data.leader_id ? String(data.leader_id) : '');
        setSide(data.game_side || (gs.zone || ''));
      }
    }, [data]);

    if (!data) return null;

    const submit = async function () {
      const payload = {
        name: name.trim(),
        description: description.trim(),
        leader_id: parseInt(leaderId, 10) || 0,
        game_side: split ? side : '',
      };
      if (isEdit) payload.id = data.id;
      try {
        const json = await api('/api/admin/teams/save', { method: 'POST', body: payload });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (err) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open={!!data} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>{isEdit ? '编辑小队' : '新建小队'}</DialogTitle>
        <DialogContent>
          <TextField label="小队名称" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={name} onChange={(e) => setName(e.target.value)} inputProps={{ maxLength: 64 }} />
          <TextField label="小队简介" fullWidth multiline minRows={2} sx={{ mb: 2 }}
            value={description} onChange={(e) => setDescription(e.target.value)} inputProps={{ maxLength: 500 }} />
          <TextField label="队长用户 ID（选填）" fullWidth type="number"
            value={leaderId} onChange={(e) => setLeaderId(e.target.value)}
            helperText="填用户 ID（用户管理页可查看），队长会自动加入该小队" />
          {split && (
            <TextField select fullWidth label="小队端别" sx={{ mt: 2 }} value={side} onChange={(e) => setSide(e.target.value)}
              helperText="全队共享＝两种端都能看到这个小队">
              <MenuItem value="">全队共享</MenuItem>
              <MenuItem value="pc">端游专区</MenuItem>
              <MenuItem value="mobile">手游专区</MenuItem>
            </TextField>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>{isEdit ? '保存' : '创建'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 公告管理 ----------
  function AdminAnnouncements() {
    const [list, setList] = useState(null);
    const [editData, setEditData] = useState(null);
    const [receipt, setReceipt] = useState(null);   // v5.6.0：查看已读回执

    const load = useCallback(async function () {
      setList(null);
      try {
        const json = await api('/api/admin/announcements');
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const delAnn = async function (id) {
      if (!window.confirm('确定删除这条公告吗？')) return;
      try {
        const json = await api('/api/admin/announcements/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // v5.36.34：撤回不是删除 —— 成员那边立刻收不到，但行、配图、谁读过的都留着，随时能恢复
    const revokeAnn = async function (a) {
      if (!window.confirm('撤回「' + a.title + '」？\n成员那边马上看不到，之前谁读过仍然查得到；点「恢复」就能再发出去。')) return;
      try {
        const json = await api('/api/admin/announcements/' + a.id + '/revoke', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };
    const restoreAnn = async function (a) {
      try {
        const json = await api('/api/admin/announcements/' + a.id + '/restore', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Box>
        <Button variant="contained" sx={{ mb: 2 }} onClick={() => setEditData({})}>发布公告</Button>
        {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="暂无公告" /> :
          list.map(function (a) {
            const hasReceipt = Number(a.need_receipt) === 1;
            // v5.36.34：state 由后端算好（live=成员现在看得到 / scheduled=还没到点 / revoked=已撤回）
            const st = a.state || 'live';
            const shortTime = function (t) { return t ? String(t).slice(5, 16) : ''; };
            return (
              <Paper key={a.id} variant="outlined" sx={{ mb: 1.5, overflow: 'hidden',
                opacity: st === 'revoked' ? 0.72 : 1 }}>
                {a.image && (
                  <Box component="img" src={a.image} alt="" onClick={() => lightbox(a.image)}
                    sx={{ width: '100%', maxHeight: 220, objectFit: 'cover', display: 'block', cursor: 'zoom-in' }} />
                )}
                <Box sx={{ p: 2 }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
                    <Typography fontWeight={600}>{a.title}</Typography>
                    {a.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(a.game_side)} /> : null}
                    {st === 'scheduled' && (
                      <Chip size="small" color="warning" variant="outlined" label={'待 ' + shortTime(a.scheduled_at) + ' 发'} />
                    )}
                    {st === 'revoked' && <Chip size="small" variant="outlined" label="已撤回" />}
                    {hasReceipt && (
                      <Chip size="small" color={a.reminded_at ? 'warning' : 'primary'} variant="outlined"
                        label={a.reminded_at ? '回执·已提醒' : '需已读回执'} />
                    )}
                  </Box>
                  <Typography variant="body2" color="text.secondary" sx={{ whiteSpace: 'pre-wrap', mt: 0.5 }}>{a.content}</Typography>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                    {st === 'scheduled' ? ('定时 ' + fmtTime(a.scheduled_at) + ' 发给成员（现在还没到点）') : ('发布于 ' + fmtTime(a.created_at))}
                    {st === 'revoked' ? ' · ' + fmtTime(a.revoked_at) + ' 撤回' : ''}
                    {hasReceipt && a.deadline ? ' · 回执截止 ' + fmtTime(a.deadline) : ''}
                  </Typography>
                  <Stack direction="row" spacing={0.8} sx={{ mt: 1.2 }}>
                    {hasReceipt && (
                      <Button size="small" variant="contained" color="primary" onClick={() => setReceipt(a)}>查看回执</Button>
                    )}
                    {st === 'revoked'
                      ? <Button size="small" variant="outlined" onClick={() => restoreAnn(a)}>恢复</Button>
                      : <Button size="small" variant="outlined" onClick={() => revokeAnn(a)}>撤回</Button>}
                    <Button size="small" variant="outlined"
                      onClick={() => setEditData({ id: a.id, title: a.title, content: a.content, need_receipt: a.need_receipt, deadline: a.deadline, game_side: a.game_side || '', scheduled_at: a.scheduled_at || '', state: st })}>编辑</Button>
                    <Button size="small" variant="outlined" color="error" onClick={() => delAnn(a.id)}>删除</Button>
                  </Stack>
                </Box>
              </Paper>
            );
          })}
        <AnnDialog data={editData} onClose={() => setEditData(null)} onDone={function () { setEditData(null); load(); }} />
        <ReceiptDialog ann={receipt} onClose={() => setReceipt(null)} />
      </Box>
    );
  }

  function AnnDialog({ data, onClose, onDone }) {
    const isEdit = !!(data && data.id);
    const gs = useGameSide();
    const gsSplit = gs.on && gs.modules.announce;
    const [side, setSide] = useState('');
    const [title, setTitle] = useState('');
    const [content, setContent] = useState('');
    const [file, setFile] = useState(null);
    // v5.6.0：已读回执选项（不需要回执的公告保持现状，不统计）
    const [needReceipt, setNeedReceipt] = useState(false);
    const [receiptDeadline, setReceiptDeadline] = useState('');
    // v5.36.34：定时发布。空=立即发；只有「还没到点」的行能改这一栏（发出去的改了也没意义，后端不认）
    const [sched, setSched] = useState('');
    const schedEditable = !(data && data.id) || data.state === 'scheduled';
    const fileRef = useRef(null);

    const nextHour = function () {
      const d = new Date(Date.now() + 3600000);
      const p = function (n) { return (n < 10 ? '0' : '') + n; };
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    };

    useEffect(function () {
      if (data) {
        setTitle(data.title || '');
        setContent(data.content || '');
        setFile(null);
        setNeedReceipt(Number(data.need_receipt) === 1);
        // v5.36.0：新公告默认落在当前专区；编辑时保留原来的端别
        setSide(data.game_side !== undefined ? (data.game_side || '') : (gs.zone || ''));
        // 服务端 'YYYY-MM-DD HH:mm:ss' → datetime-local 'YYYY-MM-DDTHH:mm'
        setReceiptDeadline(data.deadline ? String(data.deadline).slice(0, 16).replace(' ', 'T') : '');
        setSched(data.scheduled_at ? String(data.scheduled_at).slice(0, 16).replace(' ', 'T') : '');
        if (fileRef.current) fileRef.current.value = '';
      }
    }, [data]);

    if (!data) return null;

    const submit = async function () {
      const fd = new FormData();
      fd.append('title', title.trim());
      fd.append('content', content.trim());
      if (needReceipt) {
        fd.append('need_receipt', '1');
        if (receiptDeadline) fd.append('deadline', receiptDeadline.replace('T', ' '));
      }
      // v5.36.34：定时那一栏补到秒（datetime-local 只给到分），没勾就不传＝立即发
      if (schedEditable && sched) fd.append('scheduled_at', sched.replace('T', ' ') + (sched.length === 16 ? ':00' : ''));
      if (gsSplit) fd.append('game_side', side);
      if (file) fd.append('file', file);
      if (isEdit) fd.append('id', data.id);
      try {
        const json = await api('/api/admin/announcements/save', { method: 'POST', form: fd });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (err) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open={!!data} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>{isEdit ? '编辑公告' : '发布公告'}</DialogTitle>
        <DialogContent>
          <TextField label="公告标题" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={title} onChange={(e) => setTitle(e.target.value)} inputProps={{ maxLength: 128 }} />
          {gsSplit && (
            <Box sx={{ mb: 2 }}>
              <InputLabel sx={{ fontSize: 12 }}>这条公告给谁看</InputLabel>
              <Select fullWidth size="small" value={side} onChange={function (e) { setSide(e.target.value); }}>
                <MenuItem value="">全队共享（两个专区都看得到）</MenuItem>
                <MenuItem value="pc">端游专区</MenuItem>
                <MenuItem value="mobile">手游专区</MenuItem>
              </Select>
            </Box>
          )}
          <TextField label="公告内容" fullWidth required multiline minRows={4} sx={{ mb: 2 }}
            value={content} onChange={(e) => setContent(e.target.value)} />
          {/* v5.36.34：定时发布——到点自己出现在成员那边，不用人守着 */}
          {schedEditable && (
            <Paper variant="outlined" sx={{ p: 1.5, mb: 2 }}>
              <FormControlLabel
                control={<Checkbox checked={sched !== ''} onChange={(e) => setSched(e.target.checked ? nextHour() : '')} />}
                label={<Typography variant="body2">定时发布（到点自动发给成员，现在先不发）</Typography>} />
              {sched !== '' && (
                <TextField label="发布时间" type="datetime-local" fullWidth size="small"
                  InputLabelProps={{ shrink: true }}
                  value={sched} onChange={(e) => setSched(e.target.value)}
                  helperText="最多排到 30 天以后；把勾去掉就是立即发。这条时间之前成员完全看不到" />
              )}
            </Paper>
          )}
          {isEdit && data.state !== 'live' && data.state !== 'scheduled' && (
            <Alert severity="info" sx={{ mb: 2, borderRadius: 3 }}>
              这条已经撤回了：改完正文还是撤着的状态，要让成员看到请点列表上的「恢复」。
            </Alert>
          )}
          {isEdit && data.state === 'live' && (
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
              这条已经发出去了，定时那一栏改不了（成员都看到了）；要重发或改时间就另发一条。
            </Typography>
          )}
          {/* v5.6.0：送达确认——开启后可统计已读/未读名单并一键提醒 */}
          <Paper variant="outlined" sx={{ p: 1.5, mb: 2 }}>
            <FormControlLabel
              control={<Checkbox checked={needReceipt} onChange={(e) => setNeedReceipt(e.target.checked)} />}
              label={<Typography variant="body2">需要已读回执（可查看谁看了、一键提醒没看的人）</Typography>} />
            {needReceipt && (
              <TextField label="回执截止时间（选填）" type="datetime-local" fullWidth size="small"
                InputLabelProps={{ shrink: true }}
                value={receiptDeadline} onChange={(e) => setReceiptDeadline(e.target.value)}
                helperText="仅作展示与归档参考，到期不会自动冻结" />
            )}
          </Paper>
          <input
            ref={fileRef} type="file"
            onChange={(e) => setFile(e.target.files[0] || null)}
            accept="image/jpeg,image/png,image/gif,image/webp"
            style={{ display: 'none' }}
          />
          <Box onClick={() => { if (fileRef.current) fileRef.current.click(); }} sx={{
            border: '1.5px dashed', borderColor: file ? 'primary.main' : '#b6c4c1', borderRadius: '10px',
            p: 2.5, textAlign: 'center', cursor: 'pointer',
            bgcolor: file ? (t => alpha(t.palette.primary.main, 0.06)) : 'transparent', transition: 'background-color .15s',
            '&:hover': { bgcolor: (t => alpha(t.palette.primary.main, 0.1)) },
          }}>
            <Typography variant="body2" fontWeight={500} color={file ? 'primary' : 'text.secondary'}>
              {file ? ('已选择：' + file.name) : '点击选择配图（选填）'}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              {'支持 jpg / png / gif / webp，不超过 5MB' + (isEdit ? '；不上传则保持原图' : '')}
            </Typography>
          </Box>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>{isEdit ? '保存' : (sched ? '排上定时' : '发布')}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 撞车时间管理 ----------
  function AdminRaces() {
    const gs = useGameSide();
    const split = gs.on && gs.modules.race;
    const [side, setSide] = useState(gs.zone || '');
    const [title, setTitle] = useState('撞车比赛');
    const [raceTime, setRaceTime] = useState('');
    const [list, setList] = useState(null);
    const [serverTime, setServerTime] = useState('');

    const load = useCallback(async function () {
      setList(null);
      try {
        const json = await api('/api/admin/races');
        setServerTime(json.data.server_time || '');
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const add = async function () {
      if (!raceTime) { toast('请选择比赛时间', 'error'); return; }
      try {
        const json = await api('/api/admin/races/save', {
          method: 'POST',
          body: { title: title.trim(), race_time: raceTime.replace('T', ' ') + (raceTime.length === 16 ? ':00' : ''),
            game_side: split ? side : '' },
        });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const delRace = async function (id) {
      if (!window.confirm('确定删除这个时间点吗？')) return;
      try {
        const json = await api('/api/admin/races/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Box>
        <Paper variant="outlined" sx={{ p: 2.5, mb: 2 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 2 }}>发布撞车时间点（最多 3 场未开始的）</Typography>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }}>
            <TextField label="标题" size="small" value={title} inputProps={{ maxLength: 128 }}
              onChange={(e) => setTitle(e.target.value)} sx={{ minWidth: 180 }} />
            <TextField label="比赛时间" size="small" type="datetime-local" value={raceTime}
              onChange={(e) => setRaceTime(e.target.value)}
              InputLabelProps={{ shrink: true }} sx={{ minWidth: 230 }} />
            {split && (
              <Select size="small" value={side} onChange={function (e) { setSide(e.target.value); }} sx={{ minWidth: 150 }}>
                <MenuItem value="">全队共享</MenuItem>
                <MenuItem value="pc">端游专区</MenuItem>
                <MenuItem value="mobile">手游专区</MenuItem>
              </Select>
            )}
            <Button variant="contained" onClick={add}>发布</Button>
          </Stack>
        </Paper>

        {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="暂无时间点" /> :
          list.map(function (r) {
            const expired = serverTime && r.race_time <= serverTime;
            return (
              <Paper key={r.id} variant="outlined" sx={{ p: 2, mb: 1.5, display: 'flex', alignItems: 'center', gap: 2 }}>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography fontWeight={600}>{r.title}</Typography>
                  <Typography variant="body2" color="text.secondary">比赛时间：{r.race_time}</Typography>
                </Box>
                {split && r.game_side ? <Chip size="small" label={gsLabel(r.game_side)} /> : null}
                {expired
                  ? <Chip size="small" label="已过期" />
                  : <Chip size="small" color="success" label="未开始" />}
                <Button size="small" variant="outlined" color="error" onClick={() => delRace(r.id)}>删除</Button>
              </Paper>
            );
          })}
      </Box>
    );
  }

  // ---------- 自建房比赛日程管理（v3.9.6） ----------
  function AdminCustomRaces() {
    const gs = useGameSide();
    const split = gs.on && gs.modules.custom_race;
    const [side, setSide] = useState(gs.zone || '');
    const [title, setTitle] = useState('自建房比赛');
    const [raceTime, setRaceTime] = useState('');
    const [list, setList] = useState(null);
    const [serverTime, setServerTime] = useState('');

    const load = useCallback(async function () {
      setList(null);
      try {
        const json = await api('/api/admin/custom-races');
        setServerTime(json.data.server_time || '');
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const add = async function () {
      if (!raceTime) { toast('请选择比赛时间', 'error'); return; }
      try {
        const json = await api('/api/admin/custom-races/save', {
          method: 'POST',
          body: { title: title.trim(), race_time: raceTime.replace('T', ' ') + (raceTime.length === 16 ? ':00' : ''),
            game_side: split ? side : '' },
        });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const delRace = async function (id) {
      if (!window.confirm('确定删除这个日程吗？')) return;
      try {
        const json = await api('/api/admin/custom-races/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Box>
        <Paper variant="outlined" sx={{ p: 2.5, mb: 2 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 2 }}>发布自建房比赛日程（最多 3 场未开始的）</Typography>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }}>
            <TextField label="标题" size="small" value={title} inputProps={{ maxLength: 128 }}
              onChange={(e) => setTitle(e.target.value)} sx={{ minWidth: 180 }} />
            <TextField label="比赛时间" size="small" type="datetime-local" value={raceTime}
              onChange={(e) => setRaceTime(e.target.value)}
              InputLabelProps={{ shrink: true }} sx={{ minWidth: 230 }} />
            {split && (
              <Select size="small" value={side} onChange={function (e) { setSide(e.target.value); }} sx={{ minWidth: 150 }}>
                <MenuItem value="">全队共享</MenuItem>
                <MenuItem value="pc">端游专区</MenuItem>
                <MenuItem value="mobile">手游专区</MenuItem>
              </Select>
            )}
            <Button variant="contained" onClick={add}>发布</Button>
          </Stack>
        </Paper>

        {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="暂无日程" /> :
          list.map(function (r) {
            const expired = serverTime && r.race_time <= serverTime;
            return (
              <Paper key={r.id} variant="outlined" sx={{ p: 2, mb: 1.5, display: 'flex', alignItems: 'center', gap: 2 }}>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography fontWeight={600}>{r.title}</Typography>
                  <Typography variant="body2" color="text.secondary">比赛时间：{r.race_time}</Typography>
                </Box>
                {split && r.game_side ? <Chip size="small" label={gsLabel(r.game_side)} /> : null}
                {expired
                  ? <Chip size="small" label="已过期" />
                  : <Chip size="small" color="success" label="未开始" />}
                <Button size="small" variant="outlined" color="error" onClick={() => delRace(r.id)}>删除</Button>
              </Paper>
            );
          })}
      </Box>
    );
  }

  // ---------- 图片审核 ----------
  function AdminImages() {
    const [filter, setFilter] = useState('pending');
    const [list, setList] = useState(null);

    const load = useCallback(async function (f) {
      const realF = f || filter;
      setList(null);
      try {
        const json = await api('/api/admin/images?status=' + realF);
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, [filter]);

    useEffect(function () { load('pending'); }, []);  // eslint-disable-line

    const review = async function (id, action) {
      try {
        const json = await api('/api/admin/images/' + id + '/review', { method: 'POST', body: { action: action } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    // 管理员删除图片（连文件一起删，不可恢复）
    const delImg = async function (id) {
      if (!window.confirm('确定删除这张图片吗？删除后无法恢复（图片文件也会被一并删除）。')) return;
      try {
        const json = await api('/api/admin/images/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Box>
        <Box sx={{ mb: 2, minWidth: 160, display: 'inline-block' }}>
          <InputLabel sx={{ fontSize: 13 }}>审核状态</InputLabel>
          <Select size="small" fullWidth value={filter}
            onChange={function (e) { setFilter(e.target.value); load(e.target.value); }}>
            <MenuItem value="pending">待审核</MenuItem>
            <MenuItem value="approved">已通过</MenuItem>
            <MenuItem value="rejected">已拒绝</MenuItem>
          </Select>
        </Box>

        {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="暂无图片" /> : (
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(230px, 1fr))', gap: 2 }}>
            {list.map(function (img) {
              const src = resUrl('/api/images/raw/' + img.id);   // 走鉴权代理，待审核图仅管理员可见
              return (
                <Paper key={img.id} variant="outlined" sx={{ overflow: 'hidden' }}>
                  <Box component="img" src={src} alt="" onClick={() => lightbox(src)}
                    sx={{ width: '100%', height: 170, objectFit: 'cover', display: 'block', cursor: 'zoom-in', bgcolor: '#f1f5f9' }} />
                  <Box sx={{ p: 1.5 }}>
                    <Typography variant="body2" fontWeight={600} noWrap>{img.title}</Typography>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
                      {img.uploader} · {fmtTime(img.created_at)}
                    </Typography>
                    <Stack direction="row" spacing={0.8} flexWrap="wrap" useFlexGap>
                      {img.status === 'pending' && (
                        <React.Fragment>
                          <Button size="small" variant="contained" onClick={() => review(img.id, 'approve')}>通过</Button>
                          <Button size="small" variant="outlined" color="error" onClick={() => review(img.id, 'reject')}>拒绝</Button>
                        </React.Fragment>
                      )}
                      <Button size="small" variant="outlined" color="error" onClick={() => delImg(img.id)}>删除</Button>
                    </Stack>
                  </Box>
                </Paper>
              );
            })}
          </Box>
        )}
      </Box>
    );
  }

  // ---------- IP 封禁管理（v3.2：防爆破） ----------
  function AdminIpBan() {
    const [ip, setIp] = useState('');
    const [reason, setReason] = useState('');
    const [data, setData] = useState(null);
    const busyRef = useRef(false);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/blocked-ips');
        setData(json.data);
      } catch (e) { setData({ list: [], current_ip: '' }); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const add = async function () {
      if (busyRef.current) return;   // 防连点
      const ipVal = ip.trim();
      if (!ipVal) { toast('请输入要封禁的 IP', 'error'); return; }
      busyRef.current = true;
      setBusy(true);
      try {
        const json = await api('/api/admin/blocked-ips', { method: 'POST', body: { ip: ipVal, reason: reason.trim() } });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setIp(''); setReason('');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      } finally {
        busyRef.current = false;
        setBusy(false);
      }
    };

    const unblock = async function (id) {
      if (!window.confirm('确定解封这个 IP 吗？解封后该 IP 可重新登录/注册。')) return;
      try {
        const json = await api('/api/admin/blocked-ips/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Box>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mb: 1.5 }}>
          <TextField size="small" placeholder="要封禁的 IP，例如 1.2.3.4" sx={{ minWidth: 220 }}
            value={ip} onChange={(e) => setIp(e.target.value)} />
          <TextField size="small" placeholder="封禁原因（选填）" inputProps={{ maxLength: 128 }} sx={{ minWidth: 200 }}
            value={reason} onChange={(e) => setReason(e.target.value)} />
          <Button variant="contained" color="error" disabled={busy} onClick={add}>封禁 IP</Button>
        </Stack>

        {!data ? <LoadingBox /> : (
          <React.Fragment>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              提示：被封禁的 IP 将无法登录和注册；你的当前 IP 是 {data.current_ip}，系统不允许封禁自己当前所在 IP。登录连续失败 5 次会自动封禁。
            </Typography>
            {data.list.length === 0 ? <EmptyBox text="暂无被封禁的 IP" /> : (
              <TableContainer>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>IP</TableCell><TableCell>封禁原因</TableCell><TableCell>封禁人</TableCell>
                      <TableCell>封禁时间</TableCell><TableCell>操作</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {data.list.map(function (b) {
                      const auto = !b.blocked_by;
                      return (
                        <TableRow key={b.id}>
                          <TableCell>{b.ip}</TableCell>
                          <TableCell>
                            {b.reason || '-'}
                            {auto && <Chip size="small" label="自动" sx={{ ml: 0.8 }} />}
                          </TableCell>
                          <TableCell>{b.blocked_by_name}</TableCell>
                          <TableCell>{fmtTime(b.created_at)}</TableCell>
                          <TableCell>
                            <Button size="small" variant="outlined" onClick={() => unblock(b.id)}>解封</Button>
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
          </React.Fragment>
        )}
      </Box>
    );
  }

  // ---------- 多账户检测（v3.5：按注册 IP 查小号，可封禁；管理员账号不参与检测） ----------
  function AdminMulti() {
    const [groups, setGroups] = useState(null);

    const load = useCallback(async function () {
      setGroups(null);
      try {
        const json = await api('/api/admin/multi-accounts');
        setGroups(json.data.list);
      } catch (e) { setGroups([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const setStatus = async function (id, status) {
      try {
        const json = await api('/api/admin/users/' + id + '/status', { method: 'POST', body: { status: status } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (groups === null) return <LoadingBox />;

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          按注册 IP 检测小号：同一 IP 注册了 2 个及以上账号的会列在下面（管理员账号不参与检测）。可对可疑小号直接封禁。
        </Alert>
        {groups.length === 0 ? <EmptyBox text="未发现同一 IP 注册多个账号的情况" /> :
          groups.map(function (g) {
            return (
              <Paper key={g.ip} variant="outlined" sx={{ p: 2, mb: 2 }}>
                <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.2 }}>
                  <Typography fontWeight={700}>{g.ip}</Typography>
                  <Chip size="small" color="warning" label={g.count + ' 个账号'} />
                </Stack>
                <TableContainer>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell>QQ号</TableCell><TableCell>昵称</TableCell><TableCell>状态</TableCell>
                        <TableCell>禁言</TableCell><TableCell>注册时间</TableCell><TableCell>操作</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {g.users.map(function (u) {
                        const banned = u.status === 'banned';
                        return (
                          <TableRow key={u.id}>
                            <TableCell>{u.username}</TableCell>
                            <TableCell>{u.nickname}</TableCell>
                            <TableCell>{statusChip(u.status)}</TableCell>
                            <TableCell>
                              {u.muted === 1
                                ? <Chip size="small" color="error" label="已禁言" />
                                : <Chip size="small" label="正常" />}
                            </TableCell>
                            <TableCell>{fmtTime(u.created_at)}</TableCell>
                            <TableCell>
                              {banned ? (
                                <Button size="small" variant="outlined" onClick={() => setStatus(u.id, 'approved')}>解封</Button>
                              ) : (
                                <Button size="small" variant="outlined" color="error" onClick={() => setStatus(u.id, 'banned')}>封禁</Button>
                              )}
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </TableContainer>
              </Paper>
            );
          })}
      </Box>
    );
  }

  // ==================== 游戏攻略（v3.7：用户自发布文章页） ====================
  function GuidesPage({ me }) {
    const [list, setList] = useState(null);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [detail, setDetail] = useState(null);    // 当前查看的文章
    const [editorOpen, setEditorOpen] = useState(false);
    const [editing, setEditing] = useState(null);  // {}=新建，{id,...}=编辑
    const SIZE = 12;

    const load = useCallback(async function (p) {
      const realPage = p || page;
      if (p) setPage(p);
      setList(null);
      try {
        const json = await api('/api/guides?page=' + realPage + '&size=' + SIZE);
        setTotal(json.data.total);
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, [page]);

    useEffect(function () { load(1); }, []);  // eslint-disable-line

    const openDetail = async function (id) {
      try {
        const json = await api('/api/guides/' + id);
        if (json.code === 0) { setDetail(json.data); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('加载失败', 'error'); }
    };

    const openEdit = async function (id) {
      try {
        const json = await api('/api/guides/' + id);
        if (json.code === 0) { setEditing(json.data); setEditorOpen(true); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('加载失败', 'error'); }
    };

    const toggle = async function (id) {
      try {
        const json = await api('/api/guides/' + id + '/toggle', { method: 'POST' });
        if (json.code === 0) {
          toast(json.msg, 'success');
          if (detail && detail.id === id) setDetail(null);
          load();
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const del = async function (id) {
      if (!window.confirm('确定删除该攻略吗？此操作不可恢复')) return;
      try {
        const json = await api('/api/guides/' + id, { method: 'DELETE' });
        if (json.code === 0) {
          toast(json.msg, 'success');
          if (detail && detail.id === id) setDetail(null);
          load();
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const canManage = function (g) { return me.role === 'admin' || g.user_id === me.id; };
    const guideChip = function (s) {
      return s === 'published'
        ? <Chip size="small" color="success" label="已发布" />
        : <Chip size="small" label="已下架" />;
    };

    // ---------- 文章详情视图 ----------
    if (detail) {
      return (
        <Paper sx={{ p: { xs: 2, md: 3.5 } }}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 2 }}>
            <Button variant="outlined" size="small" onClick={() => setDetail(null)}>← 返回列表</Button>
            {canManage(detail) && (
              <Stack direction="row" spacing={1} sx={{ ml: 'auto' }}>
                <Button size="small" variant="outlined" onClick={() => openEdit(detail.id)}>编辑</Button>
                <Button size="small" variant="outlined" onClick={() => toggle(detail.id)}>
                  {detail.status === 'published' ? '下架' : '上架'}
                </Button>
                <Button size="small" variant="outlined" color="error" onClick={() => del(detail.id)}>删除</Button>
              </Stack>
            )}
          </Stack>
          <Typography variant="h5" fontWeight={700} sx={{ mb: 1 }}>{detail.title}</Typography>
          <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap sx={{ mb: 2 }}>
            {guideChip(detail.status)}
            <Typography variant="caption" color="text.secondary">作者：{detail.author}（{detail.author_qq}）</Typography>
            <Typography variant="caption" color="text.secondary">发布于 {fmtTime(detail.created_at)}</Typography>
            {detail.updated_at && detail.updated_at !== detail.created_at && (
              <Typography variant="caption" color="text.secondary">更新于 {fmtTime(detail.updated_at)}</Typography>
            )}
          </Stack>
          {detail.cover_url && (
            <Box component="img" src={resUrl(detail.cover_url)} alt="封面" onClick={() => lightbox(resUrl(detail.cover_url))}
              sx={{ maxWidth: '100%', maxHeight: 420, display: 'block', borderRadius: '10px', mb: 2.5, cursor: 'zoom-in' }} />
          )}
          <Divider sx={{ mb: 2.5 }} />
          <Typography sx={{ whiteSpace: 'pre-wrap', lineHeight: 1.9, fontSize: 15 }}>{detail.content}</Typography>
        </Paper>
      );
    }

    // ---------- 列表视图 ----------
    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <PanelHead title="游戏攻略" desc="分享你的打法与心得，支持图文">
          <Button variant="contained" onClick={() => { setEditing({}); setEditorOpen(true); }}>发布攻略</Button>
        </PanelHead>

        {list === null ? <LoadingBox /> : list.length === 0 ? (
          <EmptyBox text="还没有攻略，来发布第一篇吧" />
        ) : (
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 2 }}>
            {list.map(function (g) {
              return (
                <Paper key={g.id} variant="outlined" sx={{ overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
                  {g.cover_url ? (
                    <Box component="img" src={resUrl(g.cover_url)} alt="" loading="lazy" onClick={() => openDetail(g.id)}
                      sx={{ width: '100%', height: 140, objectFit: 'cover', display: 'block', cursor: 'pointer', bgcolor: '#f1f5f9' }} />
                  ) : (
                    <Box onClick={() => openDetail(g.id)} sx={{
                      height: 140, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center',
                      bgcolor: (t => alpha(t.palette.primary.main, 0.08)), color: 'primary.main',
                    }}>
                      <svg viewBox="0 0 24 24" width="40" height="40" fill="currentColor">
                        <path d="M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 1.5V8h4.5L14 3.5zM8 12h8v2H8v-2zm0 4h8v2H8v-2z" />
                      </svg>
                    </Box>
                  )}
                  <Box sx={{ p: 1.5, flex: 1, display: 'flex', flexDirection: 'column' }}>
                    <Stack direction="row" spacing={1} alignItems="center">
                      <Typography variant="body2" fontWeight={600} onClick={() => openDetail(g.id)} noWrap
                        sx={{ cursor: 'pointer', flex: 1, minWidth: 0, '&:hover': { color: 'primary.main' } }}>
                        {g.title}
                      </Typography>
                      {g.status !== 'published' && guideChip(g.status)}
                    </Stack>
                    <Typography variant="caption" color="text.secondary" sx={{
                      mt: 0.5, mb: 1, flex: 1,
                      display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden',
                    }}>{g.summary}</Typography>
                    <Typography variant="caption" color="text.secondary" noWrap>
                      {g.author} · {fmtTime(g.created_at)}
                    </Typography>
                    {canManage(g) && (
                      <Stack direction="row" spacing={0.5} sx={{ mt: 1 }} flexWrap="wrap" useFlexGap>
                        <Button size="small" onClick={() => openDetail(g.id)}>查看</Button>
                        <Button size="small" onClick={() => openEdit(g.id)}>编辑</Button>
                        <Button size="small" onClick={() => toggle(g.id)}>{g.status === 'published' ? '下架' : '上架'}</Button>
                        <Button size="small" color="error" onClick={() => del(g.id)}>删除</Button>
                      </Stack>
                    )}
                  </Box>
                </Paper>
              );
            })}
          </Box>
        )}

        {list && list.length > 0 && <Pager page={page} size={SIZE} total={total} onChange={function (p) { load(p); }} />}

        <GuideEditorDialog open={editorOpen} guide={editing}
          onClose={function () { setEditorOpen(false); setEditing(null); }}
          onDone={function () { setEditorOpen(false); setEditing(null); load(); }} />
      </Paper>
    );
  }

  // 攻略发布/编辑弹窗（标题 + 正文 + 可选封面图）
  function GuideEditorDialog({ open, guide, onClose, onDone }) {
    const [title, setTitle] = useState('');
    const [content, setContent] = useState('');
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const fileRef = useRef(null);
    const isEdit = !!(guide && guide.id);

    // 打开时回填编辑内容 / 清空新建表单
    useEffect(function () {
      if (!open) return;
      setTitle(guide && guide.title ? guide.title : '');
      setContent(guide && guide.content ? guide.content : '');
      setFile(null);
      if (fileRef.current) fileRef.current.value = '';
    }, [open]);  // eslint-disable-line

    const submit = async function () {
      if (busy) return;
      if (!title.trim() || !content.trim()) { toast('请填写标题和内容', 'error'); return; }
      setBusy(true);
      const fd = new FormData();
      fd.append('title', title.trim());
      fd.append('content', content.trim());
      if (file) fd.append('file', file);
      try {
        const url = isEdit ? '/api/guides/' + guide.id : '/api/guides';
        const json = await api(url, { method: 'POST', form: fd });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) {
        toast('提交失败，请稍后再试', 'error');
      } finally {
        setBusy(false);
      }
    };

    return (
      <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
        <DialogTitle>{isEdit ? '编辑攻略' : '发布攻略'}</DialogTitle>
        <DialogContent>
          <TextField label="攻略标题" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={title} onChange={(e) => setTitle(e.target.value)}
            inputProps={{ maxLength: 128 }} placeholder="如：XX 关卡全收集攻略" />
          <TextField label="攻略内容" fullWidth required multiline minRows={10} maxRows={18} sx={{ mb: 2 }}
            value={content} onChange={(e) => setContent(e.target.value)}
            placeholder="在这里写攻略正文，图文攻略可搭配封面图" />
          {/* 封面图：MUI TextField 不能包 file 输入，用隐藏 input + 虚线点击区 */}
          <input ref={fileRef} type="file"
            onChange={(e) => setFile(e.target.files[0] || null)}
            accept="image/jpeg,image/png,image/gif,image/webp"
            style={{ display: 'none' }} />
          <Box onClick={() => { if (fileRef.current) fileRef.current.click(); }} sx={{
            border: '1.5px dashed', borderColor: file ? 'primary.main' : '#b6c4c1', borderRadius: '10px',
            p: 2.5, textAlign: 'center', cursor: 'pointer',
            bgcolor: file ? (t => alpha(t.palette.primary.main, 0.06)) : 'transparent', transition: 'background-color .15s',
            '&:hover': { bgcolor: (t => alpha(t.palette.primary.main, 0.1)) },
          }}>
            <Typography variant="body2" fontWeight={500} color={file ? 'primary' : 'text.secondary'}>
              {file ? ('已选择：' + file.name) : (isEdit && guide.cover_url ? '点击更换封面图（不选则保留原封面）' : '点击选择封面图（可选）')}
            </Typography>
            <Typography variant="caption" color="text.secondary">支持 jpg / png / gif / webp，不超过 5MB</Typography>
          </Box>
          {isEdit && guide.cover_url && !file && (
            <Box component="img" src={guide.cover_url} alt="当前封面" sx={{ mt: 1.5, maxWidth: '100%', maxHeight: 160, borderRadius: '8px', display: 'block' }} />
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={submit}>{busy ? '提交中...' : (isEdit ? '保存修改' : '发布')}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 操作日志（v3.7：管理操作审计 + 超管清理） ----------
  function AdminLogs() {
    const [list, setList] = useState(null);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [isSuper, setIsSuper] = useState(false);
    const [days, setDays] = useState('30');
    const [busy, setBusy] = useState(false);
    const SIZE = 20;

    const load = useCallback(async function (p) {
      const realPage = p || page;
      if (p) setPage(p);
      setList(null);
      try {
        const json = await api('/api/admin/logs?page=' + realPage + '&size=' + SIZE);
        setTotal(json.data.total);
        setList(json.data.list);
        setIsSuper(!!json.data.is_super);
      } catch (e) { setList([]); }
    }, [page]);

    useEffect(function () { load(1); }, []);  // eslint-disable-line

    // 超管：清理 N 天前的聊天消息
    const cleanupChat = async function () {
      const d = parseInt(days, 10);
      if (!d || d < 1) { toast('请输入有效的天数（≥1）', 'error'); return; }
      if (!window.confirm('确定删除 ' + d + ' 天前的全部聊天消息吗？此操作不可恢复')) return;
      setBusy(true);
      try {
        const json = await api('/api/admin/chat/cleanup', { method: 'POST', body: { days: d } });
        if (json.code === 0) { toast(json.msg, 'success'); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    // 超管：清空管理日志
    const clearLogs = async function () {
      if (!window.confirm('确定清空全部管理操作日志吗？此操作不可恢复')) return;
      setBusy(true);
      try {
        const json = await api('/api/admin/logs/clear', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(1); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Box>
        <PanelHead title="操作日志" desc="记录管理员的每一次管理操作" />

        {isSuper && (
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ mb: 2.5 }}>
            <Paper variant="outlined" sx={{ p: 2, flex: 1 }}>
              <Typography variant="body2" fontWeight={600} sx={{ mb: 1 }}>清理旧聊天消息（仅超管）</Typography>
              <Stack direction="row" spacing={1} alignItems="center">
                <TextField size="small" type="number" value={days} onChange={(e) => setDays(e.target.value)}
                  inputProps={{ min: 1, max: 3650 }} sx={{ width: 110 }} />
                <Typography variant="body2" color="text.secondary">天前的消息</Typography>
                <Button size="small" variant="outlined" color="warning" disabled={busy} onClick={cleanupChat}>清理</Button>
              </Stack>
            </Paper>
            <Paper variant="outlined" sx={{ p: 2, flex: 1 }}>
              <Typography variant="body2" fontWeight={600} sx={{ mb: 1 }}>清空管理日志（仅超管）</Typography>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
                清空后会保留一条“清空日志”记录以便追溯
              </Typography>
              <Button size="small" variant="outlined" color="error" disabled={busy} onClick={clearLogs}>清空日志</Button>
            </Paper>
          </Stack>
        )}

        {list === null ? <LoadingBox /> : list.length === 0 ? (
          <EmptyBox text="暂无日志记录" />
        ) : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>时间</TableCell><TableCell>管理员</TableCell>
                  <TableCell>操作</TableCell><TableCell>详情</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {list.map(function (log) {
                  return (
                    <TableRow key={log.id}>
                      <TableCell sx={{ whiteSpace: 'nowrap' }}>{fmtTime(log.created_at)}</TableCell>
                      <TableCell sx={{ whiteSpace: 'nowrap' }}>{log.admin_name}</TableCell>
                      <TableCell><Chip size="small" label={log.action} /></TableCell>
                      <TableCell>{log.detail || '-'}</TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        {list && list.length > 0 && <Pager page={page} size={SIZE} total={total} onChange={function (p) { load(p); }} />}
      </Box>
    );
  }

  // ---------- 存储空间（v5.1.0：配额查看 + 文件清理，仅超管） ----------
  function fmtBytes(b) {
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB';
    if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
  }

  function AdminStorage() {
    const [data, setData] = useState(null);
    const [busyId, setBusyId] = useState('');

    const load = useCallback(async function () {
      setData(null);
      try {
        const json = await api('/api/admin/storage');
        if (json.code === 0) setData(json.data); else setData(null);
      } catch (e) { setData(null); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const del = async function (type, id, label) {
      if (!window.confirm('确定删除「' + label + '」吗？文件将被永久移除并释放空间')) return;
      const key = type + '-' + id;
      setBusyId(key);
      try {
        const json = await api('/api/admin/storage/delete', { method: 'POST', body: { type: type, id: id } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusyId(''); }
    };

    if (data === null) return <LoadingBox />;

    const pct = data.quota > 0 ? Math.min(100, Math.round(data.used * 100 / data.quota)) : 0;
    const barColor = pct >= 90 ? '#dc2626' : pct >= 70 ? '#f59e0b' : '#16a34a';

    return (
      <Box>
        <PanelHead title="存储空间" desc="每个战队站点有独立存储配额，删除文件即可释放空间" />

        <Paper variant="outlined" sx={{ p: 2, mb: 2.5 }}>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={3} sx={{ mb: 1.5 }}>
            <Box>
              <Typography variant="caption" color="text.secondary">总配额</Typography>
              <Typography variant="h6" fontWeight={700}>{fmtBytes(data.quota)}</Typography>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary">已使用</Typography>
              <Typography variant="h6" fontWeight={700}>{fmtBytes(data.used)}（{pct}%）</Typography>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary">剩余可用</Typography>
              <Typography variant="h6" fontWeight={700}>{fmtBytes(Math.max(0, data.quota - data.used))}</Typography>
            </Box>
          </Stack>
          <Box sx={{ height: 10, borderRadius: '5px', bgcolor: '#e2e8f0', overflow: 'hidden' }}>
            <Box sx={{ width: pct + '%', height: '100%', bgcolor: barColor, borderRadius: '5px', transition: 'width .3s' }} />
          </Box>
          {pct >= 90 && (
            <Typography variant="caption" sx={{ color: '#dc2626', display: 'block', mt: 1 }}>
              存储空间即将用尽，超配额后将无法上传图片，请及时清理不需要的文件
            </Typography>
          )}
        </Paper>

        {data.groups.map(function (g) {
          return (
            <Box key={g.type} sx={{ mb: 3 }}>
              <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>
                {g.label}（{g.count} 个文件 · {fmtBytes(g.bytes)}）
                {g.protected && (
                  <Chip size="small" label="禁止删除" sx={{ ml: 1, verticalAlign: 'middle' }} color="warning" variant="outlined" />
                )}
              </Typography>
              {g.items.length === 0 ? <EmptyBox text="暂无文件" /> : (
                <TableContainer component={Paper} variant="outlined">
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell>预览</TableCell><TableCell>说明</TableCell>
                        <TableCell>大小</TableCell><TableCell align="right">操作</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {g.items.map(function (it) {
                        const key = g.type + '-' + it.id;
                        return (
                          <TableRow key={it.id}>
                            <TableCell>
                              {it.thumb ? (
                                <Box component="img" src={it.thumb} alt="" onClick={() => lightbox(it.thumb)}
                                  sx={{ width: 64, height: 40, objectFit: 'cover', borderRadius: '6px', cursor: 'zoom-in', display: 'block', bgcolor: '#f1f5f9' }} />
                              ) : <Typography variant="caption" color="text.secondary">无</Typography>}
                            </TableCell>
                            <TableCell><Typography variant="body2" sx={{ maxWidth: 320 }} noWrap>{it.label}</Typography></TableCell>
                            <TableCell sx={{ whiteSpace: 'nowrap' }}>{fmtBytes(it.bytes)}</TableCell>
                            <TableCell align="right">
                              {g.protected || it.protected ? (
                                <Typography variant="caption" color="text.secondary">平台保管</Typography>
                              ) : (
                                <Button size="small" variant="outlined" color="error" disabled={busyId === key}
                                  onClick={() => del(g.type, it.id, it.label)}>
                                  {busyId === key ? '删除中...' : '删除'}
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
              {g.count > g.items.length && (
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                  仅显示最近 {g.items.length} 个文件，共 {g.count} 个
                </Typography>
              )}
            </Box>
          );
        })}
      </Box>
    );
  }

  // ---------- 重置用户密码弹窗（v3.7：仅非管理员账号） ----------
  function ResetPwdDialog({ user, onClose }) {
    const [pwd, setPwd] = useState('');
    const [confirm, setConfirm] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = async function () {
      if (busy) return;
      if (pwd.length < 8) { toast('新密码长度至少 8 位', 'error'); return; }
      if (pwd !== confirm) { toast('两次输入的密码不一致', 'error'); return; }
      setBusy(true);
      try {
        const json = await api('/api/admin/users/' + user.id + '/password', { method: 'POST', body: { password: pwd, confirm: confirm } });
        if (json.code === 0) { toast(json.msg, 'success'); setPwd(''); setConfirm(''); onClose(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Dialog open={!!user} onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>重置密码{user ? (' · ' + user.username) : ''}</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 1, mb: 2 }}>
            重置后用户需用新密码登录。密码需 8-64 位且包含字母和数字。
          </Typography>
          <TextField label="新密码" type="password" fullWidth sx={{ mb: 2 }}
            value={pwd} onChange={(e) => setPwd(e.target.value)} inputProps={{ maxLength: 64 }} />
          <TextField label="确认新密码" type="password" fullWidth
            value={confirm} onChange={(e) => setConfirm(e.target.value)} inputProps={{ maxLength: 64 }} />
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={submit}>{busy ? '提交中...' : '重置'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // 修改用户名字弹窗（v3.9）
  function RenameDialog({ user, onClose, onDone }) {
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      if (user) setName(user.nickname || '');
    }, [user]);

    const submit = async function () {
      if (busy) return;
      const v = name.trim();
      if (!v) { toast('请填写新名字', 'error'); return; }
      setBusy(true);
      try {
        const json = await api('/api/admin/users/' + user.id + '/rename', { method: 'POST', body: { nickname: v } });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Dialog open={!!user} onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>修改用户名字</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 1, mb: 2 }}>
            目标用户：{user ? user.username : ''}（仅修改展示昵称，不影响登录）
          </Typography>
          <TextField label="新名字" fullWidth autoFocus
            value={name} onChange={(e) => setName(e.target.value)} inputProps={{ maxLength: 32 }} />
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={submit}>{busy ? '保存中...' : '保存'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ==================== 今天轮换地图（v3.8.1：一天一组多张，每天 9 点切换） ====================
  function RotationPage() {
    const [data, setData] = useState(null);
    const [now, setNow] = useState(Date.now());
    const [showAllUpcoming, setShowAllUpcoming] = useState(false);

    useEffect(function () {
      (async function () {
        try {
          const json = await api('/api/rotation');
          setData(json.code === 0 ? json.data : { enabled: false });
        } catch (e) { setData({ enabled: false }); }
      })();
    }, []);

    // 倒计时每秒刷新
    useEffect(function () {
      const t = setInterval(function () { setNow(Date.now()); }, 1000);
      return function () { clearInterval(t); };
    }, []);

    if (!data) return <Paper sx={{ p: { xs: 2, md: 3 } }}><LoadingBox /></Paper>;

    const head = <PanelHead title="今天轮换地图" desc={data.enabled ? '每天 9:00 轮换新地图（游戏时间 9:00-24:00）' : ''} />;

    if (!data.enabled) {
      return <Paper sx={{ p: { xs: 2, md: 3 } }}>{head}<EmptyBox text="该功能暂未开启，请等待管理员开启" /></Paper>;
    }
    if (!data.today) {
      return <Paper sx={{ p: { xs: 2, md: 3 } }}>{head}<EmptyBox text="还没有配置轮换组合，请等待管理员更新" /></Paper>;
    }

    let countdownText = '';
    if (data.next_rotate_at) {
      const target = new Date(String(data.next_rotate_at).replace(' ', 'T')).getTime();
      let diff = Math.max(0, Math.floor((target - now) / 1000));
      const d = Math.floor(diff / 86400); diff %= 86400;
      const h = Math.floor(diff / 3600); diff %= 3600;
      const m = Math.floor(diff / 60);
      const s = diff % 60;
      countdownText = (d > 0 ? d + ' 天 ' : '') + h + ' 小时 ' + m + ' 分 ' + s + ' 秒';
    }

    const pending = data.status === 'pending';
    const todayMaps = data.today.maps || [];
    const upcoming = data.upcoming || [];
    const COLLAPSED_DAYS = 5;
    const shownUpcoming = showAllUpcoming ? upcoming : upcoming.slice(0, COLLAPSED_DAYS);
    const hiddenDays = upcoming.length - COLLAPSED_DAYS;

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        {head}
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap', mb: 2 }}>
          <Chip color={pending ? 'warning' : 'primary'}
            label={pending ? '今日轮换 · 9 点开始' : '今日轮换 · 进行中'} />
          {data.today.name !== '' && (
            <Typography variant="h6" fontWeight={700}>{data.today.name}</Typography>
          )}
          <Typography variant="body2" color="text.secondary">
            共 {todayMaps.length} 张地图
          </Typography>
          {countdownText !== '' && (
            <Typography variant="body2" color="text.secondary" sx={{ ml: 'auto' }}>
              {pending ? '距开始：' : '距下次轮换：'}{countdownText}
            </Typography>
          )}
        </Box>
        {todayMaps.length === 0 ? <EmptyBox text="今天的组合还没有选择地图" /> : (
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 2, mb: 3 }}>
            {todayMaps.map(function (m) {
              return (
                <Paper key={m.id} variant="outlined" sx={{ overflow: 'hidden' }}>
                  {m.image ? (
                    <Box component="img" src={m.image} alt={m.name} onClick={() => lightbox(m.image)}
                      sx={{ width: '100%', height: 150, objectFit: 'cover', display: 'block', cursor: 'zoom-in', bgcolor: '#f1f5f9' }} />
                  ) : (
                    <Box sx={{ height: 150, bgcolor: (t => alpha(t.palette.primary.main, 0.06)) }} />
                  )}
                  <Box sx={{ p: 1.5 }}>
                    <Typography variant="body1" fontWeight={600}>{m.name}</Typography>
                  </Box>
                </Paper>
              );
            })}
          </Box>
        )}
        {upcoming.length > 0 && (
          <Box>
            <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1.5 }}>轮换预告</Typography>
            <Stack spacing={1.5}>
              {shownUpcoming.map(function (u, i) {
                const dayWord = u.after_days === 1 ? '明天' : (u.after_days === 2 ? '后天' : u.after_days + ' 天后');
                const dayLabel = u.date ? dayWord + ' · ' + String(u.date).slice(5) : dayWord;
                return (
                  <Paper key={i} variant="outlined" sx={{ p: 1.5, display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap' }}>
                    <Chip size="small" variant="outlined" label={dayLabel} sx={{ flexShrink: 0 }} />
                    {u.name !== '' && <Typography variant="body2" fontWeight={600}>{u.name}</Typography>}
                    <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', flex: 1 }}>
                      {u.maps.map(function (m) {
                        return (
                          <Box key={m.id} sx={{ width: 84, textAlign: 'center' }}>
                            {m.image ? (
                              <Box component="img" src={m.image} alt={m.name} loading="lazy"
                                sx={{ width: 84, height: 50, objectFit: 'cover', borderRadius: '6px', display: 'block', bgcolor: '#f1f5f9' }} />
                            ) : (
                              <Box sx={{ width: 84, height: 50, borderRadius: '6px', bgcolor: (t => alpha(t.palette.primary.main, 0.06)) }} />
                            )}
                            <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>{m.name}</Typography>
                          </Box>
                        );
                      })}
                    </Box>
                  </Paper>
                );
              })}
            </Stack>
            {hiddenDays > 0 && (
              <Box sx={{ mt: 1.5, textAlign: 'center' }}>
                <Button size="small" variant="text" onClick={() => setShowAllUpcoming(v => !v)}>
                  {showAllUpcoming ? '收起' : '查看更多（还有 ' + hiddenDays + ' 天）'}
                </Button>
              </Box>
            )}
          </Box>
        )}
      </Paper>
    );
  }

  // ==================== 查询伪军（v3.8：按昵称查是否本战队成员） ====================
  function TraitorPage() {
    const [name, setName] = useState('');
    const [result, setResult] = useState(null);
    const [busy, setBusy] = useState(false);

    const search = async function () {
      const kw = name.trim();
      if (!kw) { toast('请输入要查询的名字', 'error'); return; }
      if (busy) return;
      setBusy(true);
      setResult(null);
      try {
        const json = await api('/api/search/traitor?name=' + encodeURIComponent(kw));
        if (json.code === 0) {
          setResult(json.data);
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) { toast('查询失败，请稍后再试', 'error'); } finally { setBusy(false); }
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 }, maxWidth: 680 }}>
        <PanelHead title="查询伪军" desc="输入名字查询是否为我们战队收录的成员" />
        <Stack direction="row" spacing={1}>
          <TextField fullWidth size="small" placeholder="输入要查询的名字（昵称）" value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') search(); }}
            inputProps={{ maxLength: 32 }} />
          <Button variant="contained" disabled={busy} onClick={search} sx={{ flexShrink: 0 }}>
            {busy ? '查询中...' : '查询'}
          </Button>
        </Stack>
        {result && (
          <Paper variant="outlined" sx={{ mt: 2.5, p: 3, textAlign: 'center' }}>
            {result.found ? (
              <Box>
                <Chip color="success" label="是" sx={{ fontSize: 16, fontWeight: 700, px: 1.5, height: 38 }} />
                <Typography sx={{ mt: 1.5 }}>该名字已收录为我们战队成员</Typography>
                {/* v5.3.0：匹配行附带职责标签 */}
                <Stack direction="row" spacing={1} justifyContent="center" flexWrap="wrap" useFlexGap sx={{ mt: 1 }}>
                  {result.list.map(function (it, i) {
                    const name = typeof it === 'string' ? it : it.nickname;
                    const tag = typeof it === 'string' ? null : it.role_tag;
                    return (
                      <Box key={i} sx={{ display: 'flex', alignItems: 'center', gap: 0.6 }}>
                        <Typography variant="body2">{name}</Typography>
                        {tag && <RoleTagChip tag={tag} />}
                      </Box>
                    );
                  })}
                </Stack>
              </Box>
            ) : (
              <Box>
                <Chip color="warning" label="不确定" sx={{ fontSize: 16, fontWeight: 700, px: 1.5, height: 38 }} />
                <Typography sx={{ mt: 1.5 }}>未收录，无法确认是否为我们战队的人</Typography>
                <Typography variant="caption" color="text.secondary">查询结果仅供参考</Typography>
              </Box>
            )}
          </Paper>
        )}
      </Paper>
    );
  }

  // ---------- 职责标签管理（v5.3.0：标签库增删改 + 用户自改开关） ----------
  function AdminRoleTags() {
    const [list, setList] = useState(null);
    const [userEditable, setUserEditable] = useState(true);
    const [edit, setEdit] = useState(null);   // null=未开表单；{id,name,color}=新增/编辑
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      setList(null);
      try {
        const json = await api('/api/admin/role-tags');
        if (json.code === 0) {
          setList(json.data.list || []);
          setUserEditable(!!json.data.user_editable);
        } else { setList([]); }
      } catch (e) { setList([]); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const save = async function () {
      const name = (edit.name || '').trim();
      if (!name) { toast('请填写标签名称', 'error'); return; }
      if (busy) return;
      setBusy(true);
      try {
        const json = await api('/api/admin/role-tags/save', {
          method: 'POST', body: { id: edit.id || 0, name: name, color: edit.color },
        });
        if (json.code === 0) { toast(json.msg, 'success'); setEdit(null); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    const remove = async function (t) {
      if (!window.confirm('确定删除标签「' + t.name + '」吗？已分配该标签的用户会自动清除')) return;
      try {
        const json = await api('/api/admin/role-tags/' + t.id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const toggleEditable = async function () {
      try {
        const json = await api('/api/admin/role-tags/toggle-user-edit', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); setUserEditable(!!json.data.user_editable); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Box>
        <Box sx={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 1, mb: 2 }}>
          <FormControlLabel sx={{ mr: 2 }}
            control={<Switch checked={userEditable} onChange={toggleEditable} />}
            label={<Typography variant="body2">允许用户自行修改标签</Typography>} />
          <Button variant="contained" size="small" onClick={() => setEdit({ id: 0, name: '', color: '#0f766e' })}>
            添加标签
          </Button>
        </Box>

        {edit && (
          <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }}>
              <TextField size="small" label="标签名称" value={edit.name} inputProps={{ maxLength: 16 }}
                onChange={(e) => setEdit(Object.assign({}, edit, { name: e.target.value }))} sx={{ minWidth: 180 }} />
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                <Typography variant="body2" color="text.secondary">颜色：</Typography>
                {/* 原生取色器 + 色块预览 */}
                <Box component="label" sx={{
                  width: 34, height: 34, borderRadius: '8px', cursor: 'pointer', position: 'relative',
                  bgcolor: edit.color, border: '2px solid #fff', boxShadow: '0 0 0 1px #cbd5e1',
                }}>
                  <input type="color" value={edit.color}
                    onChange={(e) => setEdit(Object.assign({}, edit, { color: e.target.value }))}
                    style={{ position: 'absolute', left: 0, top: 0, width: '100%', height: '100%', opacity: 0, cursor: 'pointer' }} />
                </Box>
                <Chip label="预览" sx={{ bgcolor: edit.color, color: '#fff', fontWeight: 600 }} />
              </Box>
              <Box sx={{ display: 'flex', gap: 1 }}>
                <Button variant="contained" size="small" disabled={busy} onClick={save}>{busy ? '保存中...' : '保存'}</Button>
                <Button size="small" onClick={() => setEdit(null)}>取消</Button>
              </Box>
            </Stack>
          </Paper>
        )}

        {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="还没有标签，点右上角添加" /> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>标签</TableCell><TableCell>颜色</TableCell>
                  <TableCell>已分配人数</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {list.map(function (t) {
                  return (
                    <TableRow key={t.id}>
                      <TableCell>
                        <RoleTagChip tag={t} sx={{ height: 24, fontSize: 12, lineHeight: '24px' }} />
                      </TableCell>
                      <TableCell><Typography variant="caption">{t.color}</Typography></TableCell>
                      <TableCell>{t.user_count} 人</TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={0.5}>
                          <Button size="small" variant="outlined" onClick={() => setEdit({ id: t.id, name: t.name, color: t.color })}>编辑</Button>
                          <Button size="small" variant="outlined" color="error" onClick={() => remove(t)}>删除</Button>
                        </Stack>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.5 }}>
          提示：在「用户管理」中给成员分配标签可多选，第一个为默认展示；用户在个人中心可自选 1 个（受上方开关控制）。
        </Typography>
      </Box>
    );
  }

  // 给用户分配职责标签（管理员多选，第一个为默认展示）
  function AssignTagsDialog({ user, onClose, onDone }) {
    const [tags, setTags] = useState([]);
    const [sel, setSel] = useState([]);   // 有序数组：第一个为默认展示
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      if (!user) return;
      // 回显：解析该用户已持有的标签 ID（保持顺序）
      let cur = [];
      try { cur = JSON.parse(user.role_tags || '[]'); } catch (e) { cur = []; }
      if (!Array.isArray(cur)) cur = [];
      setSel(cur.map(Number));
      (async function () {
        try {
          const json = await api('/api/admin/role-tags');
          setTags(json.code === 0 ? (json.data.list || []) : []);
        } catch (e) { setTags([]); }
      })();
    }, [user]);

    const toggle = function (id) {
      setSel(function (prev) {
        return prev.indexOf(id) >= 0 ? prev.filter(function (x) { return x !== id; }) : prev.concat([id]);
      });
    };

    // 点已选中的标签 = 提到最前成为默认展示（保持其余顺序）
    const setDefault = function (id) {
      setSel(function (prev) {
        if (prev.indexOf(id) < 0) return prev;
        return [id].concat(prev.filter(function (x) { return x !== id; }));
      });
    };

    const save = async function () {
      if (busy) return;
      setBusy(true);
      try {
        const json = await api('/api/admin/users/' + user.id + '/role-tags', {
          method: 'POST', body: { tag_ids: sel },
        });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Dialog open={!!user} onClose={onClose} maxWidth="sm" fullWidth>
        <DialogTitle>分配职责标签：{user ? user.nickname : ''}</DialogTitle>
        <DialogContent dividers>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            可多选；点击已选中的标签可将其提到最前成为默认展示（对外只显示默认的 1 个）。
          </Typography>
          {tags.length === 0 ? <EmptyBox text="标签库为空，请先在「职责标签」页添加标签" /> : (
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1 }}>
              {tags.map(function (t) {
                const idx = sel.indexOf(t.id);
                const checked = idx >= 0;
                return (
                  <Box key={t.id} onClick={() => (checked ? setDefault(t.id) : toggle(t.id))}
                    sx={{ cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 0.6,
                      border: '1px solid', borderColor: checked ? t.color : '#cbd5e1',
                      borderRadius: '8px', px: 1.2, py: 0.7, bgcolor: checked ? alpha(t.color, 0.08) : 'transparent' }}>
                    <Checkbox size="small" checked={checked} sx={{ p: 0.3 }} disableRipple
                      onClick={(e) => { e.stopPropagation(); toggle(t.id); }} />
                    <RoleTagChip tag={t} />
                    {idx === 0 && <Typography variant="caption" color="primary" fontWeight={600}>默认</Typography>}
                  </Box>
                );
              })}
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={save}>{busy ? '保存中...' : '保存'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 管理员权限分配（v3.9：仅超管可见） ----------
  function AdminPerms() {
    const [data, setData] = useState(null);
    const [editAdmin, setEditAdmin] = useState(null);

    const load = useCallback(async function () {
      setData(null);
      try {
        const json = await api('/api/admin/perms');
        setData(json.code === 0 ? json.data : { defs: [], admins: [] });
      } catch (e) { setData({ defs: [], admins: [] }); }
    }, []);

    useEffect(function () { load(); }, [load]);

    if (!data) return <LoadingBox />;
    const labelOf = function (key) {
      const d = data.defs.find(function (x) { return x.key === key; });
      return d ? d.label : key;
    };

    return (
      <Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          普通管理员只能使用这里勾选分配的功能，未分配的不会显示也无法使用；
          超级管理员（内置管理员与开通站点时的站长账号）恒拥有全部权限，不在此列表、不可被削减；
          给某位管理员勾选全部权限不会使其成为超级管理员，你随时可以调整或收回。
        </Alert>
        {data.admins.length === 0 ? <EmptyBox text="还没有普通管理员，可先在用户管理里点「设为管理」" /> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>QQ号</TableCell><TableCell>昵称</TableCell>
                  <TableCell>已分配权限</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.admins.map(function (a) {
                  return (
                    <TableRow key={a.id}>
                      <TableCell>{a.username}</TableCell>
                      <TableCell>{a.nickname}</TableCell>
                      <TableCell sx={{ maxWidth: 480 }}>
                        {a.perms.length === 0
                          ? <Typography variant="caption" color="text.secondary">未分配任何权限</Typography>
                          : (
                            <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                              {a.perms.map(function (p) {
                                return <Chip key={p} size="small" variant="outlined" label={labelOf(p)} />;
                              })}
                            </Stack>
                          )}
                      </TableCell>
                      <TableCell>
                        <Button size="small" variant="contained" onClick={() => setEditAdmin(a)}>分配权限</Button>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}
        <PermsDialog admin={editAdmin} defs={data.defs} onClose={() => setEditAdmin(null)}
          onDone={function () { setEditAdmin(null); load(); }} />
      </Box>
    );
  }

  // 权限多选弹窗（v3.9）
  function PermsDialog({ admin, defs, onClose, onDone }) {
    const [checked, setChecked] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      if (admin) setChecked((admin.perms || []).slice());
    }, [admin]);

    if (!admin) return null;

    const toggle = function (key) {
      setChecked(function (prev) {
        const i = prev.indexOf(key);
        if (i >= 0) {
          return prev.filter(function (k) { return k !== key; });
        }
        return prev.concat([key]);
      });
    };

    const submit = async function () {
      if (busy) return;
      setBusy(true);
      try {
        const json = await api('/api/admin/perms/save', { method: 'POST', body: { admin_id: admin.id, perms: checked } });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); }
        else { toast(json.msg, 'error'); }
      } catch (e) { toast('保存失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Dialog open={!!admin} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>分配权限 · {admin.nickname}（{admin.username}）</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 1.5 }}>
            可多选。保存后立即生效，对方刷新页面即可看到对应功能。
          </Typography>
          <Stack direction="row" spacing={1} sx={{ mb: 1 }}>
            <Button size="small" onClick={() => setChecked(defs.map(function (d) { return d.key; }))}>全选</Button>
            <Button size="small" onClick={() => setChecked([])}>清空</Button>
          </Stack>
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))' }}>
            {defs.map(function (d) {
              return (
                <FormControlLabel key={d.key} sx={{ m: 0 }}
                  control={<Checkbox size="small" checked={checked.indexOf(d.key) >= 0} onChange={() => toggle(d.key)} />}
                  label={<Typography variant="body2">{d.label}</Typography>} />
              );
            })}
          </Box>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={submit}>{busy ? '保存中...' : '保存权限'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ==================== 关于（v3.9.6.1：页面内容经 XOR+Base64 混淆，源码不出现明文） ====================
  const _A0 = 'A3dVHj59eAPUiIrQ8NrS5ta22r7Vg4nRw8oWQmwpJ1MQChADVmQaXm6574LXjL3R8d0WQmw6JkhGWV1YWm8Wi/LfpJui17u+WnkWAicxKVIQCmlNWjlVDCszYBsQ1I+qkNWxhvvBp6ubEh4UDSdYTHR9KlVGQEEMV3pVCCo2I08cU11bVzQbFjs+LE9QEk8aA3dYDyw6LgMIEtaL5L2066vx2saPoRAaWiBGAmxlYElGREJFQnobFjs+LE9QHkZZCHoWExNzYFBDEggUS2MEXXZmcRMLARAaWjtbGic8JwMIEtez9b2A16vjwseIoNW/8LGMy6n5w8Syote77ndJ';
  function _aD(s) {
    const bin = atob(s), out = [];
    for (let i = 0; i < bin.length; i++) out.push(bin.charCodeAt(i) ^ 'xU4nN_B!2026'.charCodeAt(i % 12));
    return JSON.parse(new TextDecoder('utf-8').decode(new Uint8Array(out)));
  }
  function AboutPage() {
    const d = useMemo(function () { try { return _aD(_A0); } catch (e) { return null; } }, []);
    if (!d) {
      return <Box sx={{ p: 3 }}><Alert severity="error">页面加载失败</Alert></Box>;
    }
    return (
      <Box sx={{ maxWidth: 560, mx: 'auto', p: { xs: 2, md: 3 } }}>
        <Paper sx={{ p: 3, textAlign: 'center' }}>
          <Box sx={{ width: 64, height: 64, mx: 'auto', mb: 1.5, borderRadius: '16px', bgcolor: 'primary.main', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d={BRAND_ICON} /></svg>
          </Box>
          <Typography variant="h6" fontWeight={700}>{d.app}</Typography>
          <Stack direction="row" spacing={1} justifyContent="center" sx={{ mt: 1 }}>
            <Chip label={'版本：' + d.ver} color="primary" size="small" />
            <Chip label={d.edition} variant="outlined" size="small" />
          </Stack>
        </Paper>
        <Paper sx={{ p: 2.5, mt: 2 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1 }}>作者信息</Typography>
          <Stack spacing={1.2}>
            <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 1 }}>
              <Typography variant="body2" color="text.secondary">QQ 号</Typography>
              <Typography variant="body2" fontWeight={600}>{d.qq}</Typography>
            </Box>
            <Divider />
            {d.links.map(function (it, i) {
              return (
                <Box key={i} sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 1 }}>
                  <Typography variant="body2" color="text.secondary">{it.label}</Typography>
                  <Link href={it.url} target="_blank" rel="noopener noreferrer" variant="body2" sx={{ wordBreak: 'break-all', textAlign: 'right' }}>{it.url}</Link>
                </Box>
              );
            })}
          </Stack>
        </Paper>
        <Alert severity="warning" sx={{ mt: 2 }}>{d.notice}</Alert>
      </Box>
    );
  }

  // ==================== 功能聚合页（v5.23.0：分区格子。原来 15 行一长条要滚 1.4 屏） ====================
  function FeaturesPage({ navItems }) {
    const drop = MOBILE_TABS.map(function (t) { return t[0]; });
    const groups = navGroups(navItems, drop);
    const admin = pickNav(navItems, NAV_ADMIN);
    return (
      <Box sx={{ maxWidth: 720, mx: 'auto', display: 'flex', flexDirection: 'column', gap: 1.5 }}>
        {groups.map(function (g) {
          return <NavGroupCards key={g.key} title={g.name} items={g.items} />;
        })}
        {admin.length > 0 && <NavGroupCards title="管理" items={admin} />}
        <Typography sx={{
          fontSize: 12, color: '#5f6b7f', textAlign: 'center',
          display: { xs: 'block', md: 'none' },
        }}>通知和聊天室在下方导航栏</Typography>
      </Box>
    );
  }

  function NavGroupCards({ title, items }) {
    return (
      <Paper sx={{ p: 1.5, borderRadius: '16px' }}>
        <Typography sx={{ fontSize: 13, fontWeight: 700, color: '#5f6b7f', px: 0.25, mb: 1 }}>{title}</Typography>
        <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(4, 1fr)', md: 'repeat(6, 1fr)' }, gap: 0.5 }}>
          {items.map(function (it) {
            return (
              <Box key={it[0]} component="a" href={'#/' + it[0]} sx={{
                minHeight: 74, px: 0.25, borderRadius: '12px', bgcolor: '#f5f5f7',
                display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
                gap: 0.5, textDecoration: 'none', color: 'primary.main',
              }}>
                <NavIcon d={it[2]} />
                <Typography sx={{
                  fontSize: 12, lineHeight: '15px', textAlign: 'center', color: TEXT_PRIMARY,
                  wordBreak: 'break-word',
                }}>{it[1]}</Typography>
              </Box>
            );
          })}
        </Box>
      </Paper>
    );
  }

  // ==================== v5.6.0：黑名单 / 公告回执 / 值班表 / 统一日程 / 训练赛 / 投票 ====================

  // ---------- 黑名单管理（管理端） ----------
  function AdminBlacklist() {
    const [list, setList] = useState(null);
    const [keyword, setKeyword] = useState('');
    const [searchText, setSearchText] = useState('');
    const [edit, setEdit] = useState(null);

    const load = useCallback(async function (kw) {
      const realKw = kw !== undefined ? kw : keyword;
      setList(null);
      try {
        const json = await api('/api/admin/blacklist?keyword=' + encodeURIComponent(realKw));
        setList(json.data.list);
      } catch (e) { setList([]); }
    }, [keyword]);

    useEffect(function () { load(''); }, []);  // eslint-disable-line

    const remove = async function (id) {
      if (!window.confirm('确定将该 QQ 移出黑名单吗？')) return;
      try {
        const json = await api('/api/admin/blacklist/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Box>
        <Stack direction="row" spacing={1} sx={{ mb: 2 }}>
          <TextField size="small" placeholder="搜索 QQ / 昵称 / 原因" value={searchText}
            onChange={(e) => setSearchText(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') { setKeyword(searchText.trim()); load(searchText.trim()); } }} />
          <Button variant="outlined" onClick={() => { setKeyword(searchText.trim()); load(searchText.trim()); }}>搜索</Button>
          <Box sx={{ flex: 1 }} />
          <Button variant="contained" onClick={() => setEdit({})}>添加黑名单</Button>
        </Stack>
        {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="黑名单为空" /> : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>QQ号</TableCell><TableCell>昵称</TableCell><TableCell>原因</TableCell>
                  <TableCell>添加人</TableCell><TableCell>添加时间</TableCell><TableCell>操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {list.map(function (b) {
                  return (
                    <TableRow key={b.id}>
                      <TableCell>{b.qq}</TableCell>
                      <TableCell>{b.nickname || '-'}</TableCell>
                      <TableCell>{b.reason}</TableCell>
                      <TableCell>{b.created_by || '-'}</TableCell>
                      <TableCell>{fmtTime(b.created_at)}</TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={0.8}>
                          <Button size="small" variant="outlined" onClick={() => setEdit(b)}>编辑</Button>
                          <Button size="small" variant="outlined" color="error" onClick={() => remove(b.id)}>移出</Button>
                        </Stack>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}
        <BlacklistDialog data={edit} onClose={() => setEdit(null)} onDone={function () { setEdit(null); load(); }} />
      </Box>
    );
  }

  // ==================== 战绩信息（v5.11.0） ====================
  // 数据由成员各自登录后经接口上传（一人一行、只留最新一份），站内只读，没有编辑入口。
  // 指标清单（列名/小数位/单位）由接口 fields 下发，新增指标这里不用改。
  const STAT_FILTERS = [['all', '全部成员'], ['uploaded', '已上传战绩'], ['missing', '未上传战绩']];

  function fmtStat(v, f) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (isNaN(n)) return String(v);
    return n.toFixed(f.decimals || 0) + (f.suffix || '');
  }

  // ---------- 全队战绩排行（v5.36.0） ----------
  // 四个指标四选一；成员端只算「战绩公开」的人，管理端不受该开关限制。
  // 端手分离开着时按专区筛（被按端/按人锁住的成员只看得到本端 + 不分类）。
  const RANK_FMT = {
    win_rate: [2, '%'], avg_score: [2, ''], kills_per_match: [2, ''], matches: [0, ''],
  };

  function fmtRank(v, metric) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (isNaN(n)) return String(v);
    const f = RANK_FMT[metric] || [0, ''];
    return n.toFixed(f[0]) + f[1];
  }

  function StatsRankingPanel({ admin }) {
    const gs = useGameSide();
    const [metric, setMetric] = useState('win_rate');
    const [scope, setScope] = useState(admin ? 'all' : (gs.zone || ''));
    const [data, setData] = useState(null);
    const [err, setErr] = useState('');

    const load = useCallback(async function () {
      setErr('');
      const q = new URLSearchParams({ metric: metric });
      if (gs.on || admin) { q.set('game_side', admin ? scope : (scope || 'all')); }
      try {
        const json = await api((admin ? '/api/admin/stats/ranking?' : '/api/stats/ranking?') + q.toString());
        if (json.code === 0) { setData(json.data); } else { setErr(json.msg || '加载失败'); }
      } catch (e) { setErr('加载失败'); }
    }, [metric, scope, admin, gs.on]);

    useEffect(function () { load(); }, [load]);

    const showScope = admin ? true : !!(data ? data.split : gs.on);
    const allowed = (data && data.allowed) || (gs.allowed || []);
    const scopeOpts = [['all', '全队'], ['pc', '端游专区'], ['mobile', '手游专区']].filter(function (o) {
      if (o[0] === 'all') { return admin || !showScope || allowed.length === 2; }
      return admin || allowed.indexOf(o[0]) >= 0;
    });
    const metrics = (data && data.metrics) || { win_rate: '胜率', avg_score: '场均评分', kills_per_match: '场均击杀', matches: '场次' };
    const metricKeys = ['win_rate', 'avg_score', 'kills_per_match', 'matches'];

    return (
      <Box>
        <Stack direction="row" spacing={0.8} flexWrap="wrap" useFlexGap sx={{ mb: 1.2 }}>
          {metricKeys.map(function (k) {
            return (
              <Chip key={k} clickable label={metrics[k] || k} color={metric === k ? 'primary' : 'default'}
                variant={metric === k ? 'filled' : 'outlined'}
                onClick={() => setMetric(k)} />
            );
          })}
        </Stack>
        {showScope && scopeOpts.length > 1 && (
          <Stack direction="row" spacing={0.8} flexWrap="wrap" useFlexGap sx={{ mb: 1.5 }}>
            <Typography variant="caption" color="text.secondary" sx={{ lineHeight: '32px' }}>范围</Typography>
            {scopeOpts.map(function (o) {
              return (
                <Chip key={o[0]} clickable size="small" label={o[1]} color={scope === o[0] ? 'secondary' : 'default'}
                  variant={scope === o[0] ? 'filled' : 'outlined'}
                  onClick={() => setScope(o[0])} />
              );
            })}
          </Stack>
        )}
        {err ? <EmptyBox text={err} /> : !data ? <LoadingBox /> :
          !data.list || data.list.length === 0 ? (
            <EmptyBox text={admin ? '还没有成员上传过战绩' : '还没有人公开战绩（只有本人打开「战绩公开」的成员会出现在这里）'} />
          ) : (
            <React.Fragment>
              <TableContainer>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell sx={{ width: 56 }}>名次</TableCell>
                      <TableCell>成员</TableCell>
                      {showScope && <TableCell>端别</TableCell>}
                      <TableCell align="right">{data.metric_label}</TableCell>
                      <TableCell align="right">场次</TableCell>
                      <TableCell>上传时间</TableCell>
                      {admin && <TableCell>对外公开</TableCell>}
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {data.list.map(function (r) {
                      return (
                        <TableRow key={r.user_id} hover sx={r.is_me ? { bgcolor: 'action.selected' } : undefined}>
                          <TableCell><b>{r.rank}</b></TableCell>
                          <TableCell sx={{ minWidth: 120 }}>
                            <Typography variant="body2" fontWeight={600}>
                              {r.nickname}
                              {r.is_me ? <Chip size="small" color="primary" label="我" sx={{ ml: 0.6, height: 20, fontSize: 11 }} /> : null}
                            </Typography>
                          </TableCell>
                          {showScope && <TableCell>{r.game_side ? gsLabel(r.game_side) : <Typography variant="caption" color="text.secondary">未分类</Typography>}</TableCell>}
                          <TableCell align="right" sx={{ fontWeight: 600 }}>{fmtRank(r[metric], metric)}</TableCell>
                          <TableCell align="right">{r.matches === null ? '—' : r.matches}</TableCell>
                          <TableCell sx={{ whiteSpace: 'nowrap' }}>{r.updated_at ? fmtTime(r.updated_at) : '—'}</TableCell>
                          {admin && (
                            <TableCell>
                              {Number(r.stats_public) === 1
                                ? <Chip size="small" variant="outlined" color="success" label="公开" />
                                : <Chip size="small" variant="outlined" label="不公开" />}
                            </TableCell>
                          )}
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.2 }}>
                {admin ? '含全部已上传战绩的已审核成员（不受「战绩公开」开关影响）。' : (data.note || '')}
                {data.total < 200 ? ' 共 ' + data.total + ' 人上榜。' : ' 榜单最多显示 200 人。'}
              </Typography>
            </React.Fragment>
          )}
      </Box>
    );
  }

  // ==================== 登录页配图（v5.12.0：仅站长 / 超级管理员） ====================
  function AdminPoster() {
    const [cfg, setCfg] = useState(null);
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState('');
    const [busy, setBusy] = useState(false);
    const fileRef = useRef(null);

    const load = useCallback(async function () {
      try {
        const j = await api('/api/admin/poster');
        if (j.code === 0) setCfg(j.data); else toast(j.msg || '加载失败', 'error');
      } catch (e) { toast('加载失败', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    // 选完先本地预览，确认点了「上传并应用」才真的换下来
    useEffect(function () {
      if (!file) { setPreview(''); return undefined; }
      const url = URL.createObjectURL(file);
      setPreview(url);
      return function () { URL.revokeObjectURL(url); };
    }, [file]);

    if (!cfg) return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;

    const post = async function (fd, fallbackMsg) {
      setBusy(true);
      try {
        const j = await api('/api/admin/poster', { method: 'POST', form: fd });
        if (j.code === 0) { toast(j.msg || fallbackMsg, 'success'); setFile(null); load(); }
        else toast(j.msg || '操作失败', 'error');
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };
    const doUpload = function () {
      if (!file) { toast('请先选择图片', 'error'); return; }
      const fd = new FormData();
      fd.append('file', file);
      post(fd, '登录页配图已更新');
    };
    const doReset = function () {
      const fd = new FormData();
      fd.append('reset', '1');
      post(fd, '已恢复默认配图');
    };

    const shown = preview || cfg.poster;
    return (
      <Stack spacing={2}>
        <Alert severity="info">
          这张海报出现在战队站点的 <b>登录 / 注册 / 找回密码</b> 页面（桌面端占左半屏、手机端是顶部横幅，共用一张）。
          不换就保持系统内置海报；改了只对本战队生效，成员刷新页面即看到，不需要重新登录。
        </Alert>
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <Box sx={{
              width: { xs: '100%', sm: 260 }, height: 320, flexShrink: 0, borderRadius: '12px',
              overflow: 'hidden', bgcolor: '#14181d',
              backgroundImage: 'url(' + shown + ')', backgroundSize: 'cover', backgroundPosition: 'center',
            }} />
            <Stack spacing={1.5} sx={{ flex: 1, minWidth: 0 }}>
              <Stack direction="row" spacing={1} alignItems="center">
                <Typography variant="subtitle1" fontWeight={600}>当前配图</Typography>
                <Chip size="small" color={file ? 'primary' : (cfg.custom ? 'default' : 'default')}
                  label={file ? '已选择新图，待上传' : (cfg.custom ? '已自定义' : '内置默认')} />
              </Stack>
              <Typography variant="body2" color="text.secondary">
                建议竖版、主体居中（边缘会被裁掉），1000×1400 以上更清晰；支持 jpg / png / gif / webp，
                单张不超过 5MB，上传后按平台的图片压缩设置自动压缩。
              </Typography>
              <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/gif,image/webp"
                style={{ display: 'none' }}
                onChange={function (e) { setFile(e.target.files && e.target.files[0] ? e.target.files[0] : null); }} />
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                <Button variant="outlined" onClick={function () { if (fileRef.current) fileRef.current.click(); }}>
                  {file ? '重新选择' : '选择图片'}
                </Button>
                <Button variant="contained" onClick={doUpload} disabled={busy || !file}>上传并应用</Button>
                <Button color="error" onClick={doReset} disabled={busy || !cfg.custom}>恢复默认</Button>
              </Stack>
              {file && (
                <Typography variant="caption" color="text.secondary">
                  待上传：{file.name}（{(file.size / 1024 / 1024).toFixed(2)} MB）
                </Typography>
              )}
            </Stack>
          </Stack>
        </Paper>
      </Stack>
    );
  }

  function AdminPlayerStats() {
    const [data, setData] = useState(null);
    const [searchText, setSearchText] = useState('');
    const [keyword, setKeyword] = useState('');
    const [filter, setFilter] = useState('all');
    const [sort, setSort] = useState('matches');
    const [order, setOrder] = useState('desc');
    const [page, setPage] = useState(1);
    const [view, setView] = useState('list');

    const load = useCallback(async function () {
      setData(null);
      const q = new URLSearchParams({ page: page, size: 20, keyword: keyword, filter: filter, sort: sort, order: order });
      try {
        const json = await api('/api/admin/stats?' + q.toString());
        if (json.code === 0) setData(json.data); else toast(json.msg || '加载失败', 'error');
      } catch (e) { toast('加载失败', 'error'); }
    }, [page, keyword, filter, sort, order]);

    useEffect(function () { load(); }, [load]);

    // 点同一列切换升降序，点新列默认降序（大数在前）；换排序条件回到第一页
    const clickSort = function (key) {
      if (sort === key) setOrder(order === 'desc' ? 'asc' : 'desc');
      else { setSort(key); setOrder('desc'); }
      setPage(1);
    };
    const headCell = function (key, label, extraSx) {
      const on = sort === key;
      return (
        <TableCell onClick={() => clickSort(key)} sx={Object.assign({
          cursor: 'pointer', whiteSpace: 'nowrap', userSelect: 'none',
          fontWeight: on ? 700 : 600, bgcolor: on ? 'action.hover' : 'transparent',
        }, extraSx || {})}>
          {label}{on ? (order === 'desc' ? ' ↓' : ' ↑') : ''}
        </TableCell>
      );
    };
    const search = function () { setKeyword(searchText.trim()); setPage(1); };
    const fields = (data && data.fields) || [];
    const counts = (data && data.counts) || null;

    return (
      <Box>
        <Tabs value={view} onChange={(e, v) => setView(v)} sx={{ mb: 2, borderBottom: '1px solid ' + CARD_BORDER }}>
          <Tab value="list" label="成员战绩" />
          <Tab value="rank" label="全队排行" />
        </Tabs>
        {view === 'rank' ? <StatsRankingPanel admin /> : (
        <React.Fragment>
        <Stack direction="row" spacing={1} sx={{ mb: 1.5 }} flexWrap="wrap" useFlexGap>
          <TextField size="small" placeholder="搜索昵称 / QQ / 游戏ID" value={searchText}
            onChange={(e) => setSearchText(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') search(); }} />
          <Button variant="outlined" onClick={search}>搜索</Button>
          <Select size="small" displayEmpty value={filter} sx={{ minWidth: 128 }}
            onChange={(e) => { setFilter(e.target.value); setPage(1); }}>
            {STAT_FILTERS.map(function (f) { return <MenuItem key={f[0]} value={f[0]}>{f[1]}</MenuItem>; })}
          </Select>
          <Box sx={{ flex: 1 }} />
          <Button variant="outlined" onClick={load}>刷新</Button>
        </Stack>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
          战绩由成员各自通过接口上传，本页面只读，管理员不可修改；点击任意指标列头可按该列排序。
          {counts ? ' 共 ' + counts.total + ' 名成员，已上传 ' + counts.uploaded + ' 人，未上传 ' + counts.missing + ' 人。' : ''}
        </Typography>
        {!data ? <LoadingBox /> : data.list.length === 0 ? (
          <EmptyBox text={keyword || filter !== 'all' ? '没有符合条件的成员' : '还没有成员上传战绩'} />
        ) : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  {headCell('nickname', '成员')}
                  {headCell('uploaded_at', '上传时间')}
                  {fields.map(function (f) { return <React.Fragment key={f.key}>{headCell(f.key, f.label, { textAlign: 'right' })}</React.Fragment>; })}
                </TableRow>
              </TableHead>
              <TableBody>
                {data.list.map(function (r) {
                  return (
                    <TableRow key={r.user_id} hover>
                      <TableCell sx={{ minWidth: 148 }}>
                        <Typography variant="body2" fontWeight={600}>{r.nickname}</Typography>
                        <Typography variant="caption" color="text.secondary">
                          QQ {r.username}{r.game_pid ? ' · ' + r.game_pid : ''}
                        </Typography>
                      </TableCell>
                      <TableCell sx={{ whiteSpace: 'nowrap' }}>
                        {r.has_stats ? fmtTime(r.uploaded_at) : <Chip size="small" color="warning" label="未上传" />}
                      </TableCell>
                      {fields.map(function (f) {
                        const v = r.stats[f.key];
                        return (
                          <TableCell key={f.key} align="right"
                            sx={{ whiteSpace: 'nowrap', color: v === null || v === undefined ? 'text.disabled' : 'text.primary' }}>
                            {fmtStat(v, f)}
                          </TableCell>
                        );
                      })}
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}
        {data && data.total > data.size && (
          <Pager page={data.page} size={data.size} total={data.total} onChange={setPage} />
        )}
        </React.Fragment>
        )}
      </Box>
    );
  }

  function MyStatsPage({ me }) {
    const [data, setData] = useState(null);
    const [tab, setTab] = useState('mine');

    useEffect(function () {
      (async function () {
        try {
          const json = await api('/api/stats/my');
          if (json.code === 0) setData(json.data); else toast(json.msg || '加载失败', 'error');
        } catch (e) { toast('加载失败', 'error'); }
      })();
    }, []);

    const stat = data ? data.stat : null;
    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Tabs value={tab} onChange={(e, v) => setTab(v)} sx={{ mb: 2, borderBottom: '1px solid ' + CARD_BORDER }}>
          <Tab value="mine" label="我的战绩" />
          <Tab value="rank" label="全队排行" />
        </Tabs>
        {tab === 'rank' ? <StatsRankingPanel /> : !data ? <LoadingBox /> : (
          <React.Fragment>
            <PanelHead title="我的战绩"
              desc={stat
                ? '上次上传 ' + fmtTime(data.uploaded_at) + (data.game_pid ? ' · 游戏ID ' + data.game_pid : '')
                : '还没有上传过战绩'} />
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>
              用你的账号密码登录本站后，由战绩工具调接口上传；战绩仅本人与管理员可见。
            </Typography>
            {!stat ? <EmptyBox text="还没有上传过战绩" /> : (
              <Box sx={{
                display: 'grid', gap: 1.5,
                gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', md: 'repeat(4, 1fr)' },
              }}>
                {data.fields.map(function (f) {
                  return (
                    <Paper key={f.key} variant="outlined" sx={{ p: 1.5, borderColor: CARD_BORDER }}>
                      <Typography variant="caption" color="text.secondary">{f.label}</Typography>
                      <Typography variant="h6" fontWeight={600} sx={{ lineHeight: 1.4 }}>
                        {fmtStat(stat[f.key], f)}
                      </Typography>
                    </Paper>
                  );
                })}
              </Box>
            )}
          </React.Fragment>
        )}
      </Paper>
    );
  }

  function BlacklistDialog({ data, onClose, onDone }) {
    const isEdit = !!(data && data.id);
    const [qq, setQq] = useState('');
    const [nickname, setNickname] = useState('');
    const [reason, setReason] = useState('');

    useEffect(function () {
      if (data) {
        setQq(data.qq || '');
        setNickname(data.nickname || '');
        setReason(data.reason || '');
      }
    }, [data]);

    if (!data) return null;

    const submit = async function () {
      try {
        const json = await api('/api/admin/blacklist/save', {
          method: 'POST',
          body: { id: data.id || 0, qq: qq.trim(), nickname: nickname.trim(), reason: reason.trim() },
        });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open={!!data} onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>{isEdit ? '编辑黑名单' : '添加黑名单'}</DialogTitle>
        <DialogContent>
          <TextField label="QQ号" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={qq} onChange={(e) => setQq(e.target.value)} inputProps={{ maxLength: 16 }} />
          <TextField label="昵称（选填）" fullWidth sx={{ mb: 2 }}
            value={nickname} onChange={(e) => setNickname(e.target.value)} inputProps={{ maxLength: 32 }} />
          <TextField label="拉黑原因（必填）" fullWidth required multiline minRows={2}
            value={reason} onChange={(e) => setReason(e.target.value)} inputProps={{ maxLength: 200 }} />
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>{isEdit ? '保存' : '添加'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 公告回执统计弹窗（管理端） ----------
  function ReceiptDialog({ ann, onClose }) {
    const [data, setData] = useState(null);

    useEffect(function () {
      if (!ann) return;
      (async function () {
        try {
          const json = await api('/api/admin/announcements/' + ann.id + '/receipt');
          if (json.code === 0) setData(json.data);
        } catch (e) { /* 忽略 */ }
      })();
    }, [ann]);

    const remind = async function () {
      try {
        const json = await api('/api/admin/announcements/' + ann.id + '/remind', { method: 'POST' });
        if (json.code === 0) {
          toast(json.msg, 'success');
          const j2 = await api('/api/admin/announcements/' + ann.id + '/receipt');
          if (j2.code === 0) setData(j2.data);
        } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (!ann) return null;
    return (
      <Dialog open={!!ann} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>已读回执：{ann.title}</DialogTitle>
        <DialogContent dividers>
          {!data ? <LoadingBox /> : (
            <Box>
              {ann.state && ann.state !== 'live' && (
                <Alert severity="info" sx={{ mb: 2, borderRadius: 3 }}>
                  {ann.state === 'revoked'
                    ? '这条已经撤回了，成员那边看不到。下面这份名单是撤回之前的记录，要重新发给成员请点「恢复」。'
                    : '这条还没到定时发布的时间，成员还看不到，所以未读是全员 —— 到点之前先别催读。'}
                </Alert>
              )}
              <Stack direction="row" spacing={1.5} alignItems="center" sx={{ mb: 2 }}>
                <Chip color="primary" label={'已读 ' + data.read_count + ' / ' + data.total} />
                {data.reminded_at && <Chip size="small" variant="outlined" label={'已提醒 ' + fmtTime(data.reminded_at)} />}
                {data.deadline && <Chip size="small" variant="outlined" label={'截止 ' + fmtTime(data.deadline)} />}
                <Box sx={{ flex: 1 }} />
                {data.unread_list.length > 0 && (
                  <Button size="small" variant="contained" color="warning" onClick={remind}
                    disabled={!!ann.state && ann.state !== 'live'}>一键提醒未读</Button>
                )}
              </Stack>
              <Typography variant="body2" fontWeight={600} sx={{ mb: 0.5 }}>未读名单（{data.unread_list.length}）</Typography>
              {data.unread_list.length === 0 ? <Typography variant="caption" color="text.secondary">全员已读</Typography> : (
                <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.8, mb: 2 }}>
                  {data.unread_list.map(function (u) { return <Chip key={u.id} size="small" color="warning" variant="outlined" label={u.nickname} />; })}
                </Box>
              )}
              <Typography variant="body2" fontWeight={600} sx={{ mb: 0.5 }}>已读名单（{data.read_list.length}）</Typography>
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.8 }}>
                {data.read_list.map(function (u) {
                  return <Chip key={u.id} size="small" variant="outlined"
                    label={u.nickname + (u.reminded ? '（提醒后已读）' : '')} />;
                })}
              </Box>
            </Box>
          )}
        </DialogContent>
        <DialogActions><Button onClick={onClose}>关闭</Button></DialogActions>
      </Dialog>
    );
  }

  // ---------- 值班表管理（管理端） ----------
  function AdminDuty() {
    const [data, setData] = useState(null);
    const [cycle, setCycle] = useState('day');
    const [anchor, setAnchor] = useState('');
    const [enabled, setEnabled] = useState(true);
    const [picked, setPicked] = useState([]);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/duty');
        if (json.code === 0) {
          setData(json.data);
          const cfg = json.data.config;
          setCycle(cfg ? cfg.cycle : 'day');
          setAnchor(cfg ? String(cfg.anchor_date).slice(0, 10) : '');
          setEnabled(cfg ? !!Number(cfg.enabled) : true);
          setPicked((json.data.members || []).map(function (m) { return m.user_id; }));
        }
      } catch (e) { /* 忽略 */ }
    }, []);

    useEffect(function () { load(); }, [load]);

    const saveConfig = async function () {
      try {
        const json = await api('/api/admin/duty/config', { method: 'POST', body: { cycle: cycle, anchor_date: anchor, enabled: enabled ? 1 : 0 } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const saveMembers = async function () {
      try {
        const json = await api('/api/admin/duty/members', { method: 'POST', body: { user_ids: picked } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const removeMember = async function (id) {
      try {
        const json = await api('/api/admin/duty/members/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (!data) return <LoadingBox />;
    return (
      <Stack spacing={2}>
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography fontWeight={600} sx={{ mb: 1.5 }}>值班配置（房主值班）</Typography>
          {data.zone ? (
            <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mb: 1.25 }}>
              现在编辑的是 <b>{data.zone_label}</b> 的值班表（名单里只列这一端的成员）；要改另一头，用顶部的端别切换。
            </Typography>
          ) : null}
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }}>
            <Select size="small" value={cycle} onChange={(e) => setCycle(e.target.value)} sx={{ minWidth: 120 }}>
              <MenuItem value="day">按天轮换</MenuItem>
              <MenuItem value="week">按周轮换</MenuItem>
            </Select>
            <TextField size="small" type="date" label="轮换锚点日期" InputLabelProps={{ shrink: true }}
              value={anchor} onChange={(e) => setAnchor(e.target.value)} />
            <FormControlLabel control={<Switch checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />} label="启用值班" />
            <Button variant="contained" size="small" onClick={saveConfig}>保存配置</Button>
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            锚点日期为轮值第 1 周期的起点；按周轮换时以锚点所在周的周一为起点。
          </Typography>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography fontWeight={600} sx={{ mb: 1 }}>轮值名单（勾选顺序即轮值顺序）</Typography>
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, mb: 1.5 }}>
            {(data.candidates || []).map(function (u) {
              const idx = picked.indexOf(u.id);
              const on = idx >= 0;
              return (
                <Chip key={u.id} clickable
                  color={on ? 'primary' : 'default'} variant={on ? 'filled' : 'outlined'}
                  label={(u.nickname || u.username) + (on ? '（第 ' + (idx + 1) + ' 位）' : '')}
                  onClick={function () {
                    setPicked(function (prev) {
                      return on ? prev.filter(function (x) { return x !== u.id; }) : prev.concat([u.id]);
                    });
                  }} />
              );
            })}
          </Box>
          <Button variant="contained" size="small" onClick={saveMembers}>保存名单</Button>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography fontWeight={600} sx={{ mb: 1 }}>未来 7 天轮值预览</Typography>
          {(data.preview || []).length === 0 ? <EmptyBox text="未配置值班" /> :
            data.preview.map(function (p) {
              return (
                <Box key={p.date} sx={{ display: 'flex', alignItems: 'center', gap: 1.2, py: 0.8, borderBottom: '1px solid #f1f5f9', '&:last-child': { borderBottom: 'none' } }}>
                  <Typography variant="body2" sx={{ width: 110 }}>{p.date}</Typography>
                  <Typography variant="body2" fontWeight={600}>{p.nickname || '—'}</Typography>
                  {p.swapped && <Chip size="small" color="warning" label={'与 ' + p.swapped_from + ' 换班'} />}
                </Box>
              );
            })}
        </Paper>

        {data.members && data.members.length > 0 && (
          <Paper variant="outlined" sx={{ p: 2 }}>
            <Typography fontWeight={600} sx={{ mb: 1 }}>当前名单管理</Typography>
            <TableContainer>
              <Table size="small">
                <TableHead><TableRow><TableCell>顺序</TableCell><TableCell>成员</TableCell><TableCell>操作</TableCell></TableRow></TableHead>
                <TableBody>
                  {data.members.map(function (m, i) {
                    return (
                      <TableRow key={m.id}>
                        <TableCell>{i + 1}</TableCell>
                        <TableCell>{m.nickname}</TableCell>
                        <TableCell><Button size="small" variant="outlined" color="error" onClick={() => removeMember(m.id)}>临时移除</Button></TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          </Paper>
        )}

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography fontWeight={600} sx={{ mb: 1 }}>换班记录</Typography>
          {!data.swaps || data.swaps.length === 0 ? <EmptyBox text="暂无换班记录" /> :
            data.swaps.map(function (s) {
              const stTxt = s.status === 'pending' ? '待确认' : s.status === 'accepted' ? '已对调' : '已拒绝';
              return (
                <Box key={s.id} sx={{ display: 'flex', alignItems: 'center', gap: 1.2, py: 0.8, borderBottom: '1px solid #f1f5f9', '&:last-child': { borderBottom: 'none' } }}>
                  <Typography variant="body2" sx={{ width: 110 }}>{s.duty_date}</Typography>
                  <Typography variant="body2">{s.from_name} → {s.to_name}</Typography>
                  <Chip size="small" variant="outlined" color={s.status === 'accepted' ? 'success' : s.status === 'pending' ? 'warning' : 'default'} label={stTxt} />
                </Box>
              );
            })}
        </Paper>
      </Stack>
    );
  }

  // ---------- 赛前提醒（v5.33.0，管理端）----------
  // 三类各一档提前量：关闭 / 10 / 30 / 60 分钟。装完默认都是开、提前 30 分钟，
  // 所以这一格第一句要先说「现在是在推的」，别让人以为得自己打开才生效。
  function AdminRemind() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState('');
    const [running, setRunning] = useState(false);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/remind');
        if (json.code === 0) { setD(json.data); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('取提醒设置失败', 'error'); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const save = async function (kind, lead) {
      setBusy(kind);
      try {
        const json = await api('/api/admin/remind/save', { method: 'POST', body: { kind: kind, lead: lead } });
        if (json.code === 0) {
          setD(function (prev) {
            if (!prev) { return prev; }
            const leads = Object.assign({}, prev.leads);
            leads[kind] = { name: leads[kind].name, lead: Number(json.data.lead) };
            return Object.assign({}, prev, { leads: leads });
          });
        }
        toast(json.msg, json.code === 0 ? 'success' : 'error');
      } catch (e) { toast('保存失败', 'error'); }
      setBusy('');
    };

    const runNow = async function () {
      setRunning(true);
      try {
        const json = await api('/api/admin/remind/run', { method: 'POST', body: {} });
        toast(json.msg, json.code === 0 ? 'success' : 'error');
        if (json.code === 0) { load(); }
      } catch (e) { toast('检查失败', 'error'); }
      setRunning(false);
    };

    const opts = (d && d.options && d.options.length) ? d.options : [0, 10, 30, 60];

    return (
      <Paper sx={{ p: { xs: 2, md: 3 }, mt: 2 }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>赛前提醒</Typography>
        <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.5 }}>
          训练赛、队内日程、跨队约战 到开打前会自动给本队发一条公告（要参加的人还会各收到一条通知）。
          下面是每一类提前几分钟推；选「不提醒」就这一类不再推。
        </Typography>
        {d && Number(d.platform_on) === 0 && (
          <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: 'warning.dark', mt: 0.75 }}>
            平台当前已停用赛前提醒，这里改了也先不推；要恢复得让平台后台把总开关打开。
          </Typography>
        )}
        {d && Number(d.bot_bound) === 0 && (
          <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.75 }}>
            本队还没绑 QQ 群，所以群里的机器人那一路不会发；站点里的公告与通知不受影响。
          </Typography>
        )}

        {!d && <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY, py: 2 }}>正在取…</Typography>}
        {d && (
          <Stack spacing={1.25} sx={{ mt: 1.5 }}>
            {Object.keys(d.leads).map(function (kind) {
              const one = d.leads[kind];
              return (
                <Stack key={kind} direction="row" alignItems="center"
                  sx={{ gap: 1.5, flexWrap: 'wrap', useFlexGap: true }}>
                  <Typography sx={{ fontSize: 13.5, color: TEXT_PRIMARY, minWidth: 88 }}>{one.name}</Typography>
                  <Select size="small" value={String(one.lead)} disabled={busy === kind}
                    onChange={function (e) { save(kind, e.target.value); }} sx={{ minWidth: 140 }}>
                    {opts.map(function (m) {
                      return <MenuItem key={m} value={String(m)}>
                        {Number(m) === 0 ? '不提醒' : '提前 ' + m + ' 分钟'}
                      </MenuItem>;
                    })}
                  </Select>
                  <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
                    {Number(one.lead) === 0 ? '这一类不推公告' : '到点前会推给本队，一场只推一次'}
                  </Typography>
                </Stack>
              );
            })}
            <Stack direction="row" spacing={1} alignItems="center" sx={{ flexWrap: 'wrap', gap: 1, mt: 0.5 }}>
              <Button size="small" variant="outlined" disabled={running} onClick={runNow}>现在检查一次</Button>
              <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
                只推到点的那些（你选的提前量以内），已经推过的不会重复推。
              </Typography>
            </Stack>
          </Stack>
        )}

        {d && Array.isArray(d.recent) && d.recent.length > 0 && (
          <Box sx={{ mt: 2, pt: 1.5, borderTop: '1px solid ' + CARD_BORDER }}>
            <Typography sx={{ fontSize: 13, fontWeight: 700, color: TEXT_PRIMARY, mb: 0.75 }}>最近推过的</Typography>
            {d.recent.slice(0, 8).map(function (r, i) {
              return (
                <Stack key={i} direction="row" alignItems="baseline"
                  sx={{ gap: 1, py: 0.5, flexWrap: 'wrap', useFlexGap: true }}>
                  <Chip size="small" label={r.kindName} sx={{ height: 20, fontSize: 11, bgcolor: BG_GRAY }} />
                  <Typography sx={{ fontSize: 13, color: TEXT_PRIMARY }}>{r.title}</Typography>
                  <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
                    {r.at}　提前 {r.lead} 分钟
                    {Number(r.groupOk) === 0 ? '（QQ 群没送到）' : ''}
                  </Typography>
                </Stack>
              );
            })}
          </Box>
        )}
      </Paper>
    );
  }

  // ---------- 一键导出表格（v5.34.0） ----------
  // 四张表拉成一个文件下载。能导哪几张由「站内看那张表的那把权限」决定，服务器算好了给过来，
  // 前端不另立一套判断 —— 两套口径迟早对不上，对不上就是「界面上点不动但其实能导」。
  function AdminExport() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState('');

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/export');
        if (json.code === 0) { setD(json.data); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('取可导出的表失败', 'error'); }
    }, []);

    useEffect(function () { load(); }, [load]);

    // 下载走 fetch + blob，不用 window.location.href 直接顶到那个地址：
    // ① 地址得补租户前缀（BASE），少了这一步在 /队伍slug/ 这种部署下请求打到站根、拿回来一个 401；
    // ② 服务端那句人话（没数据、没权限）得用提示条说出来，整页跳过去就是把一屏 JSON 甩在队长脸上。
    const go = async function (k, fmt) {
      const tag = k.kind + ':' + fmt;
      setBusy(tag);
      try {
        const res = await fetch(BASE + '/api/admin/export/file?kind='
          + encodeURIComponent(k.kind) + '&fmt=' + fmt, { credentials: 'same-origin' });
        const ct = res.headers.get('Content-Type') || '';
        if (res.status === 401) { location.href = BASE + '/login'; return; }
        if (!res.ok || ct.indexOf('json') >= 0) {
          const j = await res.json().catch(function () { return null; });
          toast((j && j.msg) ? j.msg : ('导出失败（HTTP ' + res.status + '）'), 'error');
          return;
        }
        const m = /filename\*=UTF-8''([^;]+)/.exec(res.headers.get('Content-Disposition') || '');
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = m ? decodeURIComponent(m[1]) : (k.kind + '.' + fmt);
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
        toast('已下载 ' + a.download, 'success');
      } catch (e) { toast('导出失败，稍后再试', 'error'); }
      setBusy('');
    };

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>导出表格</Typography>
        <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.5 }}>
          把下面这几张表拉成一个文件下载。文件里 QQ 号一律打码（前 3 后 2），
          因为文件是要发出去的、转手之后站内管不到。成员自己那个「对外展示」开关管的是访客页，不影响这里，内部照导。
          整站搬家用的导出在「数据迁移」那一页，跟这里不是一件事。
        </Typography>

        {!d && <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY, py: 2 }}>正在取…</Typography>}

        {d && (
          <Stack spacing={1} sx={{ mt: 1.75 }}>
            {d.kinds.map(function (k) {
              const none = Number(k.rows) === 0;
              return (
                <Box key={k.kind} sx={{ p: 1.25, borderRadius: 2, border: '1px solid ' + CARD_BORDER }}>
                  <Stack direction="row" alignItems="center"
                    sx={{ gap: 1, flexWrap: 'wrap', useFlexGap: true }}>
                    <Typography sx={{ fontSize: 14, fontWeight: 700, color: TEXT_PRIMARY }}>{k.label}</Typography>
                    <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY }}>
                      {none ? '现在没有数据'
                        : (k.capped ? (d.cap + ' 条以上，一次最多导 ' + d.cap + ' 行') : ('共 ' + k.rows + ' 条'))}
                    </Typography>
                    <Box sx={{ flex: '1 1 auto' }} />
                    <Button size="small" variant="outlined"
                      disabled={!k.can || none || busy !== ''} onClick={function () { go(k, 'xlsx'); }}>
                      {busy === (k.kind + ':xlsx') ? '正在生成…' : 'Excel'}
                    </Button>
                    <Button size="small" variant="text"
                      disabled={!k.can || none || busy !== ''} onClick={function () { go(k, 'csv'); }}>
                      {busy === (k.kind + ':csv') ? '正在生成…' : 'CSV'}
                    </Button>
                  </Stack>
                  {!k.can && (
                    <Typography sx={{ fontSize: 12, color: 'warning.dark', mt: 0.5 }}>
                      你没有看这张表的权限，导不了。
                    </Typography>
                  )}
                </Box>
              );
            })}
            <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY }}>
              Excel 那档打不开就换 CSV，同一份数据、同样的列。
            </Typography>
          </Stack>
        )}
      </Paper>
    );
  }

  // ---------- 社区论坛对接（v5.35.0，管理端） ----------
  // 把本队数据只读交给他自己另一个项目的社区论坛。三道闸都在服务器算：本队开关 + 平台总闸 + 平台按站点停用，
  // 前端只照抄结果 —— 界面自己判一道就会出现「写着开着、其实读不到」那种事。
  function AdminForumApi() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/forum-api');
        if (json.code === 0) { setD(json.data); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('取论坛对接状态失败', 'error'); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const toggle = async function (on) {
      setBusy(true);
      try {
        const json = await api('/api/admin/forum-api/toggle', { method: 'POST', body: { on: on ? '1' : '0' } });
        if (json.code === 0) {
          setD(function (prev) {
            return prev ? Object.assign({}, prev, {
              on: !!json.data.on, serving: !!json.data.serving, reason: String(json.data.reason || '')
            }) : prev;
          });
        }
        toast(json.msg, json.code === 0 ? 'success' : 'error');
      } catch (e) { toast('保存失败', 'error'); }
      setBusy(false);
    };

    // 四条开放接口挂在站点根上（不带队伍目录）：私有接口通道只受理根路径，租户目录下同路径是 404
    const origin = location.origin;
    const sample = 'curl -H "X-Api-Key: 你的密钥" ' + origin + '/api/open/forum/members';

    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>社区论坛对接</Typography>
        <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.5 }}>
          打开后，你自己的社区论坛可以用本队的接口密钥只读拉这几样：成员名单与公开战绩、
          近 30 天活动与签到榜、对外展示页勾选过的公告、战队名片与招募文案。
          论坛那边只能读，写不进本站；这一版也没有免登录跳转。
        </Typography>

        {!d && <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY, py: 2 }}>正在取…</Typography>}

        {d && (
          <Stack spacing={1.5} sx={{ mt: 1.75 }}>
            <Stack direction="row" alignItems="center" sx={{ gap: 1.5, flexWrap: 'wrap', useFlexGap: true }}>
              <FormControlLabel control={
                <Switch checked={!!d.on} disabled={busy} onChange={function (e) { toggle(e.target.checked); }} />
              } label={d.on ? '已打开' : '未打开'} />
              <Chip size="small" label={d.serving ? '论坛现在读得到' : '论坛读不到'}
                sx={{ height: 22, fontSize: 11.5, bgcolor: d.serving ? 'success.main' : BG_GRAY,
                  color: d.serving ? '#fff' : TEXT_PRIMARY }} />
            </Stack>
            {d.reason !== '' && (
              <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: 'warning.dark' }}>
                {d.reason}
              </Typography>
            )}

            <Box sx={{ p: 1.25, borderRadius: 2, border: '1px solid ' + CARD_BORDER }}>
              <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: TEXT_PRIMARY, mb: 0.75 }}>
                四条接口地址（都挂在站点根上，不带队伍目录）
              </Typography>
              {(d.endpoints || []).map(function (e) {
                return (
                  <Stack key={e[0]} direction="row" alignItems="baseline"
                    sx={{ gap: 1, py: 0.35, flexWrap: 'wrap', useFlexGap: true }}>
                    <Typography sx={{ fontSize: 12.5, color: TEXT_PRIMARY, fontFamily: 'ui-monospace, Menlo, monospace',
                      wordBreak: 'break-all' }}>{origin + e[0]}</Typography>
                    <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>{e[1]}</Typography>
                  </Stack>
                );
              })}
              <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 0.75, flexWrap: 'wrap', gap: 1 }}>
                <Button size="small" variant="outlined" onClick={function () { copyText(sample); }}>复制调用示例</Button>
                <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
                  请求头带 X-Api-Key，就是下面那一格里的密钥；只有 GET，没有写接口。
                </Typography>
              </Stack>
            </Box>

            <Box sx={{ p: 1.25, borderRadius: 2, border: '1px solid ' + CARD_BORDER }}>
              <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: TEXT_PRIMARY, mb: 0.5 }}>
                用的是哪把 Key
              </Typography>
              <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mb: 0.75 }}>
                不另发一枚：就是「API 密钥」那一格里本队的 Key（库里只存哈希、每把限频、失败多了会锁来源 IP）。
                代价说清楚：同一把 Key 也能读本队的约战接口，所以别把它交给这支队之外的第三方。
              </Typography>
              {d.key_count === 0 && (
                <Typography sx={{ fontSize: 12.5, color: 'warning.dark' }}>
                  本队还没有任何 Key：去「API 密钥」那一格生成一枚，再回到这里打开开关。
                </Typography>
              )}
              {(d.keys || []).map(function (k) {
                return (
                  <Stack key={k.id} direction="row" alignItems="baseline"
                    sx={{ gap: 1, py: 0.35, flexWrap: 'wrap', useFlexGap: true }}>
                    <Chip size="small" label={Number(k.status) === 1 ? '启用' : '已停用'}
                      sx={{ height: 20, fontSize: 11, bgcolor: BG_GRAY }} />
                    <Typography sx={{ fontSize: 12.5, color: TEXT_PRIMARY }}>{k.name}</Typography>
                    <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY,
                      fontFamily: 'ui-monospace, Menlo, monospace' }}>{k.key_prefix}…</Typography>
                    <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
                      {k.last_used_at ? ('最后使用 ' + k.last_used_at) : '还没用过'}
                    </Typography>
                  </Stack>
                );
              })}
            </Box>

            <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY }}>
              给出去的只有本人同意对外展示的成员（战绩再按各自那一档），QQ 一律打码。
              认人请用 uid（本站内部号，稳定不变）——打码那串同队会撞，不能当账号绑。
              名单最多 {d.caps.members} 人，活动看的是最近 {d.caps.window_days} 天到未来 {d.caps.window_days} 天。
            </Typography>

            <Box sx={{ pt: 1, borderTop: '1px solid ' + CARD_BORDER }}>
              <Typography sx={{ fontSize: 13, fontWeight: 700, color: TEXT_PRIMARY, mb: 0.5 }}>
                最近谁来调过
              </Typography>
              {(d.recent || []).length === 0 && (
                <Typography sx={{ fontSize: 12.5, color: TEXT_SECONDARY }}>还没有调用记录。</Typography>
              )}
              {(d.recent || []).map(function (r, i) {
                return (
                  <Stack key={i} direction="row" alignItems="baseline"
                    sx={{ gap: 1, py: 0.3, flexWrap: 'wrap', useFlexGap: true }}>
                    <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>{r.created_at}</Typography>
                    <Typography sx={{ fontSize: 12.5, color: TEXT_PRIMARY,
                      fontFamily: 'ui-monospace, Menlo, monospace' }}>{r.path}</Typography>
                    <Typography sx={{ fontSize: 12, color: Number(r.result) === 1 ? 'success.main' : 'error.main' }}>
                      {Number(r.result) === 1 ? ('读到了 ' + r.status) : ('没读到：' + (r.fail_reason || r.status))}
                    </Typography>
                    <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>{r.ip}</Typography>
                  </Stack>
                );
              })}
            </Box>
          </Stack>
        )}
      </Paper>
    );
  }

  // ---------- 日程自定义活动管理（管理端） ----------
  // ---------- 端手游分离（v5.36.0，仅队长） ----------
  function AdminGameSide({ onChanged }) {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/admin/game-side');
        if (j.code === 0) setD(j.data); else toast(j.msg, 'error');
      } catch (e) { toast('读不到端手游设置', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);
    const save = async function (patch) {
      if (busy) return;
      setBusy(true);
      try {
        const j = await api('/api/admin/game-side/save', { method: 'POST', body: patch });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
          // 顶栏的专区切换与各页的模块开关都读 me.game_side，保存后要重取一次才即时生效
          if (onChanged) { try { await onChanged(); } catch (e) { /* 忽略 */ } }
        }
      } catch (e) { toast('保存失败', 'error'); }
      setBusy(false);
    };
    const toggleModule = function (k, on) {
      const m = Object.assign({}, (d && d.modules) || {});
      m[k] = on ? 1 : 0;
      save({ modules: m });
    };
    return (
      <Paper sx={{ p: { xs: 2, md: 3 } }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>端手游分离</Typography>
        <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: TEXT_SECONDARY, mt: 0.5 }}>
          给同时有端游和手游队员的战队用：每位成员必须选端游或手游（没有「不分类」），
          打开后公告、撞车、训练赛、投票、自建房、小队、值班、日常活动可以各自决定分不分端。
          默认关闭，关闭时一切跟现在一样。
        </Typography>

        {!d && <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY, py: 2 }}>正在取…</Typography>}
        {d && (
          <Box sx={{ mt: 1 }}>
            <FormControlLabel
              control={<Switch checked={!!d.on} disabled={busy}
                onChange={function (e) { save({ on: e.target.checked ? 1 : 0 }); }} />}
              label={<Typography sx={{ fontSize: 13.5, color: TEXT_PRIMARY, fontWeight: 600 }}>
                {d.on ? '已打开：成员进站会看到「手游 / 端游」专区切换' : '未打开：全队共用一套内容'}
              </Typography>} />

            <Stack direction="row" spacing={1} sx={{ mt: 0.5, flexWrap: 'wrap', useFlexGap: true }}>
              <Chip size="small" label={'端游 ' + d.counts.pc + ' 人'} sx={{ bgcolor: BG_GRAY }} />
              <Chip size="small" label={'手游 ' + d.counts.mobile + ' 人'} sx={{ bgcolor: BG_GRAY }} />
              <Chip size="small" color={d.counts.none > 0 ? 'warning' : 'default'} variant={d.counts.none > 0 ? 'outlined' : 'filled'}
                label={'未分类 ' + d.counts.none + ' 人'} sx={d.counts.none > 0 ? {} : { bgcolor: BG_GRAY }} />
            </Stack>
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 0.75 }}>
              成员属于哪一端在「成员管理 → 端别」里选，必须选端游或手游；没有选的人会挡住总开关。
            </Typography>
            {d.counts.none > 0 && (
              <Typography sx={{ fontSize: 12.5, lineHeight: '19px', color: 'warning.dark', mt: 0.75 }}>
                {d.on
                  ? '还有 ' + d.counts.none + ' 位成员没选端游/手游：他们会同时出现在两个专区，请尽快去「成员管理」分好。'
                  : '还有 ' + d.counts.none + ' 位成员没选端游/手游。先把他们分好端，再回来打开。'}
              </Typography>
            )}

            {d.on && (
              <Box sx={{ mt: 2, pt: 2, borderTop: '1px solid ' + CARD_BORDER }}>
                <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: TEXT_PRIMARY, mb: 0.5 }}>哪几块分端</Typography>
                <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY, mb: 1 }}>
                  关掉的那一块仍然混在一起显示（等同「不分端」）；旧内容没有端别，算全队共享，两个专区都看得到。
                </Typography>
                {Object.keys(d.labels).map(function (k) {
                  return (
                    <FormControlLabel key={k} sx={{ display: 'flex', mr: 0, mb: 0.25 }}
                      control={<Switch size="small" checked={!!d.modules[k]} disabled={busy}
                        onChange={function (e) { toggleModule(k, e.target.checked); }} />}
                      label={<Typography sx={{ fontSize: 13, color: TEXT_PRIMARY }}>{d.labels[k]}</Typography>} />
                  );
                })}

                <Box sx={{ mt: 2, pt: 2, borderTop: '1px solid ' + CARD_BORDER }}>
                  <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: TEXT_PRIMARY, mb: 0.5 }}>跨端可见（按端默认）</Typography>
                  <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY, mb: 1 }}>
                    默认两端都能看；打开下面某一条，那一端的成员就只能看自己这边。个别人要例外，去成员管理里单独设。
                  </Typography>
                  <FormControlLabel sx={{ display: 'flex', mr: 0, mb: 0.25 }}
                    control={<Switch size="small" checked={!!d.locks.mobile} disabled={busy}
                      onChange={function (e) { save({ lock_mobile: e.target.checked ? 1 : 0 }); }} />}
                    label={<Typography sx={{ fontSize: 13, color: TEXT_PRIMARY }}>只限手机端，不能看端游</Typography>} />
                  <FormControlLabel sx={{ display: 'flex', mr: 0 }}
                    control={<Switch size="small" checked={!!d.locks.pc} disabled={busy}
                      onChange={function (e) { save({ lock_pc: e.target.checked ? 1 : 0 }); }} />}
                    label={<Typography sx={{ fontSize: 13, color: TEXT_PRIMARY }}>只限端游，不能看手机端</Typography>} />
                </Box>
              </Box>
            )}

            <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY, mt: 2 }}>{d.note}</Typography>
          </Box>
        )}
      </Paper>
    );
  }

  function AdminScheduleEvents() {
    const [list, setList] = useState(null);
    const [hidden, setHidden] = useState([]);
    const [edit, setEdit] = useState(null);
    const CATS = [
      ['race', '撞车比赛'], ['custom_race', '自建房比赛'], ['battle', '约战'],
      ['tournament', '赛事'], ['custom', '自定义活动'], ['duty', '值班'],
    ];

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/schedule/events');
        if (json.code === 0) {
          setList(json.data.list);
          setHidden(json.data.hidden || []);
        }
      } catch (e) { setList([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const removeEvent = async function (id) {
      if (!window.confirm('确定删除该活动吗？')) return;
      try {
        const json = await api('/api/admin/schedule/events/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const toggleCat = async function (cat) {
      const next = hidden.indexOf(cat) >= 0 ? hidden.filter(function (c) { return c !== cat; }) : hidden.concat([cat]);
      try {
        const json = await api('/api/admin/schedule/hidden', { method: 'POST', body: { hidden: next.join(',') } });
        if (json.code === 0) { setHidden(next); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Stack spacing={2}>
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Typography fontWeight={600} sx={{ mb: 1 }}>日程类别显隐（一键隐藏某类活动）</Typography>
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1 }}>
            {CATS.map(function (c) {
              const off = hidden.indexOf(c[0]) >= 0;
              return (
                <Chip key={c[0]} clickable color={off ? 'default' : 'primary'} variant={off ? 'outlined' : 'filled'}
                  label={c[1] + (off ? '（已隐藏）' : '')} onClick={() => toggleCat(c[0])} />
              );
            })}
          </Box>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction="row" alignItems="center" sx={{ mb: 1.5 }}>
            <Typography fontWeight={600} sx={{ flex: 1 }}>自定义活动</Typography>
            <Button variant="contained" size="small" onClick={() => setEdit({})}>新增活动</Button>
          </Stack>
          {list === null ? <LoadingBox /> : list.length === 0 ? <EmptyBox text="暂无自定义活动" /> :
            list.map(function (ev) {
              return (
                <Box key={ev.id} sx={{ display: 'flex', alignItems: 'center', gap: 1.2, py: 1, borderBottom: '1px solid #f1f5f9', '&:last-child': { borderBottom: 'none' } }}>
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography variant="body2" fontWeight={600}>
                      {ev.title}
                      {ev.game_side ? <Chip size="small" label={gsLabel(ev.game_side)} sx={{ ml: 0.8, height: 20, fontSize: 11 }} /> : null}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      {fmtTime(ev.start_time)} · {ev.duration_min} 分钟{ev.note ? ' · ' + ev.note : ''}
                    </Typography>
                  </Box>
                  <Button size="small" variant="outlined" onClick={() => setEdit(ev)}>编辑</Button>
                  <Button size="small" variant="outlined" color="error" onClick={() => removeEvent(ev.id)}>删除</Button>
                </Box>
              );
            })}
        </Paper>
        <EventDialog data={edit} onClose={() => setEdit(null)} onDone={function () { setEdit(null); load(); }} />
      </Stack>
    );
  }

  function EventDialog({ data, onClose, onDone }) {
    const isEdit = !!(data && data.id);
    const gs = useGameSide();
    const split = !!(gs.on && gs.modules.event);
    const [title, setTitle] = useState('');
    const [start, setStart] = useState('');
    const [minutes, setMinutes] = useState(60);
    const [note, setNote] = useState('');
    const [side, setSide] = useState('');

    useEffect(function () {
      if (data) {
        setTitle(data.title || '');
        setStart(data.start_time ? String(data.start_time).slice(0, 16).replace(' ', 'T') : '');
        setMinutes(data.duration_min || 60);
        setNote(data.note || '');
        setSide(data.game_side || (gs.zone || ''));
      }
    }, [data]);

    if (!data) return null;

    const submit = async function () {
      try {
        const json = await api('/api/admin/schedule/events/save', {
          method: 'POST',
          body: { id: data.id || 0, title: title.trim(), start_time: start.replace('T', ' '), duration_min: Number(minutes) || 60, note: note.trim(), game_side: split ? side : '' },
        });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open={!!data} onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>{isEdit ? '编辑活动' : '新增活动'}</DialogTitle>
        <DialogContent>
          <TextField label="活动标题" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={title} onChange={(e) => setTitle(e.target.value)} inputProps={{ maxLength: 128 }} />
          <TextField label="开始时间" fullWidth required type="datetime-local" InputLabelProps={{ shrink: true }} sx={{ mb: 2 }}
            value={start} onChange={(e) => setStart(e.target.value)} />
          <TextField label="时长（分钟）" fullWidth type="number" sx={{ mb: 2 }}
            value={minutes} onChange={(e) => setMinutes(e.target.value)} />
          <TextField label="说明（地点/房间号/备注，选填）" fullWidth multiline minRows={2}
            value={note} onChange={(e) => setNote(e.target.value)} inputProps={{ maxLength: 500 }} />
          {split && (
            <TextField select fullWidth label="活动端别" sx={{ mt: 2 }} value={side} onChange={(e) => setSide(e.target.value)}
              helperText="全队共享＝两种端都能看到；选了某一端就只给那一边">
              <MenuItem value="">全队共享</MenuItem>
              <MenuItem value="pc">端游专区</MenuItem>
              <MenuItem value="mobile">手游专区</MenuItem>
            </TextField>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>{isEdit ? '保存' : '添加'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 比赛日集合点名（管理端，v5.36.34） ----------
  // 开一张单（几点集合、几点截止、可挂一场撞车）；到点前 15 分钟系统顺路往 QQ 群喊一次，
  // 队长也能按「再喊一次」手动补。撤回只是收起来（谁到过仍然留着），随时能恢复。
  function AdminRollCall() {
    const [d, setD] = useState(null);
    const [edit, setEdit] = useState(null);
    const [busy, setBusy] = useState('');
    const [who, setWho] = useState(0);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/rollcall');
        if (json.code === 0) setD(json.data);
      } catch (e) { setD({ list: [], events: [] }); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const act = async function (id, verb, body) {
      setBusy(id + verb);
      try {
        const json = await api('/api/admin/rollcall/' + id + '/' + verb, { method: 'POST', body: body || {} });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(''); }
    };
    const del = async function (c) {
      if (!window.confirm('删除「' + c.title + '」？成员那份应答也会一起删掉。')) return;
      setBusy(c.id + 'del');
      try {
        const json = await api('/api/admin/rollcall/' + c.id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(''); }
    };

    if (!d) return <Paper sx={{ p: 3 }}><LoadingBox /></Paper>;

    return (
      <Stack spacing={2}>
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 1 }}>
            <Typography fontWeight={600} sx={{ flex: 1 }}>集合点名单</Typography>
            <Button size="small" onClick={load}>刷新</Button>
            <Button variant="contained" size="small" onClick={() => setEdit({})}>开一张点名单</Button>
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            集合前 {d.lead_min} 分钟，系统会顺路在本队 QQ 群里喊一声（这一队没绑群就只留在站内）。
            一张单只自动喊一次；群里没送到不影响成员在首页点名。
          </Typography>
          {d.list.length === 0 ? <EmptyBox text="还没开过点名单" /> :
            d.list.map(function (c) {
              const showList = who === c.id;
              const st = c.state === 'revoked' ? ['已撤回', 'default']
                : c.closed ? ['已截止', 'default']
                  : c.state === 'upcoming' ? ['待集合', 'primary'] : ['正在集合', 'success'];
              return (
                <Box key={c.id} sx={{ py: 1.2, borderTop: '1px solid #f1f5f9' }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
                    <Typography variant="body2" fontWeight={600} sx={{ minWidth: 0 }}>
                      {c.title}
                      {c.game_side ? <Chip size="small" label={gsLabel(c.game_side)} sx={{ ml: 0.8, height: 20, fontSize: 11 }} /> : null}
                    </Typography>
                    <Chip size="small" color={st[1]} label={st[0]} sx={{ height: 20, fontSize: 11 }} />
                    {c.notify_at ? <Chip size="small" variant="outlined" label="群里喊过了" sx={{ height: 20, fontSize: 11 }} /> : null}
                    <Box sx={{ flex: 1 }} />
                    <Button size="small" variant="outlined" disabled={busy !== ''}
                      onClick={() => setEdit(Object.assign({}, c))}>编辑</Button>
                    {c.state === 'revoked'
                      ? <Button size="small" variant="outlined" disabled={busy !== ''} onClick={() => act(c.id, 'restore')}>恢复</Button>
                      : <Button size="small" variant="outlined" disabled={busy !== ''} onClick={() => act(c.id, 'revoke')}>撤回</Button>}
                    <Button size="small" variant="outlined" disabled={busy !== '' || c.state === 'revoked'}
                      onClick={() => act(c.id, 'broadcast')}>再喊一次</Button>
                    <Button size="small" variant="outlined" color="error" disabled={busy !== ''} onClick={() => del(c)}>删除</Button>
                  </Box>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.3 }}>
                    集合 {fmtTime(c.call_at)}{c.deadline ? (' · 截至 ' + fmtTime(c.deadline)) : ''} · 共 {c.total} 人
                    {' · 到 ' + c.counts.in + ' / 迟到 ' + c.counts.late + ' / 不来 ' + c.counts.out + ' / 没回 ' + c.counts.none}
                    {' · '}{c.event_id ? ('挂了场次 #' + c.event_id) : '没挂场次'}
                  </Typography>
                  <Box sx={{ mt: 0.8, display: 'flex', gap: 1, flexWrap: 'wrap', alignItems: 'center' }}>
                    {['in', 'late', 'out', 'none'].map(function (g) {
                      const n = c.counts[g];
                      if (!n) return null;
                      const cc = RC_COLORS[g] || { color: TEXT_SECONDARY, bg: BG_GRAY };
                      return (
                        <Chip key={g} size="small" label={RC_LABEL[g] + ' ' + n}
                          onClick={() => setWho(showList ? 0 : c.id)}
                          sx={{ height: 22, fontSize: 11, bgcolor: cc.bg, color: cc.color, fontWeight: 600 }} />
                      );
                    })}
                    <Button size="small" color="inherit" onClick={() => setWho(showList ? 0 : c.id)}>
                      {showList ? '收起名单' : '看名单'}
                    </Button>
                  </Box>
                  {showList && (
                    <Box sx={{ mt: 0.8, p: 1.2, bgcolor: BG_GRAY, borderRadius: '10px' }}>
                      {['in', 'late', 'out', 'none'].map(function (g) {
                        const arr = g === 'in' ? c.list_in : g === 'late' ? c.list_late
                          : g === 'out' ? c.list_out : c.list_none;
                        if (arr.length === 0) return null;
                        return (
                          <Box key={g} sx={{ display: 'flex', gap: 1, alignItems: 'baseline', py: 0.2, flexWrap: 'wrap' }}>
                            <Typography variant="caption" fontWeight={700} sx={{ width: 46, flexShrink: 0, color: (RC_COLORS[g] || { color: TEXT_SECONDARY }).color }}>
                              {RC_LABEL[g]}
                            </Typography>
                            <Typography variant="caption" sx={{ color: '#5f6b7f', minWidth: 0 }}>
                              {arr.map(function (m) { return m.nickname + (m.note ? '（' + m.note + '）' : ''); }).join('、')}
                            </Typography>
                          </Box>
                        );
                      })}
                    </Box>
                  )}
                </Box>
              );
            })}
        </Paper>
        <RcDialog data={edit} events={d.events || []} onClose={() => setEdit(null)}
          onDone={function () { setEdit(null); load(); }} />
      </Stack>
    );
  }

  function RcDialog({ data, events, onClose, onDone }) {
    const isEdit = !!(data && data.id);
    const gs = useGameSide();
    const split = !!(gs.on && gs.modules.race);
    const [title, setTitle] = useState('');
    const [callAt, setCallAt] = useState('');
    const [dl, setDl] = useState('');
    const [eventId, setEventId] = useState('');
    const [side, setSide] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(function () {
      if (data) {
        setTitle(data.title || '');
        setCallAt(data.call_at ? String(data.call_at).slice(0, 16).replace(' ', 'T') : '');
        setDl(data.deadline ? String(data.deadline).slice(0, 16).replace(' ', 'T') : '');
        setEventId(data.event_id ? String(data.event_id) : '');
        setSide(data.game_side !== undefined ? (data.game_side || '') : (gs.zone || ''));
      }
    }, [data]);

    if (!data) return null;

    const submit = async function () {
      if (!title.trim() || !callAt) { toast('请填写标题和集合时间', 'warning'); return; }
      setBusy(true);
      try {
        const json = await api('/api/admin/rollcall/save', {
          method: 'POST',
          body: {
            id: data.id || 0, title: title.trim(), call_at: callAt.replace('T', ' '),
            deadline: dl ? dl.replace('T', ' ') : '', event_id: Number(eventId) || 0,
            game_side: split ? side : '',
          },
        });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Dialog open={!!data} onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>{isEdit ? '编辑点名单' : '开一张点名单'}</DialogTitle>
        <DialogContent>
          <TextField label="标题" fullWidth required sx={{ mt: 1, mb: 2 }} value={title}
            onChange={(e) => setTitle(e.target.value)} inputProps={{ maxLength: 128 }}
            helperText="比如「今晚八点训练赛集合」" />
          <TextField label="集合时间" fullWidth required type="datetime-local" InputLabelProps={{ shrink: true }}
            sx={{ mb: 2 }} value={callAt} onChange={(e) => setCallAt(e.target.value)} />
          <TextField label="点名截止（选填，空着就一直能点）" fullWidth type="datetime-local"
            InputLabelProps={{ shrink: true }} sx={{ mb: 2 }} value={dl} onChange={(e) => setDl(e.target.value)} />
          <InputLabel shrink>挂哪场比赛（选填）</InputLabel>
          <Select fullWidth size="small" value={eventId} displayEmpty onChange={(e) => setEventId(e.target.value)}>
            <MenuItem value="">不挂场次</MenuItem>
            {events.map(function (ev) {
              return <MenuItem key={ev.id} value={String(ev.id)}>{ev.title} · {fmtTime(ev.race_time)}</MenuItem>;
            })}
          </Select>
          {split && (
            <TextField select fullWidth label="点名单端别" sx={{ mt: 2 }} value={side} onChange={(e) => setSide(e.target.value)}
              helperText="全队共享＝两种端都能看到；选了某一端就只给那一边">
              <MenuItem value="">全队共享</MenuItem>
              <MenuItem value="pc">端游专区</MenuItem>
              <MenuItem value="mobile">手游专区</MenuItem>
            </TextField>
          )}
          <Alert severity="info" sx={{ mt: 2, fontSize: 12.5 }}>
            改集合时间会重排 QQ 群那一次通知；已经喊过的这张，改完到点前会再喊一次。
          </Alert>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" disabled={busy} onClick={submit}>{isEdit ? '保存' : '开单'}</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 投票管理（管理端） ----------
  function AdminVotes() {
    const [open, setOpen] = useState(null);
    const [closed, setClosed] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [detail, setDetail] = useState(null);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/votes');
        if (json.code === 0) {
          setOpen(json.data.open);
          setClosed(json.data.closed);
        }
      } catch (e) { setOpen([]); setClosed([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const closeVote = async function (id) {
      if (!window.confirm('确定提前结束该投票吗？')) return;
      try {
        const json = await api('/api/admin/votes/' + id + '/close', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const removeVote = async function (id) {
      if (!window.confirm('删除后投票数据不可恢复，确定吗？')) return;
      try {
        const json = await api('/api/admin/votes/' + id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const voteCard = function (v) {
      return (
        <Paper key={v.id} variant="outlined" sx={{ p: 2, mb: 1.5 }}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.6 }}>
            <Typography fontWeight={600} sx={{ flex: 1 }}>{v.title}</Typography>
            {v.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(v.game_side)} /> : null}
            {v.multi ? <Chip size="small" variant="outlined" label={'多选≤' + v.max_choices} /> : <Chip size="small" variant="outlined" label="单选" />}
            {Number(v.anonymous) === 1 && <Chip size="small" variant="outlined" label="匿名" />}
            {v.status === 'open' ? <Chip size="small" color="success" label="进行中" /> : <Chip size="small" label="已结束" />}
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
            截止 {fmtTime(v.deadline)}{Number(v.allow_add_option) === 1 ? ' · 允许补充选项' : ''}
          </Typography>
          <Stack direction="row" spacing={0.8} sx={{ mt: 1.2 }}>
            <Button size="small" variant="outlined" onClick={function () { setDetail(v.id); }}>查看结果</Button>
            {v.status === 'open' && <Button size="small" variant="outlined" color="warning" onClick={() => closeVote(v.id)}>提前结束</Button>}
            <Button size="small" variant="outlined" color="error" onClick={() => removeVote(v.id)}>删除</Button>
          </Stack>
        </Paper>
      );
    };

    return (
      <Box>
        <Button variant="contained" sx={{ mb: 2 }} onClick={() => setCreateOpen(true)}>创建投票</Button>
        <Typography fontWeight={600} sx={{ mb: 1 }}>进行中</Typography>
        {open === null ? <LoadingBox /> : open.length === 0 ? <EmptyBox text="暂无进行中的投票" /> : open.map(voteCard)}
        <Typography fontWeight={600} sx={{ mb: 1, mt: 2 }}>已结束（归档留痕）</Typography>
        {closed === null ? <LoadingBox /> : closed.length === 0 ? <EmptyBox text="暂无已结束的投票" /> : closed.map(voteCard)}
        <VoteCreateDialog open={createOpen} onClose={() => setCreateOpen(false)} onDone={function () { setCreateOpen(false); load(); }} />
        <VoteDetailDialog voteId={detail} onClose={() => setDetail(null)} />
      </Box>
    );
  }

  function VoteCreateDialog({ open, onClose, onDone }) {
    const gs = useGameSide();
    const split = !!(gs.on && gs.modules.vote);
    const [title, setTitle] = useState('');
    const [options, setOptions] = useState(['', '']);
    const [multi, setMulti] = useState(false);
    const [maxChoices, setMaxChoices] = useState(2);
    const [anon, setAnon] = useState(false);
    const [allowAdd, setAllowAdd] = useState(false);
    const [deadline, setDeadline] = useState('');
    const [side, setSide] = useState('');

    useEffect(function () {
      if (open && split) { setSide(gs.zone || ''); }
    }, [open]);

    const submit = async function () {
      try {
        const json = await api('/api/admin/votes/create', {
          method: 'POST',
          body: {
            title: title.trim(),
            options: options.map(function (o) { return o.trim(); }),
            multi: multi ? 1 : 0,
            max_choices: Number(maxChoices) || 2,
            anonymous: anon ? 1 : 0,
            allow_add_option: allowAdd ? 1 : 0,
            deadline: deadline.replace('T', ' '),
            game_side: split ? side : '',
          },
        });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>创建投票</DialogTitle>
        <DialogContent>
          <TextField label="投票标题" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={title} onChange={(e) => setTitle(e.target.value)} inputProps={{ maxLength: 128 }} />
          {options.map(function (o, i) {
            return (
              <Stack key={i} direction="row" spacing={1} sx={{ mb: 1.2 }}>
                <TextField size="small" fullWidth label={'选项 ' + (i + 1)} value={o}
                  onChange={(e) => setOptions(function (prev) { const n = prev.slice(); n[i] = e.target.value; return n; })}
                  inputProps={{ maxLength: 128 }} />
                {options.length > 2 && (
                  <IconButton size="small" onClick={() => setOptions(function (prev) { return prev.filter(function (_, j) { return j !== i; }); })}>×</IconButton>
                )}
              </Stack>
            );
          })}
          {options.length < 10 && (
            <Button size="small" onClick={() => setOptions(function (prev) { return prev.concat(['']); })}>+ 添加选项</Button>
          )}
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ mt: 2 }} alignItems={{ sm: 'center' }}>
            <FormControlLabel control={<Switch checked={multi} onChange={(e) => setMulti(e.target.checked)} />} label="多选" />
            {multi && (
              <TextField size="small" type="number" label="限选数" value={maxChoices}
                onChange={(e) => setMaxChoices(e.target.value)} sx={{ width: 100 }} />
            )}
            <FormControlLabel control={<Switch checked={anon} onChange={(e) => setAnon(e.target.checked)} />} label="匿名" />
            <FormControlLabel control={<Switch checked={allowAdd} onChange={(e) => setAllowAdd(e.target.checked)} />} label="允许补充选项" />
          </Stack>
          <TextField label="截止时间" fullWidth required type="datetime-local" InputLabelProps={{ shrink: true }} sx={{ mt: 2 }}
            value={deadline} onChange={(e) => setDeadline(e.target.value)} />
          {split && (
            <TextField select fullWidth label="投票端别" sx={{ mt: 2 }} value={side} onChange={(e) => setSide(e.target.value)}
              helperText="全队共享＝两种端都能看到并投票">
              <MenuItem value="">全队共享</MenuItem>
              <MenuItem value="pc">端游专区</MenuItem>
              <MenuItem value="mobile">手游专区</MenuItem>
            </TextField>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>创建</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ---------- 统一日程（成员端） ----------
  function SchedulePage() {
    const [scope, setScope] = useState('day');
    const [data, setData] = useState(null);

    const load = useCallback(async function (s) {
      const realScope = s || scope;
      try {
        const json = await api('/api/schedule?scope=' + realScope);
        if (json.code === 0) setData(json.data);
      } catch (e) { setData({ items: [] }); }
    }, [scope]);

    useEffect(function () { load(scope); }, [scope]);  // eslint-disable-line

    // 倒计时文案（以服务器时间为基准）
    const left = function (st, serverTime) {
      const diff = new Date(String(st).replace(/-/g, '/')).getTime() - new Date(String(serverTime).replace(/-/g, '/')).getTime();
      if (diff <= 0) return '进行中 / 已结束';
      const d = Math.floor(diff / 86400000), h = Math.floor(diff % 86400000 / 3600000), m = Math.floor(diff % 3600000 / 60000);
      return (d > 0 ? d + ' 天 ' : '') + (h > 0 ? h + ' 小时 ' : '') + m + ' 分钟后开始';
    };

    const TYPE_COLOR = { race: '#1d4ed8', custom_race: '#7c3aed', battle: '#b45309', tournament: '#166534', custom: '#0e7490', duty: '#86868b' };
    // v5.6.1：详情链接只保留真实存在的页面——约战区块在自建房页；赛事无成员端页面不显示详情按钮
    const TYPE_LINK = { race: '#/race', custom_race: '#/customrace', battle: '#/customrace' };

    if (!data) return <LoadingBox />;
    return (
      <Box sx={{ maxWidth: 860, mx: 'auto' }}>
        <Paper sx={{ p: 2, mb: 2 }}>
          <Stack direction="row" spacing={1} alignItems="center">
            <Typography fontWeight={600} sx={{ flex: 1 }}>队内日程</Typography>
            <Tabs value={scope} onChange={(e, v) => setScope(v)}>
              <Tab value="day" label="今日" />
              <Tab value="week" label="本周" />
            </Tabs>
          </Stack>
        </Paper>
        {data.items.length === 0 ? <Paper sx={{ p: 3 }}><EmptyBox text="该时段暂无活动" /></Paper> :
          data.items.map(function (it, i) {
            const link = TYPE_LINK[it.type];
            return (
              <Paper key={it.type + '-' + it.ref_id + '-' + i} variant="outlined" sx={{ p: 1.8, mb: 1.2, display: 'flex', gap: 1.5, alignItems: 'center' }}>
                <Box sx={{ width: 10, height: 10, borderRadius: '50%', bgcolor: TYPE_COLOR[it.type] || '#86868b', flexShrink: 0 }} />
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography variant="body2" fontWeight={600}>
                    {it.title}
                    <Chip size="small" variant="outlined" label={it.type_label} sx={{ ml: 1 }} />
                    {it.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(it.game_side)} sx={{ ml: 0.6 }} /> : null}
                  </Typography>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                    {fmtTime(it.start_time)}{it.duration_min ? ' · ' + it.duration_min + ' 分钟' : ''}{it.note ? ' · ' + it.note : ''}
                  </Typography>
                </Box>
                {!it.all_day && <Chip size="small" color="primary" variant="outlined" label={left(it.start_time, data.server_time)} />}
                {link && <Button size="small" component="a" href={link}>详情</Button>}
              </Paper>
            );
          })}
      </Box>
    );
  }

  // ---------- 队内训练赛（成员端） ----------
  const TRAINING_STATUS = { signup: '报名中', ongoing: '进行中', finished: '已结束', canceled: '已取消' };

  function TrainingPage({ me }) {
    const [list, setList] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [detailId, setDetailId] = useState(null);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/trainings');
        if (json.code === 0) setList(json.data.list);
      } catch (e) { setList([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    // v5.6.1：发起训练赛限管理员（超管/站长恒持 training 权限；普通成员只能报名参与）
    const canCreate = !!(me && ((me.admin_perms || []).indexOf('training') >= 0));

    return (
      <Box sx={{ maxWidth: 900, mx: 'auto' }}>
        <Paper sx={{ p: 2, mb: 2 }}>
          <Stack direction="row" spacing={1} alignItems="center">
            <Box sx={{ flex: 1 }}>
              <Typography fontWeight={600}>队内训练赛</Typography>
              <Typography variant="caption" color="text.secondary">本队内部对抗，带「训练」标签，不计入任何正式战绩{canCreate ? '' : '（由管理员发起）'}</Typography>
            </Box>
            {canCreate && <Button variant="contained" onClick={() => setCreateOpen(true)}>发起训练赛</Button>}
          </Stack>
        </Paper>
        {list === null ? <LoadingBox /> : list.length === 0 ? <Paper sx={{ p: 3 }}><EmptyBox text="暂无训练赛" /></Paper> :
          list.map(function (m) {
            return (
              <Paper key={m.id} variant="outlined" sx={{ p: 2, mb: 1.5 }}>
                <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.6 }}>
                  <Typography fontWeight={600} sx={{ flex: 1 }}>{m.title || '训练赛 #' + m.id}</Typography>
                  {m.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(m.game_side)} /> : null}
                  <Chip size="small" variant="outlined" label={m.mode === 'squad' ? '小队对抗' : '自由分组'} />
                  <Chip size="small" variant="outlined" label={String(m.series_format).toUpperCase()} />
                  <Chip size="small" color={m.status === 'ongoing' ? 'success' : m.status === 'signup' ? 'primary' : 'default'}
                    label={TRAINING_STATUS[m.status] || m.status} />
                </Stack>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                  {m.mode === 'squad' ? (m.team_a_name || '?') + ' vs ' + (m.team_b_name || '?') : '自由分组'}
                  {' · ' + fmtTime(m.match_time) + ' · 发起人 ' + m.creator_name}
                  {(m.status === 'finished' || m.status === 'ongoing') ? ' · 比分 ' + m.score_a + ' : ' + m.score_b : ''}
                </Typography>
                <Button size="small" sx={{ mt: 1 }} onClick={() => setDetailId(m.id)}>查看详情</Button>
              </Paper>
            );
          })}
        <TrainingCreateDialog open={createOpen} onClose={() => setCreateOpen(false)} onDone={function () { setCreateOpen(false); load(); }} />
        <TrainingDetailDialog id={detailId} me={me} onClose={() => setDetailId(null)} onChanged={load} />
      </Box>
    );
  }

  function TrainingCreateDialog({ open, onClose, onDone }) {
    const gs = useGameSide();
    const split = !!(gs.on && gs.modules.training);
    const [title, setTitle] = useState('');
    const [mode, setMode] = useState('squad');
    const [teams, setTeams] = useState([]);
    const [teamA, setTeamA] = useState('');
    const [teamB, setTeamB] = useState('');
    const [time, setTime] = useState('');
    const [format, setFormat] = useState('bo3');
    const [mapMode, setMapMode] = useState('banpick');
    const [pool, setPool] = useState([]);
    const [fixedMap, setFixedMap] = useState('');
    const [deadline, setDeadline] = useState('');
    const [side, setSide] = useState('');

    useEffect(function () {
      if (open && split) { setSide(gs.zone || ''); }
    }, [open]);

    useEffect(function () {
      if (!open) return;
      (async function () {
        try {
          const rs = await Promise.all([api('/api/teams'), api('/api/battles/map-pool')]);
          if (rs[0].code === 0) setTeams(rs[0].data.list || []);
          if (rs[1].code === 0) setPool(rs[1].data || []);
        } catch (e) { /* 忽略 */ }
      })();
    }, [open]);

    const submit = async function () {
      try {
        const json = await api('/api/trainings/create', {
          method: 'POST',
          body: {
            title: title.trim(), mode: mode, team_a_id: Number(teamA) || 0, team_b_id: Number(teamB) || 0,
            match_time: time.replace('T', ' '), series_format: format, map_mode: mapMode,
            fixed_map_id: Number(fixedMap) || 0, signup_deadline: deadline.replace('T', ' '),
            game_side: split ? side : '',
          },
        });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>发起训练赛</DialogTitle>
        <DialogContent>
          <TextField label="标题（选填）" fullWidth sx={{ mt: 1, mb: 2 }} value={title}
            onChange={(e) => setTitle(e.target.value)} inputProps={{ maxLength: 50 }} />
          <Stack direction="row" spacing={1.5} sx={{ mb: 2 }}>
            <Select size="small" value={mode} onChange={(e) => setMode(e.target.value)} sx={{ minWidth: 140 }}>
              <MenuItem value="squad">小队对抗</MenuItem>
              <MenuItem value="free">自由分组</MenuItem>
            </Select>
            <Select size="small" value={format} onChange={(e) => setFormat(e.target.value)} sx={{ minWidth: 100 }}>
              <MenuItem value="bo1">BO1</MenuItem>
              <MenuItem value="bo3">BO3</MenuItem>
            </Select>
            <Select size="small" value={mapMode} onChange={(e) => setMapMode(e.target.value)} sx={{ minWidth: 140 }}>
              <MenuItem value="fixed">固定地图</MenuItem>
              <MenuItem value="banpick">双Ban+抽签</MenuItem>
              <MenuItem value="random">随机地图</MenuItem>
            </Select>
          </Stack>
          {mode === 'squad' ? (
            <Stack direction="row" spacing={1.5} sx={{ mb: 2 }}>
              <Select size="small" displayEmpty value={teamA} onChange={(e) => setTeamA(e.target.value)} sx={{ minWidth: 140 }}>
                <MenuItem value="">小队 A</MenuItem>
                {teams.map(function (t) { return <MenuItem key={t.id} value={t.id}>{t.name}</MenuItem>; })}
              </Select>
              <Select size="small" displayEmpty value={teamB} onChange={(e) => setTeamB(e.target.value)} sx={{ minWidth: 140 }}>
                <MenuItem value="">小队 B</MenuItem>
                {teams.map(function (t) { return <MenuItem key={t.id} value={t.id}>{t.name}</MenuItem>; })}
              </Select>
            </Stack>
          ) : (
            <TextField label="报名截止时间" fullWidth type="datetime-local" InputLabelProps={{ shrink: true }} sx={{ mb: 2 }}
              value={deadline} onChange={(e) => setDeadline(e.target.value)} />
          )}
          {mapMode === 'fixed' && (
            <Select size="small" displayEmpty fullWidth value={fixedMap} onChange={(e) => setFixedMap(e.target.value)} sx={{ mb: 2 }}>
              <MenuItem value="">选择地图</MenuItem>
              {pool.map(function (m) { return <MenuItem key={m.id} value={m.id}>{m.name}</MenuItem>; })}
            </Select>
          )}
          <TextField label="开赛时间" fullWidth required type="datetime-local" InputLabelProps={{ shrink: true }}
            value={time} onChange={(e) => setTime(e.target.value)} />
          {split && (
            <TextField select fullWidth label="训练赛端别" sx={{ mt: 2 }} value={side} onChange={(e) => setSide(e.target.value)}
              helperText="全队共享＝两种端都能看到并报名；小队对抗只能挑同一端的小队">
              <MenuItem value="">全队共享</MenuItem>
              <MenuItem value="pc">端游专区</MenuItem>
              <MenuItem value="mobile">手游专区</MenuItem>
            </TextField>
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>发起</Button>
        </DialogActions>
      </Dialog>
    );
  }

  function TrainingDetailDialog({ id, me, onClose, onChanged }) {
    const [d, setD] = useState(null);

    const load = useCallback(async function () {
      if (!id) return;
      try {
        const json = await api('/api/trainings/' + id);
        if (json.code === 0) setD(json.data);
      } catch (e) { /* 忽略 */ }
    }, [id]);

    useEffect(function () { setD(null); load(); }, [load]);

    const post = async function (url, body) {
      try {
        const json = await api(url, { method: 'POST', body: body || {} });
        if (json.code === 0) { toast(json.msg, 'success'); load(); onChanged(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (!id) return null;
    if (!d) return <Dialog open onClose={onClose}><DialogContent><LoadingBox /></DialogContent></Dialog>;

    const m = d.match;
    const canManage = d.can_manage;
    const sideName = function (side) {
      if (m.mode === 'squad') return side === 'a' ? (d.teams[m.team_a_id] || 'A 方') : (d.teams[m.team_b_id] || 'B 方');
      return side === 'a' ? 'A 方' : 'B 方';
    };
    const banMapName = function (mid) {
      const hit = (d.map_pool || []).filter(function (x) { return x.id === mid; })[0];
      return hit ? hit.name : '';
    };

    return (
      <Dialog open onClose={onClose} fullWidth maxWidth="md">
        <DialogTitle>
          {m.title || '训练赛 #' + m.id}
          <Chip size="small" label="训练" color="info" sx={{ ml: 1 }} />
          <Chip size="small" variant="outlined" label={TRAINING_STATUS[m.status] || m.status} sx={{ ml: 0.8 }} />
        </DialogTitle>
        <DialogContent dividers>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            {m.mode === 'squad' ? sideName('a') + ' vs ' + sideName('b') : '自由分组'}
            {' · ' + fmtTime(m.match_time) + ' · ' + String(m.series_format).toUpperCase()}
            {m.result_note ? ' · 总比分 ' + m.result_note : ''}
          </Typography>

          {/* 小局与录分 */}
          <Typography fontWeight={600} sx={{ mb: 0.8 }}>小局</Typography>
          {d.rounds.length === 0 ? (
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              小局骨架尚未生成{m.map_mode === 'banpick' ? '（双Ban后抽签生成）' : m.map_mode === 'random' ? '（点「随机生成地图」）' : '（自由分组待分组后生成）'}
            </Typography>
          ) : d.rounds.map(function (r) {
            return (
              <Box key={r.id} sx={{ display: 'flex', alignItems: 'center', gap: 1.2, py: 0.8, borderBottom: '1px solid #f1f5f9' }}>
                <Chip size="small" label={'第 ' + r.round_no + ' 局'} />
                <Typography variant="body2" sx={{ flex: 1 }}>
                  {r.map_name || '地图待定'}{r.attacker_side ? ' · 攻方：' + sideName(r.attacker_side) : ''}
                </Typography>
                {r.result ? (
                  <Chip size="small" color={r.result === 'a_win' ? 'primary' : 'secondary'} label={sideName(r.result === 'a_win' ? 'a' : 'b') + ' 胜'} />
                ) : canManage && m.status !== 'canceled' ? (
                  <Stack direction="row" spacing={0.5}>
                    <Button size="small" variant="outlined" onClick={() => post('/api/trainings/' + m.id + '/round/' + r.id + '/result', { result: 'a_win' })}>{sideName('a')}胜</Button>
                    <Button size="small" variant="outlined" onClick={() => post('/api/trainings/' + m.id + '/round/' + r.id + '/result', { result: 'b_win' })}>{sideName('b')}胜</Button>
                  </Stack>
                ) : <Typography variant="caption" color="text.secondary">未录入</Typography>}
              </Box>
            );
          })}

          {/* 自由分组报名名单 */}
          {m.mode === 'free' && (
            <Box sx={{ mt: 2 }}>
              <Typography fontWeight={600} sx={{ mb: 0.8 }}>报名名单（{d.signups.length}）</Typography>
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.8 }}>
                {d.signups.length === 0 ? <Typography variant="caption" color="text.secondary">暂无人报名</Typography> :
                  d.signups.map(function (s) {
                    return <Chip key={s.id} size="small" variant="outlined"
                      label={s.nickname + (s.side ? '（' + s.side.toUpperCase() + ' 方）' : '')} />;
                  })}
              </Box>
            </Box>
          )}

          {/* 双Ban信息 */}
          {m.map_mode === 'banpick' && (m.ban_a_map_id || m.ban_b_map_id) && (
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.5 }}>
              禁用地图：{banMapName(m.ban_a_map_id) || '-'} / {banMapName(m.ban_b_map_id) || '-'}
              {m.drawn_at ? ' · 抽签于 ' + fmtTime(m.drawn_at) : ''}
            </Typography>
          )}

          {/* 操作区 */}
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mt: 2 }}>
            {m.mode === 'free' && m.status === 'signup' && !d.my_signup && (
              <Button variant="contained" size="small" onClick={() => post('/api/trainings/' + m.id + '/signup', { action: 'join' })}>报名出战</Button>
            )}
            {m.mode === 'free' && m.status === 'signup' && d.my_signup && (
              <Button variant="outlined" size="small" onClick={() => post('/api/trainings/' + m.id + '/signup', { action: 'leave' })}>退出报名</Button>
            )}
            {canManage && m.mode === 'free' && m.status === 'signup' && (
              <Button variant="outlined" size="small" onClick={() => post('/api/trainings/' + m.id + '/assign')}>自动均分并开赛</Button>
            )}
            {canManage && m.map_mode === 'banpick' && m.status !== 'finished' && m.status !== 'canceled' && (
              <Button variant="outlined" size="small" onClick={() => post('/api/trainings/' + m.id + '/draw')}>抽签生成小局</Button>
            )}
            {canManage && m.map_mode === 'random' && m.status !== 'finished' && m.status !== 'canceled' && (
              <Button variant="outlined" size="small" onClick={() => post('/api/trainings/' + m.id + '/random-maps')}>随机生成地图</Button>
            )}
            {canManage && m.status !== 'finished' && m.status !== 'canceled' && (
              <Button variant="outlined" color="error" size="small" onClick={() => post('/api/trainings/' + m.id + '/cancel')}>取消训练赛</Button>
            )}
          </Stack>
          {canManage && m.map_mode === 'banpick' && m.status !== 'finished' && m.status !== 'canceled' && (
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
              抽签规则：双方各禁一图（禁图不可选），其余逐局随机抽图 + 随机攻方。
            </Typography>
          )}
        </DialogContent>
        <DialogActions><Button onClick={onClose}>关闭</Button></DialogActions>
      </Dialog>
    );
  }

  // ---------- 轻量投票（成员端） ----------
  function VotesPage() {
    const [open, setOpen] = useState(null);
    const [closed, setClosed] = useState(null);
    const [detail, setDetail] = useState(null);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/votes');
        if (json.code === 0) {
          setOpen(json.data.open);
          setClosed(json.data.closed);
        }
      } catch (e) { setOpen([]); setClosed([]); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const card = function (v) {
      return (
        <Paper key={v.id} variant="outlined" sx={{ p: 2, mb: 1.5 }}>
          <Stack direction="row" spacing={1} alignItems="center">
            <Typography fontWeight={600} sx={{ flex: 1 }}>{v.title}</Typography>
            {v.game_side ? <Chip size="small" color="primary" variant="outlined" label={gsLabel(v.game_side)} /> : null}
            {v.multi ? <Chip size="small" variant="outlined" label="多选" /> : <Chip size="small" variant="outlined" label="单选" />}
            {Number(v.anonymous) === 1 && <Chip size="small" variant="outlined" label="匿名" />}
            {v.status === 'open' && <Chip size="small" color="success" label="进行中" />}
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.4 }}>截止 {fmtTime(v.deadline)}</Typography>
          <Button size="small" sx={{ mt: 1 }} variant={v.status === 'open' ? 'contained' : 'outlined'} onClick={() => setDetail(v.id)}>
            {v.status === 'open' ? '去投票' : '查看结果'}
          </Button>
        </Paper>
      );
    };

    return (
      <Box sx={{ maxWidth: 760, mx: 'auto' }}>
        <Typography fontWeight={600} sx={{ mb: 1 }}>进行中</Typography>
        {open === null ? <LoadingBox /> : open.length === 0 ? <Paper sx={{ p: 3, mb: 2 }}><EmptyBox text="暂无进行中的投票" /></Paper> : open.map(card)}
        <Typography fontWeight={600} sx={{ mb: 1, mt: 2 }}>已结束</Typography>
        {closed === null ? <LoadingBox /> : closed.length === 0 ? <Paper sx={{ p: 3 }}><EmptyBox text="暂无已结束的投票" /></Paper> : closed.map(card)}
        <VoteDetailDialog voteId={detail} onClose={() => setDetail(null)} onChanged={load} />
      </Box>
    );
  }

  function VoteDetailDialog({ voteId, onClose, onChanged }) {
    const [d, setD] = useState(null);
    const [picked, setPicked] = useState([]);
    const [newOpt, setNewOpt] = useState('');

    const load = useCallback(async function () {
      if (!voteId) return;
      try {
        const json = await api('/api/votes/' + voteId);
        if (json.code === 0) {
          setD(json.data);
          setPicked(json.data.my_choice || []);
        }
      } catch (e) { /* 忽略 */ }
    }, [voteId]);

    useEffect(function () { setD(null); load(); }, [load]);

    const toggle = function (oid) {
      if (!d || !d.is_open || d.my_choice.length > 0) return;
      setPicked(function (prev) {
        if (prev.indexOf(oid) >= 0) return prev.filter(function (x) { return x !== oid; });
        const limit = d.vote.multi ? Number(d.vote.max_choices) : 1;
        if (!d.vote.multi) return [oid];
        if (prev.length >= limit) { toast('最多只能选 ' + limit + ' 项', 'warning'); return prev; }
        return prev.concat([oid]);
      });
    };

    const cast = async function () {
      try {
        const json = await api('/api/votes/' + voteId + '/cast', { method: 'POST', body: { option_ids: picked } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); if (onChanged) onChanged(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const addOption = async function () {
      try {
        const json = await api('/api/votes/' + voteId + '/options', { method: 'POST', body: { content: newOpt.trim() } });
        if (json.code === 0) { toast(json.msg, 'success'); setNewOpt(''); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (!voteId) return null;
    if (!d) return <Dialog open onClose={onClose}><DialogContent><LoadingBox /></DialogContent></Dialog>;

    const v = d.vote;
    const voted = d.my_choice.length > 0;
    const showResult = voted || !d.is_open;

    return (
      <Dialog open onClose={onClose} fullWidth maxWidth="sm">
        <DialogTitle>
          {v.title}
          {Number(v.anonymous) === 1 && <Chip size="small" variant="outlined" label="匿名投票" sx={{ ml: 1 }} />}
        </DialogTitle>
        <DialogContent dividers>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            {v.multi ? '多选（限 ' + v.max_choices + ' 项）' : '单选'} · 截止 {fmtTime(v.deadline)} · 共 {d.total} 人投票
            {d.is_open ? '' : ' · 已结束'}{voted ? ' · 您已投票（不可修改）' : ''}
          </Typography>
          <Stack spacing={1.2}>
            {d.options.map(function (o) {
              const on = picked.indexOf(o.id) >= 0;
              return (
                <Box key={o.id} onClick={() => toggle(o.id)} sx={{
                  p: 1.5, borderRadius: '10px', cursor: d.is_open && !voted ? 'pointer' : 'default',
                  border: '1.5px solid ' + (on ? 'primary.main' : '#e8e8ed'),
                }}>
                  <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: showResult ? 0.8 : 0 }}>
                    {(d.is_open && !voted) && <Checkbox size="small" checked={on} sx={{ p: 0, mr: 0.5 }} />}
                    <Typography variant="body2" sx={{ flex: 1 }}>{o.content}</Typography>
                    {showResult && <Typography variant="caption" color="text.secondary">{o.count} 票 · {o.percent}%</Typography>}
                  </Stack>
                  {showResult && (
                    <Box sx={{ height: 6, borderRadius: 3, bgcolor: '#f1f5f9', overflow: 'hidden' }}>
                      <Box sx={{ height: '100%', width: o.percent + '%', bgcolor: 'primary.main', borderRadius: 3 }} />
                    </Box>
                  )}
                  {showResult && o.voters && o.voters.length > 0 && (
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                      {o.voters.map(function (u) { return u.nickname; }).join('、')}
                    </Typography>
                  )}
                </Box>
              );
            })}
          </Stack>
          {d.is_open && !voted && (
            <Button variant="contained" fullWidth sx={{ mt: 2 }} disabled={picked.length === 0} onClick={cast}>
              提交投票（一经提交不可修改）
            </Button>
          )}
          {d.is_open && Number(v.allow_add_option) === 1 && (
            <Stack direction="row" spacing={1} sx={{ mt: 2 }}>
              <TextField size="small" fullWidth placeholder="补充一个选项" value={newOpt}
                onChange={(e) => setNewOpt(e.target.value)} inputProps={{ maxLength: 128 }} />
              <Button variant="outlined" size="small" onClick={addOption}>添加</Button>
            </Stack>
          )}
        </DialogContent>
        <DialogActions><Button onClick={onClose}>关闭</Button></DialogActions>
      </Dialog>
    );
  }

  // ---------- 比赛日集合点名（v5.36.34，成员端，仪表盘嵌入）----------
  // 一张单三个钮：到了 / 迟到 / 不来。点下去是整行覆盖自己那一行，改口随时能改，
  // 截止（deadline）之后后端拒收，这里也把三个钮收成禁用并说明原因。
  const RC_COLORS = {
    in:   { color: '#166534', bg: '#f0fdf4' },
    late: { color: '#b45309', bg: '#fffbeb' },
    out:  { color: '#9d1c1c', bg: '#fef2f2' },
  };
  const RC_LABEL = { in: '到了', late: '迟到', out: '不来', none: '没回' };

  function RollCallCard() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState('');
    const [who, setWho] = useState(0);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/rollcall');
        if (json.code === 0) setD(json.data);
      } catch (e) { /* 静默 */ }
    }, []);
    useEffect(function () {
      load();
      const t = setInterval(load, 20000);
      return function () { clearInterval(t); };
    }, [load]);

    const reply = async function (c, r) {
      setBusy(c.id + r);
      try {
        const json = await api('/api/rollcall/' + c.id + '/reply', { method: 'POST', body: { reply: r } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(''); }
    };

    if (!d || !d.list || d.list.length === 0) return null;

    return (
      <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: 1 }}>
          <Box sx={{
            width: 44, height: 44, borderRadius: '12px', bgcolor: '#eff6ff', color: '#1d4ed8', flexShrink: 0,
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
              <path d="M19 3h-4.2c-.7-1.2-2-2-3.3-2s-2.6.8-3.3 2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zM12 3a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm-1 12-3-3 1.4-1.4L11 12.2l4.2-4.2L16.6 9 11 15z" />
            </svg>
          </Box>
          <Box sx={{ flex: 1, minWidth: 0 }}>
            <Typography fontWeight={600}>集合点名</Typography>
            <Typography variant="caption" color="text.secondary">
              到点前 {d.lead_min} 分钟会在 QQ 群里喊一声，站内点就行
            </Typography>
          </Box>
          <Button size="small" onClick={load}>刷新</Button>
        </Box>

        <Stack spacing={1.2}>
          {d.list.map(function (c) {
            const left = new Date(String(c.call_at).replace(/-/g, '/')).getTime() - Date.now();
            const mine = c.my_reply;
            const showList = who === c.id;
            return (
              <Paper key={c.id} variant="outlined" sx={{ p: 1.5, borderColor: CARD_BORDER }}>
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
                  <Typography fontWeight={600} sx={{ flex: '1 1 160px', minWidth: 0 }}>{c.title}</Typography>
                  {c.game_side ? <Chip size="small" variant="outlined" label={gsLabel(c.game_side)} /> : null}
                  {c.state === 'revoked' ? <Chip size="small" label="已撤回" /> :
                    c.closed ? <Chip size="small" color="default" label="已截止" /> :
                      c.state === 'upcoming' ? <Chip size="small" color="primary" variant="outlined"
                        label={left > 0 ? ('还有 ' + Math.max(1, Math.round(left / 60000)) + ' 分钟集合') : '集合时间已到'} /> :
                        <Chip size="small" color="success" label="正在集合" />}
                </Box>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.3 }}>
                  集合 {fmtTime(c.call_at)}{c.deadline ? (' · 截至 ' + fmtTime(c.deadline)) : ''}
                  {' · 已到 ' + (c.counts.in + c.counts.late) + '/' + c.total + ' 人'}
                  {c.counts.out > 0 ? ('（' + c.counts.out + ' 人说不来）') : ''}
                  {c.notify_at ? ' · 群里喊过了' : ''}
                </Typography>
                <Box sx={{ display: 'flex', gap: 1, mt: 1, flexWrap: 'wrap' }}>
                  {['in', 'late', 'out'].map(function (r) {
                    const on = mine === r;
                    const cc = RC_COLORS[r];
                    return (
                      <Button key={r} size="small" disabled={!!c.closed || busy === c.id + r}
                        variant={on ? 'contained' : 'outlined'}
                        onClick={() => reply(c, r)}
                        sx={on ? {} : { color: cc.color, borderColor: cc.color, bgcolor: cc.bg }}>
                        {RC_LABEL[r]}
                      </Button>
                    );
                  })}
                  <Button size="small" color="inherit" sx={{ ml: 'auto' }}
                    onClick={() => setWho(showList ? 0 : c.id)}>
                    {showList ? '收起名单' : ('谁到了 (' + c.counts.none + ' 人没回)')}
                  </Button>
                </Box>
                {c.closed && !mine ? (
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.6 }}>
                    这张已经截止了，没点上就不再补了。
                  </Typography>
                ) : null}
                {showList && (
                  <Box sx={{ mt: 1, pt: 1, borderTop: '1px dashed ' + CARD_BORDER }}>
                    {['in', 'late', 'out', 'none'].map(function (g) {
                      const arr = g === 'in' ? c.list_in : g === 'late' ? c.list_late
                        : g === 'out' ? c.list_out : c.list_none;
                      if (arr.length === 0) return null;
                      const cc = RC_COLORS[g] || { color: TEXT_SECONDARY, bg: BG_GRAY };
                      return (
                        <Box key={g} sx={{ display: 'flex', gap: 1, alignItems: 'baseline', py: 0.25, flexWrap: 'wrap' }}>
                          <Chip size="small" label={RC_LABEL[g] + ' ' + arr.length}
                            sx={{ bgcolor: cc.bg, color: cc.color, fontWeight: 600, flexShrink: 0 }} />
                          <Typography variant="caption" sx={{ color: '#5f6b7f', minWidth: 0 }}>
                            {arr.map(function (m) { return m.nickname + (m.note ? '（' + m.note + '）' : ''); }).join('、')}
                          </Typography>
                        </Box>
                      );
                    })}
                  </Box>
                )}
              </Paper>
            );
          })}
        </Stack>
      </Paper>
    );
  }

  // ---------- 值班卡片（成员端，仪表盘嵌入）：今日值班 + 换班发起/确认 ----------
  function DutyTodayCard() {
    const [d, setD] = useState(null);
    const [sw, setSw] = useState(null);
    const [swapOpen, setSwapOpen] = useState(false);
    const [toUid, setToUid] = useState('');
    const [busy, setBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const rs = await Promise.all([api('/api/duty/today'), api('/api/duty/swaps')]);
        if (rs[0].code === 0) setD(rs[0].data);
        if (rs[1].code === 0) setSw(rs[1].data);
      } catch (e) { /* 静默 */ }
    }, []);
    useEffect(function () { load(); }, [load]);

    // 未配置值班 / 无轮值数据：不占位
    if (!d || !d.duty) return null;

    const candidates = (d.roster || []).filter(function (m) { return m.id !== d.duty.user_id; });
    const pendingMe = (sw && sw.pending_me) || [];
    const myPending = ((sw && sw.mine) || []).filter(function (s) { return s.status === 'pending'; });

    const requestSwap = async function () {
      if (!toUid) { toast('请选择换班对象', 'warning'); return; }
      setBusy(true);
      try {
        const json = await api('/api/duty/swaps/request', { method: 'POST', body: { date: d.date, to_uid: Number(toUid) } });
        if (json.code === 0) { toast(json.msg, 'success'); setSwapOpen(false); setToUid(''); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    const resolveSwap = async function (id, action) {
      setBusy(true);
      try {
        const json = await api('/api/duty/swaps/' + id + '/resolve', { method: 'POST', body: { action: action } });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); } finally { setBusy(false); }
    };

    return (
      <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <Box sx={{
            width: 44, height: 44, borderRadius: '12px', bgcolor: '#fffbeb', color: '#b45309', flexShrink: 0,
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
              <path d="M12 3l1.9 5.8H20l-4.9 3.6 1.9 5.8-5-3.6-5 3.6 1.9-5.8L4 8.8h6.1L12 3z" />
            </svg>
          </Box>
          <Box sx={{ flex: 1 }}>
            <Typography variant="body2" fontWeight={600}>
              {d.is_me ? '今天你值班（房主）' : '今日值班：' + d.duty.nickname}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              {d.zone ? gsLabel(d.zone) + ' · ' : ''}{d.duty.swapped ? '（与 ' + d.duty.swapped_from + ' 换班）' : '房主值班 · 轮换表自动排定'}
            </Typography>
          </Box>
          {d.is_me && <Chip size="small" color="warning" label="轮到你啦" />}
          {/* 换班入口：仅当天值班人可发起（后端双重校验） */}
          {d.is_me && candidates.length > 0 && (
            <Button size="small" variant="outlined" onClick={() => setSwapOpen(true)}>发起换班</Button>
          )}
        </Box>

        {/* 待我确认的换班请求 */}
        {pendingMe.length > 0 && (
          <Box sx={{ mt: 1.5, pt: 1.5, borderTop: '1px dashed #e8e8ed' }}>
            {pendingMe.map(function (s) {
              return (
                <Box key={s.id} sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap', py: 0.4 }}>
                  <Typography variant="body2" sx={{ flex: 1 }}>
                    <b>{s.from_name}</b> 请求与你换 <b>{s.duty_date}</b> 的班
                  </Typography>
                  <Button size="small" variant="contained" disabled={busy} onClick={() => resolveSwap(s.id, 'accept')}>同意</Button>
                  <Button size="small" variant="outlined" color="error" disabled={busy} onClick={() => resolveSwap(s.id, 'reject')}>拒绝</Button>
                </Box>
              );
            })}
          </Box>
        )}

        {/* 我发起的待确认换班 */}
        {myPending.length > 0 && (
          <Box sx={{ mt: 1, display: 'flex', gap: 1, flexWrap: 'wrap' }}>
            {myPending.map(function (s) {
              return <Chip key={s.id} size="small" variant="outlined"
                label={'等待 ' + s.to_name + ' 确认 ' + s.duty_date + ' 换班'} />;
            })}
          </Box>
        )}

        {/* 发起换班弹窗 */}
        <Dialog open={swapOpen} onClose={() => setSwapOpen(false)} fullWidth maxWidth="xs">
          <DialogTitle>发起换班</DialogTitle>
          <DialogContent>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 2 }}>
              你在 <b>{d.date}</b> 值班，选择一位轮值成员与你互换。对方确认后当天值班即对调，不排队不审批。
            </Typography>
            <InputLabel shrink>换班对象</InputLabel>
            <Select fullWidth size="small" value={toUid} displayEmpty onChange={(e) => setToUid(e.target.value)}>
              <MenuItem value="" disabled>请选择成员</MenuItem>
              {candidates.map(function (m) { return <MenuItem key={m.id} value={m.id}>{m.nickname}</MenuItem>; })}
            </Select>
          </DialogContent>
          <DialogActions sx={{ px: 3, pb: 2 }}>
            <Button onClick={() => setSwapOpen(false)}>取消</Button>
            <Button variant="contained" disabled={busy || !toUid} onClick={requestSwap}>发出请求</Button>
          </DialogActions>
        </Dialog>
      </Paper>
    );
  }

  // ==================== v5.7.0：抽奖大转盘 ====================

  // 转盘配色（谢谢参与固定灰）
  const WHEEL_COLORS = ['#f59e0b', '#ef4444', '#8b5cf6', '#3b82f6', '#10b981', '#ec4899'];

  // 扇形路径：0° 指向正上方，顺时针展开
  function wheelSegPath(cx, cy, r, a0, a1) {
    const rad = function (a) { return (a - 90) * Math.PI / 180; };
    const x0 = cx + r * Math.cos(rad(a0)), y0 = cy + r * Math.sin(rad(a0));
    const x1 = cx + r * Math.cos(rad(a1)), y1 = cy + r * Math.sin(rad(a1));
    const large = (a1 - a0) > 180 ? 1 : 0;
    return 'M' + cx + ',' + cy + ' L' + x0.toFixed(2) + ',' + y0.toFixed(2) +
      ' A' + r + ',' + r + ' 0 ' + large + ' 1 ' + x1.toFixed(2) + ',' + y1.toFixed(2) + ' Z';
  }

  // 0.1234 → "12.34%"（去尾零）
  function pct(x) {
    return (Number(x || 0) * 100).toFixed(2).replace(/\.?0+$/, '') + '%';
  }

  // ---------- 抽奖大转盘（成员端） ----------
  function LotteryPage() {
    const [data, setData] = useState(null);
    const [spinning, setSpinning] = useState(false);
    const [rotation, setRotation] = useState(0);
    const [result, setResult] = useState(null);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/lottery');
        if (json.code === 0) setData(json.data);
      } catch (e) { setData({ chances: 0, used: 0, total: 0, pools: [], logs: [] }); }
    }, []);

    useEffect(function () { load(); }, [load]);

    // 转盘扇区 = 全部开启奖池的奖品展平 + 恒定一个「谢谢参与」兜底扇区
    const segs = useMemo(function () {
      const s = [];
      if (data && data.pools) {
        data.pools.forEach(function (p) {
          (p.prizes || []).forEach(function (z) {
            s.push({ id: z.id, name: z.name, is_thanks: z.is_thanks });
          });
        });
      }
      s.push({ id: 0, name: '谢谢参与', is_thanks: 1 });
      return s;
    }, [data]);

    const n = segs.length;
    const segDeg = 360 / n;

    const segColor = function (s, i) {
      return Number(s.is_thanks) === 1 ? '#a1a1a6' : WHEEL_COLORS[i % WHEEL_COLORS.length];
    };

    const spin = async function () {
      if (spinning || !data) return;
      if (data.chances <= 0) { toast('没有抽奖机会，请联系管理员发放', 'warning'); return; }
      setSpinning(true);
      try {
        const json = await api('/api/lottery/spin', { method: 'POST' });
        if (json.code !== 0) { toast(json.msg, 'error'); setSpinning(false); return; }
        const r = json.data;
        // 定位中奖扇区（未命中可见扇区 → 兜底谢谢参与扇区）
        let idx = -1;
        if (Number(r.prize_id) > 0) {
          for (let i = 0; i < segs.length; i++) {
            if (segs[i].id === Number(r.prize_id)) { idx = i; break; }
          }
        }
        if (idx < 0) idx = segs.length - 1;
        const jitter = (Math.random() * 0.5 - 0.25) * segDeg;
        setRotation(function (prev) {
          return prev + 360 * 6 + (360 - (idx * segDeg + segDeg / 2)) + jitter;
        });
        window.setTimeout(function () {
          setSpinning(false);
          setResult(r);
          load();
        }, 4500);
      } catch (e) { toast('抽奖失败，请稍后再试', 'error'); setSpinning(false); }
    };

    if (!data) return <LoadingBox />;
    return (
      <Box sx={{ maxWidth: 900, mx: 'auto' }}>
        <Paper sx={{ p: { xs: 2, md: 3 }, mb: 2 }}>
          <PanelHead title="幸运转盘" desc="机会由管理员发放；转盘先按奖池权重抽取奖池，再按池内奖品权重抽出奖励" />
          <Box sx={{ position: 'relative', width: 320, maxWidth: '86vw', mx: 'auto', my: 2 }}>
            {/* 顶部指针 */}
            <Box sx={{ position: 'absolute', top: -10, left: '50%', transform: 'translateX(-50%)', zIndex: 3 }}>
              <svg width="26" height="30" viewBox="0 0 26 30">
                <path d="M13 30 L3 7 Q13 -3 23 7 Z" fill="#ff3b30" />
              </svg>
            </Box>
            {/* 转盘（CSS transform 旋转） */}
            <Box sx={{
              width: '100%',
              transition: 'transform 4.2s cubic-bezier(0.15, 0.85, 0.25, 1)',
              transform: 'rotate(' + rotation + 'deg)',
            }}>
              <svg viewBox="0 0 320 320" style={{ display: 'block', width: '100%' }}>
                <circle cx="160" cy="160" r="159" fill="#fff" stroke="#e8e8ed" strokeWidth="2" />
                {segs.map(function (s, i) {
                  return <path key={i} d={wheelSegPath(160, 160, 150, i * segDeg, (i + 1) * segDeg)}
                    fill={segColor(s, i)} stroke="#fff" strokeWidth="1.5" />;
                })}
                {segs.map(function (s, i) {
                  const mid = i * segDeg + segDeg / 2;
                  const label = s.name.length > 6 ? s.name.slice(0, 6) + '…' : s.name;
                  return (
                    <text key={'t' + i} transform={'rotate(' + mid + ' 160 160)'} x="160" y="34"
                      textAnchor="middle" fontSize={n > 14 ? 9 : 11} fill="#fff" fontWeight="600">{label}</text>
                  );
                })}
                <circle cx="160" cy="160" r="46" fill="#fff" stroke="#e8e8ed" />
              </svg>
            </Box>
            {/* 中心开始按钮 */}
            <Box onClick={spin} sx={{
              position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%, -50%)',
              width: 82, height: 82, borderRadius: '50%', bgcolor: 'primary.main',
              display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
              cursor: spinning || data.chances <= 0 ? 'default' : 'pointer',
              opacity: data.chances > 0 ? 1 : 0.55, zIndex: 2,
              boxShadow: '0 2px 12px rgba(0,0,0,.2)',
            }}>
              <Typography fontWeight={700} sx={{ color: '#fff', fontSize: 18, lineHeight: 1.2 }}>
                {spinning ? '···' : '开始'}
              </Typography>
              <Typography sx={{ color: 'rgba(255,255,255,.85)', fontSize: 11 }}>剩余 {data.chances} 次</Typography>
            </Box>
          </Box>
          <Stack direction="row" spacing={1.5} justifyContent="center" sx={{ mt: 1 }}>
            {[['剩余机会', data.chances], ['累计发放', data.total], ['已使用', data.used]].map(function (s) {
              return (
                <Box key={s[0]} sx={{ px: 2.2, py: 1, borderRadius: '12px', bgcolor: '#f5f5f7', textAlign: 'center', minWidth: 84 }}>
                  <Typography fontWeight={700} sx={{ fontSize: 18 }}>{s[1]}</Typography>
                  <Typography variant="caption" color="text.secondary">{s[0]}</Typography>
                </Box>
              );
            })}
          </Stack>
        </Paper>

        <Paper sx={{ p: 2, mb: 2 }}>
          <Typography fontWeight={600} sx={{ mb: 1 }}>奖池与概率</Typography>
          {data.pools.length === 0 ? <EmptyBox text="管理员暂未开启奖池" /> : data.pools.map(function (p) {
            return (
              <Box key={p.id} sx={{ mb: 1.2 }}>
                <Stack direction="row" spacing={1} alignItems="center">
                  <Typography variant="body2" fontWeight={600}>{p.name}</Typography>
                  <Chip size="small" variant="outlined" label={'命中率 ' + pct(p.prob)} />
                </Stack>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.3 }}>
                  {p.prizes.map(function (z) { return z.name + ' ' + pct(z.prob); }).join(' · ')}
                </Typography>
              </Box>
            );
          })}
        </Paper>

        <Paper sx={{ p: 2 }}>
          <Typography fontWeight={600} sx={{ mb: 1 }}>我的记录（最近 20 条）</Typography>
          {data.logs.length === 0 ? <EmptyBox text="还没有抽过奖" /> : (
            <Stack spacing={0.8}>
              {data.logs.map(function (l) {
                const win = Number(l.prize_id) > 0;
                return (
                  <Stack key={l.id} direction="row" spacing={1} alignItems="center">
                    <Typography variant="caption" color="text.secondary" sx={{ width: 92, flexShrink: 0 }}>{fmtTime(l.created_at)}</Typography>
                    <Typography variant="body2" sx={{ flex: 1 }} fontWeight={win ? 600 : 400}>{l.prize_name}</Typography>
                    {win && (Number(l.fulfilled) === 1
                      ? <Chip size="small" color="success" variant="outlined" label="已发放" />
                      : <Chip size="small" color="warning" variant="outlined" label="待发放" />)}
                  </Stack>
                );
              })}
            </Stack>
          )}
        </Paper>

        <Dialog open={!!result} onClose={function () { setResult(null); }}>
          {result && (
            <Box sx={{ p: 3, textAlign: 'center', minWidth: 260 }}>
              <Typography variant="h6" fontWeight={700}>
                {Number(result.is_thanks) === 1 ? '谢谢参与' : '恭喜中奖'}
              </Typography>
              <Typography sx={{
                fontSize: 22, fontWeight: 600, mt: 1.2,
                color: Number(result.is_thanks) === 1 ? 'text.secondary' : 'primary.main',
              }}>{result.prize_name}</Typography>
              {result.pool_name ? (
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                  来自奖池：{result.pool_name}
                </Typography>
              ) : null}
              {Number(result.is_thanks) !== 1 && (
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                  请联系管理员发放奖励（管理中心 · 抽奖管理）
                </Typography>
              )}
              <Stack direction="row" spacing={1} justifyContent="center" sx={{ mt: 2.5 }}>
                <Button onClick={function () { setResult(null); }}>关闭</Button>
                {data.chances > 0 && (
                  <Button variant="contained" onClick={function () { setResult(null); window.setTimeout(spin, 80); }}>再抽一次</Button>
                )}
              </Stack>
            </Box>
          )}
        </Dialog>
      </Box>
    );
  }

  // ---------- QQ 机器人（v5.8.0：群绑定/解绑/成员绑定名单） ----------
  // 群绑定流程：群管理员在群里 @机器人 发「绑定」→ 机器人回 6 位验证码（10 分钟有效）
  // → 管理员在此回填验证码完成绑定（单群模式：一个战队一个群）
  function AdminBot() {
    const [info, setInfo] = useState(null);
    const [code, setCode] = useState('');
    const [members, setMembers] = useState([]);
    const [busy, setBusy] = useState(false);
    const [config, setConfig] = useState({
      has_config: false,
      app_id: '',
      app_secret: '',
      secret_set: false,
      enabled: 0,
      env: 'prod',
      callback_url: '',
      panel_id: ''
    });
    const [configBusy, setConfigBusy] = useState(false);
    const [panelBusy, setPanelBusy] = useState(false);
    const [diag, setDiag] = useState(null);
    const [diagBusy, setDiagBusy] = useState(false);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/bot');
        if (json.code === 0) setInfo(json.data);
        const j2 = await api('/api/admin/bot/members');
        if (j2.code === 0) setMembers(j2.data.list || []);
        // v5.9.0：加载战队独立机器人配置（Secret 不回显明文，仅回传是否已设置）
        const j3 = await api('/api/admin/bot/config');
        if (j3.code === 0) {
          setConfig({
            has_config: !!j3.data.has_config,
            app_id: j3.data.app_id || '',
            app_secret: '',
            secret_set: !!j3.data.secret_set,
            enabled: Number(j3.data.enabled) === 1,
            env: j3.data.env === 'sandbox' ? 'sandbox' : 'prod',
            callback_url: j3.data.callback_url || '',
            panel_id: j3.data.panel_id || ''
          });
        }
      } catch (e) { toast('加载失败', 'error'); }
    }, []);
    useEffect(function () { load(); }, [load]);

    const verify = async function () {
      if (!/^\d{6}$/.test(code)) { toast('请输入群里收到的 6 位数字验证码', 'error'); return; }
      setBusy(true);
      try {
        const json = await api('/api/admin/bot/verify', { method: 'POST', body: { code: code } });
        if (json.code === 0) { toast(json.msg, 'success'); setCode(''); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
      setBusy(false);
    };

    const saveConfig = async function () {
      setConfigBusy(true);
      try {
        const json = await api('/api/admin/bot/config', {
          method: 'POST',
          body: {
            app_id: config.app_id,
            app_secret: config.app_secret,
            enabled: config.enabled,
            env: config.env
          }
        });
        if (json.code === 0) {
          toast(json.msg || '配置已保存', 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
      setConfigBusy(false);
    };

    // 彻底删除本队机器人配置（含 Secret 与回调密钥）——v5.28.0 起没有「切回平台机器人」这回事
    const clearConfig = async function () {
      if (!window.confirm('删除战队机器人配置？删除后回调地址立即失效（记得去 QQ 机器人后台移除），群内指令不再响应。确定吗？')) return;
      setConfigBusy(true);
      try {
        const json = await api('/api/admin/bot/config', { method: 'POST', body: { clear: 1 } });
        if (json.code === 0) { toast(json.msg || '已删除', 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) {
        toast('删除失败', 'error');
      }
      setConfigBusy(false);
    };

    const testConn = async function () {
      setConfigBusy(true);
      try {
        const json = await api('/api/admin/bot/config/test', { method: 'POST' });
        toast(json.msg, json.code === 0 ? 'success' : 'error');
      } catch (e) { toast('测试失败', 'error'); }
      setConfigBusy(false);
    };

    // v5.36.15：群里 @ 了没反应，跑一次自检看卡在哪一步（只读，不往群里发消息）
    const diagFail = function (text) {
      setDiag({ version: '', verdict_level: 'error', verdict: text, checks: [], events: [], errors: [], calls: [], jobs: [] });
    };
    const runDiag = async function () {
      setDiagBusy(true);
      try {
        let full = BASE + '/api/admin/bot/diagnose';
        if (_SV) full += '?_sv=' + encodeURIComponent(_SV);
        const res = await fetch(full, { method: 'GET', headers: GS_ZONE ? { 'X-Game-Side': GS_ZONE } : {} });
        const text = await res.text();
        let json = null;
        try { json = JSON.parse(text); } catch (e) { }
        if (json && json.code === 0) {
          setDiag(json.data);
        } else if (json) {
          diagFail('自检接口没给结果：HTTP ' + res.status + ' · ' + (json.msg || '（无说明）'));
        } else {
          // 非 JSON（多半是 500 的错误页）：把状态码和页面前 200 字直接摆出来，便于定位
          const plain = String(text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
          diagFail('自检接口 HTTP ' + res.status + '，返回的不是 JSON：' + (plain || '（空响应）'));
        }
      } catch (e) {
        diagFail('自检请求没发出去：' + ((e && e.message) || '网络错误'));
      }
      setDiagBusy(false);
    };

    const syncPanel = async function () {
      setPanelBusy(true);
      try {
        const json = await api('/api/admin/bot/panel/sync', { method: 'POST' });
        toast(json.msg, json.code === 0 ? 'success' : 'error');
        if (json.code === 0) load();
      } catch (e) { toast('同步失败', 'error'); }
      setPanelBusy(false);
    };
    const removePanel = async function () {
      if (!window.confirm('删除后群内不再显示指令快捷按钮，确定删除吗？')) return;
      setPanelBusy(true);
      try {
        const json = await api('/api/admin/bot/panel/remove', { method: 'POST' });
        toast(json.msg, json.code === 0 ? 'success' : 'error');
        if (json.code === 0) load();
      } catch (e) { toast('删除失败', 'error'); }
      setPanelBusy(false);
    };

    const unbind = async function () {
      if (!window.confirm('解绑后群内机器人指令将停用，确定解绑吗？')) return;
      try {
        const json = await api('/api/admin/bot/unbind', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const removeMember = async function (m) {
      if (!window.confirm('移除 ' + m.nickname + ' 的绑定吗？')) return;
      try {
        const json = await api('/api/admin/bot/members/' + m.id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (info === null) {
      return <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>;
    }
    // v5.9.0：战队独立机器人已启用（凭据齐全）时不再依赖平台机器人状态
    const ownBotActive = config.has_config && config.enabled && config.secret_set && config.app_id;
    return (
      <Stack spacing={2}>
        {ownBotActive ? (
          <Alert severity="success">
            本战队的机器人已启用，群内指令走这一台。
            注意：换过 AppID / AppSecret 之后，群与成员需要重新绑定一次（旧机器人的群标识对不上新机器人）。
          </Alert>
        ) : (
          <Alert severity="warning" sx={{ whiteSpace: 'pre-line' }}>
            {'还没启用本战队的机器人，群内指令不会响应。接入步骤：\n'
              + '1. 在 QQ 开放平台（bot.q.qq.com）申请一个机器人，取 AppID 与 AppSecret 填到下方并启用；\n'
              + '2. 把本页给出的专属回调地址整行复制到机器人后台「消息推送 / Webhook」，勾选「群聊 @机器人」；\n'
              + '3. 点「测试连接」确认凭据可用，再把机器人拉进战队群，群管理员 @机器人 发「绑定」取验证码回来回填。'}
          </Alert>
        )}

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>本战队的机器人</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5, whiteSpace: 'pre-line' }}>
            {'这台机器人只服务本战队：群里 @它 的指令、签到、日程查询都走这一台，别的战队用不到它。\n'
              + '仅战队超管或被授予「QQ机器人」权限的子管理员可修改。'}
          </Typography>
          <Stack spacing={2}>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
              <TextField size="small" label="AppID" value={config.app_id}
                onChange={(e) => setConfig({ ...config, app_id: e.target.value })}
                inputProps={{ maxLength: 64 }} />
              <TextField size="small"
                label={config.secret_set ? 'AppSecret（已保存）' : 'AppSecret'}
                helperText={config.secret_set ? '留空保存表示保持原值不变' : ''}
                type="password" value={config.app_secret}
                onChange={(e) => setConfig({ ...config, app_secret: e.target.value })}
                inputProps={{ maxLength: 128 }} />
              <TextField select size="small" label="接口环境" value={config.env}
                onChange={(e) => setConfig({ ...config, env: e.target.value })} sx={{ width: 148 }}>
                <MenuItem value="prod">正式环境</MenuItem>
                <MenuItem value="sandbox">沙箱环境</MenuItem>
              </TextField>
              <FormControlLabel
                control={<Switch checked={!!config.enabled} onChange={(e) => setConfig({ ...config, enabled: e.target.checked })} />}
                label="启用" />
            </Stack>
            <Stack direction="row" spacing={1.5} flexWrap="wrap" useFlexGap>
              <Button variant="contained" disabled={configBusy} onClick={saveConfig}>保存配置</Button>
              <Button variant="outlined" disabled={configBusy || !config.has_config}
                onClick={testConn}>测试连接</Button>
              <Button variant="outlined" disabled={diagBusy} onClick={runDiag}>
                {diagBusy ? '自检中…' : '运行自检'}
              </Button>
              {config.has_config && (
                <Button variant="outlined" color="error" disabled={configBusy} onClick={clearConfig}>删除配置</Button>
              )}
            </Stack>
            {config.has_config && config.callback_url && (
              <Alert severity="info">
                专属回调地址：{location.origin + config.callback_url}
                <Typography variant="caption" sx={{ display: 'block', mt: 0.5 }}>
                  请把该地址（整行）填写到 QQ 机器人管理后台的消息推送 / Webhook 配置中。
                </Typography>
              </Alert>
            )}
          </Stack>
        </Paper>

        {diag && (
          <Paper variant="outlined" sx={{ p: 2.5 }}>
            <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
              <Typography variant="subtitle1" fontWeight={600} sx={{ flex: 1 }}>自检结果</Typography>
              <Chip size="small" variant="outlined" label={'版本 ' + diag.version} />
              <Button size="small" disabled={diagBusy} onClick={runDiag}>重新自检</Button>
            </Stack>
            <Alert severity={diag.verdict_level || 'info'} sx={{ whiteSpace: 'pre-line' }}>{diag.verdict}</Alert>
            {diag.last_event_at ? (
              <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 1 }}>
                最近一次收到 QQ 推送：{diag.last_event_at}
              </Typography>
            ) : null}
            {diag.ai ? (
              <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 0.5 }}>
                AI 聊天：{diag.ai.mode === 'off' ? '未开启' : (diag.ai.mode === 'own' ? '自有模型' : '平台模型')}
                {' · 自动接话 ' + (Number(diag.ai.auto_reply) === 1 ? '已开' : '关闭')}
                {diag.ai.mode === 'platform' ? ' · Token 余额 ' + diag.ai.balance : ''}
                {diag.ai.provider_ok ? (' · 模型 ' + (diag.ai.model || '已配置')) : (' · 模型不可用：' + (diag.ai.provider_error || '未知原因'))}
                {(diag.ai.backlog || 0) > 0 ? (' · 队列 ' + diag.ai.backlog + ' 条') : ''}
                {diag.ai.last_error ? ' · 最近失败：' + diag.ai.last_error : ''}
              </Typography>
            ) : null}
            {diag.dns && Object.keys(diag.dns).length > 0 ? (
              <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 0.5 }}>
                出站域名解析：
                {Object.keys(diag.dns).map(function (h) {
                  const d = diag.dns[h] || {};
                  return h + (d.ip ? '→' + d.ip + '（' + d.ms + 'ms）' : '→失败');
                }).join(' · ')}
              </Typography>
            ) : null}
            <Stack spacing={0.75} sx={{ mt: 1.5 }}>
              {diag.checks.map(function (c, i) {
                return (
                  <Stack key={i} direction="row" spacing={1} alignItems="flex-start">
                    <Chip size="small" color={c.ok ? 'success' : 'error'} variant="outlined"
                      label={c.ok ? '通过' : '未通过'} sx={{ flexShrink: 0 }} />
                    <Box sx={{ minWidth: 0 }}>
                      <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY }}>{c.name}</Typography>
                      {c.detail ? (
                        <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY }}>{c.detail}</Typography>
                      ) : null}
                    </Box>
                  </Stack>
                );
              })}
            </Stack>
            {diag.callback_url ? (
              <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 1.5, wordBreak: 'break-all' }}>
                本队回调地址：{location.origin + diag.callback_url}
              </Typography>
            ) : null}
            {diag.errors.length > 0 && (
              <Box sx={{ mt: 1.5 }}>
                <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.5 }}>
                  最近报错（{diag.errors.length}）
                </Typography>
                {diag.errors.map(function (e, i) {
                  return (
                    <Typography key={i} sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY, wordBreak: 'break-all' }}>
                      {e.ts} · {e.type} · {e.detail || '（无详情）'}
                    </Typography>
                  );
                })}
              </Box>
            )}
            {diag.events.length > 0 && (
              <Box sx={{ mt: 1.5 }}>
                <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.5 }}>最近收到的 QQ 推送</Typography>
                <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mb: 0.5 }}>
                  共 {diag.stats.total} 条 · @ 消息 {diag.stats.at} · 全量群消息 {diag.stats.full} · 其它 {diag.stats.other} · 被跳过 {diag.stats.skipped}
                </Typography>
                {diag.events.slice(0, 5).map(function (e, i) {
                  return (
                    <Typography key={i} sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY, wordBreak: 'break-all' }}>
                      {e.ts} · {e.type} · 群 {e.group || '—'} · bot={e.bot_raw} · {e.len} 字 · @前缀={e.mention}
                    </Typography>
                  );
                })}
              </Box>
            )}
            {diag.calls.length > 0 && (
              <Box sx={{ mt: 1.5 }}>
                <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.5 }}>最近指令</Typography>
                {diag.calls.map(function (c, i) {
                  return (
                    <Typography key={i} sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY }}>
                      {c.created_at} · {c.command || '（空）'} · {c.content}
                    </Typography>
                  );
                })}
              </Box>
            )}
            {diag.jobs.length > 0 && (
              <Box sx={{ mt: 1.5 }}>
                <Typography sx={{ fontSize: 13, fontWeight: 600, color: TEXT_PRIMARY, mb: 0.5 }}>最近 AI 任务</Typography>
                {diag.jobs.map(function (j, i) {
                  return (
                    <Typography key={i} sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY }}>
                      {j.created_at} · {j.kind} · {j.status}{j.error ? ' · ' + j.error : ''}
                    </Typography>
                  );
                })}
              </Box>
            )}
          </Paper>
        )}

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>群指令面板</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            生成后，已绑定群的聊天窗口下方会多出一排指令快捷按钮（签到 / 地图 / 改枪码 / 日程 / 我的战绩 / 战绩榜 …），
            成员点按即把指令填进输入框，@机器人 发送即可。面板是本队机器人自己的，跟别的战队互不影响。
          </Typography>
          <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap>
            <Button variant="contained" disabled={panelBusy || !config.has_config}
              onClick={syncPanel}>{config.panel_id ? '重新生成面板' : '生成指令面板'}</Button>
            {config.panel_id ? (
              <Button variant="outlined" color="error" disabled={panelBusy} onClick={removePanel}>删除面板</Button>
            ) : null}
            <Typography variant="caption" color="text.secondary">
              {config.panel_id ? '面板已生成（ID：' + config.panel_id + '）' : '尚未生成'}
            </Typography>
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>群绑定</Typography>
          {info.bound ? (
            <Stack spacing={1}>
              <Typography variant="body2">
                当前状态：<Chip size="small" color="success" label="已绑定" />
                <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1 }}>
                  绑定时间：{fmtTime(info.bound_at)} · 已绑定成员 {info.member_bindings} 人
                </Typography>
              </Typography>
              <Typography variant="caption" color="text.secondary">
                群标识（openid）：{info.group_openid}
              </Typography>
              <Box><Button variant="outlined" color="error" onClick={unbind}>解除群绑定</Button></Box>
            </Stack>
          ) : (
            <Stack spacing={1.5}>
              <Typography variant="body2" color="text.secondary" sx={{ whiteSpace: 'pre-line' }}>
                {'绑定步骤：\n'
                  + '1. 把机器人拉进战队 QQ 群（机器人后台开启「群聊」能力）；\n'
                  + '2. 群管理员在群里 @机器人 发送「绑定」，机器人会回复 6 位验证码（10 分钟有效）；\n'
                  + '3. 在下方填入验证码并确认，即可完成绑定（一个战队限绑一个群）。'}
              </Typography>
              <Stack direction="row" spacing={1.5}>
                <TextField size="small" label="群绑定验证码" value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  inputProps={{ inputMode: 'numeric', style: { letterSpacing: 4 } }} />
                <Button variant="contained"
                  disabled={busy || !ownBotActive} onClick={verify}>确认绑定</Button>
              </Stack>
            </Stack>
          )}
        </Paper>

        <Paper variant="outlined" sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>
            成员绑定名单（{members.length}）
          </Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            成员在个人中心「QQ机器人绑定」取码，群内 @机器人 发「绑定 六位码」完成；
            签到等个人功能依赖此绑定。成员换 QQ 后可在此移除旧绑定。
          </Typography>
          {members.length === 0 ? <EmptyBox text="暂无成员绑定" /> : (
            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>昵称</TableCell><TableCell>绑定时间</TableCell><TableCell>操作</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {members.map(function (m) {
                    return (
                      <TableRow key={m.id}>
                        <TableCell>{m.nickname}</TableCell>
                        <TableCell>{fmtTime(m.created_at)}</TableCell>
                        <TableCell>
                          <Button size="small" color="error" onClick={() => removeMember(m)}>移除</Button>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        </Paper>
      </Stack>
    );
  }

  // ---------- 抽奖管理（管理端：奖池/奖品/机会发放/中奖核销） ----------
  function AdminLottery() {
    const [data, setData] = useState(null);
    const [poolEdit, setPoolEdit] = useState(null);
    const [prizeEdit, setPrizeEdit] = useState(null);
    const [grantOpen, setGrantOpen] = useState(false);

    const load = useCallback(async function () {
      try {
        const json = await api('/api/admin/lottery');
        if (json.code === 0) setData(json.data);
      } catch (e) { setData({ pools: [], chances: [], logs: [] }); }
    }, []);

    useEffect(function () { load(); }, [load]);

    const removePool = async function (p) {
      if (!window.confirm('删除奖池「' + p.name + '」会同时删除其全部奖品，确定吗？')) return;
      try {
        const json = await api('/api/admin/lottery/pools/' + p.id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const removePrize = async function (z) {
      if (!window.confirm('删除奖品「' + z.name + '」，确定吗？')) return;
      try {
        const json = await api('/api/admin/lottery/prizes/' + z.id, { method: 'DELETE' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const togglePool = async function (p) {
      try {
        const json = await api('/api/admin/lottery/pools/save', {
          method: 'POST',
          body: { id: p.id, name: p.name, weight: p.weight, enabled: Number(p.enabled) === 1 ? 0 : 1 },
        });
        if (json.code === 0) { load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    const fulfill = async function (l) {
      try {
        const json = await api('/api/admin/lottery/logs/' + l.id + '/fulfill', { method: 'POST' });
        if (json.code === 0) { toast(json.msg, 'success'); load(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    if (!data) return <LoadingBox />;
    return (
      <Box>
        <Stack direction="row" spacing={1} sx={{ mb: 2 }}>
          <Button variant="contained" onClick={function () { setPoolEdit({ id: 0, name: '', weight: 1, enabled: 1 }); }}>新建奖池</Button>
          <Button variant="outlined" onClick={function () { setGrantOpen(true); }}>发放机会</Button>
        </Stack>

        <Typography fontWeight={600} sx={{ mb: 1 }}>奖池与奖品</Typography>
        {data.pools.length === 0 && <EmptyBox text="暂无奖池，先新建奖池再添加奖品" />}
        {data.pools.map(function (p) {
          return (
            <Paper key={p.id} variant="outlined" sx={{ p: 2, mb: 1.5 }}>
              <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }} flexWrap="wrap">
                <Typography fontWeight={600} sx={{ flex: 1 }}>{p.name}</Typography>
                <Chip size="small" variant="outlined" label={'权重 ' + p.weight} />
                {Number(p.enabled) === 1
                  ? <Chip size="small" color="success" variant="outlined" label={'命中率 ' + pct(p.prob)} />
                  : <Chip size="small" label="已停用" />}
                <Tooltip title={Number(p.enabled) === 1 ? '停用该奖池' : '开启该奖池'}>
                  <Switch size="small" checked={Number(p.enabled) === 1} onChange={function () { togglePool(p); }} />
                </Tooltip>
                <Button size="small" onClick={function () { setPoolEdit(p); }}>编辑</Button>
                <Button size="small" color="error" onClick={function () { removePool(p); }}>删除</Button>
              </Stack>
              {p.prizes.length === 0 ? (
                <Typography variant="caption" color="text.secondary">暂无奖品 —— 未添加奖品的奖池不会参与抽取</Typography>
              ) : (
                <TableContainer>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell>奖品</TableCell>
                        <TableCell align="center">权重</TableCell>
                        <TableCell align="center">池内概率</TableCell>
                        <TableCell align="center">库存</TableCell>
                        <TableCell align="right">操作</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {p.prizes.map(function (z) {
                        return (
                          <TableRow key={z.id}>
                            <TableCell>
                              {z.name}
                              {Number(z.is_thanks) === 1 && <Chip size="small" label="谢谢参与" sx={{ ml: 0.8 }} />}
                            </TableCell>
                            <TableCell align="center">{z.weight}</TableCell>
                            <TableCell align="center">{pct(z.prob)}</TableCell>
                            <TableCell align="center">
                              {Number(z.is_thanks) === 1 ? '—' : (Number(z.stock) < 0 ? '不限量' : z.stock)}
                            </TableCell>
                            <TableCell align="right">
                              <Button size="small" onClick={function () { setPrizeEdit(Object.assign({ pool_id: p.id }, z)); }}>编辑</Button>
                              <Button size="small" color="error" onClick={function () { removePrize(z); }}>删除</Button>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </TableContainer>
              )}
              <Button size="small" sx={{ mt: 1 }} onClick={function () {
                setPrizeEdit({ pool_id: p.id, id: 0, name: '', weight: 1, stock: -1, is_thanks: 0 });
              }}>+ 添加奖品</Button>
            </Paper>
          );
        })}

        <Typography fontWeight={600} sx={{ mb: 1, mt: 2.5 }}>成员机会台账</Typography>
        {data.chances.length === 0 ? <EmptyBox text="尚未发放过机会" /> : (
          <TableContainer component={Paper} variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>成员</TableCell>
                  <TableCell align="center">剩余机会</TableCell>
                  <TableCell align="center">已使用</TableCell>
                  <TableCell align="center">累计发放</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.chances.map(function (c) {
                  return (
                    <TableRow key={c.id}>
                      <TableCell>{c.nickname}</TableCell>
                      <TableCell align="center" sx={{ fontWeight: 600 }}>{c.chances}</TableCell>
                      <TableCell align="center">{c.used}</TableCell>
                      <TableCell align="center">{c.total}</TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        <Typography fontWeight={600} sx={{ mb: 1, mt: 2.5 }}>中奖记录（最近 200 条）</Typography>
        {data.logs.length === 0 ? <EmptyBox text="暂无抽奖记录" /> : (
          <TableContainer component={Paper} variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>时间</TableCell>
                  <TableCell>成员</TableCell>
                  <TableCell>结果</TableCell>
                  <TableCell align="center">发放状态</TableCell>
                  <TableCell align="right">操作</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.logs.map(function (l) {
                  const win = Number(l.prize_id) > 0;
                  return (
                    <TableRow key={l.id}>
                      <TableCell sx={{ whiteSpace: 'nowrap' }}>{fmtTime(l.created_at)}</TableCell>
                      <TableCell>{l.nickname}</TableCell>
                      <TableCell sx={{ fontWeight: win ? 600 : 400 }}>{l.prize_name}</TableCell>
                      <TableCell align="center">
                        {!win ? <Typography variant="caption" color="text.secondary">—</Typography>
                          : Number(l.fulfilled) === 1
                            ? <Chip size="small" color="success" variant="outlined" label="已发放" />
                            : <Chip size="small" color="warning" variant="outlined" label="待发放" />}
                      </TableCell>
                      <TableCell align="right">
                        {win && Number(l.fulfilled) !== 1 && (
                          <Button size="small" onClick={function () { fulfill(l); }}>标记已发放</Button>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        )}

        <LotteryPoolDialog value={poolEdit} onClose={function () { setPoolEdit(null); }}
          onDone={function () { setPoolEdit(null); load(); }} />
        <LotteryPrizeDialog value={prizeEdit} onClose={function () { setPrizeEdit(null); }}
          onDone={function () { setPrizeEdit(null); load(); }} />
        <LotteryGrantDialog open={grantOpen} onClose={function () { setGrantOpen(false); }}
          onDone={function () { setGrantOpen(false); load(); }} />
      </Box>
    );
  }

  function LotteryPoolDialog({ value, onClose, onDone }) {
    const [name, setName] = useState('');
    const [weight, setWeight] = useState('1');
    const [enabled, setEnabled] = useState(true);

    useEffect(function () {
      if (value) {
        setName(value.name || '');
        setWeight(String(value.weight || 1));
        setEnabled(Number(value.enabled) === 1);
      }
    }, [value]);

    if (!value) return null;

    const submit = async function () {
      try {
        const json = await api('/api/admin/lottery/pools/save', {
          method: 'POST',
          body: { id: value.id || 0, name: name.trim(), weight: Number(weight) || 1, enabled: enabled ? 1 : 0 },
        });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>{value.id ? '编辑奖池' : '新建奖池'}</DialogTitle>
        <DialogContent>
          <TextField label="奖池名称" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={name} onChange={(e) => setName(e.target.value)} inputProps={{ maxLength: 64 }}
            placeholder="例如：周赛奖池 / 欧皇奖池" />
          <TextField label="权重（越大概率越高）" fullWidth type="number" sx={{ mb: 1.5 }}
            value={weight} onChange={(e) => setWeight(e.target.value)} inputProps={{ min: 1, max: 100000 }} />
          <FormControlLabel control={<Switch checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />} label="开启（参与抽取）" />
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            概率自动换算：命中率 = 本奖池权重 ÷ 全部开启奖池权重之和
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>保存</Button>
        </DialogActions>
      </Dialog>
    );
  }

  function LotteryPrizeDialog({ value, onClose, onDone }) {
    const [name, setName] = useState('');
    const [weight, setWeight] = useState('1');
    const [stock, setStock] = useState('-1');
    const [thanks, setThanks] = useState(false);

    useEffect(function () {
      if (value) {
        setName(value.name || '');
        setWeight(String(value.weight || 1));
        setStock(String(value.stock !== undefined ? value.stock : -1));
        setThanks(Number(value.is_thanks) === 1);
      }
    }, [value]);

    if (!value) return null;

    const submit = async function () {
      const st = thanks ? -1 : (stock === '' || isNaN(Number(stock)) ? -1 : Number(stock));
      try {
        const json = await api('/api/admin/lottery/prizes/save', {
          method: 'POST',
          body: {
            id: value.id || 0,
            pool_id: value.pool_id,
            name: name.trim(),
            weight: Number(weight) || 1,
            stock: st,
            is_thanks: thanks ? 1 : 0,
          },
        });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>{value.id ? '编辑奖品' : '添加奖品'}</DialogTitle>
        <DialogContent>
          <TextField label="奖品名称" fullWidth required sx={{ mt: 1, mb: 2 }}
            value={name} onChange={(e) => setName(e.target.value)} inputProps={{ maxLength: 64 }}
            placeholder="例如：月卡一张 / 谢谢参与" />
          <TextField label="权重（越大概率越高）" fullWidth type="number" sx={{ mb: 1.5 }}
            value={weight} onChange={(e) => setWeight(e.target.value)} inputProps={{ min: 1, max: 100000 }} />
          <TextField label="库存（-1 = 不限量）" fullWidth type="number" disabled={thanks} sx={{ mb: 1 }}
            value={thanks ? '-1' : stock} onChange={(e) => setStock(e.target.value)} inputProps={{ min: -1 }} />
          <FormControlLabel control={<Switch checked={thanks} onChange={(e) => setThanks(e.target.checked)} />} label="设为「谢谢参与」（不占库存）" />
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            池内概率 = 本奖品权重 ÷ 池内全部奖品权重之和；库存抽到 0 后该奖品自动不再产出
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>保存</Button>
        </DialogActions>
      </Dialog>
    );
  }

  function LotteryGrantDialog({ open, onClose, onDone }) {
    const [mode, setMode] = useState('user');
    const [users, setUsers] = useState([]);
    const [userId, setUserId] = useState('');
    const [count, setCount] = useState('1');

    useEffect(function () {
      if (!open) return;
      setMode('user'); setUserId(''); setCount('1');
      (async function () {
        try {
          const json = await api('/api/admin/users?page=1&size=100');
          if (json.code === 0) {
            setUsers(json.data.list.filter(function (u) { return u.status === 'approved'; }));
          }
        } catch (e) { /* 忽略：成员列表加载失败时仅全员发放可用 */ }
      })();
    }, [open]);

    if (!open) return null;

    const submit = async function () {
      const c = Number(count) || 0;
      const body = { mode: mode, count: c };
      if (mode === 'user') {
        if (!userId) { toast('请选择成员', 'warning'); return; }
        body.user_id = Number(userId);
      } else if (!window.confirm('将给全部审核通过的成员各发放 ' + c + ' 次机会，确定吗？')) {
        return;
      }
      try {
        const json = await api('/api/admin/lottery/grant', { method: 'POST', body: body });
        if (json.code === 0) { toast(json.msg, 'success'); onDone(); } else { toast(json.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
    };

    return (
      <Dialog open onClose={onClose} fullWidth maxWidth="xs">
        <DialogTitle>发放抽奖机会</DialogTitle>
        <DialogContent>
          <TextField select fullWidth label="发放范围" sx={{ mt: 1, mb: 2 }}
            value={mode} onChange={(e) => setMode(e.target.value)}>
            <MenuItem value="user">指定成员</MenuItem>
            <MenuItem value="all">全员（审核通过的成员）</MenuItem>
          </TextField>
          {mode === 'user' && (
            <TextField select fullWidth label="成员" sx={{ mb: 2 }}
              value={userId} onChange={(e) => setUserId(e.target.value)}>
              {users.map(function (u) {
                return <MenuItem key={u.id} value={u.id}>{(u.nickname || u.username) + '（#' + u.id + '）'}</MenuItem>;
              })}
            </TextField>
          )}
          <TextField label="发放次数（1-10000）" fullWidth type="number"
            value={count} onChange={(e) => setCount(e.target.value)} inputProps={{ min: 1, max: 10000 }} />
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.5 }}>
            仅管理员可发放；机会累加到成员账户，抽一次消耗一次
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={onClose}>取消</Button>
          <Button variant="contained" onClick={submit}>发放</Button>
        </DialogActions>
      </Dialog>
    );
  }

  // ==================== 通知（v5.32.0：站内信独立成一页）====================
  // 论坛下线后，原来挂在论坛页顶部那张「通知」卡搬到这里独立成一页。
  // 一个红点装两件事：自己举报的东西有了结果、平台群发给自己的那一封（点开才回查整篇正文）。
  // 进这一页就把未读全标掉——这两档都不是「留一条未读当待办」的东西，逐条点只让人以为还有没看的。
  function NoticePage({ onRead }) {
    const [list, setList] = useState(null);
    const [err, setErr] = useState('');
    const [open, setOpen] = useState(0);
    const [full, setFull] = useState({});
    const gotRef = useRef({});

    const pull = function (bid) {
      setOpen(bid);
      if (gotRef.current[bid]) { return; }
      gotRef.current[bid] = 1;
      setFull(function (prev) {
        const n = Object.assign({}, prev); n[bid] = { loading: 1, err: '', data: null }; return n;
      });
      api('/api/inbox/blast?blast_id=' + bid).then(function (j) {
        setFull(function (prev) {
          const n = Object.assign({}, prev);
          n[bid] = Number(j.code) === 0
            ? { loading: 0, err: '', data: j.data || null }
            : { loading: 0, err: (j.msg || '这一封读不到了'), data: null };
          return n;
        });
      }).catch(function (e) {
        delete gotRef.current[bid];   // 没取到别让下一次点击哑掉
        setFull(function (prev) {
          const n = Object.assign({}, prev);
          n[bid] = { loading: 0, err: String((e && e.message) || e), data: null };
          return n;
        });
      });
    };

    const blastPane = function (bid) {
      const one = full[bid] || { loading: 1, err: '', data: null };
      return (
        <Box sx={{ mt: 0.75, p: 1.25, borderRadius: '12px', bgcolor: BG_GRAY, border: '1px solid ' + CARD_BORDER }}>
          {Number(one.loading) === 1 && (
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>正在取这一封…</Typography>
          )}
          {!!one.err && (
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>{one.err}</Typography>
          )}
          {!!one.data && (
            <Box>
              <Typography sx={{ fontSize: 13, fontWeight: 700, color: TEXT_PRIMARY, mb: 0.5, wordBreak: 'break-word' }}>
                {one.data.subject}
              </Typography>
              <Typography sx={{ fontSize: 13, lineHeight: '20px', color: '#3d4a63', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                {one.data.body}
              </Typography>
            </Box>
          )}
        </Box>
      );
    };

    useEffect(function () {
      api('/api/inbox?limit=50').then(function (j) {
        const d = j.data || {};
        const arr = d.list || [];
        setList(arr);
        if (arr.length > 0 && Number(d.unread || 0) > 0) {
          api('/api/inbox/read', { method: 'POST', body: {} })
            .then(function () { if (onRead) { onRead(); } })
            .catch(function () { /* 已读没标上也先让人看完这一屏 */ });
        }
      }).catch(function (e) { setErr(String(e.message || e)); setList([]); });
    }, []);   // eslint-disable-line

    return (
      <Paper sx={{ p: { xs: 2, md: 3 }, minHeight: 320 }}>
        <Stack direction="row" spacing={1} alignItems="baseline" sx={{ flexWrap: 'wrap', useFlexGap: true }}>
          <Typography sx={{ fontSize: 15, fontWeight: 700, color: TEXT_PRIMARY }}>通知</Typography>
          {Array.isArray(list) && list.length > 0 && (
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY }}>
              这里只留最近 {list.length} 条
            </Typography>
          )}
        </Stack>
        <Typography sx={{ fontSize: 12, lineHeight: '18px', color: TEXT_SECONDARY, mt: 0.5 }}>
          你递上去的举报有了结果、平台发给你的站内信、本队到点的赛前提醒，都落在这一页。
        </Typography>

        {!!err && (
          <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY, py: 2 }}>{err}</Typography>
        )}
        {!err && list === null && (
          <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY, py: 2 }}>正在取…</Typography>
        )}
        {!err && list !== null && list.length === 0 && (
          <Box sx={{ py: 4, textAlign: 'center' }}>
            <Typography sx={{ fontSize: 13, color: TEXT_SECONDARY }}>还没有通知。</Typography>
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 0.5 }}>
              举报过的东西有了结果、平台给你发过站内信、本队有到点的赛前提醒，都会出现在这里。
            </Typography>
          </Box>
        )}
        {(list || []).map(function (t) {
          const bid = Number(t.blast || 0);
          return (
            <Box key={t.id} sx={{ py: 1.5, borderTop: '1px solid ' + CARD_BORDER }}>
              <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5, flexWrap: 'wrap', gap: 0.5 }}>
                {Number(t.read || 0) === 0 && (
                  <Box sx={{ width: 7, height: 7, borderRadius: '999px', bgcolor: 'error.main', flexShrink: 0 }} />
                )}
                <Chip size="small" label={t.kindName} sx={{ height: 20, fontSize: 11, bgcolor: BG_GRAY, color: '#3d4a63' }} />
                {!!t.from && (
                  <Typography sx={{ fontSize: 11, color: TEXT_SECONDARY }}>{t.from}</Typography>
                )}
                <Typography sx={{ fontSize: 11, color: TEXT_SECONDARY, ml: 'auto' }}>{fmtTime(t.at)}</Typography>
              </Stack>
              <Typography sx={{ fontSize: 13, lineHeight: '20px', color: '#3d4a63', wordBreak: 'break-word' }}>
                {t.note}
              </Typography>
              {bid > 0 && (
                <Box sx={{ mt: 0.5 }}>
                  <Button size="small" onClick={function () {
                    if (open === bid) { setOpen(0); } else { pull(bid); }
                  }}>{open === bid ? '收起这一封' : '看这一封全文'}</Button>
                  {open === bid && blastPane(bid)}
                </Box>
              )}
            </Box>
          );
        })}
      </Paper>
    );
  }

  // ==================== 主框架 ====================
  const DRAWER_WIDTH = 232;
  const NOTICE_ICON = 'M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22zm7-5H5a1 1 0 0 1 0-2c.6 0 1-.4 1-1v-4a6 6 0 0 1 4-5.66V4a2 2 0 1 1 4 0v.34A6 6 0 0 1 18 10v4c0 .6.4 1 1 1a1 1 0 0 1 0 2z';
  const NAV_ITEMS = [
    ['dashboard', '仪表盘', 'M4 4h7v7H4V4zm9 0h7v4h-7V4zM4 13h7v7H4v-7zm9-3h7v10h-7V10z'],
    ['images', '游戏图片', 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 2v9.2l4.2-4.2 3 3L15 12l5 5V7H5zm3.5 1A1.5 1.5 0 1 1 8.5 11 1.5 1.5 0 0 1 8.5 8z'],
    ['guides', '游戏攻略', 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 1.5V8h4.5L14 3.5zM8 12h8v2H8v-2zm0 4h8v2H8v-2z'],
    ['rotation', '今天轮换地图', 'M12 4V1L8 5l4 4V6a6 6 0 0 1 5.66 8l1.92.64A8 8 0 0 0 12 4zm0 14a6 6 0 0 1-5.66-4l-1.92-.64A8 8 0 0 0 12 20v3l4-4-4-4v3z'],
    ['chat', '聊天室', 'M4 3h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H8.4L4 21.6A.6.6 0 0 1 3 21V4a1 1 0 0 1 1-1zm3 6v2h10V9H7zm0 4v2h6v-2H7z'],
    ['notice', '通知', NOTICE_ICON],
    ['guncodes', '改枪码', 'M3 5h18v4h-2v3h-4v2h-2v-2H9v4H7v-4H5v6H3V5zm2 2v3h2V7H5zm4 0v3h2V7H9zm4 0v3h2V7h-2z'],
    ['teams', '战队小队', 'M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm0 2.2L6 6.4V11c0 3.9 2.6 7.6 6 8.9 3.4-1.3 6-5 6-8.9V6.4l-6-2.2zM11 7h2v4h4v2h-6V7z'],
    ['customrace', '自建房比赛日程', 'M19 3h-1V1h-2v2H8V1H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 16H5V9h14v10zM5 7V5h14v2H5zm2 4h5v5H7v-5z'],
    ['schedule', '队内日程', 'M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 16H5V9h14v10z'],
    ['training', '队内训练赛', 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm0-14a6 6 0 1 0 6 6 6 6 0 0 0-6-6zm0 10a4 4 0 1 1 4-4 4 4 0 0 1-4 4zm0-6a2 2 0 1 0 2 2 2 2 0 0 0-2-2z'],
    ['votes', '队内投票', 'M18 13h-.68l-2 2h1.91L19 17H5l1.78-2h2.05l-2-2H6l-3 3v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4zm-1-5.05l-4.49-4.49a2 2 0 0 0-2.83 0L5 8.11a2 2 0 0 0 0 2.83l4.49 4.49a2 2 0 0 0 2.83 0l4.68-4.68a2 2 0 0 0 0-2.5z'],
    ['lottery', '幸运转盘', 'M12 2a10 10 0 1 1 0 20 10 10 0 1 1 0-20zM12 5a7 7 0 1 0 0 14 7 7 0 1 0 0-14zM11 4h2v16h-2zM4 11h16v2H4z'],
    ['race', '撞车比赛', 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm1-13h-2v6l5.2 3.1 1-1.7-4.2-2.5z'],
    ['members', '成员列表', 'M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z'],
    ['mystats', '我的战绩', 'M4 20h3V10H4v10zm6.5 0h3V4h-3v16zM17 20h3v-7h-3v7z'],
    ['profile', '个人中心', 'M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12zm0 2c-4 0-8 2-8 5v2h16v-2c0-3-4-5-8-5z'],
    ['traitor', '查询伪军', 'M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm-1 5v6h2V7h-2zm0 8v2h2v-2h-2z'],
    ['about', '关于', 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z'],
  ];
  const ADMIN_ICON = 'M12 1l9 4v6c0 5.6-3.8 10.7-9 12-5.2-1.3-9-6.4-9-12V5l9-4zm0 10.9a2.1 2.1 0 1 0 0-4.2 2.1 2.1 0 0 0 0 4.2zM12 22c3.8-1 6.9-5.1 6.9-9.1-.5.5-1.2.8-1.9.8a4.2 4.2 0 0 1-8-.9 2.4 2.4 0 0 1-2-.9c0 4 3.1 8.1 7 9.1h2z';

  // v5.32.0：论坛下线，「通知」接过那一格独立入口；聊天室仍是独立入口，其余按三组摆，管理中心单独放最底下
  const NAV_STANDALONE = ['dashboard', 'notice', 'chat'];
  const NAV_GROUPS = [
    ['act', '队内活动', ['schedule', 'training', 'votes', 'customrace', 'race', 'teams']],
    ['lib', '资料库', ['images', 'guides', 'guncodes', 'rotation']],
    ['me', '我的', ['members', 'mystats', 'profile', 'traitor', 'lottery', 'about']],
  ];
  const NAV_ADMIN = ['admin'];
  // 手机底部五格：提到模块级，功能页要靠它把「已经有 tab」的入口从列表里剔掉
  const MOBILE_TABS = [
    ['features', '功能', 'M3 3h8v8H3V3zm10 0h8v8h-8V3zM3 13h8v8H3v-8zm10 0h8v8h-8v-8z'],
    ['dashboard', '仪表盘', 'M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3l9-8z'],
    ['notice', '通知', NOTICE_ICON],
    ['chat', '聊天室', 'M4 3h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H8.4L4 21.6A.6.6 0 0 1 3 21V4a1 1 0 0 1 1-1zm3 6v2h10V9H7zm0 4v2h6v-2H7z'],
    ['profile', '我的', 'M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12zm0 2c-4 0-8 2-8 5v2h16v-2c0-3-4-5-8-5z'],
  ];
  const NAV_SHUT = 'yys_nav_collapsed';
  function getCollapsed() {
    try {
      const v = JSON.parse(localStorage.getItem(NAV_SHUT) || '{}');
      return (v && typeof v === 'object' && !Array.isArray(v)) ? v : {};
    } catch (e) { return {}; }
  }
  function storeCollapsed(v) {
    try { localStorage.setItem(NAV_SHUT, JSON.stringify(v)); } catch (e) { /* 存不下就每次默认全展开 */ }
  }
  // 顺序以 keys 为准，navItems 里没有的键跳过（入口被关掉时不会留下空格子）
  function pickNav(navItems, keys) {
    const out = [];
    keys.forEach(function (k) {
      const hit = navItems.filter(function (it) { return it[0] === k; })[0];
      if (hit) { out.push(hit); }
    });
    return out;
  }
  function navGroups(navItems, dropKeys) {
    const drop = dropKeys || [];
    return NAV_GROUPS.map(function (g) {
      const items = pickNav(navItems, g[2]).filter(function (it) { return drop.indexOf(it[0]) < 0; });
      return items.length > 0 ? { key: g[0], name: g[1], items: items } : null;
    }).filter(function (x) { return !!x; });
  }

  // 战队群在站内没有「谁读到哪一条」的记录（私聊有，是服务端算的）。
  // 群那半改成本机记「上次看到的最后一条 id」：换浏览器不认，但不用动库、也不用新接口。
  const CHAT_SEEN_KEY = 'yys_chat_group_seen';
  function groupSeenGet() {
    try {
      const v = JSON.parse(localStorage.getItem(CHAT_SEEN_KEY) || '{}');
      if (!v || typeof v !== 'object') { return -1; }
      const n = Number(v[BASE]);
      return isFinite(n) && v[BASE] !== undefined && v[BASE] !== null ? n : -1;
    } catch (e) { return -1; }
  }
  function groupSeenSet(id) {
    try {
      let v = JSON.parse(localStorage.getItem(CHAT_SEEN_KEY) || '{}');
      if (!v || typeof v !== 'object' || Array.isArray(v)) { v = {}; }
      v[BASE] = Number(id) || 0;
      localStorage.setItem(CHAT_SEEN_KEY, JSON.stringify(v));
    } catch (e) { /* 存不下就每次重新记基准，不会亮错红点 */ }
  }
  const BRAND_ICON = 'M7 6h10a5 5 0 0 1 5 5v2a5 5 0 0 1-8.9 3.1l-.6-.7h-1l-.6.7A5 5 0 0 1 2 13v-2a5 5 0 0 1 5-5zm0 2a3 3 0 0 0-3 3v2a3 3 0 0 0 5.3 1.9l1.2-1.4h3l1.2 1.4A3 3 0 0 0 20 13v-2a3 3 0 0 0-3-3H7zm1 1.5h1v1.5h1.5v1H9v1.5H8V12H6.5v-1H8V9.5zm8.5.2a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8zm2 2a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8z';

  function NavIcon({ d }) {
    return <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d={d} /></svg>;
  }

  // 侧栏一行：独立入口、分组里的行、管理入口共用，激活态还是主色 10% 底
  function NavRow({ it, active, badge }) {
    return (
      <ListItemButton component="a" href={'#/' + it[0]}
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
        {it[0] === 'admin' && !(badge > 0) && <Chip size="small" color="primary" label="管理" />}
      </ListItemButton>
    );
  }

  // v5.36.13：队长登录后的强制授权弹窗。点“暂不授权”只关本次登录，下次登录还会问；
  // 两个按钮都能按下去，不会把队长自己挡在任何页面之外。
  function SupportGrantDialog({ onGranted, onDismiss }) {
    const [busy, setBusy] = useState('');
    const grant = async function () {
      setBusy('grant');
      try {
        const j = await api('/api/support/grant', { method: 'POST' });
        if (j.code === 0) {
          toast(j.msg, 'success');
          onGranted();
        } else { toast(j.msg, 'error'); }
      } catch (e) { toast('操作失败', 'error'); }
      setBusy('');
    };
    const skip = async function () {
      setBusy('skip');
      try { await api('/api/support/dismiss', { method: 'POST' }); } catch (e) { /* 关掉就好 */ }
      setBusy('');
      onDismiss();
    };
    return (
      <Dialog open fullWidth maxWidth="sm" disableEscapeKeyDown onClose={function () {}}>
        <DialogTitle>授权平台管理员协作</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" sx={{ mb: 1.5 }}>
            平台超级管理员可以在你授权后进入本站管理中心，协助处理你不会操作的设置与功能权限。
          </Typography>
          <Stack component="ul" spacing={0.8} sx={{ pl: 2.5, m: 0 }}>
            <Typography component="li" variant="body2" color="text.secondary">
              授权长期有效，你可以在「管理中心 → 平台协作」随时撤销。
            </Typography>
            <Typography component="li" variant="body2" color="text.secondary">
              协作期间只能操作管理中心，每一步管理操作都会写入本站操作日志。
            </Typography>
            <Typography component="li" variant="body2" color="text.secondary">
              你的账号密码、通行证绑定和其它设备的免登录状态不会被改动。
            </Typography>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={skip} disabled={busy !== ''} color="inherit">暂不授权</Button>
          <Button onClick={grant} disabled={busy !== ''} variant="contained">同意授权</Button>
        </DialogActions>
      </Dialog>
    );
  }

  /**
   * 强制平台公告（v5.36.31）：平台超管写的那一条，登录战队就挡在界面最前面，不点走不掉。
   * 一次只弹一条（服务端给的是我最久没处理的那一条）；点「我已知晓」这一条不再弹，
   * 点「不再提示」以后所有强制公告都不弹——那一颗钮平台可以整颗关掉，关了就只显示「我已知晓」。
   */
  function PlatformNoticeGuard() {
    const [n, setN] = useState(undefined);   // undefined=还没取到，null=没有要弹的
    const [busy, setBusy] = useState('');

    useEffect(function () {
      let dead = false;
      api('/api/force-notice/current')
        .then(function (j) { if (!dead) { setN(j.code === 0 && j.data && j.data.notice ? j.data.notice : null); } })
        .catch(function () { if (!dead) { setN(null); } });   // 拉不到就当没公告，别拦着人正常使用
      return function () { dead = true; };
    }, []);

    if (!n) return null;

    const act = async function (kind) {
      if (busy) return;
      setBusy(kind);
      try {
        const j = await api('/api/force-notice/' + kind, { method: 'POST', body: { id: n.id } });
        if (j.code === 0) { setN(null); toast(j.msg || '已记下', 'success'); }
        else { toast(j.msg || '没记上，再点一次', 'error'); setBusy(''); }
      } catch (e) { toast('没记上，再点一次', 'error'); setBusy(''); }
    };

    return (
      <Dialog open fullWidth maxWidth="xs" disableEscapeKeyDown keepMounted={false}
        onClose={function () {}}>
        <DialogTitle sx={{ pb: 0.5 }}>
          <Stack direction="row" spacing={0.75} alignItems="center" flexWrap="wrap" useFlexGap>
            <Chip size="small" color="primary" label="平台公告" sx={{ height: 20, fontSize: 11, fontWeight: 700 }} />
            <Typography variant="subtitle1" component="span" sx={{ fontWeight: 700 }}>{n.title}</Typography>
          </Stack>
        </DialogTitle>
        <DialogContent dividers>
          <Typography sx={{ fontSize: 14, lineHeight: '23px', color: 'text.primary',
            whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{n.content}</Typography>
          {(n.at || n.actor) && (
            <Typography sx={{ fontSize: 12, color: 'text.secondary', mt: 1 }}>
              {[n.at, n.actor].filter(Boolean).join(' · ')}
            </Typography>
          )}
          <Typography sx={{ fontSize: 12, color: 'text.secondary', mt: 1 }}>
            这条是平台统一发的，点一下确认看到才关得掉；同一时间有几条就分几次登录点完。
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          {!!Number(n.mute) && (
            <Button onClick={function () { act('mute'); }} disabled={busy !== ''} color="inherit">不再提示</Button>
          )}
          <Button onClick={function () { act('ack'); }} disabled={busy !== ''} variant="contained">我已知晓</Button>
        </DialogActions>
      </Dialog>
    );
  }

  function Root() {
    const [me, setMe] = useState(null);
    const [loadErr, setLoadErr] = useState(false);
    const [tenant, setTenant] = useState(null);   // v4.0.0：当前站点租户品牌信息（公开接口）
    // hash 上可以挂查询段（#/chat?dm=7&name=张三）：路由名只取问号前面那截
    const [route, setRoute] = useState(function () { return (location.hash.replace('#/', '') || 'dashboard').split('?')[0]; });
    const [snack, setSnack] = useState({ open: false, msg: '', sev: 'info' });
    const [lbSrc, setLbSrc] = useState('');
    const [searchKw, setSearchKw] = useState('');   // v6.0：顶栏全局搜索
    // v5.36.13：队长本次登录是否已经处理过协作授权弹窗
    const [supportPromptOff, setSupportPromptOff] = useState(false);
    // 主题色：个人中心调色盘可改，仅存本地 localStorage
    const [primary, setPrimary] = useState(getStoredPrimary);
    // v5.36.0：端手分离——当前专区（手机/电脑），存本地；服务端会按人限制能看哪端
    const [gsZone, setGsZoneState] = useState(getStoredZone);
    const setGsZone = useCallback(function (z) {
      if (z !== 'pc' && z !== 'mobile') return;
      storeZone(z);
      setGsZoneState(z);
    }, []);
    const dynTheme = useMemo(function () { return makeTheme(primary); }, [primary]);
    const isMdUp = useMediaQuery(dynTheme.breakpoints.up('md'));
    const gsInfo = (me && me.game_side) || { on: false, modules: {}, allowed: [], mine: '', module_labels: {} };
    const gsAllowed = (gsInfo.allowed && gsInfo.allowed.length) ? gsInfo.allowed : ['pc', 'mobile'];
    // 关着时也把 zone 定下来（本端 or 已存的选择），这样开总开关的那一刻
    // zone 不会从空变成某一端、把整个页面重挂载（实测会把管理端页签跳回第一个）
    const gsOwn = (gsInfo.mine === 'pc' || gsInfo.mine === 'mobile') ? gsInfo.mine : '';
    const gsZoneNow = gsZone !== ''
      ? (gsAllowed.indexOf(gsZone) >= 0 ? gsZone : gsAllowed[0])
      : (gsOwn !== '' && gsAllowed.indexOf(gsOwn) >= 0 ? gsOwn : gsAllowed[0]);
    // 必须在渲染期就把专区写进模块变量：子组件的 effect 先于父组件执行，
    // 放在下面那个 effect 里会晚一拍，切专区的第一轮请求还带着上一端的头（实测列表没变）
    GS_ZONE = gsInfo.on ? gsZoneNow : '';
    useEffect(function () {
      if (gsZoneNow !== gsZone) { setGsZoneState(gsZoneNow); }
    }, [gsZoneNow, gsZone]);
    const gsCtx = {
      on: gsInfo.on,
      zone: gsZoneNow,
      setZone: setGsZone,
      allowed: gsAllowed,
      mine: gsInfo.mine || '',
      lock: gsInfo.lock || '',
      modules: gsInfo.modules || {},
      labels: gsInfo.module_labels || {},
    };

    // 注册全局 toast / lightbox 实现
    useEffect(function () {
      _toast = function (msg, sev) { setSnack({ open: true, msg: msg, sev: sev || 'info' }); };
      _lightbox = function (src) { setLbSrc(src); };
      return function () { _toast = function () {}; _lightbox = function () {}; };
    }, []);

    const refreshMe = useCallback(async function () {
      const json = await api('/api/auth/me');
      setMe(json.data);
    }, []);

    useEffect(function () {
      (async function () {
        try { await refreshMe(); } catch (e) { setLoadErr(true); }
      })();
    }, [refreshMe]);

    // v5.32.0：站内信未读数。顶栏铃铛、侧栏「通知」与手机底部那一格共用这一个数；
    // 点开「通知」页把未读标掉，页里回调把这个数落回 0，不用再拉一次
    const [noticeUnread, setNoticeUnread] = useState(0);
    useEffect(function () {
      if (!me) { return; }
      api('/api/inbox?limit=1').then(function (j) {
        setNoticeUnread(Number((j.data && j.data.unread) || 0));
      }).catch(function () { /* 拉不到就当没有红点 */ });
    }, [me]);

    // 聊天室的红点：私聊那半是服务端算的（跨设备一致），群那半靠本机记的已读位
    const [chatUnread, setChatUnread] = useState(0);
    const dmN = useRef(0);
    const groupN = useRef(0);
    const pushChat = function () { setChatUnread(dmN.current + groupN.current); };
    const pollChat = useCallback(async function () {
      try {
        const j = await api('/api/chat/dm/conversations');
        let n = 0;
        (((j.data || {}).list) || []).forEach(function (c) { n += Number(c.unread || 0); });
        dmN.current = n; pushChat();
      } catch (e) { /* 私聊被关或拉不到：那一半按 0 算，不影响群的 */ }
      const seen = groupSeenGet();
      try {
        if (seen < 0) {
          // 这台设备第一次进来：只把基准记下来，不点亮红点（不然一上线就背几十条）
          const j = await api('/api/chat/messages');
          let mx = 0;
          ((((j.data || {}).list) || [])).forEach(function (m) { if (Number(m.id) > mx) { mx = Number(m.id); } });
          groupSeenSet(mx);
        } else {
          const j = await api('/api/chat/messages?after_id=' + seen);
          groupN.current = (((j.data || {}).list) || []).length; pushChat();
        }
      } catch (e) { /* 静默：群拉不到就当没新消息 */ }
    }, []);
    useEffect(function () {
      if (!me) { return undefined; }
      pollChat();
      const t = setInterval(function () { if (!document.hidden) { pollChat(); } }, 20000);
      const onVis = function () { if (!document.hidden) { pollChat(); } };
      document.addEventListener('visibilitychange', onVis);
      return function () { clearInterval(t); document.removeEventListener('visibilitychange', onVis); };
    }, [me, pollChat]);
    const onDmUnread = function (n) { dmN.current = Number(n) || 0; pushChat(); };
    const onGroupSeen = function (id) {
      const nid = Number(id) || 0;
      if (nid > groupSeenGet()) { groupSeenSet(nid); }
      groupN.current = 0; pushChat();
    };

    // 侧栏分组的折叠状态：存本地，默认全展开
    const [collapsed, setCollapsed] = useState(getCollapsed);
    const toggleGroup = function (k) {
      setCollapsed(function (prev) {
        const next = Object.assign({}, prev);
        if (next[k]) { delete next[k]; } else { next[k] = true; }
        storeCollapsed(next);
        return next;
      });
    };
    useEffect(function () {
      // 当前页落在某个被折起来的组里，就把它展开——别让高亮项藏起来
      const g = NAV_GROUPS.filter(function (x) { return x[2].indexOf(route) >= 0; })[0];
      if (g && collapsed[g[0]]) {
        const next = Object.assign({}, collapsed);
        delete next[g[0]];
        storeCollapsed(next);
        setCollapsed(next);
      }
    }, [route]);   // eslint-disable-line

    // v5.0.0：站点品牌信息（公开接口，失败回退默认站名）
    useEffect(function () {
      (async function () {
        try {
          const res = await fetch(BASE + '/api/tenant/info');
          const json = await res.json();
          if (json && json.code === 0) setTenant(json.data);
        } catch (e) { /* 忽略：回退默认站名 */ }
      })();
    }, []);

    useEffect(function () {
      const onHash = function () {
        setRoute((location.hash.replace('#/', '') || 'dashboard').split('?')[0]);
      };
      window.addEventListener('hashchange', onHash);
      return function () { window.removeEventListener('hashchange', onHash); };
    }, []);

    const logout = async function () {
      try { await api('/api/auth/logout', { method: 'POST' }); } catch (e) { /* ignore */ }
      location.href = (me && me.support && me.support.mode) ? '/' : (BASE + '/login');
    };

    // v5.36.13：协作模式退出——结束会话后回平台控制台
    const exitSupport = async function () {
      if (!window.confirm('退出平台协作？退出后本浏览器不再以站长身份操作本站。')) return;
      try {
        const j = await api('/api/support/exit', { method: 'POST' });
        if (j.code === 0) { location.href = (j.data && j.data.url) || '/'; return; }
        toast(j.msg, 'error');
      } catch (e) { toast('操作失败', 'error'); }
    };

    // 会话失效时 api() 靠这个变量回平台首页（而不是把协作人丢到战队登录页）
    SUPPORT_MODE = !!(me && me.support && me.support.mode);

    // v5.0.0：站点品牌（顶栏/侧栏站名与 logo 随目录变化）
    const brandName = brandTitle(tenant && tenant.name, !me || me.team_name_suffix !== 0);

    const inner = (function () {
      if (!me) {
        return <Box sx={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          {loadErr ? <EmptyBox text="加载失败，请刷新重试" /> : <CircularProgress />}
        </Box>;
      }

      const navItems = NAV_ITEMS.slice();
      if (me.role === 'admin') navItems.push(['admin', '管理中心', ADMIN_ICON]);

      // 侧栏、底部与顶栏铃铛的红点：聊天室＝私聊未读 + 群未读；通知＝站内信未读（三处同一个数）
      const navBadge = function (k) {
        if (k === 'chat') { return chatUnread; }
        if (k === 'notice') { return noticeUnread; }
        return 0;
      };

      // v6.0：顶栏标题 = 当前页面名（对齐原型）
      const titleOf = (function () {
        if (route === 'features') return '功能';
        const hit = navItems.filter(function (it) { return it[0] === route; })[0];
        return hit ? hit[1] : '仪表盘';
      })();

      const page = route === 'features' ? <FeaturesPage navItems={navItems} /> :
        route === 'images' ? <ImagesPage /> :
        route === 'guides' ? <GuidesPage me={me} /> :
        route === 'rotation' ? <RotationPage /> :
        route === 'chat' ? <ChatPage me={me} onDmUnread={onDmUnread} onGroupSeen={onGroupSeen} /> :
        route === 'notice' ? <NoticePage onRead={function () { setNoticeUnread(0); }} /> :
        route === 'guncodes' ? <GunCodesPage me={me} /> :
        route === 'teams' ? <TeamsPage refreshMe={refreshMe} /> :
        route === 'customrace' ? <CustomRacePage /> :
        route === 'schedule' ? <SchedulePage /> :
        route === 'training' ? <TrainingPage me={me} /> :
        route === 'votes' ? <VotesPage /> :
        route === 'lottery' ? <LotteryPage /> :
        route === 'race' ? <RacePage /> :
        route === 'members' ? <MembersRoute me={me} /> :
        route === 'mystats' ? <MyStatsPage me={me} /> :
        route === 'profile' ? <ProfilePage me={me} refreshMe={refreshMe} primary={primary}
          onThemeChange={function (c) { storePrimary(c); setPrimary(c); }} /> :
        route === 'traitor' ? <TraitorPage /> :
        route === 'about' ? <AboutPage /> :
        route === 'admin' ? <AdminPage me={me} tenantName={(tenant && tenant.name) || ''} refreshMe={refreshMe} /> :
        <DashboardPage me={me} />;

      // v6.0：桌面侧边栏内容（Apple 风格：白底、蓝色菱形 logo、圆角高亮项、底部用户卡）
      const drawerContent = (
        <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%', bgcolor: '#fff' }}>
          <Box sx={{ px: 2.5, pt: 2.5, pb: 1.5 }}>
            {tenant && tenant.logo ? (
              <Box component="img" src={tenant.logo} alt="logo"
                sx={{ width: 36, height: 36, borderRadius: '10px', objectFit: 'cover', display: 'block' }} />
            ) : (
              <Box sx={{
                width: 36, height: 36, borderRadius: '10px', bgcolor: 'primary.main', color: '#fff',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}>
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d={BRAND_ICON} /></svg>
              </Box>
            )}
            <Typography fontWeight={700} sx={{ mt: 1.2, fontSize: 16, color: TEXT_PRIMARY }} noWrap>{brandName}</Typography>
            <Typography sx={{ fontSize: 12, color: TEXT_SECONDARY, mt: 0.3 }}>战队管理系统</Typography>
          </Box>
          <Box sx={{ flex: 1, px: 1.5, py: 1, overflowY: 'auto' }}>
            {pickNav(navItems, NAV_STANDALONE).map(function (it) {
              return <NavRow key={it[0]} it={it} active={route === it[0]} badge={navBadge(it[0])} />;
            })}
            {navGroups(navItems).map(function (g) {
              const shut = !!collapsed[g.key];
              return (
                <Box key={g.key} sx={{ mt: 1.25 }}>
                  <Box onClick={function () { toggleGroup(g.key); }}
                    sx={{ display: 'flex', alignItems: 'center', px: 1, py: 0.5, cursor: 'pointer', userSelect: 'none' }}>
                    <Typography sx={{ fontSize: 11, fontWeight: 700, letterSpacing: '.04em', color: '#5f6b7f' }}>{g.name}</Typography>
                    <Box sx={{ flex: '1 1 auto' }} />
                    <Typography sx={{
                      fontSize: 11, color: '#c7c7cc', lineHeight: '12px',
                      transform: shut ? 'rotate(-90deg)' : 'none', transition: 'transform .15s ease',
                    }}>⌄</Typography>
                  </Box>
                  {!shut && g.items.map(function (it) {
                    return <NavRow key={it[0]} it={it} active={route === it[0]} badge={navBadge(it[0])} />;
                  })}
                </Box>
              );
            })}
            {pickNav(navItems, NAV_ADMIN).length > 0 && (
              <Box sx={{ mt: 1.25, pt: 0.75, borderTop: '1px solid ' + CARD_BORDER }}>
                {pickNav(navItems, NAV_ADMIN).map(function (it) {
                  return <NavRow key={it[0]} it={it} active={route === it[0]} badge={navBadge(it[0])} />;
                })}
              </Box>
            )}
          </Box>
          <Box sx={{
            m: 1.5, p: 1.2, borderRadius: '12px', bgcolor: '#f5f5f7',
            display: 'flex', alignItems: 'center', gap: 1.2,
          }}>
            <UserAvatar user={{ id: me.id, nickname: me.nickname }} size={36} />
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Typography variant="body2" fontWeight={600} noWrap sx={{ color: TEXT_PRIMARY }}>
                {me.nickname}
              </Typography>
              <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>
                {me.role === 'admin' ? '超级管理员' : (me.team_name || '未加入小队')}
              </Typography>
            </Box>
            <Tooltip title="退出登录">
              <IconButton size="small" onClick={logout}>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="#86868b">
                  <path d="M10 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-8v-2h7V5h-7V3zm1 8v2H5.8l2.1 2.1-1.4 1.4L2 12l4.5-4.5 1.4 1.4L5.8 11H11z"/>
                </svg>
              </IconButton>
            </Tooltip>
          </Box>
        </Box>
      );

      // 手机底部 TabBar：五格（功能 / 仪表盘 / 通知 / 聊天室 / 我的），常量在模块级
      const mobileTab = MOBILE_TABS.filter(function (t) { return t[0] === route; })[0] ? route : '';

      // v6.0：顶栏全局搜索（回车跳转查询伪军）
      const doGlobalSearch = function () {
        const kw = searchKw.trim();
        if (!kw) return;
        location.hash = '#/traitor';
        setSearchKw('');
      };

      return (
        <Box sx={{ minHeight: '100vh', bgcolor: BG_GRAY }}>
          {/* 顶栏：白底浅描边，标题 + 搜索胶囊 + 铃铛 + 头像 */}
          <AppBar position="fixed" elevation={0} sx={{
            zIndex: function (t) { return t.zIndex.drawer + 1; },
            bgcolor: '#fff', color: TEXT_PRIMARY, borderBottom: '1px solid ' + CARD_BORDER,
          }}>
            <Toolbar sx={{ minHeight: '56px !important', gap: 1.5, px: { xs: 1.5, md: 3 } }}>
              <Typography variant="h6" fontWeight={700} sx={{ fontSize: { xs: 16, md: 18 }, color: TEXT_PRIMARY,
                whiteSpace: 'nowrap', maxWidth: { xs: 84, sm: 'none' }, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {titleOf}
              </Typography>
              {isMdUp && (
                <Box sx={{
                  flex: '0 1 360px', ml: 2, display: 'flex', alignItems: 'center',
                  bgcolor: '#f5f5f7', borderRadius: 999, px: 2, height: 36,
                }}>
                  <input
                    value={searchKw}
                    onChange={(e) => setSearchKw(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') doGlobalSearch(); }}
                    placeholder="搜索战队、队员、赛事…"
                    style={{ border: 'none', outline: 'none', background: 'transparent', width: '100%', fontSize: 13, color: TEXT_PRIMARY }}
                  />
                </Box>
              )}
              <Box sx={{ flex: 1 }} />
              {/* v5.36.13：协作模式随时可退出（不是横幅，只是顶栏一个动作） */}
              {me.support && me.support.mode && (
                <Button size="small" variant="outlined" color="warning" onClick={exitSupport}
                  sx={{ whiteSpace: 'nowrap', minWidth: 0, fontSize: 12 }}>
                  退出协作
                </Button>
              )}
              {/* v5.36.0：端游 / 手游专区切换（队长开了端手分离才出现） */}
              {gsInfo.on && (
                gsAllowed.length > 1 ? (
                  <Box sx={{ display: 'flex', alignItems: 'center', bgcolor: BG_GRAY, borderRadius: 999, p: '2px' }}>
                    {[['mobile', '手游'], ['pc', '端游']].filter(function (x) { return gsAllowed.indexOf(x[0]) >= 0; })
                      .map(function (x) {
                        const on = gsZone === x[0];
                        return (
                          <Box key={x[0]} component="span" onClick={function () { setGsZone(x[0]); }}
                            sx={{
                              px: { xs: 0.9, md: 1.2 }, py: 0.25, borderRadius: 999, cursor: 'pointer',
                              fontSize: 11.5, fontWeight: 600, whiteSpace: 'nowrap',
                              bgcolor: on ? '#fff' : 'transparent',
                              color: on ? TEXT_PRIMARY : TEXT_SECONDARY,
                              boxShadow: on ? '0 1px 3px rgba(0,0,0,.14)' : 'none',
                            }}>{x[1]}</Box>
                        );
                      })}
                  </Box>
                ) : (
                  <Chip size="small" label={gsAllowed[0] === 'pc' ? '端游专区' : '手游专区'}
                    sx={{ height: 22, fontSize: 11, bgcolor: BG_GRAY, color: TEXT_SECONDARY }} />
                )
              )}
              <Tooltip title={noticeUnread > 0 ? ('通知 ' + noticeUnread + ' 条') : '通知'}>
                <IconButton size="small" sx={{ color: TEXT_PRIMARY }} onClick={function () { location.hash = '#/notice'; }}>
                  <Box sx={{ position: 'relative' }}>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                      <path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22zm7-5H5a1 1 0 0 1 0-2c.6 0 1-.4 1-1v-4a6 6 0 0 1 4-5.66V4a2 2 0 1 1 4 0v.34A6 6 0 0 1 18 10v4c0 .6.4 1 1 1a1 1 0 0 1 0 2z"/>
                    </svg>
                    {noticeUnread > 0 && (
                      <Box sx={{
                        position: 'absolute', top: -2, right: -6, minWidth: 15, height: 15, px: 0.25,
                        boxSizing: 'border-box', borderRadius: '999px', bgcolor: 'error.main', color: '#fff',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        fontSize: 9, fontWeight: 700, border: '1.5px solid #fff',
                      }}>{noticeUnread > 99 ? '99+' : noticeUnread}</Box>
                    )}
                  </Box>
                </IconButton>
              </Tooltip>
              <UserAvatar user={{ id: me.id, nickname: me.nickname }} size={32} />
            </Toolbar>
          </AppBar>

          {/* 桌面固定侧边栏；手机不再用临时抽屉，改走底部 TabBar + 功能聚合页 */}
          {isMdUp && (
            <Drawer variant="permanent" sx={{
              width: DRAWER_WIDTH, flexShrink: 0,
              '& .MuiDrawer-paper': { width: DRAWER_WIDTH, boxSizing: 'border-box', borderRight: '1px solid ' + CARD_BORDER },
            }} open>
              {drawerContent}
            </Drawer>
          )}

          <Box component="main" sx={{
            // v5.6.2：p 缩写会覆盖 pb 长写（MUI sx 已知坑），曾导致手机端底部留白只剩 12px、
            // 页面底部内容被固定 TabBar 遮住（功能页"管理中心"入口 / 仪表盘"最新枪码"卡片）；
            // 故全部改用长写，并给全面屏手势条加 env() 余量
            px: { xs: 1.5, md: 2.5 },
            pt: { xs: 1.5, md: 2.5 },
            ml: isMdUp ? DRAWER_WIDTH + 'px' : 0,
            mt: '56px',
            pb: isMdUp ? 2.5 : 'calc(76px + env(safe-area-inset-bottom))',   // 手机底部给 TabBar 留位
          }}>
            {/* v5.36.0：切专区后整页重挂载，所有列表按新专区重取。
                key 只跟专区走、不跟「开没开」走——开/关总开关时若换 key，
                整个管理页会被重挂载、当前页签跳回第一个（实测会从「端手游分离」跳去「注册审核」） */}
            {React.cloneElement(page, { key: 'gs-' + (gsZoneNow || 'none') })}
          </Box>

          {/* 手机底部 TabBar */}
          {!isMdUp && (
            <Box sx={{
              position: 'fixed', bottom: 0, left: 0, right: 0, zIndex: 1200,
              bgcolor: 'rgba(255,255,255,0.92)', backdropFilter: 'blur(20px)', WebkitBackdropFilter: 'blur(20px)',
              borderTop: '1px solid ' + CARD_BORDER,
              display: 'flex', pb: 'env(safe-area-inset-bottom)',
            }}>
              {MOBILE_TABS.map(function (t) {
                const active = mobileTab === t[0];
                const n = t[0] === 'notice' ? noticeUnread : (t[0] === 'chat' ? chatUnread : 0);
                return (
                  <Box key={t[0]} component="a" href={'#/' + t[0]} sx={{
                    flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 0.3,
                    py: 0.8, textDecoration: 'none',
                    color: active ? 'primary.main' : '#86868b',
                  }}>
                    <Box sx={{ position: 'relative' }}>
                      <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d={t[2]} /></svg>
                      {n > 0 && (
                        <Box sx={{
                          position: 'absolute', top: -3, right: -11, minWidth: 15, height: 15, px: 0.25,
                          boxSizing: 'border-box', borderRadius: '999px', bgcolor: 'error.main', color: '#fff',
                          display: 'flex', alignItems: 'center', justifyContent: 'center',
                          fontSize: 9, fontWeight: 700, border: '1.5px solid rgba(255,255,255,.9)',
                        }}>{n > 99 ? '99+' : n}</Box>
                      )}
                    </Box>
                    <Typography sx={{ fontSize: 10, fontWeight: active ? 600 : 400 }}>{t[1]}</Typography>
                  </Box>
                );
              })}
            </Box>
          )}
        </Box>
      );
    })();

    return (
      <ThemeProvider theme={dynTheme}>
        <CssBaseline />
        <GameSideCtx.Provider value={gsCtx}>
          {inner}
        </GameSideCtx.Provider>
        <Snackbar open={snack.open} autoHideDuration={2200} anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
          onClose={() => setSnack(Object.assign({}, snack, { open: false }))}>
          <Alert severity={snack.sev} variant="filled" sx={{ minWidth: 240 }}>{snack.msg}</Alert>
        </Snackbar>
        {me && me.role === 'admin' && <PendingBattleGuard me={me} />}
        {/* v5.36.31：平台强制公告——全体成员（含管理员）登录后都要看到那一条才走 */}
        {me && <PlatformNoticeGuard />}
        <LightboxDialog src={lbSrc} onClose={() => setLbSrc('')} />
        {/* v5.36.13：队长登录后强制询问一次平台协作授权 */}
        {me && me.support && me.support.prompt && !supportPromptOff && (
          <SupportGrantDialog
            onGranted={function () { setSupportPromptOff(true); refreshMe(); }}
            onDismiss={function () { setSupportPromptOff(true); }} />
        )}
      </ThemeProvider>
    );
  }

  // ==================== 启动 ====================
  ReactDOM.createRoot(document.getElementById('root')).render(<Root />);
})();
