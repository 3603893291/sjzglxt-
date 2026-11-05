<?php
declare (strict_types = 1);

namespace app\service;

use app\model\User;
use think\facade\Cache;
use think\facade\Db;

/**
 * QQ 机器人指令分发（v5.8.0）
 *
 * 入口 handleWithBot()：战队独立机器人的 Webhook 收到群 @消息后调用，返回回复文本（'' = 不回复）。
 * v5.28.0：平台公共机器人已下线，机器人只走各战队自己的独立机器人。
 * 数据全部复用现有功能的数据层（签到/轮换/改枪码/日程/训练赛/撞车/伪军），
 * 机器人只是「群里的另一个前端」，不引入平行业务逻辑。
 *
 * 指令（@机器人 + 文本）：
 *   签到 / 地图 / 改枪码 / 日程 / 训练赛 / 撞车 / 值班 / 伪军 名字 / 帮助
 *   我的战绩 / 战绩榜 / 成员 / 介绍（v5.28.0 新增；v5.32.0 论坛下线，热帖与精选两条跟着撤）
 *   指令带不带斜杠都行：「帮助」与「/帮助」等价（QQ 群里两种习惯都有人用）
 *   绑定 六位码（成员绑定） / 解绑（成员解绑）
 * 未绑定群：发「绑定」生成 6 位群绑定验证码（10 分钟有效，管理中心回填）。
 */
class BotCommand
{
    /** 每群每分钟最多处理 30 条指令（防刷屏，超限静默） */
    private const RATE_LIMIT = 30;

    /**
     * 指令分发：返回 [指令名, 回复文本]。
     * 租户上下文（enterTenant）必须在调用前就位。
     */
    private static function dispatch(int $tid, string $memberOpenid, string $text, bool $forceCmd = false): array
    {
        // v5.36.25：区分「指令」和「聊天」的规则（写给后来的人，也写给用户看）：
        //   1) 以 / ／ # 开头 = 一定是指令，匹配不到就回「没有这个指令」，绝不再转给 AI；
        //   2) 正文很短（≤8 字）且含指令词，或以指令词开头 → 当指令；
        //   3) 其余（长句、闲聊）→ 交给 AI 聊天。
        // 之前一律「包含即算」，聊天里带个「地图」就被抢答成指令，AI 永远轮不到。
        // ---- 成员绑定 / 解绑 / 帮助（不要求已绑定身份） ----
        // 绑定码必须整句就是「绑定 + 6 位数字」：以前没有首尾锚点，
        // 聊天里顺口写一句「我绑定 123456 了」也会直接核销绑到别人头上。
        if (preg_match('/^绑定\s*(\d{6})$/u', $text, $m)) {
            return ['绑定', self::bindUser($tid, $memberOpenid, $m[1])];
        }
        // 解绑是写操作，只认整句「解绑 / 解除绑定」；「解绑怎么弄」这类问题不该真去解绑
        if ($text === '解绑' || $text === '解除绑定') {
            return ['解绑', self::unbindUser($tid, $memberOpenid)];
        }
        if (self::hit($text, ['帮助', '菜单', '指令', '功能'])) {
            return ['帮助', self::help()];
        }

        $user = self::userOf($tid, $memberOpenid);

        // ---- 查询类指令 ----
        // 签到只认整句（可带「今日/今天」），避免「签到规则是什么」被当成签到执行
        if (preg_match('/^(?:今日|今天)?(?:签到|打卡)[!！。.]?$/u', $text)) {
            return ['签到', self::checkin($tid, $user)];
        }
        if (self::hit($text, ['地图', '轮换'])) {
            return ['地图', self::rotation()];
        }
        if (self::hit($text, ['枪码'])) {
            return ['改枪码', self::guncodes($tid)];
        }
        if (self::hit($text, ['日程'])) {
            return ['日程', self::schedule($tid)];
        }
        if (self::hit($text, ['训练赛'])) {
            return ['训练赛', self::trainings($tid)];
        }
        if (self::hit($text, ['撞车'])) {
            return ['撞车', self::races($tid)];
        }
        if (self::hit($text, ['值班'])) {
            return ['值班', self::duty($tid)];
        }
        if (self::hit($text, ['伪军'])) {
            return ['伪军', self::traitor($text)];
        }

        // ---- v5.28.0 新增：战绩 / 成员 / 战队介绍 ----
        if (self::hit($text, ['我的战绩'])) {
            return ['我的战绩', self::myStats($tid, $user)];
        }
        if (self::hit($text, ['战绩榜', '排行'])) {
            return ['战绩榜', self::statBoard($tid)];
        }
        if (self::hit($text, ['成员', '在线'])) {
            return ['成员', self::members($tid)];
        }
        if (self::hit($text, ['介绍'])) {
            return ['介绍', self::intro($tid)];
        }

        // 明确带斜杠/井号却匹配不到：就是用户打错了指令，直接说清楚，别丢给 AI
        if ($forceCmd) {
            return ['(指令不存在)', '没有这个指令。@我 发送「帮助」（或「/帮助」）看看能用的指令。'];
        }

        // 未匹配指令（聊天由回调层转给 AI）
        return ['(未知)', '这句我没当成指令。@我 发送「帮助」（或「/帮助」）查看指令菜单。'];
    }

    /**
     * 指令词命中判定：短句（≤8 字）包含即可，长句必须是指令词开头。
     * 这样「地图」「今天地图」是指令，「我们今晚打哪张地图好呢」是聊天，交给 AI。
     */
    private static function hit(string $text, array $words): bool
    {
        $len = mb_strlen($text);
        foreach ($words as $w) {
            if ($len <= 8 && mb_strpos($text, $w) !== false) {
                return true;
            }
            if (str_starts_with($text, $w)) {
                return true;
            }
        }
        return false;
    }

    // ==================== 调用日志 ====================

    /** 写调用日志（失败不影响主流程；低概率顺带清理 60 天前旧记录） */
    private static function logCall(int $tid, string $group, string $member, string $cmd, string $content): void
    {
        try {
            Db::name('bot_call_logs')->insert([
                'tenant_id'     => $tid,
                'group_openid'  => $group,
                'member_openid' => $member,
                'command'       => mb_substr($cmd, 0, 32),
                'content'       => mb_substr($content, 0, 255),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            if (random_int(1, 20) === 1) {
                Db::name('bot_call_logs')
                    ->where('created_at', '<', date('Y-m-d H:i:s', time() - 60 * 86400))
                    ->delete();
            }
        } catch (\Throwable $e) {
            // 日志失败不影响指令回复
        }
    }

    // ==================== 未绑定群：群绑定验证码流程 ====================

    /**
     * 未绑定群的处理：生成群绑定验证码或提示绑定步骤。
     */
    private static function unbound(string $groupOpenid, string $memberOpenid, string $text): string
    {
        if (mb_strpos($text, '绑定') === false) {
            return '本群尚未绑定战队站点，无法使用机器人指令。'
                . '群管理员可 @我 发送「绑定」获取验证码，由战队超管在网站管理中心回填完成绑定。';
        }
        // 有效期内复用同一验证码，避免多人重复申请
        $row = Db::name('bot_group_codes')
            ->where('group_openid', $groupOpenid)
            ->whereNull('used_at')
            ->where('expire_at', '>', time())
            ->order('id', 'desc')->find();
        if (!$row) {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            Db::name('bot_group_codes')->insert([
                'group_openid'  => $groupOpenid,
                'code'          => $code,
                'member_openid' => $memberOpenid,
                'expire_at'     => time() + 600,
                'created_at'    => time(),
            ]);
            // 顺带清理 7 天前的旧码
            Db::name('bot_group_codes')->where('expire_at', '<', time() - 7 * 86400)->delete();
            $row = ['code' => $code];
        }
        return '群绑定验证码：' . $row['code'] . "（10 分钟内有效）\n"
            . '请战队超级管理员登录网站 → 管理中心 → QQ机器人，填入该验证码完成绑定。';
    }

    // ==================== 成员绑定 ====================

    private static function bindUser(int $tid, string $memberOpenid, string $code): string
    {
        $uid = (int)Cache::get('bot_ucode_' . $tid . '_' . $code);
        if ($uid <= 0) {
            return '验证码不正确或已过期。请在网站「个人中心 → QQ机器人绑定」重新获取验证码，'
                . '然后在群里 @我 发送「绑定 六位码」。';
        }
        $user = User::find($uid);
        if (!$user || $user->status !== 'approved') {
            return '验证码对应的账号不存在或状态异常，绑定失败。';
        }

        // 该 QQ 身份是否已被绑定
        $byOpenid = Db::name('bot_user_bindings')->where('member_openid', $memberOpenid)->find();
        if ($byOpenid && (int)$byOpenid['user_id'] !== $uid) {
            return '你的 QQ 身份已绑定其他账号。如需更换，请先在群里 @我 发送「解绑」。';
        }
        if ($byOpenid && (int)$byOpenid['user_id'] === $uid) {
            return '已经绑定过了，无需重复绑定。';
        }

        // 账号此前绑过别的 QQ 身份 → 顶替（验证码由本人网站会话签发，视为可信）
        Db::name('bot_user_bindings')->where('tenant_id', $tid)->where('user_id', $uid)->delete();
        Db::name('bot_user_bindings')->insert([
            'tenant_id'     => $tid,
            'user_id'       => $uid,
            'member_openid' => $memberOpenid,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        Cache::delete('bot_ucode_' . $tid . '_' . $code);
        return '绑定成功，' . ($user->nickname ?: '队员') . '！现在可以直接 @我 签到、查日程了。';
    }

    private static function unbindUser(int $tid, string $memberOpenid): string
    {
        $row = Db::name('bot_user_bindings')
            ->where('tenant_id', $tid)->where('member_openid', $memberOpenid)->find();
        if (!$row) {
            return '你还没有绑定账号，无需解绑。';
        }
        Db::name('bot_user_bindings')->where('id', $row['id'])->delete();
        return '已解绑。需要时可重新在个人中心获取验证码绑定。';
    }

    /** 群身份 → 站点用户（未绑定返回 null） */
    private static function userOf(int $tid, string $memberOpenid): ?User
    {
        $row = Db::name('bot_user_bindings')
            ->where('tenant_id', $tid)->where('member_openid', $memberOpenid)->find();
        if (!$row) {
            return null;
        }
        $user = User::find((int)$row['user_id']);
        return ($user && $user->status === 'approved') ? $user : null;
    }

    private static function needBind(): string
    {
        return '该指令需要识别你的账号身份，请先绑定：'
            . '登录网站 → 个人中心 → QQ机器人绑定 → 获取验证码，然后在群里 @我 发送「绑定 六位码」。';
    }

    // ==================== 签到 ====================

    private static function checkin(int $tid, ?User $user): string
    {
        if (!$user) {
            return self::needBind();
        }
        $today  = date('Y-m-d');
        $exists = Db::name('checkins')
            ->where('tenant_id', $tid)->where('user_id', $user->id)
            ->where('checkin_date', $today)->find();
        if ($exists) {
            return '今天已经签到过啦，当前连签 ' . (int)$exists['streak'] . ' 天。';
        }

        // 连签规则与网站签到完全一致：昨天签过则续上，断签归 1
        $yestStreak = (int)Db::name('checkins')
            ->where('tenant_id', $tid)->where('user_id', $user->id)
            ->where('checkin_date', date('Y-m-d', strtotime('-1 day')))
            ->value('streak');
        $streak = $yestStreak > 0 ? $yestStreak + 1 : 1;

        try {
            Db::name('checkins')->insert([
                'tenant_id'    => $tid,
                'user_id'      => $user->id,
                'checkin_date' => $today,
                'streak'       => $streak,
                'fortune_img'  => random_int(1, \app\controller\Checkin::memeCount()),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return '今天已经签到过啦。';
        }
        $siteCount = (int)Db::name('checkins')
            ->where('tenant_id', $tid)->where('checkin_date', $today)->count();
        return '签到成功！已连续签到 ' . $streak . ' 天（今日全队已签 ' . $siteCount . ' 人）。';
    }

    // ==================== 今日轮换地图 ====================

    private static function rotation(): string
    {
        // 与网站 /api/rotation 共用 MapRotation，机器人只看接下来 3 天
        $snap = MapRotation::snapshot(MapRotation::BOT_UPCOMING);
        if (!$snap['enabled']) {
            return '轮换地图功能未启用。';
        }
        if (!$snap['today']) {
            return '轮换组合尚未配置。';
        }

        $day = $snap['today'];
        $head = $snap['status'] === 'active' ? '今日轮换' : '今日轮换预告（9 点开始）';
        $out = [$head . '：' . $day['name']];
        $names = array_map(static fn(array $m): string => (string)$m['name'], $day['maps']);
        if ($names !== []) {
            $out[] = '地图：' . implode('、', $names);
        }

        if ($snap['upcoming'] !== []) {
            $out[] = '接下来：';
            foreach ($snap['upcoming'] as $u) {
                $line = '· ' . self::upcomingLabel((int)$u['after_days'], (string)$u['date']) . ' ' . $u['name'];
                $uNames = array_map(static fn(array $m): string => (string)$m['name'], $u['maps']);
                if ($uNames !== []) {
                    $shown = array_slice($uNames, 0, 6);
                    $line .= "\n   " . implode('、', $shown)
                        . (count($uNames) > 6 ? ' 等' . count($uNames) . '张' : '');
                }
                $out[] = $line;
            }
            $out[] = '完整预告见网站「轮换地图」。';
        }

        return implode("\n", $out);
    }

    /** 预告日期标签：明天 10-23 / 后天 10-24 / 3天后 10-25 */
    private static function upcomingLabel(int $afterDays, string $date): string
    {
        $word = $afterDays === 1 ? '明天' : ($afterDays === 2 ? '后天' : $afterDays . '天后');
        $ts = $date !== '' ? strtotime($date) : false;
        return $ts !== false ? $word . ' ' . date('m-d', $ts) : $word;
    }

    // ==================== 改枪码 ====================

    private static function guncodes(int $tid): string
    {
        $list = Db::name('gun_codes')->alias('g')
            ->leftJoin('users u', 'u.id = g.user_id')
            ->where('g.tenant_id', $tid)
            ->where('g.status', 'published')
            ->field('g.title, g.code, COALESCE(u.nickname, "已注销用户") AS author')
            ->order('g.id', 'desc')
            ->limit(5)
            ->select()->toArray();
        if ($list === []) {
            return '队内暂无已上架的改枪码。';
        }
        $out = ['队内改枪码（最新 ' . count($list) . ' 条，完整列表见网站）：'];
        foreach ($list as $i => $g) {
            $code = mb_substr(trim((string)$g['code']), 0, 60);
            $out[] = ($i + 1) . '. ' . $g['title'] . '（' . $g['author'] . '）'
                . ($code !== '' ? "\n   码：" . $code : '');
        }
        return implode("\n", $out);
    }

    // ==================== 队内日程（今日） ====================

    private static function schedule(int $tid): string
    {
        $hidden = array_filter(explode(',', Setting::get('schedule_hide', '')));
        $start  = date('Y-m-d 00:00:00');
        $end    = date('Y-m-d 23:59:59');
        $nowTs  = time();
        $items  = [];

        // v5.36.0：分端的内容不进 QQ 群（群里两端的人都在），机器人只说全队共享的
        if (!in_array('race', $hidden, true)) {
            $q = Db::name('race_events')->where('tenant_id', $tid)->whereBetween('race_time', [$start, $end]);
            if (GameSide::moduleOnFor($tid, 'race')) { $q->where('game_side', ''); }
            foreach ($q->select()->toArray() as $r) {
                $items[] = ['t' => $r['race_time'], 'label' => '撞车', 'title' => $r['title']];
            }
        }
        if (!in_array('custom_race', $hidden, true)) {
            $q = Db::name('custom_race_events')->where('tenant_id', $tid)->whereBetween('race_time', [$start, $end]);
            if (GameSide::moduleOnFor($tid, 'custom_race')) { $q->where('game_side', ''); }
            foreach ($q->select()->toArray() as $r) {
                $items[] = ['t' => $r['race_time'], 'label' => '自建房', 'title' => $r['title']];
            }
        }
        if (!in_array('battle', $hidden, true)) {
            foreach (BattleService::listForParty('tenant', TenantContext::id()) as $b) {
                if ($b['battle_time'] >= $start && $b['battle_time'] <= $end
                    && !in_array($b['status'], ['rejected', 'canceled'], true)) {
                    $items[] = ['t' => $b['battle_time'], 'label' => '约战', 'title' => $b['title'] ?: '约战'];
                }
            }
        }
        // 训练赛（报名中/进行中）；「今日日程」只播今天的，下周的比赛不该混进来
        $tq = Db::name('training_matches')->where('tenant_id', $tid)->whereIn('status', ['signup', 'ongoing']);
        if (GameSide::moduleOnFor($tid, 'training')) { $tq->where('game_side', ''); }
        foreach ($tq->select()->toArray() as $m) {
            $mt = (string)($m['match_time'] ?? '');
            if ($mt !== '' && ($mt < $start || $mt > $end)) { continue; }
            $items[] = [
                't'     => (string)($m['match_time'] ?: date('Y-m-d') . ' 23:59:59'),
                'label' => '训练赛',
                'title' => ($m['title'] ?: '训练赛') . '（' . ($m['status'] === 'signup' ? '报名中' : '进行中') . '）',
            ];
        }
        if (!in_array('custom', $hidden, true)) {
            $q = Db::name('custom_events')->where('tenant_id', $tid)->whereBetween('start_time', [$start, $end]);
            if (GameSide::moduleOnFor($tid, 'event')) { $q->where('game_side', ''); }
            foreach ($q->select()->toArray() as $e) {
                $items[] = ['t' => $e['start_time'], 'label' => '活动', 'title' => $e['title'],
                    'dur' => (int)$e['duration_min']];
            }
        }
        if (!in_array('duty', $hidden, true)) {
            // 分端时只取全队共享那份值班配置；端游/手游各自的轮值不在群里播
            $dutyCfg = GameSide::moduleOnFor($tid, 'duty') ? DutyService::configExact('') : DutyService::config();
            $duty = DutyService::dutyOn(date('Y-m-d'), $dutyCfg);
            if ($duty) {
                $items[] = ['t' => date('Y-m-d') . ' 00:00:00', 'label' => '值班', 'allday' => true,
                    'title' => '房主值班：' . $duty['nickname']
                        . ($duty['swapped'] ? '（与 ' . $duty['swapped_from'] . ' 换班）' : '')];
            }
        }

        // 过滤已结束（与网站日程一致：全天条目除外）
        $items = array_filter($items, function ($it) use ($nowTs) {
            if (!empty($it['allday'])) {
                return true;
            }
            $ts = strtotime((string)$it['t']);
            if ($ts === false) {
                return true;
            }
            if (!empty($it['dur'])) {
                $ts += $it['dur'] * 60;
            }
            return $ts >= $nowTs;
        });
        usort($items, fn($a, $b) => strcmp((string)$a['t'], (string)$b['t']));

        if ($items === []) {
            return '今日暂无日程安排。';
        }
        $out = ['今日日程（' . date('n月j日') . '）：'];
        foreach (array_slice($items, 0, 12) as $it) {
            $time = empty($it['allday']) ? date('H:i', strtotime((string)$it['t'])) : '全天';
            $out[] = '· ' . $time . ' 【' . $it['label'] . '】' . $it['title'];
        }
        if (count($items) > 12) {
            $out[] = '……其余 ' . (count($items) - 12) . ' 条详见网站日程';
        }
        return implode("\n", $out);
    }

    // ==================== 训练赛 ====================

    private static function trainings(int $tid): string
    {
        $q = Db::name('training_matches')
            ->where('tenant_id', $tid)
            ->whereIn('status', ['signup', 'ongoing'])
            ->order('match_time', 'asc');
        if (GameSide::moduleOnFor($tid, 'training')) {
            $q->where('game_side', '');
        }
        $list = $q->limit(3)->select()->toArray();
        if ($list === []) {
            return '当前没有报名中/进行中的训练赛。';
        }
        $out = ['队内训练赛：'];
        foreach ($list as $m) {
            $mode   = $m['mode'] === 'squad' ? '小队对抗' : '自由分组';
            $status = $m['status'] === 'signup' ? '报名中' : '进行中';
            $line   = '· ' . ($m['title'] ?: '训练赛') . '（' . $mode . '，' . $status . '）';
            if ($m['match_time']) {
                $line .= "\n   开赛：" . date('m-d H:i', strtotime((string)$m['match_time']));
            }
            $out[] = $line;
        }
        $out[] = '报名/分组请上网站操作。';
        return implode("\n", $out);
    }

    // ==================== 撞车比赛 ====================

    private static function races(int $tid): string
    {
        $q = Db::name('race_events')
            ->where('tenant_id', $tid)
            ->where('race_time', '>', date('Y-m-d H:i:s'))
            ->order('race_time', 'asc');
        if (GameSide::moduleOnFor($tid, 'race')) {
            $q->where('game_side', '');
        }
        $list = $q->limit(3)->select()->toArray();
        if ($list === []) {
            return '近期暂无撞车安排。';
        }
        $out = ['撞车比赛安排：'];
        foreach ($list as $r) {
            $out[] = '· ' . date('m月d日 H:i', strtotime((string)$r['race_time'])) . ' ' . $r['title'];
        }
        return implode("\n", $out);
    }

    // ==================== 值班 ====================

    private static function duty(int $tid): string
    {
        // 与「日程」里那条值班保持同一份配置：开了分端的队，群里只播全队共享那份
        $cfg  = GameSide::moduleOnFor($tid, 'duty') ? DutyService::configExact('') : DutyService::config();
        $duty = DutyService::dutyOn(date('Y-m-d'), $cfg);
        if (!$duty) {
            return '今日没有值班安排。';
        }
        return '今日房主值班：' . $duty['nickname']
            . ($duty['swapped'] ? '（与 ' . $duty['swapped_from'] . ' 换班）' : '');
    }

    // ==================== 查询伪军 ====================

    private static function traitor(string $text): string
    {
        // 去掉指令词，剩下要查的名字
        $name = trim(str_replace(['查询伪军', '伪军查询', '伪军', '查询'], '', $text));
        $name = preg_replace('/\s+/u', '', $name) ?? '';
        if ($name === '') {
            return '请输入要查询的名字，例如：伪军 张三';
        }
        if (mb_strlen($name) > 32) {
            return '名字太长啦（最长 32 字）。';
        }

        // 与网站「查询伪军」同源：只查已审核通过的注册用户昵称（租户作用域已生效，不跨队）
        $users = User::where('status', 'approved')
            ->whereLike('nickname', '%' . $name . '%')
            ->field('nickname, role_tags')
            ->limit(10)
            ->select();
        if (count($users) === 0) {
            return '未收录：未在队内注册成员中查到「' . $name . '」。';
        }
        $out = ['已收录（查询到 ' . count($users) . ' 条）：'];
        foreach ($users as $u) {
            $tag = display_role_tag($u);
            $out[] = '· ' . $u->nickname . ($tag ? '（' . $tag['name'] . '）' : '');
        }
        return implode("\n", $out);
    }

    // ==================== v5.28.0：战绩 ====================

    /** 一个数值字段的可读写法：没填就是「—」，整数不带小数点 */
    private static function num(array $row, string $key): string
    {
        if (!isset($row[$key]) || $row[$key] === null || $row[$key] === '') {
            return '—';
        }
        $v = (float)$row[$key];
        return $v === (float)(int)$v ? (string)(int)$v : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function pct(array $row, string $key): string
    {
        $v = self::num($row, $key);
        return $v === '—' ? '—' : $v . '%';
    }

    /** 我的战绩：要本人已绑定账号；数据与网站「我的战绩」同源（player_stats 一人一行） */
    private static function myStats(int $tid, ?User $user): string
    {
        if (!$user) {
            return self::needBind();
        }
        $row = Db::name('player_stats')
            ->where('tenant_id', $tid)->where('user_id', (int)$user->id)->find();
        if (!$row) {
            return '队内还没收录你的战绩。战绩由成员自己上传，可让管理战绩的上传工具把你的数据传一次。';
        }
        $out = [
            '我的战绩（' . ((string)$user->nickname !== '' ? $user->nickname : '队员') . '）：',
            '· 场次 ' . self::num($row, 'matches') . '　胜率 ' . self::pct($row, 'win_rate')
                . '　平均评分 ' . self::num($row, 'avg_score'),
            '· KD ' . self::num($row, 'kd') . '　KDA ' . self::num($row, 'kda')
                . '　总击杀 ' . self::num($row, 'kills'),
            '· 场均击杀 ' . self::num($row, 'kills_per_match') . '　场均得分 ' . self::num($row, 'score_per_match')
                . '　救治 ' . self::num($row, 'revives'),
            '· 近10局胜率 ' . self::pct($row, 'win_rate_last10') . '　最长连胜 ' . self::num($row, 'max_win_streak'),
        ];
        $up = trim((string)($row['updated_at'] ?? ''));
        $ts = $up !== '' ? strtotime($up) : false;
        $out[] = '最近更新：' . ($ts ? date('n月j日 H:i', $ts) : '未记录');
        return implode("\n", $out);
    }

    /** 队内战绩榜：有评分按平均评分排，全队都没评分时退而按场次排 */
    private static function statBoard(int $tid): string
    {
        // 与站内战绩排行同口径：只算已通过审核、本人勾了「战绩公开」的成员；
        // 以前只按 tenant_id 取，把关了公开和待审核的人也列进了群里。
        $rows = Db::name('player_stats')->alias('s')
            ->leftJoin('users u', 'u.id = s.user_id')
            ->where('s.tenant_id', $tid)
            ->where('u.status', 'approved')
            ->where('u.stats_public', 1)
            ->field('s.matches, s.avg_score, s.kd, s.win_rate, COALESCE(u.nickname, "已注销用户") AS nickname')
            ->limit(300)->select()->toArray();
        if ($rows === []) {
            return '队内还没有战绩数据。';
        }
        $has = static fn(array $r): bool => isset($r['avg_score']) && $r['avg_score'] !== null && $r['avg_score'] !== '';
        $byScore = array_values(array_filter($rows, $has));
        if ($byScore !== []) {
            $pool = $byScore;
            $metric = 'avg_score';
            $label  = '平均评分';
        } else {
            $pool = array_values(array_filter($rows, static fn(array $r): bool => (int)($r['matches'] ?? 0) > 0));
            $metric = 'matches';
            $label  = '场次';
        }
        if ($pool === []) {
            return '队内战绩还没上传关键指标（评分与场次都是空的）。';
        }
        usort($pool, static function (array $a, array $b) use ($metric): int {
            return (float)$b[$metric] <=> (float)$a[$metric];
        });
        $out = ['队内战绩榜（按' . $label . '，最新 ' . count(array_slice($pool, 0, 5)) . ' 名）：'];
        foreach (array_slice($pool, 0, 5) as $i => $r) {
            $out[] = ($i + 1) . '. ' . ($r['nickname'] !== '' ? $r['nickname'] : '队员')
                . '　' . $label . ' ' . self::num($r, $metric)
                . '　场次 ' . self::num($r, 'matches')
                . '　KD ' . self::num($r, 'kd');
        }
        return implode("\n", $out);
    }

    // ==================== v5.28.0：成员与在线 / 战队介绍 ====================

    /** 队内成员与在线：在线口径与网站仪表盘一致（5 分钟内有操作） */
    private static function members(int $tid): string
    {
        $total  = User::where('status', 'approved')->count();
        $rows   = User::where('status', 'approved')
            ->where('last_active_at', '>', date('Y-m-d H:i:s', time() - 300))
            ->order('last_active_at', 'desc')->limit(12)
            ->field('id, nickname')->select()->toArray();
        $names  = [];
        foreach ($rows as $u) {
            $nick = trim((string)($u['nickname'] ?? ''));
            $names[] = $nick !== '' ? $nick : '队员';
        }
        $out = ['队内成员：已通过审核 ' . (int)$total . ' 人，此刻在线 ' . count($names) . ' 人（5 分钟内有操作）。'];
        if ($names !== []) {
            $out[] = '在线：' . implode('、', $names) . (count($rows) >= 12 ? '…' : '');
        }
        $duty = DutyService::dutyOn(date('Y-m-d'));
        $out[] = '今日房主值班：' . ($duty ? $duty['nickname'] . ($duty['swapped'] ? '（与 ' . $duty['swapped_from'] . ' 换班）' : '') : '未安排');
        return implode("\n", $out);
    }

    /** 战队介绍：名称、简介、成员数、是否在招募，以及对外展示页链接（没开就不提） */
    private static function intro(int $tid): string
    {
        $t    = Db::name('tenants')->where('id', $tid)->find();
        if (!$t) {
            return '没查到这个战队的信息。';
        }
        $desc = trim((string)($t['description'] ?? ''));
        if (mb_strlen($desc) > 120) {
            $desc = mb_substr($desc, 0, 120) . '…';
        }
        $out = ['战队介绍：' . (string)$t['name']];
        $out[] = $desc !== '' ? $desc : '（这队还没填简介，可让管理员在网站「对外展示页」里补）';
        $out[] = '已通过审核成员 ' . (int)User::where('status', 'approved')->count() . ' 人。';
        $cfg = Showcase::config($tid);
        $out[] = !empty($cfg['recruiting']) ? '当前状态：正在招募。' : '当前状态：暂不招募。';
        if (Showcase::visible($tid)) {
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
            $slug = (string)$t['slug'];
            $out[] = '对外展示页：' . ($host !== '' ? 'https://' . $host . '/' . $slug . '/show' : '/' . $slug . '/show');
        }
        return implode("\n", $out);
    }

    // ==================== 帮助 ====================

    private static function help(): string
    {
        return "指令菜单（@我 + 指令）：\n"
            . "· 签到 — 每日签到记连签\n"
            . "· 地图 — 今日与接下来 3 天的轮换地图\n"
            . "· 改枪码 — 队内已上架枪码\n"
            . "· 日程 — 今日日程一览\n"
            . "· 训练赛 — 报名中/进行中的训练赛\n"
            . "· 撞车 — 近期撞车时间\n"
            . "· 值班 — 今日房主值班\n"
            . "· 伪军 名字 — 查询是否队内收录成员\n"
            . "· 我的战绩 — 我上传过的战绩（需先绑定账号）\n"
            . "· 战绩榜 — 队内战绩排名\n"
            . "· 成员 — 队内成员与此刻在线\n"
            . "· 介绍 — 战队简介与对外展示页\n"
            . "· 绑定 六位码 — 绑定网站账号（个人中心获取码）\n"
            . "· 解绑 — 解除账号绑定\n"
            . "指令带不带「/」都行。\n"
            . "队长开启 AI 聊天后，@我 随便聊也行（AI 只聊天，不执行指令）。";
    }

    // ==================== 工具 ====================

    /** 去掉 @机器人 前缀与多余空白（v5.36.14 起也供 AI 聊天归一化正文） */
    public static function clean(string $raw): string
    {
        // v5.36.20：QQ 推来的正文里可能夹着零宽字符 / 不换行空格（\u200B、\uFEFF、\u00A0…），
        // trim() 和 \s 都清不掉，会让「帮助」「地图」这类完全匹配的指令对不上（签到是包含匹配所以正常）。
        $text = preg_replace('/[\x{200B}-\x{200F}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{FEFF}\x{00A0}]/u', ' ', $raw)
            ?? $raw;
        // QQ 群 @消息正文的几种形式都剥掉前缀（可多次出现）：
        //   官方富文本  <@!机器人openid> 文本 / <@机器人openid> 文本
        //   常见别名   @机器人名 文本
        $text = trim($text);
        for ($i = 0; $i < 3; $i++) {
            $stripped = preg_replace('/^(?:<[^>]{1,64}>|@[^\s@]+)[\s]*/u', '', $text);
            if (!is_string($stripped) || $stripped === $text) {
                break;
            }
            $text = $stripped;
        }
        // 全角空格归一
        $text = str_replace("\u{3000}", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = self::trimSpace($text);
        // v5.28.0：QQ 群里很多人习惯带斜杠发指令（/帮助），而面板里填的是裸词。
        // 行首斜杠/井号一律剥掉（半角/全角都算），两种写法从此等价；
        // 井号也要剥：forceCmd 认 #，「#伪军 张三」若把 # 留在正文里，查的会是「#张三」。
        $text = preg_replace('~^[/／#＃]+~u', '', $text) ?? $text;
        return self::trimSpace($text);
    }

    /** 去掉首尾所有空白（含 Unicode 空格），比 trim() 彻底 */
    private static function trimSpace(string $s): string
    {
        return preg_replace('/^[\s\x{3000}\x{00A0}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{FEFF}]+|'
            . '[\s\x{3000}\x{00A0}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{FEFF}]+$/u', '', $s) ?? $s;
    }

    /** 切换租户上下文（回调为根级路由，无租户中间件，手工切入） */
    private static function enterTenant(array $tenant): void
    {
        TenantContext::set([
            'id'     => (int)$tenant['id'],
            'slug'   => (string)$tenant['slug'],
            'name'   => (string)$tenant['name'],
            'logo'   => (string)$tenant['logo'],
            'status' => (string)$tenant['status'],
        ]);
    }

    /**
     * 带机器人上下文的指令处理（战队独立机器人专用）
     *
     * 安全模型与平台入口一致：指令只在「已绑定群」内生效——
     * 切换为独立机器人后 group_openid 不同，需重新绑定群、成员重新取码绑定。
     * @param string $groupOpenid 群openid
     * @param string $memberOpenid 成员openid
     * @param string $text 消息内容
     * @param string $key 回调密钥
     * @return string 回复文本
     */
    public static function handleWithBot(string $groupOpenid, string $memberOpenid, string $text, string $key): string
    {
        return self::handleWithBotEx($groupOpenid, $memberOpenid, $text, $key)['reply'];
    }

    /**
     * 带上下文的指令处理（v5.36.14）：除了回复文本，还把「是否受理 / 群是否已绑定 /
     * 战队 ID / 清洗后的正文」交给回调，用来决定要不要转给 AI 聊天。
     *
     * @return array{text:string,cmd:string,reply:string,accepted:bool,bound:bool,active:bool,tenant_id:int}
     */
    public static function handleWithBotEx(string $groupOpenid, string $memberOpenid, string $text, string $key): array
    {
        $none = ['text' => '', 'cmd' => '', 'reply' => '', 'accepted' => false, 'bound' => false, 'active' => false, 'tenant_id' => 0];
        // 行首斜杠/井号在 clean() 里会被剥掉，先记下来：带它的消息一律当指令，不许转 AI。
        // 注意 QQ 推来的正文前面可能还有 <@!机器人> / @机器人名 前缀，要跳过它们再看第一个字。
        $forceCmd = (bool)preg_match('/^(?:\s*(?:<[^>]{1,64}>|@[^\s@]+)\s*)*[\/／#]/u', $text);
        $text = self::clean($text);
        if ($text === '') {
            return $none;
        }

        // 群限流：超限静默不回复（AI 聊天也不接力，避免绕过限流）
        $rateKey = 'bot_rate_' . md5($groupOpenid);
        $n = (int)Cache::get($rateKey, 0);
        if ($n >= self::RATE_LIMIT) {
            return ['text' => $text] + $none;
        }
        rate_bump($rateKey, 60);   // v5.15.0(S-M4)：flock 互斥自增，洪水打不穿每群 30 条/分钟

        $scope = self::botScope($groupOpenid, $key);
        if (!$scope['accepted']) {
            return ['text' => $text] + $none;
        }
        $tid = (int)$scope['tenant_id'];
        $base = [
            'text' => $text, 'cmd' => '', 'reply' => '', 'accepted' => true,
            'bound' => (bool)$scope['bound'], 'active' => (bool)$scope['active'], 'tenant_id' => $tid,
        ];

        // v5.9.0：群绑定检查——独立机器人的回调密钥只能定位战队，
        // 群必须完成绑定后才响应指令（防止机器人被拉进任意群泄露战队数据）
        if (!$scope['bound']) {
            $reply = self::unbound($groupOpenid, $memberOpenid, $text);
            self::logCall($tid, $groupOpenid, $memberOpenid,
                mb_strpos($text, '绑定') !== false ? '群绑定取码' : '(未绑定群)', $text);
            return ['cmd' => '(未绑定群)', 'reply' => $reply] + $base;
        }

        if (!$scope['active']) {
            self::logCall($tid, $groupOpenid, $memberOpenid, '(站点停用)', $text);
            return ['cmd' => '(站点停用)', 'reply' => '该战队站点当前处于停用/暂停状态，机器人服务暂停。'] + $base;
        }

        // v5.28.0：不再有平台授权白名单——所有战队都能用自己的机器人，
        // 只看战队管理员开关（enabled）与凭据是否齐全
        [$cmd, $reply] = self::dispatch($tid, $memberOpenid, $text, $forceCmd);
        self::logCall($tid, $groupOpenid, $memberOpenid, $cmd, $text);
        if ($cmd === '(未知)') {
            // 记录十六进制，把肉眼看不见的字符暴露出来（自检页会显示）
            QqBot::debugLog([
                'type' => 'cmd_unknown',
                'text' => mb_substr($text, 0, 40),
                'hex'  => bin2hex(mb_substr($text, 0, 20)),
                'key'  => substr($key, 0, 8),
            ]);
        }
        return ['cmd' => $cmd, 'reply' => $reply] + $base;
    }

    /**
     * 机器人 + 群绑定的作用域检查（不派发指令，不占指令限流）。
     * 全量群消息自动接话走这条：先确认这台机器人属于谁、群是否绑过、站点是否可用。
     *
     * @return array{accepted:bool,bound:bool,active:bool,tenant_id:int,tenant:array|null}
     */
    public static function botScope(string $groupOpenid, string $key): array
    {
        $none = ['accepted' => false, 'bound' => false, 'active' => false, 'tenant_id' => 0, 'tenant' => null];

        // 查找战队机器人配置（启用中 + 凭据齐全才受理）
        $botConfig = Db::name('bot_tenant_bots')
            ->where('callback_key', $key)
            ->where('enabled', 1)
            ->find();
        if (!$botConfig
            || trim((string)$botConfig['app_id']) === ''
            || trim((string)$botConfig['app_secret']) === '') {
            return $none;
        }

        $tid = (int)$botConfig['tenant_id'];
        $binding = Db::name('bot_group_bindings')
            ->where('tenant_id', $tid)
            ->where('group_openid', $groupOpenid)
            ->find();
        if (!$binding) {
            return ['accepted' => true, 'bound' => false, 'active' => false, 'tenant_id' => $tid, 'tenant' => null];
        }

        $tenant = Db::name('tenants')->where('id', $tid)->find();
        $active = $tenant && $tenant['status'] === 'approved';
        if ($active) {
            self::enterTenant($tenant);
        }
        return [
            'accepted' => true, 'bound' => true, 'active' => (bool)$active,
            'tenant_id' => $tid, 'tenant' => $active ? $tenant : null,
        ];
    }

    /** 是不是一条「像指令」的消息（全量接话时先排除，避免和指令抢答） */
    public static function looksLikeCommand(string $text): bool
    {
        $text = self::clean($text);
        if ($text === '') {
            return false;
        }
        if (preg_match('/^绑定\s*\d{6}$/u', $text)) {
            return true;
        }
        $heads = ['签到', '轮换', '地图', '今日地图', '今日轮换', '枪码', '改枪码', '日程', '训练赛',
            '撞车', '值班', '伪军', '我的战绩', '战绩榜', '排行', '成员', '在线', '介绍',
            '绑定', '解绑', '帮助', '菜单', '功能', '指令'];
        foreach ($heads as $h) {
            // 全自动接话是「没 @ 机器人」的场景，判定要保守：
            // 只有整句就是指令、或指令后面跟参数，才算指令；「今晚训练赛几点」这类闲聊仍交给 AI
            if ($text === $h || str_starts_with($text, $h . ' ') || str_starts_with($text, $h . "\u{3000}")) {
                return true;
            }
        }
        return false;
    }

}
