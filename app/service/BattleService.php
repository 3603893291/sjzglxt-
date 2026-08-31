<?php
declare (strict_types = 1);

namespace app\service;

use app\model\User;
use think\facade\Db;

/**
 * 跨战队约战总线（v5.4.0）
 *
 * 平台级业务（表均无租户列）：发起/接受/拒绝/取消、Ban/Pick、随机抽签、
 * 系列赛逐局录入与自动结算、出战名单管理。
 * 三个入口复用本服务：
 *   - 站内（session）：app/controller/Battle.php、BattleAdmin.php
 *   - 平台超管（session）：Platform.php
 *   - 开放 API（X-Api-Key）：OpenBattle.php
 *
 * 约定：方法返回 null = 成功；返回字符串 = 错误信息（控制器转 api_err）。
 * 参考三角洲行动全面战场赛事：双方各禁 1 图 → 随机抽各局地图与攻守方 →
 * bo1/bo3/bo5 系列赛，逐局无平局，先到胜场数自动结算。
 */
class BattleService
{
    /** 赛制 => 总场数（bo=系列赛；r=攻防互换小场，打满结算可平局） */
    public const FORMATS = ['bo1' => 1, 'bo3' => 3, 'bo5' => 5, 'r2' => 2, 'r4' => 4];

    /** 系列赛所需胜场数（仅 bo 赛制；r 赛制无系列赛胜者） */
    public static function winsNeeded(string $format): int
    {
        $n = self::FORMATS[$format] ?? 3;
        return intdiv($n, 2) + 1;
    }

    /** r 赛制（r2 一图两把 / r4 两图四把）：无胜者门槛，打满结算可平局 */
    public static function isRFormat(string $format): bool
    {
        return $format === 'r2' || $format === 'r4';
    }

    /** 决胜局局序（bo3=第3局 / bo5=第5局；其余无决胜局） */
    public static function deciderRoundNo(string $format): int
    {
        return $format === 'bo3' ? 3 : ($format === 'bo5' ? 5 : 0);
    }

    /** 参赛方显示名（租户取站名，外部战队取登记名） */
    public static function partyName(string $type, int $id): string
    {
        if ($type === 'tenant') {
            return (string)(Db::name('tenants')->where('id', $id)->value('name') ?: '已注销站点');
        }
        if ($type === 'external') {
            return (string)(Db::name('external_teams')->where('id', $id)->value('name') ?: '已注销外部战队');
        }
        return '未知战队';
    }

    /** 判断参赛方行是否存在且状态正常 */
    public static function partyActive(string $type, int $id): bool
    {
        if ($type === 'tenant') {
            return (bool)Db::name('tenants')->where('id', $id)->where('status', 'approved')->find();
        }
        if ($type === 'external') {
            return (bool)Db::name('external_teams')->where('id', $id)->where('status', 1)->find();
        }
        return false;
    }

    /** 某参赛方在约战单中是哪一方（'a'/'b'/null） */
    public static function sideOfParty(array $b, string $type, int $id): ?string
    {
        if ($b['challenger_type'] === $type && (int)$b['challenger_id'] === $id) return 'a';
        if ($b['defender_type'] === $type && (int)$b['defender_id'] === $id) return 'b';
        return null;
    }

    /**
     * 过期惰性处理：开赛时间已过、仍停在 pending/accepted 且从未抽签的约战自动置过期。
     * 已抽签的视为赛事进行中（可能正在打），不做自动过期，由平台超管裁决。
     */
    public static function expireLazy(): void
    {
        Db::name('battles')
            ->whereIn('status', ['pending', 'accepted'])
            ->whereNull('drawn_at')
            ->where('battle_time', '<', date('Y-m-d H:i:s'))
            ->update(['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** 平台地图池（入驻平台轮换地图 tenant_id=0，供 Ban/Pick 与抽签） */
    public static function mapPool(): array
    {
        return Db::name('rotation_maps')->where('tenant_id', 0)
            ->order('sort', 'asc')->order('id', 'asc')
            ->field('id, name, image')
            ->select()->toArray();
    }

    /** 系列赛当前比分 [a胜场, b胜场] */
    public static function roundsScore(int $battleId): array
    {
        $rows = Db::name('battle_rounds')->where('battle_id', $battleId)->column('result');
        $a = count(array_filter($rows, function ($r) { return $r === 'a_win'; }));
        $b = count(array_filter($rows, function ($r) { return $r === 'b_win'; }));
        return [$a, $b];
    }

    // ==================== 发起 / 应战 / 取消 ====================

    /**
     * 发起约战。$p 需含：title, game_mode, battle_time, room_info, remark,
     * series_format, roster_limit, challenger_type/id, defender_type/id
     * 返回 [约战id, 错误信息]（成功时错误为 null）
     */
    public static function create(array $p, string $actorDesc): array
    {
        $title = trim((string)($p['title'] ?? ''));
        $format = (string)($p['series_format'] ?? 'bo3');
        $btype  = (string)($p['battle_type'] ?? 'official');
        $limit  = (int)($p['roster_limit'] ?? 20);
        $time   = trim((string)($p['battle_time'] ?? ''));

        if ($title === '' || mb_strlen($title) > 50) {
            return [0, '请填写约战标题（不超过 50 字）'];
        }
        if (!isset(self::FORMATS[$format])) {
            return [0, '赛制无效（可选 bo1/bo3/bo5/r2/r4）'];
        }
        if (!in_array($btype, ['official', 'training'], true)) {
            return [0, '赛事类型无效（正式赛事/训练赛）'];
        }
        if ($limit < 0 || $limit > 100) {
            return [0, '出战人数上限需在 0-100 之间（0=不限）'];
        }
        $ts = strtotime($time);
        if ($ts === false || $ts <= time()) {
            return [0, '开赛时间必须是将来的有效时间'];
        }
        if ($ts > time() + 366 * 86400) {
            return [0, '开赛时间最远一年'];
        }

        $ct = (string)($p['challenger_type'] ?? '');
        $ci = (int)($p['challenger_id'] ?? 0);
        $dt = (string)($p['defender_type'] ?? '');
        $di = (int)($p['defender_id'] ?? 0);
        if (!in_array($ct, ['tenant', 'external'], true) || !in_array($dt, ['tenant', 'external'], true)) {
            return [0, '参赛方类型无效'];
        }
        if ($ct === $dt && $ci === $di) {
            return [0, '不能向自己发起约战'];
        }
        if (!self::partyActive($ct, $ci) || !self::partyActive($dt, $di)) {
            return [0, '对方战队不存在或状态异常'];
        }

        // 同双方进行中的约战唯一（正反方向都算），防重复刷屏
        $dup = Db::name('battles')
            ->whereIn('status', ['pending', 'accepted'])
            ->where(function ($q) use ($ct, $ci, $dt, $di) {
                $q->where(function ($q2) use ($ct, $ci, $dt, $di) {
                    $q2->where('challenger_type', $ct)->where('challenger_id', $ci)
                        ->where('defender_type', $dt)->where('defender_id', $di);
                })->whereOr(function ($q2) use ($ct, $ci, $dt, $di) {
                    $q2->where('challenger_type', $dt)->where('challenger_id', $di)
                        ->where('defender_type', $ct)->where('defender_id', $ci);
                });
            })
            ->find();
        if ($dup) {
            return [0, '双方之间已有进行中的约战，请先完结或取消'];
        }

        $now = date('Y-m-d H:i:s');
        $id = Db::name('battles')->insertGetId([
            'title'           => $title,
            'game_mode'       => mb_substr(trim((string)($p['game_mode'] ?? '')), 0, 50),
            'battle_time'     => date('Y-m-d H:i:s', $ts),
            'room_info'       => mb_substr(trim((string)($p['room_info'] ?? '')), 0, 200),
            'remark'          => mb_substr(trim((string)($p['remark'] ?? '')), 0, 500),
            'challenger_type' => $ct,
            'challenger_id'   => $ci,
            'defender_type'   => $dt,
            'defender_id'     => $di,
            'status'          => 'pending',
            'battle_type'     => $btype,
            'series_format'   => $format,
            'roster_limit'    => $limit,
            'created_by'      => mb_substr($actorDesc, 0, 64),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        return [(int)$id, null];
    }

    /** 应战（仅 b 方；调用方需先核验身份） */
    public static function accept(int $battleId, string $actorDesc): ?string
    {
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'pending') return '该约战当前状态不能接受';
        Db::name('battles')->where('id', $battleId)->update([
            'status'      => 'accepted',
            'accepted_by' => mb_substr($actorDesc, 0, 64),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return null;
    }

    /** 拒绝（仅 b 方） */
    public static function reject(int $battleId, string $actorDesc): ?string
    {
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'pending') return '该约战已进入后续流程，不能拒绝（可取消）';
        Db::name('battles')->where('id', $battleId)->update([
            'status'     => 'rejected',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return null;
    }

    /**
     * 取消：a 方可取消 pending/accepted；b 方可取消 accepted；平台超管任意阶段强制取消。
     * 调用方需先按上述规则核验身份（$allowed=true 即已通过守卫）。
     */
    public static function cancel(int $battleId, string $actorDesc): ?string
    {
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if (!in_array($b['status'], ['pending', 'accepted'], true)) {
            return '该约战已结束，不能取消';
        }
        Db::name('battles')->where('id', $battleId)->update([
            'status'      => 'canceled',
            'canceled_by' => mb_substr($actorDesc, 0, 64),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return null;
    }

    // ==================== Ban/Pick 与抽签 ====================

    /**
     * 提交本方禁用地图（每方一次）。$mapId=0 表示放弃 Ban 权（也记为已提交）。
     * 仅 accepted 且未抽签阶段可提交。
     */
    public static function ban(int $battleId, string $side, int $mapId): ?string
    {
        if (!in_array($side, ['a', 'b'], true)) return '参赛方无效';
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可提交禁用地图';
        if ($b['drawn_at'] !== null) return '已抽签，禁用地图通道已关闭';
        $col = $side === 'a' ? 'ban_a_map_id' : 'ban_b_map_id';
        if ($b[$col] !== null) return '本方已提交过禁用地图，不能重复提交';

        if ($mapId !== 0) {
            $map = Db::name('rotation_maps')->where('id', $mapId)->where('tenant_id', 0)->find();
            if (!$map) return '禁用的地图不在平台地图池中';
        }
        Db::name('battles')->where('id', $battleId)->update([
            $col         => $mapId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 训练赛：双方禁图（或放弃）都提交完毕后系统自动抽签，无需平台超管手动执行
        if (($b['battle_type'] ?? '') === 'training') {
            $fresh = Db::name('battles')->where('id', $battleId)->find();
            if ($fresh && $fresh['ban_a_map_id'] !== null && $fresh['ban_b_map_id'] !== null && $fresh['drawn_at'] === null) {
                self::draw($battleId, 0);
            }
        }
        return null;
    }

    /**
     * 抽签（正式赛由平台超管手动执行，训练赛由 ban() 自动触发，控制器已核验）：
     * 同图两把攻防互换（首把随机攻守）；bo5/r4 第三四局换第二张图重新抽攻守；
     * bo3 第 3 局 / bo5 第 5 局为决胜局：地图与攻守留空，由优势方选图/选边。
     * 已抽签且无任何小局胜负时允许重抽（先清旧局）。
     */
    public static function draw(int $battleId, int $platformUserId): ?string
    {
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可抽签';

        $format = (string)$b['series_format'];
        $need   = self::FORMATS[$format] ?? 3;
        // 抽签所需不同地图数：决胜局地图由优势方后选，不参与抽签（bo5 第5局、bo3 第3局）
        $needMaps = in_array($format, ['bo5', 'r4'], true) ? 2 : 1;

        // 重抽校验：已有小局录过胜负则禁止
        $hasResult = Db::name('battle_rounds')->where('battle_id', $battleId)
            ->whereNotNull('result')->find();
        if ($b['drawn_at'] !== null && $hasResult) {
            return '已有小局录入胜负，不能重抽（如需改判请取消后重建约战）';
        }

        // 地图池排除双 Ban（同一张图被双方同时 Ban 只排一次；0=放弃不计）
        $banned = array_values(array_unique(array_filter([
            (int)($b['ban_a_map_id'] ?: 0),
            (int)($b['ban_b_map_id'] ?: 0),
        ])));
        $pool = Db::name('rotation_maps')->where('tenant_id', 0)
            ->when($banned !== [], function ($q) use ($banned) { $q->whereNotIn('id', $banned); })
            ->order('sort', 'asc')->order('id', 'asc')
            ->field('id, name')->select()->toArray();
        if (count($pool) < $needMaps) {
            return '地图池剔除禁用图后仅剩 ' . count($pool) . ' 张，不足 ' . $needMaps . ' 张（请补充轮换地图或改赛制）';
        }

        // 随机抽图（shuffle 底层 Fisher-Yates）
        $keys = array_keys($pool);
        shuffle($keys);
        $m1 = $pool[$keys[0]];
        $m2 = $needMaps > 1 ? $pool[$keys[1]] : null;

        // 按赛制生成小局：同图两把攻防互换，第二张图重新随机首把攻守；决胜局留空待选
        $plan  = [];
        $side1 = random_int(0, 1) === 0 ? 'a' : 'b';
        $plan[] = [$m1, $side1];
        if ($need >= 2) $plan[] = [$m1, $side1 === 'a' ? 'b' : 'a'];
        if ($format === 'bo3') $plan[] = [null, null];                       // 决胜局（优势方选图/选边）
        if ($need >= 4) {
            $side3 = random_int(0, 1) === 0 ? 'a' : 'b';
            $plan[] = [$m2, $side3];
            $plan[] = [$m2, $side3 === 'a' ? 'b' : 'a'];
        }
        if ($format === 'bo5') $plan[] = [null, null];                       // 决胜局（优势方选图/选边）

        $now = date('Y-m-d H:i:s');
        Db::name('battle_rounds')->where('battle_id', $battleId)->delete();  // 清旧局（重抽）
        foreach ($plan as $i => $it) {
            [$map, $side] = $it;
            Db::name('battle_rounds')->insert([
                'battle_id'     => $battleId,
                'round_no'      => $i + 1,
                'map_id'        => $map ? (int)$map['id'] : null,
                'map_name'      => $map ? (string)$map['name'] : '',
                'attacker_side' => $side,
                'created_at'    => $now,
            ]);
        }
        Db::name('battles')->where('id', $battleId)->update([
            'drawn_at'   => $now,
            'adv_side'   => null,
            'adv_reason' => '',
            'adv_role'   => null,
            'updated_at' => $now,
        ]);
        return null;
    }

    /**
     * 录入小局胜负。正式赛事仅平台超管（控制器已核验）；训练赛双方管理员皆可（可附截图）。
     * 无平局；录入后不可改。$extra 可含 evidence/attack_secs/occupy_count/occupy_ratio。
     * bo 赛制某方先达胜场数自动完赛；r 赛制打满全部小局结算（可平局）。
     * bo3 战成 1:1 / bo5 战成 2:2 时自动判定决胜局优势方。
     */
    public static function recordRound(int $battleId, int $roundId, string $result, int $actorId, array $extra = []): ?string
    {
        if (!in_array($result, ['a_win', 'b_win'], true)) return '小局胜负只能是 A 胜或 B 胜';
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可录入胜负';
        if ($b['drawn_at'] === null) return '尚未抽签，请先抽签生成对局';

        $round = Db::name('battle_rounds')->where('id', $roundId)->where('battle_id', $battleId)->find();
        if (!$round) return '该小局不存在';
        if ($round['result'] !== null) return '该小局胜负已录入，不能修改';
        if ($round['map_id'] === null) return '决胜局尚未选图，请先由优势方选图或选边';

        $upd = ['result' => $result, 'result_by' => $actorId];
        if (isset($extra['evidence'])) {
            $upd['evidence'] = mb_substr((string)$extra['evidence'], 0, 200);
        }
        foreach (['attack_secs', 'occupy_count', 'occupy_ratio'] as $k) {
            if (isset($extra[$k]) && $extra[$k] !== '' && $extra[$k] !== null) {
                $upd[$k] = max(0, (int)$extra[$k]);
            }
        }
        Db::name('battle_rounds')->where('id', $roundId)->update($upd);

        $format = (string)$b['series_format'];
        [$sa, $sb] = self::roundsScore($battleId);
        $now = date('Y-m-d H:i:s');

        if (self::isRFormat($format)) {
            // r 赛制：全部小局录完即结算，允许平局（如 R2 打成 1:1）
            $done = (int)Db::name('battle_rounds')->where('battle_id', $battleId)->whereNotNull('result')->count();
            if ($done >= self::FORMATS[$format]) {
                $res = $sa > $sb ? 'a_win' : ($sb > $sa ? 'b_win' : 'draw');
                Db::name('battles')->where('id', $battleId)->update([
                    'status'      => 'finished',
                    'result'      => $res,
                    'result_note' => '系列赛 ' . $sa . ' : ' . $sb . ($res === 'draw' ? '（平局）' : ''),
                    'result_by'   => $actorId,
                    'finished_at' => $now,
                    'updated_at'  => $now,
                ]);
            } else {
                Db::name('battles')->where('id', $battleId)->update(['updated_at' => $now]);
            }
            return null;
        }

        // bo 赛制：先达胜场数即完赛；否则检查是否战成决胜比分（bo3 1:1 / bo5 2:2）
        $need = self::winsNeeded($format);
        if ($sa >= $need || $sb >= $need) {
            Db::name('battles')->where('id', $battleId)->update([
                'status'      => 'finished',
                'result'      => $sa >= $need ? 'a_win' : 'b_win',
                'result_note' => '系列赛 ' . $sa . ' : ' . $sb,
                'result_by'   => $actorId,
                'finished_at' => $now,
                'updated_at'  => $now,
            ]);
        } else {
            Db::name('battles')->where('id', $battleId)->update(['updated_at' => $now]);
            if ($sa === $need - 1 && $sb === $need - 1 && self::deciderRoundNo($format) > 0) {
                self::computeAdvantage($battleId, $b);
            }
        }
        return null;
    }

    /**
     * 决胜局优势方判定（战成决胜比分时自动触发）。
     * bo5：率先拿到两局胜利的一方。
     * bo3：前两局均为进攻方获胜 → 进攻用时较短者；均为防守方获胜 → 占领区域总数较多者，
     * 相同时比占点比例；混合或数据缺失：训练赛随机决定，正式赛留空待赛事组指定。
     */
    protected static function computeAdvantage(int $battleId, array $b): void
    {
        $format = (string)$b['series_format'];
        $rounds = Db::name('battle_rounds')->where('battle_id', $battleId)
            ->order('round_no', 'asc')->select()->toArray();
        $side = null;
        $reason = '';

        if ($format === 'bo5') {
            $wins = ['a' => 0, 'b' => 0];
            foreach ($rounds as $r) {
                if ($r['result'] === 'a_win') $wins['a']++;
                elseif ($r['result'] === 'b_win') $wins['b']++;
                if ($wins['a'] === 2 || $wins['b'] === 2) {
                    $side = $wins['a'] === 2 ? 'a' : 'b';
                    break;
                }
            }
            $reason = '率先拿到两局胜利，获得决胜局选图/选边优先权';
        } elseif ($format === 'bo3') {
            $r1 = $rounds[0] ?? null;
            $r2 = $rounds[1] ?? null;
            if ($r1 && $r2 && $r1['result'] && $r2['result']) {
                // 各局胜者；是否进攻方获胜（胜方=该局攻方）
                $w1 = $r1['result'] === 'a_win' ? 'a' : 'b';
                $w2 = $r2['result'] === 'a_win' ? 'a' : 'b';
                $a1 = ($r1['result'] === $r1['attacker_side'] . '_win');
                $a2 = ($r2['result'] === $r2['attacker_side'] . '_win');
                if ($a1 && $a2 && $r1['attack_secs'] !== null && $r2['attack_secs'] !== null
                    && (int)$r1['attack_secs'] !== (int)$r2['attack_secs']) {
                    // 双进攻方获胜：比较各自进攻局的进攻用时，短者为优势方
                    $side = (int)$r1['attack_secs'] < (int)$r2['attack_secs'] ? $w1 : $w2;
                    $reason = '前两局均为进攻方获胜，进攻用时较短方为优势方';
                } elseif (!$a1 && !$a2) {
                    if ($r1['occupy_count'] !== null && $r2['occupy_count'] !== null
                        && (int)$r1['occupy_count'] !== (int)$r2['occupy_count']) {
                        // 双防守方获胜：比较各自防守局的占领区域总数，多者为优势方
                        $side = (int)$r1['occupy_count'] > (int)$r2['occupy_count'] ? $w1 : $w2;
                        $reason = '前两局均为防守方获胜，占领区域较多方为优势方';
                    } elseif ($r1['occupy_ratio'] !== null && $r2['occupy_ratio'] !== null
                        && (int)$r1['occupy_ratio'] !== (int)$r2['occupy_ratio']) {
                        // 占领区域相同：比占点比例（占点总比例高者为优势方）
                        $side = (int)$r1['occupy_ratio'] > (int)$r2['occupy_ratio'] ? $w1 : $w2;
                        $reason = '占领区域相同，占点比例较高方为优势方';
                    }
                }
            }
            if ($side === null) {
                if (($b['battle_type'] ?? 'official') === 'training') {
                    $side = random_int(0, 1) === 0 ? 'a' : 'b';
                    $reason = '训练赛随机决定优势方';
                } else {
                    $reason = '正式赛待赛事组指定优势方';
                }
            }
        }

        Db::name('battles')->where('id', $battleId)->update([
            'adv_side'   => $side,
            'adv_reason' => mb_substr($reason, 0, 120),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 手动指定决胜局优势方（正式赛赛事组裁决入口，本版本由平台超管代管；选定前可反复改） */
    public static function setAdvantage(int $battleId, string $side): ?string
    {
        if (!in_array($side, ['a', 'b'], true)) return '优势方只能是 A 或 B';
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可指定优势方';
        if (!in_array((string)$b['series_format'], ['bo3', 'bo5'], true)) return '仅 bo3/bo5 赛制有决胜局';
        if ($b['adv_role'] !== null) return '决胜局已完成选图/选边，不能再改优势方';
        Db::name('battles')->where('id', $battleId)->update([
            'adv_side'   => $side,
            'adv_reason' => '赛事组指定',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return null;
    }

    /**
     * 决胜局选图/选边（优势方二选一，仅一次）：
     * pick_map=优势方指定地图（系统随机攻防）；pick_side=优势方指定攻方（系统从剩余池随机抽图）。
     * $attackerSide 为选边时指定的攻方（控制器按优势方意愿把「进攻/防守」折算成 a/b）。
     * $actor=[类型, id]：控制器已核验其为优势方管理员或平台身份，服务层再兼容校验一次。
     */
    public static function deciderPick(int $battleId, string $choice, int $mapId, string $attackerSide, array $actor): ?string
    {
        if (!in_array($choice, ['pick_map', 'pick_side'], true)) return '决胜选择只能是选图或选边';
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可操作决胜局';
        $format = (string)$b['series_format'];
        $dno = self::deciderRoundNo($format);
        if ($dno === 0) return '该赛制没有决胜局';
        if ($b['adv_side'] === null) return '优势方尚未判定（正式赛待赛事组指定）';
        if ($b['adv_role'] !== null) return '决胜局已完成选图/选边，不能重复操作';
        if ($actor[0] !== 'platform' && self::sideOfParty($b, (string)$actor[0], (int)$actor[1]) !== $b['adv_side']) {
            return '仅决胜局优势方可选图/选边';
        }

        $round = Db::name('battle_rounds')->where('battle_id', $battleId)->where('round_no', $dno)->find();
        if (!$round || $round['result'] !== null) return '决胜局不存在或已结束';
        if ($round['map_id'] !== null) return '决胜局地图已确定';

        // 剩余地图池：平台池排除双 Ban 与本场已用地图（决胜局地图不可与当场前序地图相同）
        $used   = array_map('intval', Db::name('battle_rounds')->where('battle_id', $battleId)
            ->whereNotNull('map_id')->column('map_id'));
        $banned = array_values(array_unique(array_filter([
            (int)($b['ban_a_map_id'] ?: 0), (int)($b['ban_b_map_id'] ?: 0),
        ])));
        $exclude = array_values(array_unique(array_merge($used, $banned)));
        $pool = Db::name('rotation_maps')->where('tenant_id', 0)
            ->when($exclude !== [], function ($q) use ($exclude) { $q->whereNotIn('id', $exclude); })
            ->field('id, name')->select()->toArray();

        if ($choice === 'pick_map') {
            $hit = null;
            foreach ($pool as $m) {
                if ((int)$m['id'] === $mapId) { $hit = $m; break; }
            }
            if (!$hit) return '所选地图不可用（已被禁用、本场已用或不在平台地图池）';
            $att = random_int(0, 1) === 0 ? 'a' : 'b';
            Db::name('battle_rounds')->where('id', $round['id'])->update([
                'map_id' => (int)$hit['id'], 'map_name' => (string)$hit['name'], 'attacker_side' => $att,
            ]);
        } else {
            if (!in_array($attackerSide, ['a', 'b'], true)) return '选边需指定攻方（a/b）';
            if ($pool === []) return '地图池已无可用新图，请联系平台超管补充轮换地图后重试';
            $m = $pool[random_int(0, count($pool) - 1)];
            Db::name('battle_rounds')->where('id', $round['id'])->update([
                'map_id' => (int)$m['id'], 'map_name' => (string)$m['name'], 'attacker_side' => $attackerSide,
            ]);
        }
        Db::name('battles')->where('id', $battleId)->update([
            'adv_role'   => $choice,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return null;
    }

    // ==================== 出战名单 ====================

    /** 某方当前报名人数 */
    public static function sideCount(int $battleId, string $side): int
    {
        return (int)Db::name('battle_signups')->where('battle_id', $battleId)->where('side', $side)->count();
    }

    /** 上限校验（0=不限；$bypass=平台超管强制调配） */
    protected static function limitPass(array $b, string $side, bool $bypass): bool
    {
        $limit = (int)$b['roster_limit'];
        if ($limit <= 0 || $bypass) return true;
        return self::sideCount((int)$b['id'], $side) < $limit;
    }

    /** 平台租户成员为自己报名（仅 accepted；超上限拒绝） */
    public static function userSignup(int $battleId, User $user): ?string
    {
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可报名出战';
        $side = self::sideOfParty($b, 'tenant', (int)$user->tenant_id);
        if ($side === null) return '只有对阵双方的成员才能报名';
        if ($user->status !== 'approved') return '账号未通过审核，不能报名';

        $exists = Db::name('battle_signups')->where('battle_id', $battleId)
            ->where('side', $side)->where('user_id', $user->id)->find();
        if ($exists) return '你已在出战名单中';
        if (!self::limitPass($b, $side, false)) return '本方出战人数已满';

        Db::name('battle_signups')->insert([
            'battle_id'    => $battleId,
            'side'         => $side,
            'signup_type'  => 'user',
            'user_id'      => $user->id,
            'display_name' => mb_substr((string)($user->nickname ?: $user->username), 0, 32),
            'tenant_id'    => (int)$user->tenant_id,
            'manual'       => 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        return null;
    }

    /** 平台租户成员退出出战名单 */
    public static function userCancelSignup(int $battleId, User $user): ?string
    {
        $n = Db::name('battle_signups')->where('battle_id', $battleId)
            ->where('user_id', $user->id)->delete();
        return $n > 0 ? null : '你不在该约战的出战名单中';
    }

    /**
     * 管理员添加平台租户用户到名单（批量）。
     * $expectTenant：要求用户必须属于该租户（站内/租户 Key 场景）；平台超管调配也传同一租户。
     */
    public static function addUsers(int $battleId, string $side, array $userIds, int $expectTenant, bool $bypassLimit): ?string
    {
        if (!in_array($side, ['a', 'b'], true)) return '参赛方无效';
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可管理出战名单';
        // 参赛方必须是租户且与期望租户一致
        if (self::sideOfParty($b, 'tenant', $expectTenant) !== $side) {
            return '只能管理本方（租户）的出战名单';
        }

        $added = 0;
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) continue;
            $u = Db::name('users')->where('id', $uid)->where('tenant_id', $expectTenant)
                ->where('status', 'approved')->find();
            if (!$u) continue;   // 非本队/未过审用户静默跳过
            $exists = Db::name('battle_signups')->where('battle_id', $battleId)
                ->where('side', $side)->where('user_id', $uid)->find();
            if ($exists) continue;
            if (!self::limitPass($b, $side, $bypassLimit)) return '本方出战人数已达上限（' . (int)$b['roster_limit'] . ' 人）';
            Db::name('battle_signups')->insert([
                'battle_id'    => $battleId,
                'side'         => $side,
                'signup_type'  => 'user',
                'user_id'      => $uid,
                'display_name' => mb_substr((string)($u['nickname'] ?: $u['username']), 0, 32),
                'tenant_id'    => $expectTenant,
                'manual'       => 1,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            $added++;
        }
        return $added > 0 ? null : '没有可添加的成员（需为本队已通过审核且未报名的用户）';
    }

    /**
     * 外部战队上报名单昵称（批量新增，重复跳过）。连接器上报同样走这里。
     * $externalTeamId：上报名单的所属外部战队（需与该方参赛身份一致）。
     */
    public static function addNames(int $battleId, string $side, array $names, int $externalTeamId, bool $bypassLimit): ?string
    {
        if (!in_array($side, ['a', 'b'], true)) return '参赛方无效';
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可管理出战名单';
        if (self::sideOfParty($b, 'external', $externalTeamId) !== $side) {
            return '只能管理本方（外部战队）的出战名单';
        }

        $added = 0;
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '' || mb_strlen($name) > 32) continue;
            $exists = Db::name('battle_signups')->where('battle_id', $battleId)
                ->where('side', $side)->where('display_name', $name)->find();
            if ($exists) continue;
            if (!self::limitPass($b, $side, $bypassLimit)) return '本方出战人数已达上限（' . (int)$b['roster_limit'] . ' 人）';
            Db::name('battle_signups')->insert([
                'battle_id'        => $battleId,
                'side'             => $side,
                'signup_type'      => 'name',
                'display_name'     => $name,
                'external_team_id' => $externalTeamId,
                'manual'           => 1,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            $added++;
        }
        return $added > 0 ? null : '没有可添加的昵称（为空或已在名单中）';
    }

    /** 移除名单条目（按报名记录 id） */
    public static function removeSignup(int $battleId, int $signupId): ?string
    {
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return '约战不存在';
        if ($b['status'] !== 'accepted') return '仅报名中的约战可管理出战名单';
        $n = Db::name('battle_signups')->where('id', $signupId)->where('battle_id', $battleId)->delete();
        return $n > 0 ? null : '名单条目不存在';
    }

    // ==================== 查询与展示 ====================

    /** 约战详情（含双方名单、Ban 图、逐局对局与比分；$viewer 非空时附 my_side） */
    public static function detail(int $battleId, ?array $viewer = null): ?array
    {
        self::expireLazy();
        $b = Db::name('battles')->where('id', $battleId)->find();
        if (!$b) return null;

        [$sa, $sb] = self::roundsScore($battleId);

        // Ban 图：未提交=未提交 / 0=放弃 / >0=地图名
        $banInfo = function ($mapId) {
            if ($mapId === null) return null;
            if ((int)$mapId === 0) return ['passed' => true, 'name' => '放弃禁用'];
            $name = (string)Db::name('rotation_maps')->where('id', (int)$mapId)->value('name');
            return ['passed' => false, 'name' => $name !== '' ? $name : '地图已下架'];
        };

        // 双方名单（只出昵称，不出 QQ）
        $signups = ['a' => [], 'b' => []];
        $rows = Db::name('battle_signups')->where('battle_id', $battleId)
            ->order('id', 'asc')->select()->toArray();
        foreach ($rows as $r) {
            $signups[$r['side']][] = [
                'id'     => (int)$r['id'],
                'name'   => (string)$r['display_name'],
                'manual' => (int)$r['manual'],
            ];
        }

        // 逐局对局
        $rounds = [];
        $roundRows = Db::name('battle_rounds')->where('battle_id', $battleId)
            ->order('round_no', 'asc')->select()->toArray();
        $aName = self::partyName((string)$b['challenger_type'], (int)$b['challenger_id']);
        $bName = self::partyName((string)$b['defender_type'], (int)$b['defender_id']);
        foreach ($roundRows as $r) {
            $att = $r['attacker_side'];
            $rounds[] = [
                'id'            => (int)$r['id'],
                'round_no'      => (int)$r['round_no'],
                'map_id'        => $r['map_id'] !== null ? (int)$r['map_id'] : null,
                'map_name'      => (string)$r['map_name'],
                'attacker_side' => $att ?: null,
                'attacker_name' => $att === 'a' ? $aName : ($att === 'b' ? $bName : ''),
                'defender_name' => $att === 'b' ? $aName : ($att === 'a' ? $bName : ''),
                'result'        => $r['result'] ?: null,
                'evidence'      => $r['evidence'] ?: null,
                'attack_secs'   => $r['attack_secs'] !== null ? (int)$r['attack_secs'] : null,
                'occupy_count'  => $r['occupy_count'] !== null ? (int)$r['occupy_count'] : null,
                'occupy_ratio'  => $r['occupy_ratio'] !== null ? (int)$r['occupy_ratio'] : null,
                'is_decider'    => $r['map_id'] === null && $r['result'] === null,
            ];
        }

        $limit = (int)$b['roster_limit'];
        return [
            'id'            => (int)$b['id'],
            'title'         => (string)$b['title'],
            'game_mode'     => (string)$b['game_mode'],
            'battle_time'   => (string)$b['battle_time'],
            'room_info'     => (string)$b['room_info'],
            'remark'        => (string)$b['remark'],
            'status'        => (string)$b['status'],
            'battle_type'   => (string)($b['battle_type'] ?? 'official'),
            'series_format' => (string)$b['series_format'],
            'roster_limit'  => $limit,
            'adv_side'      => $b['adv_side'] ?: null,
            'adv_reason'    => (string)($b['adv_reason'] ?? ''),
            'adv_role'      => $b['adv_role'] ?: null,
            'challenger'    => ['type' => (string)$b['challenger_type'], 'id' => (int)$b['challenger_id'], 'name' => $aName],
            'defender'      => ['type' => (string)$b['defender_type'], 'id' => (int)$b['defender_id'], 'name' => $bName],
            'ban_a'         => $banInfo($b['ban_a_map_id']),
            'ban_b'         => $banInfo($b['ban_b_map_id']),
            'drawn_at'      => $b['drawn_at'] ? (string)$b['drawn_at'] : null,
            'score_a'       => $sa,
            'score_b'       => $sb,
            'result'        => $b['result'] ?: null,
            'result_note'   => (string)$b['result_note'],
            'finished_at'   => $b['finished_at'] ? (string)$b['finished_at'] : null,
            'created_by'    => (string)$b['created_by'],
            'created_at'    => (string)$b['created_at'],
            'signups_a'     => $signups['a'],
            'signups_b'     => $signups['b'],
            'count_a'       => count($signups['a']),
            'count_b'       => count($signups['b']),
            'ready_a'       => $limit > 0 && count($signups['a']) >= $limit,
            'ready_b'       => $limit > 0 && count($signups['b']) >= $limit,
            'rounds'        => $rounds,
            'my_side'       => $viewer ? self::sideOfParty($b, (string)$viewer[0], (int)$viewer[1]) : null,
        ];
    }

    /** 列表行附 my_side（当前视角是哪一方，前端据此展示报名/应战按钮） */
    public static function withMySide(array $items, string $type, int $id): array
    {
        foreach ($items as &$it) {
            $my = null;
            if ($it['challenger']['type'] === $type && $it['challenger']['id'] === $id) $my = 'a';
            elseif ($it['defender']['type'] === $type && $it['defender']['id'] === $id) $my = 'b';
            $it['my_side'] = $my;
        }
        return $items;
    }

    /** 列表行（附队名与比分摘要） */
    protected static function decorate(array $rows): array
    {
        $out = [];
        foreach ($rows as $b) {
            [$sa, $sb] = self::roundsScore((int)$b['id']);
            $limit = (int)$b['roster_limit'];
            $ca = self::sideCount((int)$b['id'], 'a');
            $cb = self::sideCount((int)$b['id'], 'b');
            $out[] = [
                'id'            => (int)$b['id'],
                'title'         => (string)$b['title'],
                'game_mode'     => (string)$b['game_mode'],
                'battle_time'   => (string)$b['battle_time'],
                'status'        => (string)$b['status'],
                'battle_type'   => (string)($b['battle_type'] ?? 'official'),
                'series_format' => (string)$b['series_format'],
                'challenger'    => ['type' => (string)$b['challenger_type'], 'id' => (int)$b['challenger_id'],
                    'name' => self::partyName((string)$b['challenger_type'], (int)$b['challenger_id'])],
                'defender'      => ['type' => (string)$b['defender_type'], 'id' => (int)$b['defender_id'],
                    'name' => self::partyName((string)$b['defender_type'], (int)$b['defender_id'])],
                'score_a'       => $sa,
                'score_b'       => $sb,
                'result'        => $b['result'] ?: null,
                'count_a'       => $ca,
                'count_b'       => $cb,
                'ready_a'       => $limit > 0 && $ca >= $limit,
                'ready_b'       => $limit > 0 && $cb >= $limit,
                'drawn'         => $b['drawn_at'] !== null,
                'created_at'    => (string)$b['created_at'],
            ];
        }
        return $out;
    }

    /** 某参赛方相关的约战列表（新到旧） */
    public static function listForParty(string $type, int $id): array
    {
        self::expireLazy();
        $rows = Db::name('battles')
            ->where(function ($q) use ($type, $id) {
                $q->where(function ($q2) use ($type, $id) {
                    $q2->where('challenger_type', $type)->where('challenger_id', $id);
                })->whereOr(function ($q2) use ($type, $id) {
                    $q2->where('defender_type', $type)->where('defender_id', $id);
                });
            })
            ->order('id', 'desc')->limit(50)->select()->toArray();
        return self::decorate($rows);
    }

    /** 全平台约战列表（平台超管视角） */
    public static function listAll(): array
    {
        self::expireLazy();
        $rows = Db::name('battles')->order('id', 'desc')->limit(100)->select()->toArray();
        return self::decorate($rows);
    }
}
