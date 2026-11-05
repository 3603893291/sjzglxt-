/* v5.10.0：平台超管「SEO 与合规」面板
 * 通过 window.PlatformCompliance 暴露给 platform.jsx 的 SuperConsole 挂载
 * 子面板：SEO / 政策 / Cookie / Sitemap / 死链 / API 日志 / 图片压缩
 * 依赖：React / MaterialUI；接口全部走 /api/platform/admin/*
 */
(function () {
  'use strict';

  if (!window.React || !window.MaterialUI) return;
  const {
    useState,
    useEffect,
    useCallback
  } = React;
  const {
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
    CircularProgress,
    Divider,
    FormControlLabel,
    Switch,
    Select,
    MenuItem,
    InputLabel,
    FormControl
  } = MaterialUI;
  async function api(url, options) {
    const opts = options || {};
    const init = {
      method: opts.method || 'GET',
      credentials: 'same-origin'
    };
    if (opts.body !== undefined && opts.body !== null) {
      init.headers = {
        'Content-Type': 'application/json'
      };
      init.body = JSON.stringify(opts.body);
    }
    const res = await fetch(url, init);
    if (res.status === 401) {
      location.href = '/login';
      throw new Error('unauthorized');
    }
    let json = null;
    try {
      json = await res.json();
    } catch (e) {}
    if (!json) throw new Error('服务器响应异常');
    return json;
  }

  // 简易 toast：优先用 platform.jsx 全局注册的 window.__toast，退化为 alert
  function toast(msg, sev) {
    if (typeof window.__toast === 'function') {
      window.__toast(msg, sev);
      return;
    }
    if (sev === 'error') alert(msg);
  }
  function fmtTime(s) {
    return s ? String(s).replace('T', ' ').slice(0, 16) : '-';
  }

  // ==================== SEO 子面板 ====================
  function SeoPanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/seo');
        if (j.code === 0) setD(j.data);
      } catch (e) {}
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    if (!d) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    const save = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/seo', {
          method: 'POST',
          body: d
        });
        toast(j.msg || (j.code === 0 ? '已保存' : '失败'), j.code === 0 ? 'success' : 'error');
      } catch (e) {
        toast('网络异常', 'error');
      } finally {
        setBusy(false);
      }
    };
    const upd = function (k) {
      return function (e) {
        setD(Object.assign({}, d, {
          [k]: e.target.value
        }));
      };
    };
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u4EE5\u4E0B\u914D\u7F6E\u5F71\u54CD\u6240\u6709\u5E73\u53F0\u9875\u9762\uFF08\u6839\u8DEF\u5F84\uFF09\u7684 <title> / meta description / Open Graph / Twitter Card\u3002 \u6218\u961F\u7AD9\u70B9\u82E5\u672A\u5355\u72EC\u914D\u7F6E\uFF0C\u5219\u56DE\u843D\u5230\u8FD9\u91CC\u7684\u9ED8\u8BA4\u503C\u3002"), /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u7AD9\u70B9\u6807\u9898\uFF08title\uFF09",
      fullWidth: true,
      required: true,
      value: d.site_title,
      onChange: upd('site_title'),
      helperText: "\u663E\u793A\u5728\u6D4F\u89C8\u5668\u6807\u7B7E\u4E0E\u641C\u7D22\u7ED3\u679C\u6807\u9898\uFF0C\u5EFA\u8BAE \u2264 60 \u5B57\u7B26"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u7AD9\u70B9\u63CF\u8FF0\uFF08meta description\uFF09",
      fullWidth: true,
      multiline: true,
      minRows: 3,
      value: d.site_description,
      onChange: upd('site_description'),
      helperText: '搜索结果标题下方展示。建议 120-160 字符。当前 ' + (d.site_description || '').length + ' 字符'
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5173\u952E\u8BCD\uFF08meta keywords\uFF09",
      fullWidth: true,
      value: d.keywords,
      onChange: upd('keywords'),
      helperText: "\u82F1\u6587\u9017\u53F7\u5206\u9694\u3002Google \u5DF2\u5FFD\u7565\u6B64\u5B57\u6BB5\uFF0C\u4F46\u767E\u5EA6/\u641C\u72D7/360 \u4ECD\u4F1A\u53C2\u8003"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u793E\u4EA4\u5206\u4EAB\u9884\u89C8\u56FE\uFF08og:image\uFF09",
      fullWidth: true,
      value: d.og_image,
      onChange: upd('og_image'),
      placeholder: "https://your-domain.com/storage/uploads/xxx.jpg \u6216 /storage/uploads/xxx.jpg",
      helperText: "QQ/\u5FAE\u4FE1/Facebook/LinkedIn \u5206\u4EAB\u65F6\u663E\u793A\u7684\u5C01\u9762\u56FE\uFF0C\u5EFA\u8BAE 1200\xD7630"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "canonical \u57FA\u7840 URL",
      fullWidth: true,
      value: d.canonical_base,
      onChange: upd('canonical_base'),
      placeholder: "https://your-domain.com",
      helperText: "\u7559\u7A7A\u5219\u6309\u5F53\u524D\u8BF7\u6C42\u63A8\u5BFC\uFF1B\u914D\u7F6E\u540E\u6240\u6709 canonical/og:url \u90FD\u4EE5\u6B64\u4E3A\u524D\u7F00"
    }), /*#__PURE__*/React.createElement(Box, null, d.og_image && /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary"
    }, "\u9884\u89C8\uFF1A"), /*#__PURE__*/React.createElement(Box, {
      component: "img",
      src: d.og_image,
      alt: "og preview",
      sx: {
        maxWidth: 320,
        maxHeight: 180,
        display: 'block',
        mt: 0.5,
        borderRadius: '8px',
        border: '1px solid #e5e5ea'
      },
      onError: function (e) {
        e.target.style.display = 'none';
      }
    }))), /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: save,
      disabled: busy
    }, busy ? '保存中...' : '保存 SEO 配置'), /*#__PURE__*/React.createElement(Button, {
      sx: {
        ml: 1
      },
      onClick: load,
      disabled: busy
    }, "\u91CD\u65B0\u52A0\u8F7D"))));
  }

  // ==================== 隐私政策 / 服务条款 子面板 ====================
  function PoliciesPanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/policies');
        if (j.code === 0) setD(j.data);
      } catch (e) {}
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    if (!d) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    const save = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/policies', {
          method: 'POST',
          body: {
            privacy: d.privacy,
            terms: d.terms
          }
        });
        toast(j.msg || (j.code === 0 ? '已保存' : '失败'), j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) {
        toast('网络异常', 'error');
      } finally {
        setBusy(false);
      }
    };
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u516C\u5F00\u8BBF\u95EE\uFF1A", /*#__PURE__*/React.createElement(Link, {
      href: "/privacy",
      target: "_blank",
      underline: "hover"
    }, "/privacy"), ' · ', /*#__PURE__*/React.createElement(Link, {
      href: "/terms",
      target: "_blank",
      underline: "hover"
    }, "/terms"), "\u3002 \u6218\u961F\u7AD9\u70B9\u53EF\u901A\u8FC7\u81EA\u8EAB\u540E\u53F0\u7684\u76F8\u540C\u5B57\u6BB5\u8986\u76D6\uFF0C\u672A\u8986\u76D6\u65F6\u4F7F\u7528\u8FD9\u91CC\u7684\u9ED8\u8BA4\u503C\u3002 \u7EAF\u6587\u672C\u5B58\u50A8\uFF0C\u524D\u53F0\u8F93\u51FA\u4F1A\u81EA\u52A8\u8F6C\u4E49\u5E76\u4FDD\u7559\u6362\u884C\u3002", /*#__PURE__*/React.createElement("b", null, "\u4FDD\u5B58\u540E policy_version \u4F1A\u66F4\u65B0\uFF0C\u5DF2\u540C\u610F\u7528\u6237\u4F1A\u91CD\u65B0\u5F39\u4E00\u6B21\u6A2A\u5E45\u3002")), d.updated_at && /*#__PURE__*/React.createElement(Typography, {
      variant: "caption",
      color: "text.secondary",
      sx: {
        display: 'block',
        mb: 1
      }
    }, "\u6700\u8FD1\u4FDD\u5B58\uFF1A", fmtTime(d.updated_at)), /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u9690\u79C1\u653F\u7B56\uFF08Privacy Policy\uFF09",
      fullWidth: true,
      multiline: true,
      minRows: 14,
      value: d.privacy,
      onChange: function (e) {
        setD(Object.assign({}, d, {
          privacy: e.target.value
        }));
      },
      helperText: '当前 ' + (d.privacy || '').length + ' / 20000 字符'
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u670D\u52A1\u6761\u6B3E\uFF08Terms of Service\uFF09",
      fullWidth: true,
      multiline: true,
      minRows: 14,
      value: d.terms,
      onChange: function (e) {
        setD(Object.assign({}, d, {
          terms: e.target.value
        }));
      },
      helperText: '当前 ' + (d.terms || '').length + ' / 20000 字符'
    }), /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: save,
      disabled: busy
    }, busy ? '保存中...' : '保存并更新版本号'), /*#__PURE__*/React.createElement(Button, {
      sx: {
        ml: 1
      },
      onClick: load,
      disabled: busy
    }, "\u91CD\u65B0\u52A0\u8F7D"))));
  }

  // ==================== Cookie 同意 子面板 ====================
  function CookiePanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/cookie-consent');
        if (j.code === 0) setD(j.data);
      } catch (e) {}
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    if (!d) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    const save = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/cookie-consent', {
          method: 'POST',
          body: {
            enabled: d.enabled ? '1' : '0',
            text: d.text
          }
        });
        toast(j.msg || (j.code === 0 ? '已保存' : '失败'), j.code === 0 ? 'success' : 'error');
      } catch (e) {
        toast('网络异常', 'error');
      } finally {
        setBusy(false);
      }
    };
    const s = d.stats || {};
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u542F\u7528\u540E\u6240\u6709\u5E73\u53F0\u4E0E\u6218\u961F\u7AD9\u70B9\u9875\u9762\u5E95\u90E8\u4F1A\u51FA\u73B0 Cookie \u540C\u610F\u6A2A\u5E45\uFF1B\u7528\u6237\u9009\u62E9\u4F1A\u8BB0\u5F55\u5230 ", /*#__PURE__*/React.createElement("code", null, "cookie_consent"), " \u8868\u7528\u4E8E\u5408\u89C4\u5BA1\u8BA1\u3002"), /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: !!d.enabled,
        onChange: function (e) {
          setD(Object.assign({}, d, {
            enabled: e.target.checked
          }));
        }
      }),
      label: "\u542F\u7528 Cookie \u540C\u610F\u6A2A\u5E45"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6A2A\u5E45\u63D0\u793A\u6587\u672C",
      fullWidth: true,
      multiline: true,
      minRows: 3,
      value: d.text,
      onChange: function (e) {
        setD(Object.assign({}, d, {
          text: e.target.value
        }));
      },
      helperText: '当前 ' + (d.text || '').length + ' / 500 字符。会自动追加「隐私政策」「服务条款」链接'
    }), /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: save,
      disabled: busy
    }, busy ? '保存中...' : '保存配置'), /*#__PURE__*/React.createElement(Button, {
      sx: {
        ml: 1
      },
      onClick: load,
      disabled: busy
    }, "\u91CD\u65B0\u52A0\u8F7D")), /*#__PURE__*/React.createElement(Divider, null), /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle2",
      fontWeight: 600
    }, "\u7528\u6237\u9009\u62E9\u7EDF\u8BA1"), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Chip, {
      label: '全部同意：' + (s.accepted || 0),
      color: "success",
      variant: "outlined"
    }), /*#__PURE__*/React.createElement(Chip, {
      label: '仅必要：' + (s.necessary_only || 0),
      color: "info",
      variant: "outlined"
    }), /*#__PURE__*/React.createElement(Chip, {
      label: '拒绝：' + (s.rejected || 0),
      color: "default",
      variant: "outlined"
    }), /*#__PURE__*/React.createElement(Chip, {
      label: '合计：' + (s.total || 0),
      color: "primary"
    }))));
  }

  // ==================== Sitemap 子面板 ====================
  function SitemapPanel() {
    const [d, setD] = useState(null);
    const [baseUrl, setBaseUrl] = useState('');
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try {
        const j = await api('/api/platform/admin/sitemap');
        if (j.code === 0) {
          setD(j.data);
          setBaseUrl(j.data.base_url || '');
        }
      } catch (e) {}
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    if (!d) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    const generate = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/sitemap/generate', {
          method: 'POST',
          body: {
            base_url: baseUrl
          }
        });
        toast(j.msg || (j.code === 0 ? '已生成' : '失败'), j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) {
        toast('生成失败：' + e.message, 'error');
      } finally {
        setBusy(false);
      }
    };
    const toggle = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/sitemap/toggle', {
          method: 'POST',
          body: {
            enabled: d.enabled ? '0' : '1'
          }
        });
        toast(j.msg, j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) {
        toast('操作失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u751F\u6210\u540E\u5199\u5165 ", /*#__PURE__*/React.createElement("code", null, "public/sitemap.xml"), "\uFF08\u7D22\u5F15\uFF09\u3001", /*#__PURE__*/React.createElement("code", null, "public/sitemap-platform.xml"), "\u3001", /*#__PURE__*/React.createElement("code", null, "public/sitemap-", '{slug}', ".xml"), "\uFF08\u6BCF\u4E2A\u6218\u961F\u4E00\u4EFD\uFF09\u3002 Nginx/Apache \u4F1A\u76F4\u63A5\u5410\u8FD9\u4E9B\u9759\u6001\u6587\u4EF6\uFF0C\u4E0D\u5360 PHP \u8D44\u6E90\u3002 \u5EFA\u8BAE\uFF1A\u65B0\u6218\u961F\u5165\u9A7B\u6216\u653F\u7B56\u53D8\u66F4\u540E\u624B\u52A8\u91CD\u65B0\u751F\u6210\u4E00\u6B21\uFF1B\u4E5F\u53EF\u7528\u5916\u90E8 cron \u6BCF\u5468\u89E6\u53D1 ", /*#__PURE__*/React.createElement("code", null, "POST /api/platform/admin/sitemap/generate"), "\uFF08\u9700 X-Api-Key\uFF09\u3002"), /*#__PURE__*/React.createElement(Stack, {
      spacing: 2
    }, /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: !!d.enabled,
        onChange: toggle,
        disabled: busy
      }),
      label: "\u542F\u7528 Sitemap \u751F\u6210\uFF08\u5173\u95ED\u540E\u4ECD\u53EF\u4FDD\u7559\u5DF2\u751F\u6210\u6587\u4EF6\uFF09"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u7AD9\u70B9\u6839 URL\uFF08\u53EF\u9009\uFF0C\u7559\u7A7A\u5219\u7528 SEO \u91CC\u7684 canonical_base \u6216\u5F53\u524D\u8BF7\u6C42 Host\uFF09",
      fullWidth: true,
      value: baseUrl,
      onChange: function (e) {
        setBaseUrl(e.target.value);
      },
      placeholder: "https://your-domain.com"
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: generate,
      disabled: busy
    }, busy ? '生成中...' : '立即生成全部 Sitemap'), /*#__PURE__*/React.createElement(Button, {
      onClick: load,
      disabled: busy
    }, "\u5237\u65B0\u72B6\u6001"), d.index && /*#__PURE__*/React.createElement(Button, {
      component: "a",
      href: "/sitemap.xml",
      target: "_blank"
    }, "\u67E5\u770B sitemap.xml")), /*#__PURE__*/React.createElement(Divider, null), /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle2",
      fontWeight: 600
    }, "\u5F53\u524D\u72B6\u6001"), /*#__PURE__*/React.createElement(Stack, {
      spacing: 0.5
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "body2"
    }, "\u542F\u7528\uFF1A", /*#__PURE__*/React.createElement(Chip, {
      size: "small",
      label: d.enabled ? '是' : '否',
      color: d.enabled ? 'success' : 'default'
    })), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2"
    }, "\u6700\u8FD1\u751F\u6210\uFF1A", d.last_generated ? fmtTime(d.last_generated) : '尚未生成'), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2"
    }, "\u89E3\u6790\u5230\u7684\u7AD9\u70B9\u6839 URL\uFF1A", /*#__PURE__*/React.createElement("code", null, d.base_url || '（未配置）')), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2"
    }, "\u7D22\u5F15\u6587\u4EF6\uFF1A", d.index ? d.index.mtime + '（' + d.index.size + ' 字节）' : '不存在'), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2"
    }, "\u5E73\u53F0\u5B50\u56FE\uFF1A", d.platform ? d.platform.mtime + '（' + d.platform.size + ' 字节）' : '不存在'), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2"
    }, "\u6218\u961F\u5B50\u56FE\u6570\u91CF\uFF1A", d.tenant_files))));
  }

  // ==================== 死链检测 子面板 ====================
  function BrokenLinksPanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const [filter, setFilter] = useState({
      tenant_id: -1,
      resolved: '0',
      source_type: '',
      page: 1
    });
    const [addUrl, setAddUrl] = useState('');
    const [addNote, setAddNote] = useState('');
    const [showAdd, setShowAdd] = useState(false);
    const load = useCallback(async function () {
      try {
        const q = new URLSearchParams();
        Object.keys(filter).forEach(function (k) {
          q.set(k, String(filter[k]));
        });
        const j = await api('/api/platform/admin/broken-links?' + q.toString());
        if (j.code === 0) setD(j.data);
      } catch (e) {}
    }, [filter]);
    useEffect(function () {
      load();
    }, [load]);
    const scan = async function () {
      if (!window.confirm('立即扫描全站链接？单次最多 100 URL，可能需要 1-3 分钟')) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/broken-links/scan', {
          method: 'POST',
          body: {
            sources: []
          }
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) {
        toast('扫描失败：' + e.message, 'error');
      } finally {
        setBusy(false);
      }
    };
    const addManual = async function () {
      if (!addUrl.trim()) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/broken-links/add', {
          method: 'POST',
          body: {
            url: addUrl.trim(),
            tenant_id: 0,
            note: addNote.trim()
          }
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) {
          setShowAdd(false);
          setAddUrl('');
          setAddNote('');
          load();
        }
      } catch (e) {
        toast('操作失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const act = async function (id, action) {
      try {
        const url = action === 'remove' ? '/api/platform/admin/broken-links/' + id : '/api/platform/admin/broken-links/' + id + '/' + action;
        const j = await api(url, {
          method: action === 'remove' ? 'DELETE' : 'POST'
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) {
        toast('操作失败', 'error');
      }
    };
    if (!d) return /*#__PURE__*/React.createElement(Box, {
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
    }, "\u6570\u636E\u6E90\uFF1A\u67AA\u7801\u63CF\u8FF0 / \u516C\u544A\u6B63\u6587 / \u653B\u7565\u6B63\u6587\uFF08\u804A\u5929\u6D88\u606F\u9ED8\u8BA4\u4E0D\u626B\uFF0C\u91CF\u5927\uFF09\u3002 \u5355\u6B21\u626B\u63CF\u6700\u591A 100 URL\uFF0CHEAD \u63A2\u6D4B\u8D85\u65F6 8 \u79D2\uFF1B405/501 \u65F6\u56DE\u843D GET\u3002 \u6700\u8FD1\u626B\u63CF\uFF1A", d.last_scan ? fmtTime(d.last_scan) : '尚未扫描'), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mb: 2
      },
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: scan,
      disabled: busy
    }, busy ? '扫描中...' : '立即扫描全站'), /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setShowAdd(true);
      }
    }, "\u624B\u52A8\u68C0\u67E5 URL"), /*#__PURE__*/React.createElement(FormControl, {
      size: "small",
      sx: {
        minWidth: 130
      }
    }, /*#__PURE__*/React.createElement(InputLabel, null, "\u79DF\u6237"), /*#__PURE__*/React.createElement(Select, {
      label: "\u79DF\u6237",
      value: filter.tenant_id,
      onChange: function (e) {
        setFilter(Object.assign({}, filter, {
          tenant_id: Number(e.target.value),
          page: 1
        }));
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: -1
    }, "\u5168\u90E8"), /*#__PURE__*/React.createElement(MenuItem, {
      value: 0
    }, "\u5E73\u53F0"))), /*#__PURE__*/React.createElement(FormControl, {
      size: "small",
      sx: {
        minWidth: 130
      }
    }, /*#__PURE__*/React.createElement(InputLabel, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(Select, {
      label: "\u72B6\u6001",
      value: filter.resolved,
      onChange: function (e) {
        setFilter(Object.assign({}, filter, {
          resolved: e.target.value,
          page: 1
        }));
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u5168\u90E8"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "0"
    }, "\u672A\u89E3\u51B3"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "1"
    }, "\u5DF2\u89E3\u51B3"))), /*#__PURE__*/React.createElement(FormControl, {
      size: "small",
      sx: {
        minWidth: 140
      }
    }, /*#__PURE__*/React.createElement(InputLabel, null, "\u6765\u6E90\u7C7B\u578B"), /*#__PURE__*/React.createElement(Select, {
      label: "\u6765\u6E90\u7C7B\u578B",
      value: filter.source_type,
      onChange: function (e) {
        setFilter(Object.assign({}, filter, {
          source_type: e.target.value,
          page: 1
        }));
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u5168\u90E8"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "gun_code"
    }, "\u67AA\u7801"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "announcement"
    }, "\u516C\u544A"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "guide"
    }, "\u653B\u7565"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "chat"
    }, "\u804A\u5929"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "manual"
    }, "\u624B\u52A8"))), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      sx: {
        alignSelf: 'center'
      }
    }, "\u5171 ", d.total, " \u6761")), d.list.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "success"
    }, "\u5F53\u524D\u7B5B\u9009\u4E0B\u6CA1\u6709\u6B7B\u94FE\u8BB0\u5F55") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "URL"), /*#__PURE__*/React.createElement(TableCell, null, "\u6765\u6E90"), /*#__PURE__*/React.createElement(TableCell, null, "\u6240\u5C5E"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001\u7801"), /*#__PURE__*/React.createElement(TableCell, null, "\u5931\u8D25\u6B21\u6570"), /*#__PURE__*/React.createElement(TableCell, null, "\u6700\u8FD1\u68C0\u67E5"), /*#__PURE__*/React.createElement(TableCell, null, "\u6807\u8BB0"), /*#__PURE__*/React.createElement(TableCell, null, "\u64CD\u4F5C"))), /*#__PURE__*/React.createElement(TableBody, null, d.list.map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.id
      }, /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          maxWidth: 320
        }
      }, /*#__PURE__*/React.createElement(Link, {
        href: r.url,
        target: "_blank",
        underline: "hover",
        sx: {
          fontSize: 12,
          wordBreak: 'break-all'
        }
      }, r.url), r.error_msg && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "error",
        sx: {
          display: 'block'
        }
      }, r.error_msg)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption"
      }, r.source_type, "#", r.source_id), r.source_ref && /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        color: "text.secondary",
        sx: {
          display: 'block'
        }
      }, r.source_ref)), /*#__PURE__*/React.createElement(TableCell, null, r.tenant_name), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: r.status_code || '连接失败',
        color: r.status_code >= 400 || r.status_code === 0 ? 'error' : 'success',
        variant: "outlined"
      })), /*#__PURE__*/React.createElement(TableCell, null, r.hit_count), /*#__PURE__*/React.createElement(TableCell, null, fmtTime(r.last_check_at)), /*#__PURE__*/React.createElement(TableCell, null, r.resolved ? /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: "\u5DF2\u89E3\u51B3",
        color: "success"
      }) : /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: "\u672A\u89E3\u51B3",
        color: "warning",
        variant: "outlined"
      })), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Stack, {
        direction: "row",
        spacing: 0.5
      }, r.resolved ? /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: function () {
          act(r.id, 'unresolve');
        }
      }, "\u64A4\u9500") : /*#__PURE__*/React.createElement(Button, {
        size: "small",
        onClick: function () {
          act(r.id, 'resolve');
        }
      }, "\u6807\u4E3A\u5DF2\u89E3\u51B3"), /*#__PURE__*/React.createElement(Button, {
        size: "small",
        color: "error",
        onClick: function () {
          if (window.confirm('删除该死链记录？')) act(r.id, 'remove');
        }
      }, "\u5220\u9664"))));
    })))), d.total > d.size && /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mt: 2,
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: d.page <= 1,
      onClick: function () {
        setFilter(Object.assign({}, filter, {
          page: d.page - 1
        }));
      }
    }, "\u4E0A\u4E00\u9875"), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      sx: {
        alignSelf: 'center'
      }
    }, "\u7B2C ", d.page, " / ", Math.ceil(d.total / d.size), " \u9875"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: d.page >= Math.ceil(d.total / d.size),
      onClick: function () {
        setFilter(Object.assign({}, filter, {
          page: d.page + 1
        }));
      }
    }, "\u4E0B\u4E00\u9875")), /*#__PURE__*/React.createElement(Dialog, {
      open: showAdd,
      onClose: function () {
        setShowAdd(false);
      },
      fullWidth: true,
      maxWidth: "sm"
    }, /*#__PURE__*/React.createElement(DialogTitle, null, "\u624B\u52A8\u68C0\u67E5\u5916\u90E8 URL"), /*#__PURE__*/React.createElement(DialogContent, null, /*#__PURE__*/React.createElement(Stack, {
      spacing: 2,
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "URL",
      fullWidth: true,
      required: true,
      value: addUrl,
      onChange: function (e) {
        setAddUrl(e.target.value);
      },
      placeholder: "https://example.com"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u5907\u6CE8\uFF08\u53EF\u9009\uFF09",
      fullWidth: true,
      value: addNote,
      onChange: function (e) {
        setAddNote(e.target.value);
      }
    }))), /*#__PURE__*/React.createElement(DialogActions, null, /*#__PURE__*/React.createElement(Button, {
      onClick: function () {
        setShowAdd(false);
      }
    }, "\u53D6\u6D88"), /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: addManual,
      disabled: busy || !addUrl.trim()
    }, "\u68C0\u67E5\u5E76\u5165\u5E93"))));
  }

  // ==================== API 日志 子面板 ====================
  function ApiLogsPanel() {
    const [d, setD] = useState(null);
    const [busy, setBusy] = useState(false);
    const [filter, setFilter] = useState({
      hours: 24,
      result: '',
      scope: '',
      page: 1
    });
    const load = useCallback(async function () {
      try {
        const q = new URLSearchParams();
        Object.keys(filter).forEach(function (k) {
          q.set(k, String(filter[k]));
        });
        const j = await api('/api/platform/admin/api-logs?' + q.toString());
        if (j.code === 0) setD(j.data);
      } catch (e) {}
    }, [filter]);
    useEffect(function () {
      load();
    }, [load]);
    const clearOld = async function () {
      const days = window.prompt('删除多少天前的日志？（1-365）', '30');
      if (!days) return;
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/api-logs/clear', {
          method: 'POST',
          body: {
            days: Number(days)
          }
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
        if (j.code === 0) load();
      } catch (e) {
        toast('操作失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    if (!d) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));
    const s = d.stats || {};
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u8BB0\u5F55\u6240\u6709\u8D70 X-Api-Key \u7684\u79C1\u6709 API \u8C03\u7528\uFF08\u6210\u529F\u4E0E\u9274\u6743\u5931\u8D25\u5747\u8BB0\uFF09\u300290 \u5929\u4EE5\u4E0A\u7684\u65E5\u5FD7\u5EFA\u8BAE\u5B9A\u671F\u6E05\u7406\u3002 \u8FD1 ", s.window_hours || 24, " \u5C0F\u65F6\uFF1A\u6210\u529F ", s.ok || 0, " / \u5931\u8D25 ", s.fail || 0), /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mb: 2
      },
      flexWrap: "wrap",
      useFlexGap: true
    }, /*#__PURE__*/React.createElement(FormControl, {
      size: "small",
      sx: {
        minWidth: 130
      }
    }, /*#__PURE__*/React.createElement(InputLabel, null, "\u65F6\u95F4\u7A97\u53E3"), /*#__PURE__*/React.createElement(Select, {
      label: "\u65F6\u95F4\u7A97\u53E3",
      value: filter.hours,
      onChange: function (e) {
        setFilter(Object.assign({}, filter, {
          hours: Number(e.target.value),
          page: 1
        }));
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: 1
    }, "\u8FD1 1 \u5C0F\u65F6"), /*#__PURE__*/React.createElement(MenuItem, {
      value: 24
    }, "\u8FD1 24 \u5C0F\u65F6"), /*#__PURE__*/React.createElement(MenuItem, {
      value: 168
    }, "\u8FD1 7 \u5929"), /*#__PURE__*/React.createElement(MenuItem, {
      value: 720
    }, "\u8FD1 30 \u5929"))), /*#__PURE__*/React.createElement(FormControl, {
      size: "small",
      sx: {
        minWidth: 120
      }
    }, /*#__PURE__*/React.createElement(InputLabel, null, "\u7ED3\u679C"), /*#__PURE__*/React.createElement(Select, {
      label: "\u7ED3\u679C",
      value: filter.result,
      onChange: function (e) {
        setFilter(Object.assign({}, filter, {
          result: e.target.value,
          page: 1
        }));
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u5168\u90E8"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "1"
    }, "\u6210\u529F"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "0"
    }, "\u5931\u8D25"))), /*#__PURE__*/React.createElement(FormControl, {
      size: "small",
      sx: {
        minWidth: 130
      }
    }, /*#__PURE__*/React.createElement(InputLabel, null, "Scope"), /*#__PURE__*/React.createElement(Select, {
      label: "Scope",
      value: filter.scope,
      onChange: function (e) {
        setFilter(Object.assign({}, filter, {
          scope: e.target.value,
          page: 1
        }));
      }
    }, /*#__PURE__*/React.createElement(MenuItem, {
      value: ""
    }, "\u5168\u90E8"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "platform"
    }, "platform"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "tenant"
    }, "tenant"), /*#__PURE__*/React.createElement(MenuItem, {
      value: "external"
    }, "external"))), /*#__PURE__*/React.createElement(Button, {
      onClick: clearOld,
      disabled: busy
    }, "\u6E05\u7406\u65E7\u65E5\u5FD7"), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      sx: {
        alignSelf: 'center'
      }
    }, "\u5171 ", d.total, " \u6761")), d.list.length === 0 ? /*#__PURE__*/React.createElement(Alert, {
      severity: "success"
    }, "\u5F53\u524D\u7B5B\u9009\u4E0B\u6CA1\u6709\u65E5\u5FD7") : /*#__PURE__*/React.createElement(TableContainer, null, /*#__PURE__*/React.createElement(Table, {
      size: "small"
    }, /*#__PURE__*/React.createElement(TableHead, null, /*#__PURE__*/React.createElement(TableRow, null, /*#__PURE__*/React.createElement(TableCell, null, "\u65F6\u95F4"), /*#__PURE__*/React.createElement(TableCell, null, "Key"), /*#__PURE__*/React.createElement(TableCell, null, "Scope"), /*#__PURE__*/React.createElement(TableCell, null, "\u65B9\u6CD5"), /*#__PURE__*/React.createElement(TableCell, null, "\u8DEF\u5F84"), /*#__PURE__*/React.createElement(TableCell, null, "\u72B6\u6001"), /*#__PURE__*/React.createElement(TableCell, null, "IP"), /*#__PURE__*/React.createElement(TableCell, null, "\u8017\u65F6"))), /*#__PURE__*/React.createElement(TableBody, null, d.list.map(function (r) {
      return /*#__PURE__*/React.createElement(TableRow, {
        key: r.id
      }, /*#__PURE__*/React.createElement(TableCell, null, fmtTime(r.created_at)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption"
      }, "#", r.api_key_id, " ", r.key_prefix, "\u2026")), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: r.scope,
        variant: "outlined"
      })), /*#__PURE__*/React.createElement(TableCell, null, r.method), /*#__PURE__*/React.createElement(TableCell, {
        sx: {
          maxWidth: 280
        }
      }, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption",
        sx: {
          wordBreak: 'break-all'
        }
      }, r.path)), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Chip, {
        size: "small",
        label: r.result ? r.status : r.status + ' ' + r.fail_reason,
        color: r.result ? 'success' : 'error',
        variant: "outlined"
      })), /*#__PURE__*/React.createElement(TableCell, null, /*#__PURE__*/React.createElement(Typography, {
        variant: "caption"
      }, r.ip)), /*#__PURE__*/React.createElement(TableCell, null, r.duration_ms, " ms"));
    })))), d.total > d.size && /*#__PURE__*/React.createElement(Stack, {
      direction: "row",
      spacing: 1,
      sx: {
        mt: 2,
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: d.page <= 1,
      onClick: function () {
        setFilter(Object.assign({}, filter, {
          page: d.page - 1
        }));
      }
    }, "\u4E0A\u4E00\u9875"), /*#__PURE__*/React.createElement(Typography, {
      variant: "body2",
      sx: {
        alignSelf: 'center'
      }
    }, "\u7B2C ", d.page, " / ", Math.ceil(d.total / d.size), " \u9875"), /*#__PURE__*/React.createElement(Button, {
      size: "small",
      disabled: d.page >= Math.ceil(d.total / d.size),
      onClick: function () {
        setFilter(Object.assign({}, filter, {
          page: d.page + 1
        }));
      }
    }, "\u4E0B\u4E00\u9875")));
  }

  // ==================== API 限频 & 图片压缩 合并子面板 ====================
  function RuntimePanel() {
    const [rate, setRate] = useState(null);
    const [img, setImg] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async function () {
      try {
        const [r1, r2] = await Promise.all([api('/api/platform/admin/api-rate'), api('/api/platform/admin/image-compress')]);
        if (r1.code === 0) setRate(r1.data);
        if (r2.code === 0) setImg(r2.data);
      } catch (e) {}
    }, []);
    useEffect(function () {
      load();
    }, [load]);
    if (!rate || !img) return /*#__PURE__*/React.createElement(Box, {
      sx: {
        textAlign: 'center',
        py: 4
      }
    }, /*#__PURE__*/React.createElement(CircularProgress, null));

    // API 限频与战绩限频走同一个接口、同一份 state：接口一次校验并落库全部字段，
    // 所以任一侧保存都提交整表，避免只传一半把另一半重置成后端默认值。
    const saveConfig = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/api-rate', {
          method: 'POST',
          body: Object.assign({}, rate, {
            stats_enabled: rate.stats_enabled ? '1' : '0'
          })
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
      } catch (e) {
        toast('保存失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    const saveImg = async function () {
      setBusy(true);
      try {
        const j = await api('/api/platform/admin/image-compress', {
          method: 'POST',
          body: {
            enabled: img.enabled ? '1' : '0',
            quality: img.quality,
            max_width: img.max_width,
            max_height: img.max_height
          }
        });
        toast(j.msg || '', j.code === 0 ? 'success' : 'error');
      } catch (e) {
        toast('保存失败', 'error');
      } finally {
        setBusy(false);
      }
    };
    return /*#__PURE__*/React.createElement(Stack, {
      spacing: 3
    }, /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 600,
      sx: {
        mb: 1
      }
    }, "API \u9650\u9891\uFF08ApiKeyAuth\uFF09"), /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u53CC\u5C42\u9650\u9891\uFF1A\u6BCF Key \u4E0E\u6BCF IP \u72EC\u7ACB\u8BA1\u6570\u30025 \u5206\u949F\u5185\u7D2F\u8BA1 20 \u6B21\u8BA4\u8BC1\u5931\u8D25\u7684 IP \u4F1A\u88AB\u81EA\u52A8\u9501\u5B9A 15 \u5206\u949F\u3002"), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 2
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u7A97\u53E3\uFF08\u79D2\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: rate.rate_window,
      onChange: function (e) {
        setRate(Object.assign({}, rate, {
          rate_window: Number(e.target.value)
        }));
      },
      helperText: "5 - 3600"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6BCF Key \u4E0A\u9650\uFF08\u6B21/\u7A97\u53E3\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: rate.rate_limit,
      onChange: function (e) {
        setRate(Object.assign({}, rate, {
          rate_limit: Number(e.target.value)
        }));
      },
      helperText: "1 - 10000"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6BCF IP \u4E0A\u9650\uFF08\u6B21/\u7A97\u53E3\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: rate.ip_rate_limit,
      onChange: function (e) {
        setRate(Object.assign({}, rate, {
          ip_rate_limit: Number(e.target.value)
        }));
      },
      helperText: "1 - 100000"
    })), /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveConfig,
      disabled: busy
    }, "\u4FDD\u5B58\u9650\u9891\u914D\u7F6E"))), /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 600,
      sx: {
        mb: 1
      }
    }, "\u6218\u7EE9\u4E0A\u4F20\u9650\u9891\uFF08\u6210\u5458\u81EA\u52A9\u63A5\u53E3\uFF09"), /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u6210\u5458\u7528\u81EA\u5DF1\u7684\u8D26\u53F7\u5BC6\u7801\u767B\u5F55\u540E\u8C03 ", /*#__PURE__*/React.createElement("code", null, "POST /api/stats/upload"), " \u4E0A\u4F20\u6218\u7EE9\uFF0C\u65E0 API Key\uFF0C \u56E0\u6B64\u6309\u300C\u767B\u5F55\u8D26\u53F7 + \u6765\u6E90 IP\u300D\u4E24\u4E2A\u7EF4\u5EA6\u72EC\u7ACB\u8BA1\u6570\u3002\u914D\u7F6E\u5BF9\u5168\u7AD9\u6240\u6709\u6218\u961F\u751F\u6548\uFF0C\u5404\u6218\u961F\u4E0D\u80FD\u81EA\u884C\u8986\u76D6\u3002 \u5173\u95ED\u5F00\u5173\u53EA\u6321\u5199\u5165\uFF0C\u4E0D\u5F71\u54CD\u6210\u5458\u4E0E\u7BA1\u7406\u5458\u67E5\u770B\u5DF2\u6709\u6218\u7EE9\u3002"), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: !!rate.stats_enabled,
        onChange: function (e) {
          setRate(Object.assign({}, rate, {
            stats_enabled: e.target.checked
          }));
        }
      }),
      label: "\u5141\u8BB8\u6210\u5458\u4E0A\u4F20\u6218\u7EE9"
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 2,
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "\u7A97\u53E3\uFF08\u79D2\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: rate.stats_window,
      onChange: function (e) {
        setRate(Object.assign({}, rate, {
          stats_window: Number(e.target.value)
        }));
      },
      helperText: "5 - 3600"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6BCF\u8D26\u53F7\u4E0A\u9650\uFF08\u6B21/\u7A97\u53E3\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: rate.stats_limit,
      onChange: function (e) {
        setRate(Object.assign({}, rate, {
          stats_limit: Number(e.target.value)
        }));
      },
      helperText: "1 - 1000"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6BCF IP \u4E0A\u9650\uFF08\u6B21/\u7A97\u53E3\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: rate.stats_ip_limit,
      onChange: function (e) {
        setRate(Object.assign({}, rate, {
          stats_ip_limit: Number(e.target.value)
        }));
      },
      helperText: "1 - 100000"
    })), /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveConfig,
      disabled: busy
    }, "\u4FDD\u5B58\u6218\u7EE9\u9650\u9891\u914D\u7F6E"))), /*#__PURE__*/React.createElement(Paper, {
      variant: "outlined",
      sx: {
        p: 2
      }
    }, /*#__PURE__*/React.createElement(Typography, {
      variant: "subtitle1",
      fontWeight: 600,
      sx: {
        mb: 1
      }
    }, "\u56FE\u7247\u81EA\u52A8\u538B\u7F29"), !img.gd_loaded && /*#__PURE__*/React.createElement(Alert, {
      severity: "warning",
      sx: {
        mb: 2
      }
    }, "\u5F53\u524D PHP \u672A\u542F\u7528 GD \u6269\u5C55\uFF0C\u65E0\u6CD5\u8FDB\u884C\u670D\u52A1\u7AEF\u538B\u7F29\u3002\u8BF7\u8054\u7CFB\u8FD0\u7EF4\u5B89\u88C5 ", /*#__PURE__*/React.createElement("code", null, "php-gd"), "\u3002"), /*#__PURE__*/React.createElement(Alert, {
      severity: "info",
      sx: {
        mb: 2
      }
    }, "\u542F\u7528\u540E\u6240\u6709\u8D70 ", /*#__PURE__*/React.createElement("code", null, "ImageUpload::save()"), " \u7684\u4E0A\u4F20\uFF08\u6E38\u620F\u56FE\u7247 / \u6CE8\u518C\u622A\u56FE / \u516C\u544A\u914D\u56FE / \u653B\u7565\u5C01\u9762\uFF09\u90FD\u4F1A\u81EA\u52A8\u538B\u7F29\u3002 \u5DF2\u4E0A\u4F20\u7684\u56FE\u7247\u4E0D\u53D7\u5F71\u54CD\uFF1B", /*#__PURE__*/React.createElement("code", null, "?thumb=1"), " \u53C2\u6570\u53EF\u5728\u4E0B\u8F7D\u65F6\u6309\u9700\u751F\u6210\u7F29\u7565\u56FE\uFF08\u4E0D\u6539\u78C1\u76D8\u6587\u4EF6\uFF09\u3002"), /*#__PURE__*/React.createElement(FormControlLabel, {
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: !!img.enabled,
        disabled: !img.gd_loaded,
        onChange: function (e) {
          setImg(Object.assign({}, img, {
            enabled: e.target.checked
          }));
        }
      }),
      label: "\u542F\u7528\u56FE\u7247\u81EA\u52A8\u538B\u7F29"
    }), /*#__PURE__*/React.createElement(Stack, {
      direction: {
        xs: 'column',
        sm: 'row'
      },
      spacing: 2,
      sx: {
        mt: 1
      }
    }, /*#__PURE__*/React.createElement(TextField, {
      label: "JPEG/WebP \u8D28\u91CF\uFF0830-95\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: img.quality,
      onChange: function (e) {
        setImg(Object.assign({}, img, {
          quality: Number(e.target.value)
        }));
      },
      helperText: "\u63A8\u8350 80-85\uFF0C\u8089\u773C\u51E0\u4E4E\u65E0\u5DEE"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6700\u5927\u5BBD\u5EA6\uFF08\u50CF\u7D20\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: img.max_width,
      onChange: function (e) {
        setImg(Object.assign({}, img, {
          max_width: Number(e.target.value)
        }));
      },
      helperText: "\u8D85\u51FA\u7B49\u6BD4\u7F29\u653E\uFF1B320-4096"
    }), /*#__PURE__*/React.createElement(TextField, {
      label: "\u6700\u5927\u9AD8\u5EA6\uFF08\u50CF\u7D20\uFF09",
      type: "number",
      size: "small",
      sx: {
        flex: 1
      },
      value: img.max_height,
      onChange: function (e) {
        setImg(Object.assign({}, img, {
          max_height: Number(e.target.value)
        }));
      },
      helperText: "320-4096"
    })), /*#__PURE__*/React.createElement(Box, {
      sx: {
        mt: 2
      }
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "contained",
      onClick: saveImg,
      disabled: busy || !img.gd_loaded
    }, "\u4FDD\u5B58\u538B\u7F29\u914D\u7F6E"))));
  }

  // ==================== 顶层组件 ====================
  function PlatformCompliance() {
    const [sub, setSub] = useState('seo');
    return /*#__PURE__*/React.createElement(Box, null, /*#__PURE__*/React.createElement(Tabs, {
      value: sub,
      onChange: function (e, v) {
        setSub(v);
      },
      sx: {
        borderBottom: 1,
        borderColor: 'divider',
        mb: 2
      },
      variant: "scrollable",
      scrollButtons: "auto"
    }, /*#__PURE__*/React.createElement(Tab, {
      value: "seo",
      label: "SEO \u5143\u6570\u636E"
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "policies",
      label: "\u9690\u79C1/\u6761\u6B3E"
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "cookie",
      label: "Cookie \u540C\u610F"
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "sitemap",
      label: "Sitemap"
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "broken",
      label: "\u6B7B\u94FE\u68C0\u6D4B"
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "apilogs",
      label: "API \u65E5\u5FD7"
    }), /*#__PURE__*/React.createElement(Tab, {
      value: "runtime",
      label: "\u9650\u9891 / \u538B\u7F29"
    })), sub === 'seo' && /*#__PURE__*/React.createElement(SeoPanel, null), sub === 'policies' && /*#__PURE__*/React.createElement(PoliciesPanel, null), sub === 'cookie' && /*#__PURE__*/React.createElement(CookiePanel, null), sub === 'sitemap' && /*#__PURE__*/React.createElement(SitemapPanel, null), sub === 'broken' && /*#__PURE__*/React.createElement(BrokenLinksPanel, null), sub === 'apilogs' && /*#__PURE__*/React.createElement(ApiLogsPanel, null), sub === 'runtime' && /*#__PURE__*/React.createElement(RuntimePanel, null));
  }
  window.PlatformCompliance = PlatformCompliance;
})();