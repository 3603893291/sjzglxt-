# 战队助手 APP（WebView 壳 v2.0.0）

一个只管套壳的安卓客户端：打开填战队地址，之后直达站点；额外做一件事——私聊和战队群来消息时手机出通知。
**战队站点一行代码都不用改**，通知完全是 APP 自己按频率去问服务器问出来的。

## 这次为什么是重写

`android-shell/` 旧工程和 `release.keystore` 在机器上已经找不到了，签名证书一丢，Android 就认定新包不是同一个应用：

- 装了旧版战队助手的手机，**必须先卸载再装新包**，直接覆盖安装会报「应用未安装」/签名冲突；
- 卸载会清掉旧壳里存的战队地址，队员装完要重新填一次。

新证书 `release.keystore` + `keystore.properties` 从此一定要备份，丢了下次又是全员重装。
这两个文件和本地 SDK 路径都已写进 `.gitignore`，不会被提交进仓库。

## 顶部那条「和网页糊在一起」是怎么修掉的

第一轮只做了留白，用户回来说「还是挡住，APP 网页跟顶部导航栏融在一起了，分不清哪条是导航栏」。
回头看成因是三条叠在一起，**少一条都还是糊**：

1. **留白本身**。站内顶栏是 `position:fixed`（`app.jsx:12264`，白底 56px），WebView 不留出状态栏高度，
   它就直接顶到时钟底下。监听挂在 WebView 上不够——部分 ROM 的 decor 会先把 insets 消费掉，
   子 view 收到 `top=0`。所以监听挂到 `getWindow().getDecorView()`，并且 padding 取
   `max(systemBars.top, status_bar_height)`：后者读系统尺寸资源，**就算 insets 一次都没分发也一定垫上**。
   ★ **padding 必须给外层容器（`root.setPadding`），不能给 WebView 本身（`webView.setPadding`）。**
   第二版就是栽在这里：chromium 的 `position:fixed` 元素按 WebView 自己的视口定位，
   padding 拦不住它，那条 AppBar 照样滑到最顶上盖进状态栏——用户看到的"变黑了但还是挡着"，
   那个黑色是网页 AppBar 本身，不是留白。改成给父容器加 padding 后 WebView 整体下移，
   `uiautomator` 量到 WebView bounds 顶边从 `[0,0]` 变成 `[0,126]`（截图
   `runtime/app-shots/13-fixed-inset.png`）。
   ★ 早先的实测没抓到这条，是因为**假站顶栏当时是普通流元素而不是 fixed**——测壳必须测 fixed，
   否则等于没测。`tools/fake-site.js` 现在复刻的是站内那套 `position:fixed` + 56px + 内容留顶距。
2. **留白要涂成顶栏的颜色，不是 body 的颜色**。第一轮读的是 `document.body` 背景（`#f5f5f7` 浅灰），
   而站内顶栏是白的——浅灰条压白条，本来就分不出来。现在用
   `document.elementFromPoint(视口中点, 4px)` 往上找第一个有实际背景色的元素，取到的才是顶栏本身。
3. **状态栏图标要按背景亮度自动换深浅**。edge-to-edge 下系统默认给白色图标，白图标压在白色顶栏上，
   时钟和电量直接看不见，那一条就成了糊。现在按亮度在 Android 11+ 走
   `WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS`、Android 8~10 走
   `SYSTEM_UI_FLAG_LIGHT_STATUS_BAR`。

模拟器实测两张对照（`runtime/app-shots/4-whitetopbar.png`、`5-darktopbar.png`）：顶栏白色时状态栏图标变深、
留白变白；顶栏改深色时图标变白、留白跟着变深——证明取色和图标明暗都是动态生效，不是常量兜底。

另外键盘弹出时底部 padding 归零（`adjustResize` 已经缩过窗口，再垫一次导航栏高度就是双重留白），
录入页靠主题里的 `android:windowLightStatusBar` 固定深色图标。

**没能实机的部分**：这台机器只有 Android 10（API 29）镜像且无网下载不了别的，所以 Android 11+ 的
decor 监听分支与 `WindowInsetsController` 那半只能靠代码审查，实测覆盖的是 Android 8~10 分支
和上面第 1、2、3 条的共用逻辑。

## 通知是怎么来的

`NotifyService` 是常驻前台服务，按用户选的频率（默认 **15 秒**）拿 WebView 的 Cookie 去打两个接口：

| 消息 | 用的接口 | 判断新消息的依据 |
| --- | --- | --- |
| 私聊 | `GET /api/chat/dm/conversations` | 每个会话服务端给的 `unread` 比上次轮询变多了 |
| 战队群 | `GET /api/chat/messages?after_id=<游标>` | 本地存的「最后看到第几条」之后有新行 |

**驱动它的是服务自己的轮询循环（`NotifyService.startLoop`），闹钟和 Job 只是兜底。**
这一条来回改了三轮才对，三轮的教训都记在这，别再改回去：

- 第一版：进程内 `ScheduledExecutorService`，失败退避最高能涨到 600 秒。灭屏后请求一失败就变成
  10 分钟一次，现象是"通知根本不来"。
- 第二版：全交给 `AlarmManager.setAndAllowWhileIdle` + `TickReceiver`。结果更糟——**闹钟链是靠每轮跑完
  再排下一次来续的**，而防叠加的 `busy` 标志在重叠时直接 `return`、没续排，一轮慢请求就把链条永久掐断；
  何况广播恰恰是 Doze 和国产 ROM 最先推迟的东西。现象正是"打开 APP 或点立即检查才弹，之后好一阵又死"。
- 第三版（现在这版）：**循环回到前台服务里**，`while (running) { tick(); sleep(period); }`；
  闹钟降成 5 分钟一次的兜底、`PollJobService` 降成 15 分钟一次的看门狗（Job 归系统所有、能穿透进程被杀，
  而且 job 回调是 Android 12+ 少数允许从后台启动前台服务的豁免场景）。
  **WakeLock 必须跨过 sleep**：只在请求期间持锁时，实测灭屏下 15 秒会被拉成约 27 秒——CPU 一睡
  `Thread.sleep` 就跟着漂。锁超时给 `period*2 + 100` 秒，每轮续。
- 退避上限 120 秒；读超时 45 秒（20 秒时站点稍慢就误报"连不上战队站点"）。

灭屏实测（Android 10 模拟器，电源键灭屏、`dumpsys power` 确认 `state=OFF`、服务端打时间戳）：
连续 8 轮请求间隔 **15.08～15.10 秒**，一次不落。

★ 排查"周期不对"之前**先确认设备上存的是哪一档频率**：`poll_seconds` 是持久化的。我有一次按 15 秒
去数 110 秒该有几轮，实际那台机器存的是 60 秒，白怀疑了半小时架构。常驻通知末尾那个「· HH:mm」
就是最后成功检查时间，专给这个用的——**数字停住 = 轮询停了**，不用连 adb 也能分诊。

实测（Android 10 模拟器，`runtime/app-shots/7-screenoff-notif.png`）：按 HOME 退到后台 + 电源键灭屏，
`dumpsys power` 确认 `state=OFF`，50 秒后通知栏里出现私聊通知，`dumpsys alarm` 里挂着
`*walarm*:com.zhandui.app.TICK`（allow-while-idle 标志）——**灭屏期间通知确实自己弹了**，
消息渠道是 HIGH，所以是横幅级、和系统自己的静默通知不是一档。

三处口径是刻意的，改之前先看懂：

1. **绝不碰 `/api/chat/dm/thread`。** 那个接口在返回正文的同时会调 `touchRead()` 推进服务端已读游标，
   APP 一轮询，站内未读红点就被悄悄清空了——人还没看，数字已经没了。私聊正文只用
   `/conversations` 里的 `last_snip`，那是纯读接口，不留副作用。
2. **战队群没有服务端已读位**，所以游标由 APP 自己存，而且**按战队地址分桶**
   （`seen_group|https://站A`）。同一台手机登过两个战队站，共用游标会让一个站吞掉另一个站的未读。
3. **第一次装完不响。** 群聊和私聊第一次拿到基准时只记数、不弹通知，跟站内红点那条规则一致，
   否则新装 APP 会被历史消息糊一屏。

另外三件事：原生请求返回的 `Set-Cookie` 会回写进 WebView 的 cookie jar（站点会轮换 session、
续 `remember_me`，不回写的话两边各拿一份 session，用户聊到一半就掉线）；掉登录时不弹普通通知，
而是把常驻通知改成「登录已过期，点这里重新登录」；**群游标会自愈**——战队站重装或清过聊天记录后
消息 id 会倒退，本地游标就永远比服务器大、群通知静默失效，所以每 10 分钟回读一次最新 id，
发现落后就把游标拉回来（这条在模拟器上用「假站 id 重置」实测过，群通知能恢复）。

## 桌面图标和队名：能做到哪一步

有人提过「设置好战队之后，桌面图标自动换成战队图标、软件名改成队名」。**桌面那两样做不到**：
`android:icon` / `android:label` 是编译期资源，Android 没有运行时改 launcher 图标或应用名的公开 API，
唯一的官方变通是预声明若干 `activity-alias` 再用 `setComponentEnabledSetting` 切换——
本质上还是只能从打进包里的几套里挑。要桌面真的变成各队自己的，只能一个站打一个包
（productFlavors，顺带还能预填地址），而这个 APK 要发给很多家用，不划算。

做到的部分是 `SiteBranding`，同样不动服务器：

- **队名**取自 `GET /api/auth/me` 的 `team_name`（`app/controller/Auth.php:417`，来自 `tenants.name`），
  登录后由轮询顺手拉一次，按战队地址分桶存；
- **徽标本地画**：`tenants.logo` 没有任何接口吐出来，`public/favicon.ico` 又是 ICO 格式、
  `BitmapFactory` 解不了，所以拿队名首字画一块圆角字块——和站内大厅在 logo 为空时的做法一致
  （`view/hall.html:71`）；
- 用在三处：消息通知与常驻通知的 `setLargeIcon`、常驻通知标题（拿到队名后写队名而不是「战队助手」）、
  设置页的标题与副标题。

实测：常驻通知标题变成「暗夜战队」，`dumpsys notification` 里能看到
`android.largeIcon=Icon(typ=BITMAP size=95x95)`，设置页显示「暗」字徽标 + 「暗夜战队 · 设置」
（截图 `runtime/app-shots/12-brand-settings4.png`）。

## 常驻通知不是装饰，换的是存活率

前台服务类型用的是 `remoteMessaging`，**不是** `dataSync`——Android 15 把 `dataSync` 限成每 24 小时最多
跑 6 小时，超了系统直接停服务，表现就是「提醒又不管用了」。

代价如约定：通知栏常驻一条，点开回站内，带「立即检查」和「设置」两个动作。
**改战队地址、开关提醒、调频率、调缩放都在那条通知的「设置」里**，网页里不加任何悬浮按钮——
加了就是在用户投诉的位置上再叠一层遮挡。

小米/华为/vivo/OPPO 这类 ROM 仍可能在锁屏很久后回收进程，所以首次进入会申请忽略电池优化，
设置页脚也写明了「收不到提醒就去系统设置里允许自启动」。这是无推送通道下能做到的上限。

**和微信的差距在哪，说清楚**：微信/钉钉挂的是厂商级推送长连接（小米/华为/OPPO/vivo 各自的系统通道，
国内上不了谷歌 FCM），APP 被杀掉进程照样能收到。本 APP 做不到这一点，因为服务器没有任何推送通道，
也没有设备注册表。要补上需要两件事：服务器加「设备 token 注册 + 有新消息时出站推送」，
以及向各厂商申请推送 AppID。这是独立的一期工作，不在这次的套壳范围内。

## 构建

```
cd android-shell
powershell -ExecutionPolicy Bypass -File build_apk.ps1
```

本机环境（脚本按这套写死的）：

- 无外网，所以全程 `--offline`，依赖只能来自 `~/.gradle` 缓存。缓存里没有就构建失败，不会挂住等下载；
- Gradle 8.13 走 `%USERPROFILE%\.gradle-dists\gradle-8.13`；JDK 21 在 `D:\ja`；SDK 只有 `android-36`；
- **不能用 AndroidX**：缓存里只有 AGP 8.13.0 本身，没有任何 androidx 库。所以工程是纯 framework
  （`android.useAndroidX=false`，Activity / Notification.Builder / Switch 全是系统控件）；
- Java 源码里不写中文，文案一律进 `res/values/strings.xml`，且 `compileOptions` 不指定平台编码——
  这台机器默认是 GBK，中文写进 `.java` 会乱码。

产物 `app/build/outputs/apk/release/app-release.apk`，脚本校验签名后复制成仓库根的 `战队助手.apk`。

## 换图标 / 改名 / 加功能

名称与文案在 `strings.xml`，主色在 `colors.xml`（`#0066cc`，与站内 MUI 的 `PRIMARY` 对齐），
图标是 `mipmap-anydpi-v26` 的自适应矢量图标，因此 `minSdk` 必须 ≥ 26。
`versionCode` 只要递增就能覆盖升级（前提是签名证书没换）。
