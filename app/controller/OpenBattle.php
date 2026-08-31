<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\middleware\ApiKeyAuth;
use app\service\BattleService;
use think\facade\Db;

/**
 * 跨战队约战开放 API（v5.4.0，X-Api-Key 鉴权，读写全开）
 *
 * 三种 Key 身份（由 ApiKeyAuth 中间件注入）：
 *   platform → 等同平台超管（可代发起/抽签/录入胜负/强制取消/调配任意名单）
 *   tenant   → 绑定租户管理员身份（发起/应战/禁图/管理本方名单）
 *   external → 外部战队（开源单租户站接入总线：应战/禁图/上报昵称名单）
 *
 * 路由组 /api/open/battles（仅根路径受理，中间件内已守卫）。
 */
class OpenBattle extends BaseController
{
    // 鉴权由路由组统一挂载 ApiKeyAuth；若在控制器属性再挂一次，中间件会执行两遍——
    // 租户 Key 第一遍已把上下文切到租户，第二遍会被平台守卫误杀 404

    // ---------- 身份解析 ----------

    /** 当前 Key 身份：['platform',0] / ['tenant',租户id] / ['external',外部战队id] */
    protected function party(): array
    {
        if (ApiKeyAuth::$platformSuper) return ['platform', 0];
        if (ApiKeyAuth::$externalTeamId > 0) return ['external', ApiKeyAuth::$externalTeamId];
        return ['tenant', (int)\app\service\TenantContext::id()];
    }

    /** 平台侧操作者 id（session 平台超管取其 id；平台 Key 记 0） */
    protected function platformUid(): int
    {
        $u = current_platform_user();
        return $u ? (int)$u->id : 0;
    }

    /** 操作者描述（落库审计） */
    protected function actorDesc(): string
    {
        [$type, $id] = $this->party();
        if ($type === 'platform') return '平台超管(API)';
        if ($type === 'external') return '外部战队#' . $id . '(API)';
        return '租户#' . $id . '(API)';
    }

    /** 读取某约战单（不存在返回 [null, 响应]） */
    protected function findBattle(int $id): array
    {
        $b = Db::name('battles')->where('id', $id)->find();
        if (!$b) {
            return [null, api_err('约战不存在', 404, 404)];
        }
        return [$b, null];
    }

    /** 是否站点管理员（租户 Key 绑定用户须持 battle 权限；平台 Key 恒真） */
    protected function isAdmin(): bool
    {
        [$type] = $this->party();
        if ($type === 'platform') return true;
        if ($type === 'external') return true;  // external Key 即该外部战队的管理通道
        return admin_can('battle');
    }

    // ---------- 查询 ----------

    /** 本队相关约战列表（platform 键看全部；非平台键附 my_side） */
    public function index()
    {
        [$type, $id] = $this->party();
        if ($type === 'platform') {
            return api_ok(BattleService::listAll());
        }
        return api_ok(BattleService::withMySide(BattleService::listForParty($type, $id), $type, $id));
    }

    /** 约战详情（含双方名单、Ban 图、逐局对局）——仅限本队相关或平台键 */
    public function detail($id)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        if ($type !== 'platform' && BattleService::sideOfParty($b, $type, $pid) === null) {
            return api_err('无权查看与己无关的约战', 403, 403);
        }
        return api_ok(BattleService::detail((int)$id, $type === 'platform' ? null : [$type, $pid]));
    }

    /** 平台地图池（Ban 选择与抽签预览用） */
    public function mapPool()
    {
        return api_ok(BattleService::mapPool());
    }

    // ---------- 发起 / 应战 / 取消 ----------

    /** 发起约战（仅 tenant / platform 键；外部战队不能主动发起） */
    public function create()
    {
        [$type, $id] = $this->party();
        if ($type === 'external') {
            return api_err('外部战队不能发起约战，只能应战', 403, 403);
        }
        if (!$this->isAdmin()) {
            return api_err('需要约战管理权限', 403, 403);
        }
        $p = $this->request->post();
        // 发起方 = 本队；平台键代发须显式指定 challenger_type/challenger_id
        if ($type === 'tenant') {
            $p['challenger_type'] = 'tenant';
            $p['challenger_id']   = $id;
        } else {
            $p['challenger_type'] = (string)($p['challenger_type'] ?? 'tenant');
            $p['challenger_id']   = (int)($p['challenger_id'] ?? 0);
        }
        [$bid, $err] = BattleService::create($p, $this->actorDesc());
        if ($err) return api_err($err);
        return api_ok(['id' => $bid], '约战已发起，等待对方接受');
    }

    /** 应战（仅 b 方管理员或平台键） */
    public function accept($id)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        if ($type !== 'platform') {
            if (!$this->isAdmin()) return api_err('需要约战管理权限', 403, 403);
            if (BattleService::sideOfParty($b, $type, $pid) !== 'b') {
                return api_err('只有被约战一方的管理员可以接受', 403, 403);
            }
        }
        if ($e = BattleService::accept((int)$id, $this->actorDesc())) return api_err($e);
        return api_ok([], '已接受约战，进入报名阶段');
    }

    /** 拒绝（仅 b 方管理员或平台键） */
    public function reject($id)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        if ($type !== 'platform') {
            if (!$this->isAdmin()) return api_err('需要约战管理权限', 403, 403);
            if (BattleService::sideOfParty($b, $type, $pid) !== 'b') {
                return api_err('只有被约战一方的管理员可以拒绝', 403, 403);
            }
        }
        if ($e = BattleService::reject((int)$id, $this->actorDesc())) return api_err($e);
        return api_ok([], '已拒绝该约战');
    }

    /**
     * 取消：a 方可取消 pending/accepted；b 方可取消 accepted；平台键强制取消任意阶段。
     */
    public function cancel($id)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        if ($type !== 'platform') {
            if (!$this->isAdmin()) return api_err('需要约战管理权限', 403, 403);
            $side = BattleService::sideOfParty($b, $type, $pid);
            if ($side === null) return api_err('只有对阵双方或平台超管可以取消', 403, 403);
            if ($side === 'b' && $b['status'] !== 'accepted') {
                return api_err('待接受的约战请由发起方取消', 403, 403);
            }
        }
        if ($e = BattleService::cancel((int)$id, $this->actorDesc())) return api_err($e);
        return api_ok([], '约战已取消');
    }

    // ---------- Ban / 抽签 / 胜负 ----------

    /** 提交本方禁用地图（0=放弃；每方一次，仅 accepted 未抽签） */
    public function ban($id)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        $mapId = (int)$this->request->post('map_id', 0);
        if ($type === 'platform') {
            // 平台键代提交须指明 side
            $side = (string)$this->request->post('side', '');
            if (!in_array($side, ['a', 'b'], true)) return api_err('平台键代提交禁图须指定 side(a/b)');
        } else {
            if (!$this->isAdmin()) return api_err('需要约战管理权限', 403, 403);
            $side = BattleService::sideOfParty($b, $type, $pid);
            if ($side === null) return api_err('只有对阵双方可以提交禁用地图', 403, 403);
        }
        if ($e = BattleService::ban((int)$id, $side, $mapId)) return api_err($e);
        return api_ok([], $mapId === 0 ? '已放弃禁用权' : '禁用地图已提交');
    }

    /** 抽签（仅 platform 键） */
    public function draw($id)
    {
        if (!ApiKeyAuth::$platformSuper) {
            return api_err('抽签仅平台超管（platform 键）可执行', 403, 403);
        }
        if ($e = BattleService::draw((int)$id, $this->platformUid())) return api_err($e);
        return api_ok(BattleService::detail((int)$id)['rounds'] ?? [], '抽签完成，各局地图与攻守已生成');
    }

    /**
     * 录入小局胜负（result=a_win|b_win；可附 attack_secs/occupy_count/occupy_ratio 统计）。
     * platform 键任意赛制；tenant/external 键仅限训练赛且本队为对阵方（双方管理自裁决）。
     */
    public function roundResult($id, $rid)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        $actorId = 0;
        if (!ApiKeyAuth::$platformSuper) {
            if (($b['battle_type'] ?? 'official') !== 'training') {
                return api_err('正式赛事的小局胜负仅 platform 键（赛事组）可录入', 403, 403);
            }
            if (BattleService::sideOfParty($b, $type, $pid) === null) {
                return api_err('只有对阵双方可以录入', 403, 403);
            }
            $actorId = ApiKeyAuth::$apiUserId;
        } else {
            $actorId = $this->platformUid();
        }
        $result = (string)$this->request->post('result', '');
        $extra = [];
        foreach (['attack_secs', 'occupy_count', 'occupy_ratio', 'evidence'] as $k) {
            $v = $this->request->post($k, '');
            if ($v !== '' && $v !== null) $extra[$k] = $v;
        }
        if ($e = BattleService::recordRound((int)$id, (int)$rid, $result, $actorId, $extra)) {
            return api_err($e);
        }
        $d = BattleService::detail((int)$id);
        return api_ok([
            'score_a' => $d['score_a'], 'score_b' => $d['score_b'],
            'status'  => $d['status'],  'result'  => $d['result'],
            'adv_side' => $d['adv_side'], 'adv_reason' => $d['adv_reason'],
        ], $d['status'] === 'finished' ? '比赛已结束' : '胜负已录入');
    }

    /** 手动指定决胜局优势方（仅 platform 键；正式赛赛事组裁决入口，本版本由平台键代管） */
    public function advantage($id)
    {
        if (!ApiKeyAuth::$platformSuper) {
            return api_err('指定优势方仅 platform 键可执行', 403, 403);
        }
        $side = (string)$this->request->post('side', '');
        if ($e = BattleService::setAdvantage((int)$id, $side)) return api_err($e);
        return api_ok([], '优势方已指定，等待其选图/选边');
    }

    /**
     * 决胜局选图/选边（优势方二选一）：
     * tenant/external 键：仅本队为优势方可操作，choice=pick_map 传 map_id / pick_side 传 side=attack|defend；
     * platform 键：代任意优势方，pick_side 直接传 attacker_side(a/b)。
     */
    public function decider($id)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        $choice = (string)$this->request->post('choice', '');
        $attackerSide = '';
        if ($type === 'platform') {
            $attackerSide = (string)$this->request->post('attacker_side', '');
        } else {
            if (!$this->isAdmin()) return api_err('需要约战管理权限', 403, 403);
            $mySide = BattleService::sideOfParty($b, $type, $pid);
            if ($mySide === null) return api_err('只有对阵双方可操作决胜局', 403, 403);
            if ($b['adv_side'] !== null && $b['adv_side'] !== $mySide) {
                return api_err('本局决胜优先权在对方', 403, 403);
            }
            if ($choice === 'pick_side') {
                $want = (string)$this->request->post('side', '');
                if (!in_array($want, ['attack', 'defend'], true)) return api_err('选边请指定进攻或防守');
                $attackerSide = ($want === 'attack') ? $mySide : ($mySide === 'a' ? 'b' : 'a');
            }
        }
        $e = BattleService::deciderPick((int)$id, $choice, (int)$this->request->post('map_id', 0), $attackerSide, [$type, $pid]);
        if ($e) return api_err($e);
        return api_ok(BattleService::detail((int)$id)['rounds'] ?? [], '决胜局已确定');
    }

    // ---------- 出战名单 ----------

    /**
     * 名单操作（租户键管理本方用户名单；外部键上报昵称名单；平台键调配任意方）。
     * POST body: add_names[] | add_user_ids[] | remove_ids[]
     */
    public function roster($id)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        $names    = array_values(array_filter(array_map('trim', (array)$this->request->post('add_names', []))));
        $userIds  = array_values(array_filter(array_map('intval', (array)$this->request->post('add_user_ids', []))));
        $removeId = (int)$this->request->post('remove_id', 0);
        $bypass   = false;

        if ($type === 'platform') {
            // 平台键调配任意方：须指定 side；超管添加不受上限约束
            $side = (string)$this->request->post('side', '');
            if (!in_array($side, ['a', 'b'], true)) return api_err('平台键调配名单须指定 side(a/b)');
            $bypass = true;
        } else {
            if (!$this->isAdmin()) return api_err('需要约战管理权限', 403, 403);
            $side = BattleService::sideOfParty($b, $type, $pid);
            if ($side === null) return api_err('只能管理本方的出战名单', 403, 403);
        }

        if ($removeId > 0) {
            // 非平台键只能移除本方的名单条目
            if ($type !== 'platform') {
                $row = Db::name('battle_signups')->where('id', $removeId)->where('battle_id', (int)$id)->find();
                if (!$row || $row['side'] !== $side) return api_err('只能移除本方的名单条目', 403, 403);
            }
            if ($e = BattleService::removeSignup((int)$id, $removeId)) return api_err($e);
            return api_ok([], '已从名单移除');
        }
        if ($type === 'external') {
            if ($names === []) return api_err('请提供上报名单昵称（add_names）');
            if ($e = BattleService::addNames((int)$id, $side, $names, $pid, $bypass)) return api_err($e);
        } elseif ($type === 'tenant') {
            if ($userIds === []) return api_err('请提供要添加的本队成员（add_user_ids）');
            if ($e = BattleService::addUsers((int)$id, $side, $userIds, $pid, $bypass)) return api_err($e);
        } else {
            // 平台键：用户按所属租户入名单，昵称按指定 side 入名单
            if ($userIds !== []) {
                $tid = (int)$this->request->post('tenant_id', 0);
                if ($tid <= 0) return api_err('平台键添加用户名单须指定 tenant_id');
                if ($e = BattleService::addUsers((int)$id, $side, $userIds, $tid, $bypass)) return api_err($e);
            }
            if ($names !== []) {
                $extId = (int)$this->request->post('external_team_id', 0);
                if ($extId <= 0) return api_err('平台键上报昵称名单须指定 external_team_id');
                if ($e = BattleService::addNames((int)$id, $side, $names, $extId, $bypass)) return api_err($e);
            }
            if ($userIds === [] && $names === []) return api_err('请提供要添加的名单内容');
        }
        return api_ok([], '出战名单已更新');
    }

    /** 外部战队昵称名单上报/撤回（action=add|remove；等价于 roster 的名单操作，语义更直白） */
    public function signup($id)
    {
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        [$type, $pid] = $this->party();
        if ($type !== 'external') {
            return api_err('该接口仅供外部战队（external 键）上报名单', 403, 403);
        }
        $side = BattleService::sideOfParty($b, $type, $pid);
        if ($side === null) return api_err('只能为与己相关的约战上报名单', 403, 403);
        $names = array_values(array_filter(array_map('trim', (array)$this->request->post('names', []))));
        if ($names === []) return api_err('请提供名单昵称（names）');

        $action = (string)$this->request->post('action', 'add');
        if ($action === 'remove') {
            $n = Db::name('battle_signups')->where('battle_id', (int)$id)
                ->where('side', $side)->whereIn('display_name', $names)->delete();
            return $n > 0 ? api_ok([], '已撤回 ' . $n . ' 条名单') : api_err('名单中无对应昵称');
        }
        if ($e = BattleService::addNames((int)$id, $side, $names, $pid, false)) return api_err($e);
        return api_ok([], '名单已上报');
    }
}
