# THIRD-PARTY / 第三方组件清单

本仓库中由作者原创编写的代码以 **GNU GPL v3.0** 发布（见 `LICENSE.txt`）。
下面这些是随代码一起分发的第三方组件，各自遵循自己的许可证；GPL 允许这样的聚合分发，
但请连同各自的 `LICENSE` 文件一起保留。

## PHP 依赖（`vendor/`，随 composer 安装产物一起提交）

| 组件 | 许可证 | 许可文本所在 |
| --- | --- | --- |
| topthink/framework（ThinkPHP 8 内核） | Apache-2.0 | `vendor/topthink/framework/LICENSE.txt` |
| topthink/think-orm | Apache-2.0 | `vendor/topthink/think-orm/LICENSE` |
| topthink/think-validate | Apache-2.0 | `vendor/topthink/think-validate/LICENSE` |
| topthink/think-container | Apache-2.0 | `vendor/topthink/think-container/LICENSE` |
| topthink/think-helper | Apache-2.0 | `vendor/topthink/think-helper/LICENSE` |
| topthink/think-filesystem | Apache-2.0 | 这个包里没有随附许可文件，许可证取自它的 `composer.json`；分发时建议从上游补一份 |
| league/flysystem | MIT | `vendor/league/flysystem/LICENSE` |
| league/flysystem-cached-adapter | MIT | `vendor/league/flysystem-cached-adapter/LICENSE` |
| league/mime-type-detection | MIT | `vendor/league/mime-type-detection/LICENSE` |
| psr/cache | MIT | `vendor/psr/cache/LICENSE.txt` |
| psr/container | MIT | `vendor/psr/container/LICENSE` |
| psr/http-message | MIT | `vendor/psr/http-message/LICENSE` |
| psr/log | MIT | `vendor/psr/log/LICENSE` |
| psr/simple-cache | MIT | `vendor/psr/simple-cache/LICENSE.md` |

上面每一项的许可证值都是从各自 `composer.json` 的 `license` 字段读出来的，不是手抄的。

## 前端本地库（`public/static/vendor/`）

这份项目不走 CDN，React / MUI 全部放在站点目录里（多租户按目录分站点，CDN 上不去）。

| 文件 | 上游项目 | 上游许可证 | 备注 |
| --- | --- | --- | --- |
| `react.production.min.js` | React | MIT | 文件头带 `@license MIT` |
| `react-dom.production.min.js` | React DOM | MIT | 文件头带 `@license MIT` |
| `material-ui.production.min.js` | Material-UI (MUI) | MIT | 文件头带 `@license MIT` |
| `emotion-react.umd.min.js` | Emotion | MIT | 文件头带许可说明 |
| `emotion-styled.umd.min.js` | Emotion | MIT | **打包件里没有许可头**，取自 Emotion 官方 UMD 构建；分发时请一并保留上游 `LICENSE` |
| `babel.min.js` | Babel Standalone | MIT | **打包件里没有许可头**，取自 Babel 官方 standalone 构建；分发时请一并保留上游 `LICENSE` |

带粗体那两条要留意：这两个 min 文件里没写许可注释，所以本表里的许可证是按上游项目本身认定的。
如果这份仓库要拿去正式分发，建议把这两个文件换成带许可头的官方发布件。

## 素材与图片

| 位置 | 内容 | 说明 |
| --- | --- | --- |
| `public/static/img/login-poster.webp` | 登录页默认海报 | 本项目自用的界面素材 |
| `public/static/img/team-training.webp` | 官网首页配图 | 本项目自用的界面素材 |
| `public/static/meme/1.jpg` ~ `11.jpg` | 成员「每日签到 → 今日运势」随机抽的那 11 张图 | **网络上流传的表情包，不是作者原创**；随 GPL 一起分发等于把它们也按 GPL 授权了。自用没问题，要正式对外分发请换成自己制作的图或已确认授权的素材（`app/controller/Checkin.php` 里就是按这 11 个文件名取的） |
| 各组件目录内的 `LICENSE*` | 第三方许可证原文 | 保留，勿删 |

用户上传的内容（战队图片、成员头像、公告配图、投稿截图）全部落在 `public/storage/uploads/`，
被 `.gitignore` 整目录挡在仓库之外，不会随源码分发。
