/* 战队入驻平台（v5.0.0：独立注册登录体系，面向入驻战队 + 平台超管）
 * 页面由 view/platform.html 承载，PLATFORM_MODE = login / register / app
 * 平台运行在根路径（无目录前缀），所有接口走 /api/platform/...
 */
(function () {
  'use strict';

  const {
    useState,
    useEffect,
    useCallback,
    useRef
  } = React;
  const {
    ThemeProvider,
    createTheme,
    CssBaseline,
    Box,
    Paper,
    Typography,
    TextField,
    Button,
    Alert,
    Link,
    Tabs,
    Tab,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableRow,
    TableContainer,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    Chip,
    Stack,
    Snackbar,
    CircularProgress,
    LinearProgress,
    Divider,
    FormControlLabel,
    Switch,
    Checkbox,
    Select,
    MenuItem,
    InputLabel,
    AppBar,
    Toolbar,
    Drawer,
    List,
    ListItemButton,
    ListItemIcon,
    ListItemText,
    IconButton,
    Tooltip,
    useMediaQuery,
    alpha
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
      primary: {
        main: '#0066cc'
      },
      error: {
        main: '#ff3b30'
      },
      background: {
        default: '#f5f5f7'
      },
      text: {
        primary: '#1d1d1f',
        secondary: '#86868b'
      }
    },
    shape: {
      borderRadius: 12
    },
    typography: {
      fontFamily: '-apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif'
    },
    components: {
      MuiButton: {
        defaultProps: {
          disableElevation: true
        },
        styleOverrides: {
          root: {
            textTransform: 'none',
            borderRadius: 999,
            fontWeight: 500
          },
          contained: {
            boxShadow: 'none'
          },
          outlined: {
            borderColor: APPLE_BLUE
          },
          outlinedError: {
            borderColor: '#ff3b30',
            '&:hover': {
              borderColor: '#ff3b30'
            }
          }
        }
      },
      MuiPaper: {
        styleOverrides: {
          root: {
            backgroundImage: 'none'
          },
          outlined: {
            borderColor: '#e8e8ed',
            borderRadius: 16
          },
          elevation1: {
            boxShadow: 'none',
            border: '1px solid #e8e8ed',
            borderRadius: 16
          }
        }
      },
      MuiOutlinedInput: {
        styleOverrides: {
          root: {
            borderRadius: 12,
            bgcolor: '#fff',
            '& fieldset': {
              borderColor: '#d2d2d7'
            },
            '&:hover fieldset': {
              borderColor: '#a1a1a6'
            }
          }
        }
      },
      MuiChip: {
        styleOverrides: {
          root: {
            borderRadius: 999
          }
        }
      },
      MuiDialog: {
        styleOverrides: {
          paper: {
            borderRadius: 18
          }
        }
      }
    }
  });

  // ==================== 请求工具 ====================
  async function api(url, options) {
    const opts = options || {};
    const init = {
      method: opts.method || 'GET'
    };
    if (opts.form) {
      init.body = opts.form;
    } else if (opts.body !== undefined && opts.body !== null) {
      init.headers = {
        'Content-Type': 'application/json'
      };
      init.body = JSON.stringify(opts.body);
    }
    const res = await fetch(url, init);
    let json = null;
    try {
      json = await res.json();
    } catch (e) {/* 非JSON响应 */}
    if (res.status === 401) {
      location.href = '/login';
      throw new Error('unauthorized');
    }
    if (!json) throw new Error('服务器响应异常');
    return json;
  }
  let _toast = function () {};
  function toast(msg, sev) {
    _toast(msg, sev);
  }
  // v5.10.0：向外射一个全局 hook，便于 compliance-admin.jsx 之类拆分子模块复用同一个 Snackbar
  window.__toast = function (msg, sev) {
    _toast(msg, sev);
  };

  // v5.2.2：带上传进度条的 POST（fetch 拿不到上传进度，大数据包用 XHR）
  // v5.2.4：加超时保护——服务器无响应/被代理掐断时报错退出，不再无限卡在"校验中"
  function uploadJson(url, formData, onProgress) {
    return new Promise(function (resolve, reject) {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.timeout = 600000; // 10 分钟：覆盖慢速上传大包的场景
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable && onProgress) onProgress(Math.round(e.loaded * 100 / e.total));
      };
      xhr.onload = function () {
        if (xhr.status === 401) {
          location.href = '/login';
          reject(new Error('unauthorized'));
          return;
        }
        let json = null;
        try {
          json = JSON.parse(xhr.responseText);
        } catch (e) {/* 非JSON响应 */}
        if (!json) {
          reject(new Error('服务器响应异常（文件可能超过服务器上传上限）'));
          return;
        }
        resolve(json);
      };
      xhr.ontimeout = function () {
        reject(new Error('服务器响应超时，本次上传未成功，请重试'));
      };
      xhr.onabort = function () {
        reject(new Error('上传已中断'));
      };
      xhr.onerror = function () {
        reject(new Error('网络异常，上传未成功'));
      };
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
  function AuthAside({
    mode
  }) {
    const isRegister = mode === 'register';
    const bullets = isRegister ? [['一支战队一个平台账号', '用队长 QQ 号注册就行，不用邮箱，也不用下载任何东西。'], ['注册后提交入驻资料', '站点目录名、战队简介，再附一张队长身份证明截图。'], ['平台核对身份后开通', '通过后你会有独立站点 /你的战队名/，成员在那里各自注册。']] : [['登录后即可提交入驻申请', '资料齐全当场提交，平台核对身份后开通站点。'], ['每支战队一个独立站点', '数据互相隔离，登录态也互不串，成员看不到别队的事。'], ['枪码、轮换地图、战绩、约战', '开完站在自己站点里管，平台这边只做治理与审核。']];
    return /*#__PURE__*/React.createElement(Box, {
      sx: {
        width: {
          xs: '100%',
          md: '50%'
        },
        flexShrink: 0,
        minHeight: {
          xs: 0,
          md: '100vh'
        },
        bgcolor: ASIDE_DARK,
        color: '#fff',
        boxSizing: 'border-box',
        px: {
          xs: 3,
          md: 6
        },
        py: {
          xs: 3,
          md: 6
        },
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        backgroundImage: 'linear-gradient(160deg, rgba(0,102,204,0.34) 0%, rgba(20,24,29,0) 58%)'
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        display: 'flex',
        alignItems: 'center',
        gap: 1.5,
        mb: {
          xs: 1.2,
          md: 4
        }
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        width: 34,
        height: 34,
        borderRadius: '9px',
        bgcolor: APPLE_BLUE,
        flexShrink: 0,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      width: "19",
      height: "19",
      fill: "#fff"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm0 2.2L6 6.4V11c0 3.9 2.6 7.6 6 8.9 3.4-1.3 6-5 6-8.9V6.4l-6-2.2zM11 7h2v4h4v2h-6V7z"
    }))), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: '#fff',
        letterSpacing: '0.2px'
      }
    }, "\u6218\u961F\u5165\u9A7B\u5E73\u53F0")), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: {
          xs: 19,
          md: 30
        },
        fontWeight: 700,
        lineHeight: 1.32,
        letterSpacing: '-0.5px',
        color: '#fff'
      }
    }, isRegister ? '注册一个平台账号，把战队搬进自己的站点' : '一个战队，一套自己的系统'), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        mt: 1.5,
        fontSize: {
          xs: 13,
          md: 15
        },
        lineHeight: 1.7,
        color: 'rgba(255,255,255,0.72)',
        maxWidth: '24em'
      }
    }, isRegister ? '成员管理、轮换地图、枪码库、战绩上传、跨队约战、聊天室，一次配齐。' : '入驻战队的队长和平台管理员从这里进。'), /*#__PURE__*/React.createElement(Box, {
      sx: {
        display: {
          xs: 'none',
          md: 'block'
        },
        mt: 4
      }
    }, bullets.map(function (b) {
      return /*#__PURE__*/React.createElement(Box, {
        key: b[0],
        sx: {
          display: 'flex',
          gap: 1.5,
          mb: 2.5
        }
      }, /*#__PURE__*/React.createElement(Box, {
        sx: {
          width: 6,
          height: 6,
          borderRadius: '50%',
          bgcolor: APPLE_BLUE,
          mt: '9px',
          flexShrink: 0
        }
      }), /*#__PURE__*/React.createElement(Box, {
        sx: {
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 15,
          fontWeight: 600,
          color: '#fff'
        }
      }, b[0]), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          mt: 0.3,
          fontSize: 13.5,
          lineHeight: 1.65,
          color: 'rgba(255,255,255,0.66)'
        }
      }, b[1])));
    })));
  }

  // 两栏外壳：桌面左栏右表单，手机上下叠（与 auth.jsx 的 Root 同一套断点）
  function AuthFrame({
    mode,
    children
  }) {
    return /*#__PURE__*/React.createElement(Box, {
      sx: {
        minHeight: '100vh',
        display: 'flex',
        flexDirection: {
          xs: 'column',
          md: 'row'
        },
        bgcolor: BG_GRAY
      }
    }, /*#__PURE__*/React.createElement(AuthAside, {
      mode: mode
    }), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1,
        display: 'flex',
        alignItems: {
          xs: 'flex-start',
          md: 'center'
        },
        justifyContent: 'center',
        px: 3,
        py: {
          xs: 4,
          md: 6
        },
        minHeight: 0
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        width: '100%',
        maxWidth: 400
      }
    }, children)));
  }

  // ==================== 页面底部：隐私政策 / 服务条款（v5.10.0）====================
  // 平台根路径 BASE=''，直接指向 /privacy 与 /terms（Compliance 控制器）
  function PolicyFooter({
    verb
  }) {
    const action = verb || '登录';
    return /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 3,
        pt: 2,
        borderTop: '1px solid ' + BORDER,
        textAlign: 'center'
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        lineHeight: 1.7,
        fontSize: 12
      }
    }, action, "\u5373\u4EE3\u8868\u60A8\u540C\u610F\u672C\u7AD9\u7684", ' ', /*#__PURE__*/React.createElement(Link, {
      href: "/terms",
      target: "_blank",
      rel: "noopener",
      underline: "hover",
      sx: {
        color: '#0066cc',
        fontSize: 12
      }
    }, "\u670D\u52A1\u6761\u6B3E"), ' ', "\u4E0E", ' ', /*#__PURE__*/React.createElement(Link, {
      href: "/privacy",
      target: "_blank",
      rel: "noopener",
      underline: "hover",
      sx: {
        color: '#0066cc',
        fontSize: 12
      }
    }, "\u9690\u79C1\u653F\u7B56")));
  }

  // ==================== 未晞通行证（v5.36.8：入驻平台一键登录） ====================
  // 复用「通行证 → 战队超管 → 平台入驻账号」这层绑定；平台账号体系本身不改。
  const PLATFORM_PASSPORT_NOTICES = {
    admin_needed: {
      tone: 'warning',
      title: '这个通行证还没有绑定战队超管',
      message: '平台一键登录只对「战队超管账号绑过通行证」对应的入驻平台账号生效；平台超管账号请直接用密码登录。'
    },
    admin_multi: {
      tone: 'warning',
      title: '无法确定要进入哪个入驻账号',
      message: '这个通行证绑定在多个战队的超管账号上，请先用平台账号密码登录。'
    },
    disabled: {
      tone: 'error',
      title: '平台账号已被停用',
      message: '这个通行证对应的入驻账号已停用，请联系平台管理员。'
    },
    session_lost: {
      tone: 'warning',
      title: '登录状态没有保存',
      message: '浏览器没有保住登录 Cookie。请允许本站 Cookie 后重新点一次通行证登录，或改用账号密码登录。'
    },
    denied: {
      tone: 'warning',
      title: '已取消授权',
      message: '你取消了未晞通行证授权，还没有登录。'
    },
    state: {
      tone: 'warning',
      title: '登录请求已过期',
      message: '请重新点一次「使用未晞通行证登录」。'
    },
    config: {
      tone: 'error',
      title: '通行证尚未配置',
      message: '平台管理员还没有完成未晞通行证对接。'
    },
    failed: {
      tone: 'error',
      title: '登录失败',
      message: '未晞通行证校验没有通过，请稍后重试或联系平台管理员。'
    }
  };
  function LockIcon() {
    return /*#__PURE__*/React.createElement("svg", {
      width: "15",
      height: "15",
      viewBox: "0 0 24 24",
      fill: "none",
      "aria-hidden": "true"
    }, /*#__PURE__*/React.createElement("rect", {
      x: "5",
      y: "10",
      width: "14",
      height: "10",
      rx: "2.2",
      stroke: "currentColor",
      strokeWidth: "1.8"
    }), /*#__PURE__*/React.createElement("path", {
      d: "M8 10V7.5a4 4 0 1 1 8 0V10",
      stroke: "currentColor",
      strokeWidth: "1.8",
      strokeLinecap: "round"
    }));
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
      fetch('/api/passport/config', {
        headers: {
          Accept: 'application/json'
        }
      }).then(function (res) {
        return res.json();
      }).then(function (json) {
        if (alive) setCfg(json && json.code === 0 ? json.data || {} : null);
      }).catch(function () {
        if (alive) setCfg(null);
      }).then(function () {
        if (alive) setLoading(false);
      });
      return function () {
        alive = false;
      };
    }, []);

    // 从通行证页按浏览器「后退」回来时会走 bfcache，不复位就会卡在「正在前往…」
    useEffect(function () {
      const onShow = function (e) {
        if (e.persisted) setBusy(false);
      };
      window.addEventListener('pageshow', onShow);
      return function () {
        window.removeEventListener('pageshow', onShow);
      };
    }, []);
    if (loading || !cfg || !cfg.enabled) return null;
    const ready = !!cfg.ready;
    const start = function () {
      if (!ready || busy) return;
      setBusy(true);
      location.href = cfg.start_url || '/passport/platform/start';
    };
    return /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 2.5
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        display: 'flex',
        alignItems: 'center',
        gap: 1.5,
        mb: 2.5
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1,
        height: 1,
        bgcolor: BORDER
      }
    }), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        color: TEXT_SECONDARY
      }
    }, "\u6216"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1,
        height: 1,
        bgcolor: BORDER
      }
    })), /*#__PURE__*/React.createElement(Button, {
      type: "button",
      variant: "outlined",
      fullWidth: true,
      size: "large",
      disabled: !ready || busy,
      onClick: start,
      startIcon: !busy ? /*#__PURE__*/React.createElement(LockIcon, null) : null,
      sx: {
        height: 48,
        fontSize: 15,
        fontWeight: 600,
        borderRadius: 999,
        color: APPLE_BLUE,
        borderColor: APPLE_BLUE,
        borderWidth: '1.5px',
        bgcolor: '#fff',
        '&:hover': {
          borderColor: '#0055aa',
          borderWidth: '1.5px',
          bgcolor: alpha(APPLE_BLUE, 0.05)
        },
        '&.Mui-disabled': {
          borderColor: BORDER,
          color: '#9b9ba1',
          bgcolor: '#fff'
        }
      }
    }, busy ? '正在前往通行证…' : '使用未晞通行证登录'), !ready && /*#__PURE__*/React.createElement(Typography, {
      align: "center",
      sx: {
        mt: 1,
        fontSize: 12,
        color: TEXT_SECONDARY
      }
    }, "\u901A\u884C\u8BC1\u767B\u5F55\u6682\u4E0D\u53EF\u7528"));
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
          method: 'POST',
          body: {
            username: username.trim(),
            password: password
          }
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
    return /*#__PURE__*/React.createElement(Box, {
      component: "form",
      onSubmit: submit,
      sx: {
        width: '100%'
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: {
          xs: 28,
          md: 32
        },
        fontWeight: 700,
        color: TEXT_PRIMARY,
        letterSpacing: '-0.5px'
      }
    }, "\u5E73\u53F0\u767B\u5F55"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        mt: 1,
        mb: 3.5,
        fontSize: 15,
        color: TEXT_SECONDARY
      }
    }, "\u5165\u9A7B\u6218\u961F\u4E0E\u5E73\u53F0\u7BA1\u7406\u5458\u767B\u5F55\u5165\u53E3"), notice && /*#__PURE__*/React.createElement(Alert, {
      severity: notice.tone,
      sx: {
        mb: 2,
        borderRadius: 3
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13.5,
        fontWeight: 600
      }
    }, notice.title), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        mt: 0.3,
        lineHeight: 1.6
      }
    }, notice.message)), error && /*#__PURE__*/React.createElement(Alert, {
      severity: "error",
      sx: {
        mb: 2,
        borderRadius: 3
      }
    }, error), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        fontWeight: 600,
        color: TEXT_PRIMARY,
        mb: 0.8
      }
    }, "\u8D26\u53F7"), /*#__PURE__*/React.createElement(TextField, {
      fullWidth: true,
      required: true,
      sx: {
        mb: 2
      },
      value: username,
      onChange: e => setUsername(e.target.value),
      autoComplete: "username",
      placeholder: "\u6CE8\u518C\u65F6\u586B\u5199\u7684\u8D26\u53F7"
    }), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        fontWeight: 600,
        color: TEXT_PRIMARY,
        mb: 0.8
      }
    }, "\u5BC6\u7801"), /*#__PURE__*/React.createElement(TextField, {
      fullWidth: true,
      type: "password",
      required: true,
      sx: {
        mb: 2.5
      },
      value: password,
      onChange: e => setPassword(e.target.value),
      autoComplete: "current-password",
      placeholder: "\u8BF7\u8F93\u5165\u5BC6\u7801"
    }), /*#__PURE__*/React.createElement(Button, {
      type: "submit",
      variant: "contained",
      fullWidth: true,
      size: "large",
      disabled: busy,
      sx: {
        height: 48,
        fontSize: 16
      }
    }, busy ? '登录中...' : '登 录'), /*#__PURE__*/React.createElement(PlatformPassportButton, null), /*#__PURE__*/React.createElement(Typography, {
      align: "center",
      sx: {
        mt: 2.5,
        fontSize: 14,
        color: TEXT_SECONDARY
      }
    }, "\u60F3\u5165\u9A7B\u6218\u961F\uFF1F", ' ', /*#__PURE__*/React.createElement(Link, {
      href: "/register",
      underline: "none",
      sx: {
        color: APPLE_BLUE,
        fontWeight: 600
      }
    }, "\u6CE8\u518C\u5E73\u53F0\u8D26\u53F7")), /*#__PURE__*/React.createElement(Typography, {
      align: "center",
      sx: {
        mt: 1.5,
        fontSize: 12.5,
        color: TEXT_SECONDARY
      }
    }, "\u6218\u961F\u6210\u5458\u8BF7\u52FF\u5728\u6B64\u6CE8\u518C\uFF0C\u8BF7\u5230\u6240\u5C5E\u6218\u961F\u7AD9\u70B9\u6CE8\u518C"), /*#__PURE__*/React.createElement(PolicyFooter, {
      verb: "\u767B\u5F55"
    }));
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
      setError('');
      setOk('');
      setBusy(true);
      try {
        const json = await api('/api/platform/auth/register', {
          method: 'POST',
          body: {
            username: username.trim(),
            nickname: nickname.trim(),
            password: password,
            confirm: confirm
          }
        });
        if (json.code === 0) {
          setUsername('');
          setNickname('');
          setPassword('');
          setConfirm('');
          setOk((json.msg || '注册成功') + '，即将跳转登录...');
          window.setTimeout(function () {
            location.href = '/login';
          }, 1200);
        } else {
          setError(json.msg || '注册失败');
        }
      } catch (err) {
        setError('网络异常，请稍后再试');
      } finally {
        setBusy(false);
      }
    };
    return /*#__PURE__*/React.createElement(Box, {
      component: "form",
      onSubmit: submit,
      sx: {
        width: '100%'
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: {
          xs: 28,
          md: 32
        },
        fontWeight: 700,
        color: TEXT_PRIMARY,
        letterSpacing: '-0.5px'
      }
    }, "\u6CE8\u518C\u5E73\u53F0\u8D26\u53F7"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        mt: 1,
        mb: 3.5,
        fontSize: 15,
        color: TEXT_SECONDARY
      }
    }, "\u6CE8\u518C\u540E\u5373\u53EF\u63D0\u4EA4\u6218\u961F\u5165\u9A7B\u7533\u8BF7"), error && /*#__PURE__*/React.createElement(Alert, {
      severity: "error",
      sx: {
        mb: 2,
        borderRadius: 3
      }
    }, error), ok && /*#__PURE__*/React.createElement(Alert, {
      severity: "success",
      sx: {
        mb: 2,
        borderRadius: 3
      }
    }, ok), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        fontWeight: 600,
        color: TEXT_PRIMARY,
        mb: 0.8
      }
    }, "\u8D26\u53F7"), /*#__PURE__*/React.createElement(TextField, {
      fullWidth: true,
      required: true,
      sx: {
        mb: 0.5
      },
      value: username,
      onChange: e => setUsername(e.target.value.trim()),
      placeholder: "3-20 \u4F4D\u5B57\u6BCD / \u6570\u5B57 / \u4E0B\u5212\u7EBF",
      autoComplete: "username"
    }), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        display: 'block',
        mb: 2
      }
    }, "\u5EFA\u8BAE\u76F4\u63A5\u7528\u961F\u957F QQ \u53F7\uFF0C\u548C\u4EE5\u540E\u7AD9\u70B9\u91CC\u7684\u8D85\u7BA1\u8D26\u53F7\u5BF9\u5F97\u4E0A"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        fontWeight: 600,
        color: TEXT_PRIMARY,
        mb: 0.8
      }
    }, "\u6635\u79F0 / \u6218\u961F\u79F0\u547C"), /*#__PURE__*/React.createElement(TextField, {
      fullWidth: true,
      required: true,
      sx: {
        mb: 2
      },
      value: nickname,
      onChange: e => setNickname(e.target.value),
      placeholder: "\u7528\u4E8E\u5E73\u53F0\u5C55\u793A\u4E0E\u5BA1\u6838\u8054\u7CFB"
    }), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        fontWeight: 600,
        color: TEXT_PRIMARY,
        mb: 0.8
      }
    }, "\u5BC6\u7801"), /*#__PURE__*/React.createElement(TextField, {
      fullWidth: true,
      type: "password",
      required: true,
      sx: {
        mb: 2
      },
      value: password,
      onChange: e => setPassword(e.target.value),
      placeholder: "8-64 \u4F4D\uFF0C\u9700\u5305\u542B\u5B57\u6BCD\u548C\u6570\u5B57",
      autoComplete: "new-password"
    }), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        fontWeight: 600,
        color: TEXT_PRIMARY,
        mb: 0.8
      }
    }, "\u786E\u8BA4\u5BC6\u7801"), /*#__PURE__*/React.createElement(TextField, {
      fullWidth: true,
      type: "password",
      required: true,
      sx: {
        mb: 3
      },
      value: confirm,
      onChange: e => setConfirm(e.target.value),
      placeholder: "\u518D\u6B21\u8F93\u5165\u5BC6\u7801",
      autoComplete: "new-password"
    }), /*#__PURE__*/React.createElement(Button, {
      type: "submit",
      variant: "contained",
      fullWidth: true,
      size: "large",
      disabled: busy,
      sx: {
        height: 48,
        fontSize: 16
      }
    }, busy ? '提交中...' : '注 册'), /*#__PURE__*/React.createElement(Box, {
      sx: {
        display: 'flex',
        alignItems: 'center',
        gap: 1.5,
        my: 2.5
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1,
        height: 1,
        bgcolor: BORDER
      }
    }), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        color: TEXT_SECONDARY
      }
    }, "\u6216"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1,
        height: 1,
        bgcolor: BORDER
      }
    })), /*#__PURE__*/React.createElement(Typography, {
      align: "center",
      sx: {
        fontSize: 14,
        color: TEXT_SECONDARY
      }
    }, "\u5DF2\u6709\u8D26\u53F7\uFF1F", ' ', /*#__PURE__*/React.createElement(Link, {
      href: "/login",
      underline: "none",
      sx: {
        color: APPLE_BLUE,
        fontWeight: 600
      }
    }, "\u76F4\u63A5\u767B\u5F55")), /*#__PURE__*/React.createElement(PolicyFooter, {
      verb: "\u6CE8\u518C"
    }));
  }

  // ==================== 入驻申请表单（战队账号） ====================
  function ApplyForm({
    onDone
  }) {
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
      if (!slug) {
        setSlugMsg('');
        setSlugOk(false);
        return;
      }
      const h = window.setTimeout(async function () {
        try {
          const res = await fetch('/api/platform/check-slug?slug=' + encodeURIComponent(slug));
          const json = await res.json();
          setSlugOk(json.code === 0);
          setSlugMsg(json.msg || '');
        } catch (e) {/* 网络异常忽略 */}
      }, 500);
      return function () {
        window.clearTimeout(h);
      };
    }, [slug]);
    const submit = async function () {
      if (busy) return;
      if (!name.trim()) {
        toast('请填写战队名称', 'error');
        return;
      }
      if (!slug || !slugOk) {
        toast('请填写可用的站点目录', 'error');
        return;
      }
      if (!adminUser.trim()) {
        toast('请填写战队系统超管账号', 'error');
        return;
      }
      if (adminPass.length < 8) {
        toast('超管密码至少 8 位', 'error');
        return;
      }
      if (adminPass !== adminConfirm) {
        toast('两次输入的超管密码不一致', 'error');
        return;
      }
      if (!file) {
        toast('请上传战队队长证明截图', 'error');
        return;
      }
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
        const json = await api('/api/platform/apply', {
          method: 'POST',
          form: fd
        });
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
    return /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        }
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 700,
      sx: {
        mb: 0.4
      }
    }, "\u63D0\u4EA4\u5165\u9A7B\u7533\u8BF7"), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 2
      }
    }, "\u5BA1\u6838\u901A\u8FC7\u540E\u5373\u53EF\u83B7\u5F97\u4E13\u5C5E\u6218\u961F\u7AD9\u70B9\uFF08\u5730\u5740 = \u5E73\u53F0\u57DF\u540D + \u4F60\u586B\u5199\u7684\u76EE\u5F55\uFF09"), /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u6218\u961F\u540D\u79F0",
      value: name,
      onChange: e => setName(e.target.value),
      inputProps: {
        maxLength: 64
      },
      fullWidth: true,
      size: "small",
      required: true
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u7AD9\u70B9\u76EE\u5F55\uFF08\u5982 delta \u2192 \u5E73\u53F0\u57DF\u540D/delta/\uFF09",
      value: slug,
      onChange: e => setSlug(e.target.value.toLowerCase().trim()),
      helperText: slug ? slugMsg : '2-30 位小写字母/数字/连字符，首尾须为字母或数字',
      FormHelperTextProps: {
        sx: {
          color: slug ? slugOk ? 'success.main' : 'error.main' : 'text.secondary'
        }
      },
      fullWidth: true,
      size: "small",
      required: true
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6218\u961F\u7B80\u4ECB\uFF08\u9009\u586B\uFF09",
      value: description,
      onChange: e => setDescription(e.target.value),
      inputProps: {
        maxLength: 500
      },
      multiline: true,
      minRows: 2,
      fullWidth: true,
      size: "small"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6218\u961F\u7CFB\u7EDF\u8D85\u7BA1\u8D26\u53F7\uFF08\u5EFA\u8BAE\u7528\u961F\u957F QQ \u53F7\uFF09",
      value: adminUser,
      onChange: e => setAdminUser(e.target.value.trim()),
      helperText: "\u5F00\u901A\u540E\u7528\u6B64\u8D26\u53F7\u767B\u5F55\u4F60\u7684\u6218\u961F\u7CFB\u7EDF\uFF0C\u62E5\u6709\u5168\u90E8\u7BA1\u7406\u6743\u9650",
      fullWidth: true,
      size: "small",
      required: true
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 2
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u8D85\u7BA1\u5BC6\u7801\uFF088-64\u4F4D\uFF0C\u542B\u5B57\u6BCD\u548C\u6570\u5B57\uFF09",
      type: "password",
      value: adminPass,
      onChange: e => setAdminPass(e.target.value),
      fullWidth: true,
      size: "small",
      required: true
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u786E\u8BA4\u8D85\u7BA1\u5BC6\u7801",
      type: "password",
      value: adminConfirm,
      onChange: e => setAdminConfirm(e.target.value),
      fullWidth: true,
      size: "small",
      required: true
    })), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 2
    }, /*#__PURE__*/React.createElement(Button, {
      component: "label",
      variant: "outlined",
      size: "small"
    }, file ? '重选队长证明截图' : '战队队长证明截图（必填）', /*#__PURE__*/React.createElement("input", {
      type: "file",
      accept: "image/*",
      hidden: true,
      onChange: e => setFile(e.target.files[0] || null)
    })), /*#__PURE__*/React.createElement(Button, {
      component: "label",
      variant: "outlined",
      size: "small",
      color: "inherit"
    }, logoFile ? '重选logo' : '战队logo（选填）', /*#__PURE__*/React.createElement("input", {
      type: "file",
      accept: "image/*",
      hidden: true,
      onChange: e => setLogoFile(e.target.files[0] || null)
    }))), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary"
    }, "\u961F\u957F\u8BC1\u660E\u622A\u56FE\uFF1A\u4E0A\u4F20\u80FD\u8BC1\u660E\u4F60\u662F\u6218\u961F\u961F\u957F\u7684\u622A\u56FE\uFF08\u5982\u6E38\u620F\u5185\u6218\u961F\u7BA1\u7406\u9875\u3001\u961F\u957F\u8EAB\u4EFD\u9875\u7B49\uFF09\uFF0C\u4F9B\u5E73\u53F0\u5BA1\u6838"), (file || logoFile) && /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1.5
    }, file && /*#__PURE__*/React.createElement(Box, {
      component: "img",
      src: URL.createObjectURL(file),
      alt: "\u961F\u957F\u8BC1\u660E\u9884\u89C8",
      sx: {
        width: 96,
        height: 72,
        objectFit: 'cover',
        borderRadius: '6px'
      }
    }), logoFile && /*#__PURE__*/React.createElement(Box, {
      component: "img",
      src: URL.createObjectURL(logoFile),
      alt: "logo\u9884\u89C8",
      sx: {
        width: 72,
        height: 72,
        objectFit: 'cover',
        borderRadius: '6px'
      }
    })), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      size: "large",
      disabled: busy,
      onClick: submit
    }, busy ? /*#__PURE__*/React.createElement(CircularProgress, {
      size: 22
    }) : '提交入驻申请')));
  }

  // ==================== 战队控制台（我的站点） ====================
  const TENANT_CHIP = {
    approved: ['已开通', 'success'],
    paused: ['已暂停', 'warning'],
    banned: ['已封禁', 'error'],
    transferring: ['转移冷静期', 'info']
  };

  // ==================== 数据导入导出（v5.2.0：提交审核制） ====================
  const DATA_TASK_CHIP = {
    pending: ['待审核', 'warning'],
    approved: ['已通过', 'success'],
    rejected: ['已驳回', 'error'],
    done: ['已完成', 'success'],
    failed: ['执行失败', 'error'],
    cancelled: ['已撤回', 'default']
  };
  function DataTaskPanel({
    tenantOk
  }) {
    const [data, setData] = useState(null);
    const [upload, setUpload] = useState(null); // v5.2.2：上传中 { pct }，非 null 时显示进度条
    const fileRef = React.useRef(null);
    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/my/data/tasks');
        if (json.code === 0) setData(json.data);
      } catch (e) {/* 忽略 */}
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const reqExport = async function () {
      if (!window.confirm('申请导出本站全部数据（含图片，不含轮换地图）？\n平台管理员审核通过后即可下载。')) return;
      try {
        const json = await api('/api/platform/my/data/export', {
          method: 'POST'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const reqImport = async function (e) {
      const f = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!f) return;
      if (upload) return;
      // v5.2.4：超过服务器上传上限的文件直接在浏览器拦下（传上去也会被丢弃，
      // 旧版本会卡在"服务器校验中"无任何反馈）
      if (data.max_upload_bytes > 0 && f.size > data.max_upload_bytes) {
        toast('数据包太大（' + (f.size / 1048576).toFixed(1) + 'MB），服务器上限 ' + (data.max_upload_bytes / 1048576).toFixed(0) + 'MB，请精简图片后重新导出', 'error');
        return;
      }
      if (!window.confirm('上传数据包并申请导入？\n审核通过后当前站点数据将被数据包整体覆盖（不可恢复）。')) return;
      const form = new FormData();
      form.append('file', f);
      setUpload({
        pct: 0
      });
      try {
        const json = await uploadJson('/api/platform/my/data/import', form, function (pct) {
          setUpload({
            pct: pct
          });
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (err) {
        toast(err && err.message ? err.message : '上传失败（文件可能过大或网络中断）', 'error');
      }
      setUpload(null);
    };
    const cancelTask = async function (t) {
      if (!window.confirm('撤回该' + (t.type === 'export' ? '导出' : '导入') + '申请？')) return;
      try {
        const json = await api('/api/platform/my/data/' + t.id + '/cancel', {
          method: 'POST'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    if (!tenantOk) return null;
    if (data === null) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 2
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, {
      size: 22
    }));
    return /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        }
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 700,
      sx: {
        mb: 0.4
      }
    }, "\u6570\u636E\u5BFC\u5165 / \u5BFC\u51FA"), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1.5
      }
    }, "\u65B9\u4FBF\u4ECE\u5F00\u6E90\u7248\uFF08\u5982 v3.9.6.1\uFF09\u8FC1\u79FB\u6570\u636E\u6216\u5907\u4EFD\u672C\u7AD9\u6570\u636E\uFF1A\u542B\u6210\u5458/\u56FE\u7247/\u804A\u5929/\u516C\u544A\u7B49\uFF0C\u4E0D\u542B\u4ECA\u65E5\u8F6E\u6362\u5730\u56FE\uFF1B \u6BCF\u5929\u5404 1 \u6B21\u673A\u4F1A\uFF08\u88AB\u9A73\u56DE\u3001\u64A4\u56DE\u6216\u6267\u884C\u5931\u8D25\u4E0D\u6D88\u8017\u673A\u4F1A\uFF0C\u53EF\u91CD\u65B0\u63D0\u4EA4\uFF09\uFF1B\u7AD9\u70B9\u6EE1 ", data.min_export_members, " \u4EBA\u53EF\u5BFC\u51FA\uFF0C \u65B0\u5EFA\u7AD9\u70B9\u53EA\u53EF\u5BFC\u5165\uFF1B\u5747\u9700\u5E73\u53F0\u7BA1\u7406\u5458\u5BA1\u6838\u540C\u610F\u540E\u6267\u884C\u3002"), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 1.2,
      sx: {
        mb: 1.5
      }
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      disabled: !!upload || !data.can_export || data.export_left <= 0,
      onClick: reqExport
    }, "\u7533\u8BF7\u5BFC\u51FA", data.can_export ? data.export_left <= 0 ? '（今日已用）' : '' : '（成员不足 ' + data.min_export_members + ' 人）'), /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      component: "label",
      disabled: !!upload || data.import_left <= 0
    }, data.import_left <= 0 ? '上传数据包（今日已用）' : '上传数据包申请导入', /*#__PURE__*/React.createElement("input", {
      type: "file",
      accept: ".zip",
      hidden: true,
      ref: fileRef,
      onChange: reqImport
    }))), upload && /*#__PURE__*/React.createElement(Box, {
      sx: {
        mb: 1.5
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 0.5
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary"
    }, upload.pct < 100 ? '数据包上传中 ' + upload.pct + '%' : '上传完成，服务器校验中…')), /*#__PURE__*/React.createElement(LinearProgress, {
      variant: "determinate",
      value: upload.pct,
      sx: {
        height: 8,
        borderRadius: 4
      }
    })), data.list.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u5BFC\u5165\u5BFC\u51FA\u8BB0\u5F55") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u7C7B\u578B"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u63D0\u4EA4\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "\u5907\u6CE8"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, data.list.map(function (t) {
      const chip = DATA_TASK_CHIP[t.status] || [t.status, 'default'];
      return /*#__PURE__*/React.createElement(TableRow, {
        key: t.id
      }, /*#__PURE__*/React.createElement(TableCell, null, t.type === 'export' ? '导出' : '导入'), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: chip[0],
        color: chip[1],
        variant: "outlined"
      })), /*#__PURE__*/React.createElement(TableCell, null, fmtTime(t.created_at)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, t.note || '-')), /*#__PURE__*/React.createElement(TableCell, null, t.status === 'pending' && /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: () => cancelTask(t)
      }, "\u64A4\u56DE"), t.type === 'export' && (t.status === 'approved' || t.status === 'done') && /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "contained",
        onClick: function () {
          window.location.href = '/api/platform/my/data/' + t.id + '/download';
        }
      }, "\u4E0B\u8F7D")));
    })))));
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
      } catch (e) {
        setLoadErr(true);
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const saveAccount = async function () {
      if (!newUser.trim()) {
        toast('请输入新账号', 'error');
        return;
      }
      try {
        const json = await api('/api/platform/my/admin-account', {
          method: 'POST',
          body: {
            username: newUser.trim()
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setNewUser('');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const savePassword = async function () {
      if (newPass.length < 8) {
        toast('密码至少 8 位', 'error');
        return;
      }
      if (newPass !== newConfirm) {
        toast('两次输入的密码不一致', 'error');
        return;
      }
      try {
        const json = await api('/api/platform/my/admin-password', {
          method: 'POST',
          body: {
            password: newPass,
            confirm: newConfirm
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setNewPass('');
          setNewConfirm('');
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.1.0：自助暂停 / 恢复站点
    const setStatus = async function (action) {
      const tip = action === 'pause' ? '暂停后站点目录将无法访问，成员不能登录，确定暂停吗？' : '恢复后站点立即可正常访问，确定恢复吗？';
      if (!window.confirm(tip)) return;
      try {
        const json = await api('/api/platform/my/status', {
          method: 'POST',
          body: {
            action: action
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.1.0：发起战队转移
    const startTransfer = async function () {
      if (!transferTo.trim()) {
        toast('请输入接收方的平台账号', 'error');
        return;
      }
      if (!window.confirm('确认把战队转移给「' + transferTo.trim() + '」？\n对方同意后进入 7 天冷静期，期间站点无法访问，冷静期结束后站点归对方所有。')) return;
      try {
        const json = await api('/api/platform/transfer/start', {
          method: 'POST',
          body: {
            to_username: transferTo.trim()
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setTransferTo('');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.1.0：撤回进行中的转移
    const cancelTransfer = async function (id) {
      if (!window.confirm('撤回后转移作废，站点恢复正常，确定撤回吗？')) return;
      try {
        const json = await api('/api/platform/transfer/' + id + '/cancel', {
          method: 'POST'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.1.0：接收 / 拒绝别人转过来的战队
    const handleTransferIn = async function (id, act) {
      if (act === 'accept' && !window.confirm('确认接收「' + my.transfer_in.tenant_name + '」？\n接收后进入 7 天冷静期（站点暂停访问），冷静期结束战队正式归你。')) return;
      try {
        const json = await api('/api/platform/transfer/' + id + '/' + act, {
          method: 'POST'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.1.0：登录日志
    const openLogs = async function () {
      setLogsOpen(true);
      if (logs !== null) return;
      try {
        const json = await api('/api/platform/my/login-logs');
        setLogs(json.data.list || []);
      } catch (e) {
        setLogs([]);
      }
    };
    if (loadErr) return /*#__PURE__*/React.createElement(Alert, {
      severity: "error"
    }, "\u52A0\u8F7D\u5931\u8D25\uFF0C\u8BF7\u5237\u65B0\u91CD\u8BD5");
    if (my === null) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 6
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));

    // 登录日志弹窗
    const logsDialog = /*#__PURE__*/React.createElement(Dialog, {
      open: logsOpen,
      onClose: () => setLogsOpen(false),
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u767B\u5F55\u65E5\u5FD7\uFF08\u6700\u8FD1 50 \u6761\uFF09"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, logs === null ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 3
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, {
      size: 26
    })) : logs.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u767B\u5F55\u8BB0\u5F55") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "IP"), /*#__PURE__*/React.createElement(TableCell, null, "\u5F52\u5C5E\u5730"), /*#__PURE__*/React.createElement(TableCell, null, "\u7ED3\u679C"))), /*#__PURE__*/React.createElement(TableBody, null, logs.map(function (l) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: l.id
      }, /*#__PURE__*/React.createElement(TableCell, null, fmtTime(l.created_at)), /*#__PURE__*/React.createElement(TableCell, null, l.ip), /*#__PURE__*/React.createElement(TableCell, null, l.region || '-'), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: l.result,
        color: l.result === '成功' ? 'success' : 'error',
        variant: "outlined"
      })));
    }))))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setLogsOpen(false)
    }, "\u5173\u95ED")));

    // 有待接收的转移单（名下无站点时展示）
    const transferInCard = my.transfer_in ? /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        }
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 700,
      sx: {
        mb: 0.4
      }
    }, "\u5F85\u63A5\u6536\u7684\u6218\u961F\u8F6C\u79FB"), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      sx: {
        mb: 1
      }
    }, "\u300C", my.transfer_in.from_name, "\u300D\u8981\u628A\u6218\u961F\u300C", /*#__PURE__*/React.createElement("b", null, my.transfer_in.tenant_name), "\u300D\u8F6C\u79FB\u7ED9\u4F60\u3002 \u540C\u610F\u540E\u8FDB\u5165 7 \u5929\u51B7\u9759\u671F\uFF08\u671F\u95F4\u7AD9\u70B9\u6682\u505C\u8BBF\u95EE\uFF09\uFF0C\u51B7\u9759\u671F\u7ED3\u675F\u6218\u961F\u6B63\u5F0F\u5F52\u4F60\u3002"), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1.2
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: () => handleTransferIn(my.transfer_in.id, 'accept')
    }, "\u540C\u610F\u63A5\u6536"), /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      color: "error",
      onClick: () => handleTransferIn(my.transfer_in.id, 'reject')
    }, "\u62D2\u7EDD"))) : null;

    // 已开通站点：站点信息 + 超管账号管理
    if (my.tenant) {
      const t = my.tenant;
      const chip = TENANT_CHIP[t.status] || [t.status, 'default'];
      const usedPct = t.storage_quota > 0 ? Math.min(100, Math.round(t.storage_used / t.storage_quota * 100)) : 0;
      const tr = my.transfer_out;
      return /*#__PURE__*/React.createElement(Stack, {
        spacing: 2
      }, /*#__PURE__*/React.createElement(Paper, {
        sx: {
          p: {
            xs: 2,
            md: 3
          }
        }
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        alignItems: "center",
        spacing: 1.2,
        sx: {
          mb: 1.5
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        variant: "subtitle1",
        fontWeight: 700,
        sx: {
          flex: 1
        }
      }, "\u6211\u7684\u6218\u961F\u7AD9\u70B9"), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: chip[1],
        label: chip[0]
      }), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        onClick: openLogs
      }, "\u767B\u5F55\u65E5\u5FD7")), /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        sx: {
          mb: 0.8
        }
      }, "\u6218\u961F\u540D\u79F0\uFF1A", /*#__PURE__*/React.createElement("b", null, t.name)), /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        sx: {
          mb: 0.8
        }
      }, "\u8BBF\u95EE\u5730\u5740\uFF1A", /*#__PURE__*/React.createElement(Link, {
        href: t.url,
        target: "_blank",
        underline: "hover"
      }, location.origin, t.url), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block'
        }
      }, "\u628A\u6B64\u5730\u5740\u53D1\u7ED9\u6218\u961F\u6210\u5458\uFF0C\u6210\u5458\u5728\u8BE5\u7AD9\u70B9\u6CE8\u518C\u52A0\u5165")), /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        sx: {
          mb: 1.2
        }
      }, "\u8D85\u7BA1\u8D26\u53F7\uFF1A", /*#__PURE__*/React.createElement("b", null, t.admin_username), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block'
        }
      }, "\u7528\u6B64\u8D26\u53F7 + \u4F60\u8BBE\u7F6E\u7684\u5BC6\u7801\u767B\u5F55\u6218\u961F\u7CFB\u7EDF\uFF0C\u5373\u4E3A\u7AD9\u70B9\u7BA1\u7406\u5458")), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          mb: 0.5
        }
      }, "\u5B58\u50A8\u7A7A\u95F4\uFF1A", fmtBytes(t.storage_used), " / ", fmtBytes(t.storage_quota)), /*#__PURE__*/React.createElement(Box, {
        sx: {
          height: 8,
          borderRadius: 4,
          bgcolor: '#e9e5f5',
          overflow: 'hidden',
          mb: 1.5
        }
      }, /*#__PURE__*/React.createElement(Box, {
        sx: {
          height: '100%',
          width: usedPct + '%',
          bgcolor: usedPct >= 90 ? '#dc2626' : usedPct >= 70 ? '#f59e0b' : '#7c3aed'
        }
      })), /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 1.2
      }, t.status === 'approved' ? /*#__PURE__*/React.createElement(Button, {
        variant: "outlined",
        color: "warning",
        onClick: () => setStatus('pause')
      }, "\u6682\u505C\u7AD9\u70B9") : t.status === 'paused' ? /*#__PURE__*/React.createElement(Button, {
        variant: "outlined",
        color: "success",
        onClick: () => setStatus('resume')
      }, "\u6062\u590D\u7AD9\u70B9") : null), t.status === 'paused' && /*#__PURE__*/React.createElement(Alert, {
        severity: "warning",
        sx: {
          mt: 1.5
        }
      }, "\u7AD9\u70B9\u5DF2\u6682\u505C\uFF0C\u6210\u5458\u6682\u65F6\u65E0\u6CD5\u8BBF\u95EE\uFF0C\u70B9\u51FB\u4E0A\u65B9\"\u6062\u590D\u7AD9\u70B9\"\u5373\u53EF\u91CD\u65B0\u5F00\u653E")), /*#__PURE__*/React.createElement(Paper, {
        sx: {
          p: {
            xs: 2,
            md: 3
          }
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        variant: "subtitle1",
        fontWeight: 700,
        sx: {
          mb: 0.4
        }
      }, "\u6218\u961F\u8F6C\u79FB"), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          mb: 2
        }
      }, "\u628A\u6218\u961F\u8F6C\u7ED9\u5176\u4ED6\u5E73\u53F0\u8D26\u53F7\uFF1A\u5BF9\u65B9\u540C\u610F\u540E\u8FDB\u5165 7 \u5929\u51B7\u9759\u671F\uFF08\u671F\u95F4\u7AD9\u70B9\u65E0\u6CD5\u8BBF\u95EE\uFF09\uFF0C\u51B7\u9759\u671F\u7ED3\u675F\u540E\u7AD9\u70B9\u5F52\u5BF9\u65B9\uFF0C\u4F60\u4E0D\u518D\u62E5\u6709\u8BE5\u6218\u961F"), tr ? /*#__PURE__*/React.createElement(Alert, {
        severity: tr.status === 'cooling' ? 'warning' : 'info',
        sx: {
          mb: 1.5
        }
      }, tr.status === 'cooling' ? '转移冷静期中：站点暂停访问，' + fmtTime(tr.cooling_until) + ' 后正式交接给 ' + tr.to_name : '等待 ' + tr.to_name + ' 确认接收') : null, /*#__PURE__*/React.createElement(Stack, {
        direction: {
          xs: 'column',
          sm: 'row'
        },
        spacing: 1.5
      }, /*#__PURE__*/React.createElement(TextField, {
        label: "\u63A5\u6536\u65B9\u5E73\u53F0\u8D26\u53F7",
        value: transferTo,
        onChange: e => setTransferTo(e.target.value.trim()),
        placeholder: "\u5BF9\u65B9\u6CE8\u518C\u5E73\u53F0\u65F6\u7528\u7684\u8D26\u53F7",
        size: "small",
        sx: {
          flex: 1
        },
        disabled: !!tr
      }), /*#__PURE__*/React.createElement(Button, {
        variant: "contained",
        color: "warning",
        disabled: !!tr || t.status !== 'approved',
        onClick: startTransfer
      }, "\u53D1\u8D77\u8F6C\u79FB"), tr && /*#__PURE__*/React.createElement(Button, {
        variant: "outlined",
        color: "error",
        onClick: () => cancelTransfer(tr.id)
      }, "\u64A4\u56DE\u8F6C\u79FB"))), t.status === 'approved' && /*#__PURE__*/React.createElement(DataTaskPanel, {
        tenantOk: true
      }), /*#__PURE__*/React.createElement(Paper, {
        sx: {
          p: {
            xs: 2,
            md: 3
          }
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        variant: "subtitle1",
        fontWeight: 700,
        sx: {
          mb: 0.4
        }
      }, "\u66F4\u6539\u8D85\u7BA1\u8D26\u53F7"), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          mb: 2
        }
      }, "\u4FEE\u6539\u540E\u7ACB\u5373\u751F\u6548\uFF0C\u8BF7\u4F7F\u7528\u65B0\u8D26\u53F7\u767B\u5F55\u6218\u961F\u7CFB\u7EDF"), /*#__PURE__*/React.createElement(Stack, {
        direction: {
          xs: 'column',
          sm: 'row'
        },
        spacing: 1.5
      }, /*#__PURE__*/React.createElement(TextField, {
        label: "\u65B0\u8D85\u7BA1\u8D26\u53F7",
        value: newUser,
        onChange: e => setNewUser(e.target.value.trim()),
        placeholder: "3-20 \u4F4D\u5B57\u6BCD/\u6570\u5B57/\u4E0B\u5212\u7EBF",
        size: "small",
        sx: {
          flex: 1
        }
      }), /*#__PURE__*/React.createElement(Button, {
        variant: "contained",
        onClick: saveAccount
      }, "\u4FDD\u5B58\u8D26\u53F7")), /*#__PURE__*/React.createElement(Divider, {
        sx: {
          my: 2.5
        }
      }), /*#__PURE__*/React.createElement(Typography, {
        variant: "subtitle1",
        fontWeight: 700,
        sx: {
          mb: 0.4
        }
      }, "\u66F4\u6539\u8D85\u7BA1\u5BC6\u7801"), /*#__PURE__*/React.createElement(Stack, {
        direction: {
          xs: 'column',
          sm: 'row'
        },
        spacing: 1.5
      }, /*#__PURE__*/React.createElement(TextField, {
        label: "\u65B0\u5BC6\u7801",
        type: "password",
        value: newPass,
        onChange: e => setNewPass(e.target.value),
        placeholder: "8-64 \u4F4D\uFF0C\u542B\u5B57\u6BCD\u548C\u6570\u5B57",
        size: "small",
        sx: {
          flex: 1
        }
      }), /*#__PURE__*/React.createElement(TextField, {
        label: "\u786E\u8BA4\u65B0\u5BC6\u7801",
        type: "password",
        value: newConfirm,
        onChange: e => setNewConfirm(e.target.value),
        size: "small",
        sx: {
          flex: 1
        }
      }), /*#__PURE__*/React.createElement(Button, {
        variant: "contained",
        onClick: savePassword
      }, "\u4FDD\u5B58\u5BC6\u7801"))), transferInCard, logsDialog);
    }

    // 有申请记录：展示状态；被驳回可重新提交
    if (my.application) {
      const a = my.application;
      if (a.status === 'pending') {
        return /*#__PURE__*/React.createElement(Stack, {
          spacing: 2
        }, /*#__PURE__*/React.createElement(Paper, {
          sx: {
            p: {
              xs: 2,
              md: 3
            }
          }
        }, /*#__PURE__*/React.createElement(Alert, {
          severity: "info",
          sx: {
            mb: 1.5
          }
        }, "\u4F60\u7684\u5165\u9A7B\u7533\u8BF7\u6B63\u5728\u5BA1\u6838\u4E2D\uFF0C\u8BF7\u8010\u5FC3\u7B49\u5F85"), /*#__PURE__*/React.createElement(Typography, {
          variant: "body2"
        }, "\u6218\u961F\u540D\u79F0\uFF1A", /*#__PURE__*/React.createElement("b", null, a.name)), /*#__PURE__*/React.createElement(Typography, {
          variant: "body2"
        }, "\u7AD9\u70B9\u76EE\u5F55\uFF1A", /*#__PURE__*/React.createElement("b", null, a.slug), "\uFF08\u5BA1\u6838\u901A\u8FC7\u540E\u751F\u6548\uFF09"), /*#__PURE__*/React.createElement(Typography, {
          variant: "body2"
        }, "\u63D0\u4EA4\u65F6\u95F4\uFF1A", fmtTime(a.created_at))), /*#__PURE__*/React.createElement(Stack, {
          direction: "row"
        }, /*#__PURE__*/React.createElement(Button, {
          variant: "outlined",
          onClick: openLogs
        }, "\u767B\u5F55\u65E5\u5FD7")), transferInCard, logsDialog);
      }
      if (a.status === 'rejected') {
        return /*#__PURE__*/React.createElement(Stack, {
          spacing: 2
        }, /*#__PURE__*/React.createElement(Alert, {
          severity: "warning"
        }, "\u4F60\u7684\u5165\u9A7B\u7533\u8BF7\u88AB\u9A73\u56DE", a.reject_reason ? '，原因：' + a.reject_reason : '', "\u3002\u53EF\u4FEE\u6539\u8D44\u6599\u540E\u91CD\u65B0\u63D0\u4EA4\u3002"), transferInCard, /*#__PURE__*/React.createElement(ApplyForm, {
          onDone: load
        }), logsDialog, /*#__PURE__*/React.createElement(Stack, {
          direction: "row"
        }, /*#__PURE__*/React.createElement(Button, {
          variant: "outlined",
          onClick: openLogs
        }, "\u767B\u5F55\u65E5\u5FD7")));
      }
    }

    // 无记录：入驻申请表单
    return /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, transferInCard, /*#__PURE__*/React.createElement(ApplyForm, {
      onDone: load
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row"
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      onClick: openLogs
    }, "\u767B\u5F55\u65E5\u5FD7")), logsDialog);
  }

  // ==================== 轮换地图统一管理（v5.1.0：原战队系统后台收归平台，全战队共用） ====================
  function PlatformRotation() {
    const [data, setData] = useState(null);
    const [enabled, setEnabled] = useState(false);
    const [start, setStart] = useState('');
    // 地图弹窗
    const [mapDlg, setMapDlg] = useState(null); // {id, name} 或 null
    const [mapName, setMapName] = useState('');
    const [mapFile, setMapFile] = useState(null);
    // 组合弹窗
    const [dayDlg, setDayDlg] = useState(null); // {id, name, map_ids} 或 null
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
      } catch (e) {/* ignore */}
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const saveSetting = async function () {
      try {
        const json = await api('/api/platform/admin/rotation/save', {
          method: 'POST',
          body: {
            enabled: enabled ? 1 : 0,
            start: start
          }
        });
        if (json.code === 0) toast(json.msg, 'success');else toast(json.msg, 'error');
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const openMap = function (m) {
      setMapDlg(m || {
        id: 0
      });
      setMapName(m ? m.name : '');
      setMapFile(null);
    };
    const saveMap = async function () {
      if (!mapName.trim()) {
        toast('请填写地图名称', 'error');
        return;
      }
      const fd = new FormData();
      if (mapDlg.id > 0) fd.append('id', mapDlg.id);
      fd.append('name', mapName.trim());
      if (mapFile) fd.append('file', mapFile);
      try {
        const json = await api('/api/platform/admin/rotation/maps/save', {
          method: 'POST',
          form: fd
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setMapDlg(null);
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const removeMap = async function (m) {
      if (!window.confirm('删除地图「' + m.name + '」？相关每日组合中的该地图也会移除')) return;
      try {
        const json = await api('/api/platform/admin/rotation/maps/' + m.id, {
          method: 'DELETE'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const openDay = function (d) {
      setDayDlg(d || {
        id: 0
      });
      setDayName(d ? d.name : '');
      setDayMaps(d ? (d.map_ids || []).slice() : []);
    };
    const toggleDayMap = function (mid) {
      setDayMaps(function (prev) {
        return prev.indexOf(mid) >= 0 ? prev.filter(function (x) {
          return x !== mid;
        }) : prev.concat([mid]);
      });
    };
    const saveDay = async function () {
      if (dayMaps.length === 0) {
        toast('请至少选择一张地图', 'error');
        return;
      }
      try {
        const json = await api('/api/platform/admin/rotation/days/save', {
          method: 'POST',
          body: {
            id: dayDlg.id || 0,
            name: dayName.trim(),
            map_ids: dayMaps
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setDayDlg(null);
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const removeDay = async function (d) {
      if (!window.confirm('删除每日组合「' + (d.name || '#' + d.id) + '」？')) return;
      try {
        const json = await api('/api/platform/admin/rotation/days/' + d.id, {
          method: 'DELETE'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    if (data === null) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    const mapById = {};
    data.maps.forEach(function (m) {
      mapById[m.id] = m;
    });
    return /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6B64\u914D\u7F6E\u4E3A\u5168\u5E73\u53F0\u7EDF\u4E00\uFF0C\u4FDD\u5B58\u540E\u6240\u6709\u6218\u961F\u7AD9\u70B9\u7ACB\u5373\u751F\u6548\uFF08\u6218\u961F\u7CFB\u7EDF\u5185\u53EA\u5C55\u793A\uFF0C\u4E0D\u53EF\u518D\u4FEE\u6539\uFF09"), /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle2",
      fontWeight: 700,
      sx: {
        mb: 1.5
      }
    }, "\u8F6E\u6362\u8BBE\u7F6E"), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 1.5,
      alignItems: {
        sm: 'center'
      }
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: enabled,
        onChange: e => setEnabled(e.target.checked)
      }),
      label: enabled ? '功能已开启' : '功能已关闭'
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u951A\u70B9\u65E5\u671F\uFF08\u7B2C 1 \u4E2A\u7EC4\u5408\u5BF9\u5E94\u7684\u65E5\u671F\uFF09",
      type: "date",
      size: "small",
      value: start,
      onChange: e => setStart(e.target.value),
      InputLabelProps: {
        shrink: true
      },
      sx: {
        width: 260
      }
    }), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveSetting
    }, "\u4FDD\u5B58\u8BBE\u7F6E")), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mt: 1
      }
    }, "\u6BCF\u5929 9:00 \u5207\u6362\u5230\u4E0B\u4E00\u4E2A\u7EC4\u5408\u5FAA\u73AF\u8F6E\u6362\uFF1B\u51CC\u6668 0-9 \u70B9\u5C55\u793A\u5F53\u5929\u9884\u544A")), /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      sx: {
        mb: 1.5
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle2",
      fontWeight: 700,
      sx: {
        flex: 1
      }
    }, "\u5730\u56FE\u6C60\uFF08", data.maps.length, "\uFF09"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      onClick: () => openMap(null)
    }, "\u6DFB\u52A0\u5730\u56FE")), data.maps.length === 0 ? /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary"
    }, "\u8FD8\u6CA1\u6709\u5730\u56FE\uFF0C\u5148\u6DFB\u52A0\u51E0\u5F20") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u56FE\u7247"), /*#__PURE__*/React.createElement(TableCell, null, "\u540D\u79F0"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, data.maps.map(function (m) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: m.id
      }, /*#__PURE__*/React.createElement(TableCell, null, m.image ? /*#__PURE__*/React.createElement(Box, {
        component: "img",
        src: m.image,
        alt: m.name,
        sx: {
          width: 64,
          height: 40,
          objectFit: 'cover',
          borderRadius: '6px',
          cursor: 'zoom-in'
        },
        onClick: () => window.open(m.image, '_blank')
      }) : /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u65E0\u56FE")), /*#__PURE__*/React.createElement(TableCell, null, m.name), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.8
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: () => openMap(m)
      }, "\u7F16\u8F91"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        color: "error",
        onClick: () => removeMap(m)
      }, "\u5220\u9664"))));
    }))))), /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      sx: {
        mb: 1.5
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle2",
      fontWeight: 700,
      sx: {
        flex: 1
      }
    }, "\u6BCF\u65E5\u7EC4\u5408\uFF08", data.days.length, "\uFF0C\u6309\u987A\u5E8F\u5FAA\u73AF\uFF09"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      onClick: () => openDay(null),
      disabled: data.maps.length === 0
    }, "\u6DFB\u52A0\u7EC4\u5408")), data.days.length === 0 ? /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary"
    }, "\u8FD8\u6CA1\u6709\u6BCF\u65E5\u7EC4\u5408") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u987A\u5E8F"), /*#__PURE__*/React.createElement(TableCell, null, "\u540D\u79F0"), /*#__PURE__*/React.createElement(TableCell, null, "\u5730\u56FE"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, data.days.map(function (d, i) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: d.id
      }, /*#__PURE__*/React.createElement(TableCell, null, i + 1), /*#__PURE__*/React.createElement(TableCell, null, d.name || '#' + d.id), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.5,
        flexWrap: "wrap",
        useFlexGap: true
      }, (d.map_ids || []).map(function (mid) {
        return /*#__PURE__*/React.createElement(Chip, {
          key: mid,
          size: "small",
          label: mapById[mid] ? mapById[mid].name : '#' + mid
        });
      }))), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.8
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: () => openDay(d)
      }, "\u7F16\u8F91"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        color: "error",
        onClick: () => removeDay(d)
      }, "\u5220\u9664"))));
    }))))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!mapDlg,
      onClose: () => setMapDlg(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, mapDlg && mapDlg.id > 0 ? '编辑地图' : '添加地图'), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(TextField, {
      label: "\u5730\u56FE\u540D\u79F0",
      value: mapName,
      onChange: e => setMapName(e.target.value),
      inputProps: {
        maxLength: 64
      },
      fullWidth: true,
      size: "small",
      sx: {
        mb: 2,
        mt: 1
      },
      autoFocus: true
    }), /*#__PURE__*/React.createElement(Button, {
      component: "label",
      variant: "outlined",
      size: "small"
    }, mapFile ? '重选图片' : '选择地图图片（选填）', /*#__PURE__*/React.createElement("input", {
      type: "file",
      accept: "image/*",
      hidden: true,
      onChange: e => setMapFile(e.target.files[0] || null)
    })), mapFile && /*#__PURE__*/React.createElement(Box, {
      component: "img",
      src: URL.createObjectURL(mapFile),
      alt: "\u9884\u89C8",
      sx: {
        width: 120,
        height: 75,
        objectFit: 'cover',
        borderRadius: '6px',
        mt: 1.5,
        display: 'block'
      }
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setMapDlg(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveMap
    }, "\u4FDD\u5B58"))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!dayDlg,
      onClose: () => setDayDlg(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, dayDlg && dayDlg.id > 0 ? '编辑每日组合' : '添加每日组合'), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(TextField, {
      label: "\u7EC4\u5408\u540D\u79F0\uFF08\u9009\u586B\uFF09",
      value: dayName,
      onChange: e => setDayName(e.target.value),
      inputProps: {
        maxLength: 32
      },
      fullWidth: true,
      size: "small",
      sx: {
        mb: 1.5,
        mt: 1
      },
      autoFocus: true
    }), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 0.5
      }
    }, "\u5F53\u5929\u8F6E\u6362\u7684\u5730\u56FE\uFF08\u53EF\u591A\u9009\uFF09\uFF1A"), data.maps.map(function (m) {
      return /*#__PURE__*/React.createElement(FormControlLabel, {
        key: m.id,
        sx: {
          display: 'block'
        },
        control: /*#__PURE__*/React.createElement(Checkbox, {
          size: "small",
          checked: dayMaps.indexOf(m.id) >= 0,
          onChange: () => toggleDayMap(m.id)
        }),
        label: m.name
      });
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setDayDlg(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveDay
    }, "\u4FDD\u5B58"))));
  }

  // ==================== 平台超管控制台 ====================
  // ==================== QQ 机器人（v5.28.0：平台侧只剩敏感词与调用日志） ====================
  // 机器人凭据、接口环境、测试连接、群指令面板都在各战队的「管理中心 → QQ机器人」那一页；
  // 平台不再持有任何机器人的 AppID / AppSecret，也不再决定哪支战队能用——配了自己的机器人就都能用。
  function PlatformBot() {
    const [cfg, setCfg] = useState(null);
    const [form, setForm] = useState({
      words: '',
      mask_char: '*#'
    });
    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/bot-words');
        if (json.code === 0) {
          setCfg(json.data);
          setForm({
            words: json.data.words || '',
            mask_char: json.data.mask_char || '*#'
          });
        }
      } catch (e) {/* 忽略 */}
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const save = async function () {
      try {
        const json = await api('/api/platform/admin/bot-words', {
          method: 'POST',
          body: {
            words: form.words,
            mask_char: form.mask_char
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
    };
    if (cfg === null) {
      return /*#__PURE__*/React.createElement(Box, {
        sx: {
          textAlign: 'center',
          py: 4
        }
      }, /*#__PURE__*/React.createElement(CircularProgress, null));
    }
    return /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        whiteSpace: 'pre-line'
      }
    }, '机器人由各战队自己在「管理中心 → QQ机器人」里接入：AppID / AppSecret / 专属回调地址 / 测试连接 / 群指令面板都在那一页，所有战队都能用，不用平台逐个开通。\n' + '平台侧只留两件全站的事：下面这份出站敏感词（任何机器人回复都要过一遍），以及各战队的调用日志。'), /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2.5
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 600,
      sx: {
        mb: 0.5
      }
    }, "\u51FA\u7AD9\u6587\u672C\u98CE\u63A7\u5C4F\u853D"), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1.5
      }
    }, "\u673A\u5668\u4EBA\u53D1\u51FA\u7684\u6D88\u606F\u4F1A\u5148\u8FC7\u6EE4\u654F\u611F\u8BCD\uFF08\u547D\u4E2D\u9010\u5B57\u66FF\u6362\u4E3A\u5C4F\u853D\u5B57\u7B26\uFF0C\u907F\u514D\u6574\u6761\u88AB\u98CE\u63A7\u541E\u6389\uFF09\u3002 \u5185\u7F6E\u9ED8\u8BA4\u8BCD\u5E93\u4E4B\u5916\uFF0C\u53EF\u5728\u6B64\u8FFD\u52A0\uFF08\u6362\u884C\u6216\u9017\u53F7\u5206\u9694\uFF09\u3002"), /*#__PURE__*/React.createElement(TextField, {
      label: "\u81EA\u5B9A\u4E49\u654F\u611F\u8BCD",
      fullWidth: true,
      multiline: true,
      minRows: 3,
      size: "small",
      sx: {
        mb: 2
      },
      value: form.words,
      onChange: e => setForm(Object.assign({}, form, {
        words: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1.5,
      alignItems: "center",
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u5C4F\u853D\u5B57\u7B26",
      size: "small",
      value: form.mask_char,
      sx: {
        width: 200
      },
      inputProps: {
        maxLength: 4
      },
      onChange: e => setForm(Object.assign({}, form, {
        mask_char: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: save
    }, "\u4FDD\u5B58\u8BBE\u7F6E"))), /*#__PURE__*/React.createElement(BotLogsCard, null));
  }
  // ---- v5.8.3：机器人调用日志（平台超管可查看 / 一键清空） ----
  function BotLogsCard() {
    const [data, setData] = useState(null);
    const [page, setPage] = useState(1);
    const load = useCallback(async function (p) {
      try {
        const j = await api('/api/platform/admin/bot-logs?page=' + p);
        if (j.code === 0) setData(j.data);
      } catch (e) {/* 忽略 */}
    }, []);
    useEffect(function () {
      load(1);
    }, [load]);
    const clear = async function () {
      if (!window.confirm('确定清空全部机器人调用日志吗？此操作不可恢复。')) return;
      try {
        const j = await api('/api/platform/admin/bot-logs/clear', {
          method: 'POST'
        });
        toast(j.msg, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load(1);
      } catch (e) {
        toast('清空失败', 'error');
      }
    };
    const totalPages = data ? Math.max(1, Math.ceil(data.total / 50)) : 1;
    return /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2.5
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      justifyContent: "space-between",
      alignItems: "flex-start",
      sx: {
        mb: 1.5
      }
    }, /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 600
    }, "\u8C03\u7528\u65E5\u5FD7"), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary"
    }, "\u8BB0\u5F55\u6BCF\u6761\u7FA4\u6307\u4EE4\uFF08\u65F6\u95F4 / \u6218\u961F / \u6307\u4EE4 / \u539F\u6587\uFF09\uFF0C\u81EA\u52A8\u6E05\u7406 60 \u5929\u524D\u8BB0\u5F55\u3002")), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1
    }, /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: function () {
        load(page);
      }
    }, "\u5237\u65B0"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      color: "error",
      onClick: clear
    }, "\u6E05\u7A7A\u65E5\u5FD7"))), data === null ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 2
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)) : data.list.length === 0 ? /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary"
    }, "\u6682\u65E0\u8C03\u7528\u65E5\u5FD7") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, null, "\u6307\u4EE4"), /*#__PURE__*/React.createElement(TableCell, null, "\u539F\u59CB\u6D88\u606F"))), /*#__PURE__*/React.createElement(TableBody, null, data.list.map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.id
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          whiteSpace: 'nowrap'
        }
      }, r.created_at), /*#__PURE__*/React.createElement(TableCell, null, r.tenant_name), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: r.command
      })), /*#__PURE__*/React.createElement(TableCell, null, r.content));
    })))), data !== null && totalPages > 1 ? /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      justifyContent: "center",
      sx: {
        mt: 1.5
      }
    }, /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: page <= 1,
      onClick: function () {
        setPage(page - 1);
        load(page - 1);
      }
    }, "\u4E0A\u4E00\u9875"), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      sx: {
        alignSelf: 'center'
      }
    }, page, " / ", totalPages), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: page >= totalPages,
      onClick: function () {
        setPage(page + 1);
        load(page + 1);
      }
    }, "\u4E0B\u4E00\u9875")) : null);
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
      } catch (e) {
        setList([]);
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const approve = async function (t) {
      const tip = t.type === 'export' ? '同意「' + t.tenant_name + '」的导出申请？将打包该站全部数据（含图片，不含轮换地图）供其下载' : '同意「' + t.tenant_name + '」的导入申请？\n该站现有数据将被上传的数据包整体覆盖，请确认数据包来源可信';
      if (!window.confirm(tip)) return;
      try {
        const json = await api('/api/platform/admin/data/' + t.id + '/approve', {
          method: 'POST'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const doReject = async function () {
      try {
        const json = await api('/api/platform/admin/data/' + rejectT.id + '/reject', {
          method: 'POST',
          body: {
            reason: reason.trim()
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setRejectT(null);
          setReason('');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    if (list === null) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    if (list.length === 0) return /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u6570\u636E\u5BFC\u5165\u5BFC\u51FA\u4EFB\u52A1");
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "ID"), /*#__PURE__*/React.createElement(TableCell, null, "\u7AD9\u70B9"), /*#__PURE__*/React.createElement(TableCell, null, "\u7C7B\u578B"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u5305\u5927\u5C0F"), /*#__PURE__*/React.createElement(TableCell, null, "\u63D0\u4EA4\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "\u5907\u6CE8"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, list.map(function (t) {
      const chip = DATA_TASK_CHIP[t.status] || [t.status, 'default'];
      return /*#__PURE__*/React.createElement(TableRow, {
        key: t.id
      }, /*#__PURE__*/React.createElement(TableCell, null, t.id), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        fontWeight: 600
      }, t.tenant_name), t.tenant_slug && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "/", t.tenant_slug, "/")), /*#__PURE__*/React.createElement(TableCell, null, t.type === 'export' ? '导出' : '导入'), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: chip[0],
        color: chip[1],
        variant: t.status === 'pending' ? 'filled' : 'outlined'
      })), /*#__PURE__*/React.createElement(TableCell, null, t.file_size > 0 ? fmtBytes(t.file_size) : '-'), /*#__PURE__*/React.createElement(TableCell, null, fmtTime(t.created_at)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          maxWidth: 160,
          display: 'block'
        }
      }, t.note || '-')), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.5,
        flexWrap: "wrap",
        useFlexGap: true
      }, t.status === 'pending' && /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "contained",
        onClick: () => approve(t)
      }, "\u540C\u610F"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: "error",
        onClick: () => {
          setRejectT(t);
          setReason('');
        }
      }, "\u9A73\u56DE")), t.file_size > 0 && /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: function () {
          window.location.href = '/api/platform/admin/data/' + t.id + '/download';
        }
      }, "\u67E5\u770B\u5305"))));
    })))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!rejectT,
      onClose: () => setRejectT(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u9A73\u56DE\u6570\u636E\u4EFB\u52A1"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        mb: 1.5
      }
    }, "\u9A73\u56DE\u540E\u6218\u961F\u65B9\u53EF\u770B\u5230\u539F\u56E0\u5E76\u53EF\u91CD\u65B0\u63D0\u4EA4"), /*#__PURE__*/React.createElement(TextField, {
      label: "\u9A73\u56DE\u539F\u56E0\uFF08\u53EF\u9009\uFF09",
      value: reason,
      onChange: e => setReason(e.target.value),
      inputProps: {
        maxLength: 200
      },
      fullWidth: true,
      size: "small",
      autoFocus: true
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setRejectT(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      color: "error",
      onClick: doReject
    }, "\u786E\u8BA4\u9A73\u56DE"))));
  }

  // ==================== 赛事约战（v5.4.0：平台超管为最高控制者） ====================
  const PB_STATUS = {
    pending: ['待接受', 'warning'],
    accepted: ['进行中', 'primary'],
    finished: ['已完赛', 'success'],
    rejected: ['已拒绝', 'default'],
    canceled: ['已取消', 'default'],
    expired: ['已过期', 'default']
  };
  function pbChip(s) {
    const m = PB_STATUS[s] || [s, 'default'];
    return /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      color: m[1],
      label: m[0],
      variant: m[1] === 'default' ? 'outlined' : 'filled'
    });
  }
  function PlatformBattles() {
    const [sub, setSub] = useState('battles');
    const [data, setData] = useState(null); // {list, map_pool, tenants, externals}
    const [openId, setOpenId] = useState(0);
    const [detail, setDetail] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [rosterId, setRosterId] = useState(0);
    const [rform, setRform] = useState({
      side: 'a',
      kind: 'name',
      tenant_id: '',
      external_team_id: '',
      content: ''
    });
    const [teams, setTeams] = useState(null);
    const [teamForm, setTeamForm] = useState(null); // null=关闭；{id, name, contact_name, remark, status}
    const [keys, setKeys] = useState(null);
    const [issueForm, setIssueForm] = useState(null); // {scope, external_team_id, name}
    const [plain, setPlain] = useState('');
    const [cf, setCf] = useState({
      title: '',
      challenger: '',
      defender: '',
      format: 'bo3',
      btype: 'official',
      time: '',
      mode: '',
      limit: 20,
      room: '',
      remark: ''
    });
    const [rec, setRec] = useState({
      battleId: 0,
      roundId: 0,
      result: '',
      secs: '',
      count: '',
      ratio: ''
    });
    const [dec, setDec] = useState({
      battleId: 0,
      choice: 'pick_map',
      mapId: '',
      att: 'a'
    });
    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/battles');
        if (json.code === 0) setData(json.data);
      } catch (e) {
        setData({
          list: [],
          map_pool: [],
          tenants: [],
          externals: []
        });
      }
    }, []);
    const loadTeams = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/external-teams');
        if (j.code === 0) setTeams(j.data || []);
      } catch (e) {
        setTeams([]);
      }
    }, []);
    const loadKeys = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/api-keys');
        if (j.code === 0) setKeys(j.data || []);
      } catch (e) {
        setKeys([]);
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    useEffect(function () {
      if (sub === 'teams') loadTeams();
      if (sub === 'keys') loadKeys();
    }, [sub, loadTeams, loadKeys]);
    const refreshDetail = async function (id) {
      try {
        const j = await api('/api/platform/admin/battles/' + id);
        if (j.code === 0) setDetail(j.data);
      } catch (e) {/* 忽略 */}
    };
    const toggle = function (id) {
      if (openId === id) {
        setOpenId(0);
        setDetail(null);
        return;
      }
      setOpenId(id);
      setDetail(null);
      refreshDetail(id);
    };
    const draw = async function (b) {
      const tip = b.drawn ? '已抽签，重新抽签将重建全部小局（仅未录胜负时允许），确定重抽吗？' : '执行抽签：从地图池剔除双方禁用图后随机抽各局地图与攻守方，确定吗？';
      if (!window.confirm(tip)) return;
      try {
        const j = await api('/api/platform/admin/battles/' + b.id + '/draw', {
          method: 'POST'
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          load();
          if (openId === b.id) refreshDetail(b.id);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('抽签失败', 'error');
      }
    };

    // 逐局录胜负（含可选决胜统计：进攻用时/占领区域/占点比例）
    const submitRecord = async function () {
      if (!rec.result) {
        toast('请选择获胜方', 'error');
        return;
      }
      const body = {
        result: rec.result
      };
      if (rec.secs !== '') body.attack_secs = rec.secs;
      if (rec.count !== '') body.occupy_count = rec.count;
      if (rec.ratio !== '') body.occupy_ratio = rec.ratio;
      const bid = rec.battleId;
      try {
        const j = await api('/api/platform/admin/battles/' + bid + '/round/' + rec.roundId + '/result', {
          method: 'POST',
          body: body
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          setRec({
            battleId: 0,
            roundId: 0,
            result: '',
            secs: '',
            count: '',
            ratio: ''
          });
          load();
          refreshDetail(bid);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('录入失败', 'error');
      }
    };

    // 手动指定决胜局优势方（正式赛赛事组裁决入口，本版本平台超管代管）
    const setAdv = async function (bid, side) {
      if (!window.confirm('指定 ' + (side === 'a' ? 'A 方' : 'B 方') + ' 为决胜局优势方？')) return;
      try {
        const j = await api('/api/platform/admin/battles/' + bid + '/advantage', {
          method: 'POST',
          body: {
            side: side
          }
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          load();
          refreshDetail(bid);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // 代优势方完成决胜局选图/选边（平台侧直接指定攻方 a/b）
    const submitDecider = async function () {
      const body = {
        choice: dec.choice
      };
      if (dec.choice === 'pick_map') {
        if (!dec.mapId) {
          toast('请选择决胜局地图', 'error');
          return;
        }
        body.map_id = parseInt(dec.mapId, 10);
      } else {
        body.attacker_side = dec.att;
      }
      const bid = dec.battleId;
      try {
        const j = await api('/api/platform/admin/battles/' + bid + '/decider', {
          method: 'POST',
          body: body
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          setDec({
            battleId: 0,
            choice: 'pick_map',
            mapId: '',
            att: 'a'
          });
          load();
          refreshDetail(bid);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('提交失败', 'error');
      }
    };
    // 可选新图：平台池剔除本场已用与双方禁图（决胜局地图不可与前序地图相同）
    const deciderMaps = function () {
      if (!detail || !data) return [];
      const used = (detail.rounds || []).filter(function (r) {
        return r.map_id;
      }).map(function (r) {
        return r.map_id;
      });
      return data.map_pool.filter(function (m) {
        return used.indexOf(m.id) < 0 && !(detail.ban_a && !detail.ban_a.passed && detail.ban_a.name === m.name) && !(detail.ban_b && !detail.ban_b.passed && detail.ban_b.name === m.name);
      });
    };
    const cancel = async function (id) {
      if (!window.confirm('强制取消该约战？')) return;
      try {
        const j = await api('/api/platform/admin/battles/' + id + '/cancel', {
          method: 'POST'
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          load();
          if (openId === id) refreshDetail(id);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const submitCreate = async function () {
      if (!cf.challenger || !cf.defender) {
        toast('请选择双方战队', 'error');
        return;
      }
      const c = cf.challenger.split(':'),
        d = cf.defender.split(':');
      try {
        const j = await api('/api/platform/admin/battles', {
          method: 'POST',
          body: {
            title: cf.title,
            challenger_type: c[0],
            challenger_id: parseInt(c[1], 10),
            defender_type: d[0],
            defender_id: parseInt(d[1], 10),
            series_format: cf.format,
            battle_type: cf.btype,
            battle_time: cf.time.replace('T', ' '),
            game_mode: cf.mode,
            roster_limit: parseInt(cf.limit, 10) || 0,
            room_info: cf.room,
            remark: cf.remark
          }
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          setCreateOpen(false);
          setCf({
            title: '',
            challenger: '',
            defender: '',
            format: 'bo3',
            btype: 'official',
            time: '',
            mode: '',
            limit: 20,
            room: '',
            remark: ''
          });
          load();
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('发起失败', 'error');
      }
    };
    const submitRoster = async function () {
      const body = {
        side: rform.side
      };
      if (rform.kind === 'user') {
        const tid = parseInt(rform.tenant_id, 10);
        if (!tid) {
          toast('请填写用户所属租户 id', 'error');
          return;
        }
        body.add_user_ids = rform.content.split(/[,，\s]+/).filter(Boolean).map(Number);
        body.tenant_id = tid;
      } else {
        const eid = parseInt(rform.external_team_id, 10);
        if (!eid) {
          toast('请填写外部战队 id', 'error');
          return;
        }
        body.add_names = rform.content.split(/[,，]/).map(function (s) {
          return s.trim();
        }).filter(Boolean);
        body.external_team_id = eid;
      }
      try {
        const j = await api('/api/platform/admin/battles/' + rosterId + '/roster', {
          method: 'POST',
          body: body
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          setRosterId(0);
          load();
          if (openId === rosterId) refreshDetail(rosterId);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('提交失败', 'error');
      }
    };
    const removeSignup = async function (bid, sid) {
      try {
        const j = await api('/api/platform/admin/battles/' + bid + '/roster', {
          method: 'POST',
          body: {
            remove_id: sid
          }
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          refreshDetail(bid);
          load();
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // 外部战队保存 / 密钥签发与状态切换 / 删除复用同一套 api
    const saveTeam = async function () {
      if (!teamForm.name.trim()) {
        toast('请填写战队名称', 'error');
        return;
      }
      try {
        const j = await api('/api/platform/admin/external-teams/save', {
          method: 'POST',
          body: teamForm
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          setTeamForm(null);
          loadTeams();
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
    };
    const issueKey = async function () {
      try {
        const j = await api('/api/platform/admin/api-keys/issue', {
          method: 'POST',
          body: {
            scope: issueForm.scope,
            external_team_id: parseInt(issueForm.external_team_id, 10) || 0,
            name: issueForm.name
          }
        });
        if (j.code === 0) {
          setIssueForm(null);
          setPlain(j.data.key);
          loadKeys();
          loadTeams();
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('签发失败', 'error');
      }
    };
    const toggleKey = async function (id) {
      try {
        const j = await api('/api/platform/admin/api-keys/' + id + '/status', {
          method: 'POST'
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          loadKeys();
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const removeKey = async function (id) {
      if (!window.confirm('删除后该密钥立即失效，确定吗？')) return;
      try {
        const j = await api('/api/platform/admin/api-keys/' + id, {
          method: 'DELETE'
        });
        if (j.code === 0) {
          toast(j.msg, 'success');
          loadKeys();
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const partyOptions = function () {
      const opts = [];
      (data && data.tenants || []).forEach(function (t) {
        opts.push({
          v: 'tenant:' + t.id,
          l: t.name + '（租户）'
        });
      });
      (data && data.externals || []).forEach(function (t) {
        opts.push({
          v: 'external:' + t.id,
          l: t.name + '（外部）'
        });
      });
      return opts;
    };
    const banText = function (ban) {
      if (ban === null || ban === undefined) return '未提交';
      return ban.passed ? '放弃禁用' : '禁用：' + ban.name;
    };
    const battleCard = function (b) {
      const expanded = openId === b.id;
      return /*#__PURE__*/React.createElement(Paper, {
        key: b.id,
        variant: "outlined",
        sx: {
          p: 2,
          mb: 1.5
        }
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 1.5,
        alignItems: "center"
      }, /*#__PURE__*/React.createElement(Box, {
        sx: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 1,
        alignItems: "center",
        sx: {
          flexWrap: 'wrap'
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        fontWeight: 600
      }, b.challenger.name), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: "VS"
      }), /*#__PURE__*/React.createElement(Typography, {
        fontWeight: 600
      }, b.defender.name), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: "primary",
        label: (b.series_format || 'bo3').toUpperCase()
      }), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: b.battle_type === 'training' ? 'warning' : 'info',
        label: b.battle_type === 'training' ? '训练赛' : '正式赛事'
      }), pbChip(b.status), b.drawn && /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        label: "\u5DF2\u62BD\u7B7E"
      })), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          mt: 0.4
        }
      }, b.title, b.game_mode ? ' · ' + b.game_mode : '', " \xB7 \u5F00\u8D5B\uFF1A", (b.battle_time || '').slice(0, 16))), b.drawn && /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontFamily: 'Consolas, monospace',
          fontWeight: 700,
          color: 'primary.main'
        }
      }, b.score_a, " : ", b.score_b), b.status === 'accepted' && /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.8
      }, b.battle_type !== 'training' && /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "contained",
        onClick: () => draw(b)
      }, b.drawn ? '重抽' : '抽签'), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        onClick: () => {
          setRosterId(b.id);
          setRform({
            side: 'a',
            kind: 'name',
            tenant_id: '',
            external_team_id: '',
            content: ''
          });
        }
      }, "\u540D\u5355\u8C03\u914D"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        color: "error",
        onClick: () => cancel(b.id)
      }, "\u5F3A\u5236\u53D6\u6D88")), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: () => toggle(b.id)
      }, expanded ? '收起' : '详情')), expanded && (detail ? /*#__PURE__*/React.createElement(Box, {
        sx: {
          mt: 1.5,
          pt: 1.5,
          borderTop: '1px dashed',
          borderColor: 'divider'
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          mb: 1
        }
      }, "\u7981\u7528\u5730\u56FE\uFF1A", detail.challenger.name, " ", banText(detail.ban_a), " \xB7 ", detail.defender.name, " ", banText(detail.ban_b), detail.result_note ? ' · ' + detail.result_note : ''), (detail.series_format === 'bo3' || detail.series_format === 'bo5') && detail.status === 'accepted' && /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 1,
        alignItems: "center",
        sx: {
          flexWrap: 'wrap',
          mb: 1
        }
      }, detail.adv_side ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "warning",
        label: '决胜优先权：' + (detail.adv_side === 'a' ? detail.challenger.name : detail.defender.name)
      }) : /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.8,
        alignItems: "center"
      }, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, detail.adv_reason || '决胜局优势方待指定'), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        onClick: () => setAdv(b.id, 'a')
      }, "\u6307\u5B9A A \u65B9\u4F18\u52BF"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        onClick: () => setAdv(b.id, 'b')
      }, "\u6307\u5B9A B \u65B9\u4F18\u52BF")), detail.adv_reason && detail.adv_side && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, detail.adv_reason), detail.adv_role && /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        label: detail.adv_role === 'pick_map' ? '已选图' : '已选边'
      }), detail.adv_side && !detail.adv_role && /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "contained",
        onClick: () => setDec({
          battleId: b.id,
          choice: 'pick_map',
          mapId: '',
          att: 'a'
        })
      }, "\u4EE3\u9009\u51B3\u80DC\uFF08\u9009\u56FE/\u9009\u8FB9\uFF09")), /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 2,
        sx: {
          mb: 1.5,
          flexWrap: 'wrap'
        }
      }, [['a', detail.challenger.name, detail.signups_a], ['b', detail.defender.name, detail.signups_b]].map(function (s) {
        return /*#__PURE__*/React.createElement(Box, {
          key: s[0],
          sx: {
            flex: 1,
            minWidth: 220
          }
        }, /*#__PURE__*/React.createElement(Typography, {
          variant: "body2",
          fontWeight: 600,
          sx: {
            mb: 0.5
          }
        }, s[1], "\uFF08", s[2].length, " \u4EBA\uFF09"), /*#__PURE__*/React.createElement(Box, {
          sx: {
            display: 'flex',
            flexWrap: 'wrap',
            gap: 0.5
          }
        }, s[2].length === 0 ? /*#__PURE__*/React.createElement(Typography, {
          variant: "caption",
          color: "text.secondary"
        }, "\u6682\u65E0") : s[2].map(function (su) {
          return /*#__PURE__*/React.createElement(Chip, {
            key: su.id,
            size: "small",
            label: su.name,
            onDelete: () => removeSignup(b.id, su.id)
          });
        })));
      })), detail.rounds.length === 0 ? /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, detail.battle_type === 'training' ? '训练赛：等待双方提交禁图后自动抽签' : '尚未抽签，点击右上「抽签」生成各局地图与攻守') : detail.rounds.map(function (r) {
        const winner = r.result === 'a_win' ? detail.challenger.name : r.result === 'b_win' ? detail.defender.name : null;
        const stats = [];
        if (r.attack_secs !== null) stats.push('进攻用时 ' + Math.floor(r.attack_secs / 60) + '分' + r.attack_secs % 60 + '秒');
        if (r.occupy_count !== null) stats.push('占领区域 ' + r.occupy_count);
        if (r.occupy_ratio !== null) stats.push('占点 ' + r.occupy_ratio + '%');
        return /*#__PURE__*/React.createElement(Paper, {
          key: r.round_no,
          variant: "outlined",
          sx: {
            p: 1.2,
            mb: 1,
            display: 'flex',
            alignItems: 'center',
            gap: 1.5
          }
        }, /*#__PURE__*/React.createElement(Typography, {
          variant: "body2",
          sx: {
            width: 60
          }
        }, "\u7B2C ", r.round_no, " \u5C40"), r.is_decider && /*#__PURE__*/React.createElement(Chip, {
          size: "small",
          color: "warning",
          label: "\u51B3\u80DC"
        }), /*#__PURE__*/React.createElement(Typography, {
          variant: "body2",
          fontWeight: 600,
          sx: {
            width: 130
          }
        }, r.map_name || '待定（选图/选边）'), /*#__PURE__*/React.createElement(Typography, {
          variant: "caption",
          color: "text.secondary",
          sx: {
            flex: 1
          }
        }, "\u653B\uFF1A", r.attacker_name || '待定', " \xB7 \u5B88\uFF1A", r.defender_name || '待定', stats.length > 0 ? ' · ' + stats.join(' · ') : ''), r.evidence && /*#__PURE__*/React.createElement(Link, {
          href: r.evidence,
          target: "_blank",
          rel: "noopener",
          variant: "caption"
        }, "\u622A\u56FE"), winner ? /*#__PURE__*/React.createElement(Chip, {
          size: "small",
          color: "success",
          label: winner + ' 胜'
        }) : b.status === 'accepted' ? r.is_decider && r.map_id === null ? /*#__PURE__*/React.createElement(Chip, {
          size: "small",
          variant: "outlined",
          label: "\u5F85\u9009\u56FE/\u9009\u8FB9"
        }) : /*#__PURE__*/React.createElement(Button, {
          size: "small",
          variant: "outlined",
          onClick: () => setRec({
            battleId: b.id,
            roundId: r.id,
            result: '',
            secs: '',
            count: '',
            ratio: ''
          })
        }, "\u5F55\u80DC\u8D1F") : /*#__PURE__*/React.createElement(Chip, {
          size: "small",
          variant: "outlined",
          label: "\u672A\u5F00\u8D5B"
        }));
      })) : /*#__PURE__*/React.createElement(Box, {
        sx: {
          mt: 1.5
        }
      }, /*#__PURE__*/React.createElement(CircularProgress, {
        size: 22
      }))));
    };
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Tabs, {
      value: sub,
      onChange: (e, v) => setSub(v),
      sx: {
        borderBottom: 1,
        borderColor: 'divider',
        mb: 2
      }
    }, /*#__PURE__*/React.createElement(Tab, {
      value: "battles",
      label: "\u7EA6\u6218\u603B\u89C8"
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "teams",
      label: "\u5916\u90E8\u6218\u961F"
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "keys",
      label: "API \u5BC6\u94A5"
    })), sub === 'battles' && (data === null ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)) : /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mb: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        flex: 1
      }
    }, "\u5730\u56FE\u6C60 ", data.map_pool.length, " \u5F20\uFF08\u8F6E\u6362\u5730\u56FE\uFF09\xB7 \u62BD\u7B7E\u6392\u9664\u53CC Ban \u540E\u968F\u673A\u62BD\u53D6\uFF0C\u9010\u5C40\u5F55\u5165\u80DC\u8D1F\u81EA\u52A8\u7ED3\u7B97"), /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      onClick: load
    }, "\u5237\u65B0"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: () => setCreateOpen(true)
    }, "\u4EE3\u53D1\u8D77\u7EA6\u6218")), data.list.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u7EA6\u6218") : data.list.map(battleCard))), sub === 'teams' && (teams === null ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)) : /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mb: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        flex: 1
      }
    }, "\u5F00\u6E90\u5355\u79DF\u6237\u7AD9\u63A5\u5165\u7EA6\u6218\u603B\u7EBF\uFF1A\u767B\u8BB0\u540E\u7B7E\u53D1 external Key\uFF0C\u5BF9\u65B9\u5728\u7AD9\u70B9\u8BBE\u7F6E\u4E2D\u586B\u5165\u5373\u53EF\u63A5\u5165"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: () => setTeamForm({
        id: 0,
        name: '',
        contact_name: '',
        remark: '',
        status: 1
      })
    }, "\u767B\u8BB0\u5916\u90E8\u6218\u961F")), teams.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u5916\u90E8\u6218\u961F") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "ID"), /*#__PURE__*/React.createElement(TableCell, null, "\u961F\u540D"), /*#__PURE__*/React.createElement(TableCell, null, "\u8054\u7CFB\u4EBA"), /*#__PURE__*/React.createElement(TableCell, null, "\u5907\u6CE8"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u5BC6\u94A5"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, teams.map(function (t) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: t.id
      }, /*#__PURE__*/React.createElement(TableCell, null, t.id), /*#__PURE__*/React.createElement(TableCell, null, t.name), /*#__PURE__*/React.createElement(TableCell, null, t.contact_name || '-'), /*#__PURE__*/React.createElement(TableCell, null, t.remark || '-'), /*#__PURE__*/React.createElement(TableCell, null, parseInt(t.status, 10) === 1 ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "success",
        label: "\u6B63\u5E38"
      }) : /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: "\u505C\u7528"
      })), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: () => setIssueForm({
          scope: 'external',
          external_team_id: String(t.id),
          name: t.name
        })
      }, "\u7B7E\u53D1 Key")), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: () => setTeamForm({
          id: t.id,
          name: t.name,
          contact_name: t.contact_name,
          remark: t.remark,
          status: parseInt(t.status, 10)
        })
      }, "\u7F16\u8F91")));
    })))))), sub === 'keys' && (keys === null ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)) : /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mb: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        flex: 1
      }
    }, "platform \u952E\u7B49\u540C\u5E73\u53F0\u8D85\u7BA1\uFF08\u62BD\u7B7E/\u5F55\u5165\u80DC\u8D1F\uFF09\uFF1B\u79DF\u6237\u81EA\u5EFA\u952E\u5728\u5404\u6218\u961F\u7BA1\u7406\u4E2D\u5FC3\u7EF4\u62A4\uFF0C\u6B64\u5904\u4EC5\u5217\u5E73\u53F0\u4FA7\u5BC6\u94A5"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: () => setIssueForm({
        scope: 'platform',
        external_team_id: '',
        name: ''
      })
    }, "\u7B7E\u53D1\u5BC6\u94A5")), keys.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u5E73\u53F0\u4FA7\u5BC6\u94A5") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u524D\u7F00"), /*#__PURE__*/React.createElement(TableCell, null, "\u5907\u6CE8"), /*#__PURE__*/React.createElement(TableCell, null, "\u7C7B\u578B"), /*#__PURE__*/React.createElement(TableCell, null, "\u5916\u90E8\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u6700\u540E\u4F7F\u7528"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, keys.map(function (k) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: k.id
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          fontFamily: 'Consolas, monospace'
        }
      }, k.key_prefix, "\u2026"), /*#__PURE__*/React.createElement(TableCell, null, k.name), /*#__PURE__*/React.createElement(TableCell, null, k.scope === 'platform' ? '平台' : '外部战队'), /*#__PURE__*/React.createElement(TableCell, null, k.team_name || '-'), /*#__PURE__*/React.createElement(TableCell, null, parseInt(k.status, 10) === 1 ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "success",
        label: "\u542F\u7528"
      }) : /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: "\u505C\u7528"
      })), /*#__PURE__*/React.createElement(TableCell, null, (k.last_used_at || '').slice(0, 16) || '-'), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: () => toggleKey(k.id)
      }, parseInt(k.status, 10) === 1 ? '停用' : '启用'), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        color: "error",
        onClick: () => removeKey(k.id)
      }, "\u5220\u9664")));
    })))))), /*#__PURE__*/React.createElement(Dialog, {
      open: createOpen,
      onClose: () => setCreateOpen(false),
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u4EE3\u53D1\u8D77\u7EA6\u6218"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u6807\u9898",
      fullWidth: true,
      sx: {
        mb: 2,
        mt: 1
      },
      inputProps: {
        maxLength: 50
      },
      value: cf.title,
      onChange: e => setCf(Object.assign({}, cf, {
        title: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 2,
      sx: {
        mb: 2
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1
      }
    }, /*#__PURE__*/React.createElement(InputLabel, {
      shrink: true
    }, "\u53D1\u8D77\u65B9\uFF08A\uFF09"), /*#__PURE__*/React.createElement(Select, {
      fullWidth: true,
      value: cf.challenger,
      displayEmpty: true,
      onChange: e => setCf(Object.assign({}, cf, {
        challenger: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u8BF7\u9009\u62E9"), partyOptions().map(function (o) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: o.v,
        value: o.v
      }, o.l);
    }))), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1
      }
    }, /*#__PURE__*/React.createElement(InputLabel, {
      shrink: true
    }, "\u5BF9\u65B9\uFF08B\uFF09"), /*#__PURE__*/React.createElement(Select, {
      fullWidth: true,
      value: cf.defender,
      displayEmpty: true,
      onChange: e => setCf(Object.assign({}, cf, {
        defender: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u8BF7\u9009\u62E9"), partyOptions().map(function (o) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: o.v,
        value: o.v
      }, o.l);
    })))), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 2,
      sx: {
        mb: 2
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u8D5B\u4E8B\u7C7B\u578B",
      sx: {
        width: 200
      },
      value: cf.btype,
      onChange: e => setCf(Object.assign({}, cf, {
        btype: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "official"
    }, "\u6B63\u5F0F\u8D5B\u4E8B\uFF08\u5E73\u53F0\u88C1\u51B3\uFF09"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "training"
    }, "\u8BAD\u7EC3\u8D5B\uFF08\u81EA\u52A8\u62BD\u7B7E\u81EA\u88C1\u51B3\uFF09")), /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u8D5B\u5236",
      sx: {
        width: 240
      },
      value: cf.format,
      onChange: e => setCf(Object.assign({}, cf, {
        format: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "bo1"
    }, "BO1 \u5355\u5C40"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "bo3"
    }, "BO3 \u4E09\u5C40\u4E24\u80DC"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "bo5"
    }, "BO5 \u4E94\u5C40\u4E09\u80DC"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "r2"
    }, "R2 \u4E00\u56FE\u653B\u9632\u8F6C\u6362\u4E24\u628A"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "r4"
    }, "R4 \u4E24\u56FE\u653B\u9632\u8F6C\u6362\u56DB\u628A")), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6BCF\u65B9\u4EBA\u6570\u4E0A\u9650\uFF080=\u4E0D\u9650\uFF09",
      sx: {
        flex: 1
      },
      type: "number",
      inputProps: {
        min: 0,
        max: 100
      },
      value: cf.limit,
      onChange: e => setCf(Object.assign({}, cf, {
        limit: e.target.value
      }))
    })), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5F00\u8D5B\u65F6\u95F4",
      type: "datetime-local",
      fullWidth: true,
      sx: {
        mb: 2
      },
      InputLabelProps: {
        shrink: true
      },
      value: cf.time,
      onChange: e => setCf(Object.assign({}, cf, {
        time: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6A21\u5F0F\u8BF4\u660E",
      fullWidth: true,
      sx: {
        mb: 2
      },
      inputProps: {
        maxLength: 50
      },
      value: cf.mode,
      onChange: e => setCf(Object.assign({}, cf, {
        mode: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u623F\u95F4/\u5730\u70B9",
      fullWidth: true,
      sx: {
        mb: 2
      },
      inputProps: {
        maxLength: 200
      },
      value: cf.room,
      onChange: e => setCf(Object.assign({}, cf, {
        room: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5907\u6CE8",
      fullWidth: true,
      multiline: true,
      minRows: 2,
      inputProps: {
        maxLength: 500
      },
      value: cf.remark,
      onChange: e => setCf(Object.assign({}, cf, {
        remark: e.target.value
      }))
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setCreateOpen(false)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: submitCreate
    }, "\u53D1\u8D77"))), /*#__PURE__*/React.createElement(Dialog, {
      open: rec.battleId > 0,
      onClose: () => setRec({
        battleId: 0,
        roundId: 0,
        result: '',
        secs: '',
        count: '',
        ratio: ''
      }),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u5F55\u5165\u5C0F\u5C40\u80DC\u8D1F"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1.5
      }
    }, "BO3 \u6218\u6210 1:1 \u65F6\u7684\u51B3\u80DC\u5224\u5B9A\u4F9D\u636E\uFF1A\u53CC\u653B\u80DC\u6BD4\u8FDB\u653B\u7528\u65F6\uFF0C\u53CC\u5B88\u80DC\u6BD4\u5360\u9886\u533A\u57DF\uFF0C\u76F8\u540C\u6BD4\u5360\u70B9\u6BD4\u4F8B\u3002"), /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u83B7\u80DC\u65B9",
      fullWidth: true,
      sx: {
        mb: 2
      },
      value: rec.result,
      onChange: e => setRec(Object.assign({}, rec, {
        result: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u8BF7\u9009\u62E9"), detail && /*#__PURE__*/React.createElement(MenuItem, {
      value: "a_win"
    }, "A \u65B9\uFF1A", detail.challenger.name), detail && /*#__PURE__*/React.createElement(MenuItem, {
      value: "b_win"
    }, "B \u65B9\uFF1A", detail.defender.name)), /*#__PURE__*/React.createElement(Stack, {
      spacing: 1.5
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u8FDB\u653B\u7528\u65F6\uFF08\u79D2\uFF09",
      type: "number",
      fullWidth: true,
      inputProps: {
        min: 0
      },
      value: rec.secs,
      onChange: e => setRec(Object.assign({}, rec, {
        secs: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5360\u9886\u533A\u57DF\u6570",
      type: "number",
      fullWidth: true,
      inputProps: {
        min: 0
      },
      value: rec.count,
      onChange: e => setRec(Object.assign({}, rec, {
        count: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5360\u70B9\u6BD4\u4F8B\uFF08%\uFF09",
      type: "number",
      fullWidth: true,
      inputProps: {
        min: 0,
        max: 100
      },
      value: rec.ratio,
      onChange: e => setRec(Object.assign({}, rec, {
        ratio: e.target.value
      }))
    }))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setRec({
        battleId: 0,
        roundId: 0,
        result: '',
        secs: '',
        count: '',
        ratio: ''
      })
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: submitRecord
    }, "\u63D0\u4EA4"))), /*#__PURE__*/React.createElement(Dialog, {
      open: dec.battleId > 0,
      onClose: () => setDec({
        battleId: 0,
        choice: 'pick_map',
        mapId: '',
        att: 'a'
      }),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u51B3\u80DC\u5C40\uFF1A\u4EE3\u4F18\u52BF\u65B9\u9009\u56FE/\u9009\u8FB9"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1.5
      }
    }, "\u9009\u56FE\uFF1A\u6307\u5B9A\u5730\u56FE\uFF0C\u653B\u9632\u968F\u673A\uFF1B\u9009\u8FB9\uFF1A\u76F4\u63A5\u6307\u5B9A\u653B\u65B9\uFF08a=\u53D1\u8D77\u65B9 / b=\u5E94\u6218\u65B9\uFF09\uFF0C\u5730\u56FE\u4ECE\u5269\u4F59\u65B0\u56FE\u968F\u673A\u62BD\u53D6\u3002"), /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u51B3\u80DC\u4F18\u52BF\u7528\u6CD5",
      fullWidth: true,
      sx: {
        mb: 2
      },
      value: dec.choice,
      onChange: e => setDec(Object.assign({}, dec, {
        choice: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "pick_map"
    }, "\u9009\u56FE\uFF08\u6307\u5B9A\u5730\u56FE\uFF09"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "pick_side"
    }, "\u9009\u8FB9\uFF08\u6307\u5B9A\u653B\u65B9\uFF09")), dec.choice === 'pick_map' ? /*#__PURE__*/React.createElement(Select, {
      fullWidth: true,
      value: dec.mapId,
      displayEmpty: true,
      onChange: e => setDec(Object.assign({}, dec, {
        mapId: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u8BF7\u9009\u62E9\u51B3\u80DC\u5C40\u5730\u56FE"), deciderMaps().map(function (m) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: m.id,
        value: String(m.id)
      }, m.name);
    })) : /*#__PURE__*/React.createElement(Select, {
      fullWidth: true,
      value: dec.att,
      onChange: e => setDec(Object.assign({}, dec, {
        att: e.target.value
      }))
    }, detail && /*#__PURE__*/React.createElement(MenuItem, {
      value: "a"
    }, "\u653B\u65B9\uFF1A", detail.challenger.name, "\uFF08A\uFF09"), detail && /*#__PURE__*/React.createElement(MenuItem, {
      value: "b"
    }, "\u653B\u65B9\uFF1A", detail.defender.name, "\uFF08B\uFF09"))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setDec({
        battleId: 0,
        choice: 'pick_map',
        mapId: '',
        att: 'a'
      })
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: submitDecider
    }, "\u63D0\u4EA4"))), /*#__PURE__*/React.createElement(Dialog, {
      open: rosterId > 0,
      onClose: () => setRosterId(0),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u540D\u5355\u8C03\u914D\uFF08\u8D85\u7BA1\u4E0D\u53D7\u4E0A\u9650\u7EA6\u675F\uFF09"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, /*#__PURE__*/React.createElement(Stack, {
      spacing: 2,
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u8C03\u914D\u54EA\u4E00\u65B9",
      value: rform.side,
      onChange: e => setRform(Object.assign({}, rform, {
        side: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "a"
    }, "A \u65B9\uFF08\u53D1\u8D77\u65B9\uFF09"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "b"
    }, "B \u65B9\uFF08\u5E94\u6218\u65B9\uFF09")), /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u6DFB\u52A0\u7C7B\u578B",
      value: rform.kind,
      onChange: e => setRform(Object.assign({}, rform, {
        kind: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "name"
    }, "\u6635\u79F0\uFF08\u5916\u90E8\u6218\u961F\u540D\u5355\uFF09"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "user"
    }, "\u5E73\u53F0\u7528\u6237 id")), rform.kind === 'user' ? /*#__PURE__*/React.createElement(TextField, {
      label: "\u6240\u5C5E\u79DF\u6237 id",
      value: rform.tenant_id,
      onChange: e => setRform(Object.assign({}, rform, {
        tenant_id: e.target.value
      }))
    }) : /*#__PURE__*/React.createElement(TextField, {
      label: "\u5916\u90E8\u6218\u961F id",
      value: rform.external_team_id,
      onChange: e => setRform(Object.assign({}, rform, {
        external_team_id: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(TextField, {
      label: rform.kind === 'user' ? '用户 id（逗号分隔）' : '昵称（逗号分隔）',
      fullWidth: true,
      value: rform.content,
      onChange: e => setRform(Object.assign({}, rform, {
        content: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary"
    }, "\u79FB\u9664\u540D\u5355\uFF1A\u5728\u5C55\u5F00\u7684\u8BE6\u60C5\u91CC\u70B9\u51FB\u540D\u5355\u6807\u7B7E\u7684 \xD7"))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setRosterId(0)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: submitRoster
    }, "\u6DFB\u52A0"))), /*#__PURE__*/React.createElement(Dialog, {
      open: teamForm !== null,
      onClose: () => setTeamForm(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, teamForm && teamForm.id ? '编辑外部战队' : '登记外部战队'), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, teamForm && /*#__PURE__*/React.createElement(Stack, {
      spacing: 2,
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u961F\u540D",
      fullWidth: true,
      inputProps: {
        maxLength: 50
      },
      value: teamForm.name,
      onChange: e => setTeamForm(Object.assign({}, teamForm, {
        name: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u8054\u7CFB\u4EBA",
      fullWidth: true,
      inputProps: {
        maxLength: 50
      },
      value: teamForm.contact_name,
      onChange: e => setTeamForm(Object.assign({}, teamForm, {
        contact_name: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5907\u6CE8",
      fullWidth: true,
      multiline: true,
      minRows: 2,
      inputProps: {
        maxLength: 200
      },
      value: teamForm.remark,
      onChange: e => setTeamForm(Object.assign({}, teamForm, {
        remark: e.target.value
      }))
    }), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: teamForm.status === 1,
        onChange: e => setTeamForm(Object.assign({}, teamForm, {
          status: e.target.checked ? 1 : 0
        }))
      }),
      label: teamForm.status === 1 ? '正常' : '停用（其 Key 将不可用）'
    }))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setTeamForm(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveTeam
    }, "\u4FDD\u5B58"))), /*#__PURE__*/React.createElement(Dialog, {
      open: issueForm !== null,
      onClose: () => setIssueForm(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u7B7E\u53D1 API \u5BC6\u94A5"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, issueForm && /*#__PURE__*/React.createElement(Stack, {
      spacing: 2,
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u7C7B\u578B",
      value: issueForm.scope,
      onChange: e => setIssueForm(Object.assign({}, issueForm, {
        scope: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "platform"
    }, "platform\uFF08\u7B49\u540C\u5E73\u53F0\u8D85\u7BA1\uFF09"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "external"
    }, "external\uFF08\u5916\u90E8\u6218\u961F\u63A5\u5165\uFF09")), issueForm.scope === 'external' && /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u5916\u90E8\u6218\u961F",
      value: issueForm.external_team_id,
      onChange: e => setIssueForm(Object.assign({}, issueForm, {
        external_team_id: e.target.value
      }))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u8BF7\u9009\u62E9"), (teams || []).map(function (t) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: t.id,
        value: String(t.id)
      }, t.name);
    })), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5907\u6CE8",
      fullWidth: true,
      value: issueForm.name,
      onChange: e => setIssueForm(Object.assign({}, issueForm, {
        name: e.target.value
      }))
    }))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setIssueForm(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: issueKey
    }, "\u7B7E\u53D1"))), /*#__PURE__*/React.createElement(Dialog, {
      open: plain !== '',
      onClose: () => setPlain(''),
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u5BC6\u94A5\u5DF2\u751F\u6210"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mb: 1.5
      }
    }, "\u5BC6\u94A5\u4EC5\u672C\u6B21\u663E\u793A\uFF0C\u8BF7\u7ACB\u5373\u4FDD\u5B58\uFF01\u9057\u5931\u53EA\u80FD\u5220\u9664\u91CD\u53D1\u3002"), /*#__PURE__*/React.createElement(TextField, {
      fullWidth: true,
      value: plain,
      InputProps: {
        readOnly: true,
        sx: {
          fontFamily: 'Consolas, monospace'
        }
      }
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => {
        try {
          navigator.clipboard.writeText(plain);
          toast('已复制', 'success');
        } catch (e) {
          toast('复制失败', 'error');
        }
      }
    }, "\u590D\u5236"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: () => setPlain('')
    }, "\u6211\u5DF2\u4FDD\u5B58"))));
  }

  // ==================== 赛事中心互通配置（v5.5.0）+ 多站点绑定（v5.5.1） ====================
  function PlatformTournament() {
    const [cfg, setCfg] = useState(null);
    const [url, setUrl] = useState('');
    const [key, setKey] = useState('');
    // v5.5.1：平台共享库多站点
    const [binds, setBinds] = useState(null);
    const [editB, setEditB] = useState(null);
    const [bForm, setBForm] = useState({
      name: '',
      url: '',
      key: '',
      channel: 'external',
      enabled: 1
    });
    const [confirmDel, setConfirmDel] = useState(null);
    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/tournament-config');
        if (json.code === 0) {
          setCfg(json.data);
          setUrl(json.data.url || '');
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('加载失败', 'error');
      }
    }, []);
    const loadBinds = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/tournament-bindings');
        if (json.code === 0) setBinds(json.data.bindings || []);
      } catch (e) {
        setBinds([]);
      }
    }, []);
    useEffect(function () {
      load();
      loadBinds();
    }, [load, loadBinds]);
    const save = async function () {
      try {
        const json = await api('/api/platform/admin/tournament-config', {
          method: 'POST',
          body: {
            url: url.trim(),
            key: key.trim()
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setKey('');
          load();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
    };
    const openAdd = function () {
      setEditB({
        __new: true
      });
      setBForm({
        name: '',
        url: '',
        key: '',
        channel: 'external',
        enabled: 1
      });
    };
    const openEdit = function (b) {
      setEditB(b);
      setBForm({
        name: b.name,
        url: b.base_url,
        key: '',
        channel: b.channel,
        enabled: b.enabled ? 1 : 0
      });
    };
    const saveBinding = async function () {
      const body = {
        name: bForm.name.trim(),
        base_url: bForm.url.trim(),
        key: bForm.key.trim(),
        channel: bForm.channel,
        enabled: bForm.enabled
      };
      const id = editB && !editB.__new ? editB.id : 0;
      try {
        const path = '/api/platform/admin/tournament-bindings' + (id ? '/' + id : '');
        const json = await api(path, {
          method: 'POST',
          body: body
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setEditB(null);
          loadBinds();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
    };
    const delBinding = async function () {
      try {
        const json = await api('/api/platform/admin/tournament-bindings/' + confirmDel.id, {
          method: 'DELETE'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setConfirmDel(null);
          loadBinds();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('删除失败', 'error');
      }
    };
    if (!cfg) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u8D5B\u4E8B\u4E2D\u5FC3\u662F\u72EC\u7ACB\u90E8\u7F72\u7684\u7F51\u7AD9\uFF08\u81EA\u5E26\u62A5\u540D\u5BA1\u6838/\u53CC\u8D25\u5BF9\u9635/\u5BF9\u5C40\u5F15\u64CE\uFF09\u3002\u5E73\u53F0\u53EF\u7EF4\u62A4", /*#__PURE__*/React.createElement("b", null, "\u591A\u4E2A\u8D5B\u4E8B\u4E2D\u5FC3"), "\uFF08\u5171\u4EAB\u5E93\uFF09\u4F9B\u6240\u6709\u6218\u961F\u63A5\u5165\uFF1B \u6218\u961F\u4E5F\u53EF\u81EA\u884C\u6DFB\u52A0\u672A\u6536\u5F55\u7684\u8D5B\u4E8B\u4E2D\u5FC3\u5E76\u83B7\u6388\u6743\u540E\u542F\u7528\u3002\u53CD\u5411\uFF1A\u8D5B\u4E8B\u7AD9 .env \u9700\u914D\u7F6E\u672C\u7AD9\u5730\u5740\u4E0E\u4E00\u679A\u672C\u5E73\u53F0\u7B7E\u53D1\u7684 platform Key \u505A\u8EAB\u4EFD\u6838\u9A8C\u3002"), /*#__PURE__*/React.createElement(Stack, {
      spacing: 1.5,
      sx: {
        maxWidth: 560,
        mb: 3
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 600
    }, "\u5386\u53F2\u5355\u7AD9\u70B9\u8FDE\u63A5\uFF08v5.5.0\uFF0C\u517C\u5BB9\u4FDD\u7559\uFF09"), /*#__PURE__*/React.createElement(TextField, {
      label: "\u8D5B\u4E8B\u4E2D\u5FC3\u5730\u5740",
      size: "small",
      value: url,
      onChange: e => setUrl(e.target.value),
      helperText: "\u8D5B\u4E8B\u7AD9\u5B8C\u6574\u8BBF\u95EE\u5730\u5740\uFF08http/https \u5F00\u5934\uFF09\uFF0C\u6E05\u7A7A\u5373\u65AD\u5F00\u4E92\u901A",
      placeholder: "https://tournament.example.com"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u8D5B\u4E8B\u7AD9 API Key",
      size: "small",
      value: key,
      onChange: e => setKey(e.target.value),
      helperText: cfg.key_set ? '已设置（' + cfg.key_masked + '），留空保持不变' : '在赛事站赛事组工作台「账号与密钥」签发',
      placeholder: cfg.key_set ? '••••••••（已设置，留空保持）' : '粘贴赛事站签发的 Key'
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: save
    }, "\u4FDD\u5B58\u914D\u7F6E"), cfg.url ? /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      onClick: () => window.open(cfg.url, '_blank')
    }, "\u6253\u5F00\u8D5B\u4E8B\u7AD9\u95E8\u6237") : null)), /*#__PURE__*/React.createElement(Divider, {
      sx: {
        my: 2.5
      }
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      justifyContent: "space-between",
      sx: {
        mb: 1.5
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 600
    }, "\u8D5B\u4E8B\u4E2D\u5FC3\u5171\u4EAB\u5E93\uFF08\u591A\u7AD9\u70B9\uFF0Cv5.5.1\uFF09"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      onClick: openAdd
    }, "\u65B0\u589E\u8D5B\u4E8B\u4E2D\u5FC3")), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1
      }
    }, "\u5171\u4EAB\u5E93\u4E2D\u7684\u8D5B\u4E8B\u4E2D\u5FC3\u5BF9\u6240\u6709\u6218\u961F\u53EF\u89C1\u3001\u53EF\u76F4\u63A5\u52A0\u5165\u4F7F\u7528\uFF08\u65E0\u9700\u9010\u4E00\u786E\u8BA4\uFF09\u3002Key \u53EA\u5B58\u670D\u52A1\u7AEF\u3001\u6253\u7801\u56DE\u663E\u3002"), /*#__PURE__*/React.createElement(TableContainer, {
      component: Paper,
      variant: "outlined"
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u540D\u79F0"), /*#__PURE__*/React.createElement(TableCell, null, "\u5730\u5740"), /*#__PURE__*/React.createElement(TableCell, null, "\u8EAB\u4EFD\u901A\u9053"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right"
    }, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, !binds ? /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, {
      colSpan: 5,
      align: "center"
    }, /*#__PURE__*/React.createElement(CircularProgress, {
      size: 22
    }))) : binds.length === 0 ? /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, {
      colSpan: 5,
      align: "center",
      sx: {
        color: 'text.secondary'
      }
    }, "\u6682\u65E0\u5171\u4EAB\u8D5B\u4E8B\u4E2D\u5FC3\uFF0C\u70B9\u51FB\u53F3\u4E0A\u89D2\u65B0\u589E")) : binds.map(function (b) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: b.id,
        hover: true
      }, /*#__PURE__*/React.createElement(TableCell, null, b.name), /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          color: 'text.secondary',
          maxWidth: 240,
          overflow: 'hidden',
          textOverflow: 'ellipsis',
          whiteSpace: 'nowrap'
        }
      }, b.base_url || b.url), /*#__PURE__*/React.createElement(TableCell, null, b.channel === 'tenant' ? '租户身份' : '外部身份'), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: b.enabled ? '启用' : '停用',
        color: b.enabled ? 'success' : 'default'
      })), /*#__PURE__*/React.createElement(TableCell, {
        align: "right"
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: () => openEdit(b)
      }, "\u7F16\u8F91"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        color: "error",
        onClick: () => setConfirmDel(b)
      }, "\u5220\u9664")));
    })))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!editB,
      onClose: () => setEditB(null),
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, editB && editB.__new ? '新增赛事中心' : '编辑赛事中心'), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Stack, {
      spacing: 1.5,
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u540D\u79F0",
      size: "small",
      value: bForm.name,
      onChange: e => setBForm({
        ...bForm,
        name: e.target.value
      }),
      helperText: "\u5982\uFF1A\u4E09\u89D2\u6D32\u9080\u8BF7\u8D5B\xB7\u534E\u4E1C\u533A"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u8D5B\u4E8B\u4E2D\u5FC3\u5730\u5740",
      size: "small",
      value: bForm.url,
      onChange: e => setBForm({
        ...bForm,
        url: e.target.value
      }),
      placeholder: "https://tournament.example.com"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "API Key",
      size: "small",
      value: bForm.key,
      onChange: e => setBForm({
        ...bForm,
        key: e.target.value
      }),
      helperText: editB && !editB.__new && editB.key_set ? '已设置（' + editB.key_masked + '），留空保持不变' : '在赛事站赛事组工作台「账号与密钥」签发'
    }), /*#__PURE__*/React.createElement(TextField, {
      select: true,
      label: "\u8EAB\u4EFD\u901A\u9053",
      size: "small",
      value: bForm.channel,
      onChange: e => setBForm({
        ...bForm,
        channel: e.target.value
      }),
      helperText: "\u5916\u90E8\u8EAB\u4EFD=\u901A\u7528\u7B2C\u4E09\u65B9\u7AD9\u7528\u6211\u65B9\u4E3A\u8BE5\u6218\u961F\u7EF4\u62A4\u7684\u5916\u90E8\u6218\u961F\u8EAB\u4EFD\uFF1B\u79DF\u6237\u8EAB\u4EFD=\u4EC5\u5BF9\u63A5\u80FD\u56DE\u8C03\u6838\u9A8C\u79DF\u6237\u7684\u7AD9"
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "external"
    }, "\u5916\u90E8\u6218\u961F\u8EAB\u4EFD\uFF08\u63A8\u8350\uFF0C\u901A\u7528\uFF09"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "tenant"
    }, "\u79DF\u6237\u8EAB\u4EFD\uFF08\u9AD8\u4FE1\u4EFB\u7AD9\u70B9\uFF09")), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Checkbox, {
        size: "small",
        checked: !!bForm.enabled,
        onChange: e => setBForm({
          ...bForm,
          enabled: e.target.checked ? 1 : 0
        })
      }),
      label: "\u542F\u7528\uFF08\u6218\u961F\u53EF\u52A0\u5165\u4F7F\u7528\uFF09"
    }))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setEditB(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveBinding
    }, "\u4FDD\u5B58"))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!confirmDel,
      onClose: () => setConfirmDel(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u5220\u9664\u8D5B\u4E8B\u4E2D\u5FC3"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary"
    }, "\u5220\u9664\u540E\u6218\u961F\u5C06\u65E0\u6CD5\u518D\u4F7F\u7528\u300C", confirmDel && confirmDel.name, "\u300D\uFF0C\u786E\u5B9A\u5220\u9664\uFF1F")), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setConfirmDel(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      color: "error",
      onClick: delBinding
    }, "\u786E\u8BA4\u5220\u9664"))));
  }

  // ==================== v5.16.0：隐身巡查台账 ====================
  // 平台超管看战队后台数据只有这一条通道：/{slug}/api/inspect/*（白名单只读接口）。
  // 一次性令牌只用在第一条请求上——兑票后换 30 分钟 HttpOnly 巡查 cookie，之后各页签凭 cookie 读；
  // 每一次读取都会写入该站点的操作日志（站长可见），所以这里给的是全量字段而不是打码名单。
  const INSPECT_TABS = [['overview', '站点概览'], ['members', '成员全量资料'], ['pending', '待审核注册'], ['blacklist', '黑名单'], ['password-resets', '找回密码申请'], ['logs', '站长操作日志'], ['images', '图片台账'], ['player-stats', '战绩台账'], ['battles', '约战台账']];
  function inspectCell(v) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? '是' : '否';
    if (typeof v === 'object') {
      const s = Object.keys(v).map(function (k) {
        return k + ':' + v[k];
      }).join(' ');
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
    const tokRef = useRef(props.token); // 一次性：兑票后立即置空，绝不重复带

    const load = useCallback(async function (name, pg) {
      setBusy(true);
      setErr('');
      setData(null);
      let url = base + name;
      if (pg > 1) url += '?page=' + pg;
      if (tokRef.current) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + '_sv=' + encodeURIComponent(tokRef.current);
        tokRef.current = '';
      }
      try {
        const json = await api(url);
        if (json.code !== 0) {
          setErr((json.msg || '读取失败') + '（巡查会话 30 分钟有效、可被平台随时撤销；需要时重新点「巡查台账」签发入口）');
          return;
        }
        setData(json.data);
      } catch (e) {
        setErr('读取失败：巡查会话可能已过期（30 分钟）或已被撤销，请重新签发入口');
      } finally {
        setBusy(false);
      }
    }, [base]);
    useEffect(function () {
      load(view, page);
    }, [view, page, load]);
    const paged = (view === 'members' || view === 'player-stats') && data && data.total > data.size;
    const cols = data && data.list && data.list.length ? Object.keys(data.list[0]) : [];
    return /*#__PURE__*/React.createElement(Dialog, {
      open: true,
      onClose: props.onClose,
      fullWidth: true,
      maxWidth: "lg"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u5DE1\u67E5\u53F0\u8D26 \xB7 ", t.name, /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block'
      }
    }, "\u53EA\u8BFB\u901A\u9053 /", t.slug, "/api/inspect/* \xB7 30 \u5206\u949F\u5185\u6709\u6548 \xB7 \u6BCF\u6B21\u8BFB\u53D6\u90FD\u5199\u5165\u8BE5\u7AD9\u70B9\u64CD\u4F5C\u65E5\u5FD7", typeof props.quota === 'number' ? '（本小时剩余签发额度 ' + props.quota + '）' : '')), /*#__PURE__*/React.createElement(DialogContent, {
      sx: {
        minHeight: 320
      }
    }, /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mb: 1.5
      }
    }, "\u4F60\u5728\u5DE1\u67E5\u671F\u95F4\u8BFB\u53D6\u5230\u7684\u6BCF\u4E00\u4EFD\u6210\u5458\u8D44\u6599\uFF08\u5B8C\u6574 QQ / \u90AE\u7BB1 / \u6CE8\u518C IP\uFF09\u90FD\u4F1A\u9010\u6761\u7559\u75D5\uFF0C\u7AD9\u957F\u80FD\u5728\u540E\u53F0\u65E5\u5FD7\u91CC\u770B\u5230"), /*#__PURE__*/React.createElement(Tabs, {
      value: view,
      onChange: (e, v) => {
        setPage(1);
        setView(v);
      },
      variant: "scrollable",
      scrollButtons: "auto",
      sx: {
        minHeight: 38,
        borderBottom: 1,
        borderColor: 'divider',
        mb: 1.5
      }
    }, INSPECT_TABS.map(function (it) {
      return /*#__PURE__*/React.createElement(Tab, {
        key: it[0],
        value: it[0],
        label: it[1],
        sx: {
          minHeight: 38,
          py: 0.5
        }
      });
    })), busy ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)) : err ? /*#__PURE__*/React.createElement(Alert, {
      severity: "error"
    }, err) : view === 'overview' ? /*#__PURE__*/React.createElement(Box, null, data && data.notice && /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 1.5
      }
    }, data.notice), /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableBody, null, data && Object.keys(data).filter(k => k !== 'notice').map(function (k) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: k
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          width: 240
        }
      }, k), /*#__PURE__*/React.createElement(TableCell, null, inspectCell(data[k])));
    }))))) : /*#__PURE__*/React.createElement(Box, null, data && (data.list || []).length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u8BE5\u53F0\u8D26\u6682\u65E0\u8BB0\u5F55") : /*#__PURE__*/React.createElement(TableContainer, {
      sx: {
        maxHeight: 420
      }
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small",
      stickyHeader: true
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, cols.map(function (c) {
      return /*#__PURE__*/React.createElement(TableCell, {
        key: c
      }, c);
    }))), /*#__PURE__*/React.createElement(TableBody, null, (data ? data.list : []).map(function (row, i) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: i
      }, cols.map(function (c) {
        return /*#__PURE__*/React.createElement(TableCell, {
          key: c,
          sx: {
            whiteSpace: 'normal',
            wordBreak: 'break-all'
          }
        }, inspectCell(row[c]));
      }));
    })))), data && (data.total !== undefined || data.count !== undefined) && /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mt: 1
      }
    }, paged ? '共 ' + data.total + ' 条' : '本次返回 ' + (data.total !== undefined ? data.total : data.count) + ' 条'))), /*#__PURE__*/React.createElement(DialogActions, null, paged && /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mr: 'auto'
      }
    }, /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: page <= 1 || busy,
      onClick: () => setPage(page - 1)
    }, "\u4E0A\u4E00\u9875"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: busy,
      onClick: () => setPage(page + 1)
    }, "\u4E0B\u4E00\u9875")), /*#__PURE__*/React.createElement(Button, {
      onClick: () => load(view, page),
      disabled: busy
    }, "\u91CD\u65B0\u8BFB\u53D6"), /*#__PURE__*/React.createElement(Button, {
      onClick: () => window.open('/' + t.slug + '/app', '_blank')
    }, "\u524D\u53F0\u6D4F\u89C8"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: props.onClose
    }, "\u5173\u95ED")));
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
      } catch (e) {
        setRows([]);
      }
    }, [tid]);
    useEffect(function () {
      load();
    }, [load]);
    useEffect(function () {
      (async function () {
        try {
          const json = await api('/api/platform/admin/tenants');
          setTenants(json.data.list || []);
        } catch (e) {/* 非超管拿不到列表，筛选框留空即可 */}
      })();
    }, []);
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 1.5
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary"
    }, "\u9690\u8EAB\u5DE1\u67E5\u6BCF\u4E00\u6B21\u8BFB\u53D6\u90FD\u4F1A\u5199\u4E00\u884C\u65E5\u5FD7\uFF0C\u8FD9\u91CC\u662F\u5168\u5E73\u53F0\u6C47\u603B"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        minWidth: 180
      }
    }, /*#__PURE__*/React.createElement(Select, {
      size: "small",
      value: tid,
      onChange: e => setTid(Number(e.target.value))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: 0
    }, "\u5168\u90E8\u7AD9\u70B9"), tenants.map(function (x) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: x.id,
        value: x.id
      }, x.name);
    }))), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      onClick: load
    }, "\u5237\u65B0")), rows === null ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)) : rows.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u5DE1\u67E5\u8BB0\u5F55\uFF08\u5E73\u53F0\u8FD8\u6CA1\u4EBA\u7528\u8FC7\u5DE1\u67E5\u901A\u9053\uFF09") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "\u7AD9\u70B9"), /*#__PURE__*/React.createElement(TableCell, null, "\u5DE1\u67E5\u8005"), /*#__PURE__*/React.createElement(TableCell, null, "\u52A8\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, rows.map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.id
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          whiteSpace: 'nowrap'
        }
      }, fmtTime(r.created_at)), /*#__PURE__*/React.createElement(TableCell, null, r.tenant_name), /*#__PURE__*/React.createElement(TableCell, null, r.admin_name), /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          whiteSpace: 'normal',
          wordBreak: 'break-all'
        }
      }, r.detail));
    })))));
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
      try {
        const json = await api('/api/platform/my/login-logs');
        setMine(json.data);
      } catch (e) {
        setMine({
          list: [],
          unread: 0
        });
      }
    }, []);
    const loadTeam = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/login-logs?limit=200' + (tid ? '&tenant_id=' + tid : '') + (flt ? '&filter=' + flt : ''));
        setRows(json.data.list || []);
      } catch (e) {
        setRows([]);
      }
    }, [tid, flt]);
    useEffect(function () {
      loadMine();
    }, [loadMine]);
    useEffect(function () {
      loadTeam();
    }, [loadTeam]);
    useEffect(function () {
      (async function () {
        try {
          const json = await api('/api/platform/admin/tenants');
          setTenants(json.data.list || []);
        } catch (e) {/* 忽略 */}
      })();
    }, []);
    const markRead = async function () {
      setMarking(true);
      try {
        const json = await api('/api/platform/my/login-alerts/read', {
          method: 'POST'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setMine(function (p) {
            return p ? Object.assign({}, p, {
              unread: 0
            }) : p;
          });
        } else toast(json.msg, 'error');
      } catch (e) {
        toast('操作失败', 'error');
      } finally {
        setMarking(false);
      }
    };
    const okChip = function (r) {
      const isOk = r === '成功' || r === 'success';
      return /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: isOk ? 'success' : 'error',
        label: isOk ? '成功' : '密码错误'
      });
    };
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2,
        mb: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle2"
    }, "\u6211\u8FD9\u4E2A\u5E73\u53F0\u8D26\u53F7\u7684\u767B\u5F55"), mine && mine.unread > 0 && /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      color: "error",
      label: '新归属地未读 ' + mine.unread
    }), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flexGrow: 1
      }
    }), mine && mine.unread > 0 && /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      disabled: marking,
      onClick: markRead
    }, "\u6211\u5DF2\u6838\u5BF9")), mine && mine.unread > 0 && /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mb: 1
      }
    }, "\u6700\u8FD1\u6709\u6765\u81EA\u65B0\u5F52\u5C5E\u5730\u7684\u6210\u529F\u767B\u5F55\u3002\u82E5\u4E0D\u662F\u4F60\u672C\u4EBA\uFF0C\u8BF7\u7ACB\u5373\u4FEE\u6539\u5E73\u53F0\u8D85\u7BA1\u5BC6\u7801\u2014\u2014\u6539\u5BC6\u540E\u65E7\u4F1A\u8BDD\u4F1A\u88AB\u5F3A\u5236\u4E0B\u7EBF"), !mine ? /*#__PURE__*/React.createElement(CircularProgress, {
      size: 22
    }) : (mine.list || []).length === 0 ? /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary"
    }, "\u6682\u65E0\u767B\u5F55\u8BB0\u5F55") : /*#__PURE__*/React.createElement(TableContainer, {
      sx: {
        maxHeight: 220
      }
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small",
      stickyHeader: true
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "IP"), /*#__PURE__*/React.createElement(TableCell, null, "\u5F52\u5C5E\u5730"), /*#__PURE__*/React.createElement(TableCell, null, "\u65B9\u5F0F"), /*#__PURE__*/React.createElement(TableCell, null, "\u7ED3\u679C"))), /*#__PURE__*/React.createElement(TableBody, null, mine.list.map(function (l) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: l.id,
        sx: {
          bgcolor: l.flagged ? 'rgba(255,59,48,0.06)' : 'inherit'
        }
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          whiteSpace: 'nowrap'
        }
      }, fmtTime(l.created_at)), /*#__PURE__*/React.createElement(TableCell, null, l.ip), /*#__PURE__*/React.createElement(TableCell, null, l.region || '—', l.flagged ? ' · 新归属地' : ''), /*#__PURE__*/React.createElement(TableCell, null, l.via === 'cookie' ? '免登录恢复' : l.via === 'passport' ? '通行证登录' : '密码登录'), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: l.result === '成功' ? 'success' : 'error',
        label: l.result
      })));
    }))))), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 1.5
      },
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle2"
    }, "\u5404\u6218\u961F\u7684\u7BA1\u7406\u5458\u767B\u5F55"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        minWidth: 180
      }
    }, /*#__PURE__*/React.createElement(Select, {
      size: "small",
      value: tid,
      onChange: e => setTid(Number(e.target.value))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: 0
    }, "\u5168\u90E8\u7AD9\u70B9"), tenants.map(function (x) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: x.id,
        value: x.id
      }, x.name);
    }))), [['', '全部'], ['flagged', '仅新归属地'], ['fail', '仅失败']].map(function (f) {
      return /*#__PURE__*/React.createElement(Button, {
        key: f[0],
        size: "small",
        variant: flt === f[0] ? 'contained' : 'outlined',
        onClick: () => setFlt(f[0])
      }, f[1]);
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: loadTeam
    }, "\u5237\u65B0")), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1
      }
    }, "\u53EA\u8BB0\u5404\u6218\u961F\u7684\u961F\u957F\u4E0E\u526F\u7BA1\u7406\u5458\u8D26\u53F7\uFF1B\u6210\u5458\u767B\u5F55\u4E0D\u5165\u8FD9\u5F20\u8868\uFF0C\u9632\u649E\u5E93\u4ECD\u7531 IP \u5C01\u7981\u4E0E\u8D26\u53F7\u9501\u5B9A\u8D1F\u8D23"), rows === null ? /*#__PURE__*/React.createElement(CircularProgress, {
      size: 22
    }) : rows.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u767B\u5F55\u8BB0\u5F55") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "\u7AD9\u70B9"), /*#__PURE__*/React.createElement(TableCell, null, "\u8D26\u53F7"), /*#__PURE__*/React.createElement(TableCell, null, "IP"), /*#__PURE__*/React.createElement(TableCell, null, "\u5F52\u5C5E\u5730"), /*#__PURE__*/React.createElement(TableCell, null, "\u65B9\u5F0F"), /*#__PURE__*/React.createElement(TableCell, null, "\u7ED3\u679C"))), /*#__PURE__*/React.createElement(TableBody, null, rows.map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.id,
        sx: {
          bgcolor: r.flagged ? 'rgba(255,59,48,0.06)' : 'inherit'
        }
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          whiteSpace: 'nowrap'
        }
      }, fmtTime(r.created_at)), /*#__PURE__*/React.createElement(TableCell, null, r.tenant_name), /*#__PURE__*/React.createElement(TableCell, null, r.qq), /*#__PURE__*/React.createElement(TableCell, null, r.ip), /*#__PURE__*/React.createElement(TableCell, null, r.region || '—', r.flagged ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "warning",
        sx: {
          ml: 0.5
        },
        label: "\u65B0"
      }) : ''), /*#__PURE__*/React.createElement(TableCell, null, r.via === 'cookie' ? '免登录恢复' : r.via === 'passport' ? '通行证登录' : '密码登录'), /*#__PURE__*/React.createElement(TableCell, null, okChip(r.result)));
    })))));
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
  const MAIL_SECURE = [['', '按端口猜（465 走 SSL，其它端口走 STARTTLS）'], ['ssl', 'SSL（一般是 465）'], ['tls', 'STARTTLS（一般是 587 或 25）'], ['none', '不加密（本机中继、自测时才用）']];
  const MAIL_ITEM_STATUS = {
    pending: '待发',
    sending: '在飞',
    sent: '已发出',
    failed: '被退回',
    skipped: '没地址'
  };
  function MailStatusChip({
    s
  }) {
    const tone = s === 'sent' ? 'success' : s === 'failed' ? 'error' : s === 'skipped' ? 'warning' : 'default';
    return /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: MAIL_ITEM_STATUS[s] || s,
      color: tone,
      variant: tone === 'default' ? 'outlined' : 'filled',
      sx: {
        height: 20,
        fontSize: 11
      }
    });
  }
  const MAIL_BLAST_STATUS = {
    queued: '待发送',
    running: '发送中',
    stopped: '已暂停',
    done: '已发完'
  };
  function BlastStatusChip({
    s
  }) {
    const tone = s === 'done' ? 'success' : s === 'stopped' ? 'warning' : s === 'running' ? 'primary' : 'default';
    return /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: MAIL_BLAST_STATUS[s] || s,
      color: tone,
      variant: tone === 'default' ? 'outlined' : 'filled',
      sx: {
        height: 20,
        fontSize: 11
      }
    });
  }

  /** 配置卡：平台那一套发信账号 + 限速的三个数 */
  function MailConfig({
    cfg,
    onSaved
  }) {
    const [f, setF] = useState(Object.assign({}, cfg));
    const [busy, setBusy] = useState(false);
    useEffect(function () {
      setF(Object.assign({}, cfg));
    }, [cfg]);
    const set = function (k, v) {
      setF(Object.assign({}, f, function () {
        const o = {};
        o[k] = v;
        return o;
      }()));
    };
    const save = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/config', {
          method: 'POST',
          body: {
            enabled: f.enabled ? 1 : 0,
            host: f.host,
            port: f.port,
            secure: f.secure || '',
            user: f.user,
            pass: f.pass,
            from: f.from,
            preview: f.preview,
            batch: f.batch,
            gap: f.gap,
            max: f.max
          }
        });
        if (j.code === 0) {
          toast('已保存平台发信账号', 'success');
          setF(Object.assign({}, j.data.config, {
            pass: ''
          }));
          onSaved();
        } else {
          toast(j.msg || '保存失败', 'error');
        }
      } catch (e) {
        toast('保存失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const num = {
      width: {
        xs: '100%',
        sm: 130
      }
    };
    return /*#__PURE__*/React.createElement(Paper, {
      elevation: 1,
      sx: {
        p: {
          xs: 1.5,
          md: 2
        },
        mb: 1.5,
        borderRadius: '16px'
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 1,
        flexWrap: 'wrap',
        gap: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u5E73\u53F0\u53D1\u4FE1\u8D26\u53F7"), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: cfg.ready ? '已配好' : '还没配好',
      color: cfg.ready ? 'success' : 'warning',
      variant: "outlined",
      sx: {
        height: 20,
        fontSize: 11
      }
    }), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: Number(f.enabled) === 1,
        onChange: e => set('enabled', e.target.checked ? 1 : 0)
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12
        }
      }, "\u5F00\u653E\u7FA4\u53D1")
    })), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 1,
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "SMTP \u670D\u52A1\u5668",
      value: f.host,
      onChange: e => set('host', e.target.value),
      sx: {
        flex: '1 1 220px',
        minWidth: 0
      },
      placeholder: "\u5982 smtp.qq.com"
    }), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "\u7AEF\u53E3",
      type: "number",
      value: f.port,
      onChange: e => set('port', e.target.value),
      sx: num
    }), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 220px',
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 11,
        color: TEXT_SECONDARY,
        mb: 0.5
      }
    }, "\u52A0\u5BC6\u65B9\u5F0F"), /*#__PURE__*/React.createElement(Select, {
      size: "small",
      value: f.secure || '',
      onChange: e => set('secure', e.target.value),
      sx: {
        width: '100%'
      }
    }, MAIL_SECURE.map(function (o) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: o[0],
        value: o[0]
      }, o[1]);
    })))), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 1,
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "\u90AE\u7BB1\u8D26\u53F7\uFF08\u5B8C\u6574\u5730\u5740\uFF09",
      value: f.user,
      onChange: e => set('user', e.target.value),
      sx: {
        flex: '1 1 200px',
        minWidth: 0
      },
      placeholder: "123456@qq.com"
    }), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "\u6388\u6743\u7801",
      type: "password",
      value: f.pass || '',
      onChange: e => set('pass', e.target.value),
      sx: {
        flex: '1 1 200px',
        minWidth: 0
      },
      autoComplete: "new-password",
      placeholder: cfg.has_pass ? '不填＝保持原样（填 CLEAR 是清空）' : '邮箱授权码，不是登录密码',
      helperText: "QQ / 163 \u8981\u5148\u5728\u90AE\u7BB1\u8BBE\u7F6E\u91CC\u5F00\u542F SMTP \u670D\u52A1\u5E76\u62FF\u6388\u6743\u7801"
    })), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 1,
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "\u53D1\u4EF6\u4EBA\u663E\u793A\u540D",
      value: f.from,
      onChange: e => set('from', e.target.value),
      sx: {
        flex: '1 1 200px',
        minWidth: 0
      },
      placeholder: "\u53EA\u586B\u540D\u79F0\u5C31\u884C\uFF0C\u5982 \u5E73\u53F0\u8FD0\u8425",
      helperText: "\u4FE1\u5C01\u5730\u5740\u6052\u7B49\u4E8E\u4E0A\u9762\u90A3\u4E2A\u90AE\u7BB1\u8D26\u53F7\uFF0C\u8FD9\u91CC\u53EA\u5F71\u54CD\u6536\u4EF6\u4EBA\u770B\u5230\u7684\u540D\u5B57"
    }), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "\u9884\u89C8\u6536\u4EF6\u5730\u5740",
      value: f.preview,
      onChange: e => set('preview', e.target.value),
      sx: {
        flex: '1 1 200px',
        minWidth: 0
      },
      placeholder: "\u7559\u7A7A\uFF1D\u53D1\u5230\u81EA\u5DF1\u90A3\u4E2A\u53D1\u4FE1\u90AE\u7BB1",
      helperText: "\u300C\u5148\u53D1\u4E00\u5C01\u7ED9\u6211\u770B\u300D\u5C31\u53D1\u5230\u8FD9\u4E2A\u5730\u5740"
    })), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 1,
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "\u6BCF\u6279\u5C01\u6570",
      type: "number",
      value: f.batch,
      onChange: e => set('batch', e.target.value),
      sx: num,
      helperText: "\u4E00\u6B21\u70B9\u51FB\u53D1\u51E0\u5C01\uFF081-20\uFF09"
    }), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "\u6BCF\u5C01\u95F4\u9694\uFF08\u6BEB\u79D2\uFF09",
      type: "number",
      value: f.gap,
      onChange: e => set('gap', e.target.value),
      sx: {
        width: {
          xs: '100%',
          sm: 150
        }
      },
      helperText: "\u9010\u5C01\u9650\u901F\uFF0C\u522B\u628A\u90AE\u7BB1\u5361\u8FDB\u7070\u540D\u5355"
    }), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      label: "\u5355\u6279\u6700\u591A\u4EBA\u6570",
      type: "number",
      value: f.max,
      onChange: e => set('max', e.target.value),
      sx: num,
      helperText: "\u8D85\u8FC7\u5C31\u8981\u5206\u5F00\u53D1"
    })), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      disabled: busy,
      onClick: save
    }, "\u4FDD\u5B58"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: onSaved,
      disabled: busy
    }, "\u91CD\u65B0\u8BFB\u53D6"), !cfg.ready && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: '#b25000'
      }
    }, "\u670D\u52A1\u5668 / \u90AE\u7BB1\u8D26\u53F7 / \u6388\u6743\u7801\u4E09\u9879\u9F50\u4E86\u624D\u80FD\u53D1\u4FE1")));
  }

  /** 写作卡：标题 + 正文 + 收件分组 + 实时人数 + 预览 + 正式发送 */
  function MailCompose({
    cfg,
    draft,
    setDraft,
    onCreated
  }) {
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
      if (cfg && cfg.vars) {
        setVars(Object.keys(cfg.vars).map(function (k) {
          return [k, cfg.vars[k]];
        }));
      }
    }, [cfg]);
    const hasVar = function () {
      return vars.some(function (v) {
        return (draft.subject + draft.body).indexOf(v[0]) >= 0;
      });
    };
    const insertVar = function (token) {
      const onSubj = lastRef.current && lastRef.current === subjRef.current;
      const field = onSubj ? 'subject' : 'body';
      const el = onSubj ? subjRef.current : bodyRef.current;
      const cur = onSubj ? draft.subject : draft.body;
      const at = el && typeof el.selectionStart === 'number' ? el.selectionStart : cur.length;
      const to = el && typeof el.selectionEnd === 'number' ? el.selectionEnd : at;
      const next = cur.slice(0, at) + token + cur.slice(to);
      setDraft(Object.assign({}, draft, function () {
        var o = {};
        o[field] = next;
        return o;
      }()));
      // 受控输入框一改值光标会跳到末尾，等这一帧写完再把它放回插入位置后面
      setTimeout(function () {
        try {
          if (el) {
            el.focus();
            el.setSelectionRange(at + token.length, at + token.length);
          }
        } catch (e) {/* 拿不到光标就算了 */}
      }, 0);
    };
    const lookRender = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/render', {
          method: 'POST',
          body: {
            subject: draft.subject,
            body: draft.body,
            groups: draft.groups.join(','),
            include_banned: draft.banned ? '1' : '0'
          }
        });
        if (j.code === 0) {
          setSamples(j.data.list || []);
          setShowVars(true);
        } else {
          toast(j.msg || '拼不出示例', 'error');
        }
      } catch (e) {
        toast('拼不出示例', 'error');
      }
      setBusy(false);
    };
    const toggle = function (g) {
      const has = draft.groups.indexOf(g) >= 0;
      const next = has ? draft.groups.filter(function (x) {
        return x !== g;
      }) : draft.groups.concat([g]);
      setDraft(Object.assign({}, draft, {
        groups: next
      }));
    };

    // 人数只在「分组变了」时才要重算，标题正文改字不影响名单
    useEffect(function () {
      if (!draft.groups.length) {
        setStat(null);
        return;
      }
      let dead = false;
      const t = setTimeout(function () {
        api('/api/platform/admin/mail/recipients', {
          method: 'POST',
          body: {
            groups: draft.groups.join(','),
            include_banned: draft.banned ? '1' : '0'
          }
        }).then(function (j) {
          if (!dead) {
            setStat(j.code === 0 ? j.data : null);
          }
        }).catch(function () {
          if (!dead) {
            setStat(null);
          }
        });
      }, 350);
      return function () {
        dead = true;
        clearTimeout(t);
      };
    }, [draft.groups.join(','), draft.banned]); // eslint-disable-line

    const preview = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/preview', {
          method: 'POST',
          body: {
            subject: draft.subject,
            body: draft.body,
            groups: draft.groups.join(','),
            include_banned: draft.banned ? '1' : '0',
            host: cfg.host,
            port: cfg.port,
            secure: cfg.secure || '',
            user: cfg.user,
            pass: '',
            from: cfg.from
          }
        });
        if (j.code === 0) {
          setOkKey(key);
          setFmt(j.data && j.data.format || '');
          toast(j.msg || '预览已发出', 'success');
        } else {
          setOkKey('');
          setFmt('');
          toast(j.msg || '预览没发出去', 'error');
        }
      } catch (e) {
        toast('预览失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const send = async function () {
      setAsk(false);
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/blast', {
          method: 'POST',
          body: {
            subject: draft.subject,
            body: draft.body,
            groups: draft.groups.join(','),
            include_banned: draft.banned ? '1' : '0'
          }
        });
        if (j.code === 0) {
          setOkKey('');
          const id = Number(j.data.blast_id || 0);
          toast('批次 #' + id + ' 已建好，点「开始发送」就按限速一封封走', 'success');
          setFmt('');
          onCreated(id);
        } else {
          toast(j.msg || '建批失败', 'error');
        }
      } catch (e) {
        toast('建批失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const over = stat && Number(stat.over) === 1;
    return /*#__PURE__*/React.createElement(Paper, {
      elevation: 1,
      sx: {
        p: {
          xs: 1.5,
          md: 2
        },
        mb: 1.5,
        borderRadius: '16px'
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700,
        color: TEXT_PRIMARY,
        mb: 1
      }
    }, "\u5199\u8FD9\u5C01\u901A\u77E5"), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      fullWidth: true,
      label: "\u90AE\u4EF6\u6807\u9898",
      value: draft.subject,
      sx: {
        mb: 1
      },
      inputRef: subjRef,
      onFocus: function () {
        lastRef.current = subjRef.current;
      },
      onChange: e => setDraft(Object.assign({}, draft, {
        subject: e.target.value
      })),
      inputProps: {
        maxLength: cfg.subject_max
      },
      helperText: draft.subject.length + '' + ' / ' + cfg.subject_max + '　｜　可以写 {team} {name} 这些变量'
    }), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      fullWidth: true,
      multiline: true,
      minRows: 9,
      maxRows: 22,
      label: "\u6B63\u6587\uFF08\u4F60\u81EA\u5DF1\u5199\uFF09",
      value: draft.body,
      inputRef: bodyRef,
      onFocus: function () {
        lastRef.current = bodyRef.current;
      },
      onChange: e => setDraft(Object.assign({}, draft, {
        body: e.target.value
      })),
      inputProps: {
        maxLength: cfg.body_max
      },
      helperText: draft.body.length + ' / ' + cfg.body_max + '　｜　标签写一半会被当纯文本发（尖括号会露出来），要排版就整篇写成一封 HTML'
    }), /*#__PURE__*/React.createElement(Box, {
      sx: {
        p: 1,
        mb: 1,
        borderRadius: '12px',
        bgcolor: BG_GRAY,
        border: '1px solid ' + CARD_BORDER
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      sx: {
        gap: 1,
        flexWrap: 'wrap',
        useFlexGap: true
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        fontWeight: 600,
        color: TEXT_PRIMARY
      }
    }, "\u6BCF\u4E2A\u4EBA\u6536\u5230\u81EA\u5DF1\u90A3\u4E00\u4EFD\uFF08\u70B9\u4E00\u4E0B\u63D2\u5230\u5149\u6807\u90A3\u91CC\uFF09"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      disabled: busy || !draft.subject || !draft.body.trim(),
      onClick: lookRender
    }, "\u770B\u6E32\u67D3\u6548\u679C")), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 0.5,
      sx: {
        mt: 0.5
      },
      flexWrap: "wrap",
      useFlexGap: true
    }, vars.map(function (v) {
      return /*#__PURE__*/React.createElement(Tooltip, {
        key: v[0],
        title: v[1]
      }, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: v[0],
        onClick: function () {
          insertVar(v[0]);
        },
        sx: {
          height: 20,
          fontSize: 11.5,
          fontFamily: 'ui-monospace, Menlo, monospace',
          bgcolor: draft.body.indexOf(v[0]) >= 0 || draft.subject.indexOf(v[0]) >= 0 ? 'primary.main' : '#fff',
          color: draft.body.indexOf(v[0]) >= 0 || draft.subject.indexOf(v[0]) >= 0 ? '#fff' : TEXT_PRIMARY,
          border: '1px solid ' + CARD_BORDER,
          cursor: 'pointer'
        }
      }));
    })), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 11.5,
        lineHeight: '17px',
        color: TEXT_SECONDARY,
        mt: 0.5
      }
    }, "\u6807\u9898\u4E0E\u6B63\u6587\u90FD\u66FF\u6362\uFF0C\u53D1\u7684\u90A3\u4E00\u523B\u6309\u6536\u4EF6\u4EBA\u81EA\u5DF1\u90A3\u4E00\u884C\u73B0\u62FC\uFF1B\u5E93\u91CC\u5B58\u7684\u8FD8\u662F\u4F60\u5199\u7684\u8FD9\u4E00\u4EFD\u6A21\u677F\u3002 \u84DD\u6389\u7684\u8868\u793A\u8FD9\u6761\u5DF2\u7ECF\u7528\u4E0A\u4E86\u3002", hasVar() ? '' : ' 现在这条还没用变量，发出去人人都一样。')), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        mt: 1,
        mb: 0.5
      }
    }, "\u53D1\u7ED9\u8C01\uFF08\u53EF\u591A\u9009\uFF09"), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      flexWrap: "wrap",
      useFlexGap: true,
      sx: {
        mb: 1
      }
    }, MAIL_GROUPS.map(function (g) {
      return /*#__PURE__*/React.createElement(FormControlLabel, {
        key: g[0],
        sx: {
          mr: 0
        },
        control: /*#__PURE__*/React.createElement(Checkbox, {
          size: "small",
          checked: draft.groups.indexOf(g[0]) >= 0,
          onChange: () => toggle(g[0])
        }),
        label: /*#__PURE__*/React.createElement(Typography, {
          sx: {
            fontSize: 13
          }
        }, g[1])
      });
    })), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      flexWrap: "wrap",
      useFlexGap: true,
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      sx: {
        mr: 0
      },
      control: /*#__PURE__*/React.createElement(Checkbox, {
        size: "small",
        checked: draft.banned === 1,
        onChange: function (e) {
          setDraft(Object.assign({}, draft, {
            banned: e.target.checked ? 1 : 0
          }));
        }
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13
        }
      }, "\u5E26\u4E0A\u88AB\u5C01\u7981\u7684\u7AD9\u70B9\uFF08\u9500\u6BC1\u524D\u7684\u544A\u77E5\uFF09")
    }), stat && Number(stat.banned_people) > 0 && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: draft.banned ? '#b25000' : TEXT_SECONDARY
      }
    }, "\u73B0\u5728\u88AB\u5C01\u7684\u7AD9\u70B9\u91CC\u6709 ", stat.banned_teams, " \u652F\u3001\u6309\u4E0A\u9762\u52FE\u9009\u7684\u5206\u7EC4\u80FD\u7B97\u5230 ", stat.banned_people, " \u4EBA", draft.banned ? '，这一批已经带上他们' : '，不勾这一颗就一封都不发'), stat && Number(stat.banned_people) === 0 && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY
      }
    }, "\u73B0\u5728\u6CA1\u6709\u88AB\u5C01\u7981\u7684\u7AD9\u70B9\uFF0C\u8FD9\u4E00\u9897\u52FE\u4E0D\u52FE\u90FD\u4E00\u6837")), stat && /*#__PURE__*/React.createElement(Box, {
      sx: {
        p: 1,
        mb: 1,
        borderRadius: '12px',
        bgcolor: '#fafafc',
        border: '1px solid ' + CARD_BORDER
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        color: TEXT_PRIMARY
      }
    }, "\u8FD9\u4E00\u6279 ", stat.total, " \u4EBA\uFF1A\u7AD9\u957F\u4E0E\u7BA1\u7406\u5458 ", stat.owner, " + \u6210\u5458 ", stat.member, "\uFF0C\u8986\u76D6 ", stat.teams, " \u652F\u961F", draft.banned === 1 && Number(stat.banned_people) > 0 ? '（含被封禁的 ' + stat.banned_teams + ' 支 ' + stat.banned_people + ' 人）' : ''), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: stat.no_mail > 0 ? '#b25000' : TEXT_SECONDARY,
        mt: 0.25
      }
    }, "\u5176\u4E2D ", stat.no_mail, " \u4EBA\u6CA1\u6709\u53EF\u7528\u90AE\u7BB1 \u2014\u2014 \u90A3\u51E0\u5C01\u4F1A\u8BB0\u6210\u300C\u6CA1\u5730\u5740\u300D\u53D1\u4E0D\u51FA\u53BB\uFF0C\u4F46\u7AD9\u5185\u4FE1\u7167\u6837\u8865\u7ED9\u4ED6\u4EEC"), (stat.sample || []).length > 0 && /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 0.5,
      flexWrap: "wrap",
      useFlexGap: true,
      sx: {
        mt: 0.5
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 11,
        color: TEXT_SECONDARY,
        mr: 0.5
      }
    }, "\u5730\u5740\u6837\u4F8B\uFF08\u5DF2\u6253\u7801\uFF09"), stat.sample.map(function (s) {
      return /*#__PURE__*/React.createElement(Chip, {
        key: s,
        size: "small",
        label: s,
        sx: {
          height: 18,
          fontSize: 11
        }
      });
    })), over && /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mt: 1,
        p: 0.75
      }
    }, "\u8D85\u8FC7\u5355\u6279\u4E0A\u9650 ", stat.max, " \u4EBA\uFF1A\u8BF7\u8C03\u4F4E\u4E0A\u9650\u6216\u5206\u5F00\u53D1\u3002")), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      disabled: busy || !draft.groups.length,
      onClick: preview
    }, "\u5148\u53D1\u4E00\u5C01\u7ED9\u6211\u770B"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      disabled: busy || !ready || !draft.groups.length || over,
      onClick: () => setAsk(true)
    }, "\u6B63\u5F0F\u53D1\u9001"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: ready ? '#1b7a3d' : TEXT_SECONDARY
      }
    }, ready ? '这一份已预览过，可以正式发送' : '没预览过的正文发不出去；改一个字要重新预览'), fmt && /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '预览那封按' + fmt + '发',
      color: fmt === 'HTML' ? 'primary' : 'default',
      variant: "outlined",
      sx: {
        height: 20,
        fontSize: 11
      }
    })), /*#__PURE__*/React.createElement(Dialog, {
      open: ask,
      onClose: () => setAsk(false),
      maxWidth: "sm",
      fullWidth: true
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u786E\u8BA4\u7FA4\u53D1\u8FD9\u4E00\u5C01\uFF1F"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        lineHeight: '22px'
      }
    }, "\u53D1\u4EF6\uFF1A", cfg.user || '（还没配账号）', /*#__PURE__*/React.createElement("br", null), "\u6807\u9898\uFF1A", draft.subject, /*#__PURE__*/React.createElement("br", null), "\u6536\u4EF6\uFF1A", stat ? stat.total : '—', " \u4EBA\uFF08\u7AD9\u957F\u4E0E\u7BA1\u7406\u5458 ", stat ? stat.owner : '—', " + \u6210\u5458 ", stat ? stat.member : '—', "\uFF09", /*#__PURE__*/React.createElement("br", null), draft.banned === 1 && stat && Number(stat.banned_people) > 0 ? /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Box, {
      component: "span",
      sx: {
        color: '#b25000',
        fontWeight: 600
      }
    }, "\u8FD9\u4E00\u6279\u5E26\u4E0A\u4E86\u88AB\u5C01\u7981\u7684 ", stat.banned_teams, " \u652F\u961F\uFF08", stat.banned_people, " \u4EBA\uFF09"), "\u2014\u2014\u4ED6\u4EEC\u7684\u7AD9\u70B9\u6253\u4E0D\u5F00\uFF0C\u7AD9\u5185\u4FE1\u8981\u7B49\u89E3\u5C01\u540E\u624D\u770B\u5F97\u5230\uFF0C\u90AE\u7BB1\u8FD9\u4E00\u8DEF\u662F\u552F\u4E00\u80FD\u9001\u8FBE\u7684\u3002", /*#__PURE__*/React.createElement("br", null)) : null, "\u6BCF\u4E00\u5C01\u540C\u65F6\u8865\u4E00\u6761\u7AD9\u5185\u4FE1\uFF1B\u53D1\u51FA\u53BB\u6536\u4E0D\u56DE\uFF0C\u53EA\u80FD\u518D\u53D1\u4E00\u5C01\u66F4\u6B63\u901A\u77E5\u3002")), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setAsk(false)
    }, "\u518D\u770B\u770B"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: send
    }, "\u786E\u8BA4\u53D1\u9001"))), /*#__PURE__*/React.createElement(Dialog, {
      open: showVars,
      onClose: function () {
        setShowVars(false);
      },
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u53D1\u51FA\u53BB\u5C31\u662F\u8FD9\u4E2A\u6837\u5B50"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: TEXT_SECONDARY,
        mb: 1
      }
    }, "\u4ECE\u52FE\u7684\u5206\u7EC4\u91CC\u5404\u53D6\u4E00\u4E2A\u4EBA\u505A\u6837\u672C\uFF08\u4E0B\u9762\u8FD9\u4E24\u4EFD\u53EA\u662F\u7ED9\u4F60\u770B\u6E32\u67D3\u6548\u679C\uFF0C\u6CA1\u6709\u771F\u7684\u53D1\u51FA\u53BB\uFF09\u3002 \u6635\u79F0\u4E0E\u961F\u540D\u91CC\u7684\u5C16\u62EC\u53F7\u4F1A\u88AB\u53BB\u6389\uFF0C\u522B\u4EBA\u81EA\u5DF1\u586B\u7684\u4E1C\u897F\u4E0D\u4F1A\u5728\u4F60\u7684 HTML \u91CC\u585E\u6807\u7B7E\u3002"), samples === null && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13
      }
    }, "\u6B63\u5728\u62FC\u2026"), samples !== null && samples.length === 0 && /*#__PURE__*/React.createElement(Alert, {
      severity: "warning"
    }, "\u8FD9\u4E24\u7EC4\u91CC\u4E00\u4E2A\u4EBA\u90FD\u6CA1\u6709\uFF0C\u62FC\u4E0D\u51FA\u793A\u4F8B\u3002"), samples !== null && samples.map(function (s, i) {
      return /*#__PURE__*/React.createElement(Box, {
        key: i,
        sx: {
          p: 1.25,
          mb: 1,
          borderRadius: '12px',
          border: '1px solid ' + CARD_BORDER
        }
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.75,
        alignItems: "center",
        sx: {
          flexWrap: 'wrap',
          useFlexGap: true,
          mb: 0.5
        }
      }, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: s.who === 'owner' ? '站长与管理员' : '普通成员',
        sx: {
          height: 20,
          fontSize: 11
        }
      }), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12.5,
          color: TEXT_SECONDARY
        }
      }, s.team, " \xB7 ", s.name)), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13.5,
          fontWeight: 700,
          color: TEXT_PRIMARY
        }
      }, s.subject), /*#__PURE__*/React.createElement(Typography, {
        component: "pre",
        sx: {
          fontSize: 12.5,
          lineHeight: '19px',
          color: TEXT_PRIMARY,
          whiteSpace: 'pre-wrap',
          wordBreak: 'break-word',
          m: 0.5,
          fontFamily: 'inherit',
          maxHeight: 240,
          overflow: 'auto'
        }
      }, s.body));
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setShowVars(false);
      }
    }, "\u5173\u6389"))));
  }

  /** 队列面板：进度 + 逐封结果 + 开始/暂停/继续/重排 */
  function MailQueue({
    bid,
    onRefresh
  }) {
    const [p, setP] = useState(null);
    const [run, setRun] = useState(false);
    const runRef = useRef(false);
    const epRef = useRef(0);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/mail/blast?blast_id=' + bid + '&size=1');
        if (j.code === 0) {
          setP(j.data.progress);
          return j.data.progress;
        }
      } catch (e) {/* 读不到进度就留着上一份，别把面板清空 */}
      return null;
    }, [bid]);
    useEffect(function () {
      setP(null);
      setRun(false);
      load();
    }, [load]);
    const oneStep = async function () {
      try {
        const j = await api('/api/platform/admin/mail/step', {
          method: 'POST',
          body: {
            blast_id: bid
          }
        });
        if (j.code !== 0) {
          toast(j.msg || '这一步没跑成', 'error');
          return null;
        }
        setP(j.data);
        return j.data;
      } catch (e) {
        toast('发送请求没走通，队列停了', 'error');
        return null;
      }
    };

    // 一句一句推：每点一次「开始」换一个新的序号，上一轮循环下一轮发现自己过期就退出，
    // 所以任何时刻最多只有一条驱动在飞（服务端本来就是先认领再发送，这里只是不让两个进度互相盖）。
    useEffect(function () {
      if (!run) {
        runRef.current = false;
        return;
      }
      const ep = ++epRef.current;
      runRef.current = true;
      (async function () {
        let halted = false;
        while (ep === epRef.current && runRef.current) {
          const d = await oneStep();
          if (!d) {
            halted = true;
            break;
          }
          if (Number(d.left || 0) === 0) {
            break;
          }
          // 一封都没认领到＝这一批被暂停了/账号没配齐，再问下去只会空转
          if (Number(d.tried || 0) === 0) {
            halted = true;
            break;
          }
          await new Promise(function (r) {
            setTimeout(r, 200);
          });
        }
        if (ep !== epRef.current) {
          return;
        }
        if (halted) {
          setRun(false);
          return;
        }
        const p2 = await load();
        if (!p2 || Number(p2.left || 0) === 0) {
          setRun(false);
        }
      })();
      return function () {
        runRef.current = false;
      };
    }, [run]); // eslint-disable-line

    const act = async function (path, askText) {
      if (askText && !window.confirm(askText)) {
        return;
      }
      try {
        const j = await api('/api/platform/admin/mail/' + path, {
          method: 'POST',
          body: {
            blast_id: bid
          }
        });
        if (j.code === 0) {
          toast(j.msg || '好了', 'success');
          if (j.data && j.data.progress) {
            setP(j.data.progress);
          } else {
            load();
          }
          onRefresh();
        } else {
          toast(j.msg || '没改成', 'error');
        }
      } catch (e) {
        toast('请求失败', 'error');
      }
    };
    if (!p) {
      return /*#__PURE__*/React.createElement(Paper, {
        elevation: 1,
        sx: {
          p: 2,
          mb: 1.5,
          borderRadius: '16px'
        }
      }, /*#__PURE__*/React.createElement(LinearProgress, null));
    }
    const doneN = Number(p.sent || 0) + Number(p.failed || 0) + Number(p.skipped || 0);
    const pct = p.target > 0 ? Math.round(doneN * 100 / p.target) : 0;
    return /*#__PURE__*/React.createElement(Paper, {
      elevation: 1,
      sx: {
        p: {
          xs: 1.5,
          md: 2
        },
        mb: 1.5,
        borderRadius: '16px'
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 1,
        flexWrap: 'wrap',
        gap: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u6279\u6B21 #", bid, " \xB7 ", p.subject), /*#__PURE__*/React.createElement(BlastStatusChip, {
      s: p.status
    })), /*#__PURE__*/React.createElement(Box, {
      sx: {
        display: 'flex',
        alignItems: 'center',
        gap: 1,
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto',
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement(LinearProgress, {
      variant: "determinate",
      value: pct,
      sx: {
        height: 8,
        borderRadius: 4
      }
    })), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        whiteSpace: 'nowrap'
      }
    }, doneN, " / ", p.target)), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      flexWrap: "wrap",
      useFlexGap: true,
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '已发出 ' + p.sent,
      color: "success",
      variant: "outlined",
      sx: {
        fontSize: 11,
        height: 20
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '被退回 ' + p.failed,
      color: "error",
      variant: "outlined",
      sx: {
        fontSize: 11,
        height: 20
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '没地址 ' + p.skipped,
      color: "warning",
      variant: "outlined",
      sx: {
        fontSize: 11,
        height: 20
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '待发 ' + p.pending,
      variant: "outlined",
      sx: {
        fontSize: 11,
        height: 20
      }
    }), Number(p.sending) > 0 && /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '卡在「已认领」 ' + p.sending,
      color: "warning",
      sx: {
        fontSize: 11,
        height: 20
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '站内信 ' + p.notices,
      variant: "outlined",
      sx: {
        fontSize: 11,
        height: 20
      }
    })), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        mb: 1
      }
    }, p.msg || ''), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      flexWrap: "wrap",
      useFlexGap: true
    }, Number(p.left) > 0 && !run && p.status !== 'stopped' && /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      onClick: () => setRun(true)
    }, "\u5F00\u59CB\u53D1\u9001"), Number(p.left) > 0 && p.status === 'stopped' && /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      onClick: function () {
        act('resume');
      }
    }, "\u5148\u89E3\u9664\u6682\u505C"), Number(p.left) > 0 && run && /*#__PURE__*/React.createElement(Button, {
      size: "small",
      color: "warning",
      variant: "contained",
      onClick: function () {
        setRun(false);
        act('stop');
      }
    }, "\u6682\u505C"), Number(p.sending) > 0 && !run && /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: () => act('requeue', '把卡在「已认领」的 ' + p.sending + ' 封重新排回待发？\n\n它们可能已经发出去了，重排会再发一次。')
    }, "\u91CD\u6392\u5728\u98DE\u7684"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: load
    }, "\u5237\u65B0\u8FDB\u5EA6")));
  }

  /** 记录 + 逐封明细（整站唯一能看到完整收件地址的一页，服务端每次读都记一行日志） */
  function MailHistory({
    onPick,
    picked
  }) {
    const [rows, setRows] = useState(null);
    const [det, setDet] = useState(null);
    const [flt, setFlt] = useState('all'); // 「全部」不能拿空串当值：Select 对空串不出字，看着像个空框
    const [page, setPage] = useState(1);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/mail/blasts');
        if (j.code === 0) {
          setRows(j.data.list || []);
        } else {
          setRows([]);
        }
      } catch (e) {
        setRows([]);
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const loadDet = useCallback(async function (id, st, pg) {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/mail/blast?blast_id=' + id + '&status=' + (st === 'all' ? '' : st || '') + '&page=' + (pg || 1) + '&size=20');
        if (j.code === 0) {
          setDet(j.data);
        } else {
          toast(j.msg || '读不到明细', 'error');
          setDet(null);
        }
      } catch (e) {
        setDet(null);
      } finally {
        setBusy(false);
      }
    }, []);
    useEffect(function () {
      if (picked > 0) {
        loadDet(picked, flt, page);
      }
    }, [picked, flt, page, loadDet]);
    const cell = {
      fontSize: 12,
      whiteSpace: 'nowrap',
      px: 0.75,
      py: 0.5
    };
    const head = {
      fontSize: 11,
      whiteSpace: 'normal',
      lineHeight: '14px',
      minWidth: 30,
      px: 0.75,
      py: 0.5,
      fontWeight: 700
    };
    return /*#__PURE__*/React.createElement(Paper, {
      elevation: 1,
      sx: {
        p: {
          xs: 1.5,
          md: 2
        },
        mb: 1.5,
        borderRadius: '16px'
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u53D1\u9001\u8BB0\u5F55"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: function () {
        load();
        if (picked) {
          loadDet(picked, flt, page);
        }
      }
    }, "\u5237\u65B0")), rows === null && /*#__PURE__*/React.createElement(LinearProgress, {
      sx: {
        mb: 1
      }
    }), rows && rows.length === 0 && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        py: 0.5
      }
    }, "\u8FD8\u6CA1\u6709\u53D1\u8FC7\uFF1A\u4E0A\u9762\u5199\u597D\u6B63\u6587\u3001\u9884\u89C8\u8FC7\uFF0C\u518D\u70B9\u6B63\u5F0F\u53D1\u9001\u3002"), rows && rows.length > 0 && /*#__PURE__*/React.createElement(TableContainer, {
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, ['#', '标题', '发给', '计划', '已发出', '退回', '没地址', '站内信', '状态', '谁点的', '时间', ''].map(function (h) {
      return /*#__PURE__*/React.createElement(TableCell, {
        key: h,
        sx: head
      }, h);
    }))), /*#__PURE__*/React.createElement(TableBody, null, rows.map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.id,
        hover: true,
        selected: Number(picked) === Number(r.id)
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.id), /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          fontSize: 12,
          maxWidth: 170,
          overflow: 'hidden',
          textOverflow: 'ellipsis',
          px: 0.75,
          py: 0.5
        }
      }, r.subject), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.groupNames), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.target), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.n_sent), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.n_failed), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.n_skipped), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.n_notice), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, /*#__PURE__*/React.createElement(BlastStatusChip, {
        s: r.status
      })), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.actor), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, fmtTime(r.created_at)), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: function () {
          onPick(Number(r.id));
        }
      }, Number(picked) === Number(r.id) ? '收起明细' : '看明细')));
    })))), picked > 0 && det && /*#__PURE__*/React.createElement(Box, {
      sx: {
        pt: 1,
        borderTop: '1px solid ' + CARD_BORDER
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 1
      },
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u6279\u6B21 #", picked, " \u660E\u7EC6"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        minWidth: 130
      }
    }, /*#__PURE__*/React.createElement(Select, {
      size: "small",
      value: flt,
      onChange: e => {
        setFlt(e.target.value);
        setPage(1);
      },
      sx: {
        width: '100%'
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "all"
    }, "\u5168\u90E8"), Object.keys(MAIL_ITEM_STATUS).map(function (k) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: k,
        value: k
      }, MAIL_ITEM_STATUS[k]);
    }))), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY
      }
    }, "\u5171 ", det.total, " \u884C \xB7 \u7B2C ", det.page, " \u9875"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: busy || Number(det.page) <= 1,
      onClick: () => setPage(Number(det.page) - 1)
    }, "\u4E0A\u4E00\u9875"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: busy || Number(det.page) * Number(det.size) >= Number(det.total),
      onClick: () => setPage(Number(det.page) + 1)
    }, "\u4E0B\u4E00\u9875")), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        mb: 0.5
      }
    }, "\u539F\u6587\uFF08", Number(det.blast.is_html) === 1 ? '这封按 HTML 发' : '这封按纯文本发', "\uFF1B\u7AD9\u5185\u4FE1\u91CC\u7ED9\u6210\u5458\u770B\u7684\u662F\u5265\u6389\u6807\u7B7E\u7684\u53EF\u8BFB\u6587\u672C\uFF09"), /*#__PURE__*/React.createElement(Box, {
      component: "pre",
      sx: {
        m: 0,
        mb: 1,
        p: 1,
        borderRadius: '12px',
        bgcolor: '#fafafc',
        border: '1px solid ' + CARD_BORDER,
        fontSize: 12,
        lineHeight: '20px',
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
        maxHeight: 220,
        overflow: 'auto',
        fontFamily: 'inherit'
      }
    }, det.blast.body), /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, ['战队', '显示名', '收件地址', '身份', '结果', '站内信', '原因', '时间'].map(function (h) {
      return /*#__PURE__*/React.createElement(TableCell, {
        key: h,
        sx: head
      }, h);
    }))), /*#__PURE__*/React.createElement(TableBody, null, (det.list || []).map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.uid,
        hover: true
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.team), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.name), /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          fontSize: 12,
          wordBreak: 'break-all'
        }
      }, r.email || '（没有可用地址）'), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.who === 'owner' ? '站长/管理员' : '成员'), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, /*#__PURE__*/React.createElement(MailStatusChip, {
        s: r.status
      })), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, Number(r.notice) === 1 ? '已补' : '没补'), /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          fontSize: 11,
          color: '#8a2b22',
          maxWidth: 240,
          whiteSpace: 'normal',
          wordBreak: 'break-word'
        }
      }, r.err || ''), /*#__PURE__*/React.createElement(TableCell, {
        sx: cell
      }, r.sent_at ? fmtTime(r.sent_at) : ''));
    })))), (det.list || []).length === 0 && !busy && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        py: 1
      }
    }, "\u8FD9\u4E00\u6863\u73B0\u5728\u6CA1\u6709\u884C\u3002")));
  }
  function PlatformMailBlast() {
    const [cfg, setCfg] = useState(null);
    // banned=1 只在这一次算进被封禁站点（销毁前的告知用），默认关
    const [draft, setDraft] = useState({
      subject: '',
      body: '',
      groups: ['owner', 'member'],
      banned: 0
    });
    const [bid, setBid] = useState(0);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/mail/config');
        if (j.code === 0) {
          setCfg(j.data);
        } else {
          toast(j.msg || '读不到配置', 'error');
        }
      } catch (e) {
        toast('读不到配置', 'error');
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    if (!cfg) {
      return /*#__PURE__*/React.createElement(Paper, {
        elevation: 1,
        sx: {
          p: 2,
          borderRadius: '16px'
        }
      }, /*#__PURE__*/React.createElement(LinearProgress, null));
    }
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 1.5
      }
    }, "\u8FD9\u4E00\u9875\u53EA\u6709\u5E73\u53F0\u8D85\u7BA1\u80FD\u7528\uFF1A\u8FD0\u8425\u81EA\u5DF1\u5199\u6807\u9898\u4E0E\u6B63\u6587\uFF0C\u4E00\u6B21\u53D1\u7ED9\u5404\u6218\u961F\u7684\u7AD9\u957F\u4E0E\u7BA1\u7406\u5458\u3001\u6216\u5168\u90E8\u5DF2\u4E0A\u7EBF\u6210\u5458\u3002 \u53D1\u4FE1\u7528\u7684\u662F", /*#__PURE__*/React.createElement("b", null, "\u5E73\u53F0\u81EA\u5DF1\u914D\u7684\u90A3\u4E00\u5957\u90AE\u7BB1"), "\uFF0C\u4E0E\u5404\u6218\u961F\u7BA1\u7406\u4E2D\u5FC3\u91CC\u7684 smtp_* \u5404\u7BA1\u5404\u7684\uFF0C\u6218\u961F\u8BFB\u4E0D\u5230\u8FD9\u91CC\u7684\u51ED\u636E\u3002 \u6536\u4EF6\u5730\u5740\u5C31\u662F\u6CE8\u518C\u65F6\u90A3\u4E2A QQ\u53F7@qq.com\uFF08\u8FD9\u7248\u4E0D\u7ED9\u6210\u5458\u52A0\u300C\u81EA\u5DF1\u586B\u90AE\u7BB1\u300D\uFF09\uFF0C\u6CA1\u90AE\u7BB1\u7684\u4EBA\u6539\u7531", /*#__PURE__*/React.createElement("b", null, "\u7AD9\u5185\u4FE1"), "\u770B\u5230\u540C\u4E00\u4EFD\u901A\u77E5\u3002 \u6B63\u5F0F\u53D1\u9001\u524D\u5FC5\u987B\u5148\u70B9\u3010\u5148\u53D1\u4E00\u5C01\u7ED9\u6211\u770B\u3011\u2014\u2014 \u9884\u89C8\u7684\u5C31\u662F\u8FD9\u4E00\u4EFD\u6807\u9898+\u6B63\u6587\uFF0C\u6539\u4E00\u4E2A\u5B57\u95E8\u7981\u5C31\u4F5C\u5E9F\u3002 \u6807\u9898\u4E0E\u6B63\u6587\u91CC\u53EF\u4EE5\u5199 ", /*#__PURE__*/React.createElement("b", null, '{team} {name} {qq} {role} {date} {site} {slug}'), "\uFF0C\u53D1\u7684\u90A3\u4E00\u523B\u6309\u6BCF\u4E2A\u4EBA\u81EA\u5DF1\u66FF\u6362\uFF08\u9884\u89C8\u90A3\u5C01\u672B\u5C3E\u4F1A\u9644\u4E00\u6BB5\u6E32\u67D3\u540E\u7684\u6837\u5B50\uFF09\u3002"), !Number(cfg.enabled) && /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mb: 1.5
      }
    }, "\u7FA4\u53D1\u76EE\u524D\u662F", /*#__PURE__*/React.createElement("b", null, "\u5173\u95ED"), "\u7684\uFF1A\u73B0\u5728\u53EA\u80FD\u770B\u8BB0\u5F55\uFF0C\u9884\u89C8\u4E0E\u6B63\u5F0F\u53D1\u9001\u90FD\u4F1A\u88AB\u9000\u56DE\u3002\u8981\u5728\u4E0B\u9762\u90A3\u5F20\u5361\u91CC\u6253\u5F00\u3002"), /*#__PURE__*/React.createElement(MailConfig, {
      cfg: cfg,
      onSaved: load
    }), /*#__PURE__*/React.createElement(MailCompose, {
      cfg: cfg,
      draft: draft,
      setDraft: setDraft,
      onCreated: function (id) {
        setBid(id);
      }
    }), bid > 0 && /*#__PURE__*/React.createElement(MailQueue, {
      bid: bid,
      onRefresh: load
    }), /*#__PURE__*/React.createElement(MailHistory, {
      onPick: function (id) {
        setBid(function (b) {
          return b === id ? 0 : id;
        });
      },
      picked: bid
    }));
  }

  /**
   * 强制平台公告（v5.36.31）：写一条，全体已上线成员登录战队就弹，不点「我已知晓」走不掉。
   * 阅读计数：应读是「现在」还在册的人数（战队被封的不算），公告发出去之后新注册的人也进分母。
   */
  function PlatNotice() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const [draft, setDraft] = useState({
      id: 0,
      title: '',
      content: '',
      status: 1
    });
    const [detail, setDetail] = useState(null);
    const [askDel, setAskDel] = useState(null);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/announcements');
        if (j.code === 0) {
          setD(j.data);
        } else {
          toast(j.msg || '公告读不到', 'error');
        }
      } catch (e) {
        toast('公告读不到', 'error');
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const post = async function (url, body, fb) {
      if (busy) {
        return;
      }
      setBusy(true);
      try {
        const j = await api(url, {
          method: 'POST',
          body: body || {}
        });
        toast(j.msg || fb, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
        setBusy(false);
        return j;
      } catch (e) {
        toast('操作失败', 'error');
        setBusy(false);
        return null;
      }
    };
    const save = async function () {
      const j = await post('/api/platform/admin/announcement/save', {
        id: draft.id,
        title: draft.title,
        content: draft.content,
        status: draft.status
      }, '已保存');
      if (j && j.code === 0) {
        setDraft({
          id: 0,
          title: '',
          content: '',
          status: 1
        });
      }
    };
    const edit = function (r) {
      setDraft({
        id: r.id,
        title: r.title,
        content: r.content_full || '',
        status: Number(r.status)
      });
      if (!r.content_full) {
        api('/api/platform/admin/announcement?id=' + r.id).then(function (j) {
          if (j.code === 0) {
            setDraft({
              id: r.id,
              title: j.data.title,
              content: j.data.content,
              status: Number(j.data.status)
            });
          }
        }).catch(function () {/* 拿不到就留着列表里那句开头 */});
      }
    };
    const openDetail = async function (r) {
      try {
        const j = await api('/api/platform/admin/announcement?id=' + r.id);
        if (j.code === 0) {
          setDetail(j.data);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('取这一条失败', 'error');
      }
    };
    if (!d) {
      return /*#__PURE__*/React.createElement(Paper, {
        elevation: 1,
        sx: {
          p: 2,
          borderRadius: '16px'
        }
      }, /*#__PURE__*/React.createElement(LinearProgress, null));
    }
    return /*#__PURE__*/React.createElement(Box, null, !Number(d.available) && /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mb: 1.5
      }
    }, "\u8FD9\u4E00\u7248\u8981\u7684\u4E24\u5F20\u8868\u8FD8\u6CA1\u5EFA\uFF1A\u5728\u670D\u52A1\u5668\u4E0A\u6267\u884C ", /*#__PURE__*/React.createElement("b", null, "database/upgrade_v5.36.30_to_v5.36.31.sql"), "\uFF0C \u6216\u8005\u7528\u6D4F\u89C8\u5668\uFF08\u767B\u5F55\u7740\u5E73\u53F0\u8D85\u7BA1\u7684\u540C\u4E00\u4E2A\u6D4F\u89C8\u5668\uFF09\u6253\u5F00\u4E00\u6B21 ", /*#__PURE__*/React.createElement("b", null, "/notice_install_v53631.php"), " \u70B9\u90A3\u4E2A\u6309\u94AE\u3002 \u8865\u5B8C\u5237\u65B0\u8FD9\u4E00\u9875\u5C31\u80FD\u5199\u4E86\u3002"), /*#__PURE__*/React.createElement(Paper, {
      elevation: 1,
      sx: {
        p: {
          xs: 1.5,
          md: 2
        },
        mb: 1.5,
        borderRadius: '16px'
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700,
        color: TEXT_PRIMARY,
        mb: 1
      }
    }, "\u5199\u4E00\u6761\u5F3A\u5236\u516C\u544A"), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      fullWidth: true,
      label: "\u516C\u544A\u6807\u9898",
      value: draft.title,
      sx: {
        mb: 1
      },
      onChange: e => setDraft(Object.assign({}, draft, {
        title: e.target.value
      })),
      inputProps: {
        maxLength: d.title_max
      },
      helperText: draft.title.length + ' / ' + d.title_max + '　｜　弹窗最上面那一句'
    }), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      fullWidth: true,
      multiline: true,
      minRows: 5,
      maxRows: 16,
      label: "\u516C\u544A\u6B63\u6587",
      value: draft.content,
      onChange: e => setDraft(Object.assign({}, draft, {
        content: e.target.value
      })),
      inputProps: {
        maxLength: d.content_max
      },
      helperText: draft.content.length + ' / ' + d.content_max + '　｜　纯文本，换行原样显示，不认 HTML'
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mt: 1,
        flexWrap: 'wrap',
        useFlexGap: true
      }
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Checkbox, {
        size: "small",
        checked: Number(draft.status) === 1,
        onChange: function (e) {
          setDraft(Object.assign({}, draft, {
            status: e.target.checked ? 1 : 0
          }));
        }
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13
        }
      }, "\u4FDD\u5B58\u540E\u5C31\u5728\u7EBF\u5F39\u51FA")
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      disabled: busy || !draft.title.trim() || !draft.content.trim(),
      onClick: save
    }, draft.id > 0 ? '保存修改' : Number(draft.status) === 1 ? '发布并弹出' : '先存着不弹'), draft.id > 0 && /*#__PURE__*/React.createElement(Button, {
      size: "small",
      color: "inherit",
      disabled: busy,
      onClick: function () {
        setDraft({
          id: 0,
          title: '',
          content: '',
          status: 1
        });
      }
    }, "\u4E0D\u6539\u4E86"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY
      }
    }, draft.id > 0 ? '改标题正文算同一条，已读人数不清零' : '同时在线最多 ' + d.online_max + ' 条；成员一次只弹最久没处理的那一条'))), /*#__PURE__*/React.createElement(Paper, {
      elevation: 1,
      sx: {
        p: {
          xs: 1.5,
          md: 2
        },
        mb: 1.5,
        borderRadius: '16px'
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      sx: {
        gap: 1,
        flexWrap: 'wrap',
        useFlexGap: true
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u6210\u5458\u80FD\u4E0D\u80FD\u5173\u6389"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        size: "small",
        checked: Number(d.allow_mute) === 1,
        disabled: busy,
        onChange: function (e) {
          post('/api/platform/admin/announcement/mute', {
            allow: e.target.checked ? 1 : 0
          }, '已保存');
        }
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13
        }
      }, "\u5141\u8BB8\u300C\u4E0D\u518D\u63D0\u793A\u300D")
    })), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: TEXT_SECONDARY,
        mt: 0.5
      }
    }, "\u300C\u6211\u5DF2\u77E5\u6653\u300D\u53EA\u505C\u8FD9\u4E00\u6761\uFF0C\u300C\u4E0D\u518D\u63D0\u793A\u300D\u4EE5\u540E\u6240\u6709\u5F3A\u5236\u516C\u544A\u90FD\u4E0D\u5F39\u7ED9\u90A3\u4E2A\u4EBA\u3002 \u5F53\u524D\u6709 ", /*#__PURE__*/React.createElement("b", null, d.global_muted), " \u4EBA\u70B9\u4E86\u4E0D\u518D\u63D0\u793A", Number(d.allow_mute) === 1 ? '。' : '；关掉这一档之后，新的人点不了，已经点过的要他自己回来点才行。')), /*#__PURE__*/React.createElement(Paper, {
      elevation: 1,
      sx: {
        p: {
          xs: 1.5,
          md: 2
        },
        borderRadius: '16px'
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      sx: {
        gap: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u53D1\u8FC7\u7684\u516C\u544A"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      disabled: busy,
      onClick: load
    }, "\u5237\u65B0")), d.list.length === 0 && /*#__PURE__*/React.createElement(EmptyBox, {
      text: "\u8FD8\u6CA1\u5199\u8FC7\u5F3A\u5236\u516C\u544A"
    }), d.list.map(function (r) {
      return /*#__PURE__*/React.createElement(Box, {
        key: r.id,
        sx: {
          p: 1.25,
          mt: 1,
          borderRadius: '12px',
          border: '1px solid ' + CARD_BORDER
        }
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        alignItems: "center",
        sx: {
          gap: 1,
          flexWrap: 'wrap',
          useFlexGap: true
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13.5,
          fontWeight: 700,
          color: TEXT_PRIMARY,
          wordBreak: 'break-word'
        }
      }, r.title), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: Number(r.status) === 1 ? '在线弹出' : '已下线',
        color: Number(r.status) === 1 ? 'success' : 'default',
        variant: Number(r.status) === 1 ? 'filled' : 'outlined',
        sx: {
          height: 20,
          fontSize: 11
        }
      }), /*#__PURE__*/React.createElement(Box, {
        sx: {
          flex: '1 1 auto'
        }
      }), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 11.5,
          color: TEXT_SECONDARY
        }
      }, r.created_at, " \xB7 ", r.actor)), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12.5,
          lineHeight: '19px',
          color: TEXT_SECONDARY,
          mt: 0.5,
          wordBreak: 'break-word'
        }
      }, r.content_head, r.content_len > 80 ? '…（共 ' + r.content_len + ' 字）' : ''), /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.5,
        alignItems: "center",
        sx: {
          mt: 0.75,
          flexWrap: 'wrap',
          useFlexGap: true
        }
      }, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: '应读 ' + r.need,
        sx: {
          height: 20,
          fontSize: 11,
          bgcolor: BG_GRAY
        }
      }), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: '已读 ' + r.read + '（知晓 ' + r.ack + ' · 静音 ' + r.muted + '）',
        sx: {
          height: 20,
          fontSize: 11,
          bgcolor: r.read > 0 ? 'success.main' : BG_GRAY,
          color: r.read > 0 ? '#fff' : TEXT_PRIMARY
        }
      }), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: '未读 ' + r.unread,
        sx: {
          height: 20,
          fontSize: 11,
          bgcolor: r.unread > 0 ? '#fff3e0' : BG_GRAY,
          color: r.unread > 0 ? '#b25000' : TEXT_PRIMARY
        }
      }), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: r.rate + '%',
        sx: {
          height: 20,
          fontSize: 11,
          bgcolor: BG_GRAY
        }
      }), /*#__PURE__*/React.createElement(Box, {
        sx: {
          flex: '1 1 auto'
        }
      }), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: function () {
          openDetail(r);
        }
      }, "\u6309\u6218\u961F\u770B"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: function () {
          edit(r);
        }
      }, "\u6539"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        disabled: busy,
        onClick: function () {
          post('/api/platform/admin/announcement/status', {
            id: r.id,
            status: Number(r.status) === 1 ? 0 : 1
          }, '已保存');
        }
      }, Number(r.status) === 1 ? '下线' : '上线'), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        color: "error",
        disabled: busy,
        onClick: function () {
          setAskDel(r);
        }
      }, "\u5220")));
    }), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        lineHeight: '18px',
        color: TEXT_SECONDARY,
        mt: 1
      }
    }, "\u5E94\u8BFB\u4EBA\u6570\u6309\u300C\u73B0\u5728\u8FD8\u5728\u518C\u7684\u5DF2\u4E0A\u7EBF\u6210\u5458\u300D\u73B0\u7B97\uFF0C\u6218\u961F\u88AB\u5E73\u53F0\u5C01\u6389\u7684\u6574\u961F\u4E0D\u7B97\uFF1B\u516C\u544A\u53D1\u51FA\u53BB\u4E4B\u540E\u65B0\u6CE8\u518C\u7684\u4EBA\u4F1A\u8FDB\u5206\u6BCD\uFF0C \u6240\u4EE5\u8FD9\u4E2A\u6570\u4F1A\u968F\u65F6\u95F4\u53D8\u5927 \u2014\u2014 \u5F3A\u5236\u516C\u544A\u672C\u6765\u5C31\u8BE5\u8BA9\u6BCF\u4E2A\u6D3B\u7740\u7684\u4EBA\u770B\u5230\u3002")), /*#__PURE__*/React.createElement(Dialog, {
      open: !!detail,
      onClose: function () {
        setDetail(null);
      },
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, detail ? detail.title : ''), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, detail && /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        lineHeight: '20px',
        color: TEXT_PRIMARY,
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
        mb: 1
      }
    }, detail.content), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 0.5,
      sx: {
        flexWrap: 'wrap',
        useFlexGap: true,
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '应读 ' + detail.stats.need,
      sx: {
        bgcolor: BG_GRAY
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '已读 ' + detail.stats.read,
      sx: {
        bgcolor: 'success.main',
        color: '#fff'
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '未读 ' + detail.stats.unread,
      sx: {
        bgcolor: '#fff3e0',
        color: '#b25000'
      }
    })), /*#__PURE__*/React.createElement(TableContainer, {
      sx: {
        border: '1px solid ' + CARD_BORDER,
        borderRadius: '12px'
      }
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, {
      sx: {
        fontSize: 12
      }
    }, "\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right",
      sx: {
        fontSize: 12
      }
    }, "\u5E94\u8BFB"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right",
      sx: {
        fontSize: 12
      }
    }, "\u5DF2\u8BFB"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right",
      sx: {
        fontSize: 12
      }
    }, "\u672A\u8BFB"))), /*#__PURE__*/React.createElement(TableBody, null, detail.stats.by_team.map(function (t) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: t.tenant_id
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          fontSize: 12.5
        }
      }, t.name, /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 11,
          color: TEXT_SECONDARY,
          fontFamily: 'ui-monospace, Menlo, monospace'
        }
      }, t.slug)), /*#__PURE__*/React.createElement(TableCell, {
        align: "right",
        sx: {
          fontSize: 12.5
        }
      }, t.need), /*#__PURE__*/React.createElement(TableCell, {
        align: "right",
        sx: {
          fontSize: 12.5
        }
      }, t.read), /*#__PURE__*/React.createElement(TableCell, {
        align: "right",
        sx: {
          fontSize: 12.5,
          color: t.unread > 0 ? '#b25000' : TEXT_SECONDARY
        }
      }, t.unread));
    }), detail.stats.by_team.length === 0 && /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, {
      colSpan: 4,
      sx: {
        fontSize: 12.5,
        color: TEXT_SECONDARY,
        textAlign: 'center'
      }
    }, "\u73B0\u5728\u4E00\u4E2A\u5E94\u8BFB\u7684\u4EBA\u90FD\u6CA1\u6709\uFF08\u7AD9\u70B9\u4E0A\u90FD\u8FD8\u6CA1\u4EBA\uFF0C\u6216\u8005\u5168\u88AB\u5C01\u4E86\uFF09"))))), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        mt: 1
      }
    }, "\u6309\u672A\u8BFB\u4EBA\u6570\u591A\u7684\u6392\u5728\u4E0A\u9762\uFF0C\u8BE5\u53BB\u50AC\u8C01\u4E00\u773C\u770B\u51FA\u6765\u3002"))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setDetail(null);
      }
    }, "\u5173\u6389"))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!askDel,
      onClose: function () {
        setAskDel(null);
      },
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u5220\u6389\u8FD9\u6761\u516C\u544A\uFF1F"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        lineHeight: '20px'
      }
    }, "\u8FDE\u300C\u8C01\u70B9\u8FC7\u77E5\u6653\u300D\u4E00\u8D77\u5220\uFF0C\u5220\u4E86\u5C31\u5BF9\u4E0D\u4E0A\u8D26\u4E86\u3002\u53EA\u662F\u4E0D\u60F3\u518D\u5F39\u7684\u8BDD\uFF0C\u70B9\u300C\u4E0B\u7EBF\u300D\u5C31\u591F\u4E86 \u2014\u2014 \u4E0B\u7EBF\u4E0D\u5220\u8BB0\u5F55\uFF0C\u5DF2\u8BFB\u4EBA\u6570\u4E5F\u7559\u7740\u3002")), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setAskDel(null);
      }
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      color: "error",
      variant: "contained",
      disabled: busy,
      onClick: async function () {
        const r = askDel;
        setAskDel(null);
        await post('/api/platform/admin/announcement/delete', {
          id: r.id
        }, '已删除');
      }
    }, "\u786E\u8BA4\u5220\u9664"))));
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
        if (json.code === 0) {
          setStats(json.data);
        } else {
          toast(json.msg, 'error');
          setStats({
            list: [],
            tables: {}
          });
        }
      } catch (e) {
        setStats({
          list: [],
          tables: {}
        });
      } finally {
        setBusy(false);
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const post = async function (apply) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(before)) {
        toast('请填写截止日期（YYYY-MM-DD）', 'error');
        return;
      }
      if (apply && !window.confirm('确定清理吗？此操作不可恢复。\n\n站点：' + (tid > 0 ? 'ID ' + tid : '全部站点') + '\n类型：' + (type === 'all' ? '全部日志' : type) + '\n早于：' + before + (inspect ? '\n★ 已勾选连巡查审计一起删' : '\n（巡查审计会被保留）'))) return;
      setBusy(true);
      try {
        const json = await api('/api/platform/admin/log-cleanup', {
          method: 'POST',
          body: {
            tenant_id: tid,
            type: type,
            before: before,
            include_inspect: inspect ? 1 : 0,
            apply: apply ? 1 : 0
          }
        });
        if (json.code === 0) {
          setPlan(json.data.plan || null);
          if (apply) {
            toast(json.msg, 'success');
            setPlan(null);
            load();
          }
        } else {
          toast(json.msg, 'error');
          setPlan(null);
        }
      } catch (e) {
        toast('操作失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const tblKeys = stats ? Object.keys(stats.tables || {}) : [];
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 1.5
      }
    }, "\u6309\u622A\u6B62\u65E5\u671F\u6E05\u7406\u5404\u6218\u961F\u7684\u65E5\u5FD7\uFF08\u5220\u9664\u65E9\u4E8E\u8BE5\u65E5\u671F\u7684\u8BB0\u5F55\uFF09\u3002\u9ED8\u8BA4\u4FDD\u7559 v5.16.0 \u7684\u300C\u9690\u8EAB\u5DE1\u67E5\u300D\u5BA1\u8BA1\u884C\uFF0C \u90A3\u662F\u300C\u5E73\u53F0\u8BFB\u8FC7\u54EA\u4E9B\u7AD9\u70B9\u6570\u636E\u300D\u7684\u552F\u4E00\u51ED\u8BC1\uFF1B\u786E\u9700\u4E00\u8D77\u6E05\u518D\u52FE\u9009\u3002\u6E05\u7406\u52A8\u4F5C\u672C\u8EAB\u4F1A\u5199\u5165\u88AB\u6E05\u7406\u7AD9\u70B9\u7684\u64CD\u4F5C\u65E5\u5FD7\u3002"), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      sx: {
        mb: 1.5
      },
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        minWidth: 180
      }
    }, /*#__PURE__*/React.createElement(Select, {
      size: "small",
      value: tid,
      onChange: e => setTid(Number(e.target.value))
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: 0
    }, "\u5168\u90E8\u7AD9\u70B9"), (stats ? stats.list : []).map(function (x) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: x.tenant_id,
        value: x.tenant_id
      }, x.name);
    }))), /*#__PURE__*/React.createElement(Box, {
      sx: {
        minWidth: 160
      }
    }, /*#__PURE__*/React.createElement(Select, {
      size: "small",
      value: type,
      onChange: e => setType(e.target.value)
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "all"
    }, "\u5168\u90E8\u65E5\u5FD7\u7C7B\u578B"), tblKeys.map(function (k) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: k,
        value: k
      }, stats.tables[k]);
    }))), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      placeholder: "YYYY-MM-DD",
      value: before,
      onChange: e => setBefore(e.target.value),
      sx: {
        width: 150
      },
      inputProps: {
        maxLength: 10
      },
      helperText: "\u65E9\u4E8E\u6B64\u65E5\u671F"
    }), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Checkbox, {
        checked: inspect,
        onChange: e => setInspect(e.target.checked)
      }),
      label: "\u542B\u5DE1\u67E5\u5BA1\u8BA1"
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      disabled: busy,
      onClick: () => post(false)
    }, "\u9884\u89C8\u6761\u6570"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      color: "error",
      disabled: busy,
      onClick: () => post(true)
    }, "\u6267\u884C\u6E05\u7406"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: load
    }, "\u5237\u65B0\u7EDF\u8BA1")), plan && /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mb: 1.5
      }
    }, Object.keys(plan).map(function (k) {
      const p = plan[k];
      return /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        key: k
      }, stats.tables[k] || k, "\uFF1A\u5C06\u5220 ", p.total, " \u6761", p.inspect > 0 ? '（其中巡查审计 ' + p.inspect + ' 条，未勾选则保留）' : '', p.total > 0 ? ' · 涉及 ' + Object.keys(p.tenants).length + ' 个站点' : '');
    })), stats === null || busy && !stats.list ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)) : stats.list.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u7AD9\u70B9") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u7AD9\u70B9"), tblKeys.map(function (k) {
      return /*#__PURE__*/React.createElement(TableCell, {
        key: k
      }, stats.tables[k]);
    }), /*#__PURE__*/React.createElement(TableCell, null, "\u5408\u8BA1"), /*#__PURE__*/React.createElement(TableCell, null, "\u5DE1\u67E5\u5BA1\u8BA1"))), /*#__PURE__*/React.createElement(TableBody, null, stats.list.map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.tenant_id
      }, /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2"
      }, r.name), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "/", r.slug)), tblKeys.map(function (k) {
        const c = r.tables[k] || {
          n: 0,
          oldest: ''
        };
        return /*#__PURE__*/React.createElement(TableCell, {
          key: k
        }, /*#__PURE__*/React.createElement(Typography, {
          variant: "body2"
        }, c.n), c.oldest && /*#__PURE__*/React.createElement(Typography, {
          variant: "caption",
          color: "text.secondary"
        }, "\u6700\u65E9 ", fmtTime(c.oldest)));
      }), /*#__PURE__*/React.createElement(TableCell, null, r.total), /*#__PURE__*/React.createElement(TableCell, null, r.inspect > 0 ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: "warning",
        label: r.inspect + ' 条受保护'
      }) : /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u2014")));
    })))), stats && stats.platform_login_logs > 0 && /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mt: 1
      }
    }, "\u53E6\u6709\u5E73\u53F0\u8D26\u53F7\u767B\u5F55\u65E5\u5FD7 ", stats.platform_login_logs, " \u6761\uFF08\u5728\u300C\u767B\u5F55\u65E5\u5FD7\u300D\u9875\u7B7E\u4E0A\u534A\u6BB5\u67E5\u770B\uFF1B \u673A\u5668\u4EBA\u8C03\u7528\u65E5\u5FD7\u4E0E API \u65E5\u5FD7\u7684\u6574\u8868\u6E05\u7A7A\u4ECD\u5728\u5404\u81EA\u9875\u7B7E\uFF09"));
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
        if (j.code === 0) {
          setData(j.data);
          setFeatured(j.data.featured || []);
        } else toast(j.msg || '读取失败', 'error');
      } catch (e) {
        toast('读取失败', 'error');
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const post = async function (url, body, msg) {
      setBusy(true);
      try {
        const j = await api(url, {
          method: 'POST',
          body: body
        });
        if (j.code === 0) {
          toast(j.msg || msg, 'success');
          load();
          return j;
        }
        toast(j.msg || '操作失败', 'error');
      } catch (e) {
        toast('操作失败', 'error');
      } finally {
        setBusy(false);
      }
      return null;
    };
    if (!data) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    const open = data.list.filter(function (x) {
      return x.visible === 1;
    });
    const toggle = function (tid) {
      const has = featured.indexOf(tid) >= 0;
      if (!has && featured.length >= 3) {
        toast('焦点区最多 3 支', 'error');
        return;
      }
      setFeatured(has ? featured.filter(function (x) {
        return x !== tid;
      }) : featured.concat([tid]));
    };
    return /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6218\u961F\u5BF9\u5916\u5C55\u793A\u9875\uFF08/", '{', "\u76EE\u5F55", '}', "/show\uFF09\u7531\u5404\u81EA\u6218\u961F\u7F16\u8F91\u3002\u5E73\u53F0\u4FA7\u8FD9\u4E09\u4EF6\u4E8B\u4F18\u5148\u4E8E\u6218\u961F\u81EA\u5DF1\u7684\u8BBE\u7F6E\uFF1A \u5927\u5385\u603B\u5F00\u5173\u3001\u7126\u70B9\u533A\u7CBE\u9009\u3001\u4EE5\u53CA\"\u4E00\u952E\u4E0B\u67B6\"\u2014\u2014\u4E0B\u67B6\u540E\u5373\u4F7F\u961F\u957F\u90A3\u8FB9\u4ECD\u662F\u5F00\u542F\uFF0C\u8BBF\u5BA2\u4E5F\u6253\u4E0D\u5F00\uFF0C \u800C\u4E14\u8FD9\u6761\u4F1A\u5199\u8FDB\u90A3\u652F\u6218\u961F\u81EA\u5DF1\u7684\u64CD\u4F5C\u65E5\u5FD7\uFF0C\u4ED6\u4EEC\u67E5\u5F97\u5230\u662F\u8C01\u4E0B\u7684\u3002"), /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        md: 'row'
      },
      spacing: 2,
      alignItems: {
        md: 'center'
      }
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: data.hall_open === 1,
        disabled: busy,
        onChange: e => post('/api/platform/admin/showcase/hall', {
          enabled: e.target.checked ? 1 : 0
        }, '已切换')
      }),
      label: "\u5F00\u653E\u516C\u793A\u5927\u5385\uFF08/hall\uFF09"
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      onClick: () => window.open('/hall', '_blank')
    }, "\u6253\u5F00\u5927\u5385\u770B\u770B"), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '已开主页 ' + data.shown + ' / ' + data.total + ' 站'
    }))), /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      fontWeight: 700
    }, "\u5927\u5385\u7126\u70B9\u533A\uFF08\u6700\u591A 3 \u652F\uFF09"), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1
      }
    }, "\u53EA\u6709\u5DF2\u5F00\u542F\u5C55\u793A\u9875\u4E14\u6CA1\u88AB\u4E0B\u67B6\u7684\u7AD9\u70B9\u80FD\u88AB\u9009\u8FDB\u6765\u3002"), open.length === 0 && /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary"
    }, "\u5F53\u524D\u6CA1\u6709\u7AD9\u70B9\u5F00\u7740\u4E3B\u9875\u3002"), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      flexWrap: "wrap",
      useFlexGap: true
    }, open.map(function (x) {
      return /*#__PURE__*/React.createElement(FormControlLabel, {
        key: x.tid,
        control: /*#__PURE__*/React.createElement(Checkbox, {
          size: "small",
          checked: featured.indexOf(x.tid) >= 0,
          disabled: busy,
          onChange: () => toggle(x.tid)
        }),
        label: /*#__PURE__*/React.createElement(Typography, {
          variant: "body2"
        }, x.name)
      });
    })), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      sx: {
        mt: 1
      },
      disabled: busy,
      onClick: () => post('/api/platform/admin/showcase/featured', {
        tenant_ids: featured.join(',')
      }, '焦点区已保存')
    }, "\u4FDD\u5B58\u7126\u70B9\u533A")), /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, null, "\u76EE\u5F55"), /*#__PURE__*/React.createElement(TableCell, null, "\u7AD9\u70B9\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, {
      align: "center"
    }, "\u6218\u961F\u5F00\u542F"), /*#__PURE__*/React.createElement(TableCell, {
      align: "center"
    }, "\u5E73\u53F0\u4E0B\u67B6"), /*#__PURE__*/React.createElement(TableCell, {
      align: "center"
    }, "\u8BBF\u5BA2\u53EF\u89C1"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right"
    }, "\u6210\u5458"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right"
    }, "\u5F85\u5904\u7406\u6295\u9012"), /*#__PURE__*/React.createElement(TableCell, null, "\u4E3B\u9875"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right"
    }, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, data.list.map(function (x) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: x.tid
      }, /*#__PURE__*/React.createElement(TableCell, null, x.name), /*#__PURE__*/React.createElement(TableCell, null, "/", x.slug, "/"), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: x.site_status,
        color: x.site_status === 'approved' ? 'success' : 'default'
      })), /*#__PURE__*/React.createElement(TableCell, {
        align: "center"
      }, x.team_open === 1 ? '已开' : '未开'), /*#__PURE__*/React.createElement(TableCell, {
        align: "center"
      }, x.force_off === 1 ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "error",
        label: "\u5DF2\u4E0B\u67B6"
      }) : '—'), /*#__PURE__*/React.createElement(TableCell, {
        align: "center"
      }, x.visible === 1 ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "success",
        label: "\u53EF\u8BBF\u95EE"
      }) : '不可访问'), /*#__PURE__*/React.createElement(TableCell, {
        align: "right"
      }, x.members), /*#__PURE__*/React.createElement(TableCell, {
        align: "right"
      }, x.pending > 0 ? x.pending : '—'), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Link, {
        href: x.show_url,
        target: "_blank",
        rel: "noopener"
      }, x.show_url)), /*#__PURE__*/React.createElement(TableCell, {
        align: "right"
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        disabled: busy,
        onClick: () => post('/api/platform/admin/showcase/force', {
          tenant_id: x.tid,
          off: x.force_off === 1 ? 0 : 1
        }, x.force_off === 1 ? '已恢复' : '已下架')
      }, x.force_off === 1 ? '恢复上架' : '一键下架')));
    })))));
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
        if (j.code === 0) {
          setD(j.data);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('取论坛对接状态失败', 'error');
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const setMaster = async function (on) {
      setBusy('master');
      try {
        const j = await api('/api/platform/admin/forum-api/master', {
          method: 'POST',
          body: {
            on: on ? '1' : '0'
          }
        });
        toast(j.msg, j.code === 0 ? 'success' : 'error');
        // 关总闸改的是「每一队还读不读得到」，只把开关那格抹掉不够：浏览器里量到过开关写着「已停用全站」、
        // 下面汇总与每行还挂着「正在给读数：1」，那就是自相矛盾。重取一次让两者同一个口径。
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
      setBusy('');
    };
    const setOne = async function (t, off) {
      setBusy('t' + t.id);
      try {
        const j = await api('/api/platform/admin/forum-api/toggle', {
          method: 'POST',
          body: {
            tenant_id: t.id,
            off: off ? '1' : '0'
          }
        });
        toast(j.msg, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
      setBusy('');
    };

    // 正在给读数的排前面：出事的时候要一眼看到那几队，而不是从列表第一行开始找
    const rows = (d && d.list ? d.list.slice() : []).sort(function (a, b) {
      if (a.serving !== b.serving) {
        return Number(b.serving) - Number(a.serving);
      }
      return Number(b.tenant_on) - Number(a.tenant_on) || a.id - b.id;
    });
    return /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        }
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u793E\u533A\u8BBA\u575B\u5BF9\u63A5"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: '#6e6e73',
        mt: 0.5,
        mb: 1.25
      }
    }, "\u5404\u6218\u961F\u628A\u672C\u961F\u6570\u636E\u53EA\u8BFB\u4EA4\u7ED9\u81EA\u5DF1\u90A3\u4E2A\u793E\u533A\u8BBA\u575B\uFF1B\u8FD9\u4E00\u9875\u7BA1\u7684\u662F\u8981\u4E0D\u8981\u8BA9\u5B83\u51FA\u7AD9\u3002"), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      sx: {
        gap: 1.5,
        flexWrap: 'wrap',
        useFlexGap: true,
        mb: 1.25
      }
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: !!(d && d.master_on),
        disabled: busy === 'master',
        onChange: function (e) {
          setMaster(e.target.checked);
        }
      }),
      label: d && d.master_on ? '全站可用' : '已停用全站'
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '正在给读数：' + (d ? d.serving : '…'),
      sx: {
        bgcolor: BG_GRAY
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '已开通站点：' + (d ? d.listed : '…'),
      sx: {
        bgcolor: BG_GRAY
      }
    })), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: TEXT_SECONDARY,
        mb: 1
      }
    }, "\u5173\u6389\u603B\u95F8\u662F\u6240\u6709\u961F\u7ACB\u523B\u8BFB\u4E0D\u5230\uFF08\u6218\u961F\u90A3\u8FB9\u4F1A\u770B\u5230\u300C\u5E73\u53F0\u5DF2\u505C\u7528\u300D\u8FD9\u53E5\u8BDD\uFF0C\u4E0D\u662F\u5BF9\u7740 403 \u731C\uFF09\uFF1B \u4E0B\u9762\u6BCF\u4E00\u884C\u53EA\u505C\u90A3\u4E00\u961F\u3002\u4E24\u79CD\u505C\u6CD5\u90FD\u4E0D\u6539\u961F\u957F\u81EA\u5DF1\u90A3\u4E00\u683C\u7684\u8BBE\u7F6E\u3002"), !d && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        color: TEXT_SECONDARY,
        py: 2
      }
    }, "\u6B63\u5728\u53D6\u2026"), d && rows.length === 0 && /*#__PURE__*/React.createElement(EmptyBox, {
      text: "\u8FD8\u6CA1\u6709\u5DF2\u5F00\u901A\u7684\u7AD9\u70B9"
    }), d && /*#__PURE__*/React.createElement(Stack, {
      spacing: 0.75
    }, rows.map(function (t) {
      return /*#__PURE__*/React.createElement(Box, {
        key: t.id,
        sx: {
          p: 1.25,
          borderRadius: 2,
          border: '1px solid ' + CARD_BORDER
        }
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        alignItems: "center",
        sx: {
          gap: 1,
          flexWrap: 'wrap',
          useFlexGap: true
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13.5,
          fontWeight: 700,
          color: TEXT_PRIMARY
        }
      }, t.name), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12,
          color: TEXT_SECONDARY,
          fontFamily: 'ui-monospace, Menlo, monospace'
        }
      }, t.slug), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: Number(t.serving) === 1 ? '正在给读数' : Number(t.force_off) === 1 ? '平台已停用' : Number(t.tenant_on) === 1 ? '本队开着但总闸关着' : '本队没打开',
        sx: {
          height: 20,
          fontSize: 11,
          bgcolor: BG_GRAY
        }
      }), /*#__PURE__*/React.createElement(Box, {
        sx: {
          flex: '1 1 auto'
        }
      }), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12,
          color: TEXT_SECONDARY
        }
      }, Number(t.calls_24h) > 0 ? '近 24 小时调用 ' + t.calls_24h + ' 次 · 最近 ' + t.last_call : '近 24 小时没人调用'), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: Number(t.force_off) === 1 ? 'contained' : 'outlined',
        disabled: busy === 't' + t.id,
        onClick: function () {
          setOne(t, Number(t.force_off) !== 1);
        }
      }, Number(t.force_off) === 1 ? '放出来' : '停用这一队')));
    })));
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
        if (j.code === 0) {
          setD(j.data);
        } else {
          toast(j.msg, 'error');
        }
      } catch (e) {
        toast('取站点活跃失败', 'error');
      }
      setBusy(false);
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const label = function (t) {
      if (!t.last_at) {
        return t.members > 0 ? '还没人用过' : '还没有成员';
      }
      if (t.today > 0) {
        return '今天有动静';
      }
      if (t.d7 > 0) {
        return '7 天内有动静';
      }
      if (t.d30 > 0) {
        return '30 天内有动静';
      }
      return '一个月没动静';
    };
    const ago = function (t) {
      if (!t.last_at) {
        return '还没人用过';
      }
      if (!t.idle_days) {
        return '今天有人用过 · ' + t.last_at;
      }
      return t.idle_days + ' 天前有人用过 · ' + t.last_at;
    };
    return /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        }
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      sx: {
        gap: 1,
        flexWrap: 'wrap',
        useFlexGap: true
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u7AD9\u70B9\u6D3B\u8DC3"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      disabled: busy,
      onClick: load
    }, "\u5237\u65B0")), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: TEXT_SECONDARY,
        mt: 0.5
      }
    }, "\u54EA\u4E2A\u6218\u961F\u8FD8\u5728\u771F\u7684\u7528\u8FD9\u4E2A\u7AD9\u70B9\uFF1A\u6309\u8D26\u53F7\u6700\u540E\u4E00\u6B21\u5E26\u767B\u5F55\u6001\u8BF7\u6C42\u7684\u65F6\u95F4\u7B97\uFF0C\u4ECA\u65E5 / 7 \u5929 / 30 \u5929\u4E09\u4E2A\u6570\u3002 \u53EA\u7ED9\u6BCF\u961F\u7684\u6570\u5B57\uFF0C\u4E0D\u5217\u6210\u5458\u6635\u79F0\u3002"), !d && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        color: TEXT_SECONDARY,
        py: 2
      }
    }, "\u6B63\u5728\u53D6\u2026"), d && /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      alignItems: "center",
      sx: {
        gap: 1,
        flexWrap: 'wrap',
        useFlexGap: true,
        my: 1.25
      }
    }, /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '今天有动静：' + d.live.today + ' 队',
      sx: {
        bgcolor: d.live.today > 0 ? 'success.main' : BG_GRAY,
        color: d.live.today > 0 ? '#fff' : TEXT_PRIMARY
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '7 天内有：' + d.live.d7 + ' 队',
      sx: {
        bgcolor: BG_GRAY
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '30 天内有：' + d.live.d30 + ' 队',
      sx: {
        bgcolor: BG_GRAY
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '站点共 ' + d.sites + ' 个',
      sx: {
        bgcolor: BG_GRAY
      }
    })), d && d.list.length === 0 && /*#__PURE__*/React.createElement(EmptyBox, {
      text: "\u8FD8\u6CA1\u6709\u5DF2\u5F00\u901A\u7684\u7AD9\u70B9"
    }), d && d.list.map(function (t) {
      return /*#__PURE__*/React.createElement(Box, {
        key: t.id,
        sx: {
          p: 1.25,
          mb: 0.75,
          borderRadius: 2,
          border: '1px solid ' + CARD_BORDER
        }
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        alignItems: "center",
        sx: {
          gap: 1,
          flexWrap: 'wrap',
          useFlexGap: true
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13.5,
          fontWeight: 700,
          color: TEXT_PRIMARY
        }
      }, t.name), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12,
          color: TEXT_SECONDARY,
          fontFamily: 'ui-monospace, Menlo, monospace'
        }
      }, t.slug), /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: label(t),
        sx: {
          height: 20,
          fontSize: 11,
          bgcolor: t.today > 0 ? 'success.main' : BG_GRAY,
          color: t.today > 0 ? '#fff' : TEXT_PRIMARY
        }
      }), /*#__PURE__*/React.createElement(Box, {
        sx: {
          flex: '1 1 auto'
        }
      }), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12,
          color: TEXT_SECONDARY
        }
      }, "\u6210\u5458 ", t.members, " \u4EBA")), /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        alignItems: "baseline",
        sx: {
          gap: 1.25,
          flexWrap: 'wrap',
          useFlexGap: true,
          mt: 0.5
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12.5,
          color: TEXT_PRIMARY
        }
      }, "\u4ECA\u5929 ", /*#__PURE__*/React.createElement("b", null, t.today), " \u4EBA \xB7 7 \u5929 ", /*#__PURE__*/React.createElement("b", null, t.d7), " \u4EBA \xB7 30 \u5929 ", /*#__PURE__*/React.createElement("b", null, t.d30), " \u4EBA"), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12,
          color: TEXT_SECONDARY
        }
      }, ago(t))));
    }), d && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        lineHeight: '18px',
        color: TEXT_SECONDARY,
        mt: 1
      }
    }, d.note));
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
        if (j.code === 0) {
          setD(j.data);
        } else {
          toast(j.msg || '提醒设置读不到', 'error');
        }
      } catch (e) {
        toast('提醒设置读不到', 'error');
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const post = async function (url, body, fb) {
      if (busy) {
        return;
      }
      setBusy(true);
      try {
        const j = await api(url, {
          method: 'POST',
          body: body || {}
        });
        toast(j.msg || fb, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
      setBusy(false);
    };
    return /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        }
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u8D5B\u524D\u63D0\u9192"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: '#6e6e73',
        mt: 0.5
      }
    }, "\u5404\u6218\u961F\u7684\u8BAD\u7EC3\u8D5B\u3001\u961F\u5185\u65E5\u7A0B\u3001\u8DE8\u961F\u7EA6\u6218 \u5230\u5F00\u6253\u524D\u4F1A\u81EA\u52A8\u7ED9\u672C\u961F\u53D1\u4E00\u6761\u516C\u544A\uFF0C\u62A5\u4E86\u540D\u7684\u4EBA\u518D\u5404\u6536\u5230\u4E00\u6761\u901A\u77E5\u3002 \u63D0\u524D\u51E0\u5206\u949F\u7531\u5404\u6218\u961F\u961F\u957F\u81EA\u5DF1\u9009\uFF0C\u8FD9\u91CC\u8FD9\u4E00\u628A\u662F\u5168\u7AD9\u603B\u95F8\u3002"), !d && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        color: '#6e6e73',
        py: 2
      }
    }, "\u6B63\u5728\u53D6\u2026"), d && /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 1.5
      }
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: Number(d.enabled) === 1,
        disabled: busy,
        onChange: function (e) {
          post('/api/platform/admin/remind/config', {
            enabled: e.target.checked ? 1 : 0
          }, '已保存');
        }
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13.5,
          color: TEXT_PRIMARY
        }
      }, Number(d.enabled) === 1 ? '正在推（所有队照自己设的提前量推）' : '已停用（一条都不推，队长设的提前量保留）')
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mt: 1.5,
        flexWrap: 'wrap',
        useFlexGap: true
      }
    }, /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '已上线站点 ' + d.tenants + ' 个',
      sx: {
        bgcolor: BG_GRAY,
        fontSize: 12
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '今天推出去 ' + d.sent_today + ' 条',
      sx: {
        bgcolor: BG_GRAY,
        fontSize: 12
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '默认提前 ' + d.default_lead + ' 分钟',
      sx: {
        bgcolor: BG_GRAY,
        fontSize: 12
      }
    })), /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 1.25
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        fontWeight: 600,
        color: TEXT_PRIMARY,
        mb: 0.5
      }
    }, "\u6709\u591A\u5C11\u961F\u628A\u54EA\u4E00\u7C7B\u5173\u4E86"), Object.keys(d.closed).map(function (k) {
      return /*#__PURE__*/React.createElement(Typography, {
        key: k,
        sx: {
          fontSize: 12.5,
          lineHeight: '20px',
          color: '#6e6e73'
        }
      }, d.closed[k].name, "\uFF1A", Number(d.closed[k].off) === 0 ? '没有队关掉' : d.closed[k].off + ' 个队选了不提醒');
    }), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        lineHeight: '18px',
        color: '#6e6e73',
        mt: 0.5
      }
    }, "\u6CA1\u5728\u8FD9\u4EFD\u540D\u5355\u91CC\u7684\u961F\u8D70\u9ED8\u8BA4 30 \u5206\u949F\uFF1B\u5B83\u4EEC\u6CA1\u5199\u8FC7\u8BBE\u7F6E\uFF0C\u6240\u4EE5\u5E93\u91CC\u67E5\u4E0D\u5230\u8FD9\u4E00\u884C\u3002")), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      sx: {
        mt: 1.5
      },
      disabled: busy,
      onClick: function () {
        post('/api/platform/admin/remind/tick', {}, '已检查');
      }
    }, "\u5168\u7AD9\u70B9\u68C0\u67E5\u4E00\u6B21"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        lineHeight: '18px',
        color: '#6e6e73',
        mt: 0.5
      }
    }, "\u53EA\u63A8\u5230\u70B9\u7684\u90A3\u4E9B\uFF0C\u5DF2\u7ECF\u63A8\u8FC7\u7684\u4E0D\u4F1A\u91CD\u590D\u63A8\u3002\u6210\u5458\u5E73\u65F6\u6253\u5F00\u7AD9\u70B9\u65F6\u4E5F\u4F1A\u987A\u8DEF\u67E5\u4E00\u6B21\uFF08\u4E00\u961F\u4E00\u5206\u949F\u6700\u591A\u4E00\u6B21\uFF09\uFF0C \u6240\u4EE5\u4E0D\u914D\u8BA1\u5212\u4EFB\u52A1\u7167\u6837\u80FD\u7528\uFF1B\u8981\u66F4\u51C6\u65F6\uFF0C\u5C31\u5728\u8BA1\u5212\u4EFB\u52A1\u91CC\u6BCF\u5206\u949F\u6253\u4E00\u6B21 POST /api/open/remind/tick\uFF08\u5E26\u5E73\u53F0\u4F5C\u7528\u57DF\u7684 X-Api-Key\uFF09\u3002"), /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mt: 1.5,
        fontSize: 12.5
      }
    }, d.note)));
  }
  function PlatChatGov() {
    const [view, setView] = useState('dm'); // dm | mutes
    const [busy, setBusy] = useState(false);
    const [dm, setDm] = useState(null);
    const [dmStatus, setDmStatus] = useState('pending');
    const [dmNote, setDmNote] = useState({});
    const [open, setOpen] = useState({}); // 举报 id => 展开的那段私聊正文
    const [ask, setAsk] = useState(null); // {mode:'mute', id, name, reason}
    const [mutes, setMutes] = useState(null);
    const loadDm = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/chat/reports?status=' + dmStatus + '&size=20');
        if (j.code === 0) setDm(j.data);else toast(j.msg || '私聊举报读不到', 'error');
      } catch (e) {
        toast('私聊举报读不到', 'error');
      }
    }, [dmStatus]);
    const loadMutes = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/mutes');
        if (j.code === 0) setMutes(j.data);else toast(j.msg || '禁言名单读不到', 'error');
      } catch (e) {
        toast('禁言名单读不到', 'error');
      }
    }, []);
    useEffect(function () {
      loadDm();
    }, [loadDm]);
    useEffect(function () {
      loadMutes();
    }, [loadMutes]);
    const reloadAll = function () {
      loadDm();
      loadMutes();
      // 侧栏那颗红点算的是同一份数，这一页结完案要让它一起重取
      try {
        window.dispatchEvent(new Event('yys-gov-badge'));
      } catch (e) {/* 广播失败不影响本页 */}
    };
    const post = async function (url, body, msg) {
      if (busy) {
        return null;
      }
      setBusy(true);
      try {
        const j = await api(url, {
          method: 'POST',
          body: body
        });
        if (j.code === 0) {
          toast(j.msg || msg, 'success');
          reloadAll();
          return j;
        }
        toast(j.msg || '操作失败', 'error');
      } catch (e) {
        toast('操作失败', 'error');
      } finally {
        setBusy(false);
      }
      return null;
    };
    const openThread = async function (r) {
      if (open[r.id]) {
        const off = Object.assign({}, open);
        delete off[r.id];
        setOpen(off);
        return;
      }
      if (busy) {
        return;
      }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/chat/report/open', {
          method: 'POST',
          body: {
            id: r.id
          }
        });
        if (j.code === 0) {
          const next = Object.assign({}, open);
          next[r.id] = j.data;
          setOpen(next);
          toast(j.msg || '已展开这段对话', 'success');
          // 展开会把这条从「待处理」挪到「已展开待结案」，筛选档跟着挪，刚展开的那段才不会被筛掉
          if (dmStatus === 'pending') {
            setDmStatus('opened');
          } else {
            loadDm();
          }
        } else {
          toast(j.msg || '展开失败', 'error');
        }
      } catch (e) {
        toast('展开失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const submitAsk = async function () {
      if (!ask) {
        return;
      }
      if (!ask.reason.trim()) {
        toast('理由不能空着', 'error');
        return;
      }
      const j = await post('/api/platform/admin/mute', {
        user_id: ask.id,
        reason: ask.reason.trim()
      }, '已禁言');
      if (j) setAsk(null);
    };
    const DM_STATUS = {
      pending: '待处理',
      opened: '已展开待结案',
      handled: '已处理',
      dismissed: '不成立'
    };
    const dmCount = dm ? (dm.counts && dm.counts.pending || 0) + (dm.counts && dm.counts.opened || 0) : 0;
    const muteCount = mutes ? Number(mutes.total || 0) : 0;

    // 当事人当前被谁禁着：平台这条给「解除」，本队那条只标出来（这一页解不了，也不该解）
    const muteTag = function (p) {
      if (!p || Number(p.muted) !== 1) {
        return null;
      }
      const mine = p.mby === 'platform';
      return /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: mine ? 'warning' : 'default',
        label: mine ? '平台已禁言' : '本队已禁言',
        sx: {
          height: 18,
          fontSize: 11
        }
      });
    };
    return /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1.5,
      alignItems: "center",
      sx: {
        flexWrap: 'wrap',
        gap: 1.5
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700
      }
    }, "\u79C1\u804A\xB7\u5E73\u53F0\u6CBB\u7406"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 0.75,
      sx: {
        flexWrap: 'wrap',
        gap: 0.75
      }
    }, /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      color: dmCount ? 'warning' : 'default',
      label: '私聊举报 ' + dmCount,
      sx: {
        height: 22,
        fontSize: 11
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      color: muteCount ? 'warning' : 'default',
      label: '平台禁言 ' + muteCount,
      sx: {
        height: 22,
        fontSize: 11
      }
    }))), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mt: 1
      }
    }, "\u79C1\u804A\u7684\u4E3E\u62A5\u4E0E\u7981\u8A00\u90FD\u5728\u8FD9\u4E00\u9875\u5904\u7406\uFF0C\u6218\u961F\u961F\u957F\u90A3\u8FB9\u53EA\u5269\u300C\u672C\u7AD9\u8981\u4E0D\u8981\u5F00\u79C1\u804A\u300D\u8FD9\u4E00\u4E2A\u5F00\u5173\u3002 \u6B63\u6587\u53EA\u6709\u987A\u7740\u5F53\u4E8B\u4EBA\u7684\u4E00\u6761\u4E3E\u62A5\u624D\u8BFB\u5F97\u5230\uFF0C\u6BCF\u8BFB\u4E00\u6B21\u90FD\u8BB0\u8FDB\u5E73\u53F0\u64CD\u4F5C\u65E5\u5FD7\uFF1B\u5E73\u53F0\u7ED9\u7684\u7981\u8A00\u672C\u961F\u961F\u957F\u89E3\u4E0D\u6389\u3002")), /*#__PURE__*/React.createElement(Tabs, {
      value: view,
      onChange: (e, v) => setView(v),
      variant: "scrollable",
      scrollButtons: "auto",
      sx: {
        borderBottom: 1,
        borderColor: 'divider'
      }
    }, /*#__PURE__*/React.createElement(Tab, {
      value: "dm",
      label: '私聊举报' + (dmCount ? '（' + dmCount + '）' : '')
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "mutes",
      label: '平台禁言' + (muteCount ? '（' + muteCount + '）' : '')
    })), view === 'dm' && /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      flexWrap: "wrap",
      useFlexGap: true,
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700
      }
    }, "\u79C1\u804A\u4E3E\u62A5"), /*#__PURE__*/React.createElement(Select, {
      size: "small",
      value: dmStatus,
      onChange: e => setDmStatus(e.target.value),
      sx: {
        minWidth: 130
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "pending"
    }, "\u5F85\u5904\u7406"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "opened"
    }, "\u5DF2\u5C55\u5F00\u5F85\u7ED3\u6848"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "handled"
    }, "\u5DF2\u5904\u7406"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "dismissed"
    }, "\u4E0D\u6210\u7ACB"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "all"
    }, "\u5168\u90E8")), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: loadDm
    }, "\u5237\u65B0")), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1
      }
    }, dm && dm.notice ? dm.notice : '私聊正文只有顺着一条举报才读得到，每次读取都会往平台操作日志记一行。'), !dm && /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary"
    }, "\u6B63\u5728\u53D6\u2026"), dm && dm.list.length === 0 && /*#__PURE__*/React.createElement(Alert, {
      severity: "success",
      sx: {
        borderRadius: '10px'
      }
    }, "\u8FD9\u4E00\u6863\u6CA1\u6709\u79C1\u804A\u4E3E\u62A5\u3002"), dm && dm.list.map(function (r) {
      const th = open[r.id];
      return /*#__PURE__*/React.createElement(Box, {
        key: r.id,
        sx: {
          py: 1,
          borderTop: '1px solid rgba(0,0,0,.08)'
        }
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 1,
        alignItems: "center",
        flexWrap: "wrap",
        useFlexGap: true
      }, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: r.status === 'pending' || r.status === 'opened' ? 'warning' : 'default',
        label: DM_STATUS[r.status] || r.status,
        sx: {
          height: 20,
          fontSize: 11
        }
      }), r.cross_team === 1 && /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: "\u8DE8\u6218\u961F",
        sx: {
          height: 18,
          fontSize: 11
        }
      }), muteTag(r.peer), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 12
        }
      }, r.reporter && r.reporter.name || '已注销', "\uFF08", r.reporter && r.reporter.team || '—', "\uFF09 \u4E3E\u62A5", r.peer && r.peer.name || '已注销', "\uFF08", r.peer && r.peer.team || '—', "\uFF09"), /*#__PURE__*/React.createElement(Box, {
        sx: {
          flex: '1 1 auto'
        }
      }), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, r.created_at)), /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        sx: {
          mt: 0.5,
          wordBreak: 'break-word'
        }
      }, "\u4E3E\u62A5\u7406\u7531\uFF1A", r.reason), r.msg_alive === 0 && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "warning.main",
        sx: {
          display: 'block'
        }
      }, "\u88AB\u4E3E\u62A5\u7684\u90A3\u6761\u6D88\u606F\u5DF2\u7ECF\u4E0D\u5728\u4E86\uFF0C\u4E0B\u9762\u5C55\u5F00\u7684\u662F\u8FD9\u6BB5\u4F1A\u8BDD\u5269\u4E0B\u7684\u90E8\u5206\u3002"), /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 1,
        alignItems: "center",
        flexWrap: "wrap",
        useFlexGap: true,
        sx: {
          mt: 1
        }
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: () => openThread(r)
      }, th ? '收起这段对话' : '展开正文'), th && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u5171 ", th.list.length, " \u6761\uFF08\u6700\u591A\u53D6\u6700\u8FD1 100 \u6761\uFF09")), th && /*#__PURE__*/React.createElement(Box, {
        sx: {
          mt: 1,
          p: 1.5,
          borderRadius: '10px',
          bgcolor: 'rgba(0,0,0,.03)',
          maxHeight: 280,
          overflowY: 'auto'
        }
      }, th.list.length === 0 && /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        color: "text.secondary"
      }, "\u8FD9\u6BB5\u4F1A\u8BDD\u91CC\u6CA1\u6709\u53EF\u8BFB\u7684\u6D88\u606F\u3002"), th.list.map(function (m) {
        return /*#__PURE__*/React.createElement(Box, {
          key: m.id,
          sx: {
            py: 0.5,
            px: m.reported === 1 ? 1 : 0,
            mb: 0.25,
            borderRadius: '8px',
            bgcolor: m.reported === 1 ? 'rgba(255,149,0,.16)' : 'transparent'
          }
        }, /*#__PURE__*/React.createElement(Typography, {
          variant: "caption",
          color: "text.secondary"
        }, m.created_at, " \xB7 ", m.from_name, m.from_team ? '（' + m.from_team + '）' : '', m.reported === 1 ? ' · 被举报的那条' : ''), /*#__PURE__*/React.createElement(Typography, {
          sx: {
            fontSize: 13,
            wordBreak: 'break-word'
          }
        }, m.recalled === 1 ? /*#__PURE__*/React.createElement("em", null, "\uFF08\u8FD9\u6761\u5DF2\u88AB\u53D1\u9001\u65B9\u64A4\u56DE\uFF09") : m.content));
      })), (r.status === 'pending' || r.status === 'opened') && /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 1,
        alignItems: "center",
        flexWrap: "wrap",
        useFlexGap: true,
        sx: {
          mt: 1
        }
      }, /*#__PURE__*/React.createElement(TextField, {
        size: "small",
        placeholder: "\u7ED3\u679C\u8BF4\u660E\uFF08\u4F1A\u56DE\u7ED9\u4E3E\u62A5\u4EBA\uFF09",
        value: dmNote[r.id] || '',
        onChange: e => setDmNote(Object.assign({}, dmNote, {
          [r.id]: e.target.value
        })),
        inputProps: {
          maxLength: 200
        },
        sx: {
          flex: '1 1 220px',
          maxWidth: 360
        }
      }), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: "error",
        disabled: busy,
        onClick: () => post('/api/platform/admin/chat/report/handle', {
          id: r.id,
          action: 'handled',
          note: (dmNote[r.id] || '').trim()
        }, '已结案')
      }, "\u5DF2\u5904\u7406"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: () => post('/api/platform/admin/chat/report/handle', {
          id: r.id,
          action: 'dismissed',
          note: (dmNote[r.id] || '').trim()
        }, '已驳回')
      }, "\u4E0D\u6210\u7ACB"), r.peer && Number(r.peer.muted) === 1 && r.peer.mby === 'platform' ? /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: () => post('/api/platform/admin/unmute', {
          user_id: r.peer.id
        }, '已解除禁言')
      }, "\u89E3\u9664\u7981\u8A00") : /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: () => setAsk({
          mode: 'mute',
          id: r.peer && r.peer.id || 0,
          name: r.peer && r.peer.name || '',
          reason: '你违反用户协议，根据用户协议禁言'
        })
      }, "\u7981\u8A00\u88AB\u4E3E\u62A5\u4EBA")), (r.status === 'handled' || r.status === 'dismissed') && !!r.result_note && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        sx: {
          display: 'block',
          mt: 0.5,
          color: 'success.main'
        }
      }, "\u7ED3\u679C\uFF1A", r.result_note));
    })), view === 'mutes' && /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      flexWrap: "wrap",
      useFlexGap: true,
      sx: {
        mb: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 14,
        fontWeight: 700
      }
    }, "\u5E73\u53F0\u7981\u8A00\u4E2D"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: '1 1 auto'
      }
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      onClick: loadMutes
    }, "\u5237\u65B0")), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1
      }
    }, "\u8FD9\u91CC\u53EA\u6709\u5E73\u53F0\u81EA\u5DF1\u4E0B\u7684\u90A3\u6279\u7981\u8A00\uFF08muted_by=platform\uFF09\uFF1B\u672C\u961F\u961F\u957F\u81EA\u8BBE\u7684\u90A3\u6761\u4E0D\u5728\u5217\uFF0C\u4E5F\u53EA\u80FD\u7531\u672C\u961F\u81EA\u5DF1\u89E3\u3002 \u7981\u8A00\u7406\u7531\u4E0E\u64CD\u4F5C\u4EBA\u8BB0\u5728\u300C\u5DE1\u67E5\u8BB0\u5F55\u300D\u90A3\u4E00\u4EFD\u5E73\u53F0\u64CD\u4F5C\u65E5\u5FD7\u91CC\uFF0C\u8FD9\u4E00\u9875\u53EA\u8D1F\u8D23\u770B\u5F97\u89C1\u4E0E\u653E\u5F97\u6389\u3002"), !mutes && /*#__PURE__*/React.createElement(LoadingBox, null), mutes && mutes.list.length === 0 && /*#__PURE__*/React.createElement(Alert, {
      severity: "success",
      sx: {
        borderRadius: '10px'
      }
    }, "\u5E73\u53F0\u8FD9\u8FB9\u6CA1\u6709\u4EBA\u5728\u7981\u8A00\u4E2D\u3002"), mutes && mutes.list.length > 0 && /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u6210\u5458"), /*#__PURE__*/React.createElement(TableCell, null, "\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, null, "\u8D26\u53F7\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, mutes.list.map(function (u) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: u.id,
        sx: {
          verticalAlign: 'top'
        }
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          fontSize: 12,
          whiteSpace: 'nowrap'
        }
      }, u.name), /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          fontSize: 12,
          whiteSpace: 'nowrap'
        }
      }, u.team || '—'), /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          fontSize: 12
        }
      }, u.state === 'approved' ? '正常' : u.state), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: () => post('/api/platform/admin/unmute', {
          user_id: u.id
        }, '已解除禁言')
      }, "\u89E3\u9664\u7981\u8A00")));
    })))), mutes && Number(mutes.capped) === 1 && /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mt: 1
      }
    }, "\u540D\u5355\u5230\u8FD9\u91CC\u622A\u4E86\u4E00\u5200\uFF08\u6700\u591A\u5217 ", muteCount, " \u4EBA\uFF09\uFF1B\u771F\u88AB\u7981\u5230\u8FD9\u4E48\u591A\u4EBA\uFF0C\u9760\u7684\u5C31\u4E0D\u8BE5\u662F\u8FD9\u4E00\u9875\u3002")), /*#__PURE__*/React.createElement(Dialog, {
      open: !!ask,
      onClose: () => setAsk(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, {
      sx: {
        pb: 0.5
      }
    }, ask ? '禁言 ' + (ask.name || '') : ''), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1
      }
    }, "\u7406\u7531\u4F1A\u8BB0\u8FDB\u5E73\u53F0\u64CD\u4F5C\u65E5\u5FD7\u3002\u7981\u8A00\u540E\u4ED6\u5728\u81EA\u5DF1\u961F\u7684\u6218\u961F\u7FA4\u91CC\u8BF4\u4E0D\u4E86\u8BDD\u3001\u79C1\u804A\u4E0E\u67AA\u7801\u8BC4\u8BBA\u4E5F\u53D1\u4E0D\u51FA\u53BB\uFF0C\u800C\u4E14\u672C\u961F\u961F\u957F\u89E3\u4E0D\u6389\u8FD9\u4E00\u6761\u3002"), /*#__PURE__*/React.createElement(TextField, {
      fullWidth: true,
      multiline: true,
      minRows: 2,
      size: "small",
      label: "\u7406\u7531",
      value: ask ? ask.reason : '',
      onChange: e => setAsk({
        ...ask,
        reason: e.target.value
      }),
      inputProps: {
        maxLength: 200
      },
      margin: "normal"
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setAsk(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      color: "error",
      disabled: busy,
      onClick: submitAsk
    }, "\u786E\u5B9A"))));
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
          setForm(Object.assign({}, j.data.config, {
            api_key: ''
          }));
        } else {
          toast(j.msg || 'AI 配置读不到', 'error');
        }
      } catch (e) {
        toast('AI 配置读不到', 'error');
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const set = function (k) {
      return function (e) {
        setForm(Object.assign({}, form, {
          [k]: e.target.value
        }));
      };
    };
    const setBool = function (k) {
      return function (e) {
        setForm(Object.assign({}, form, {
          [k]: e.target.checked ? 1 : 0
        }));
      };
    };
    const save = async function (extra) {
      if (!form || busy) {
        return;
      }
      setBusy(true);
      try {
        const body = Object.assign({
          enabled: form.enabled,
          base_url: form.base_url,
          api_key: form.api_key,
          model: form.model,
          max_tokens: form.max_tokens,
          temperature: form.temperature,
          prompt: form.prompt,
          proactive_prompt: form.proactive_prompt,
          allow_links: form.allow_links,
          max_proactive_per_day: form.max_proactive_per_day,
          sticker_enabled: form.sticker_enabled
        }, extra || {});
        const j = await api('/api/platform/admin/ai/save', {
          method: 'POST',
          body: body
        });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
      setBusy(false);
    };
    const test = async function () {
      if (!form || busy) {
        return;
      }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/test', {
          method: 'POST',
          body: {
            base_url: form.base_url,
            api_key: form.api_key,
            model: form.model
          }
        });
        toast(j.msg || '测试完成', j.code === 0 ? 'success' : 'error');
      } catch (e) {
        toast('测试失败', 'error');
      }
      setBusy(false);
    };
    const doGrant = async function () {
      const n = parseInt(grantAmount, 10);
      if (!grantT || isNaN(n) || n <= 0) {
        toast('请输入正整数 Token 数', 'error');
        return;
      }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/tenants/' + grantT.id + '/grant', {
          method: 'POST',
          body: {
            amount: n,
            note: grantNote
          }
        });
        toast(j.msg || '已充值', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          setGrantT(null);
          setGrantNote('');
          load();
        }
      } catch (e) {
        toast('充值失败', 'error');
      }
      setBusy(false);
    };
    const doReset = async function (t) {
      if (!window.confirm('清零「' + t.name + '」的平台 Token 余额？\n历史消耗记录保留，只把余额归零')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/tenants/' + t.id + '/reset', {
          method: 'POST',
          body: {
            note: '平台清零'
          }
        });
        toast(j.msg || '已清零', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('清零失败', 'error');
      }
      setBusy(false);
    };
    const doDisable = async function (t) {
      const off = Number(t.platform_disabled) === 1 ? 0 : 1;
      if (!window.confirm(off ? '停用「' + t.name + '」的 AI 聊天？\n停用后平台模型和自有模型都不能用。' : '恢复「' + t.name + '」的 AI 聊天？')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/tenants/' + t.id + '/disable', {
          method: 'POST',
          body: {
            disabled: off
          }
        });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
      setBusy(false);
    };
    const tick = async function () {
      if (busy) {
        return;
      }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/tick', {
          method: 'POST',
          body: {}
        });
        toast(j.msg || '已检查', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('检查失败', 'error');
      }
      setBusy(false);
    };

    // ---- v5.36.29：从 Base URL 拉模型名 ----
    const fetchModels = async function () {
      if (!form) {
        return;
      }
      if (!form.base_url) {
        toast('先填 Base URL', 'error');
        return;
      }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/models', {
          method: 'POST',
          body: {
            base_url: form.base_url,
            api_key: form.api_key
          }
        });
        if (j.code !== 0) {
          toast(j.msg || '没拿到模型列表', 'error');
          return;
        }
        const list = j.data && j.data.models || [];
        setModels(list);
        if (list.length === 1) {
          setForm(Object.assign({}, form, {
            model: list[0]
          }));
        }
        toast('已获取 ' + list.length + ' 个模型', 'success');
      } catch (e) {
        toast('获取失败', 'error');
      }
      setBusy(false);
    };

    // ---- 人设库 ----
    const openPrompt = function (row) {
      setPromptEdit(row ? Object.assign({}, row) : {
        id: 0,
        name: '',
        body: '',
        note: '',
        enabled: 1,
        sort: 0
      });
    };
    const savePrompt = async function () {
      const p = promptEdit;
      if (!p) {
        return;
      }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/prompts/save', {
          method: 'POST',
          body: {
            id: p.id,
            name: p.name,
            body: p.body,
            note: p.note,
            enabled: p.enabled,
            sort: p.sort
          }
        });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          setPromptEdit(null);
          load();
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
      setBusy(false);
    };
    const delPrompt = async function (row) {
      if (!window.confirm('删除人设「' + row.name + '」？\n正在用这条人设的战队会自动回落到平台默认模板，不会哑掉。')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/prompts/' + row.id + '/delete', {
          method: 'POST',
          body: {}
        });
        toast(j.msg || '已删除', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('删除失败', 'error');
      }
      setBusy(false);
    };

    // ---- 表情包库 ----
    const uploadSticker = async function () {
      if (!stickerFile) {
        toast('先选一张图片', 'error');
        return;
      }
      if (!stickerName.trim()) {
        toast('给这张图起个名字，AI 靠名字挑图', 'error');
        return;
      }
      setBusy(true);
      try {
        const fd = new FormData();
        fd.append('image', stickerFile);
        fd.append('name', stickerName.trim());
        fd.append('note', stickerNote.trim());
        const j = await api('/api/platform/admin/ai/stickers/upload', {
          method: 'POST',
          form: fd
        });
        toast(j.msg || '已上传', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          setStickerName('');
          setStickerNote('');
          setStickerFile(null);
          load();
        }
      } catch (e) {
        toast('上传失败', 'error');
      }
      setBusy(false);
    };
    const saveSticker = async function () {
      const s = stickerEdit;
      if (!s) {
        return;
      }
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/stickers/save', {
          method: 'POST',
          body: {
            id: s.id,
            name: s.name,
            note: s.note,
            enabled: s.enabled ? 1 : 0,
            sort: s.sort
          }
        });
        toast(j.msg || '已保存', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          setStickerEdit(null);
          load();
        }
      } catch (e) {
        toast('保存失败', 'error');
      }
      setBusy(false);
    };
    const delSticker = async function (row) {
      if (!window.confirm('删除表情包「' + row.name + '」？图片文件也会一起删掉。')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/ai/stickers/' + row.id + '/delete', {
          method: 'POST',
          body: {}
        });
        toast(j.msg || '已删除', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          load();
        }
      } catch (e) {
        toast('删除失败', 'error');
      }
      setBusy(false);
    };
    const MODE_LABEL = {
      off: '未开启',
      platform: '平台模型',
      own: '自有模型'
    };
    const num = function (v) {
      return Number(v || 0).toLocaleString();
    };
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        }
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "QQ \u673A\u5668\u4EBA \xB7 AI \u804A\u5929"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: '#6e6e73',
        mt: 0.5
      }
    }, "\u5E73\u53F0\u63D0\u4F9B\u9996\u9009\u6A21\u578B\u548C\u7EDF\u4E00\u7684\u4EBA\u8BBE\u63D0\u793A\u8BCD\uFF0C\u5E76\u7ED9\u6218\u961F\u53D1 Token\uFF1B\u961F\u957F\u4E5F\u53EF\u4EE5\u6539\u6210\u81EA\u5DF1\u7684\u6A21\u578B\u3002 AI \u53EA\u804A\u5929\uFF0C\u4E0D\u6267\u884C\u4EFB\u4F55\u673A\u5668\u4EBA\u6307\u4EE4\u3002\u5E73\u53F0\u770B\u4E0D\u5230\u6218\u961F\u7FA4\u7684\u804A\u5929\u6B63\u6587\u3002"), !form && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        color: '#6e6e73',
        py: 2
      }
    }, "\u6B63\u5728\u53D6\u2026"), form && /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      disabled: busy,
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: Number(form.enabled) === 1,
        onChange: setBool('enabled')
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13.5,
          color: TEXT_PRIMARY
        }
      }, Number(form.enabled) === 1 ? '平台首选模型已开放' : '平台首选模型已关闭（战队仍可用自有模型）')
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        md: 'row'
      },
      spacing: 1.5,
      sx: {
        mt: 1.5
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "Base URL",
      size: "small",
      fullWidth: true,
      value: form.base_url,
      onChange: set('base_url'),
      placeholder: "https://api.example.com/v1",
      helperText: "OpenAI \u517C\u5BB9\u5730\u5740\uFF0C\u670D\u52A1\u7AEF\u4F1A\u81EA\u52A8\u8865 /chat/completions"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "API Key",
      size: "small",
      fullWidth: true,
      type: "password",
      value: form.api_key,
      onChange: set('api_key'),
      placeholder: form.has_key ? '留空 = 不修改（当前 ' + form.api_key_mask + '）' : '尚未填写'
    })), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        md: 'row'
      },
      spacing: 1.5,
      sx: {
        mt: 1.5
      },
      alignItems: {
        md: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u6A21\u578B\u540D",
      size: "small",
      fullWidth: true,
      value: form.model,
      onChange: set('model'),
      placeholder: "\u70B9\u53F3\u8FB9\u300C\u83B7\u53D6\u6A21\u578B\u5217\u8868\u300D\uFF0C\u6216\u76F4\u63A5\u624B\u586B",
      helperText: "\u5927\u90E8\u5206\u4E2D\u8F6C\u7AD9\u586B\u4E86 Base URL \u5C31\u80FD\u81EA\u52A8\u5217\u51FA\u6765\uFF0C\u4E0D\u7528\u8BB0\u6A21\u578B\u540D"
    }), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      disabled: busy,
      onClick: fetchModels,
      sx: {
        height: 40,
        flexShrink: 0,
        whiteSpace: 'nowrap'
      }
    }, "\u83B7\u53D6\u6A21\u578B\u5217\u8868")), models.length > 0 && /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        md: 'row'
      },
      spacing: 1,
      sx: {
        mt: 1
      },
      alignItems: "center"
    }, /*#__PURE__*/React.createElement(Select, {
      size: "small",
      fullWidth: true,
      displayEmpty: true,
      value: models.indexOf(form.model) >= 0 ? form.model : '',
      onChange: function (e) {
        setForm(Object.assign({}, form, {
          model: e.target.value
        }));
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: "",
      disabled: true
    }, "\u4ECE\u62FF\u5230\u7684 ", models.length, " \u4E2A\u6A21\u578B\u91CC\u9009\u4E00\u4E2A"), models.map(function (m) {
      return /*#__PURE__*/React.createElement(MenuItem, {
        key: m,
        value: m
      }, m);
    })), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "text",
      sx: {
        flexShrink: 0
      },
      onClick: function () {
        setModels([]);
      }
    }, "\u6536\u8D77\u5217\u8868")), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        md: 'row'
      },
      spacing: 1.5,
      sx: {
        mt: 1.5
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u56DE\u590D\u4E0A\u9650",
      size: "small",
      type: "number",
      sx: {
        width: {
          xs: '100%',
          md: 140
        }
      },
      value: form.max_tokens,
      onChange: set('max_tokens'),
      inputProps: {
        min: 32,
        max: 300
      },
      helperText: "Token\uFF0C32-300"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6E29\u5EA6",
      size: "small",
      type: "number",
      sx: {
        width: {
          xs: '100%',
          md: 120
        }
      },
      value: form.temperature,
      onChange: set('temperature'),
      inputProps: {
        min: 0,
        max: 2,
        step: 0.1
      }
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u4E3B\u52A8\u4E0A\u9650/\u5929",
      size: "small",
      type: "number",
      sx: {
        width: {
          xs: '100%',
          md: 150
        }
      },
      value: form.max_proactive_per_day,
      onChange: set('max_proactive_per_day'),
      inputProps: {
        min: 0,
        max: 10
      },
      helperText: "0 = \u5168\u7AD9\u4E0D\u8BB8\u4E3B\u52A8"
    })), /*#__PURE__*/React.createElement(TextField, {
      label: "\u89D2\u8272\u626E\u6F14\u63D0\u793A\u8BCD\uFF08\u5E73\u53F0\u7EDF\u4E00\uFF0C\u6218\u961F\u53EA\u8BFB\uFF09",
      size: "small",
      fullWidth: true,
      multiline: true,
      minRows: 4,
      sx: {
        mt: 1.5
      },
      value: form.prompt,
      onChange: set('prompt'),
      placeholder: "\u7559\u7A7A\u4F7F\u7528\u5185\u7F6E\u4EBA\u8BBE",
      helperText: "\u53EF\u7528 {team} \u6218\u961F\u540D\u3001{alias} \u673A\u5668\u4EBA\u79F0\u547C\uFF1B\u4FDD\u5B58\u540E\u670D\u52A1\u7AEF\u59CB\u7EC8\u8FFD\u52A0\u5B89\u5168\u786C\u89C4\u5219"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u4E3B\u52A8\u53D1\u8A00\u9644\u52A0\u63D0\u793A",
      size: "small",
      fullWidth: true,
      multiline: true,
      minRows: 2,
      sx: {
        mt: 1.5
      },
      value: form.proactive_prompt,
      onChange: set('proactive_prompt'),
      placeholder: "\u4EC5\u7528\u4E8E\u5B9A\u65F6\u4E3B\u52A8\u53D1\u8A00"
    }), /*#__PURE__*/React.createElement(FormControlLabel, {
      sx: {
        mt: 1
      },
      disabled: busy,
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: Number(form.allow_links) === 1,
        onChange: setBool('allow_links')
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13,
          color: TEXT_PRIMARY
        }
      }, "\u5141\u8BB8\u6A21\u578B\u56DE\u590D\u91CC\u5E26\u94FE\u63A5\uFF08\u9ED8\u8BA4\u5173\uFF1B\u5173\u6389\u4F1A\u66FF\u6362\u6210\u300C\u94FE\u63A5\u5DF2\u7701\u7565\u300D\uFF09")
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mt: 1.5
      }
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      size: "small",
      disabled: busy,
      onClick: function () {
        save();
      }
    }, "\u4FDD\u5B58"), /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      size: "small",
      disabled: busy,
      onClick: test
    }, "\u6D4B\u8BD5\u9996\u9009\u6A21\u578B"), /*#__PURE__*/React.createElement(Button, {
      variant: "text",
      size: "small",
      disabled: busy,
      onClick: tick
    }, "\u68C0\u67E5\u5230\u70B9\u7684\u4E3B\u52A8\u53D1\u8A00")), /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mt: 1.5,
        fontSize: 12.5
      }
    }, "\u5B89\u5168\u786C\u89C4\u5219\uFF1A", d && d.defaults.safety))), d && /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        },
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u89D2\u8272\u626E\u6F14\u4EBA\u8BBE\u5E93"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: '#6e6e73',
        mt: 0.3
      }
    }, "\u8FD9\u91CC\u653E\u591A\u5957\u4EBA\u8BBE\uFF08\u6BD4\u5982\u300C\u6B63\u7ECF\u961F\u957F\u300D\u300C\u6C99\u96D5\u7F51\u53CB\u300D\u300C\u6218\u672F\u5206\u6790\u5E08\u300D\uFF09\u3002\u7AD9\u957F\u5728\u6218\u961F\u540E\u53F0\u53EA\u80FD\u4ECE\u91CC\u9762\u6311\u4E00\u6761\uFF0C \u6311\u4E0D\u4E86\u4E0A\u9762\u90A3\u6761\u7EDF\u4E00\u4EBA\u8BBE\u4EE5\u5916\u7684\u5185\u5BB9\u3002\u5B89\u5168\u786C\u89C4\u5219\u6C38\u8FDC\u7531\u670D\u52A1\u7AEF\u8FFD\u52A0\uFF0C\u4EBA\u8BBE\u6539\u4E0D\u6389\u5B83\u3002")), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "contained",
      disabled: busy,
      onClick: function () {
        openPrompt(null);
      }
    }, "\u65B0\u589E\u4EBA\u8BBE")), d.prompts.length === 0 ? /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        color: TEXT_SECONDARY,
        mt: 1.5
      }
    }, "\u8FD8\u6CA1\u6709\u989D\u5916\u4EBA\u8BBE\uFF0C\u6240\u6709\u6218\u961F\u90FD\u7528\u4E0A\u9762\u90A3\u6761\u7EDF\u4E00\u4EBA\u8BBE\u3002\u70B9\u300C\u65B0\u589E\u4EBA\u8BBE\u300D\u52A0\u4E00\u6761\uFF0C\u6218\u961F\u540E\u53F0\u5C31\u4F1A\u51FA\u73B0\u4E0B\u62C9\u3002") : /*#__PURE__*/React.createElement(TableContainer, {
      sx: {
        mt: 1.5
      }
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u540D\u79F0"), /*#__PURE__*/React.createElement(TableCell, null, "\u8BF4\u660E"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right"
    }, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, d.prompts.map(function (p) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: p.id
      }, /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        fontWeight: 600
      }, p.name)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, p.note || '—')), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: Number(p.enabled) === 1 ? 'success' : 'default',
        label: Number(p.enabled) === 1 ? '可选用' : '已下架'
      })), /*#__PURE__*/React.createElement(TableCell, {
        align: "right"
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.5,
        justifyContent: "flex-end"
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: function () {
          openPrompt(p);
        }
      }, "\u7F16\u8F91"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: "error",
        disabled: busy,
        onClick: function () {
          delPrompt(p);
        }
      }, "\u5220\u9664"))));
    }))))), d && /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        },
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      alignItems: "center",
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u8868\u60C5\u5305\u5E93"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        lineHeight: '19px',
        color: '#6e6e73',
        mt: 0.3
      }
    }, "AI \u56DE\u590D\u5B8C\u6587\u5B57\u540E\uFF0C\u6309\u6218\u961F\u81EA\u5DF1\u8BBE\u7684\u6982\u7387\u8FFD\u53D1\u4E00\u5F20\u3002\u547D\u4E2D\u65F6\u628A\u8FD9\u91CC\u7684\u300C\u540D\u79F0 + \u9002\u7528\u573A\u666F\u300D\u4EA4\u7ED9\u6A21\u578B\u6311\uFF0C \u6A21\u578B\u6CA1\u6311\u5C31\u8BF4\u4E0D\u51FA\u7F16\u53F7\uFF0C\u670D\u52A1\u7AEF\u968F\u673A\u8865\u4E00\u5F20\u3002\u56FE\u7247\u53EA\u5728\u670D\u52A1\u7AEF\u4E0A\u4F20\uFF0CQQ \u7FA4\u91CC\u770B\u5230\u7684\u662F\u4E00\u5F20\u771F\u56FE\u3002")), /*#__PURE__*/React.createElement(FormControlLabel, {
      disabled: busy,
      sx: {
        mr: 0
      },
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: Number(form.sticker_enabled) === 1,
        onChange: setBool('sticker_enabled')
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13,
          color: TEXT_PRIMARY
        }
      }, "\u8868\u60C5\u5305\u603B\u95F8")
    })), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        md: 'row'
      },
      spacing: 1.5,
      sx: {
        mt: 1.5
      },
      alignItems: {
        md: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u8868\u60C5\u5305\u540D\u79F0",
      size: "small",
      sx: {
        width: {
          xs: '100%',
          md: 200
        }
      },
      value: stickerName,
      onChange: function (e) {
        setStickerName(e.target.value);
      },
      placeholder: "\u4F8B\u5982 \u70B9\u8D5E / \u65E0\u8BED",
      helperText: "AI \u5C31\u662F\u770B\u8FD9\u4E2A\u540D\u5B57\u6311\u56FE\u7684"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u9002\u7528\u573A\u666F\uFF08\u53EF\u9009\uFF09",
      size: "small",
      fullWidth: true,
      value: stickerNote,
      onChange: function (e) {
        setStickerNote(e.target.value);
      },
      placeholder: "\u4F8B\u5982 \u5938\u4EBA\u65F6\u7528 / \u5BF9\u65B9\u8BF4\u4E86\u79BB\u8C31\u7684\u8BDD\u65F6\u7528"
    }), /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      size: "small",
      component: "label",
      disabled: busy,
      sx: {
        height: 40,
        flexShrink: 0,
        whiteSpace: 'nowrap'
      }
    }, stickerFile ? '已选：' + stickerFile.name : '选择图片', /*#__PURE__*/React.createElement("input", {
      type: "file",
      hidden: true,
      accept: "image/jpeg,image/png,image/gif,image/webp",
      onChange: function (e) {
        setStickerFile(e.target.files && e.target.files[0] ? e.target.files[0] : null);
      }
    })), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      size: "small",
      disabled: busy,
      sx: {
        height: 40,
        flexShrink: 0
      },
      onClick: uploadSticker
    }, "\u4E0A\u4F20")), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 11.5,
        color: TEXT_SECONDARY,
        mt: 0.5
      }
    }, "jpg / png / gif / webp\uFF0C\u5355\u5F20\u4E0D\u8D85\u8FC7 2MB\u3002\u5EFA\u8BAE\u7528\u6B63\u65B9\u5F62\u5C0F\u56FE\u3002"), d.stickers.length === 0 ? /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        color: TEXT_SECONDARY,
        mt: 1.5
      }
    }, "\u5E93\u91CC\u8FD8\u6CA1\u6709\u8868\u60C5\u5305\u3002\u6218\u961F\u628A\u6982\u7387\u8C03\u591A\u9AD8\u90FD\u4E0D\u4F1A\u6709\u56FE\uFF0C\u5148\u4F20\u51E0\u5F20\u3002") : /*#__PURE__*/React.createElement(TableContainer, {
      sx: {
        mt: 1.5
      }
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u9884\u89C8"), /*#__PURE__*/React.createElement(TableCell, null, "\u540D\u79F0"), /*#__PURE__*/React.createElement(TableCell, null, "\u9002\u7528\u573A\u666F"), /*#__PURE__*/React.createElement(TableCell, null, "\u5DF2\u53D1"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, {
      align: "right"
    }, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, d.stickers.map(function (s) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: s.id
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          width: 72
        }
      }, /*#__PURE__*/React.createElement(Box, {
        component: "img",
        src: s.path,
        alt: s.name,
        sx: {
          width: 56,
          height: 56,
          objectFit: 'cover',
          borderRadius: 1.5,
          border: '1px solid ' + BORDER,
          display: 'block'
        }
      })), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        fontWeight: 600
      }, s.name)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, s.note || '—')), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption"
      }, num(s.sent_count))), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: Number(s.enabled) === 1 ? 'success' : 'default',
        label: Number(s.enabled) === 1 ? '可发' : '停用'
      })), /*#__PURE__*/React.createElement(TableCell, {
        align: "right"
      }, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.5,
        justifyContent: "flex-end"
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: function () {
          setStickerEdit(Object.assign({}, s));
        }
      }, "\u7F16\u8F91"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: "error",
        disabled: busy,
        onClick: function () {
          delSticker(s);
        }
      }, "\u5220\u9664"))));
    })))), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 11.5,
        color: TEXT_SECONDARY,
        mt: 1
      }
    }, "\u6539\u4E86\u603B\u95F8\u8981\u8BB0\u5F97\u70B9\u4E0A\u9762\u90A3\u5F20\u5361\u7247\u7684\u300C\u4FDD\u5B58\u300D\u624D\u4F1A\u751F\u6548\u3002")), d && /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        },
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u5404\u6218\u961F\u4F59\u989D\u4E0E\u7528\u91CF"), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mt: 1,
        flexWrap: 'wrap',
        useFlexGap: true
      }
    }, /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '近 7 天调用 ' + num(d.stats.calls_7d),
      sx: {
        bgcolor: BG_GRAY,
        fontSize: 12
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '近 7 天 Token ' + num(d.stats.tokens_7d),
      sx: {
        bgcolor: BG_GRAY,
        fontSize: 12
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '平台模型 ' + num(d.stats.platform_tokens_7d),
      sx: {
        bgcolor: BG_GRAY,
        fontSize: 12
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '自有模型 ' + num(d.stats.own_tokens_7d),
      sx: {
        bgcolor: BG_GRAY,
        fontSize: 12
      }
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '失败 ' + num(d.stats.fail_7d),
      color: d.stats.fail_7d > 0 ? 'warning' : 'default',
      variant: "outlined"
    }), /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: '排队 ' + num(d.stats.pending_jobs),
      variant: "outlined"
    })), /*#__PURE__*/React.createElement(TableContainer, {
      sx: {
        mt: 1.5
      }
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, null, "\u6A21\u5F0F"), /*#__PURE__*/React.createElement(TableCell, null, "\u4F59\u989D"), /*#__PURE__*/React.createElement(TableCell, null, "\u4ECA\u65E5"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, d.tenants.map(function (t) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: t.id
      }, /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        fontWeight: 600
      }, t.name), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "/", t.slug, "/ \xB7 ", t.status === 'approved' ? '已上线' : t.status)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        label: MODE_LABEL[t.mode] || t.mode
      })), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2"
      }, num(t.balance)), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u7D2F\u8BA1\u53D1 ", num(t.token_granted), " / \u7528 ", num(t.token_used))), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption"
      }, num(t.daily_used), t.daily_limit > 0 ? ' / ' + num(t.daily_limit) : '（不限）')), /*#__PURE__*/React.createElement(TableCell, null, Number(t.platform_disabled) === 1 ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "error",
        variant: "outlined",
        label: "\u5E73\u53F0\u505C\u7528"
      }) : t.last_error ? /*#__PURE__*/React.createElement(Tooltip, {
        title: t.last_error
      }, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "warning",
        variant: "outlined",
        label: "\u6700\u8FD1\u5931\u8D25"
      })) : /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "success",
        variant: "outlined",
        label: "\u6B63\u5E38"
      })), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.5,
        flexWrap: "wrap",
        useFlexGap: true
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: function () {
          setGrantT(t);
          setGrantAmount('5000');
          setGrantNote('');
        }
      }, "\u5145\u503C"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: busy,
        onClick: function () {
          doReset(t);
        }
      }, "\u6E05\u96F6"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: Number(t.platform_disabled) === 1 ? 'primary' : 'error',
        disabled: busy,
        onClick: function () {
          doDisable(t);
        }
      }, Number(t.platform_disabled) === 1 ? '恢复' : '停用'))));
    }))))), d && d.usage.length > 0 && /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        },
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 15,
        fontWeight: 700,
        color: TEXT_PRIMARY
      }
    }, "\u6700\u8FD1\u8C03\u7528\uFF08\u4E0D\u542B\u804A\u5929\u6B63\u6587\uFF09"), /*#__PURE__*/React.createElement(TableContainer, {
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, null, "\u7C7B\u578B"), /*#__PURE__*/React.createElement(TableCell, null, "\u6765\u6E90"), /*#__PURE__*/React.createElement(TableCell, null, "\u6A21\u578B"), /*#__PURE__*/React.createElement(TableCell, null, "Token"), /*#__PURE__*/React.createElement(TableCell, null, "\u7ED3\u679C"))), /*#__PURE__*/React.createElement(TableBody, null, d.usage.map(function (u, i) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: i
      }, /*#__PURE__*/React.createElement(TableCell, null, fmtTime(u.created_at)), /*#__PURE__*/React.createElement(TableCell, null, u.tenant_name), /*#__PURE__*/React.createElement(TableCell, null, u.kind), /*#__PURE__*/React.createElement(TableCell, null, u.source === 'platform' ? '平台' : '自有'), /*#__PURE__*/React.createElement(TableCell, null, u.model), /*#__PURE__*/React.createElement(TableCell, null, num(u.total_tokens)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: u.status === 'ok' ? 'success' : 'warning',
        label: u.status
      })));
    }))))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!grantT,
      onClose: function () {
        setGrantT(null);
      },
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u7ED9\u300C", grantT && grantT.name, "\u300D\u5145\u503C Token"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        color: '#6e6e73',
        mb: 1.5
      }
    }, "\u5F53\u524D\u4F59\u989D ", grantT ? num(grantT.balance) : '-', "\u3002\u4E00\u6B21\u6027\u4F59\u989D\uFF0C\u7528\u5B8C\u4E0D\u4F1A\u81EA\u52A8\u7EED\uFF1B \u6E05\u96F6\u53EA\u5F52\u96F6\u4F59\u989D\uFF0C\u5386\u53F2\u6D88\u8017\u8BB0\u5F55\u4FDD\u7559\u3002\u961F\u957F\u7528\u81EA\u6709\u6A21\u578B\u65F6\u4E0D\u6263\u8FD9\u91CC\u7684\u4F59\u989D\u3002"), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5145\u503C\u6570\u91CF",
      type: "number",
      size: "small",
      fullWidth: true,
      value: grantAmount,
      onChange: function (e) {
        setGrantAmount(e.target.value);
      },
      inputProps: {
        min: 1
      },
      autoFocus: true
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5907\u6CE8\uFF08\u53EF\u9009\uFF09",
      size: "small",
      fullWidth: true,
      sx: {
        mt: 1.5
      },
      value: grantNote,
      onChange: function (e) {
        setGrantNote(e.target.value);
      },
      inputProps: {
        maxLength: 200
      }
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setGrantT(null);
      }
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      disabled: busy,
      onClick: doGrant
    }, "\u786E\u8BA4\u5145\u503C"))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!promptEdit,
      onClose: function () {
        setPromptEdit(null);
      },
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, promptEdit && promptEdit.id ? '编辑人设' : '新增人设'), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(TextField, {
      label: "\u540D\u79F0",
      size: "small",
      fullWidth: true,
      sx: {
        mt: 1
      },
      value: promptEdit && promptEdit.name || '',
      onChange: function (e) {
        setPromptEdit(Object.assign({}, promptEdit, {
          name: e.target.value
        }));
      },
      placeholder: "\u4F8B\u5982 \u6C99\u96D5\u7F51\u53CB",
      inputProps: {
        maxLength: 32
      },
      autoFocus: true
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u63D0\u793A\u8BCD\u6B63\u6587",
      size: "small",
      fullWidth: true,
      multiline: true,
      minRows: 7,
      sx: {
        mt: 1.5
      },
      value: promptEdit && promptEdit.body || '',
      onChange: function (e) {
        setPromptEdit(Object.assign({}, promptEdit, {
          body: e.target.value
        }));
      },
      placeholder: "\u4F60\u662F\u300C{team}\u300D\u6218\u961F\u7FA4\u91CC\u7684\u7FA4\u52A9\u624B\u300C{alias}\u300D\u2026",
      helperText: "\u53EF\u7528 {team} \u6218\u961F\u540D\u3001{alias} \u673A\u5668\u4EBA\u79F0\u547C\uFF1B\u6700\u591A 4000 \u5B57\uFF0C\u5B89\u5168\u786C\u89C4\u5219\u7531\u670D\u52A1\u7AEF\u81EA\u52A8\u8FFD\u52A0"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u7ED9\u961F\u957F\u770B\u7684\u4E00\u53E5\u8BF4\u660E\uFF08\u53EF\u9009\uFF09",
      size: "small",
      fullWidth: true,
      sx: {
        mt: 1.5
      },
      value: promptEdit && promptEdit.note || '',
      onChange: function (e) {
        setPromptEdit(Object.assign({}, promptEdit, {
          note: e.target.value
        }));
      },
      placeholder: "\u4F8B\u5982 \u8BF4\u8BDD\u50CF\u8001\u6CB9\u6761\uFF0C\u7231\u5F00\u73A9\u7B11",
      inputProps: {
        maxLength: 200
      }
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1.5,
      sx: {
        mt: 1.5
      },
      alignItems: "center"
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u6392\u5E8F",
      size: "small",
      type: "number",
      sx: {
        width: 120
      },
      value: promptEdit && promptEdit.sort || 0,
      onChange: function (e) {
        setPromptEdit(Object.assign({}, promptEdit, {
          sort: e.target.value
        }));
      }
    }), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: !!promptEdit && Number(promptEdit.enabled) === 1,
        onChange: function (e) {
          setPromptEdit(Object.assign({}, promptEdit, {
            enabled: e.target.checked ? 1 : 0
          }));
        }
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13
        }
      }, "\u8BA9\u6218\u961F\u53EF\u9009")
    }))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setPromptEdit(null);
      }
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      disabled: busy,
      onClick: savePrompt
    }, "\u4FDD\u5B58\u4EBA\u8BBE"))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!stickerEdit,
      onClose: function () {
        setStickerEdit(null);
      },
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u7F16\u8F91\u8868\u60C5\u5305"), /*#__PURE__*/React.createElement(DialogContent, null, stickerEdit && /*#__PURE__*/React.createElement(Box, {
      component: "img",
      src: stickerEdit.path,
      alt: stickerEdit.name,
      sx: {
        width: 96,
        height: 96,
        objectFit: 'cover',
        borderRadius: 2,
        border: '1px solid ' + BORDER,
        display: 'block',
        mt: 1
      }
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u540D\u79F0",
      size: "small",
      fullWidth: true,
      sx: {
        mt: 1.5
      },
      value: stickerEdit && stickerEdit.name || '',
      onChange: function (e) {
        setStickerEdit(Object.assign({}, stickerEdit, {
          name: e.target.value
        }));
      },
      inputProps: {
        maxLength: 32
      }
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u9002\u7528\u573A\u666F",
      size: "small",
      fullWidth: true,
      sx: {
        mt: 1.5
      },
      value: stickerEdit && stickerEdit.note || '',
      onChange: function (e) {
        setStickerEdit(Object.assign({}, stickerEdit, {
          note: e.target.value
        }));
      },
      inputProps: {
        maxLength: 200
      }
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1.5,
      sx: {
        mt: 1.5
      },
      alignItems: "center"
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u6392\u5E8F",
      size: "small",
      type: "number",
      sx: {
        width: 120
      },
      value: stickerEdit && stickerEdit.sort || 0,
      onChange: function (e) {
        setStickerEdit(Object.assign({}, stickerEdit, {
          sort: e.target.value
        }));
      }
    }), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: !!stickerEdit && Number(stickerEdit.enabled) === 1,
        onChange: function (e) {
          setStickerEdit(Object.assign({}, stickerEdit, {
            enabled: e.target.checked ? 1 : 0
          }));
        }
      }),
      label: /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 13
        }
      }, "\u53EF\u4EE5\u53D1")
    }))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setStickerEdit(null);
      }
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      disabled: busy,
      onClick: saveSticker
    }, "\u4FDD\u5B58"))));
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
        if (json.code !== 0) {
          setList([]);
          setTip(json.msg || '读不到销毁登记');
          return;
        }
        setTip(json.data.available ? '' : '这一版的库还没补（缺 tenant_purges 表），先执行升级脚本');
        setList(json.data.list || []);
      } catch (e) {
        setList([]);
        setTip('这一页没读通：刷新再看一次');
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    if (list === null) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    return /*#__PURE__*/React.createElement(Box, null, tip && /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mb: 1
      }
    }, tip), /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 1
      }
    }, "\u5907\u4EFD\u5305\u91CC\u662F\u90A3\u4E00\u8F6E\u7684\u6574\u4EFD SQL\u3001\u56FE\u7247\u4E0E\u7AD9\u70B9\u914D\u7F6E\uFF0C\u8981\u6062\u590D\u5C31\u7167\u5305\u91CC\u7684 MANIFEST \u624B\u52A8\u5BFC\u56DE\u3002 \u5305\u5B58\u5728\u670D\u52A1\u5668\u7684 runtime/data_tasks \u91CC\uFF0C\u7AD9\u70B9\u672C\u8EAB\u5DF2\u7ECF\u5220\u5E72\u51C0\u4E86\uFF0C\u8FD9\u91CC\u53EA\u662F\u53F0\u8D26\u3002"), list.length === 0 ? /*#__PURE__*/React.createElement(EmptyBox, {
      text: "\u8FD8\u6CA1\u6709\u7AD9\u70B9\u88AB\u9500\u6BC1\u8FC7"
    }) : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u7AD9\u70B9"), /*#__PURE__*/React.createElement(TableCell, null, "\u9500\u6BC1\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "\u5F53\u65F6\u6210\u5458"), /*#__PURE__*/React.createElement(TableCell, null, "\u5220\u4E86\u591A\u5C11\u884C"), /*#__PURE__*/React.createElement(TableCell, null, "\u5220\u4E86\u591A\u5C11\u56FE"), /*#__PURE__*/React.createElement(TableCell, null, "\u7ECF\u624B\u4EBA"), /*#__PURE__*/React.createElement(TableCell, null, "\u5907\u4EFD\u5305"))), /*#__PURE__*/React.createElement(TableBody, null, list.map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.id
      }, /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        fontWeight: 600
      }, r.name), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "/", r.slug, "/ \xB7 ID ", r.tenant_id)), /*#__PURE__*/React.createElement(TableCell, null, fmtTime(r.created_at)), /*#__PURE__*/React.createElement(TableCell, null, r.member_count), /*#__PURE__*/React.createElement(TableCell, null, r.row_count), /*#__PURE__*/React.createElement(TableCell, null, r.file_count, " \u5F20 / ", fmtBytes(r.bytes)), /*#__PURE__*/React.createElement(TableCell, null, r.actor_name || '-', Number(r.banned_days) > 0 && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block'
        }
      }, "\u5C01\u4E86 ", r.banned_days, " \u5929", r.banned_at ? '（' + fmtTime(r.banned_at) + ' 起）' : '')), /*#__PURE__*/React.createElement(TableCell, null, !r.backup_file ? /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u6CA1\u767B\u8BB0\u5305\u540D") : /*#__PURE__*/React.createElement(Stack, {
        spacing: 0.2
      }, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        sx: {
          fontFamily: 'ui-monospace, Menlo, monospace',
          maxWidth: 170,
          display: 'block',
          wordBreak: 'break-all'
        }
      }, r.backup_file), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, fmtBytes(r.backup_bytes)), r.backup_alive ? /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        sx: {
          alignSelf: 'flex-start',
          minWidth: 0
        },
        onClick: function () {
          window.location.href = '/api/platform/admin/purged/download?file=' + encodeURIComponent(r.backup_file);
        }
      }, "\u4E0B\u8F7D\u5907\u4EFD") : /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        color: "warning",
        label: "\u5305\u5DF2\u7ECF\u4E0D\u5728\u4E86",
        sx: {
          height: 20,
          fontSize: 11,
          alignSelf: 'flex-start'
        }
      }))));
    })))));
  }
  function SuperConsole({
    tab,
    onPending,
    onGov
  }) {
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
      } catch (e) {
        setApps([]);
      }
    }, []);
    const loadTenants = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/tenants');
        setTenants(json.data.list);
      } catch (e) {
        setTenants([]);
      }
    }, []);
    useEffect(function () {
      loadApps();
      loadTenants();
    }, [loadApps, loadTenants]);
    // 待审数交给外壳，侧栏「入驻申请」那行才亮得上红点
    useEffect(function () {
      if (apps && onPending) {
        onPending(apps.filter(function (a) {
          return a.status === 'pending';
        }).length);
      }
    }, [apps, onPending]);
    // v5.32.0：侧栏「私聊治理」那行的红点 = 待结案的私聊举报（大厅举报那一半跟着大厅一起没了）
    // 数是从举报接口自己带的 counts 取的，与切进这一页看到的列表同一个口径，不会两处不一样
    const [gov, setGov] = useState(0);
    const loadGov = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/chat/reports?status=pending&size=1');
        if (json.code === 0) {
          const c = json.data && json.data.counts || {};
          setGov((c.pending || 0) + (c.opened || 0));
        }
      } catch (e) {/* 红点亮不起来不挡治理 */}
    }, []);
    useEffect(function () {
      loadGov();
    }, [tab, loadGov]);
    useEffect(function () {
      window.addEventListener('yys-gov-badge', loadGov);
      return function () {
        window.removeEventListener('yys-gov-badge', loadGov);
      };
    }, [loadGov]);
    useEffect(function () {
      if (onGov) {
        onGov(gov);
      }
    }, [gov, onGov]);
    const approve = async function (a) {
      if (!window.confirm('通过「' + a.name + '」的入驻申请？\n将开通站点目录 /' + a.slug + '/ 并创建战队超管账号')) return;
      try {
        const json = await api('/api/platform/admin/applications/' + a.id + '/approve', {
          method: 'POST'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          loadApps();
          loadTenants();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const doReject = async function () {
      if (!reason.trim()) {
        toast('请填写驳回原因', 'error');
        return;
      }
      try {
        const json = await api('/api/platform/admin/applications/' + rejectApp.id + '/reject', {
          method: 'POST',
          body: {
            reason: reason.trim()
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setRejectApp(null);
          setReason('');
          loadApps();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // 封禁 / 恢复租户
    const toggleTenant = async function (t) {
      const next = t.status === 'approved' ? 'banned' : 'approved';
      const tip = next === 'banned' ? '封禁「' + t.name + '」？封禁后该站点目录访问将显示封禁提示页' : '恢复「' + t.name + '」？恢复后该站点可正常访问';
      if (!window.confirm(tip)) return;
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/status', {
          method: 'POST',
          body: {
            status: next
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          loadTenants();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.1.0：修改存储配额
    const saveQuota = async function () {
      const gb = parseFloat(quotaGb);
      if (isNaN(gb) || gb < 0) {
        toast('请输入合法的 GB 数（0 = 恢复默认 2GB）', 'error');
        return;
      }
      try {
        const json = await api('/api/platform/admin/tenants/' + quotaT.id + '/quota', {
          method: 'POST',
          body: {
            quota_gb: gb
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setQuotaT(null);
          loadTenants();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.1.0：隐身巡查——拿签名入口直接新窗口打开（只读）
    // v5.16.0：入口有效期收到 30 分钟，且巡查期间的每一次读取都会写入该站点操作日志
    const stealthView = async function (t) {
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/stealth', {
          method: 'POST'
        });
        if (json.code === 0) {
          toast('隐身入口已生成（30 分钟内有效、仅一次）。巡查期间的读取会写入该站点操作日志，站长可见', 'info');
          window.open(json.data.url, '_blank');
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.16.0：巡查台账——就地读取该站点的后台台账（成员全量资料 / 待审核 / 黑名单 / 找回申请 /
    // 操作日志 / 图片 / 战绩 / 约战）。凭据是同一次签发的巡查会话 cookie，逐次落审计。
    const openInspect = async function (t) {
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/stealth', {
          method: 'POST'
        });
        if (json.code !== 0) {
          toast(json.msg, 'error');
          return;
        }
        setInspectBox({
          t: t,
          token: json.data.token,
          quota: json.data.quota
        });
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.16.0：一键撤销——清掉该站点全部巡查登记（未兑票的链接 + 正在用的会话）
    const revokeStealth = async function (t) {
      if (!window.confirm('撤销「' + t.name + '」的全部隐身巡查入口与在用巡查会话？\n已签发的链接立即失效，正在巡查的浏览器下一步读取就会被拒')) return;
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/stealth-revoke', {
          method: 'POST'
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          loadTenants();
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.36.13：进入平台协作——队长授权后才可用，一次性票据 5 分钟有效
    const enterSupport = async function (t) {
      if (!t.support_granted) {
        toast('该战队队长尚未授权平台协作，请让队长登录后在弹窗点「同意授权」', 'warning');
        return;
      }
      if (!window.confirm('以「' + t.name + '」战队超管身份进入管理中心？\n\n' + '· 协作期间只能操作管理中心\n' + '· 每一步管理操作都会写入该站操作日志\n' + '· 队长可随时撤销授权，撤销后立即失效')) return;
      try {
        const json = await api('/api/platform/admin/tenants/' + t.id + '/support', {
          method: 'POST'
        });
        if (json.code === 0) {
          location.href = json.data.url;
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };

    // v5.36.32：确认码是一枚 5 分钟就过期的东西，页面上要跟着数，别让人拿着作废的码反复点
    useEffect(function () {
      if (!purgeBox || !purgeBox.token) {
        return;
      }
      const timer = setInterval(function () {
        setPurgeBox(function (p) {
          if (!p || !p.token) {
            return p;
          }
          return Object.assign({}, p, {
            left: Math.max(0, Number(p.left || 0) - 1)
          });
        });
      }, 1000);
      return function () {
        clearInterval(timer);
      };
    }, [purgeBox && purgeBox.token]);
    const openPurge = async function (t) {
      setPurgeBox({
        t: t,
        asking: true
      });
      let json = null;
      try {
        json = await api('/api/platform/admin/tenants/' + t.id + '/purge-preview', {
          method: 'POST'
        });
      } catch (e) {
        setPurgeBox({
          t: t,
          asking: false,
          err: '预览这一步没走通：刷新这一页再试一次（没有确认码本来就删不了）'
        });
        return;
      }
      if (!json || json.code !== 0) {
        setPurgeBox({
          t: t,
          asking: false,
          err: json && json.msg || '预览失败，销毁中止'
        });
        return;
      }
      const d = json.data || {};
      setPurgeBox({
        t: t,
        asking: false,
        pre: d.preview || {},
        token: String(d.token || ''),
        left: Number(d.expires_in || 0),
        slug: String(d.confirm_slug || ''),
        days: Number(d.days || 0),
        after: Number(d.after_days || 15),
        typed: '',
        busy: false,
        err: ''
      });
    };
    const doPurge = async function () {
      const b = purgeBox;
      if (!b || !b.token || b.busy) {
        return;
      }
      if (b.typed !== b.slug) {
        toast('要把站点目录名逐字打一遍：' + b.slug, 'error');
        return;
      }
      setPurgeBox(Object.assign({}, b, {
        busy: true
      }));
      let json = null;
      try {
        json = await api('/api/platform/admin/tenants/' + b.t.id + '/purge', {
          method: 'POST',
          body: {
            token: b.token,
            confirm_slug: b.typed
          }
        });
      } catch (e) {
        // 请求没回来不等于没删：先把列表拉一遍，别让这一页停在一个已经不存在的站点上
        setPurgeBox(null);
        loadTenants();
        toast('这一步没走通。列表已刷新——上面那行里还有这个站点，就是没删成（确认码已作废，要重来一遍预览）', 'error');
        return;
      }
      if (!json || json.code !== 0) {
        setPurgeBox(Object.assign({}, b, {
          busy: false,
          token: '',
          left: 0,
          typed: '',
          err: json && json.msg || '销毁中止（确认码过期或现状变了，重新预览一次再看）'
        }));
        loadTenants();
        return;
      }
      const st = json.data || {};
      setPurgeBox({
        t: b.t,
        asking: false,
        done: json.msg || '已销毁',
        stat: {
          rows: st.rows,
          tables: st.tables,
          files: st.files,
          bytes: st.file_bytes,
          backup: st.backup
        }
      });
      loadTenants();
      toast(json.msg || '已销毁', 'success');
    };

    // v5.1.0：给战队管理员发通知
    const sendNotice = async function () {
      if (!noticeText.trim()) {
        toast('请填写通知内容', 'error');
        return;
      }
      try {
        const json = await api('/api/platform/admin/tenants/' + notifyT.id + '/notify', {
          method: 'POST',
          body: {
            content: noticeText.trim()
          }
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setNotifyT(null);
          setNoticeText('');
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    const STATUS_CHIP = {
      pending: ['审核中', 'warning'],
      approved: ['已开通', 'success'],
      rejected: ['已驳回', 'error'],
      banned: ['已封禁', 'error'],
      paused: ['已暂停', 'warning'],
      transferring: ['转移中', 'info']
    };
    return /*#__PURE__*/React.createElement(Box, null, tab === 'showcase' && /*#__PURE__*/React.createElement(PlatShowcase, null), tab === 'chat' && /*#__PURE__*/React.createElement(PlatChatGov, null), tab === 'apps' && (apps === null ? /*#__PURE__*/React.createElement(LoadingBox, null) : apps.length === 0 ? /*#__PURE__*/React.createElement(EmptyBox, {
      text: "\u6682\u65E0\u5165\u9A7B\u7533\u8BF7"
    }) : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, null, "\u7533\u8BF7\u4EBA"), /*#__PURE__*/React.createElement(TableCell, null, "\u7AD9\u70B9\u76EE\u5F55"), /*#__PURE__*/React.createElement(TableCell, null, "\u8D85\u7BA1\u8D26\u53F7"), /*#__PURE__*/React.createElement(TableCell, null, "\u622A\u56FE / logo"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u7533\u8BF7\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, apps.map(function (a) {
      const chip = STATUS_CHIP[a.status] || [a.status, 'default'];
      return /*#__PURE__*/React.createElement(TableRow, {
        key: a.id
      }, /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        fontWeight: 600
      }, a.name), a.description && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          maxWidth: 180
        }
      }, a.description)), /*#__PURE__*/React.createElement(TableCell, null, a.applicant_name), /*#__PURE__*/React.createElement(TableCell, null, "/", a.slug, "/"), /*#__PURE__*/React.createElement(TableCell, null, a.admin_username), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.8
      }, a.screenshot && /*#__PURE__*/React.createElement(Box, {
        component: "img",
        src: a.screenshot,
        alt: "\u622A\u56FE",
        sx: {
          width: 64,
          height: 48,
          objectFit: 'cover',
          borderRadius: '6px',
          cursor: 'zoom-in'
        },
        onClick: () => window.open(a.screenshot, '_blank')
      }), a.logo && /*#__PURE__*/React.createElement(Box, {
        component: "img",
        src: a.logo,
        alt: "logo",
        sx: {
          width: 48,
          height: 48,
          objectFit: 'cover',
          borderRadius: '6px',
          cursor: 'zoom-in'
        },
        onClick: () => window.open(a.logo, '_blank')
      }))), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: chip[0],
        color: chip[1],
        variant: a.status === 'pending' ? 'filled' : 'outlined'
      }), a.status === 'rejected' && a.reject_reason && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          maxWidth: 140
        }
      }, a.reject_reason)), /*#__PURE__*/React.createElement(TableCell, null, fmtTime(a.created_at)), /*#__PURE__*/React.createElement(TableCell, null, a.status === 'pending' ? /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.8
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "contained",
        onClick: () => approve(a)
      }, "\u901A\u8FC7"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: "error",
        onClick: () => {
          setRejectApp(a);
          setReason('');
        }
      }, "\u9A73\u56DE")) : '-'));
    }))))), tab === 'tenants' && (tenants === null ? /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)) : tenants.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "info"
    }, "\u6682\u65E0\u5DF2\u5F00\u901A\u7AD9\u70B9") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "ID"), /*#__PURE__*/React.createElement(TableCell, null, "\u6218\u961F"), /*#__PURE__*/React.createElement(TableCell, null, "\u7AD9\u70B9\u76EE\u5F55"), /*#__PURE__*/React.createElement(TableCell, null, "\u7533\u8BF7\u4EBA"), /*#__PURE__*/React.createElement(TableCell, null, "\u6210\u5458"), /*#__PURE__*/React.createElement(TableCell, null, "\u5B58\u50A8"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u5C01\u7981\u4E0E\u9500\u6BC1"), /*#__PURE__*/React.createElement(TableCell, null, "\u5DE1\u67E5"), /*#__PURE__*/React.createElement(TableCell, null, "\u5E73\u53F0\u534F\u4F5C"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, tenants.map(function (t) {
      const chip = STATUS_CHIP[t.status] || [t.status, 'default'];
      const pct = t.storage_quota > 0 ? Math.round(t.storage_used / t.storage_quota * 100) : 0;
      return /*#__PURE__*/React.createElement(TableRow, {
        key: t.id
      }, /*#__PURE__*/React.createElement(TableCell, null, t.id), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "body2",
        fontWeight: 600
      }, t.name), /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u8D85\u7BA1\uFF1A", t.admin_username), t.transfer && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "warning.main",
        sx: {
          display: 'block'
        }
      }, t.transfer.status === 'cooling' ? '转移冷静期至 ' + fmtTime(t.transfer.cooling_until) : '转移待 ' + t.transfer.to_name + ' 确认')), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Link, {
        href: t.url,
        target: "_blank",
        underline: "hover"
      }, t.url)), /*#__PURE__*/React.createElement(TableCell, null, t.applicant_name), /*#__PURE__*/React.createElement(TableCell, null, t.member_count), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption"
      }, fmtBytes(t.storage_used), " / ", fmtBytes(t.storage_quota), "\uFF08", pct, "%\uFF09"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        sx: {
          display: 'block',
          p: 0,
          minWidth: 0
        },
        onClick: () => {
          setQuotaT(t);
          setQuotaGb(t.storage_quota > 0 ? (t.storage_quota / 1073741824).toString() : '');
        }
      }, "\u4FEE\u6539\u914D\u989D")), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: chip[0],
        color: chip[1],
        variant: "outlined"
      })), /*#__PURE__*/React.createElement(TableCell, null, !t.purge ? /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u8FD9\u4E00\u7248\u8FD8\u6CA1\u8FD9\u4E00\u9879") : !t.purge.banned ? /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u672A\u5C01\u7981") : /*#__PURE__*/React.createElement(Stack, {
        spacing: 0.2
      }, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          whiteSpace: 'nowrap'
        }
      }, t.purge.banned_at ? '封于 ' + t.purge.banned_at : '没有封禁起点'), t.purge.ready ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "error",
        variant: "outlined",
        label: '满 ' + t.purge.days + ' 天，可销毁',
        sx: {
          height: 20,
          fontSize: 11,
          alignSelf: 'flex-start'
        }
      }) : /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "warning.main",
        sx: {
          display: 'block',
          maxWidth: 130
        }
      }, t.purge.reason))), /*#__PURE__*/React.createElement(TableCell, null, t.stealth_active > 0 ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "warning",
        variant: "outlined",
        label: '活跃登记 ' + t.stealth_active
      }) : /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary"
      }, "\u65E0")), /*#__PURE__*/React.createElement(TableCell, null, t.support_granted ? /*#__PURE__*/React.createElement(Stack, {
        spacing: 0.3
      }, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: "success",
        variant: "outlined",
        label: "\u5DF2\u6388\u6743"
      }), t.support_granted_at && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block',
          maxWidth: 130
        }
      }, fmtTime(t.support_granted_at)), t.support_sessions > 0 && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "warning.main"
      }, "\u5728\u7528 ", t.support_sessions, " \u4E2A\u4F1A\u8BDD")) : /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        variant: "outlined",
        label: "\u672A\u6388\u6743"
      })), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.5,
        flexWrap: "wrap",
        useFlexGap: true
      }, t.status === 'approved' ? /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: "error",
        onClick: () => toggleTenant(t)
      }, "\u5C01\u7981") : /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        onClick: () => toggleTenant(t)
      }, "\u6062\u590D"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        disabled: !t.support_granted,
        onClick: () => enterSupport(t)
      }, "\u8FDB\u5165\u534F\u4F5C"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        onClick: () => stealthView(t)
      }, "\u524D\u53F0\u6D4F\u89C8"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        onClick: () => openInspect(t)
      }, "\u5DE1\u67E5\u53F0\u8D26"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: "warning",
        onClick: () => revokeStealth(t)
      }, "\u64A4\u9500\u5DE1\u67E5"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        onClick: () => {
          setNotifyT(t);
          setNoticeText('');
        }
      }, "\u53D1\u901A\u77E5"), /*#__PURE__*/React.createElement(Tooltip, {
        title: t.purge ? t.purge.ready ? '封满 15 天，可以销毁' : t.purge.reason : '这一版的库还没补，先执行升级脚本'
      }, /*#__PURE__*/React.createElement(Box, {
        component: "span",
        sx: {
          display: 'inline-block'
        }
      }, /*#__PURE__*/React.createElement(Button, {
        size: "small",
        variant: "outlined",
        color: "error",
        disabled: !(t.purge && t.purge.ready),
        onClick: () => openPurge(t)
      }, "\u9500\u6BC1\u7AD9\u70B9"))))));
    }))))), tab === 'rotation' && /*#__PURE__*/React.createElement(PlatformRotation, null), tab === 'battles' && /*#__PURE__*/React.createElement(PlatformBattles, null), tab === 'tournament' && /*#__PURE__*/React.createElement(PlatformTournament, null), tab === 'datatasks' && /*#__PURE__*/React.createElement(PlatformDataTasks, null), tab === 'bot' && /*#__PURE__*/React.createElement(PlatformBot, null), tab === 'ai' && /*#__PURE__*/React.createElement(PlatAi, null), tab === 'compliance' && (typeof window.PlatformCompliance === 'function' ? React.createElement(window.PlatformCompliance) : /*#__PURE__*/React.createElement(Alert, {
      severity: "warning"
    }, "\u5408\u89C4\u9762\u677F\u672A\u52A0\u8F7D\uFF0C\u8BF7\u786E\u8BA4 compliance-admin.jsx \u5DF2\u90E8\u7F72\u5E76\u5F3A\u5236\u5237\u65B0\uFF08Ctrl+F5\uFF09")), tab === 'audits' && /*#__PURE__*/React.createElement(PlatformStealthAudits, null), tab === 'loginlogs' && /*#__PURE__*/React.createElement(PlatformLoginLogs, null), tab === 'logclean' && /*#__PURE__*/React.createElement(PlatformLogCleanup, null), tab === 'passport' && /*#__PURE__*/React.createElement(PlatPassport, null), tab === 'mail' && /*#__PURE__*/React.createElement(PlatformMailBlast, null), tab === 'notice' && /*#__PURE__*/React.createElement(PlatNotice, null), tab === 'remind' && /*#__PURE__*/React.createElement(PlatRemind, null), tab === 'forumapi' && /*#__PURE__*/React.createElement(PlatForumApi, null), tab === 'active' && /*#__PURE__*/React.createElement(PlatActive, null), tab === 'purged' && /*#__PURE__*/React.createElement(PlatformPurged, null), /*#__PURE__*/React.createElement(Dialog, {
      open: !!purgeBox,
      onClose: function () {
        if (!purgeBox || !purgeBox.busy) {
          setPurgeBox(null);
        }
      },
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u9500\u6BC1\u7AD9\u70B9\u300C", purgeBox && purgeBox.t ? purgeBox.t.name : '', "\u300D\uFF1F"), /*#__PURE__*/React.createElement(DialogContent, {
      dividers: true
    }, purgeBox && purgeBox.asking && /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 3
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null)), purgeBox && purgeBox.done && /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "success",
      sx: {
        mb: 1
      }
    }, purgeBox.done), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        lineHeight: '22px'
      }
    }, "\u5220\u6389 ", purgeBox.stat.rows, " \u884C\uFF08", purgeBox.stat.tables, " \u5F20\u8868\uFF09\u3001\u56FE\u7247 ", purgeBox.stat.files, " \u5F20\uFF08", fmtBytes(purgeBox.stat.bytes), "\uFF09\u3002", /*#__PURE__*/React.createElement("br", null), "\u5907\u4EFD\u5305 ", /*#__PURE__*/React.createElement(Box, {
      component: "span",
      sx: {
        fontFamily: 'ui-monospace, Menlo, monospace',
        fontSize: 12
      }
    }, purgeBox.stat.backup), " \u5DF2\u7ECF\u5B58\u597D\uFF0C \u5728\u5DE6\u8FB9\u300C\u5DF2\u9500\u6BC1\u7AD9\u70B9\u300D\u90A3\u4E00\u9875\u80FD\u4E0B\u8F7D\u3002", /*#__PURE__*/React.createElement("br", null), "\u8DDF\u522B\u961F\u7684\u7EA6\u6218\u7559\u7740\uFF0C\u5BF9\u9762\u90A3\u4E00\u4FA7\u7684\u961F\u540D\u663E\u793A\u6210\u300C\u5DF2\u6CE8\u9500\u6218\u961F\u300D\u3002")), purgeBox && purgeBox.err && !purgeBox.pre && /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "warning"
    }, purgeBox.err), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        color: TEXT_SECONDARY,
        mt: 1
      }
    }, "\u6CA1\u8D70\u5230\u300C\u9884\u89C8\u300D\u8FD9\u4E00\u6B65\u5C31\u6CA1\u6709\u786E\u8BA4\u7801\uFF0C\u4E5F\u5C31\u5220\u4E0D\u4E86\u2014\u2014\u8FD9\u4E00\u9897\u94AE\u6309\u4E0B\u53BB\u4E0D\u4F1A\u4F24\u5230\u4EFB\u4F55\u6570\u636E\u3002")), purgeBox && purgeBox.pre && /*#__PURE__*/React.createElement(Box, null, purgeBox.err && /*#__PURE__*/React.createElement(Alert, {
      severity: "error",
      sx: {
        mb: 1
      }
    }, purgeBox.err), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13,
        color: TEXT_PRIMARY,
        mb: 1
      }
    }, "\u5DF2\u7ECF\u5C01\u4E86 ", purgeBox.days, " \u5929\uFF08\u6EE1 ", purgeBox.after, " \u5929\u624D\u8BA9\u5220\uFF09\u3002\u4E0B\u9762\u8FD9\u4E9B\u662F\u5168\u90FD\u8981\u6CA1\u7684\u4E1C\u897F\uFF1A"), /*#__PURE__*/React.createElement(Box, {
      sx: {
        p: 1.25,
        mb: 1,
        borderRadius: '12px',
        bgcolor: BG_GRAY,
        border: '1px solid ' + CARD_BORDER
      }
    }, /*#__PURE__*/React.createElement(Stack, {
      spacing: 0.4
    }, /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13
      }
    }, "\u6210\u5458 ", purgeBox.pre.member_count, " \u4EBA"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13
      }
    }, "\u6570\u636E ", purgeBox.pre.row_count, " \u884C\uFF0C\u644A\u5728 ", purgeBox.pre.table_count, " \u5F20\u8868\u4E0A"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 13
      }
    }, "\u56FE\u7247 ", purgeBox.pre.file_count, " \u5F20\uFF08\u76D8\u4E0A\u8FD8\u5728\u7684 ", purgeBox.pre.file_present, " \u5F20\uFF0C\u5171 ", fmtBytes(purgeBox.pre.bytes), "\uFF09"), Number(purgeBox.pre.battles_kept) > 0 && /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        color: TEXT_SECONDARY
      }
    }, "\u53E6\u6709 ", purgeBox.pre.battles_kept, " \u573A\u8DE8\u961F\u7EA6\u6218\u7559\u5728\u5E73\u53F0\u53F0\u8D26\u91CC\u4E0D\u5220\uFF0C\u5BF9\u9762\u770B\u5230\u7684\u961F\u540D\u662F\u300C\u5DF2\u6CE8\u9500\u6218\u961F\u300D"))), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12.5,
        color: TEXT_SECONDARY,
        mb: 1
      }
    }, "\u5220\u4E4B\u524D\u5148\u6253\u4E00\u4EFD\u5907\u4EFD\u5305\uFF08\u6253\u4E0D\u51FA\u6765\u5C31\u4E00\u6761\u90FD\u4E0D\u5220\uFF09\uFF0C\u7136\u540E\u8FDE\u56FE\u7247\u4E00\u8D77\u7269\u7406\u5220\u5E72\u51C0\u2014\u2014\u8FD9\u4E00\u6B65\u4E0D\u80FD\u64A4\u9500\u3002 \u8FD8\u6CA1\u7ED9\u961F\u5458\u53D1\u544A\u77E5\u4FE1\u7684\u8BDD\uFF0C\u73B0\u5728\u53BB\u300C\u90AE\u4EF6\u7FA4\u53D1\u300D\u52FE\u4E0A\u300C\u5E26\u4E0A\u88AB\u5C01\u7981\u7684\u7AD9\u70B9\u300D\u53D1\u4E00\u5C01\u3002"), /*#__PURE__*/React.createElement(TextField, {
      size: "small",
      fullWidth: true,
      label: '逐字输入站点目录名（/' + purgeBox.slug + '/）',
      value: purgeBox.typed,
      disabled: purgeBox.busy,
      autoFocus: true,
      onChange: function (e) {
        setPurgeBox(Object.assign({}, purgeBox, {
          typed: e.target.value
        }));
      },
      helperText: purgeBox.token ? '确认码 ' + purgeBox.left + ' 秒后过期，过期就得重新点一次预览' : '确认码已经不在了：点下面「重新预览」拿一枚新的'
    }))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setPurgeBox(null);
      },
      disabled: purgeBox && purgeBox.busy
    }, purgeBox && purgeBox.done ? '知道了' : '取消'), purgeBox && purgeBox.pre && !purgeBox.done && /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      disabled: purgeBox.busy,
      onClick: function () {
        openPurge(purgeBox.t);
      }
    }, purgeBox.token ? '重新预览' : '重新预览拿新码'), purgeBox && purgeBox.pre && !purgeBox.done && /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      color: "error",
      disabled: purgeBox.busy || !purgeBox.token || purgeBox.left <= 0 || purgeBox.typed !== purgeBox.slug,
      onClick: doPurge
    }, purgeBox.busy ? '正在销毁…' : '确认销毁'))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!rejectApp,
      onClose: () => setRejectApp(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u9A73\u56DE\u5165\u9A7B\u7533\u8BF7"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        mb: 1.5
      }
    }, "\u9A73\u56DE\u300C", rejectApp && rejectApp.name, "\u300D\u540E\uFF0C\u7533\u8BF7\u4EBA\u767B\u5F55\u5E73\u53F0\u53EF\u770B\u5230\u9A73\u56DE\u539F\u56E0"), /*#__PURE__*/React.createElement(TextField, {
      label: "\u9A73\u56DE\u539F\u56E0",
      value: reason,
      onChange: e => setReason(e.target.value),
      inputProps: {
        maxLength: 200
      },
      fullWidth: true,
      size: "small",
      autoFocus: true
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setRejectApp(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      color: "error",
      onClick: doReject
    }, "\u786E\u8BA4\u9A73\u56DE"))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!quotaT,
      onClose: () => setQuotaT(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u4FEE\u6539\u5B58\u50A8\u914D\u989D"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        mb: 1.5
      }
    }, "\u7AD9\u70B9\u300C", quotaT && quotaT.name, "\u300D\u5F53\u524D\u5DF2\u7528 ", quotaT ? fmtBytes(quotaT.storage_used) : '-'), /*#__PURE__*/React.createElement(TextField, {
      label: "\u914D\u989D\uFF08GB\uFF09",
      value: quotaGb,
      onChange: e => setQuotaGb(e.target.value),
      helperText: "\u7559\u7A7A\u6216\u586B 0 = \u6062\u590D\u9ED8\u8BA4 2GB",
      fullWidth: true,
      size: "small",
      autoFocus: true
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setQuotaT(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveQuota
    }, "\u4FDD\u5B58"))), /*#__PURE__*/React.createElement(Dialog, {
      open: !!notifyT,
      onClose: () => setNotifyT(null),
      fullWidth: true,
      maxWidth: "xs"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u53D1\u901A\u77E5\u7ED9\u6218\u961F\u7BA1\u7406\u5458"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        mb: 1.5
      }
    }, "\u901A\u77E5\u5C06\u5C55\u793A\u5728\u300C", notifyT && notifyT.name, "\u300D\u6218\u961F\u7CFB\u7EDF\u7BA1\u7406\u4E2D\u5FC3\u7684\"\u5E73\u53F0\u901A\u77E5\"\u680F"), /*#__PURE__*/React.createElement(TextField, {
      label: "\u901A\u77E5\u5185\u5BB9",
      value: noticeText,
      onChange: e => setNoticeText(e.target.value),
      inputProps: {
        maxLength: 500
      },
      multiline: true,
      minRows: 3,
      fullWidth: true,
      size: "small",
      autoFocus: true
    })), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: () => setNotifyT(null)
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: sendNotice
    }, "\u53D1\u9001"))), inspectBox && /*#__PURE__*/React.createElement(PlatformInspectDialog, {
      tenant: inspectBox.t,
      token: inspectBox.token,
      quota: inspectBox.quota,
      onClose: () => setInspectBox(null)
    }));
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
      return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
    } catch (e) {
      return {};
    }
  }
  function storeCollapsed(v) {
    try {
      localStorage.setItem(NAV_SHUT, JSON.stringify(v));
    } catch (e) {/* 存不下就每次全展开 */}
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
    purged: 'M18 7H6v12c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7zm-1.5 12h-9V9h9v10zM15.5 4H13L12 3h-1L10 4H8.5L7 5.5V7h10V5.5L15.5 4z'
  };
  const SUPER_NAV = [['apps', '入驻申请'], ['tenants', '已开通站点'], ['purged', '已销毁站点'], ['active', '站点活跃'], ['showcase', '展示页治理'], ['rotation', '轮换地图'], ['battles', '赛事约战'], ['tournament', '赛事中心'], ['datatasks', '数据审核'], ['chat', '私聊治理'], ['audits', '巡查记录'], ['bot', 'QQ机器人'], ['ai', 'AI 聊天'], ['compliance', 'SEO与合规'], ['passport', '未晞通行证'], ['loginlogs', '登录日志'], ['logclean', '日志清理'], ['mail', '邮件群发'], ['notice', '强制公告'], ['remind', '赛前提醒'], ['forumapi', '论坛对接']].map(function (it) {
    return [it[0], it[1], NAV_ICONS[it[0]]];
  });
  const SUPER_GROUPS = [['apply', '入驻与站点', ['apps', 'tenants', 'purged', 'active', 'showcase', 'rotation']], ['data', '赛事与数据', ['battles', 'tournament', 'datatasks']], ['gov', '社区治理', ['chat', 'audits']], ['set', '平台设置', ['bot', 'ai', 'compliance', 'passport', 'loginlogs', 'logclean', 'mail', 'notice', 'remind', 'forumapi']]];
  const MY_NAV = [['my', '我的入驻与站点', NAV_ICONS.my]];
  const MY_GROUPS = [['own', '我的战队', ['my']]];
  function NavIcon({
    d
  }) {
    return /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      width: "20",
      height: "20",
      fill: "currentColor"
    }, /*#__PURE__*/React.createElement("path", {
      d: d
    }));
  }
  function NavRow({
    it,
    active,
    badge,
    onPick
  }) {
    return /*#__PURE__*/React.createElement(ListItemButton, {
      onClick: function () {
        onPick(it[0]);
      },
      sx: {
        borderRadius: '10px',
        mb: 0.4,
        color: active ? 'primary.main' : '#48484a',
        bgcolor: active ? t => alpha(t.palette.primary.main, 0.1) : 'transparent',
        '&:hover': {
          bgcolor: active ? t => alpha(t.palette.primary.main, 0.14) : '#f5f5f7'
        }
      }
    }, /*#__PURE__*/React.createElement(ListItemIcon, {
      sx: {
        minWidth: 34,
        color: 'inherit'
      }
    }, /*#__PURE__*/React.createElement(NavIcon, {
      d: it[2]
    })), /*#__PURE__*/React.createElement(ListItemText, {
      primary: it[1],
      primaryTypographyProps: {
        fontSize: 14,
        fontWeight: active ? 600 : 400
      }
    }), badge > 0 && /*#__PURE__*/React.createElement(Box, {
      sx: {
        ml: 'auto',
        minWidth: 20,
        height: 18,
        px: 0.6,
        flexShrink: 0,
        borderRadius: '999px',
        bgcolor: 'error.main',
        color: '#fff',
        boxSizing: 'border-box',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 11,
        fontWeight: 700
      }
    }, badge > 99 ? '99+' : badge));
  }
  function EmptyBox({
    text
  }) {
    return /*#__PURE__*/React.createElement(Box, {
      sx: {
        py: 5,
        textAlign: 'center',
        color: 'text.secondary',
        fontSize: 14
      }
    }, text);
  }
  function LoadingBox() {
    return /*#__PURE__*/React.createElement(Box, {
      sx: {
        py: 5,
        textAlign: 'center'
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, {
      size: 28
    }));
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
        scope: c.scope || ''
      });
    };
    const load = useCallback(async function () {
      try {
        const json = await api('/api/platform/admin/passport');
        if (json.code === 0) fill(json.data);else toast(json.msg || '通行证配置加载失败', 'error');
      } catch (e) {
        toast('通行证配置加载失败', 'error');
      }
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    const set = function (k, v) {
      setForm(function (f) {
        const n = Object.assign({}, f);
        n[k] = v;
        return n;
      });
    };
    const save = async function () {
      if (!form) return;
      setBusy(true);
      try {
        const json = await api('/api/platform/admin/passport/save', {
          method: 'POST',
          body: Object.assign({}, form, {
            enabled: form.enabled ? 1 : 0,
            client_secret: secret
          })
        });
        if (json.code === 0) {
          toast(json.msg, 'success');
          setSecret('');
          setProbe(null);
          fill(json.data);
        } else {
          toast(json.msg, 'error');
        }
      } catch (e) {
        toast('保存失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const test = async function () {
      setBusy(true);
      try {
        const json = await api('/api/platform/admin/passport/test', {
          method: 'POST'
        });
        if (json.code === 0) {
          setProbe(json.data);
          toast(json.data.message || '检测完成', 'success');
        } else toast(json.msg, 'error');
      } catch (e) {
        toast('检测失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const copy = function (text) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          toast('已复制', 'success');
        });
      } else {
        toast('复制失败，请手动选中', 'warning');
      }
    };
    if (form === null || cfg === null) {
      return /*#__PURE__*/React.createElement(Paper, {
        sx: {
          p: 3,
          textAlign: 'center'
        }
      }, /*#__PURE__*/React.createElement(CircularProgress, {
        size: 26
      }));
    }
    const probeRows = [['discovery', 'OIDC 配置'], ['jwks', 'JWKS 公钥'], ['authorize', '授权端点'], ['userinfo', '用户信息端点']];
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        }
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "h6",
      fontWeight: 700
    }, "\u672A\u665E\u901A\u884C\u8BC1"), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        mt: 0.8,
        mb: 2,
        lineHeight: 1.7
      }
    }, "\u7ED9\u6210\u5458\u548C\u5165\u9A7B\u961F\u957F\u591A\u4E00\u79CD\u767B\u5F55\u65B9\u5F0F\u3002\u5F00\u542F\u540E\uFF0C\u6218\u961F\u767B\u5F55\u9875\u3001\u6CE8\u518C\u9875\u548C\u5165\u9A7B\u5E73\u53F0\u767B\u5F55\u9875\u90FD\u4F1A\u591A\u51FA\u4E00\u4E2A \u300C\u4F7F\u7528\u672A\u665E\u901A\u884C\u8BC1\u767B\u5F55\u300D\u6309\u94AE\uFF1B\u5E73\u53F0\u4E00\u952E\u767B\u5F55\u53EA\u5BF9\u5DF2\u628A\u901A\u884C\u8BC1\u7ED1\u5230\u6218\u961F\u8D85\u7BA1\u8D26\u53F7\u7684\u5165\u9A7B\u8D26\u53F7\u751F\u6548\u3002 \u539F\u6709\u8D26\u53F7\u5BC6\u7801\u6CE8\u518C\u3001\u767B\u5F55\u3001\u627E\u56DE\u5BC6\u7801\u3001\u6539\u5BC6\u5168\u90E8\u4FDD\u7559\u3002\u5173\u95ED\u540E\u5165\u53E3\u76F4\u63A5\u6D88\u5931\uFF0C\u4E0D\u5F71\u54CD\u5BC6\u7801\u767B\u5F55\u3002"), !cfg.table_ready ? /*#__PURE__*/React.createElement(Alert, {
      severity: "error",
      sx: {
        mb: 2
      }
    }, "passport_links \u8868\u8FD8\u6CA1\u5EFA\uFF0C\u5F00\u542F\u540E\u6210\u5458\u65E0\u6CD5\u5B8C\u6210\u9996\u6B21\u7ED1\u5B9A\u3002\u8BF7\u5148\u5728\u540C\u4E00\u4E2A\u6D4F\u89C8\u5668\u91CC\u6253\u5F00", ' ', /*#__PURE__*/React.createElement(Link, {
      href: "/passport_install_v5366.php",
      target: "_blank",
      rel: "noopener"
    }, "\u901A\u884C\u8BC1\u8865\u5E93\u5DE5\u5177"), "\u70B9\u4E00\u6B21\u6309\u94AE\uFF08\u8DD1\u5B8C\u4F1A\u81EA\u52A8\u5220\u9664\uFF09\u3002") : null, /*#__PURE__*/React.createElement(Stack, {
      spacing: 2,
      sx: {
        maxWidth: 760
      }
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: form.enabled === true,
        onChange: function (e) {
          set('enabled', e.target.checked);
        }
      }),
      label: "\u5F00\u542F\u672A\u665E\u901A\u884C\u8BC1\u767B\u5F55"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u901A\u884C\u8BC1\u7AD9\u70B9\u5730\u5740",
      fullWidth: true,
      value: form.issuer,
      onChange: function (e) {
        set('issuer', e.target.value);
      },
      placeholder: "https://\u4F60\u7684\u901A\u884C\u8BC1\u670D\u52A1\u5730\u5740",
      helperText: "\u53EA\u586B\u7AD9\u70B9\u6839\u5730\u5740\uFF0C\u4E0D\u5E26\u8DEF\u5F84\uFF1B\u6B63\u5F0F\u73AF\u5883\u5FC5\u987B\u662F https"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "Client ID",
      fullWidth: true,
      value: form.client_id,
      onChange: function (e) {
        set('client_id', e.target.value);
      }
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "Client Secret",
      fullWidth: true,
      type: "password",
      value: secret,
      onChange: function (e) {
        setSecret(e.target.value);
      },
      placeholder: cfg.secret_set ? '已保存，留空表示不修改' : '第一次配置必须填写',
      helperText: cfg.secret_set ? '当前密钥：' + cfg.secret_hint + '（只显示末四位）' : '密钥只保存在服务端，页面永远不会回显完整值'
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u56DE\u8C03\u5730\u5740",
      fullWidth: true,
      value: form.redirect_uri,
      onChange: function (e) {
        set('redirect_uri', e.target.value);
      },
      helperText: "\u5FC5\u987B\u4E0E\u901A\u884C\u8BC1\u540E\u53F0\u767B\u8BB0\u7684\u5730\u5740\u9010\u5B57\u4E00\u81F4\uFF0C\u8DEF\u5F84\u56FA\u5B9A\u4E3A /passport/callback"
    }), /*#__PURE__*/React.createElement(Box, {
      sx: {
        p: 1.5,
        borderRadius: 2,
        bgcolor: 'action.hover'
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 0.5
      }
    }, "\u5F53\u524D\u7AD9\u70B9\u5EFA\u8BAE\u586B\u5199\u7684\u56DE\u8C03\u5730\u5740"), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 1,
      alignItems: {
        xs: 'stretch',
        sm: 'center'
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      sx: {
        wordBreak: 'break-all',
        flex: 1,
        fontFamily: 'Consolas, monospace'
      }
    }, cfg.default_callback), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      variant: "outlined",
      onClick: function () {
        copy(cfg.default_callback);
      }
    }, "\u590D\u5236"))), /*#__PURE__*/React.createElement(TextField, {
      label: "Scope",
      fullWidth: true,
      value: form.scope,
      onChange: function (e) {
        set('scope', e.target.value);
      },
      helperText: "\u9ED8\u8BA4 openid profile email\uFF0C\u5FC5\u987B\u5305\u542B openid"
    }), /*#__PURE__*/React.createElement(Box, {
      sx: {
        p: 1.5,
        borderRadius: 2,
        bgcolor: 'action.hover'
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      sx: {
        lineHeight: 1.8
      }
    }, "\u7B2C\u4E00\u6B21\u4F7F\u7528\u901A\u884C\u8BC1\u7684\u6210\u5458\uFF0C\u4F1A\u770B\u5230\u300C\u7ED1\u5B9A\u672C\u7AD9\u8D26\u53F7\u300D\u9875\uFF1A\u8F93\u5165\u81EA\u5DF1\u7684\u672C\u7AD9\u8D26\u53F7\u548C\u5BC6\u7801\u786E\u8BA4\u8EAB\u4EFD\u540E\uFF0C \u8FD9\u679A\u901A\u884C\u8BC1\u624D\u4F1A\u7ED1\u5230\u90A3\u4E2A\u8D26\u53F7\u4E0A\u3002\u4E0D\u4F1A\u81EA\u52A8\u5EFA\u53F7\uFF0C\u4E5F\u4E0D\u4F1A\u6309\u90AE\u7BB1\u731C\u6D4B\u8D26\u53F7\u3002 \u6210\u5458\u4E5F\u53EF\u4EE5\u5728\u300C\u4E2A\u4EBA\u4E2D\u5FC3 \u2192 \u672A\u665E\u901A\u884C\u8BC1\u300D\u91CC\u4E3B\u52A8\u7ED1\u5B9A\u6216\u89E3\u9664\u7ED1\u5B9A\u3002"), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      sx: {
        mt: 1,
        lineHeight: 1.8
      }
    }, "\u5165\u9A7B\u5E73\u53F0\u7684\u4E00\u952E\u767B\u5F55\u590D\u7528\u8FD9\u5C42\u7ED1\u5B9A\uFF1A\u901A\u884C\u8BC1\u5728\u67D0\u652F\u6218\u961F\u7ED1\u6210\u8D85\u7BA1\u540E\uFF0C\u5E73\u53F0\u767B\u5F55\u9875\u53EF\u76F4\u63A5\u8FDB\u5165\u8BE5\u6218\u961F\u5BF9\u5E94\u7684 \u5E73\u53F0\u5165\u9A7B\u8D26\u53F7\uFF1B\u6CA1\u7ED1\u8FC7\u7684\u901A\u884C\u8BC1\u4F1A\u63D0\u793A\u5148\u53BB\u6218\u961F\u4E2A\u4EBA\u4E2D\u5FC3\u7ED1\u5B9A\u3002\u5E73\u53F0\u8D85\u7BA1\u8D26\u53F7\u4E0D\u53C2\u4E0E\u4E00\u952E\u767B\u5F55\u3002")), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1.2
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      disabled: busy,
      onClick: save
    }, busy ? '处理中…' : '保存配置'), /*#__PURE__*/React.createElement(Button, {
      variant: "outlined",
      disabled: busy,
      onClick: test
    }, "\u6D4B\u8BD5\u8FDE\u63A5")))), probe ? /*#__PURE__*/React.createElement(Paper, {
      sx: {
        p: {
          xs: 2,
          md: 3
        },
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 600,
      sx: {
        mb: 1
      }
    }, "\u8FDE\u901A\u6027\u68C0\u6D4B"), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      sx: {
        mb: 2,
        lineHeight: 1.7
      }
    }, probe.message || ''), /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u7AEF\u70B9"), /*#__PURE__*/React.createElement(TableCell, null, "HTTP \u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "\u7ED3\u679C"))), /*#__PURE__*/React.createElement(TableBody, null, probeRows.map(function (row) {
      const p = (probe.probe || {})[row[0]] || {};
      const good = probe.summary && probe.summary[row[0]];
      return /*#__PURE__*/React.createElement(TableRow, {
        key: row[0]
      }, /*#__PURE__*/React.createElement(TableCell, null, row[1]), /*#__PURE__*/React.createElement(TableCell, null, p.error ? p.error : p.status || 0), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        color: good ? 'success' : 'warning',
        label: good ? '正常' : '需检查'
      })));
    }))))) : null);
  }

  // 外壳：桌面固定侧栏，手机收成顶栏菜单按钮 + 临时抽屉（14 个入口塞不进底部格，底部栏会藏功能）
  function ConsoleFrame({
    items,
    groups,
    tab,
    onTab,
    badgeOf,
    roleLabel,
    me,
    onLogout,
    children
  }) {
    const [collapsed, setCollapsed] = useState(getCollapsed);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const isMdUp = useMediaQuery('(min-width:900px)');
    const toggleGroup = function (k) {
      const next = Object.assign({}, collapsed);
      if (next[k]) {
        delete next[k];
      } else {
        next[k] = 1;
      }
      setCollapsed(next);
      storeCollapsed(next);
    };
    const pick = function (keys) {
      const out = [];
      keys.forEach(function (k) {
        const hit = items.filter(function (it) {
          return it[0] === k;
        })[0];
        if (hit) {
          out.push(hit);
        }
      });
      return out;
    };
    const current = items.filter(function (it) {
      return it[0] === tab;
    })[0];
    const go = function (k) {
      onTab(k);
      setDrawerOpen(false);
    };
    const drawerContent = /*#__PURE__*/React.createElement(Box, {
      sx: {
        display: 'flex',
        flexDirection: 'column',
        height: '100%',
        bgcolor: '#fff'
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        px: 2.5,
        pt: 2.5,
        pb: 1.5
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        width: 36,
        height: 36,
        borderRadius: '10px',
        bgcolor: 'primary.main',
        color: '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      width: "20",
      height: "20",
      fill: "currentColor"
    }, /*#__PURE__*/React.createElement("path", {
      d: NAV_ICONS.shield
    }))), /*#__PURE__*/React.createElement(Typography, {
      fontWeight: 700,
      sx: {
        mt: 1.2,
        fontSize: 16,
        color: TEXT_PRIMARY
      },
      noWrap: true
    }, "\u6218\u961F\u5165\u9A7B\u5E73\u53F0"), /*#__PURE__*/React.createElement(Typography, {
      sx: {
        fontSize: 12,
        color: TEXT_SECONDARY,
        mt: 0.3
      }
    }, roleLabel)), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1,
        px: 1.5,
        py: 1,
        overflowY: 'auto'
      }
    }, groups.map(function (g) {
      const rows = pick(g[2]);
      if (rows.length === 0) {
        return null;
      }
      const shut = !!collapsed[g[0]];
      return /*#__PURE__*/React.createElement(Box, {
        key: g[0],
        sx: {
          mt: 1.25
        }
      }, /*#__PURE__*/React.createElement(Box, {
        onClick: function () {
          toggleGroup(g[0]);
        },
        sx: {
          display: 'flex',
          alignItems: 'center',
          px: 1,
          py: 0.5,
          cursor: 'pointer',
          userSelect: 'none'
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 11,
          fontWeight: 700,
          letterSpacing: '.04em',
          color: SUB_TEXT
        }
      }, g[1]), /*#__PURE__*/React.createElement(Box, {
        sx: {
          flex: '1 1 auto'
        }
      }), /*#__PURE__*/React.createElement(Typography, {
        sx: {
          fontSize: 11,
          color: '#c7c7cc',
          lineHeight: '12px',
          transform: shut ? 'rotate(-90deg)' : 'none',
          transition: 'transform .15s ease'
        }
      }, "\u2304")), !shut && rows.map(function (it) {
        return /*#__PURE__*/React.createElement(NavRow, {
          key: it[0],
          it: it,
          active: tab === it[0],
          badge: badgeOf(it[0]),
          onPick: go
        });
      }));
    })), me && /*#__PURE__*/React.createElement(Box, {
      sx: {
        m: 1.5,
        p: 1.2,
        borderRadius: '12px',
        bgcolor: BG_GRAY,
        display: 'flex',
        alignItems: 'center',
        gap: 1.2
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        width: 36,
        height: 36,
        borderRadius: '50%',
        bgcolor: 'primary.main',
        color: '#fff',
        flexShrink: 0,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 15,
        fontWeight: 700
      }
    }, String(me.nickname || '?').slice(0, 1)), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      fontWeight: 600,
      noWrap: true,
      sx: {
        color: TEXT_PRIMARY
      }
    }, me.nickname), /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      noWrap: true,
      sx: {
        display: 'block'
      }
    }, me.role === 'admin' ? '平台超管' : '入驻战队')), /*#__PURE__*/React.createElement(Tooltip, {
      title: "\u9000\u51FA\u767B\u5F55"
    }, /*#__PURE__*/React.createElement(IconButton, {
      size: "small",
      onClick: onLogout
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      width: "18",
      height: "18",
      fill: "#86868b"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M10 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-8v-2h7V5h-7V3zm1 8v2H5.8l2.1 2.1-1.4 1.4L2 12l4.5-4.5 1.4 1.4L5.8 11H11z"
    }))))));
    return /*#__PURE__*/React.createElement(Box, {
      sx: {
        minHeight: '100vh',
        bgcolor: BG_GRAY
      }
    }, /*#__PURE__*/React.createElement(AppBar, {
      position: "fixed",
      elevation: 0,
      sx: {
        zIndex: function (t) {
          return t.zIndex.drawer + 1;
        },
        bgcolor: '#fff',
        color: TEXT_PRIMARY,
        borderBottom: '1px solid ' + CARD_BORDER
      }
    }, /*#__PURE__*/React.createElement(Toolbar, {
      sx: {
        minHeight: '56px !important',
        gap: 1.5,
        px: {
          xs: 1.5,
          md: 3
        }
      }
    }, !isMdUp && /*#__PURE__*/React.createElement(IconButton, {
      size: "small",
      edge: "start",
      sx: {
        color: TEXT_PRIMARY,
        ml: -1
      },
      onClick: function () {
        setDrawerOpen(true);
      }
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      width: "22",
      height: "22",
      fill: "currentColor"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M4 6h16v2H4V6zm0 5h16v2H4v-2zm0 5h16v2H4v-2z"
    }))), /*#__PURE__*/React.createElement(Typography, {
      variant: "h6",
      fontWeight: 700,
      sx: {
        fontSize: 18,
        color: TEXT_PRIMARY,
        whiteSpace: 'nowrap',
        overflow: 'hidden',
        textOverflow: 'ellipsis'
      }
    }, current ? current[1] : '战队入驻平台'), /*#__PURE__*/React.createElement(Box, {
      sx: {
        flex: 1
      }
    }), me && !isMdUp && /*#__PURE__*/React.createElement(Tooltip, {
      title: "\u9000\u51FA\u767B\u5F55"
    }, /*#__PURE__*/React.createElement(IconButton, {
      size: "small",
      onClick: onLogout
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      width: "20",
      height: "20",
      fill: "#86868b"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M10 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-8v-2h7V5h-7V3zm1 8v2H5.8l2.1 2.1-1.4 1.4L2 12l4.5-4.5 1.4 1.4L5.8 11H11z"
    })))), me && isMdUp && /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      color: "text.secondary",
      noWrap: true
    }, me.nickname))), isMdUp && /*#__PURE__*/React.createElement(Drawer, {
      variant: "permanent",
      sx: {
        width: DRAWER_WIDTH,
        flexShrink: 0,
        '& .MuiDrawer-paper': {
          width: DRAWER_WIDTH,
          boxSizing: 'border-box',
          borderRight: '1px solid ' + CARD_BORDER
        }
      },
      open: true
    }, drawerContent), !isMdUp && /*#__PURE__*/React.createElement(Drawer, {
      variant: "temporary",
      open: drawerOpen,
      onClose: function () {
        setDrawerOpen(false);
      },
      ModalProps: {
        keepMounted: true
      },
      sx: {
        '& .MuiDrawer-paper': {
          width: DRAWER_WIDTH,
          boxSizing: 'border-box',
          borderRight: '1px solid ' + CARD_BORDER
        }
      }
    }, drawerContent), /*#__PURE__*/React.createElement(Box, {
      component: "main",
      sx: {
        px: {
          xs: 1.5,
          md: 2.5
        },
        pt: {
          xs: 1.5,
          md: 2.5
        },
        pb: {
          xs: 2.5,
          md: 2.5
        },
        ml: isMdUp ? DRAWER_WIDTH + 'px' : 0,
        mt: '56px'
      }
    }, /*#__PURE__*/React.createElement(Box, {
      sx: {
        maxWidth: 1180,
        mx: 'auto',
        width: '100%'
      }
    }, children)));
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
          if (json.data && json.data.role !== 'admin') {
            setTab('my');
          }
        } catch (e) {
          setLoadErr(true);
        }
      })();
    }, []);
    const logout = async function () {
      try {
        await api('/api/platform/auth/logout', {
          method: 'POST'
        });
      } catch (e) {/* ignore */}
      location.href = '/login';
    };
    if (loadErr) {
      return /*#__PURE__*/React.createElement(Box, {
        sx: {
          p: 3
        }
      }, /*#__PURE__*/React.createElement(Alert, {
        severity: "error"
      }, "\u52A0\u8F7D\u5931\u8D25\uFF0C\u8BF7\u5237\u65B0\u91CD\u8BD5"));
    }
    if (me === null) {
      return /*#__PURE__*/React.createElement(Box, {
        sx: {
          minHeight: '100vh',
          bgcolor: BG_GRAY
        }
      }, /*#__PURE__*/React.createElement(LoadingBox, null));
    }
    const isAdmin = me.role === 'admin';
    return /*#__PURE__*/React.createElement(ConsoleFrame, {
      items: isAdmin ? SUPER_NAV : MY_NAV,
      groups: isAdmin ? SUPER_GROUPS : MY_GROUPS,
      tab: tab,
      onTab: setTab,
      badgeOf: isAdmin ? function (k) {
        return k === 'apps' ? pending : k === 'chat' ? gov : 0;
      } : function () {
        return 0;
      },
      roleLabel: isAdmin ? '平台管理控制台' : '入驻战队中心',
      me: me,
      onLogout: logout
    }, isAdmin ? /*#__PURE__*/React.createElement(SuperConsole, {
      tab: tab,
      onPending: setPending,
      onGov: setGov
    }) : /*#__PURE__*/React.createElement(TeamConsole, null));
  }

  // ==================== 根组件 ====================
  function Root() {
    const [snack, setSnack] = useState({
      open: false,
      msg: '',
      sev: 'info'
    });
    useEffect(function () {
      _toast = function (msg, sev) {
        setSnack({
          open: true,
          msg: msg,
          sev: sev || 'info'
        });
      };
      return function () {
        _toast = function () {};
      };
    }, []);
    const page = MODE === 'login' || MODE === 'register' ? /*#__PURE__*/React.createElement(AuthFrame, {
      mode: MODE
    }, MODE === 'login' ? /*#__PURE__*/React.createElement(LoginPage, null) : /*#__PURE__*/React.createElement(RegisterPage, null)) : /*#__PURE__*/React.createElement(Console, null);
    return /*#__PURE__*/React.createElement(ThemeProvider, {
      theme: theme
    }, /*#__PURE__*/React.createElement(CssBaseline, null), page, /*#__PURE__*/React.createElement(Snackbar, {
      open: snack.open,
      autoHideDuration: 2200,
      anchorOrigin: {
        vertical: 'top',
        horizontal: 'center'
      },
      onClose: () => setSnack(Object.assign({}, snack, {
        open: false
      }))
    }, /*#__PURE__*/React.createElement(Alert, {
      severity: snack.sev,
      variant: "filled",
      sx: {
        minWidth: 240
      }
    }, snack.msg)));
  }
  ReactDOM.createRoot(document.getElementById('root')).render( /*#__PURE__*/React.createElement(Root, null));
})();