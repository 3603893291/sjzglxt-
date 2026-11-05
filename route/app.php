<?php
// +----------------------------------------------------------------------
// | 路由定义 v2
// +----------------------------------------------------------------------
use think\facade\Route;

// ============ 页面 ============
Route::get('/', 'Index/index');
Route::get('/login', 'Index/loginPage');
Route::get('/register', 'Index/registerPage');
Route::get('/forgot', 'Index/forgotPage');   // v5.7.1(L1)：忘记密码（仅战队站点）
Route::get('/app', 'Index/appPage');
// v5.36.13：平台协作入口（/{slug}/support/enter?token=...，一次性票据兑票，不需要本站登录态）
Route::get('/support/enter', 'Support/enter');

// ============ v5.10.0：合规公开页与 Cookie 同意 API（平台与战队站点均可访问） ============
Route::get('/privacy', 'Compliance/privacy');
Route::get('/terms',   'Compliance/terms');
Route::get('/api/cookie-consent',  'Compliance/consentGet');
Route::post('/api/cookie-consent', 'Compliance/consentSubmit');

// ============ 站点品牌（v5.0.0：平台/租户各自返回自身信息，公开） ============
Route::get('/api/tenant/info', 'Tenant/info');

// ============ v5.20.0：战队对外展示页与公示大厅（访客可访问，不挂任何登录中间件） ============
//   GET  /{slug}/show          单队展示页，服务端直出（战队上下文；未开启/被平台下架/站点停用一律 404）
//   GET  /hall                 平台公示大厅（仅根路径；平台总开关关掉后 404）
//   GET  /show/captcha/{key}   图形验证码出图（缓存里没有这张码就 404，不画空白图）
//   POST /api/show/submit      访客提交：type=post 内容投稿 / type=intent 入队意向
// 展示页只渲染 Showcase::visible() 通过的内容，成员 QQ/邮箱/IP 一律不下发（详见 app/service/Showcase.php）
Route::get('/show', 'Showcase/page');
Route::get('/hall', 'Showcase/hall');
Route::get('/show/captcha/:key', 'Showcase/captcha');
Route::post('/api/show/submit', 'Showcase/submit');

// ============ QQ 机器人 Webhook 回调（v5.8.0：根级，无登录/租户中间件） ============
// QQ 开放平台把群 @消息事件 POST 到这里。官方只对 op:13 的 URL 校验要求应答签名，
// 事件推送没有入站验签头，因此来源辨识靠回调地址里的随机密钥：
//   /api/bot/callback/{key} —— 战队独立机器人（bot_tenant_bots.callback_key）。
//   v5.28.0：平台公共机器人下线，无密钥的 /api/bot/callback 这条旧地址已从路由撤掉。
// 其余防线：事件 ID 去重 + 「已绑定群才受理指令」 + 绑定需管理中心回填验证码。
Route::post('/api/bot/callback/:key', 'BotCallback/callbackWithKey');

// ============ 入驻平台 API（v5.0.0：仅根路径受理，控制器内平台上下文守卫） ============
Route::post('/api/platform/auth/register', 'Platform/register');
Route::post('/api/platform/auth/login', 'Platform/login');
Route::post('/api/platform/auth/logout', 'Platform/logout');
Route::get('/api/platform/auth/me', 'Platform/me');
Route::get('/api/platform/check-slug', 'Platform/checkSlug');
Route::post('/api/platform/apply', 'Platform/apply');
Route::get('/api/platform/my', 'Platform/my');
Route::post('/api/platform/my/admin-account', 'Platform/adminAccount');
Route::post('/api/platform/my/admin-password', 'Platform/adminPassword');
Route::get('/api/platform/my/login-logs', 'Platform/loginLogs');          // v5.1.0：登录日志
Route::post('/api/platform/my/login-alerts/read', 'Platform/loginAlertsRead'); // v5.17.0：提醒已读
Route::get('/api/platform/admin/login-logs', 'Platform/adminLoginLogs');  // v5.17.0：跨站点登录日志
Route::get('/api/platform/admin/log-stats', 'Platform/logStats');         // v5.17.0：各战队日志条数统计
Route::post('/api/platform/admin/log-cleanup', 'Platform/logCleanup');    // v5.17.0：按日期清理战队日志
Route::post('/api/platform/my/status', 'Platform/myStatus');              // v5.1.0：自助暂停/恢复站点
Route::post('/api/platform/transfer/start', 'Platform/transferStart');    // v5.1.0：战队转移
Route::post('/api/platform/transfer/:id/accept', 'Platform/transferAccept');
Route::post('/api/platform/transfer/:id/reject', 'Platform/transferReject');
Route::post('/api/platform/transfer/:id/cancel', 'Platform/transferCancel');
// v5.2.0：站点数据导入导出（战队端：提交审核制）
Route::get('/api/platform/my/data/tasks', 'Platform/dataTasks');
Route::post('/api/platform/my/data/export', 'Platform/dataExportRequest');
Route::post('/api/platform/my/data/import', 'Platform/dataImportRequest');
Route::post('/api/platform/my/data/:id/cancel', 'Platform/dataTaskCancel');
Route::get('/api/platform/my/data/:id/download', 'Platform/dataTaskDownload');
Route::get('/api/platform/admin/applications', 'Platform/applications');
Route::post('/api/platform/admin/applications/:id/approve', 'Platform/approve');
Route::post('/api/platform/admin/applications/:id/reject', 'Platform/reject');
Route::get('/api/platform/admin/tenants', 'Platform/tenants');
Route::post('/api/platform/admin/tenants/:id/status', 'Platform/setTenantStatus');
Route::post('/api/platform/admin/tenants/:id/quota', 'Platform/setQuota');        // v5.1.0：存储配额
Route::post('/api/platform/admin/tenants/:id/stealth', 'Platform/stealth');       // v5.1.0：隐身巡查入口
Route::post('/api/platform/admin/tenants/:id/stealth-revoke', 'Platform/stealthRevoke'); // v5.16.0：撤销巡查
Route::post('/api/platform/admin/tenants/:id/support', 'Platform/supportEnter');         // v5.36.13：平台协作入口
Route::get('/api/platform/admin/stealth-audits', 'Platform/stealthAudits');              // v5.16.0：巡查审计
Route::post('/api/platform/admin/tenants/:id/notify', 'Platform/notify');         // v5.1.0：给战队发通知

// v5.36.32：封满 15 天的战队，平台超管可整队销毁（备份包 + 登记表）
Route::post('/api/platform/admin/tenants/:id/purge-preview', 'Platform/purgePreview');
Route::post('/api/platform/admin/tenants/:id/purge',         'Platform/purgeRun');
Route::get ('/api/platform/admin/purged',                    'Platform/purgedList');
Route::get ('/api/platform/admin/purged/download',           'Platform/purgedDownload');
// v5.1.0：轮换地图收归平台超管统一配置（全战队共用一套）
Route::get('/api/platform/admin/rotation', 'Platform/rotation');
Route::post('/api/platform/admin/rotation/save', 'Platform/rotationSave');
Route::post('/api/platform/admin/rotation/maps/save', 'Platform/rotationMapSave');
Route::delete('/api/platform/admin/rotation/maps/:id', 'Platform/rotationMapRemove');
Route::post('/api/platform/admin/rotation/days/save', 'Platform/rotationDaySave');
Route::delete('/api/platform/admin/rotation/days/:id', 'Platform/rotationDayRemove');
// v5.2.0：数据导入导出审核（平台超管）
Route::get('/api/platform/admin/data/tasks', 'Platform/dataTasksAdmin');
Route::post('/api/platform/admin/data/:id/approve', 'Platform/dataTaskApprove');
Route::post('/api/platform/admin/data/:id/reject', 'Platform/dataTaskReject');
Route::get('/api/platform/admin/data/:id/download', 'Platform/dataTaskDownloadAdmin');
// v5.4.0：跨战队约战总线（平台超管：全平台总览/代发起/抽签/逐局录入/强制取消/名单调配）
Route::get('/api/platform/admin/battles', 'Platform/battles');
Route::get('/api/platform/admin/battles/:id', 'Platform/battleDetail');
Route::post('/api/platform/admin/battles', 'Platform/battleCreate');
Route::post('/api/platform/admin/battles/:id/draw', 'Platform/battleDraw');
Route::post('/api/platform/admin/battles/:id/round/:rid/result', 'Platform/battleRoundResult');
Route::post('/api/platform/admin/battles/:id/advantage', 'Platform/battleAdvantage');   // 赛事组指定优势方（预留）
Route::post('/api/platform/admin/battles/:id/decider', 'Platform/battleDecider');       // 代优势方选图/选边
Route::post('/api/platform/admin/battles/:id/cancel', 'Platform/battleCancel');
Route::post('/api/platform/admin/battles/:id/roster', 'Platform/battleRoster');
// v5.4.0：外部战队登记与 API 密钥管理（平台超管）
Route::get('/api/platform/admin/external-teams', 'Platform/externalTeams');
Route::post('/api/platform/admin/external-teams/save', 'Platform/externalTeamSave');
Route::get('/api/platform/admin/api-keys', 'Platform/apiKeys');
Route::post('/api/platform/admin/api-keys/issue', 'Platform/apiKeyIssue');
Route::post('/api/platform/admin/api-keys/:id/status', 'Platform/apiKeyStatus');
Route::delete('/api/platform/admin/api-keys/:id', 'Platform/apiKeyRemove');
// v5.5.0：赛事中心互通配置（平台超管：赛事站地址/Key）
Route::get('/api/platform/admin/tournament-config', 'Platform/tournamentConfig');
Route::post('/api/platform/admin/tournament-config', 'Platform/tournamentConfigSave');
// v5.5.1：赛事中心多站点绑定（平台共享库）
Route::get('/api/platform/admin/tournament-bindings', 'Platform/tournamentBindings');
Route::post('/api/platform/admin/tournament-bindings', 'Platform/tournamentBindingSave');
Route::post('/api/platform/admin/tournament-bindings/:id', 'Platform/tournamentBindingSave');
Route::delete('/api/platform/admin/tournament-bindings/:id', 'Platform/tournamentBindingRemove');
// v5.28.0：平台侧不再持有机器人凭据，只留两件事——出站敏感词、跨战队调用日志
Route::get('/api/platform/admin/bot-words', 'Platform/botWords');
Route::post('/api/platform/admin/bot-words', 'Platform/botWordsSave');
Route::get('/api/platform/admin/bot-logs', 'Platform/botLogs');
Route::post('/api/platform/admin/bot-logs/clear', 'Platform/botLogsClear');

// ============ v5.10.0：平台运营与合规（平台超管） ============
// SEO / 隐私政策 / Cookie 同意 / Sitemap / 死链 / API 日志 / 图片压缩
Route::get ('/api/platform/admin/seo',           'Platform/seo');
Route::post('/api/platform/admin/seo',           'Platform/seoSave');
Route::get ('/api/platform/admin/policies',      'Platform/policies');
Route::post('/api/platform/admin/policies',      'Platform/policiesSave');
Route::get ('/api/platform/admin/cookie-consent','Platform/cookieConsent');
Route::post('/api/platform/admin/cookie-consent','Platform/cookieConsentSave');
Route::get ('/api/platform/admin/sitemap',           'Platform/sitemapStatus');
Route::post('/api/platform/admin/sitemap/generate',  'Platform/sitemapGenerate');
Route::post('/api/platform/admin/sitemap/toggle',    'Platform/sitemapToggle');
// 对外展示页与公示大厅（v5.20.0：平台侧治理）——总开关、一键下架某站、焦点区精选。
// 下架优先于战队自己的开关，违规内容要能立刻断掉；每次操作写进那一站的操作日志
Route::get ('/api/platform/admin/showcase',          'Platform/showList');
Route::post('/api/platform/admin/showcase/hall',     'Platform/showHallToggle');
Route::post('/api/platform/admin/showcase/force',    'Platform/showForceToggle');
Route::post('/api/platform/admin/showcase/featured', 'Platform/showFeaturedSave');

// 私聊·平台治理（v5.30.1 把举报与禁言从战队侧整体搬到这里；v5.32.0 论坛与大厅下线，这一页只剩私聊）
// 私聊正文只有顺着当事人的一条举报才读得到，每读一次都往 admin_logs(tenant_id=0) 记一行
Route::get ('/api/platform/admin/chat/reports',        'PlatformChat/reports');
Route::post('/api/platform/admin/chat/report/open',    'PlatformChat/openReport');
Route::post('/api/platform/admin/chat/report/handle',  'PlatformChat/handleReport');
// 禁言分来源：平台下的（muted_by=platform）战队队长解不掉，本队自设的那条仍由本队自己解
Route::post('/api/platform/admin/mute',                'PlatformChat/mute');
Route::post('/api/platform/admin/unmute',              'PlatformChat/unmute');
Route::get ('/api/platform/admin/mute-state',          'PlatformChat/muteState');
Route::get ('/api/platform/admin/mutes',               'PlatformChat/mutes');       // v5.32.0：大厅下线后，平台禁言名单与解禁入口
// 赛前提醒总闸（v5.33.0）：提醒往每个队的公告里写，出问题时这里要有一把能一把按住的手
Route::get ('/api/platform/admin/remind/config',       'PlatformRemind/config');
Route::post('/api/platform/admin/remind/config',       'PlatformRemind/configSave');
Route::post('/api/platform/admin/remind/tick',         'PlatformRemind/tick');
// 社区论坛对接（v5.35.0）：数据要出站，所以平台侧既有一把按全站的总闸，也能只停某一站，
// 并且战队那边读得到「是平台挡的」这句话，不是对着一个 403 猜。这里只写停不停，不改队长自己那一格。
Route::get ('/api/platform/admin/forum-api',           'PlatformForumApi/config');
Route::post('/api/platform/admin/forum-api/master',    'PlatformForumApi/master');
Route::post('/api/platform/admin/forum-api/toggle',    'PlatformForumApi/toggle');
// 站点活跃（v5.35.1）：按账号最后活跃时间看哪个队还在真的用；只给平台超管，只看每队的数字
Route::get ('/api/platform/admin/active',              'PlatformActive/index');
// 平台邮件群发（v5.31.0）：只平台超管能用。发信用平台自己那一套 p_mail_*，与各战队的 smtp_* 互不相干；
// 没预览过这一份「标题+正文」建不了批；队列一次 HTTP 只推进一步，页面关了只是暂停
Route::get ('/api/platform/admin/mail/config',    'PlatformMail/config');
Route::post('/api/platform/admin/mail/config',    'PlatformMail/configSave');
Route::post('/api/platform/admin/mail/recipients','PlatformMail/recipients');
Route::post('/api/platform/admin/mail/render',    'PlatformMail/renderSamples');
Route::post('/api/platform/admin/mail/preview',   'PlatformMail/preview');
Route::post('/api/platform/admin/mail/blast',     'PlatformMail/blast');
Route::post('/api/platform/admin/mail/step',      'PlatformMail/step');
Route::post('/api/platform/admin/mail/stop',      'PlatformMail/stop');
Route::post('/api/platform/admin/mail/resume',    'PlatformMail/resume');
Route::post('/api/platform/admin/mail/requeue',   'PlatformMail/requeue');
Route::get ('/api/platform/admin/mail/blasts',    'PlatformMail/blasts');
Route::get ('/api/platform/admin/mail/blast',     'PlatformMail/blastDetail');
// 强制平台公告（v5.36.31）：平台超管写一条，全体已上线成员登录战队就弹，点「我已知晓」才走
Route::get ('/api/platform/admin/announcements',      'PlatformAnnounceAdmin/index');
Route::get ('/api/platform/admin/announcement',       'PlatformAnnounceAdmin/detail');
Route::post('/api/platform/admin/announcement/save',   'PlatformAnnounceAdmin/save');
Route::post('/api/platform/admin/announcement/status', 'PlatformAnnounceAdmin/status');
Route::post('/api/platform/admin/announcement/delete', 'PlatformAnnounceAdmin/remove');
Route::post('/api/platform/admin/announcement/mute',   'PlatformAnnounceAdmin/mute');
Route::get   ('/api/platform/admin/broken-links',              'Platform/brokenLinks');
Route::post  ('/api/platform/admin/broken-links/scan',          'Platform/brokenLinkScan');
Route::post  ('/api/platform/admin/broken-links/add',           'Platform/brokenLinkAdd');
Route::post  ('/api/platform/admin/broken-links/:id/resolve',   'Platform/brokenLinkResolve');
Route::post  ('/api/platform/admin/broken-links/:id/unresolve', 'Platform/brokenLinkUnresolve');
Route::delete('/api/platform/admin/broken-links/:id',           'Platform/brokenLinkRemove');
Route::get ('/api/platform/admin/api-logs',        'Platform/apiLogs');
Route::post('/api/platform/admin/api-logs/clear',  'Platform/apiLogsClear');
Route::get ('/api/platform/admin/api-rate',        'Platform/apiRateConfig');
Route::post('/api/platform/admin/api-rate',        'Platform/apiRateConfigSave');
Route::get ('/api/platform/admin/image-compress',  'Platform/imageCompress');
Route::post('/api/platform/admin/image-compress',  'Platform/imageCompressSave');

// 未晞通行证（v5.36.6）：平台超管填 Client ID / Secret、回调地址并启停；成员原有密码登录不受影响
Route::get ('/api/platform/admin/passport',      'PassportAdmin/index');
Route::post('/api/platform/admin/passport/save', 'PassportAdmin/save');
Route::post('/api/platform/admin/passport/test', 'PassportAdmin/test');

// QQ 机器人 AI 聊天（v5.36.14）：平台出首选模型与提示词、给战队发 Token；
// 不读战队群聊天正文，用量页只有 Token 数字。
Route::get ('/api/platform/admin/ai',                     'PlatformAi/index');
Route::post('/api/platform/admin/ai/save',                'PlatformAi/save');
Route::post('/api/platform/admin/ai/test',                'PlatformAi/test');
Route::get ('/api/platform/admin/ai/tenants',             'PlatformAi/tenants');
Route::post('/api/platform/admin/ai/tick',                'PlatformAi/tick');
Route::post('/api/platform/admin/ai/tenants/:id/grant',   'PlatformAi/grant');
Route::post('/api/platform/admin/ai/tenants/:id/reset',   'PlatformAi/reset');
Route::post('/api/platform/admin/ai/tenants/:id/disable', 'PlatformAi/disable');
// v5.36.29：模型名自动获取 + 人设库 + 表情包库
Route::post('/api/platform/admin/ai/models',              'PlatformAi/models');
Route::get ('/api/platform/admin/ai/prompts',             'PlatformAi/prompts');
Route::post('/api/platform/admin/ai/prompts/save',        'PlatformAi/promptSave');
Route::post('/api/platform/admin/ai/prompts/:id/delete',  'PlatformAi/promptDelete');
Route::get ('/api/platform/admin/ai/stickers',            'PlatformAi/stickers');
Route::post('/api/platform/admin/ai/stickers/upload',     'PlatformAi/stickerUpload');
Route::post('/api/platform/admin/ai/stickers/save',       'PlatformAi/stickerSave');
Route::post('/api/platform/admin/ai/stickers/:id/delete', 'PlatformAi/stickerDelete');

// ============ 认证 API ============
Route::post('/api/auth/register', 'Auth/register');
Route::post('/api/auth/login', 'Auth/login');
Route::post('/api/auth/logout', 'Auth/logout');
Route::post('/api/auth/forgot', 'Auth/forgot');   // v5.7.1(L1)：忘记密码申请（截图佐证 + 管理员审核）
// v5.36.34：找回密码的第二条路 —— 邮箱验证码当场改密码，与上面那条截图审核并存，谁先做成算谁
Route::get ('/api/auth/reset-options', 'Auth/resetOptions');    // 这条路现在通不通（平台发信配好没有）
Route::post('/api/auth/send-code',     'Auth/sendResetCode');   // 索取验证码（账号存在与否回同一句）
Route::post('/api/auth/reset-by-code', 'Auth/resetByCode');     // 码对上就改密码，不用等审核

// ============ 未晞通行证（v5.36.6：额外登录方式，密码登录保留） ============
//   GET /{slug}/passport/start    发起授权（?remember=1 勾选 7 天免登录）
//   GET /passport/callback        通行证唯一回调地址（根路径，所有战队共用）
//   GET /{slug}/passport/finish   回调兑换票落地到该战队的会话
//   GET /{slug}/passport/link     已登录成员绑定通行证
//   GET /{slug}/passport/claim    首次使用：验证本站账号密码后绑定
//   GET /api/passport/config      登录页公开配置（是否开启/是否可用，不含密钥）
Route::get('/passport/start', 'Passport/start');
// v5.36.8：入驻平台一键登录（通行证 → 战队超管绑定 → 平台入驻账号）
Route::get('/passport/platform/start', 'Passport/platformStart');
Route::get('/passport/callback', 'Passport/callback');
Route::get('/passport/finish', 'Passport/finish');
Route::get('/passport/link', 'Passport/link');
Route::get('/passport/claim', 'Passport/claim');
Route::post('/passport/claim', 'Passport/claimSubmit');
Route::get('/api/passport/config', 'Passport/config');

// ============ 约战开放 API（v5.4.0：X-Api-Key 鉴权，读写全开——开源单租户接入总线主通道） ============
Route::group('/api/open/battles', function () {
    Route::get('/', 'OpenBattle/index');
    Route::get('/map-pool', 'OpenBattle/mapPool');
    Route::get('/:id', 'OpenBattle/detail');
    Route::post('/', 'OpenBattle/create');
    Route::post('/:id/accept', 'OpenBattle/accept');
    Route::post('/:id/reject', 'OpenBattle/reject');
    Route::post('/:id/cancel', 'OpenBattle/cancel');
    Route::post('/:id/ban', 'OpenBattle/ban');
    Route::post('/:id/draw', 'OpenBattle/draw');
    Route::post('/:id/round/:rid/result', 'OpenBattle/roundResult');
    Route::post('/:id/advantage', 'OpenBattle/advantage');
    Route::post('/:id/decider', 'OpenBattle/decider');
    Route::post('/:id/roster', 'OpenBattle/roster');
    Route::post('/:id/signup', 'OpenBattle/signup');
})->middleware(\app\middleware\ApiKeyAuth::class);
// v5.5.0：赛事中心互通开放 API（X-Api-Key 鉴权）
// 赛事站持 platform Key 拉取租户/外部战队名单做身份核验
Route::get('/api/open/tournament-parties/tenants', 'Platform/tournamentPartiesTenants')
    ->middleware(\app\middleware\ApiKeyAuth::class);
Route::get('/api/open/tournament-parties/external-teams', 'Platform/tournamentPartiesExternal')
    ->middleware(\app\middleware\ApiKeyAuth::class);
// v5.33.0：赛前提醒的「准时刻」那一条路——计划任务凭平台作用域的 X-Api-Key 打这条，不认登录态；
// 站长不配它也照样工作（有人打开站点时顺路查一次，见 EventReminder::tickLazy）
Route::post('/api/open/remind/tick', 'PlatformRemind/tick')
    ->middleware(\app\middleware\ApiKeyAuth::class);
// v5.36.14：AI 主动发言的准点路——计划任务凭平台作用域 X-Api-Key 打这条；
// 不配也照样工作（有人打开站点或回调时顺路查一次，见 AiChat::tickLazy / runAfterResponse）
Route::post('/api/open/ai/tick', 'PlatformAi/tick')
    ->middleware(\app\middleware\ApiKeyAuth::class);
// v5.35.0：社区论坛对接 —— 给队长自己另一个项目的论坛只读拉数。
// 四条全是 GET（这一版不接任何写回），鉴权/限频/留痕沿用上面那条私有接口通道；
// 三道闸（本队开关 + 平台总闸 + 平台按站点停用）在 ForumOpen::gate 里过。
Route::group('/api/open/forum', function () {
    Route::get('/team', 'ForumOpen/team');
    Route::get('/members', 'ForumOpen/members');
    Route::get('/activity', 'ForumOpen/activity');
    Route::get('/notices', 'ForumOpen/notices');
})->middleware(\app\middleware\ApiKeyAuth::class);
// 连接器站凭 external Key 经主站透传赛事站（连接器 → 主站 → 赛事站）
Route::group('/api/open/tournament', function () {
    Route::get('', 'OpenTournament/tournaments');
    Route::get('/', 'OpenTournament/tournaments');
    Route::get('/my', 'OpenTournament/my');
    Route::get('/:id', 'OpenTournament/detail');
    Route::post('/apply', 'OpenTournament/apply');
    Route::post('/withdraw', 'OpenTournament/withdraw');
    Route::post('/roster', 'OpenTournament/roster');
    Route::post('/squads', 'OpenTournament/squads');
    Route::post('/match-squads', 'OpenTournament/matchSquads');
})->middleware(\app\middleware\ApiKeyAuth::class);
// v5.3.0：me 纳入 AuthCheck 保护组——会话过期时凭免登录 cookie 静默恢复后再返回用户信息
// （未登录仍返回 401，前端探测登录状态的行为不变）

// ============ 业务 API（需登录） ============
Route::group('/api', function () {
    Route::get('/auth/me', 'Auth/me');
    // v5.36.13：平台协作（队长授权/撤销/暂不授权/退出协作）
    Route::get('/support/status', 'Support/status');
    Route::post('/support/grant', 'Support/grant');
    Route::post('/support/revoke', 'Support/revoke');
    Route::post('/support/dismiss', 'Support/dismiss');
    Route::post('/support/exit', 'Support/exitSupport');
    // v5.7.1(M2)：退出隐身巡查（作废巡查 cookie，跳回平台控制台）
    Route::get('/auth/stealth-exit', 'Auth/stealthExit');
    // 仪表盘
    Route::get('/dashboard', 'Dashboard/index');
    Route::get('/races', 'Dashboard/races');
    Route::get('/custom-races', 'Dashboard/customRaces');  // v3.9.6：自建房比赛日程（公开）

    // 个人中心
    Route::post('/profile/nickname', 'Profile/updateNickname');
    Route::post('/profile/password', 'Profile/updatePassword');
    Route::post('/profile/screenshot', 'Profile/updateScreenshot');  // v3.9.2：更换注册截图，回炉重新审核
    Route::post('/profile/role-tags', 'Profile/updateRoleTags');     // v5.3.0：职责标签自选（单选）
    Route::post('/profile/show-public', 'Profile/showPublic');      // v5.20.0：本人是否愿意出现在战队对外展示页
    Route::post('/profile/stats-public', 'Profile/statsPublic');    // v5.30.0：本人是否愿意把战绩给本队成员看（另一档开关）

    // 未晞通行证（v5.36.6）：查绑定状态 / 解除绑定。绑定入口走 /passport/link（GET 跳转到通行证）
    Route::get('/passport/binding', 'Passport/binding');
    Route::post('/passport/unbind', 'Passport/unbind');

    // 成员名录与成员主页（v5.30.0）：普通成员也看得到的那一版，只有显示名/职责标签/在线与否，
    // 战绩按上面那个开关走；带账号与 IP 的那份名单在 /api/admin/users，不在这里
    Route::get('/members', 'Members/index');
    Route::get('/members/:id', 'Members/show');

    // 个人黑名单（v5.30.1）：每人最多 10 个、只能拉其它战队的人，只对本人视角生效
    Route::get('/blocks', 'Block/index');
    Route::post('/blocks/add', 'Block/add');
    Route::post('/blocks/remove', 'Block/remove');

    // 头像代理（v3.4：前端只传 user_id，QQ 号不出前端）
    Route::get('/avatar/:uid', 'Avatar/show');

    // 游戏图片
    Route::get('/images/my', 'Image/my');
    Route::get('/images', 'Image/all');
    Route::post('/images/upload', 'Image/upload');
    // 图片代理输出（通过控制器鉴权，禁止直链访问待审核/未登录）
    Route::get('/images/raw/:id', 'Image/raw');

    // 聊天室
    Route::get('/chat/messages', 'Chat/messages');
    Route::post('/chat/send', 'Chat/send');
    Route::post('/chat/recall/:id', 'Chat/recall');
    Route::post('/chat/global-mute', 'Chat/toggleGlobalMute');
    // v5.19.0：聊天室拆成「战队群 + 私聊」；在线人数两个页签共用一份口径
    Route::get('/chat/online', 'Chat/online');
    Route::get('/chat/dm/conversations', 'ChatDm/conversations');
    Route::get('/chat/dm/thread', 'ChatDm/thread');
    Route::get('/chat/dm/candidates', 'ChatDm/candidates');
    Route::post('/chat/dm/send', 'ChatDm/send');
    Route::post('/chat/dm/recall', 'ChatDm/recall');
    Route::post('/chat/dm/my-setting', 'ChatDm/mySetting');
    Route::post('/chat/dm/report', 'ChatDm/report');
    Route::get('/chat/dm/my-settings', 'ChatDm/mySettings');   // v5.30.1：个人中心那两个开关的当前值


    // 改枪码
    Route::get('/guncodes', 'GunCode/all');
    Route::post('/guncodes', 'GunCode/create');
    // 枪码评论（v3.2：发/查/删，禁言用户拦截）
    Route::get('/guncodes/:id/comments', 'GunCode/comments');
    Route::post('/guncodes/:id/comments', 'GunCode/addComment');
    Route::delete('/guncodes/comments/:cid', 'GunCode/deleteComment');
    Route::put('/guncodes/:id', 'GunCode/update');
    Route::post('/guncodes/:id/toggle', 'GunCode/toggleStatus');
    Route::delete('/guncodes/:id', 'GunCode/remove');

    // 游戏攻略（v3.7：用户自发布文章页）
    Route::get('/guides', 'Guide/all');
    Route::get('/guides/:id', 'Guide/detail');
    Route::post('/guides', 'Guide/create');
    Route::post('/guides/:id', 'Guide/update');
    Route::post('/guides/:id/toggle', 'Guide/toggle');
    Route::delete('/guides/:id', 'Guide/remove');
    Route::get('/guides/raw/:id', 'Guide/raw');

    // 今天轮换地图（v3.8：全局开关 + 按天数轮换）
    Route::get('/rotation', 'Rotation/index');

    // 查询伪军（v3.8：按昵称查是否本战队成员）
    Route::get('/search/traitor', 'Search/traitor');

    // 职责标签（v5.3.0：标签库列表，供个人主页选择）
    Route::get('/role-tags', 'RoleTag/all');

    // 每日签到（v5.3.0：状态查询 / 执行签到）
    Route::get('/checkin', 'Checkin/index');
    Route::post('/checkin', 'Checkin/checkin');

    // 战队小队（自由加入/退出）
    Route::get('/teams/my', 'Team/my');
    Route::get('/teams', 'Team/all');
    Route::post('/teams/join', 'Team/join');
    Route::post('/teams/leave', 'Team/leave');

    // 跨战队约战（v5.4.0：本队相关列表/详情/地图池/本人报名）
    Route::get('/battles/map-pool', 'Battle/mapPool');
    Route::get('/battles/:id', 'Battle/detail');
    Route::get('/battles', 'Battle/index');
    Route::post('/battles/:id/signup', 'Battle/signup');

    // 约战中心连接器（v5.4.0：单租户开源部署接入平台总线，未接入时统一提示）
    Route::get('/battle-hub/status', 'BattleHub/status');
    Route::get('/battle-hub/battles', 'BattleHub/battles');
    Route::get('/battle-hub/map-pool', 'BattleHub/mapPool');
    Route::get('/battle-hub/battles/:id', 'BattleHub/detail');
    Route::post('/battle-hub/battles/:id/accept', 'BattleHub/accept');
    Route::post('/battle-hub/battles/:id/reject', 'BattleHub/reject');
    Route::post('/battle-hub/battles/:id/cancel', 'BattleHub/cancel');
    Route::post('/battle-hub/battles/:id/ban', 'BattleHub/ban');
    Route::post('/battle-hub/battles/:id/round/:rid/result', 'BattleHub/roundResult');
    Route::post('/battle-hub/battles/:id/decider', 'BattleHub/decider');
    Route::post('/battle-hub/battles/:id/signup', 'BattleHub/signup');
    Route::post('/battle-hub/battles/:id/roster', 'BattleHub/roster');

    // 赛事中心·租户代理（v5.5.0：查询登录即可；报名/名单/小队需管理员 + tournament 权限）
    // v5.5.1：多站点绑定（bindings 系列须在 :id 前，避免被通配吞掉）
    Route::get('/tournament-center/bindings', 'Tournament/bindings');
    Route::get('/tournament-center/bindings-mine', 'Tournament/bindingsMine');
    Route::post('/tournament-center/bindings/add', 'Tournament/bindingAdd');
    Route::post('/tournament-center/bindings/confirm', 'Tournament/bindingConfirm');
    Route::post('/tournament-center/bindings/remove', 'Tournament/bindingRemove');
    Route::get('/tournament-center', 'Tournament/tournaments');
    Route::get('/tournament-center/my', 'Tournament/my');
    Route::get('/tournament-center/:id', 'Tournament/detail');
    Route::post('/tournament-center/apply', 'Tournament/apply');
    Route::post('/tournament-center/withdraw', 'Tournament/withdraw');
    Route::post('/tournament-center/roster', 'Tournament/roster');
    Route::post('/tournament-center/squads', 'Tournament/squads');
    Route::post('/tournament-center/match-squads', 'Tournament/matchSquads');

    // 公告送达确认（v5.6.0：成员端未读列表 + 打开即已读）
    Route::get('/notices', 'Notice/index');
    Route::post('/notices/:id/read', 'Notice/read');

    // 强制平台公告（v5.36.31：平台超管写的那一条，登录后挡在界面最前面）
    // 与上面 /notices 那两条是两套东西：那是战队自己发的公告栏，这是全平台强制弹的那一条
    Route::get('/force-notice/current', 'ForceNotice/current');
    Route::post('/force-notice/ack',    'ForceNotice/ack');
    Route::post('/force-notice/mute',   'ForceNotice/mute');

    // 值班表（v5.6.0：今日值班卡片 + 换班发起/确认）
    Route::get('/duty/today', 'Duty/today');
    Route::get('/duty/swaps', 'Duty/swaps');
    Route::post('/duty/swaps/request', 'Duty/requestSwap');
    Route::post('/duty/swaps/:id/resolve', 'Duty/resolveSwap');

    // 统一日程（v5.6.0：聚合撞车/自建房/约战/赛事/自定义活动/值班）
    Route::get('/schedule', 'Schedule/index');

    // 队内训练赛（v5.6.0：create 等具体路径须在 :id 前）
    Route::get('/trainings', 'Training/index');
    Route::post('/trainings/create', 'Training/create');
    Route::get('/trainings/:id', 'Training/detail');
    Route::post('/trainings/:id/signup', 'Training/signup');
    Route::post('/trainings/:id/assign', 'Training/assign');
    Route::post('/trainings/:id/draw', 'Training/draw');
    Route::post('/trainings/:id/random-maps', 'Training/randomMaps');
    Route::post('/trainings/:id/round/:rid/result', 'Training/roundResult');
    Route::post('/trainings/:id/cancel', 'Training/cancel');

    // 轻量投票（v5.6.0：列表/详情/投票/补充选项）
    Route::get('/votes', 'Voting/index');
    Route::get('/votes/:id', 'Voting/detail');
    Route::post('/votes/:id/cast', 'Voting/cast');
    Route::post('/votes/:id/options', 'Voting/addOption');

    // 比赛日集合点名（v5.36.34：成员点「到了/迟到/不来」，一人一行覆盖自己那行）
    Route::get('/rollcall', 'RollCall/index');
    Route::get('/rollcall/:id', 'RollCall/detail');
    Route::post('/rollcall/:id/reply', 'RollCall/reply');


    // 站内信（v5.32.0：论坛下线后从 /forum/* 搬出来，红点与「通知」页都读这三条）
    // 路径用 inbox：/api/notices 那三条是公告栏（Notice 控制器，v5.6.0），两个东西不是一回事
    Route::get ('/inbox', 'Inbox/index');
    Route::post('/inbox/read', 'Inbox/read');
    // 平台邮件群发同时补的那条，点开看整篇正文，只认「这条是发给我的」
    Route::get ('/inbox/blast', 'Inbox/blast');
    // 抽奖大转盘（v5.7.0：成员查看奖池概率 / 转盘抽奖）
    Route::get('/lottery', 'Lottery/index');
    Route::post('/lottery/spin', 'Lottery/spin');

    // QQ 机器人成员绑定（v5.8.0：个人中心取码，群内 @机器人 发「绑定 六位码」关联身份）
    Route::get('/bot/status', 'Bot/status');
    Route::post('/bot/bind-code', 'Bot/bindCode');
    Route::post('/bot/unbind', 'Bot/unbind');

    // 成员战绩（v5.11.0：成员用账号密码登录后上传自己的战绩，只写本人一行；仅本人与持 stats 权限的管理员可见）
    Route::get('/stats/my', 'Stats/my');
    Route::post('/stats/upload', 'Stats/upload');
    // v5.36.0：全队战绩排行（只算自愿公开战绩的人；四个指标由 ?metric= 选）
    Route::get('/stats/ranking', 'Stats/ranking');
})->middleware(\app\middleware\AuthCheck::class);

// ============ 管理中心 API（需管理员） ============
Route::group('/api/admin', function () {
    // 注册审核
    Route::get('/review', 'Admin/reviewList');
    Route::post('/review/:id', 'Admin/reviewUser');

    // v5.7.1(L1)：忘记密码申请审核（approve 权限）
    Route::get('/pwd-resets', 'Admin/pwdResetList');
    Route::post('/pwd-resets/:id', 'Admin/pwdResetReview');

    // 用户管理 + 权限组 + 禁言
    Route::get('/users', 'Admin/users');
    Route::post('/users/:id/status', 'Admin/setUserStatus');
    Route::post('/users/:id/role', 'Admin/setUserRole');
    Route::post('/users/:id/mute', 'Admin/setUserMute');
    Route::delete('/users/:id', 'Admin/removeUser');

    // 修改用户名字（v3.9）
    Route::post('/users/:id/rename', 'Admin/renameUser');

    // 管理员权限分配（v3.9：仅超管）
    Route::get('/perms', 'Admin/perms');
    Route::post('/perms/save', 'Admin/permsSave');

    // 战队小队管理
    Route::get('/teams', 'Admin/teams');
    Route::post('/teams/save', 'Admin/saveTeam');
    Route::delete('/teams/:id', 'Admin/removeTeam');
    Route::post('/teams/assign', 'Admin/assignTeam');  // v3.9.6：管理员分配成员到小队

    // 职责标签管理（v5.3.0：role_tag 权限）
    Route::get('/role-tags', 'Admin/roleTags');
    Route::post('/role-tags/save', 'Admin/roleTagSave');
    Route::post('/role-tags/toggle-user-edit', 'Admin/roleTagToggleUserEdit');
    Route::delete('/role-tags/:id', 'Admin/roleTagRemove');
    Route::post('/users/:id/role-tags', 'Admin/assignUserTags');  // v5.3.0：给用户分配标签（多选）

    // 图片审核 + 删除
    Route::get('/images', 'Admin/images');
    Route::post('/images/:id/review', 'Admin/reviewImage');
    Route::delete('/images/:id', 'Admin/removeImage');

    // 公告管理
    Route::get('/announcements', 'Admin/announcements');
    Route::post('/announcements/save', 'Admin/saveAnnouncement');
    Route::delete('/announcements/:id', 'Admin/removeAnnouncement');
    // v5.36.34：撤回与恢复（撤回只盖时间戳不删行，队长按得动恢复那颗钮）
    Route::post('/announcements/:id/revoke',  'Admin/revokeAnnouncement');
    Route::post('/announcements/:id/restore', 'Admin/restoreAnnouncement');

    // 撞车时间管理
    Route::get('/races', 'Admin/races');
    Route::post('/races/save', 'Admin/saveRace');
    Route::delete('/races/:id', 'Admin/removeRace');

    // 自建房比赛日程管理（v3.9.6）
    Route::get('/custom-races', 'Admin/customRaces');
    Route::post('/custom-races/save', 'Admin/saveCustomRace');
    Route::delete('/custom-races/:id', 'Admin/removeCustomRace');

    // IP 封禁管理（v3.2：防爆破自动封禁 + 手动封禁/解封）
    Route::get('/blocked-ips', 'Admin/blockedIps');
    Route::post('/blocked-ips', 'Admin/blockIp');
    Route::delete('/blocked-ips/:id', 'Admin/unblockIp');

    // 多账户检测（v3.5：按注册 IP 查小号）
    Route::get('/multi-accounts', 'Admin/multiAccounts');

    // 重置用户密码（v3.7：仅限非管理员账号）
    Route::post('/users/:id/password', 'Admin/setUserPassword');

    // 管理操作日志（v3.7：查看 + 超管清空）
    Route::get('/logs', 'Admin/logs');
    Route::post('/logs/clear', 'Admin/clearLogs');
    // v5.17.0：管理员登录日志与新归属地提醒（列表需 login_log 权限；「我的提醒」任何管理员可读自己那条）
    Route::get('/login-logs', 'Admin/loginLogs');
    Route::get('/login-alerts', 'Admin/loginAlerts');
    Route::post('/login-alerts/read', 'Admin/loginAlertsRead');

    // 聊天消息清理（v3.7：仅超管，清理 N 天前的旧消息）
    Route::post('/chat/cleanup', 'Admin/chatCleanup');

    // 存储空间（v5.1.0：配额/用量查看 + 清理；轮换地图已收归入驻平台统一管理）
    Route::get('/storage', 'Admin/storage');
    Route::post('/storage/delete', 'Admin/storageDelete');

    // 平台通知（v5.1.0：入驻平台超管定向发给本站管理员的公告栏；v5.2.0：已阅标记）
    Route::get('/platform-notices', 'Admin/platformNotices');
    Route::post('/platform-notices/read', 'Admin/platformNoticesRead');

    // 跨战队约战管理（v5.4.0：battle 权限；发起/应战/禁图/名单/取消）
    Route::get('/battles', 'BattleAdmin/index');
    Route::post('/battles', 'BattleAdmin/create');
    Route::get('/battles/pending', 'BattleAdmin/pending');            // v5.13.0：待本队同意（管理员强制弹窗）
    Route::get('/battle-notice', 'BattleAdmin/noticeConfig');         // v5.13.0：成立自动公告开关（读）
    Route::post('/battle-notice', 'BattleAdmin/noticeSave');          // v5.13.0：成立自动公告开关（存）
    Route::post('/battles/:id/accept', 'BattleAdmin/accept');
    Route::post('/battles/:id/reject', 'BattleAdmin/reject');
    Route::post('/battles/:id/cancel', 'BattleAdmin/cancel');
    Route::post('/battles/:id/ban', 'BattleAdmin/ban');
    Route::post('/battles/:id/roster', 'BattleAdmin/roster');
    Route::post('/battles/:id/round/:rid/result', 'BattleAdmin/roundResult');  // 训练赛双方管理自裁决（可附截图）
    Route::post('/battles/:id/decider', 'BattleAdmin/decider');                // 决胜局选图/选边（优势方）
    Route::delete('/battles/:id', 'BattleAdmin/remove');                       // v5.6.1：删除已过期/已完赛（含已录分）约战

    // API 密钥（v5.4.0：仅超管，生成本队 tenant 键供私有客户端用）
    Route::get('/api-keys', 'BattleAdmin/apiKeys');
    Route::post('/api-keys', 'BattleAdmin/apiKeyCreate');
    Route::post('/api-keys/:id/status', 'BattleAdmin/apiKeyStatus');
    Route::delete('/api-keys/:id', 'BattleAdmin/apiKeyRemove');

    // 约战中心连接配置（v5.4.0：仅超管，连接器模式开关/地址/Key）
    Route::get('/battle-hub-config', 'BattleAdmin/hubConfig');
    Route::post('/battle-hub-config', 'BattleAdmin/hubConfigSave');

    // 黑名单管理（v5.6.0：blacklist 权限）
    Route::get('/blacklist', 'BlacklistAdmin/index');
    Route::post('/blacklist/save', 'BlacklistAdmin/save');
    Route::delete('/blacklist/:id', 'BlacklistAdmin/remove');

    // 公告送达确认（v5.6.0：沿用 announce 权限）
    Route::get('/announcements/:id/receipt', 'Notice/receipt');
    Route::post('/announcements/:id/remind', 'Notice/remind');

    // 值班表管理（v5.6.0：duty 权限）
    Route::get('/duty', 'DutyAdmin/index');
    Route::post('/duty/config', 'DutyAdmin/saveConfig');
    Route::post('/duty/members', 'DutyAdmin/saveMembers');
    Route::delete('/duty/members/:id', 'DutyAdmin/removeMember');

    // 统一日程·自定义活动管理（v5.6.0：schedule 权限）
    Route::get('/schedule/events', 'Schedule/events');
    Route::post('/schedule/events/save', 'Schedule/saveEvent');
    Route::delete('/schedule/events/:id', 'Schedule/removeEvent');
    Route::post('/schedule/hidden', 'Schedule/saveHidden');

    // 比赛日集合点名管理（v5.36.34：race 或 schedule 权限，控制器方法内另有 admin_can_any 守卫）
    Route::get('/rollcall', 'RollCall/adminList');
    Route::post('/rollcall/save', 'RollCall/save');
    Route::post('/rollcall/:id/revoke', 'RollCall/revoke');
    Route::post('/rollcall/:id/restore', 'RollCall/restore');
    Route::post('/rollcall/:id/broadcast', 'RollCall/broadcast');
    Route::delete('/rollcall/:id', 'RollCall/remove');

    // 轻量投票管理（v5.6.0：vote 权限；控制器方法内另有 admin_can 守卫）
    Route::post('/votes/create', 'Voting/create');
    Route::post('/votes/:id/close', 'Voting/close');
    Route::delete('/votes/:id', 'Voting/remove');

    // 抽奖管理（v5.7.0：lottery 权限；奖池/奖品/机会发放/中奖核销）
    Route::get('/lottery', 'LotteryAdmin/index');
    Route::post('/lottery/pools/save', 'LotteryAdmin/savePool');
    Route::delete('/lottery/pools/:id', 'LotteryAdmin/removePool');
    Route::post('/lottery/prizes/save', 'LotteryAdmin/savePrize');
    Route::delete('/lottery/prizes/:id', 'LotteryAdmin/removePrize');
    Route::post('/lottery/grant', 'LotteryAdmin/grant');
    Route::post('/lottery/logs/:id/fulfill', 'LotteryAdmin/fulfill');

    // QQ 机器人（v5.8.0：bot 权限；回填群验证码绑定 / 解绑 / 成员绑定名单）
    Route::get('/bot', 'BotAdmin/index');
    Route::post('/bot/verify', 'BotAdmin/verify');
    Route::post('/bot/unbind', 'BotAdmin/unbind');
    Route::get('/bot/members', 'BotAdmin/members');
    Route::delete('/bot/members/:id', 'BotAdmin/removeMember');
    // v5.9.0：战队机器人配置（AppID/AppSecret/环境/启用开关/回调地址）
    Route::get('/bot/config', 'BotAdmin/config');
    Route::post('/bot/config', 'BotAdmin/config');
    // v5.28.0：连接测试与群指令面板从平台端搬到这里（各战队管自己那台机器人）
    Route::post('/bot/config/test', 'BotAdmin/test');
    // v5.36.15：机器人自检——群里没反应时，在面板上看卡在哪一步（只读，不外呼）
    Route::get ('/bot/diagnose', 'BotAdmin/diagnose');
    Route::post('/bot/panel/sync', 'BotAdmin/panelSync');
    Route::post('/bot/panel/remove', 'BotAdmin/panelRemove');

    // QQ 机器人 AI 聊天（v5.36.14：bot 权限；平台模型要 Token，自有模型自己填）
    Route::get ('/ai',              'AiAdmin/index');
    Route::post('/ai/save',         'AiAdmin/save');
    Route::post('/ai/test',         'AiAdmin/test');
    Route::post('/ai/models',       'AiAdmin/models');
    Route::post('/ai/sticker-test', 'AiAdmin/stickerTest');
    Route::post('/ai/sticker-logs/clear', 'AiAdmin/stickerLogsClear');
    Route::post('/ai/clear-context','AiAdmin/clearContext');
    Route::post('/ai/proactive-test','AiAdmin/proactiveTest');

    // 战绩信息（v5.11.0：stats 权限；全战队成员战绩列表 + 任意指标排序，只读）
    Route::get('/stats', 'StatsAdmin/index');
    // v5.36.0：管理端全队排行（与成员页同一算法，不受「战绩公开」限制）
    Route::get('/stats/ranking', 'StatsAdmin/ranking');
    // 登录页配图（v5.12.0：仅站长/内置超管可换本队登录页那张海报，未设置时用内置默认）
    Route::get('/poster', 'PosterAdmin/index');
    Route::post('/poster', 'PosterAdmin/save');

    // 私聊治理（v5.19.0：chat_report 权限）——管理员默认只见元数据，
    // 正文必须顺着当事人的一条举报才能展开，且每次展开都写进本队操作日志
    // 私聊治理整体搬到平台侧（v5.30.1，见 PlatformChat）：上面那四条 /chat/dm-stats、/chat/reports、
    // /chat/report-open、/chat/report-dismiss 已从路由下架（老调用现在直接 404）。
    // 战队这边只剩「本站要不要开放私聊」这一个功能开关，正文与举报处理都不在战队侧了。
    Route::post('/chat/dm-toggle', 'ChatAdmin/dmToggle');
    // 站名显示（v5.30.1）：左上角要不要自动补「战队」两个字，默认补，队长可以关掉
    Route::post('/brand-suffix', 'Admin/brandSuffix');


    // 对外展示页（v5.20.0：showcase 权限）——编辑本站主页 + 处理访客投递的两个箱子。
    // 精选内容只能勾本站仍然有效的行，访客 QQ 只在这组接口里给管理员看全
    Route::get('/showcase', 'ShowcaseAdmin/index');
    Route::post('/showcase/save', 'ShowcaseAdmin/save');
    Route::post('/showcase/image', 'ShowcaseAdmin/uploadImage');
    Route::post('/showcase/item-add', 'ShowcaseAdmin/itemAdd');
    Route::post('/showcase/item-save', 'ShowcaseAdmin/itemSave');
    Route::post('/showcase/item-move', 'ShowcaseAdmin/itemMove');
    Route::post('/showcase/item-del', 'ShowcaseAdmin/itemDel');
    Route::get('/showcase/subs', 'ShowcaseAdmin/subs');
    Route::post('/showcase/sub-review', 'ShowcaseAdmin/subReview');
    Route::post('/showcase/sub-del', 'ShowcaseAdmin/subDel');

    // 赛前提醒（v5.33.0：schedule 权限）：训练赛/队内日程/跨队约战 开打前推给本队
    Route::get('/remind', 'RemindAdmin/index');
    Route::post('/remind/save', 'RemindAdmin/save');
    Route::post('/remind/run', 'RemindAdmin/run');

    // 一键导出表格（v5.34.0）：成员名单/战绩/签到/活动报名拉成 xlsx 或 csv。
    // 能导哪几张按站内看那张表的那把权限闸，文件里 QQ 一律打码。
    Route::get('/export', 'ExportAdmin/index');
    Route::get('/export/file', 'ExportAdmin/file');

    // 社区论坛对接（v5.35.0）：这一页只管「本队开不开、给谁读、最近谁来调过」；
    // 密钥仍在本队 API 密钥那一格发（同一把 scope=tenant 的 Key，不另发一枚同类东西）
    Route::get('/forum-api', 'ForumAdmin/index');
    Route::post('/forum-api/toggle', 'ForumAdmin/toggle');

    // 端手分离（v5.36.0）：总开关与八块模块开关只给队长本人；成员分类走用户管理那一格
    Route::get('/game-side', 'GameSideAdmin/index');
    Route::post('/game-side/save', 'GameSideAdmin/save');
    Route::post('/users/:id/game-side', 'GameSideAdmin/saveUser');
})->middleware(\app\middleware\AdminCheck::class);

// ============ 隐身巡查只读台账（v5.16.0：仅隐身巡查会话可达） ============
// 普通成员/管理员/站长乃至超管的常规登录态都会被判 403，平台超管只有凭巡查令牌兑出的
// 会话才进得来；每条路由都是独立只读 SQL，避开若干 GET 路径里藏着的写操作。
Route::group('/api/inspect', function () {
    Route::get('/overview',         'Inspect/overview');
    Route::get('/members',          'Inspect/members');          // 全量：完整 QQ / 邮箱 / 注册 IP
    Route::get('/pending',          'Inspect/pending');
    Route::get('/blacklist',        'Inspect/blacklist');
    Route::get('/password-resets',  'Inspect/passwordResets');
    Route::get('/logs',             'Inspect/logs');
    Route::get('/images',           'Inspect/images');
    Route::get('/player-stats',     'Inspect/playerStats');
    Route::get('/battles',          'Inspect/battles');
})->middleware(\app\middleware\StealthRead::class);
