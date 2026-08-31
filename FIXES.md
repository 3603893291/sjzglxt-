# FIXES / 版本更新记录

> 本文件记录各版本的功能变更、修复与升级注意事项。最新版本在最上面。

---

## v5.7.1 — 安全加固：v5.7.0 安全审计报告 17 项全部修复（5 高 / 7 中 / 5 低）

> 本版依据《安全审计报告_v5.7.0》逐项修复：认证链路、会话管理、站内纵向越权、
> CSRF、部署形态一次收齐。多租户行级隔离经审计确认健全、未做破坏性改动；
> 新增 1 个权限键（`member_view`）、1 张表（`password_resets`）、1 个字段
> （`users.session_epoch`）、1 个全局中间件（`CsrfGuard`）。

### 高危（H1-H5）

1. **H1 平台登录会话固定**：`Platform::login` 写会话后补 `Session::setId(bin2hex(random_bytes(16)))`
   轮换 Session ID，与战队登录对齐，杜绝"种 ID 劫持平台超管"。
2. **H2 管理端漏挂权限守卫（纵向越权）**：新增权限键 `member_view`（查看成员名单）；
   `Admin::users()`（成员详情：QQ/邮箱/注册IP/归属地）改为 `member_view/approve/ban/
   role_manage/rename/mute/reset_pwd` 任一持有才可访问；`teams()`（全量名单+小队结构）
   收紧为 `team/member_view`；`logs()`（管理轨迹）收紧为**仅超级管理员**；
   零权限管理员账号在管理端入口统一拦截，前端页签可见性改为按权限数组/`__super__` 过滤，
   无任何页签时展示提示而非空白。
3. **H3 改密不踢旧会话**：新增 `users.session_epoch`（密码纪元）。三处改密入口
   （自助改密 / 管理员重置 / 战队主改超管密码）统一：纪元 +1、清该用户全部 7 天免登录令牌；
   `AuthCheck`/`AdminCheck` 每请求比对会话内纪元，不一致即 401 强制下线；
   操作者自己的会话重新盖章保持在线。账号失陷后改密即可把攻击者踢下线。
4. **H4 注册零限流**：战队注册限流"同 IP 每小时 3 次 + 同战队每天 30 次"，
   平台注册/入驻申请按 IP 限流，超限 429；堵住脚本灌存储配额/投毒审核队列。
5. **H5 爆破防护绕过**：新增**账号维度**失败计数（同账号 15 分钟内失败 10 次 →
   锁定 30 分钟，分布式 IP 也锁得住），安全事件写管理日志便于溯源；
   新增**弱口令黑名单**（注册/自助改密/入驻超管/管理员设密码四处生效，
   `qq123456`、`a1234567`、`password123` 一类直接拒绝）。

### 中危（M1-M7）

6. **M1 用户名枚举**：战队登录失败统一返回「账号或密码错误」，不再区分
   "账号未注册 / 密码错误"（内部爆破计数保留），与平台登录口径一致。
7. **M2 隐身巡查令牌进 URL**：`?_sv=` 改为一次性兑票——校验通过立即作废令牌，
   换发 2 小时 `HttpOnly + SameSite=Lax` cookie（path 限战队目录），302 到不带令牌的
   干净地址；令牌不再落入浏览器历史 / Referer / Nginx 与 CDN 日志。
   新增 `GET /api/auth/stealth-exit` 显式退出巡查。
8. **M3 无 CSRF 防护**：新增全局中间件 `CsrfGuard`——写操作（POST/PUT/DELETE/PATCH）
   优先校验 `Sec-Fetch-Site`，回退 `Origin/Referer` 主机名比对，跨站请求 403；
   有副作用的 `GET /api/notices/:id/read`、`GET /api/platform-notices/read` 改为 POST。
9. **M4 Cookie 未 Secure**：`config/cookie.php` 支持 `COOKIE_SECURE` 环境变量开关
   （生产已全站 HTTPS，`.env` 追加 `COOKIE_SECURE = true` 即为所有会话/免登录/巡查
   cookie 启用 Secure）；`deploy/nginx.conf.example` 重写为 80 端口 301 → 443 +
   TLSv1.2/1.3 + HSTS 的 HTTPS 形态。
10. **M5 头像接口单点依赖**：`Avatar` 由 `BaseController` 改挂 `AuthBase`
    （控制器级登录守卫，不再仅依赖路由组中间件）；跨战队遍历本身由模型租户作用域拦截
    （他队 uid 落到文字头像），队内互见头像是聊天/成员列表的功能必需，予以保留。
11. **M6 会话名 slug 撞名**：session cookie 名由"slug 归一化"改为按租户 ID 命名
    （`PHPSESSID_t{id}`），`my-team` 与 `myteam` 不再互顶会话。
12. **M7 API 鉴权静态属性**：`ApiKeyAuth::handle()` 开头显式清零四个静态属性，
    常驻内存部署（Swoole/Workerman）下不再有串权残留风险。

### 低危（L1-L5）

13. **L1 全站无忘记密码**：新增完整找回流程——战队登录页「忘记密码?」入口
    （`/{slug}/forgot`），成员提交 QQ 号 + 新密码 + 游戏主页截图，管理员在
    管理中心 → 注册审核页签审核（通过/驳回，附截图查看与申请 IP）；通过后即用新密码
    登录且旧会话全部失效（联动 H3）。同一账号同时只挂一条待审申请，接口按 IP 限流，
    驳回/覆盖时自动清理孤儿截图。
14. **L2 根路径可调战队认证接口**：`Auth::register/login/forgot` 在平台上下文一律 404，
    不再产生 `tenant_id=0` 的游离账号。
15. **L3 无 .gitignore**：新增，覆盖 `.env`、`/runtime/`、上传目录内容、`*.sqlite`、
    工具目录等，杜绝一次 `git add .` 泄库口令。
16. **L4 误导性注释**：`Image::raw` 注释更正——think-orm 的 `find()` 本身应用租户
    全局作用域，显式 `tenant_id` 校验作为纵深防御保留，两层缺一不可。
17. **L5 上传允许 GIF**：评估后保留（随机文件名 + `X-Content-Type-Options: nosniff` +
    nginx 禁 uploads 下 PHP 执行三重防护在位，实际风险很低）；提示：未来若放开 SVG
    或允许用户控制文件名需重新评估。

### 数据库变更

- `users` 表新增 `session_epoch INT UNSIGNED NOT NULL DEFAULT 0`（密码纪元）；
- 新表 `password_resets`（忘记密码申请：tenant_id+user_id 唯一、新密码哈希、截图、申请 IP）。
- 升级执行 `database/upgrade_v5.7.1.sql`（`ALTER TABLE` 重复执行会报 Duplicate column，
  可忽略；建表为 `IF NOT EXISTS` 幂等）。全新安装用 `database/game_team.sql` 或
  `scripts/dev_init_sqlite.php`，均已内置。

### 升级方式

1. 覆盖热修包内全部文件；
2. 执行 `database/upgrade_v5.7.1.sql`；
3. `.env` 追加 `COOKIE_SECURE = true`（前提：已全站 HTTPS）；
4. `ASSET_BUILD` 已提升至 5.7.1，用户刷新即加载新前端；
5. 在「管理中心 → 管理员权限分配」给需要查看成员名单的子管理员勾选「查看成员名单」
   （此前能看成员详情的场景现在至少需要该权限或审核/封禁等任一权限）。

### 回归测试

本地 SQLite 全链路 87 项断言全部通过：注册/入驻/找回密码限流、弱口令四处拦截、
统一文案、账号维度锁定、密码纪元踢会话（管理员重置/自助改密双路径）、零权限与
分权后的越权矩阵、CSRF 跨站拒绝/同源放行、隐身令牌兑票→只读→退出全链路、
忘记密码提交/审核/驳回全链路、平台路由 404、v5.7.0 抽奖回归、开放 API 无 Key 拒绝。
另经无头浏览器实测：`/{slug}/forgot` 页面完整渲染（三个输入项 + 截图上传）、
登录页「忘记密码?」链接跳转正常，页面无 JS 异常。

---

## v5.7.0 — 新功能：战队内部抽奖大转盘（奖池/概率/机会发放/中奖核销）

> 本版新增「幸运转盘」抽奖模块：成员端一个 SVG 大转盘 + 管理端「抽奖管理」页签。
> 抽取模型为**单转盘两级加权**：先按奖池权重抽中奖池，再按池内奖品权重抽出具体奖励；
> 前端展示的实际概率 = 两级权重各自归一化后相乘。新增 4 张数据表、1 个权限键、
> 2 个控制器、9 条管理路由 + 2 条成员路由。

1. **权限控制**：新增权限键 `lottery`（抽奖管理）。机会**只能由管理员发放**
   （指定成员或全员，仅审核通过的成员可被发放）；普通成员调用任何管理接口一律 403，
   未授 `lottery` 权限的子管理员同样 403；站长/真超管恒持。成员端仅可查看与消耗机会。
2. **奖池设置**：可建多个奖池，每池可设多个奖品；奖池可启停（停用不参与抽取）、
   可删除（级联删奖品）。未添加奖品的奖池不参与抽取。
3. **概率设置**：奖池与奖品均填「权重」（1-100000），概率自动换算并实时展示——
   管理端显示奖池命中率与池内概率，成员端显示奖池命中率与每件奖品的最终概率。
4. **库存与核销**：奖品可设库存（-1 不限量），库存抽到 0 后自动不再产出
   （并发扣减失败兜底为「谢谢参与」）；中奖记录带「待发放/已发放」标记，
   管理员在中奖记录里一键「标记已发放」核销，重复核销/谢谢参与核销均被拦截。
5. **机会账户**：每人一条机会账（剩余/已用/累计），发放累加、抽奖原子扣减
   （`where chances>0` 防超扣）；管理端「成员机会台账」可查全员余额。
6. **前端**：成员端「幸运转盘」页（导航第 11 项，手机功能页同步出现）——SVG 等分扇区转盘
   （全部开启奖池奖品展平 + 恒定「谢谢参与」兜底扇区）、CSS 缓动旋转 4.2s 指向中奖扇区、
   结果弹窗、奖池概率公示、我的记录；管理端「抽奖管理」页签含奖池/奖品维护、
   发放机会（指定成员/全员）、机会台账、中奖记录核销。
7. **审计**：建池/改池/删池/建奖品/改奖品/删奖品/发放/核销全部写入管理操作日志。

### 数据库变更（4 张新表）

`lottery_pools`（奖池）、`lottery_prizes`（奖品）、`lottery_chances`（机会账）、
`lottery_logs`（抽奖记录）。升级执行 `database/upgrade_v5.7.0.sql`（幂等，
`CREATE TABLE IF NOT EXISTS`）；全新安装用 `database/game_team.sql` 或
`scripts/dev_init_sqlite.php` 已内置新表。

### 升级方式

1. 覆盖热修包内全部文件（2 个新控制器 + 路由 + 公共函数 + 前端 + SQL 脚本）；
2. 执行 `database/upgrade_v5.7.0.sql`；
3. `ASSET_BUILD` 已提升至 5.7.0，用户刷新即加载新前端；
4. 在「管理中心 → 管理员权限分配」给需要运营抽奖的管理员勾选「抽奖管理」。

### 回归测试

本地 SQLite 全链路 67 项断言通过（平台超管 + 站长 + 普通成员×2 + 无权限子管理员 +
未审核成员），含越权矩阵：普通成员/无 lottery 权限子管理员访问抽奖管理与发放均 403、
未登录 401、未审核成员不可被发放、无机会不可抽、库存耗尽兜底谢谢参与、重复核销拦截；
另有无头浏览器实测转盘渲染、真实点击抽奖弹窗、管理页签渲染，页面零 JS 异常。

---

## v5.6.2 — 移动端修复：底部内容不再被 TabBar 遮挡（功能页"管理中心" / 仪表盘"最新枪码"）

> 本版修复 v6.0 手机端改版引入的一处布局缺陷：主内容区 sx 中 `p:` 缩写覆盖了
> `pb:` 长写（MUI sx 已知坑），手机端底部留白实际只有 12px 而非 76px，固定底部
> TabBar（53px 高）因此遮住各页面最底部的内容——功能聚合页滑到底看不到「管理中心」
> 入口、仪表盘滑到底「最新枪码」卡片被截断。无数据库变更，仅前端 1 个文件 + 版本号。

1. **主内容区底部留白修复**（`app.jsx` Root 布局）：`p: { xs, md }` 改为
   `px:` + `pt:` 长写（桌面端视觉不变），`pb` 手机端升级为
   `calc(76px + env(safe-area-inset-bottom))`，全面屏手势条设备额外留余量。
   修复后实测（iPhone 视口 390×844 无头浏览器）：功能页「管理中心」条目与
   TabBar 间距 32px、仪表盘「最新枪码」卡片与 TabBar 间距 23px，均完整可见。
2. **前端资源版本号**升至 `5.6.2`（`ASSET_BUILD`），用户刷新即生效。

### 升级方式

无 SQL。热修包覆盖 `public/static/js/app.jsx`、`app/common.php` 两个文件即可。

---

## v5.6.1 — 体验修正包：日程过滤已结束 / 危险按钮红框 / 约战可删除 / 训练赛发起限管理员

> 本版为 v5.6.0 上线后的四项体验修正，均源自实际使用反馈；
> 无数据库结构变更（无需执行升级 SQL），覆盖前后端共 6 个文件。

1. **队内日程：已结束条目不再显示 + 详情跳转修复**（`Schedule::index`、`app.jsx`）：
   日程聚合结果在返回前过滤掉已结束的条目（自定义活动按「开始时间+时长」判定，
   点事件按开始时间判定，全天值班条目豁免）；同时修复「详情」按钮跳转到仪表盘的问题——
   原链接指向不存在的 `#/battles`、`#/tournament` 路由，现约战详情改指自建房页约战区块
   （`#/customrace`），赛事无成员端页面不再显示详情按钮。
2. **危险操作按钮统一红框**（`app.jsx` 主题 `makeTheme`）：修复 MUI 主题全局
   `outlined.borderColor` 覆盖导致「蓝框红字」的观感问题，新增 `outlinedError` 样式槽，
   `color="error"` 的描边按钮（拒绝/撤回/删除等）现在整体呈红色。
3. **约战管理新增删除**（`BattleAdmin::remove` + `DELETE /api/admin/battles/:id`）：
   仅管理员（battle 权限）可删；可删范围：已过期 / 已完赛 / 已取消 / 被拒绝，
   以及「进行中但已有录分（部分完赛）」的约战；待接受与未录分的进行中约战请先走取消。
   删除连同报名与小局记录一并清理并记管理日志；非参赛方站点、无 battle 权限者一律 403；
   仅站内总线模式提供（连接器模式数据在约战中心侧）。前端在对应卡片显示红色「删除」按钮。
4. **队内训练赛发起限管理员**（`Training::create`、`app.jsx`）：发起训练赛需 `training`
   权限（超管/站长恒持，可为普通管理员单独授权），普通成员调用返回 403，前端同步隐藏
   「发起训练赛」按钮并提示「由管理员发起」；成员查看与报名不受影响。

**升级方式**：直接覆盖热修包内文件即可，无需 SQL；`ASSET_BUILD` 已提升至 5.6.1，
用户刷新页面即加载新前端。

**回归测试**：本地 SQLite 全链路 76 项断言通过（三租户+平台超管+普通成员+受限子管理员），
含越权用例：成员/无权限管理员/非参赛方删除约战均 403，成员发起训练赛 403。

---

## v5.6.0 — 六项功能包：统一日程 / 公告送达确认 / 队内训练赛 / 黑名单 / 值班表 / 轻量投票

> 本版一次性补齐六项候选功能（详见 `功能规划_六项候选功能.md`），
> 覆盖「成员日常协作」与「管理员运营」两条线：统一日程聚合全站事件、
> 公告可要求已读回执并一键提醒、队内训练赛补齐本队内部对抗入口、
> 黑名单与注册审核自动预警联动、房主值班表按天/按周轮换并支持成员换班、
> 轻量投票支持匿名/多选/补充选项。本次交接同时修复前任遗留的致命语法错误与前端断线。

### 新增：六项功能

1. **统一日程**（`Schedule` 控制器，`/api/schedule`）：成员端聚合撞车比赛、自建房比赛、
   约战（本队参与含总线）、赛事（经赛事中心接口，未接入静默跳过）、自定义活动、值班，
   `scope=day` 当日时间轴 / `scope=week` 本周列表；管理端（schedule 权限）自定义活动增删改、
   类别隐藏开关（`settings.schedule_hide`，逗号分隔 `race,custom_race,battle,tournament,custom,duty`）。
2. **公告送达确认**（`Notice` 成员端 + `Admin` 公告扩展）：发布公告可勾选「需要已读回执」
   （选填回执截止时间）；成员打开即记已读（`announcement_reads`）；
   管理端查看回执统计（应到/已读/未读名单）、一键提醒未读（被提醒者置顶+标黄，`reminded` 标记）；
   仪表盘公告块接 `/api/notices`：未读红点与计数、点开详情自动已读、被提醒警示条。
3. **队内训练赛**（`Training` 控制器，`/api/trainings/*`）：任何登录成员可发起，带「训练」标签
   不计正式战绩；两种对抗模式——小队对抗（选两队）/ 自由分组（报名截止后自动均分或手动分边）；
   地图方式：固定地图（建赛即生成小局）/ 双Ban抽签（双方各禁一图后随机抽图+随机攻守）/ 随机地图；
   BO1/BO3，逐局录胜负，全部录完按多数自动判定总胜负；管理动作限发起人或 training 权限；
   地图池复用平台轮换地图（`rotation_maps` 的 `tenant_id=0` 行）。
4. **黑名单**（`BlacklistAdmin`，blacklist 权限）：按 QQ 号登记（昵称+原因必填），搜索/编辑/删除；
   与注册审核联动：待审用户 QQ 命中黑名单时审核列表自动附 `blacklist_reason` 预警。
5. **值班表**（`Duty` 成员端 + `DutyAdmin` 管理端 + `DutyService`）：管理端配置轮换周期
   （按天/按周，锚点日期起算，modulo 轮转）与值班成员排序；成员端 `/api/duty/today`
   返回今日值班人、`is_me`、花名册；换班：当日值班人向花名册成员发起请求，对方同意后
   当日两人对调（按日期存例外，不改轮换序）；管理端总览未来排班+换班记录、启停与调整。
6. **轻量投票**（`Voting` 控制器，成员 `/api/votes*` + 管理 `/api/admin/votes*`）：
   管理端（vote 权限）创建（2~10 选项、截止时间、匿名/多选/限选数/允许补充选项）、提前结束、删除；
   成员端一人一票（一经提交不可修改）、结果分布、非匿名可见投票人、匿名任何人不可见；
   截止惰性冻结；被禁言/封禁成员不能投票；补充选项上限共 10 个。

### 交接补完与修复（本次）

1. **修复 `Voting.php` 致命语法错误**：`index()` 中 `($cond ? $open : $closed)[] = $v;`
   触发 "Cannot use temporary expression in write context"，该文件此前从未成功编译
   （所有投票接口 500），改为显式 if/else 追加分组。
2. `Duty/today` 补返回 `roster`（值班花名册，供换班弹窗选人）；`Duty/swaps` 的
   `pending_me` / `mine` 补 `from_name` / `to_name` 姓名。
3. 前端（`public/static/js/app.jsx`）四处断线接通：
   - `AnnDialog` 发布表单新增「需要已读回执」勾选 + 回执截止时间输入，编辑时回填；
   - 公告卡片显示「需已读回执/回执·已提醒」徽标，「查看回执」按钮接通孤立的 `ReceiptDialog`；
   - 仪表盘公告块改接 `/api/notices`（未读计数/红点、提醒警示条、点开详情自动已读、15 秒轮询）；
   - 仪表盘嵌入值班卡 `DutyTodayCard`（今日值班、发起换班、待我确认同意/拒绝），并挂进页面渲染。
4. `ASSET_BUILD` 5.4.0 → 5.6.0（强制全端刷新前端资源缓存）。

### 数据模型

升级脚本 `database/game_team_upgrade_v5_5_1_to_v5_6_0.sql`（在 v5.5.1 库上执行）：
新表 `blacklist`、`announcement_reads`、`custom_events`、`duty_configs`、`duty_members`、
`duty_swaps`、`training_matches`、`training_signups`、`training_rounds`、`votes`、
`vote_options`、`vote_ballots`；`announcements` 增列 `need_receipt / deadline / reminded_at`。
所有租户级表带 `tenant_id` 行走 `BelongsToTenant` 隔离；训练赛地图池取平台共享行（`tenant_id=0`）。

### 权限

`admin_perm_defs()` 新增：`schedule` 日程管理 / `duty` 值班管理 / `blacklist` 黑名单 /
`vote` 投票管理 / `training` 训练赛（公告回执沿用既有 `announce`）。
真超管经 v5.3.1 机制自动恒持全部权限，无需迁移授权；普通管理员需超管在权限组中勾选。

### 变更文件

- 后端：`app/controller/{Schedule,Notice,Training,BlacklistAdmin,Duty,DutyAdmin,Voting}.php`、
  `app/controller/Admin.php`（公告回执字段/审核预警）、`app/service/DutyService.php`、
  对应模型（`app/model/` 下 12 个新模型）、`route/app.php`、`app/common.php`（ASSET_BUILD）
- 前端：`public/static/js/app.jsx`
- 数据库：`database/game_team_upgrade_v5_5_1_to_v5_6_0.sql`
- 文档：`FIXES.md`、`README.md`

### 升级与验证

1. 覆盖文件后执行升级 SQL（先备份数据库）；
2. 本地 SQLite 全流程 e2e **81/81 通过**（含六功能正常链路、越权拦截 403、
   空值/重复/不存在/截止等边界）；全部 PHP 文件 `php -l` 通过；
   `app.jsx / auth.jsx / platform.jsx` Babel 编译通过。

---

## v5.4.0 — 跨战队约战系统（系列赛 + Ban/Pick 抽签 + 训练赛/正式赛分离 + BO3/BO5 决胜规则 + 私有 API + 开源单租户连接器）

> 新增平台级跨战队约战总线：战队管理员互相约战，报名制出战名单，
> 赛制参考三角洲行动全面战场赛事（bo1/bo3/bo5/r2/r4、双方各禁 1 图、
> 随机抽签地图与攻守、逐局录入胜负自动结算系列赛总比分），
> 正式赛事与训练赛分流（前者平台赛事组管控，后者自动抽签+双方自裁决）。
> 开源单租户部署可经私有 API（external Key）以「外部战队」身份接入约战总线，
> 同一套源码亦可作为连接器接入别家的约战中心。
> 本版同期交付平台侧三项变更：注册去次数上限、登录提示分离、client_ip 适配 CDN。

### 新增：约战系统

1. **约战全流程**：发起（选对方战队/赛事类型/赛制/时间/模式/每方名单上限）→ 对方接受/拒绝 →
   报名中（成员报名、管理员手动增删名单，上限默认 20、0=不限）→ 双方各提交 1 张禁用地图（可放弃）→
   抽签 → 逐局录入胜负（无平局、录入后不可改）→ 自动完赛结算总比分；
   `battle_time` 过期未完结惰性标记过期。
2. **赛制与地图结构**：`bo1/bo3/bo5/r2/r4` 五种。bo 系列先到胜场数结算；
   r 系列（r2=一图两把 / r4=两图四把）打满结算、允许平局（result=draw）。
   抽签按「同图成对、对内攻防互换」生成：bo3 前两局同图互换、第三局决胜；
   bo5/r4 成对抽两张图（每对独立随机首把攻守，第二把互换）；
   抽签池 = 平台轮换地图池 - 双方 Ban 图。
3. **正式赛事 / 训练赛分离**（`battles.battle_type`）：
   - 正式赛（official）：双方禁图后**不自动抽签**，平台超管（赛事组）抽签并逐局录入胜负；
   - 训练赛（training）：双方禁图完毕**系统自动抽签**，对阵双方管理员均可录胜负（双方自裁决），
     可上传结果截图（存 `storage/uploads/battles/`，逐局战报展示）。
4. **BO3/BO5 决胜规则**（1:1 / 2:2 时自动判定优势方）：
   - 前两局均进攻方获胜 → 进攻用时较短者为优势方；
   - 前两局均防守方获胜 → 占领区域总数较多者为优势方，相同则比占点总比例；
   - 胜负混合：训练赛随机定优势方，正式赛留待赛事组指定（平台超管可手动指定任一方）；
   - bo5 战至 2:2 → 率先拿到两局胜利的一方获决胜优先权。
   优势方二选一：**选图**（指定一张新图，攻守随机）或 **选边**（指定攻方，地图从剩余池随机抽）；
   剩余池 = 平台地图池 - 双方 Ban - 本场已用（决胜局地图不与当场前序地图相同）。
5. **逐局统计字段**：录胜负可附 `attack_secs`（进攻用时秒）/`occupy_count`（占领区域数）/
   `occupy_ratio`（占点%），供 bo3 决胜判定与战报展示；决胜局未定图前拒录。
6. **平台超管赛事控制**：代发起、强制取消、重抽（未录胜负前）、任意调配双方名单、
   手动指定决胜优势方、代优势方选图/选边、登记外部战队并签发 external Key、生成平台级 Key。
7. **私有 API（`/api/open/battles`，X-Api-Key 鉴权，读写全开）**：三种 Key 作用域——
   platform（等同平台超管，可代发起）/ tenant（绑定本队管理员身份）/ external（外部战队，
   只能应战不能发起；限训练赛录胜负）；库中仅存 sha256 哈希、明文仅签发时显示一次；
   每 Key 限频 30 次/10 秒（429）。
8. **连接器模式**（单租户开源部署接入约战中心）：站点设置新增「约战中心」配置
   （开关/地址/Key，Key 只存服务端、展示打码）；本站日程页与管理中心直接操作 Hub 约战
   （接受/拒绝/禁图/勾选本站成员上报昵称名单/录训练赛胜负/取消），未配置时统一提示「约战中心未接入」。
9. **前端**：自建房比赛日程页「跨战队约战」区块（双方名单、赛事类型/BO 赛制徽标、系列赛总比分、
   逐局地图攻守与战报统计、截图链接、优势方徽标与判定理由、训练赛双方管理员录胜负弹窗、
   优势方决胜选图/选边弹窗、5 秒轮询）；管理中心新页签「约战管理」（battle 权限）与「API 密钥」（仅站长）；
   平台管理区赛事约战总览（抽签/逐局录入含三项统计/指定优势方/代选决胜/强制取消/外部战队登记/Key 管理）。
10. **数据模型**：五张平台级新表 `battles`、`battle_signups`、`battle_rounds`、
   `external_teams`、`api_keys`（升级脚本 `database/game_team_upgrade_v5_3_to_v5_4.sql`）；
   `battles` 含 `battle_type/adv_side/adv_reason/adv_role`；`battle_rounds` 的 `map_id/attacker_side`
   可空（决胜局占位），含 `attack_secs/occupy_count/occupy_ratio/evidence`；
   地图池复用平台轮换地图（`rotation_maps` 的 `tenant_id=0` 行），小局存地图名快照防下架悬空。
11. `admin_perm_defs()` 新增 `'battle' => '约战管理'`；真超管经 v5.3.1 机制自动恒持，无需迁移授权。

### 同期交付：平台侧变更

12. **注册去次数上限**：战队成员注册不再限制每 IP 次数（原 3 次/小时限制移除）。
13. **登录提示分离**：未注册账号登录提示「该账号未注册，请先注册」（不计入错误次数）；
    仅密码错误计入连续错误并提示剩余次数，达 5 次封禁 IP；正确登录清零计数。
14. **client_ip 适配 CDN**：生产已上 CDN + Nginx `real_ip_header`，`client_ip()` 直接取
    `$_SERVER['REMOTE_ADDR']`，不再自行解析 X-Forwarded-For（防伪造）。
15. ASSET_BUILD → 5.4.0。

### 变更文件（增量包内容）

- 新增：`app/service/BattleService.php`、`app/service/BattleHubClient.php`、`app/middleware/ApiKeyAuth.php`、
  `app/controller/OpenBattle.php`、`app/controller/Battle.php`、`app/controller/BattleAdmin.php`、`app/controller/BattleHub.php`、
  `database/game_team_upgrade_v5_3_to_v5_4.sql`
- 修改：`app/common.php`、`app/controller/Auth.php`、`app/controller/Platform.php`、`route/app.php`、
  `scripts/dev_init_sqlite.php`、`database/game_team.sql`、`database/game_team_phpmyadmin.sql`、
  `public/static/js/app.jsx`、`public/static/js/platform.jsx`

### 升级注意

- **必须先执行升级 SQL**（`game_team_upgrade_v5_3_to_v5_4.sql`，含五张新表与两轮全部新列）再覆盖文件，
  清 `runtime` 缓存后 Ctrl+F5。
- 若此前执行过本版本**早期包**内附的升级 SQL，请先删除五张新表（`battles`/`battle_signups`/
  `battle_rounds`/`external_teams`/`api_keys`，早期包未上线无生产数据）再执行本文件；全新升级直接执行即可。
- 约战数据为平台级（`tenant_id` 为空），不受租户作用域过滤；历史数据零影响。
- 外部战队名单只收昵称（脱敏，不收 QQ）；`roster_limit` 为软上限，平台超管手动添加不受限。
- CDN 场景确认 Nginx 已配 `set_real_ip_from` + `real_ip_header X-Forwarded-For`，
  否则封禁/日志将记录 CDN 节点 IP。

### 测试记录（SQLite e2e，PHP 8.1 内置服务器，108 断言全过 × 2 轮）

- ✅ 平台注册/入驻/审核开通/成员注册去限次/审核/登录；未注册提示与密码错误计数分离、正确登录清零
- ✅ BO3 正式赛：同图互换结构、排除双 Ban、决胜留空；正式赛禁战队录入；
  双攻胜判定时用短者优势/理由文案；决胜未选图拒录；非优势方抢选被拒；选图生效+攻守随机；完赛结算
- ✅ BO5 正式赛：两图成对结构、对内互换、决胜留空；2:2 率先两胜方获优先权；
  选边折算攻方、决胜抽全新图（非前序非禁图）、完赛结算
- ✅ R2 训练赛：自动抽签（单方禁图不抽、双方完毕才抽）、管理员录胜负附截图（evidence 落库）、打满平局结算
- ✅ BO3 训练赛：双守胜判定占领多者优势、优势方选图、完赛
- ✅ 开放 API：无 Key 拒绝、platform Key 列表/地图池/代发、完赛拒决胜、external Key 禁发起；
  连接器（另端口防自请求死锁）：状态/列表/代接受/双方弃禁自动抽签/录胜负/完赛

---

## v5.3.1 — 运势图加载修复 / 个人主页标签提速 / 超管权限修正与保护加固

> 三个线上问题修复：签到“今日运势”表情包 404 看不到；个人主页职责标签区
> 比其他内容晚半拍才出现；战队超级管理员（租户站长）看不到职责标签管理页，
> 而持权限的普通管理员反而能改超管的名字和标签。

### 修复

1. **今日运势表情包无法加载（404）**
   - 表情包是物理静态文件，只存在于根路径 `/static/meme/`；此前前端给它拼了租户目录前缀，
     请求 `/{slug}/static/meme/N.jpg` 在服务器上不存在导致 404。改为直接引用根路径（与模板里
     vendor JS 的引用方式一致），签到卡片缩略图与运势弹窗两处同步修正。
2. **个人主页职责标签区迟到刷新**
   - 标签区原先等 `/api/role-tags` 返回前整块不渲染，接口回来才弹出；现在卡片立即占位渲染（与个人中心其他区块同时出现），标签拉齐后填入；保存后也不再先清空再重载。
3. **战队超级管理员看不到职责标签管理页**
   - 根因：`admin_perms_of()` 只给内置 `admin` 全部权限，租户站长只读 `users.admin_perms` 列；
     v5.3.0 新增的 `role_tag` 键不在站长历史授权 JSON 里，导致页签被隐藏、接口 403。
     现改为真超管（内置 admin / 租户站长）恒持全部权限，后续新增权限键不再遗漏。
4. **非超管管理员可改超管的名字/标签（越权）**
   - `Admin::renameUser` 补上真超管保护（与封禁/禁言/降权/删除的守卫一致）；
     `Admin::assignUserTags` 新增保护：除超管本人外，不得给超级管理员分配标签。
     超管自己给自己分标签不受影响；超管改名可走个人中心昵称修改。

### 变更文件（增量包内容）

- `app/common.php`（`admin_perms_of` 超管恒持全部权限 + ASSET_BUILD→5.3.1）
- `app/controller/Admin.php`（renameUser / assignUserTags 超管保护守卫）
- `public/static/js/app.jsx`（运势图去租户前缀 + 标签区占位渲染）

### 升级注意

- 无数据库变更，直接覆盖 3 个文件即可；清 `runtime` 缓存（路由/模板）后 Ctrl+F5。
- 若此前给某管理员授权时勾过“全选”，本次修复后其权限行为不变；真超管无需任何额外操作即可看到职责标签页。
- 部署后可验证：直接访问 `域名根路径/static/meme/1.jpg` 应能看到图片；若 404 说明表情包目录未上传。

---

## v5.3.0 — 职责标签 / 7天免登录 / 每日签到 / 枪码总数虚高修复

> 四项需求一次交付：管理中心新增"职责标签"体系（彩色标签、多选分配、个人主页自选、
> 聊天/伪军/小队展示）；战队侧登录支持"7 天内自动登录"；仪表盘新增每日签到
> （连续天数/全站人数/本队前三/每日一言/今日运势表情包）；修复列表总数虚高
> （`total` 计入他租户数据）这一跨站点隐患。

### 新增功能

1. **职责标签**
   - 新表 `role_tags`（租户级标签库：名称≤16字 + 自定义颜色），`users` 增列 `role_tags`
     （JSON 数组，第一个元素为默认展示标签）。
   - 管理中心新页签"职责标签"（新权限键 `role_tag`）：增删改标签、原生取色器、
     各标签已分配人数、"允许用户自行修改"全局开关（`settings.role_tag_user_editable`）。
   - 用户管理每行"职责标签"按钮：管理员多选分配；个人主页标签区：普通用户自由单选
     （可清除），被分配多标签的用户可调整默认；开关关闭时用户侧只读。
   - 展示点：聊天室消息、查询伪军结果、战队小队成员行渲染彩色标签（仅默认那 1 个）。
   - 删除标签时联动清理所有用户持有记录；非管理员提交强制收敛为 ≤1 个有效标签。
2. **7 天免密码登录（仅战队站点）**
   - 登录表单新增"7 天内自动登录"复选框（默认勾选）。
   - 新表 `remember_tokens`：令牌原值不落库，只存 sha256；cookie `remember_me`
     值为 `用户ID.令牌`，httponly，路径按租户目录隔离。
   - 会话过期后访问任一受保护接口（含 `me` 与管理接口）自动静默恢复登录；
     命中即令牌轮换（旧令牌一次性作废、发新令牌续期），并换新 session ID 防固定。
   - 退出登录、修改密码、更换注册截图、账号封禁/删除均会使令牌失效。
3. **每日签到**
   - 新表 `checkins`（每人每天一条，唯一键兜底并发重复提交）。
   - 仪表盘欢迎横幅下方新增签到卡片：签到按钮、连续签到天数（昨天签过 +1，断签归 1）、
     全站今日签到人数、本队今日最早前 3 名、每日一言。
   - 签到成功弹"今日运势"：随机一张表情包（`/static/meme/1~11.jpg`，当天固定展示这张）。
   - 每日一言每自然日只调一次外部接口（`uapis.cn`），按日期缓存全站统一；
     接口不可用时有兜底文案，不影响签到。表情包张数扫描目录自动探测，不写死。

### 修复（枪码总数虚高 / 列表计数跨租户泄漏）

- **现象**：枪码列表"共 39 条"但实际只有 10 条左右；`total` 把其他战队站点的数据也算了进去。
- **根因**（本地 SQLite 复现确认）：think-orm 的 `scope()` 登记惰性作用域时把**当时的查询对象**
  闭包进参数；`(clone $query)->count()` 克隆体未重新登记作用域，导致 `BelongsToTenant`
  全局租户作用域（`where tenant_id = 当前站点`）只附加到原对象、克隆体 count 丢条件，
  `total` 统计了全部租户的行（select 列表正常，所以"条数与总数对不上"）。
- **修复**：`vendor/topthink/think-orm/src/db/BaseQuery.php` 增加 `__clone()`——
  克隆时切断 `where`/`bind` 的引用共享并把全部作用域重新登记到克隆体。
  全站 5 处同模式计数（枪码/图片/攻略/用户管理×2）一次性修复，且对后续所有
  "克隆后计数"的写法免疫。
- **回归**：普通用户与管理员、翻页、关键词搜索下 `total` 与列表条数一致；
  图片、攻略、用户管理列表同步回归。

### 变更文件

- 新增：`app/model/RoleTag.php`、`app/controller/Checkin.php`、
  `database/game_team_upgrade_v5_2_to_v5_3.sql`、`public/static/meme/1~11.jpg`
- 后端：`route/app.php`（新路由 + `me` 纳入登录保护组）、`app/common.php`
  （权限定义/标签助手/免登录函数/ASSET_BUILD→5.3.0）、`app/controller/Auth.php`、
  `app/controller/Admin.php`、`app/controller/Profile.php`、`app/controller/Search.php`、
  `app/controller/Chat.php`、`app/controller/Team.php`、
  `app/middleware/AuthCheck.php`、`app/middleware/AdminCheck.php`
- 前端：`public/static/js/app.jsx`、`public/static/js/auth.jsx`
- 修复：`vendor/topthink/think-orm/src/db/BaseQuery.php`（__clone 作用域修正）
- 全量同步：`database/game_team.sql`、`game_team_phpmyadmin.sql`、`scripts/dev_init_sqlite.php`

### 测试记录

- SQLite e2e 43/43 通过：标签增删改/重名/非法颜色/越权 403/多选分配/单选自选/
  默认切换/四处展示/开关只读/删除联动清理；免登录勾选签发/恢复/轮换/退出作废/
  改密码作废/未勾选不签发；签到首签/重签拦截/连续累计/断签归1/全站人数/本队前三/
  运势图当天固定；枪码计数回归。
- 全部改动 PHP 过 `php -l`，三个 JSX 过 Babel 编译校验。

### 升级注意

- **必须先执行增量 SQL** `database/game_team_upgrade_v5_2_to_v5_3.sql`（3 张新表 +
  1 个新列 + 默认开关），再覆盖文件。
- 增量包内含 **vendor 修复文件**（`BaseQuery.php`），请勿跳过。
- 覆盖后清空 `runtime` 缓存并 Ctrl+F5。

---

## v5.2.5 — 修复导入空附件引用导致的 statName 报错

> 单点缺陷修复：数据包里存在无附件的行（如无头像成员、无配图公告）时，导入执行抛
> `ZipArchive::statName(): Argument #1 ($name) cannot be empty` 导致整个导入失败。
> 无数据库结构变更，无前端变更。

### 修复

- **根因**：v5.2.4 给 `DataTransfer::runImport` 的 `restoreFile` 加纵深校验时，首行直接调
  `$zip->statName($entry)` 取文件大小；数据包里空引用（`profile_image`/`file` 为空字符串）
  未经拦截传入，PHP 8 对空参数抛 `ValueError`，事务回滚、导入失败。
- **修复**：`restoreFile` 开头先判空/判 `files/` 前缀，空引用直接返回空字符串（等价"无附件"），
  不再触碰 `statName`。`files/` 前缀校验从 `entrySafe` 提前，避免对包外路径做无谓的 zip 查询。

### 变更文件

- `app/service/DataTransfer.php`：`restoreFile` 闭包入口守卫（+5 行）

### 测试记录

- SQLite e2e 15/15 通过：构造含空头像成员 + 空文件引用图片行 + 正常附件成员的数据包，
  走完整链路（注册→入驻→审核开通→上传数据包→超管审核执行导入），
  导入成功、空引用行正常落库、正常附件落库落盘、站长保留（防锁定）回归通过。

---

## v5.2.4 — 数据包导入投毒防护/上传卡死修复

> 安全加固 + 体验修复：数据包内文件改为图片扩展名白名单并限制大小（防 webshell/zip 炸弹），
> 上传请求加超时保护、超大文件浏览器预检，不再无限卡在"服务器校验中"。无数据库结构变更。

### 安全加固（投毒防护）

1. **包内文件白名单**：数据包 `files/` 内只允许 jpg/jpeg/png/gif/webp；夹带 `.php`/`.html`/`.svg`
   等可执行或可脚本文件会在**上传时**被拒绝（此前导入执行时不校验扩展名，恶意包经审核后
   可把 webshell 落到公网可访问的 `/storage/uploads/`）。
2. **路径穿越与隐藏文件**：拒绝含 `..` 的条目和 `.htaccess` 等隐藏文件（上传拦截 + 执行时
   `restoreFile` 纵深二次校验）。
3. **大小上限（防 zip 炸弹）**：单文件解压后 ≤ 20MB，包内文件总量 ≤ 512MB；超出即拒绝并明确提示。
4. **uploads 目录禁脚本执行**：新增 `public/storage/uploads/.htaccess`（Apache 下 php/jsp/sh 等
   一律拒绝访问）；nginx 部署示例已有等效 deny 规则，`client_max_body_size` 建议值提升到 128m。

### 修复（卡在"服务器校验中"）

5. **XHR 超时保护**：上传请求加 10 分钟超时与 abort 处理，服务器无响应/被代理掐断时报错退出
   并恢复按钮，不再永久卡住；报错文案区分超时/网络异常/响应异常。
6. **上传上限下发 + 预检**：`/api/platform/my/data/tasks` 新增 `max_upload_bytes`
   （取 `upload_max_filesize`、`post_max_size`、512MB 三者最小值），前端选择文件后先比对大小，
   超限直接提示"请精简图片后重新导出"，不发请求。

### 说明

- 被安全校验拦截的上传**不创建任务、不消耗当日导入机会**，可立即修正重传。
- 导入仍需平台超管审核；导入的管理员降级为普通成员、保留当前站长等既有规则不变。

### 变更文件

- `app/service/DataTransfer.php`：`entryReason/entrySafe`、`validatePackage` 扫描、`restoreFile` 纵深校验、大小常量
- `app/controller/Platform.php`：`dataTasks` 下发 `max_upload_bytes`
- `public/storage/uploads/.htaccess`：新增（禁止脚本执行）
- `public/static/js/platform.jsx`：`uploadJson` 超时、`reqImport` 预检与明确报错
- `app/common.php`：ASSET_BUILD → 5.2.4
- `deploy/nginx.conf.example`：`client_max_body_size` 建议值 128m

### 测试记录

SQLite e2e 25/25 通过：php webshell/路径穿越/隐藏文件/html/21MB 超大文件全部拦截且不消耗机会；
正常包上传→审核→落库（管理员降级、站长保留、头像重映射）→落盘回归通过；uploads 全目录扫描无可执行文件。

---

## v5.2.3 — 游戏地图工具换用开源地图工具

> 纯前端调整：战队站点"游戏地图工具"页内嵌地址由官方工具页（df.qq.com）换为
> 开源地图工具 https://dt.dpx1.icu/ ，页面上标注其开源项目地址。无数据库结构变更。

### 变更

1. **内嵌地址更换**：`MapToolPage` 的 iframe 由 `https://df.qq.com/cp/a20240729directory/index.html`
   改为 `https://dt.dpx1.icu/`。
2. **开源标注**：iframe 上方新增说明行，链接到开源项目
   [github.com/Deng0430/delta-force](https://github.com/Deng0430/delta-force)（新窗口打开）。
3. 面板副标题同步改为"地图工具内嵌展示（开源项目）"；iframe 高度预留微调（190px→210px）容纳说明行。

### 变更文件

- `public/static/js/app.jsx`：`MapToolPage` 组件
- `app/common.php`：ASSET_BUILD → 5.2.3（强制刷新前端缓存）

> 注意：若 dt.dpx1.icu 后续设置了 X-Frame-Options / CSP frame-ancestors，内嵌会被浏览器拦截，
> 届时需在目标站点放开本平台的嵌入白名单。

---

## v5.2.2 — 数据任务失败不占每日机会/数据包上传进度条

> 两项数据中心体验修复：导入/导出任务"执行失败"不再消耗当天唯一的机会（可当天修正后重传），
> 上传数据包增加实时进度条。本版本无数据库结构变更（无需执行升级 SQL）。

### 修复

1. **执行失败不占机会**：此前 `dataTaskCountToday` 只排除"已驳回/已撤回"，平台超管审核执行导入
   一旦失败（如数据包内容不合法），`failed` 任务也占掉当天唯一的导入机会，战队当天无法重传。
   现将 `failed` 一并排除——被驳回、撤回、执行失败的任务都不消耗机会；只有待审核/已通过/已完成
   的任务才占用。导入导出共用同一计数，导出同样受益。
2. **数据包上传进度条**：上传数据包改用 XHR（fetch 无上传进度），上传中显示实时百分比进度条
   （100% 后提示"服务器校验中"），上传期间两个按钮禁用防重复提交；说明文案同步注明
   "被驳回、撤回或执行失败不消耗机会，可重新提交"。

### 变更文件

- `app\controller\Platform.php`：`dataTaskCountToday` 排除 `failed` 状态
- `public\static\js\platform.jsx`：新增 `uploadJson` 进度上传工具；`DataTaskPanel` 进度条 UI 与文案
- `app\common.php`：ASSET_BUILD=5.2.2

### 测试记录（SQLite e2e，22/22 通过）

- 坏包（清单合法、data.json 损坏）上传受理 → 审核执行失败 → 当天机会恢复（import_left=1）→ 重传成功
- 合法包审核执行导入完成；成功后占用当天机会；当天第二次上传被拦截
- 落库校验：外站管理员降为普通成员、公告/设置导入、当前站长保留（防锁定）

---

## v5.2.1 — 入驻材料防绕过删除/超管定义收紧/开源版导出工具

> 三项修复与工具：堵住从"注册审核截图"分类删除入驻材料的绕过路径、
> 收紧超级管理员定义（授满权限不再产生第二个动不了的超管）、
> 为开源版 v3.9.6.1 提供独立数据导出脚本，打通迁移到平台的数据通路。
> 本版本无数据库结构变更（无需执行升级 SQL）。

### 修复

1. **入驻材料防绕过删除（存储清理）**：站点开通时队长证明截图会复制到站长的注册截图
   （`users.profile_image`），此前可从存储空间"注册审核截图"分类把它删掉，绕过"入驻材料"分组保护。
   现按文件路径全分类联动保护：概览里该条目就地标记"平台保管（入驻材料）"不给删除按钮，
   服务端 `Storage::removeFile` 命中入驻材料文件一律拒绝；普通成员的注册截图正常清理不受影响（e2e 实证）。
2. **超级管理员定义收紧**：此前"持有全部权限即超管"，导致超管给别人授满 17 项权限后
   对方变成受保护的超管、真超管反而动不了他。现超管仅限两类：内置 `admin` 与开通站点时创建的站长账号
   （`tenants.admin_username`）。被授满权限的管理员只是"权限拿满的管理员"：仍在分配列表、
   真超管可随时削减权限/封禁/删除；反向不成立——授满者对真超管的封禁/降级/禁言/删除/削权全部被拦截。
   权限分配页、存储清理、日志清理等超管专属能力回归仅真超管可用（e2e 38 项全过）。

### 新工具

3. **开源版数据导出脚本**：`scripts/open_export_v396.php`，面向自建开源版（v3.9.x）站点。
   放到站点根目录浏览器访问即自动读取 `.env` 数据库配置，把成员（含密码哈希）/小队/图片/枪码/
   聊天/公告/日程/攻略/设置与全部图片文件打包成平台可识别的数据包（不含轮换地图数据），
   再到入驻平台"数据中心"上传提交导入审核即可完成迁移。导出后应删除该脚本。

### 变更文件

- `app\common.php`：`is_super_admin` 改按"内置 admin / 租户站长"判定，新增 `is_tenant_owner`；ASSET_BUILD=5.2.1
- `app\service\Storage.php`：新增 `protectedPaths`，概览联动标记 + 删除拦截
- `app\controller\Admin.php`：权限分配列表/保存守卫与四个用户操作守卫改按新超管定义
- `public\static\js\app.jsx`：权限分配页提示文案同步新定义
- `scripts\open_export_v396.php`：新增（开源版独立导出工具）

### 测试记录（SQLite e2e，38/38 通过）

- 开通站点后站长截图=入驻材料截图；profiles 分类该条目标记 protected、删除被拒、物理文件与 DB 引用完好
- application 分组删除仍被拒（回归）；普通成员注册截图可正常清理
- 授满权限者仍在分配列表、`is_super=false`、可被削权/封禁/删除；站长不进列表且授满者无法对其任何处置
- 开源版导出脚本语法校验通过（运行时依赖目标站 MySQL，随迁移用户环境验证）

---

## v5.2.0 — 通知已阅/超管保护/隔离加固/隐身令牌落库/入驻材料保护/数据导入导出

> 一次性落地 6 项需求：平台通知已阅标记+管理员未读提示、超管权限不可被削减、跨战队数据隔离审计加固、
> 隐身巡查令牌改为数据库签发制、存储空间禁删入驻材料、站点数据导入导出（含图片、审核制）。
> 数据库在 v5.1.0 基础上增量变更（有升级 SQL，见文末）。

### 新功能

1. **平台通知已阅**：`tenant_notices` 增 `read_at` 列；战队管理中心打开「平台通知」栏即全部标记已阅，
   接口返回 `unread` 未读数，前端有未读时弹 toast 提示「收到 N 条未读平台通知」，每条通知显示未读/已阅状态。
2. **超管权限保护**：持有全部权限的管理员（含租户站长）即超管——不出现在权限分配列表、
   `permsSave`/`setUserStatus`/`setUserRole`/`setUserMute`/`removeUser` 全部拦截，
   超管恒为超管，任何操作都无法把超管降权成普通管理员；普通管理员的权限分配不受影响。
   （注：超管定义已在 v5.2.1 收紧，"持全部权限即超管"不再成立，见上方 v5.2.1）
3. **跨战队数据隔离加固**：审计确认 think-orm 全局租户作用域对 `find()/where()` 全生效（e2e 实证），
   session 按目录隔离（`PHPSESSID_{slug}`）互不串；`Image::raw`/`Guide::raw` 代理口补显式
   tenant_id 校验作纵深防御。未登录/他站登录态访问本站图片一律 401/404。
4. **隐身巡查令牌落库**（安全加固）：旧 HMAC 方案改为**数据库签发制**——新表 `stealth_tokens`
   （tenant_id/token_hash=sha256/created_by/expires_at），仅平台超管经接口可签发，每枚令牌可审计溯源；
   校验只认库内登记，代码开源泄露也无法伪造。只读强制不变（仅 GET/HEAD、管理接口 403、不写 session/日志）。
   平台 settings 中的 `stealth_secret` 机制废弃。
5. **存储空间保护入驻材料**：队长证明截图与战队logo计入存储用量并在存储页单列「入驻材料（平台保护）」组，
   标注「平台保管」，`Storage::removeFile` 对 type=application 一律拒绝删除。
6. **站点数据导入导出**（面向自建开源版 v3.9.6.1 迁移与平台站点备份）：
   - 规则：站点成员 ≥5 人才可导出；每天各 1 次导出/导入机会（撤回、驳回不占名额）；
     导入覆盖本站数据（保留当前站长账号防锁定）；全程**提交审核制**，平台超管同意后才生成包/执行导入。
   - 数据包：ZIP（manifest.json + data.json + files/**），含用户（密码哈希）/小队/图片/改枪码/
     评论/聊天/公告/撞车日程/攻略/设置与全部图片文件；**不含轮换地图数据**（收归平台统一配置）。
   - 导入安全：外站数据包中的管理员身份一律降为普通成员（防带入第二个超管）；文件重名自动加后缀；
     导入前预估存储配额；包存 `runtime/data_tasks/` 不走 web 直出，仅任务所有者与平台超管可下载。
   - 新表 `tenant_data_tasks`；任务流 pending→approved（导出）/done（导入）/rejected/failed/cancelled。
   - 平台超管控制台「数据审核」Tab：查看包/同意/驳回（填原因）。战队管理中心（approved 站点）「数据导入导出」面板。

### 数据库变更（v5.1.0 增量）

- `tenant_notices` 增列：`read_at DATETIME DEFAULT NULL`
- 新表：`stealth_tokens`（tenant_id/token_hash CHAR(64)/created_by/created_at/expires_at）、
  `tenant_data_tasks`（tenant_id/type=export|import/status/file/note/created_at/reviewed_at/reviewed_by/finished_at）
- **升级 SQL：`database/game_team_upgrade_v5_1_to_v5_2.sql`**（旧库执行即可）
- 提醒：导入包上传受 php.ini 限制，建议 `upload_max_filesize`/`post_max_size` ≥ 64M

### 测试记录（SQLite e2e，PHP 8.1 内置服务器，85 断言全过）

- ✅ 双租户注册/入驻/审核开通全流程
- ✅ 超管保护：权限列表不含站长；permsSave/改角色/封禁/禁言/删除对超管全拦截且权限未变；普通管理员可正常分配
- ✅ 通知已阅：发送→未读 1→打开标记已阅→未读 0
- ✅ 隐身：签发落库；前台 GET 放行；管理接口 403；伪造令牌拒绝；POST 拒绝；战队账号无法签发
- ✅ 隔离：他站登录态/匿名访问本站图片 raw 均拒绝；跨租户 id 访问 404
- ✅ 存储：入驻材料组展示（含截图+logo）、删除被拒、物理文件仍在
- ✅ 数据：<5 人导出拒绝；申请→审核→下载 ZIP（manifest/data.json/图片文件齐全、轮换设置已排除）；
  他站无法下载；新站导入→审核→数据覆盖（用户可登录/图片文件还原/站长保留/管理员降级/轮换不导入）；
  每日次数限制；驳回流（不可下载）；撤回流

---

## v5.1.0 — 入驻平台运营能力增强（转移/停用/日志/配额/隐身巡查/轮换收归）

> 一次性落地 11 项需求：战队转移（7 天冷静期）、战队主自助停用、登录日志、存储空间配额、
> 称呼修复、聊天空消息修复、关于页 QQ 更正、超管封禁+隐身巡查+定向通知、入驻申请文案、
> 个人中心昵称修复、轮换地图收归平台统一配置。数据库在 v5.0.0 基础上增量变更（有升级 SQL，见文末）。

### 新功能

1. **战队转移**：战队主在平台控制台发起转移（填接收方平台账号，对方须 role=team、状态正常、名下无站点）→
   接收方同意 → 站点进入 `transferring` 冷静期（7 天）**整站不可访问**（页面显示预计恢复日期）→ 到期**惰性结算**
   （`ResolveTenant`/`Platform::my` 遇 transferring 先 `Transfer::settle()` 再重读），applicant_id 换成接收方。
   冷静期内发起方可撤回；pending 阶段接收方可拒绝。新表 `tenant_transfers`。
2. **自助停用**：战队主可暂停/恢复自己的站点（`POST /api/platform/my/status`，tenants.status=paused）；
   被封禁/转移中的站点不允许自助操作。
3. **登录日志**：平台登录成功/失败/停用拦截均记入 `platform_login_logs`（IP + 归属地 + 结果），
   控制台可查本人最近 50 条。
4. **存储空间配额**：每站点默认 **2GB**（tenants.storage_quota，0=默认）；平台超管可改 0-1024GB；
   用量 = 游戏图片/注册截图/攻略封面/公告配图登记文件字节合计；上传前 `Storage::ensureRoom` 超额拒绝；
   战队管理中心（仅超管）可看配额/用量/分类明细并逐条清理（images 删记录，其余清文件列）。
5. **平台超管封禁 + 隐身巡查 + 定向通知**：
   - 封禁：tenants.status=banned，整站拦截文案「站点已被平台封禁」。
   - 隐身：超管点「隐身查看」签发 2 小时 HMAC 令牌（绑定 slug，密钥存平台级 settings `stealth_secret`），
     以 `/{slug}/app?_sv=令牌` 进入。**只读强制**：仅 GET/HEAD 生效、`AdminCheck` 一律 403、
     不写 session/不记日志/不刷 last_active；以租户超管视角渲染。paused/banned 站点也可巡查。
   - 通知：`tenant_notices` 表，租户管理中心「平台通知」只读展示最近 50 条。
6. **轮换地图收归平台**：`rotation_maps/rotation_days/rotation_day_maps` 数据统一落 tenant_id=0，
   平台超管在控制台「轮换地图」Tab 统一维护（开关/锚点/地图池/每日组合），全部战队共用一套；
   租户端管理中心已移除轮换管理入口与权限键，只保留前台展示读取。

### 修复 / 文案

- 审核开通建的租户超管 nickname 改用申请人填写的超管账号（此前误用战队名，导致个人中心昵称显示成战队名）；
  战队名展示移至管理中心头部。
- 聊天室无消息时一直转圈 → 空列表正常渲染。
- 战队系统内「超管/超级管理员」称呼统一改为「管理员」。
- 关于页 QQ 号更正为 **3603893291**。
- 入驻申请上传项文案改为「战队队长证明截图（必填）」+「战队logo（可选）」。
- 前端新增 `resUrl()`：`/api/*` 代理图片地址统一补租户前缀并在隐身模式追加 `_sv`
  （`/storage/uploads/*` 静态文件根路径直出，不加前缀）。

### 数据库变更（v5.0.0 增量）

- `tenants` 增列：`storage_quota BIGINT UNSIGNED NOT NULL DEFAULT 0`（0=默认 2GB）；status 取值扩展
  `approved|banned|paused|transferring`
- 新表：`tenant_transfers`（tenant_id/from_uid/to_uid/status=pending|cooling|completed|cancelled|rejected/cooling_until）、
  `platform_login_logs`（platform_user_id/username/ip/region/result）、`tenant_notices`（tenant_id/content）
- 文件：`database/game_team.sql` / `game_team_phpmyadmin.sql` 已含全量新结构；
  **升级 SQL：`database/game_team_upgrade_v5_to_v5_1.sql`**（旧库执行即可，轮换表保留原数据改挂 tenant_id=0）

### 测试记录（SQLite e2e，PHP 8.1 内置服务器）

- ✅ 平台注册/登录（成功+错误密码均入登录日志）；入驻申请（队长证明截图+logo multipart）审核开通
- ✅ 租户超管 nickname=超管账号；聊天空消息 code=0；存储默认 2GB→改 1GB→上传计量→清理释放
- ✅ 自助暂停（整站 403 提示页）/恢复；封禁/解封
- ✅ 轮换：平台统一配置（开关/地图/组合）→ 租户端读取一致；租户管理端旧路由 404
- ✅ 通知发送 → 租户管理中心可见
- ✅ 隐身：页面 200、读 me 返回租户超管、POST 401、管理接口 403、图片代理 200；转移冷静期拒绝隐身
- ✅ 转移：发起→同意→冷静期整站 403（含恢复日期）→撤回恢复

---

## v5.0.0 — 入驻平台独立化 + 目录制多租户（架构重做）

> **重要**：v4.0.0 的「二级域名 + 主站内入驻审核」方案整体作废，本版本按用户确认的需求重做：
> 入驻是一套独立平台系统，不塞在游戏战队系统里；同一套代码、同一主域名，用**目录**分开各战队站点。
> v5 与 v4 数据库结构不兼容，无升级脚本（用户确认：现有数据全部清空重来）。

### 产品形态

1. **入驻平台（主域名根路径 `dpx1.icu/`）**：独立注册登录，仅面向想入驻的战队。
   注册平台账号 → 提交入驻资料（目录名/战队名/简介/超管账号密码/截图）→ 平台超管审核 → 通过即开通站点。
2. **战队站点（`dpx1.icu/{slug}/`）**：英文 slug 目录，功能与既有游戏战队系统完全一致，数据按 `tenant_id` 隔离。
   **战队成员在各自战队系统内注册**（`/{slug}/register`），不在平台注册。
3. **平台账号体系**：`platform_users` 表（role=admin 内置超管 / role=team 战队账号），与战队系统 `users` 表完全分离。
4. **超管自主管理**：战队登录平台控制台可查看站点访问地址与超管账号，并可随时修改战队系统的超管账号和密码。
5. **平台超管**：初始账号 `admin / admin123`，负责审核入驻申请、停用/恢复租户站点。

### 架构

- **租户解析**：全局中间件 `ResolveTenant` 取 pathinfo 第一段查 `tenants`：命中 → 租户上下文 + `setPathinfo()` 剥离前缀（路由/控制器对前缀无感知）；未命中 → 平台上下文，路径不动。
  - 根路径剥离后写**空字符串**（非 `/`），否则租户根路径 `/{slug}/` 匹配不到根路由 404。
  - 租户非 approved 整站拦截：`api/*` 返 403 JSON，页面渲染停用提示页。
- **Session 按目录隔离**：同域名下平台用默认 `PHPSESSID`，租户用 `PHPSESSID_{slug}`（中间件内 `Config::set(['name'=>...],'session')`，先于 SessionInit 生效）。
  平台 session 键 `platform_user_id`，战队 session 键 `user_id`，互不串。
- **前端基址**：view 模板注入 `window.SITE_BASE`（平台=''，租户='/slug'），app.jsx/auth.jsx 所有 fetch/跳转带前缀；
  `/static/*`、`/storage/uploads/*` 保持绝对路径不加前缀。
- **平台接口守卫**：`Platform` 控制器 `platformOnly()` 在非平台上下文一律 404；平台侧改战队超管走 `Db::name('users')` 直连（绕过 BelongsToTenant globalScope）；审核建超管用 `User::create` 显式 tenant_id。
- **保留字 slug**：www/api/app/admin/login/register/static/storage/uploads/mail/smtp/dev/test/main/assets/index/favicon/robots/apply/home。

### 移除项（v4.0.0 残留清理）

- 主站公开 `/apply` 页与 QQ 查状态接口、管理中心「入驻审核」Tab、`is_platform_admin()`、tenants 的 `owner_qq` 字段与 `main` 固定行、二级域名 Host 解析逻辑。
- `public/index.html` 原 YYS 宣传页改写为入驻平台首页（平台介绍/入驻流程/功能清单/登录注册入口，紫色主题）。

### 数据库变更（全新安装，不兼容 v4）

- 新表：`platform_users`（username UNIQUE / password / nickname / role / status / reg_ip），seed 平台超管 admin/admin123
- 重构：`tenants`（applicant_id / slug UNIQUE / name / logo / description / admin_username / admin_user_id / status=approved|banned，审核通过才建行）；
  `tenant_applications`（applicant_id / slug / name / logo / description / admin_username / admin_password / screenshot / status / reject_reason）
- 15 张业务表保留 v4 的 `tenant_id` 结构，但去掉 `DEFAULT 1`（租户 id 由审核开通时动态生成）
- 无任何预置业务数据（无示例小队/公告/聊天/settings），站点由入驻审核通过时自动建超管 + 默认 settings
- 文件：`database/game_team.sql` / `game_team_phpmyadmin.sql` 全量重写；`scripts/dev_init_sqlite.php` 同步 v5；
  `config/database.php` 新增 sqlite 连接段（仅 `DB_DRIVER=sqlite` 时生效，供本地开发/e2e）

### 部署

- **单域名即可**：无需泛解析、无需通配符证书（v4 的部署步骤全部作废）。详见 `deploy/nginx.conf.example` 文末说明。
- 根路径静态首页由 nginx `index index.html` 优先命中；PHP 开发服务器下 `/` 回退到路由 302 → `/login`。

### 测试记录（SQLite e2e，PHP 8.1 内置服务器）

- ✅ 平台注册/登录/me；保留账号 admin 拒注册；密码强度校验；登录失败 5 次锁 15 分钟（429）
- ✅ slug 查重：可用/保留字/格式不合法；入驻 multipart 提交（截图必填）
- ✅ 超管审核：通过 → 事务建租户+超管（全权限）+10 条默认 settings；驳回必填原因、申请人可见、可重新提交
- ✅ 战队控制台：我的站点信息、改超管账号（租户内查重）、改超管密码，新凭据可登录/旧凭据失效
- ✅ 租户全链路：`/demo/` 根路径 302 → `/demo/login`；登录 is_super=true；公告/聊天/仪表盘 code=0
- ✅ 隔离：session cookie 名分离（PHPSESSID vs PHPSESSID_demo）、跨上下文互 401；平台 API 在租户目录 404
- ✅ 生命周期：停用 → API 403 + 停用提示页；恢复 → 200；每平台账号限一个租户
- ✅ 前端基址：平台页 PLATFORM_MODE 注入、租户页 SITE_BASE='/demo'、jsx 资源 200

### 已知说明

- slug 规则：`[a-z0-9][a-z0-9-]{1,28}[a-z0-9]`（TenantContext::validSlug）
- 平台登录防爆破与战队系统 blocked_ips 机制相互独立
- `database/game_team_upgrade_v1_to_v2.sql` 为更早版本的历史文件，保留备查

---

## v4.0.0 — 战队入驻多租户平台（已作废，被 v5.0.0 取代）

### 新功能

1. **战队入驻**：主站公开页 `/apply` 提交入驻申请（战队名/二级域名 slug/简介/站长QQ/密码/游戏截图/可选 logo）；
   slug 实时查重（格式校验 + 保留字 + 已占用拦截）；凭 QQ 号查询申请状态与驳回原因。
2. **入驻审核**：主站管理中心新增「入驻审核」Tab（仅平台管理员可见）。通过后事务内自动：
   创建租户（status=approved）→ 在租户下创建站长账号（用户名=站长QQ，role=admin，全权限=本站超管）→ 写入租户默认 settings。
3. **租户站点（全功能 1:1 克隆）**：审核通过后，战队凭专属二级域名 `xxx.dpx1.icu` 访问，
   拥有与主站完全一致的全部功能，数据按 `tenant_id` 行级隔离。
4. **租户品牌**：顶栏/侧栏站名与 logo 随域名自动切换（公开接口 `GET /api/tenant/info`）。
5. **租户生命周期**：平台管理员可停用/恢复租户；停用后该域名 API 返回 403、页面渲染停用提示页。

### 架构

- **隔离方式**：共享数据库 + `tenant_id` 行级隔离。主站 = 租户 1（`tenants.id=1`，slug=`main`），存量数据全部归主站，行为不变。
- **租户解析**：全局中间件 `ResolveTenant`（先于 SessionInit）取 Host 一级标签查 `tenants`；
  IP 访问 / 未匹配 slug / 主域名 → 主站。session cookie 按域名天然隔离。
- **模型隔离**：trait `app/model/concern/BelongsToTenant`（globalScope `tenant` + save 自动补 tenant_id）
  挂载于 14 个业务模型；显式指定的 `tenant_id` 优先（跨租户建站长账号用）。
  注意：scope 中列名带表别名/表名限定，避免 LEFT JOIN users 时 `ambiguous column name: tenant_id`。
- **账号体系**：`users` 唯一键改 `(tenant_id, username)`，各租户用户名互不冲突；
  `is_super_admin` 扩展为「username=admin 或持有全部权限」（租户站长由此获得本站超管能力）；
  新增 `is_platform_admin()`（仅主站管理员）守卫入驻管理接口。
- **全局表不隔离**：`blocked_ips`（防爆破按 IP 全局）、`email_codes`、`tenants`、`tenant_applications`。

### 数据库变更

- 新表：`tenants`、`tenant_applications`
- 15 张表加 `tenant_id INT UNSIGNED NOT NULL DEFAULT 1` + 索引：
  users / teams / images / gun_codes / gun_code_comments / chat_messages / announcements /
  race_events / custom_race_events / guides / admin_logs / settings / rotation_maps / rotation_days / rotation_day_maps
- `users` 删 `uk_username` 加 `UNIQUE(tenant_id, username)`；`teams` 同理改 `(tenant_id, name)`；`settings` 主键改 `(tenant_id, key)`
- **存量升级**：执行 `database/game_team_upgrade_v4_0_0.sql`（幂等，仅执行一次；tenant_id 默认 1 对存量无损）
- 全量导入：`database/game_team.sql` / `game_team_phpmyadmin.sql` 已同步 v4 结构
- 本地开发：`scripts/dev_init_sqlite.php` 已同步 v4（含主站租户行 seed）

### 部署（二级域名生效条件）

1. 域名服务商添加泛解析：`*.dpx1.icu` A 记录 → 服务器 IP
2. 申请通配符 SSL 证书 `*.dpx1.icu`（宝塔一键 Let's Encrypt，DNS 验证）
3. 宝塔现有站点域名列表追加 `*.dpx1.icu` 挂通配符证书（ThinkPHP 按 Host 分流，无需多站点）
4. 详见 `deploy/nginx.conf.example` 文末步骤

### 测试记录（SQLite e2e，Host 头模拟域名）

- ✅ 入驻提交 / slug 查重 / 保留字拒绝 / 同 QQ 待审重复提交拦截 / 租户域名提交 404
- ✅ 审核通过 → 租户 + 站长账号 + 默认 settings 事务创建；申请状态查询
- ✅ 租户 branding（info 接口返回正确 slug/name/is_main）
- ✅ 隔离验证：租户发公告/聊天 → 主站列表完全不可见（反向亦然）；跨租户 cookie 401 纵深防御
- ✅ 停用租户 → API 403 + 页面停用提示；恢复；主站 id=1 禁止停用
- ✅ 租户站长 is_super=true / is_platform=false；租户站点访问入驻管理接口 403
- ✅ 主站回归：登录/仪表盘/用户/图片/公告/枪码/攻略/轮换/日志/IP封禁 全部 code=0
- ✅ 浏览器实测：/apply 页面 UI、slug 实时查重红绿提示、侧栏「战队入驻」入口、入驻审核 Tab 双表格

### 已知说明

- slug 规则：`[a-z0-9][a-z0-9-]{1,28}[a-z0-9]`；保留字：www/api/app/admin/login/register/static/storage/uploads/mail/smtp/dev/test/main
- 同一 IP 每小时最多提交 3 次入驻申请
- 租户站点的管理员分权、注册审核等功能完全复用现有模块（各自租户内独立运作）
