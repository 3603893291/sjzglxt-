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

// ============ 站点品牌（v5.0.0：平台/租户各自返回自身信息，公开） ============
Route::get('/api/tenant/info', 'Tenant/info');

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
Route::post('/api/platform/admin/tenants/:id/notify', 'Platform/notify');         // v5.1.0：给战队发通知
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

// ============ 认证 API ============
Route::post('/api/auth/register', 'Auth/register');
Route::post('/api/auth/login', 'Auth/login');
Route::post('/api/auth/logout', 'Auth/logout');
Route::post('/api/auth/forgot', 'Auth/forgot');   // v5.7.1(L1)：忘记密码申请（截图佐证 + 管理员审核）

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

    // 抽奖大转盘（v5.7.0：成员查看奖池概率 / 转盘抽奖）
    Route::get('/lottery', 'Lottery/index');
    Route::post('/lottery/spin', 'Lottery/spin');
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
})->middleware(\app\middleware\AdminCheck::class);
