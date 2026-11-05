<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\QqBot;
use app\service\TenantContext;
use think\facade\Db;

/**
 * QQ 机器人·管理端（v5.8.0，bot 权限）
 *
 * v5.28.0：机器人只有「本战队自己那一台」——凭据、环境、连接测试、群指令面板都在这里管，
 * 平台侧不再持有机器人凭据，也不再决定哪支战队能用。
 *
 * 群绑定流程（单群模式：一个战队一个群）：
 *   1. 群管理员在群里 @机器人 发「绑定」→ 机器人回复 6 位验证码（10 分钟有效）
 *   2. 战队管理员在本面板回填验证码 → 校验通过后写入绑定
 *   3. 解绑双向：本面板解绑 / 群内成员各自解绑自己的账号绑定
 */
class BotAdmin extends AuthBase
{
    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    /** 绑定状态概览 */
    public function index()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $b   = Db::name('bot_group_bindings')->where('tenant_id', $tid)->find();
        return api_ok([
            'bound'           => (bool)$b,
            'group_openid'    => $b ? (string)$b['group_openid'] : '',
            'bound_at'        => $b ? (string)$b['created_at'] : '',
            'member_bindings' => (int)Db::name('bot_user_bindings')->where('tenant_id', $tid)->count(),
            // v5.28.0：可用性就等于本队独立机器人是否配齐并启用
            'bot_enabled'     => QqBot::ownBot($tid) !== null,
            'has_config'      => Db::name('bot_tenant_bots')->where('tenant_id', $tid)->find() !== null,
        ]);
    }

    /** 战队独立机器人配置（已启用且凭据齐全返回配置行，否则 null） */
    private function ownBot(int $tid): ?array
    {
        return QqBot::ownBot($tid);
    }

    /** 回填群绑定验证码，完成群绑定 */
    public function verify()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $code = trim((string)$this->request->post('code', ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            return api_err('请输入 6 位数字验证码');
        }
        $tid = TenantContext::id();
        // v5.28.0：先有自己的机器人再谈绑群（平台公共机器人已下线）
        $ownBot = $this->ownBot($tid);
        if (!$ownBot) {
            return api_err('请先在本页填写 AppID 与 AppSecret 并打开启用开关，保存后再回填群绑定验证码');
        }
        if (Db::name('bot_group_bindings')->where('tenant_id', $tid)->find()) {
            return api_err('本战队已绑定 QQ 群（一队一群），如需更换请先解绑');
        }

        // v5.15.0(S-M3)：bot_group_codes 不记归属战队（6 位码本身就是「谁能绑这个群」的能力
        // 凭据），而回填侧原先一次失败都不计数 —— 10^6 的码空间配上不限速的接口是可以硬撞的。
        // 这里补两道计数：本队一小时的总尝试数、同一枚码的尝试数，超限直接拒。
        $tryTeam = rate_bump('bot_bind_try_t_' . $tid, 3600);
        if ($tryTeam > 30) {
            return api_err('绑定尝试过多，请 1 小时后再试', 429, 429);
        }
        $tryCode = rate_bump('bot_bind_try_c_' . $tid . '_' . $code, 600);
        if ($tryCode > 8) {
            return api_err('该验证码尝试次数过多，请到群里重新获取一个', 429, 429);
        }

        $row = Db::name('bot_group_codes')
            ->where('code', $code)
            ->whereNull('used_at')
            ->order('id', 'desc')->find();
        if (!$row) {
            return api_err('验证码不存在。请群管理员在群里 @机器人 发送「绑定」获取最新验证码');
        }
        if ((int)$row['expire_at'] < time()) {
            return api_err('验证码已过期。请群管理员在群里 @机器人 发送「绑定」重新获取');
        }
        if (Db::name('bot_group_bindings')->where('group_openid', $row['group_openid'])->find()) {
            return api_err('该群已被其他战队绑定');
        }

        try {
            Db::name('bot_group_bindings')->insert([
                'tenant_id'    => $tid,
                'group_openid' => (string)$row['group_openid'],
                'bound_by'     => current_user()->id,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 唯一键冲突：并发下被其他战队抢先绑定该群
            return api_err('该群刚刚被其他战队绑定');
        }
        Db::name('bot_group_codes')->where('id', $row['id'])->update(['used_at' => time()]);
        \think\facade\Cache::delete('bot_bind_try_t_' . $tid);   // v5.15.0(S-M3)：成功即清零
        Db::name('bot_group_codes')->where('expire_at', '<', time() - 7 * 86400)->delete();
        admin_log('QQ机器人', '绑定 QQ 群 ' . substr((string)$row['group_openid'], 0, 12) . '…');

        // 面板是本队机器人的资源：首次绑群时建自己的面板（best-effort，失败不影响绑定，
        // 但要把失败原因带回去，别让管理端只看到「已生成」而群里一直没有按钮）
        $panelNote = '';
        $panelId = (string)$ownBot['panel_id'];
        if ($panelId === '') {
            $panelId = QqBot::panelCreateWithBot($ownBot, [(string)$row['group_openid']]);
            if ($panelId !== '') {
                Db::name('bot_tenant_bots')->where('id', $ownBot['id'])->update(['panel_id' => $panelId]);
            } else {
                $panelNote = '；群已绑定，但指令面板创建失败，请到上方点「生成指令面板」重试';
            }
        } else {
            [$okPanel, $panelErr] = QqBot::panelTargetWithBot($ownBot, $panelId, 'add', [(string)$row['group_openid']]);
            if (!$okPanel) {
                $panelNote = '；群已绑定，但指令面板关联失败（' . $panelErr . '），请到上方点「重新生成面板」';
            }
        }
        // 群里主动通知（best-effort；QQ 官方 2025-04 起已停发机器人主动消息，送不达属正常）
        $notified = QqBot::sendGroupTextWithBot((string)$row['group_openid'],
            '绑定成功！现在 @我 发送「帮助」查看指令菜单，成员请先绑定账号（个人中心获取验证码）。',
            (string)$ownBot['callback_key']);
        $baseMsg = $notified ? '绑定成功'
            : '绑定成功（群内通知未送达：QQ 官方已限制机器人主动消息，不影响群内指令使用）';
        return api_ok([], $baseMsg . $panelNote);
    }

    /** 解绑群（成员的个人绑定保留，重新绑群后仍可用） */
    public function unbind()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $b   = Db::name('bot_group_bindings')->where('tenant_id', $tid)->find();
        if (!$b) {
            return api_err('本战队尚未绑定 QQ 群');
        }
        Db::name('bot_group_bindings')->where('id', $b['id'])->delete();
        admin_log('QQ机器人', '解绑 QQ 群');
        $ownBot = $this->ownBot($tid);
        if ($ownBot) {
            $panelId = (string)$ownBot['panel_id'];
            if ($panelId !== '') {
                QqBot::panelTargetWithBot($ownBot, $panelId, 'del', [(string)$b['group_openid']]);
            }
            QqBot::sendGroupTextWithBot((string)$b['group_openid'],
                '本群已与战队站点解绑，机器人指令已停用。', (string)$ownBot['callback_key']);
        }
        return api_ok([], '已解绑');
    }

    /** 成员绑定名单 */
    public function members()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid  = TenantContext::id();
        $rows = Db::name('bot_user_bindings')->alias('b')
            ->leftJoin('users u', 'u.id = b.user_id AND u.tenant_id = b.tenant_id')
            ->where('b.tenant_id', $tid)
            ->field('b.id, b.user_id, b.created_at, COALESCE(u.nickname, "已注销用户") AS nickname')
            ->order('b.id', 'desc')
            ->limit(200)
            ->select()->toArray();
        return api_ok(['list' => $rows]);
    }

    /** 战队机器人配置（查看/保存） */
    public function config()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();

        if ($this->request->isPost()) {
            return $this->configSave($tid);
        }

        return $this->configShow($tid);
    }

    /** 显示机器人配置 */
    private function configShow(int $tid): \think\response\Json
    {
        $botConfig = Db::name('bot_tenant_bots')->where('tenant_id', $tid)->find();
        if (!$botConfig) {
            return api_ok([
                'has_config'        => false,
                'app_id'            => '',
                'secret_set'        => false,
                'secret_masked'     => '',
                'enabled'           => 0,
                'env'               => 'prod',
                'callback_url'      => '',
                'panel_id'          => '',
            ]);
        }

        return api_ok([
            'has_config'        => true,
            'app_id'            => $botConfig['app_id'],
            'secret_set'        => $botConfig['app_secret'] !== '',
            'secret_masked'     => $botConfig['app_secret'] !== '' ? substr($botConfig['app_secret'], 0, 4) . str_repeat('*', 8) : '',
            'enabled'           => (int)$botConfig['enabled'],
            'env'               => strtolower((string)($botConfig['env'] ?? '')) === 'sandbox' ? 'sandbox' : 'prod',
            'callback_url'      => $botConfig['callback_key'] !== '' ? '/api/bot/callback/' . $botConfig['callback_key'] : '',
            'panel_id'          => $botConfig['panel_id'],
        ]);
    }

    /** 保存机器人配置 */
    private function configSave(int $tid): \think\response\Json
    {
        // v5.9.0：clear=1 彻底删除战队机器人配置（含 Secret / 回调密钥 / 面板引用）
        if ((string)$this->request->post('clear', '0') === '1') {
            $own = $this->ownBot($tid);
            if ($own && (string)$own['panel_id'] !== '') {
                // best-effort：顺带删除该独立机器人在 QQ 侧的指令面板（失败不影响删除）
                QqBot::panelDeleteWithBot($own, (string)$own['panel_id']);
            }
            Db::name('bot_tenant_bots')->where('tenant_id', $tid)->delete();
            admin_log('QQ机器人', '删除战队独立机器人配置');
            return api_ok([], '战队机器人配置已删除，群内指令不再响应；需要时重新填写并启用');
        }

        $appId   = trim((string)$this->request->post('app_id', ''));
        $secret  = trim((string)$this->request->post('app_secret', ''));
        $enabled = (int)(bool)$this->request->post('enabled', 0);
        // v5.28.0：接口环境跟着战队自己走（原来这份是平台公共机器人的设置）
        $env     = strtolower(trim((string)$this->request->post('env', ''))) === 'sandbox' ? 'sandbox' : 'prod';

        if ($appId !== '' && (strlen($appId) > 64 || !preg_match('/^[A-Za-z0-9_]+$/', $appId))) {
            return api_err('AppID 格式不正确（仅字母/数字/下划线，最长 64 字符）');
        }
        if ($secret !== '' && strlen($secret) > 128) {
            return api_err('AppSecret 过长');
        }
        // 启用时凭据必须齐全（Secret 留空表示沿用已保存的原值）
        if ($enabled === 1) {
            $oldSecret = (string)Db::name('bot_tenant_bots')->where('tenant_id', $tid)->value('app_secret');
            if ($appId === '' || ($secret === '' && trim($oldSecret) === '')) {
                return api_err('启用独立机器人需要同时填写 AppID 和 AppSecret');
            }
        }

        // 生成随机回调密钥（如果不存在）
        $callbackKey = '';
        $botConfig = Db::name('bot_tenant_bots')->where('tenant_id', $tid)->find();
        if ($botConfig) {
            $callbackKey = (string)$botConfig['callback_key'];
        } else {
            $callbackKey = substr(md5(random_bytes(16)), 0, 32);
        }

        // Secret 明文入库与平台机器人保持一致（站点与库均已设防），查看仅回显打码版；
        // 未填写新 secret 时保持原有值不变
        $existingSecret = $botConfig ? (string)$botConfig['app_secret'] : '';
        $savedSecret = $secret !== '' ? $secret : $existingSecret;

        $data = [
            'app_id'      => $appId,
            'app_secret'  => $savedSecret,
            'callback_key' => $callbackKey,
            'enabled'     => $enabled,
            'env'         => $env,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        // 换凭据后旧 Token 缓存自然失效（缓存键里带 AppID+Secret 的指纹），不必手动清

        if ($botConfig) {
            Db::name('bot_tenant_bots')->where('tenant_id', $tid)->update($data);
            admin_log('QQ机器人', '更新战队机器人配置');
        } else {
            $data['tenant_id'] = $tid;
            $data['created_at'] = date('Y-m-d H:i:s');
            Db::name('bot_tenant_bots')->insert($data);
            admin_log('QQ机器人', '创建战队机器人配置');
        }

        return api_ok([], '配置已保存');
    }

    // ==================== v5.28.0：连接测试与群指令面板（从平台端搬来） ====================

    /** 连接测试：拿本队已保存的凭据真去要一次 Access Token */
    public function test()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        if (rate_bump('bot_test_' . $tid, 60) > 6) {
            return api_err('测试太频繁了，请一分钟后再试', 429, 429);
        }
        $row = Db::name('bot_tenant_bots')->where('tenant_id', $tid)->find();
        if (!$row || trim((string)$row['app_id']) === '' || trim((string)$row['app_secret']) === '') {
            return api_err('请先填写并保存 AppID 与 AppSecret，再测连接');
        }
        $c = QqBot::configWithBot($row);
        if ($c['app_id'] === 'mock') {
            return api_ok([], '本地调试模式（AppID=mock）：不外呼，发送内容写入运行日志');
        }
        $token = QqBot::tokenWithBot($row);
        if ($token !== '') {
            return api_ok([], '连接成功，Access Token 获取正常（' . ($c['env'] === 'sandbox' ? '沙箱' : '正式') . '环境）');
        }
        $detail = QqBot::lastError();
        return api_err('连接失败：请核对 AppID 与 AppSecret（复制时别带多余空格），或稍后再试'
            . ($detail !== '' ? '。上游返回：' . mb_substr($detail, 0, 200) : ''));
    }

    /** 群指令面板：创建或更新本队机器人的面板，并把已绑定的群关联进去 */
    public function panelSync()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $row = Db::name('bot_tenant_bots')->where('tenant_id', $tid)->find();
        if (!$row || trim((string)$row['app_id']) === '') {
            return api_err('请先配置本战队的机器人（AppID 与 AppSecret）');
        }
        $group  = trim((string)Db::name('bot_group_bindings')->where('tenant_id', $tid)->value('group_openid'));
        $groups = $group !== '' ? [$group] : [];
        [$ok, $msg, $panelId] = QqBot::syncPanelWithBot($row, $groups);
        if (!$ok) {
            return api_err($msg);
        }
        admin_log('QQ机器人', '同步指令面板 ' . $panelId);
        return api_ok(['panel_id' => $panelId], $msg . ($groups === [] ? '（本队还没绑群，绑好后再生成一次即可关联）' : ''));
    }

    /** 删除本队的群指令面板 */
    public function panelRemove()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $row = Db::name('bot_tenant_bots')->where('tenant_id', $tid)->find();
        $panelId = $row ? trim((string)$row['panel_id']) : '';
        if ($panelId === '') {
            return api_err('本战队还没有指令面板');
        }
        [$ok, $err] = QqBot::panelDeleteWithBot($row, $panelId);
        if (!$ok) {
            return api_err('删除失败：' . $err);
        }
        admin_log('QQ机器人', '删除指令面板 ' . $panelId);
        return api_ok([], '指令面板已删除');
    }

    /** 移除某条成员绑定（成员更换 QQ 身份后由管理员清理） */
    public function removeMember($id)
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $row = Db::name('bot_user_bindings')
            ->where('id', (int)$id)->where('tenant_id', $tid)->find();
        if (!$row) {
            return api_err('绑定记录不存在', 404);
        }
        Db::name('bot_user_bindings')->where('id', $row['id'])->delete();
        admin_log('QQ机器人', '移除成员绑定 #' . (int)$id);
        return api_ok([], '已移除');
    }

    /**
     * 机器人自检（v5.36.15）：群里 @ 了没反应时，先看这里卡在哪一步。
     * 只读：查配置、绑定、运行时日志，不外呼 QQ、不改任何数据。
     *
     * v5.36.22：自检本身绝不再 500——任何一步抛错都换成一句可读的原因返回，
     * 否则线上看不到错在哪（生产环境 app_debug 关闭，页面只有「系统发生错误」）。
     */
    public function diagnose()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        try {
            return $this->buildDiagnose();
        } catch (\Throwable $e) {
            $where = basename($e->getFile()) . ':' . $e->getLine();
            try {
                QqBot::debugLog(['type' => 'diag_error', 'error' => $e->getMessage(), 'where' => $where]);
            } catch (\Throwable $ignore) {
            }
            try {
                \think\facade\Log::error('[bot diagnose] ' . $e->getMessage() . ' @ ' . $where);
            } catch (\Throwable $ignore) {
            }
            return api_ok(self::scrub([
                'version'       => defined('ASSET_BUILD') ? ASSET_BUILD : '',
                'verdict'       => '自检本身报错了：' . $e->getMessage() . '（' . $where . '）',
                'verdict_level' => 'error',
                'checks'        => [],
                'stats'         => [],
                'events'        => [],
                'errors'        => [],
                'calls'         => [],
                'jobs'          => [],
            ]));
        }
    }

    /** 自检主体（抛错由 diagnose 兜住并转成一句话） */
    private function buildDiagnose()
    {
        $tid = TenantContext::id();
        $checks = [];
        $push = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok ? 1 : 0, 'detail' => $detail];
        };

        // ---- 1. 代码文件是否都更新到了（AI 版本会调用新方法） ----
        $need = [
            '指令服务 BotCommand::handleWithBotEx' => [\app\service\BotCommand::class, 'handleWithBotEx'],
            'AI 服务 AiChat::offerAt'               => [\app\service\AiChat::class, 'offerAt'],
            '模型客户端 AiClient::chat'             => [\app\service\AiClient::class, 'chat'],
        ];
        foreach ($need as $label => $pair) {
            $ok  = false;
            $err = '';
            try {
                $ok = class_exists($pair[0]) && method_exists($pair[0], $pair[1]);
            } catch (\Throwable $e) {
                $err = $e->getMessage();
            }
            $push($label, $ok, $ok ? '' : '文件没覆盖完整或加载失败：' . ($err !== '' ? $err : '缺少方法'));
        }

        // ---- 2. 数据表 ----
        foreach (['bot_tenant_bots', 'bot_group_bindings', 'bot_call_logs', 'bot_ai_jobs', 'ai_usage_logs', 'tenant_ai_configs', 'bot_chat_messages'] as $table) {
            $ok  = true;
            $err = '';
            try {
                Db::name($table)->count();
            } catch (\Throwable $e) {
                $ok  = false;
                $err = mb_substr($e->getMessage(), 0, 120);
            }
            $push('数据表 ' . $table, $ok, $ok ? '' : '查询失败：' . $err . '（升级 SQL 可能没跑完）');
        }

        // ---- 3. 机器人配置 ----
        $bot = Db::name('bot_tenant_bots')->where('tenant_id', $tid)->find();
        $push('本队机器人已配置', $bot !== null, $bot ? '' : '先在下方填 AppID / AppSecret 并保存');
        $enabled = $bot && (int)($bot['enabled'] ?? 0) === 1;
        $push('机器人已启用', $enabled, $enabled ? '' : '关闭状态群里一律不回复');
        $cred = $bot && trim(self::str($bot['app_id'] ?? '')) !== '' && trim(self::str($bot['app_secret'] ?? '')) !== '';
        $push('AppID / AppSecret 齐全', $cred);

        // ---- 3.6 AI 聊天能不能接上（指令以外的消息都转给它） ----
        $aiCfg  = \app\service\AiClient::tenantConfig($tid);
        $aiMode = (string)($aiCfg['mode'] ?? 'off');
        $aiOk   = false;
        $aiWhy  = '';
        $aiSrc  = '';
        $aiModel = '';
        try {
            $prov    = \app\service\AiClient::resolveProvider($tid);
            $aiOk    = (bool)($prov['ok'] ?? false);
            $aiWhy   = $aiOk ? '' : (string)($prov['error'] ?? '');
            $aiSrc   = (string)($prov['source'] ?? '');
            $aiModel = (string)($prov['model'] ?? '');
        } catch (\Throwable $e) {
            $aiWhy = '检测失败：' . $e->getMessage();
        }
        $push('AI 聊天已开启（本队）', $aiMode !== 'off',
            $aiMode !== 'off' ? '模式：' . ($aiMode === 'own' ? '自有模型' : '平台模型')
                              : '没开：@我 聊天不会回复（管理中心 → AI 聊天 → 选模型并打开）');
        $push('AI 模型可调用', $aiOk,
            $aiOk ? ('可用：' . ($aiSrc === 'own' ? '自有模型 ' : '平台模型 ') . $aiModel) : ('接不通：' . $aiWhy));
        $push('异步回复队列', true, '任务在回调结束后处理；下面「最近 AI 任务」能看到每条的状态');
        // 队列表真的能写吗（升级 SQL 少跑一列，入队就会静默失败，群里只看到「没法识别」）
        $jobsWritable = true;
        $jobsWhy = '';
        try {
            Db::name('bot_ai_jobs')->where('id', -1)
                ->field('id,status,attempts,error,reply_deadline,created_at')->select();
        } catch (\Throwable $e) {
            $jobsWritable = false;
            $jobsWhy = mb_substr($e->getMessage(), 0, 160);
        }
        $push('AI 队列可写入', $jobsWritable, $jobsWritable ? '' : 'bot_ai_jobs 字段不全或表有问题：' . $jobsWhy . '（升级 SQL 没跑完）');
        $backlog = 0;
        try {
            $backlog = (int)Db::name('bot_ai_jobs')->where('tenant_id', $tid)
                ->whereIn('status', ['pending', 'processing'])->count();
        } catch (\Throwable $e) {
        }
        $push('AI 队列没有积压', $backlog < 20, '当前排队/处理中 ' . $backlog . ' 条' . ($backlog >= 20 ? '（达到 20 条上限，新消息会被拒收）' : ''));
        // 回调结束后到底有没有人真的在处理队列（没人处理时任务会一直 pending，群里就没回复）
        $beat = (int)\think\facade\Cache::get('ai_drain_beat', 0);
        $beatAgo = $beat > 0 ? max(0, time() - $beat) : null;
        $push('AI 后台处理在跑', $beatAgo !== null && $beatAgo <= 600,
            $beatAgo === null ? '还没跑过：说明回调结束后队列没有被处理（PHP 进程被提前结束），AI 永远回不了'
                : ('上次处理队列：' . self::ago($beatAgo) . '前'));

        // ---- 3.55 表情包（v5.36.29）：只在配了人设库/表情包库之后才有意义 ----
        $stickerCount = 0;
        $stickerOn = false;
        $stickerWhy = '';
        $stickerLast = '';
        $stickerLogLast = '';
        try {
            $stickerCount = \app\service\AiLibrary::stickerCount(true);
            $stickerOn = \app\service\AiClient::platformConfig()['sticker_enabled'];
            $stickerLast = (string)\think\facade\Cache::get('ai_sticker_last_' . $tid, '');
            $rows = \app\service\AiLibrary::logs($tid, 1);
            if ($rows !== []) {
                $r = $rows[0];
                $stickerLogLast = (string)$r['created_at'] . ' ' . ($r['status'] === 'ok' ? '已发' : ($r['status'] === 'fail' ? '失败' : '没发'))
                    . ($r['sticker_name'] !== '' ? '「' . $r['sticker_name'] . '」' : '')
                    . ($r['error'] !== '' ? '：' . $r['error'] : '');
            }
        } catch (\Throwable $e) {
            $stickerWhy = '表情包日志表没建起来：' . mb_substr($e->getMessage(), 0, 120) . '（执行 upgrade_v5.36.29_to_v5.36.30.sql）';
        }
        $sp = (int)($aiCfg['sticker_probability'] ?? 0);
        if ($stickerWhy !== '') {
            $push('表情包库可用', false, $stickerWhy);
        } elseif (!$stickerOn) {
            $push('表情包已发送', true, '平台已关闭表情包总闸（不影响聊天）');
        } elseif ($sp <= 0) {
            $push('表情包已发送', true, '本队概率 0，只发文字' . ($stickerCount > 0 ? '；平台库里现有 ' . $stickerCount . ' 张可随时开' : ''));
        } else {
            $push('表情包已发送', true, '概率 ' . $sp . '%，平台库 ' . $stickerCount . ' 张'
                . ($stickerLogLast !== '' ? '；最近一次：' . $stickerLogLast : ($stickerLast !== '' ? '；最近一次：' . $stickerLast : '；还没有触发过'))
                . ($stickerCount === 0 ? '（库里没图，命中概率也发不出去）' : ''));
        }

        // ---- 3.5 出站域名解析（发消息必须解析得到 QQ 接口域名） ----
        $dns = [];
        foreach (['api.bot.qq.com', 'bots.qq.com', 'api.sgroup.qq.com'] as $h) {
            $t0 = microtime(true);
            $ip = QqBot::resolveHost($h);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            $dns[$h] = ['ip' => $ip, 'ms' => $ms];
            $push('域名解析 ' . $h, $ip !== '',
                $ip !== '' ? '解析到 ' . $ip . '（' . $ms . 'ms）'
                           : '解析不到。本机 DNS 有问题：机器人回复会卡在这一步（日志里是 HTTP 0 / Resolving timed out）');
        }
        $key = $bot ? trim(self::str($bot['callback_key'] ?? '')) : '';
        $push('回调密钥已生成', $key !== '', '');
        $callbackUrl = $key !== '' ? '/api/bot/callback/' . $key : '';

        // ---- 4. 群绑定与站点状态 ----
        $bind = Db::name('bot_group_bindings')->where('tenant_id', $tid)->find();
        $push(
            '战队群已绑定',
            $bind !== null,
            $bind
                ? '群 ' . self::maskOpenid(self::str($bind['group_openid'] ?? '')) . '，绑定于 ' . self::str($bind['created_at'] ?? '')
                : '在群里 @机器人 发「绑定」拿 6 位码，再回填到本页'
        );
        $tenant   = Db::name('tenants')->where('id', $tid)->find();
        $approved = $tenant && self::str($tenant['status'] ?? '') === 'approved';
        $push('站点已通过审核', $approved, $approved ? '' : '站点未通过审核时机器人不工作');

        // ---- 5. 最近收到的 QQ 推送（读运行时日志） ----
        [$events, $errors, $stats] = self::readBotLog();

        // ---- 6. 最近指令与 AI 任务 ----
        $calls = [];
        try {
            $calls = Db::name('bot_call_logs')->where('tenant_id', $tid)
                ->field('command,content,group_openid,created_at')
                ->order('id', 'desc')->limit(5)->select()->toArray();
            foreach ($calls as $i => $c) {
                $calls[$i]['group_openid'] = self::maskOpenid(self::str($c['group_openid'] ?? ''));
                $calls[$i]['content']      = mb_substr(self::str($c['content'] ?? ''), 0, 40);
            }
        } catch (\Throwable $e) {
        }
        $jobs = [];
        try {
            $jobs = Db::name('bot_ai_jobs')->where('tenant_id', $tid)
                ->field('kind,status,error,created_at')
                ->order('id', 'desc')->limit(5)->select()->toArray();
        } catch (\Throwable $e) {
        }

        // ---- 7. 一句人话结论 ----
        $lastEventAt  = self::str($events[0]['ts'] ?? '');
        $lastEventAgo = $lastEventAt !== '' ? max(0, time() - (int)strtotime($lastEventAt)) : null;
        $lastCallAt   = self::str($calls[0]['created_at'] ?? '');
        $lastCallAgo  = $lastCallAt !== '' ? max(0, time() - (int)strtotime($lastCallAt)) : null;
        $level = 'info';
        if (!$bot || !$enabled) {
            [$level, $verdict] = ['warning', '机器人没启用，群里发什么都没反应。先保存配置并打开「启用」。'];
        } elseif (!$cred) {
            [$level, $verdict] = ['warning', 'AppID / AppSecret 不完整，既收不到也发不出。补齐后点「测试连接」。'];
        } elseif (!$bind) {
            [$level, $verdict] = ['warning', '群还没绑定：在群里 @机器人 发「绑定」，拿到 6 位码回填本页。'];
        } elseif ($stats['total'] === 0) {
            [$level, $verdict] = ['warning', '最近两天 QQ 一条推送都没打到本站。去 QQ 机器人后台把「消息推送 / Webhook」改成本页这条回调地址（一字不差），并勾选「群聊 @机器人」后保存。'];
        } elseif ($stats['unknown_key'] > 0) {
            [$level, $verdict] = ['error', 'QQ 推过来的地址带的是旧密钥（被拒 ' . $stats['unknown_key'] . ' 次）。把本页回调地址重新复制到 QQ 后台保存一次即可。'];
        } elseif ($stats['appid_mismatch'] > 0) {
            [$level, $verdict] = ['error', 'QQ 推送里的 AppID 与本页保存的不一致（被拒 ' . $stats['appid_mismatch'] . ' 次），确认两边是同一台机器人。'];
        } elseif ($stats['send_fail'] > 0 && self::sendFailSays($errors, ['Resolving timed out', 'Could not resolve', 'Failed to resolve'])) {
            // 域名解析不动 → 回复根本发不出去。v5.36.24 起会自己解析 + 公共 DNS 兜底
            [$level, $verdict] = ['error', '指令收到了，但机器人发回复时解析不了 QQ 接口域名（日志里是 HTTP 0 · Resolving timed out）。'
                . 'v5.36.24 起会自动改用系统解析并钉住 IP，解析不到时再走公共 DNS（223.5.5.5 / 120.53.53.53）。'
                . '覆盖新文件后再 @ 一次；若这里「域名解析」一栏仍全是失败，说明这台服务器对外 DNS 出不去，需要在主机面板或服务商那边修 DNS。'];
        } elseif ($stats['send_fail'] > 0) {
            // 事件到了、指令也执行了，但 QQ 接口把回复拒了——这就是群里看不到回复的直接原因
            $lastFail = '';
            foreach ($errors as $e) {
                if ($e['type'] === '发送被拒') { $lastFail = $e['ts'] . ' ' . $e['detail']; break; }
            }
            [$level, $verdict] = ['error', '指令收到了、也执行了，但机器人把回复发出去时被 QQ 拒收（' . $stats['send_fail']
                . ' 次）。最近一次：' . $lastFail . '。看下方「最近报错」里的 QQ 返回。'];
        } elseif ($stats['cmd_error'] > 0) {
            [$level, $verdict] = ['error', '事件收到了，但处理时报错 ' . $stats['cmd_error'] . ' 次，看下方「最近报错」。'];
        } elseif ($stats['cmd_unknown'] > 0 && !$aiOk) {
            // 指令以外的话都会转给 AI；AI 接不通时群里就只剩「没当成指令」的提示
            [$level, $verdict] = ['error', '最近有 ' . $stats['cmd_unknown'] . ' 条消息被当成聊天转给 AI，但 AI 现在接不通：' . $aiWhy
                . '。' . ($aiMode === 'off' ? '先在本页「AI 聊天」里选模型并打开。' : '按这句话去修（平台设置 → AI 聊天，或自有模型地址）。')];
        } elseif (!$jobsWritable) {
            [$level, $verdict] = ['error', 'AI 收不下消息：bot_ai_jobs 表写不进去（' . $jobsWhy
                . '）。升级 SQL 没跑完，把 database/upgrade_v5.36.13_to_v5.36.14.sql 再执行一遍。'];
        } elseif ($aiOk && $beatAgo === null && $stats['cmd_unknown'] > 0) {
            [$level, $verdict] = ['error', 'AI 配好了、消息也进了队列，但回调结束后没有任何进程去处理队列（从没跑过），所以群里一直没回复。'
                . '覆盖 v5.36.26 后，打开一次站点后台就能把积压消化掉；如果仍然如此，需要给主机配一条定时访问 /api/open/ai/tick 的任务。'];
        } elseif ($aiOk && $backlog >= 20) {
            [$level, $verdict] = ['error', 'AI 队列积压了 ' . $backlog . ' 条，新消息会被直接拒收（群里只会说「没当成指令」）。'
                . '这些卡住的任务 15 分钟后会自动失效；也可以等一会儿再点「重新自检」确认积压回到 0。'];
        } elseif ($stats['skip_bot'] > 0 && $stats['at'] === 0) {
            [$level, $verdict] = ['error', '消息被判成「机器人自己发的」整条跳过了（' . $stats['skip_bot']
                . ' 次），所以群里完全静默。这是 v5.36.14 引入的判断 bug，v5.36.17 已修，覆盖新文件后就会恢复。'];
        } elseif ($stats['skipped'] > 0 && $stats['at'] === 0) {
            [$level, $verdict] = ['error', '收到了消息事件，但群或正文为空、被跳过 ' . $stats['skipped'] . ' 次，看下方「最近报错」那一行。'];
        } elseif ($stats['at'] === 0 && $stats['full'] > 0) {
            [$level, $verdict] = ['warning', '最近两天只收到「全量群消息」（' . $stats['full']
                . ' 条），没有单独的 @ 事件。v5.36.18 起全量消息里带 <@!机器人> 的也按 @ 处理；'
                . '如果 @ 指令仍不回，去 QQ 机器人后台把「群聊 @机器人」事件也勾上并保存。'];
        } elseif ($stats['at'] === 0) {
            [$level, $verdict] = ['warning', '最近两天没有收到任何 @ 消息推送（收到 ' . $stats['total']
                . ' 条其它事件）。先重新 @机器人 发一条「帮助」，再点「重新自检」；还是 0 就去 QQ 后台核对事件勾选。'];
        } elseif ($lastCallAgo !== null && $lastCallAgo > 3600) {
            [$level, $verdict] = ['warning', '有 @ 事件进来（' . $stats['at'] . ' 条），但 ' . self::ago($lastCallAgo)
                . '没有新的指令记录。确认 @ 的是已绑定的那个群（本页「群绑定」里的 openid）。'];
        } elseif ($lastEventAgo !== null && $lastEventAgo > 1800) {
            [$level, $verdict] = ['warning', '链路通，但最近一次 QQ 推送是 ' . self::ago($lastEventAgo)
                . '前，刚才那条没有进来。请重新 @机器人 一次，再点「重新自检」。'];
        } else {
            [$level, $verdict] = ['success', '链路是通的：最近一次 QQ 推送在 ' . $lastEventAt
                . '（@ 事件 ' . $stats['at'] . ' 条），最近一次指令在 ' . ($lastCallAt !== '' ? $lastCallAt : '（暂无）') . '。'];
        }

        return api_ok(self::scrub([
            'version'       => ASSET_BUILD,
            'callback_url'  => $callbackUrl,
            'checks'        => $checks,
            'verdict'       => $verdict,
            'verdict_level' => $level,
            'last_event_at' => $lastEventAt,
            'stats'         => $stats,
            'dns'           => $dns,
            'events'        => $events,
            'errors'        => $errors,
            'calls'         => $calls,
            'jobs'          => $jobs,
            'ai'            => [
                'mode'           => self::str($aiCfg['mode'] ?? 'off'),
                'auto_reply'     => (int)($aiCfg['auto_reply'] ?? 0),
                'balance'        => max(0, (int)($aiCfg['token_granted'] ?? 0) - (int)($aiCfg['token_used'] ?? 0)),
                'last_error'     => self::str($aiCfg['last_error'] ?? ''),
                'provider_ok'    => $aiOk ? 1 : 0,
                'provider_error' => $aiWhy,
                'model'          => $aiModel,
                'source'         => $aiSrc,
                'backlog'        => $backlog,
                'prompt_preset_id' => (int)($aiCfg['prompt_preset_id'] ?? 0),
                'sticker_enabled'  => $stickerOn ? 1 : 0,
                'sticker_probability' => $sp,
                'sticker_count'    => $stickerCount,
                'sticker_last'     => $stickerLast,
                'sticker_log_last' => $stickerLogLast,
            ],
        ]));
    }

    /**
     * 安全取字符串：日志里的字段可能是数组（例如 QQ 返回体 resp 就是解码后的数组），
     * 直接 (string) 数组会报 Array to string conversion，ThinkPHP 又把它转成异常。
     */
    private static function str($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        if (is_array($v) || is_object($v)) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            return is_string($j) ? $j : '';
        }
        return '';
    }

    /**
     * 把整份返回洗成 JSON 能编码的样子：非法 UTF-8 换成 U+FFFD、超长字符串截断。
     * 日志里偶尔有被截断的多字节字符，json_encode 一失败整个接口就 500，这里提前兜住。
     */
    private static function scrub($v)
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = self::scrub($item);
            }
            return $out;
        }
        if (is_string($v)) {
            if (!mb_check_encoding($v, 'UTF-8')) {
                $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            }
            return mb_strlen($v) > 400 ? mb_substr($v, 0, 400) . '…' : $v;
        }
        if (is_float($v) && (is_nan($v) || is_infinite($v))) {
            return 0;
        }
        return $v;
    }

    /** 「发送被拒」详情里是否出现某些字样（用来区分 DNS / 鉴权 / 频控） */
    private static function sendFailSays(array $errors, array $needles): bool
    {
        foreach ($errors as $e) {
            if (($e['type'] ?? '') !== '发送被拒') {
                continue;
            }
            $d = (string)($e['detail'] ?? '');
            foreach ($needles as $n) {
                if ($n !== '' && stripos($d, $n) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /** 秒 → 人话间隔 */
    private static function ago(int $sec): string
    {
        if ($sec < 90) {
            return $sec . ' 秒';
        }
        if ($sec < 5400) {
            return (int)round($sec / 60) . ' 分钟';
        }
        if ($sec < 172800) {
            return (int)round($sec / 3600) . ' 小时';
        }
        return (int)round($sec / 86400) . ' 天';
    }

    /** openid 打码：首 4 位 + 末 4 位 */
    private static function maskOpenid(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        return strlen($s) <= 10 ? substr($s, 0, 2) . '***' : substr($s, 0, 4) . '…' . substr($s, -4);
    }

    /**
     * 读 runtime/bot/ 今天与昨天的日志，统计事件与报错。
     * @return array{0:array<int,array<string,string>>,1:array<int,array<string,string>>,2:array<string,int>}
     */
    private static function readBotLog(): array
    {
        $events = [];
        $errors = [];
        $stats  = ['total' => 0, 'at' => 0, 'full' => 0, 'other' => 0, 'rejected' => 0,
                   'unknown_key' => 0, 'appid_mismatch' => 0, 'cmd_error' => 0, 'verify_fail' => 0,
                   'send_fail' => 0, 'skipped' => 0, 'skip_bot' => 0, 'skip_empty' => 0,
                   'cmd_unknown' => 0];
        $dir = runtime_path() . 'bot';
        // 先昨天后今天：拼出来就是时间正序，最后统一 reverse 取最近的
        foreach ([date('Ymd', time() - 86400), date('Ymd')] as $day) {
            $file = $dir . DIRECTORY_SEPARATOR . 'bot_' . $day . '.log';
            if (!is_file($file)) {
                continue;
            }
            $fh = @fopen($file, 'rb');
            if (!$fh) {
                continue;
            }
            // 只读尾部 256KB，避免大日志拖慢页面
            $size    = (int)filesize($file);
            $seeking = $size > 262144;
            if ($seeking) {
                fseek($fh, $size - 262144);
            }
            $raw = stream_get_contents($fh);
            fclose($fh);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $lines = explode("\n", $raw);
            if ($seeking) {
                array_shift($lines);   // 从中间截断时首行只有半截，JSON 必定解析失败
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $type = self::str($row['type'] ?? '');
                $ts   = self::str($row['ts'] ?? '');
                if ($type === 'event') {
                    $t = self::str($row['t'] ?? '');
                    $stats['total']++;
                    if ($t === 'GROUP_AT_MESSAGE_CREATE') {
                        $stats['at']++;
                    } elseif ($t === 'GROUP_MESSAGE_CREATE') {
                        $stats['full']++;
                    } else {
                        $stats['other']++;
                    }
                    // 原始推送里顺手记下群 / bot 标记 / 正文字数，排查时不用再翻服务器日志
                    $dd     = (array)($row['d'] ?? []);
                    $mentionBot = false;
                    foreach ((array)($dd['mentions'] ?? []) as $mm) {
                        if (is_array($mm) && filter_var($mm['bot'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                            $mentionBot = true;
                            break;
                        }
                    }
                    if (str_starts_with(ltrim(self::str($dd['content'] ?? '')), '<@!')) {
                        $mentionBot = true;
                    }
                    $events[] = [
                        'ts'      => $ts,
                        'type'    => $t !== '' ? $t : '(未知事件)',
                        'group'   => self::maskOpenid(self::str($dd['group_id'] ?? $dd['group_openid'] ?? '')),
                        'bot_raw' => isset($dd['author']['bot']) ? self::str($dd['author']['bot']) : '(无)',
                        'len'     => mb_strlen(self::str($dd['content'] ?? '')),
                        'mention' => $mentionBot ? '是' : '否',
                    ];
                } elseif ($type === 'event_skip') {
                    $stats['skipped']++;
                    $reason = self::str($row['reason'] ?? '');
                    $reason === 'from_bot' ? $stats['skip_bot']++ : $stats['skip_empty']++;
                    $errors[] = [
                        'ts'     => $ts,
                        'type'   => '事件被跳过',
                        'detail' => $reason === 'from_bot'
                            ? ('判成机器人自己的消息（bot=' . (isset($row['bot_raw']) ? self::str($row['bot_raw']) : '?') . '）')
                            : ('群或正文为空（t=' . self::str($row['t'] ?? '') . '，有群=' . (!empty($row['has_group']) ? '是' : '否') . '，正文长度=' . (int)($row['content_len'] ?? 0) . '）'),
                    ];
                } elseif ($type === 'event_rejected') {
                    $stats['rejected']++;
                    $reason = self::str($row['reason'] ?? '');
                    if ($reason === 'unknown_key') {
                        $stats['unknown_key']++;
                    }
                    if ($reason === 'appid_mismatch') {
                        $stats['appid_mismatch']++;
                    }
                    $errors[] = ['ts' => $ts, 'type' => '事件被拒', 'detail' => $reason];
                } elseif ($type === 'cmd_error' || $type === 'verify_fail') {
                    $type === 'cmd_error' ? $stats['cmd_error']++ : $stats['verify_fail']++;
                    $errors[] = [
                        'ts'     => $ts,
                        'type'   => $type === 'cmd_error' ? '处理报错' : '回调验证失败',
                        'detail' => mb_substr(self::str($row['error'] ?? $row['reason'] ?? ''), 0, 200),
                    ];
                } elseif ($type === 'send_fail') {
                    // QQ 明确回了业务错误：指令没错，是回复没被 QQ 收下。
                    // resp 是解码后的数组（含 code / message / trace_id），这里挑关键字段拼一行
                    $stats['send_fail']++;
                    $resp = $row['resp'] ?? '';
                    $bits = ['HTTP ' . (int)($row['http'] ?? 0)];
                    if (is_array($resp)) {
                        foreach (['code', 'message', 'msg', 'err_code', 'err_msg', 'trace_id'] as $k) {
                            if (isset($resp[$k]) && is_scalar($resp[$k]) && (string)$resp[$k] !== '') {
                                $bits[] = $k . '=' . $resp[$k];
                            }
                        }
                    }
                    $bits[] = mb_substr(self::str($resp), 0, 140);
                    $errors[] = [
                        'ts'     => $ts,
                        'type'   => '发送被拒',
                        'detail' => implode(' · ', array_filter($bits, static fn ($s) => trim($s) !== '')),
                    ];
                } elseif ($type === 'cmd_unknown') {
                    $stats['cmd_unknown']++;
                    $errors[] = [
                        'ts'     => $ts,
                        'type'   => '指令未识别',
                        'detail' => '「' . mb_substr(self::str($row['text'] ?? ''), 0, 30) . '」 hex=' . mb_substr(self::str($row['hex'] ?? ''), 0, 60),
                    ];
                } elseif ($type === 'ai_queue_fail') {
                    $errors[] = ['ts' => $ts, 'type' => 'AI 排队失败', 'detail' => (string)($row['why'] ?? '') === 'backlog'
                        ? '队列积压：还有 ' . (int)($row['busy'] ?? 0) . ' 条没处理'
                        : ('写队列失败：' . mb_substr(self::str($row['error'] ?? ''), 0, 160))];
                } elseif ($type === 'ai_offer') {
                    // 每条被当成聊天的消息都会留一条，回答「这句为什么没被 AI 回」
                    $bit = [];
                    $bit[] = !empty($row['queued']) ? '已转给 AI（排队）' : '没进 AI 队列';
                    if (!empty($row['off'])) {
                        $bit[] = 'AI 未开启';
                    }
                    if ((string)($row['reply'] ?? '') !== '') {
                        $bit[] = '直接回了：' . mb_substr(self::str($row['reply']), 0, 60);
                    }
                    $errors[] = ['ts' => $ts, 'type' => 'AI 分流', 'detail' => implode(' · ', $bit)];
                } elseif (strpos($type, 'ai_') === 0) {
                    $errors[] = ['ts' => $ts, 'type' => 'AI 队列', 'detail' => mb_substr(self::str($row['error'] ?? $type), 0, 200)];
                }
            }
        }
        return [
            array_slice(array_reverse($events), 0, 10),
            array_slice(array_reverse($errors), 0, 10),
            $stats,
        ];
    }
}
