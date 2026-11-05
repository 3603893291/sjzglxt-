<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BattleNotice;
use app\service\BattleService;
use app\service\Setting;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 跨战队约战·管理中心入口（v5.4.0，AdminCheck + battle 权限）
 *
 * 战队管理员视角：发起约战（对方可为平台租户或外部战队）、接受/拒绝、
 * 提交禁用地图、管理本方出战名单、取消；另含本队 API 密钥（仅超管）
 * 与约战中心连接配置（仅超管，连接器模式）。
 */
class BattleAdmin extends AuthBase
{
    protected $middleware = [\app\middleware\AdminCheck::class];

    /** battle 权限守卫（真超管经 admin_perms_of 恒持） */
    protected function guard()
    {
        if (!admin_can('battle')) {
            return api_err('无权限：需要约战管理权限', 403, 403);
        }
        return null;
    }

    protected function tenantId(): int
    {
        return TenantContext::id();
    }

    protected function actorDesc(): string
    {
        $u = current_user();
        return '租户#' . $this->tenantId() . '(' . ($u ? ($u->nickname ?: $u->username) : '?') . ')';
    }

    // ---------- 列表 / 发起 / 应战 ----------

    /** 本队相关约战列表 + 候选对方（发起表单下拉用） */
    public function index()
    {
        if ($r = $this->guard()) return $r;
        $tid = $this->tenantId();
        $parties = [];
        foreach (Db::name('tenants')->where('status', 'approved')->where('id', '<>', $tid)
            ->order('id', 'asc')->field('id, name')->select()->toArray() as $t) {
            $parties[] = ['type' => 'tenant', 'id' => (int)$t['id'], 'name' => (string)$t['name']];
        }
        foreach (Db::name('external_teams')->where('status', 1)
            ->order('id', 'asc')->field('id, name')->select()->toArray() as $t) {
            $parties[] = ['type' => 'external', 'id' => (int)$t['id'], 'name' => (string)$t['name']];
        }
        return api_ok([
            'list'    => BattleService::withMySide(BattleService::listForParty('tenant', $tid), 'tenant', $tid),
            'parties' => $parties,
        ]);
    }

    /**
     * 待本队同意的约战（v5.13.0：管理员进站的强制弹窗数据源）
     * 只取「本队是被约战方(b) 且仍 pending」的单子；不建表也不记已读——
     * 弹窗由约战真实状态驱动，管理员同意或拒绝后自然消失，普通成员看不到。
     */
    public function pending()
    {
        if ($r = $this->guard()) return $r;
        BattleService::expireLazy();
        $list = [];
        $rows = Db::name('battles')->where('status', 'pending')
            ->where('defender_type', 'tenant')->where('defender_id', $this->tenantId())
            ->order('id', 'desc')->limit(10)->select()->toArray();
        foreach ($rows as $b) {
            $list[] = [
                'id'            => (int)$b['id'],
                'title'         => (string)$b['title'],
                'game_mode'     => (string)$b['game_mode'],
                'battle_time'   => (string)$b['battle_time'],
                'series_format' => (string)$b['series_format'],
                'battle_type'   => (string)($b['battle_type'] ?? 'official'),
                'roster_limit'  => (int)$b['roster_limit'],
                'challenger'    => BattleService::partyName((string)$b['challenger_type'], (int)$b['challenger_id']),
                'created_at'    => (string)$b['created_at'],
            ];
        }
        return api_ok(['list' => $list]);
    }

    /** 本队「约战成立自动公告」开关状态（默认开） */
    public function noticeConfig()
    {
        if ($r = $this->guard()) return $r;
        return api_ok(['enabled' => BattleNotice::enabledFor($this->tenantId())]);
    }

    /** 保存开关（关闭后成立不再自动发公告，管理员仍可自行发公告） */
    public function noticeSave()
    {
        if ($r = $this->guard()) return $r;
        $on = $this->request->post('enabled') ? 1 : 0;
        Setting::set(BattleNotice::SWITCH_KEY, $on ? '1' : '0');
        admin_log('约战管理', $on ? '开启约战成立自动公告' : '关闭约战成立自动公告');
        return api_ok(['enabled' => (bool)$on],
            $on ? '约战成立后将自动向本队发公告' : '已关闭约战成立自动公告');
    }

    /** 发起约战（对方：平台租户或外部战队） */
    public function create()
    {
        if ($r = $this->guard()) return $r;
        $p = $this->request->post();
        $p['challenger_type'] = 'tenant';
        $p['challenger_id']   = $this->tenantId();
        [$id, $err] = BattleService::create($p, $this->actorDesc());
        if ($err) return api_err($err);
        return api_ok(['id' => $id], '约战已发起，等待对方接受');
    }

    /** 读取约战单（不存在返回 [null, 响应]） */
    protected function findBattle(int $id): array
    {
        $b = Db::name('battles')->where('id', $id)->find();
        if (!$b) {
            return [null, api_err('约战不存在', 404, 404)];
        }
        return [$b, null];
    }

    /** 接受约战（仅本队为 b 方） */
    public function accept($id)
    {
        if ($r = $this->guard()) return $r;
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        if (BattleService::sideOfParty($b, 'tenant', $this->tenantId()) !== 'b') {
            return api_err('只有被约战一方的管理员可以接受', 403, 403);
        }
        if ($e = BattleService::accept((int)$id, $this->actorDesc())) return api_err($e);
        return api_ok([], '已接受约战，进入报名阶段');
    }

    /** 拒绝约战（仅本队为 b 方） */
    public function reject($id)
    {
        if ($r = $this->guard()) return $r;
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        if (BattleService::sideOfParty($b, 'tenant', $this->tenantId()) !== 'b') {
            return api_err('只有被约战一方的管理员可以拒绝', 403, 403);
        }
        if ($e = BattleService::reject((int)$id, $this->actorDesc())) return api_err($e);
        return api_ok([], '已拒绝该约战');
    }

    /** 取消：a 方可取消 pending/accepted；b 方仅可取消 accepted */
    public function cancel($id)
    {
        if ($r = $this->guard()) return $r;
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        $side = BattleService::sideOfParty($b, 'tenant', $this->tenantId());
        if ($side === null) return api_err('只有对阵双方可以取消', 403, 403);
        if ($side === 'b' && $b['status'] !== 'accepted') {
            return api_err('待接受的约战请由发起方取消', 403, 403);
        }
        if ($e = BattleService::cancel((int)$id, $this->actorDesc())) return api_err($e);
        return api_ok([], '约战已取消');
    }

    /**
     * 删除约战（v5.6.1，仅管理员）
     * 可删范围：已过期 / 已完赛 / 已取消 / 被拒绝，或「进行中但已有录分（部分已完赛）」；
     * 待接受与尚未录分的进行中约战请先走取消。连同报名与小局记录一并删除。
     */
    public function remove($id)
    {
        if ($r = $this->guard()) return $r;
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        if (BattleService::sideOfParty($b, 'tenant', $this->tenantId()) === null) {
            return api_err('本站不是该约战的参赛方，不能删除', 403, 403);
        }
        $dead = ['expired', 'finished', 'canceled', 'rejected'];
        $recorded = (int)Db::name('battle_rounds')->where('battle_id', $b['id'])->whereNotNull('result')->count();
        if (!in_array($b['status'], $dead, true) && !($b['status'] === 'accepted' && $recorded > 0)) {
            return api_err('仅已过期或已完赛（含已录分）的约战可删除，进行中请先取消');
        }
        Db::name('battle_signups')->where('battle_id', $b['id'])->delete();
        Db::name('battle_rounds')->where('battle_id', $b['id'])->delete();
        Db::name('battles')->where('id', $b['id'])->delete();
        admin_log('约战管理', '删除约战 #' . $b['id'] . ' ' . ($b['title'] ?: '') . '（状态 ' . $b['status'] . '）');
        return api_ok([], '约战已删除');
    }

    // ---------- Ban / 名单 ----------

    /** 提交本方禁用地图（0=放弃，每方一次） */
    public function ban($id)
    {
        if ($r = $this->guard()) return $r;
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        $side = BattleService::sideOfParty($b, 'tenant', $this->tenantId());
        if ($side === null) return api_err('只有对阵双方可以提交禁用地图', 403, 403);
        $mapId = (int)$this->request->post('map_id', 0);
        if ($e = BattleService::ban((int)$id, $side, $mapId)) return api_err($e);
        return api_ok([], $mapId === 0 ? '已放弃禁用权' : '禁用地图已提交');
    }

    /** 管理本方出战名单（添加本队成员 add_user_ids[] / 移除 remove_id） */
    public function roster($id)
    {
        if ($r = $this->guard()) return $r;
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        $side = BattleService::sideOfParty($b, 'tenant', $this->tenantId());
        if ($side === null) return api_err('只能管理本方的出战名单', 403, 403);

        $removeId = (int)$this->request->post('remove_id', 0);
        if ($removeId > 0) {
            // v5.15.0(S-H1)：removeSignup 只按 (id, battle_id) 删，而 detail() 把双方条目
            // 的 id 一并回给参赛方 —— 少这一层 side 校验，发起方就能拿对方条目 id 精准删人。
            // 开放接口侧 OpenBattle::roster 早有同样的判断，session 通道此前漏了。
            $row = Db::name('battle_signups')->where('id', $removeId)->where('battle_id', (int)$id)->find();
            if (!$row || $row['side'] !== $side) return api_err('只能移除本方的名单条目', 403, 403);
            if ($e = BattleService::removeSignup((int)$id, $removeId)) return api_err($e);
            return api_ok([], '已从名单移除');
        }
        $userIds = array_values(array_filter(array_map('intval', (array)$this->request->post('add_user_ids', []))));
        if ($userIds === []) return api_err('请选择要添加的本队成员');
        if ($e = BattleService::addUsers((int)$id, $side, $userIds, $this->tenantId(), false)) return api_err($e);
        return api_ok([], '出战名单已更新');
    }

    // ---------- 训练赛小局录入（双方管理员自裁决，可附游戏截图） ----------

    /** 训练赛录入小局胜负（正式赛事仍由平台超管录入；可上传结果截图） */
    public function roundResult($id, $rid)
    {
        if ($r = $this->guard()) return $r;
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        if (($b['battle_type'] ?? 'official') !== 'training') {
            return api_err('正式赛事的小局胜负由平台超管（赛事组）录入', 403, 403);
        }
        if (BattleService::sideOfParty($b, 'tenant', $this->tenantId()) === null) {
            return api_err('只有对阵双方的管理员可以录入', 403, 403);
        }
        $result = (string)$this->request->post('result', '');
        $extra = [];
        foreach (['attack_secs', 'occupy_count', 'occupy_ratio'] as $k) {
            $v = $this->request->post($k, '');
            if ($v !== '' && $v !== null) $extra[$k] = (int)$v;
        }
        // 结果截图（可选，multipart 字段 file）
        $upFiles = $this->request->file();
        if (!empty($upFiles['file'])) {
            [$path, $upErr] = \app\service\ImageUpload::save($upFiles['file'], 'battles');
            if ($upErr !== null) return api_err($upErr);
            $extra['evidence'] = $path;
        }
        if ($e = BattleService::recordRound((int)$id, (int)$rid, $result, (int)current_user()->id, $extra)) {
            return api_err($e);
        }
        $d = BattleService::detail((int)$id);
        return api_ok([
            'score_a' => $d['score_a'], 'score_b' => $d['score_b'],
            'status'  => $d['status'],  'result'  => $d['result'],
        ], $d['status'] === 'finished' ? '比赛已结束' : '胜负已录入');
    }

    /** 决胜局选图/选边（仅优势方管理员；choice=pick_map 传 map_id，pick_side 传 side=attack|defend） */
    public function decider($id)
    {
        if ($r = $this->guard()) return $r;
        [$b, $err] = $this->findBattle((int)$id);
        if ($err) return $err;
        $mySide = BattleService::sideOfParty($b, 'tenant', $this->tenantId());
        if ($mySide === null) return api_err('只有对阵双方可操作决胜局', 403, 403);
        if ($b['adv_side'] !== null && $b['adv_side'] !== $mySide) {
            return api_err('本局决胜优先权在对方，请等待对方选图/选边', 403, 403);
        }
        $choice = (string)$this->request->post('choice', '');
        $attackerSide = '';
        if ($choice === 'pick_side') {
            // 选边=选进攻还是防守，折算成攻方 a/b（attacker_side 指攻方）
            $want = (string)$this->request->post('side', '');
            if (!in_array($want, ['attack', 'defend'], true)) return api_err('选边请指定进攻或防守');
            $attackerSide = ($want === 'attack') ? $mySide : ($mySide === 'a' ? 'b' : 'a');
        }
        $e = BattleService::deciderPick((int)$id, $choice, (int)$this->request->post('map_id', 0), $attackerSide, ['tenant', $this->tenantId()]);
        if ($e) return api_err($e);
        return api_ok(BattleService::detail((int)$id)['rounds'] ?? [], '决胜局已确定');
    }

    // ---------- API 密钥（仅超管：本队 tenant 键） ----------

    /** 本队密钥列表（不出明文，仅前缀辨认） */
    public function apiKeys()
    {
        if (!is_super_admin()) return api_err('仅超管可管理 API 密钥', 403, 403);
        $rows = Db::name('api_keys')->where('scope', 'tenant')->where('tenant_id', $this->tenantId())
            ->order('id', 'desc')->select()->toArray();
        foreach ($rows as &$r) {
            unset($r['key_hash']);
            $bu = $r['bound_user_id'] ? Db::name('users')->where('id', (int)$r['bound_user_id'])
                ->field('id, username, nickname')->find() : null;
            $r['bound_user'] = $bu ? (string)($bu['nickname'] ?: $bu['username']) : '已删除';
        }
        return api_ok($rows);
    }

    /** 生成本队密钥（绑定本队任一管理员身份；明文仅此次返回） */
    public function apiKeyCreate()
    {
        if (!is_super_admin()) return api_err('仅超管可管理 API 密钥', 403, 403);
        $uid = (int)$this->request->post('bind_user_id', 0);
        if ($uid <= 0) {
            $uid = (int)current_user()->id;
        }
        $u = Db::name('users')->where('id', $uid)->where('tenant_id', $this->tenantId())
            ->where('role', 'admin')->where('status', 'approved')->find();
        if (!$u) return api_err('绑定身份必须是本队在职管理员');

        [$plain, $hash, $prefix] = battle_api_make_key();
        $id = Db::name('api_keys')->insertGetId([
            'key_hash'      => $hash,
            'key_prefix'    => $prefix,
            'name'          => mb_substr(trim((string)$this->request->post('name', '')), 0, 50) ?: '本队密钥',
            'scope'         => 'tenant',
            'tenant_id'     => $this->tenantId(),
            'bound_user_id' => $uid,
            'status'        => 1,
            'created_by'    => (int)current_user()->id,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        return api_ok(['id' => $id, 'key' => $plain], '密钥已生成，请立即保存，之后不再显示');
    }

    /** 停用/启用本队密钥 */
    public function apiKeyStatus($id)
    {
        if (!is_super_admin()) return api_err('仅超管可管理 API 密钥', 403, 403);
        $row = Db::name('api_keys')->where('id', (int)$id)->where('scope', 'tenant')
            ->where('tenant_id', $this->tenantId())->find();
        if (!$row) return api_err('密钥不存在', 404, 404);
        Db::name('api_keys')->where('id', (int)$id)->update(['status' => (int)$row['status'] === 1 ? 0 : 1]);
        return api_ok([], '密钥状态已更新');
    }

    /** 删除本队密钥 */
    public function apiKeyRemove($id)
    {
        if (!is_super_admin()) return api_err('仅超管可管理 API 密钥', 403, 403);
        $n = Db::name('api_keys')->where('id', (int)$id)->where('scope', 'tenant')
            ->where('tenant_id', $this->tenantId())->delete();
        return $n > 0 ? api_ok([], '密钥已删除') : api_err('密钥不存在', 404, 404);
    }

    // ---------- 约战中心连接配置（仅超管，连接器模式） ----------

    /** 读取连接配置（Key 打码回显） */
    public function hubConfig()
    {
        if (!is_super_admin()) return api_err('仅超管可查看约战中心配置', 403, 403);
        $key = Setting::get('battle_hub_key');
        return api_ok([
            'enabled' => Setting::get('battle_hub_enabled') === '1',
            'url'     => Setting::get('battle_hub_url'),
            'key_set' => $key !== '',
            'key_masked' => $key !== '' ? substr($key, 0, 8) . str_repeat('*', 16) : '',
        ]);
    }

    /** 保存连接配置（key 留空=保持原值） */
    public function hubConfigSave()
    {
        if (!is_super_admin()) return api_err('仅超管可配置约战中心', 403, 403);
        $enabled = $this->request->post('enabled') ? '1' : '0';
        $url = rtrim(trim((string)$this->request->post('url', '')), '/');
        $key = trim((string)$this->request->post('key', ''));
        if ($enabled === '1' && ($url === '' || !preg_match('#^https?://#i', $url))) {
            return api_err('请填写约战中心地址（http/https 开头）');
        }
        // v5.15.0(S-M6)：这个地址随后由本站服务器直接发起请求，只校验前缀等于
        // 给了站长一个探测宿主内网的跳板（127.0.0.1:6379 / 10.x / 169.254.x）
        if ($enabled === '1') {
            $bad = safe_outbound_url($url);
            if ($bad !== null) return api_err('约战中心地址不可用：' . $bad);
        }
        Setting::set('battle_hub_enabled', $enabled);
        Setting::set('battle_hub_url', $url);
        if ($key !== '') {
            Setting::set('battle_hub_key', $key);
        }
        return api_ok([], '约战中心配置已保存');
    }
}
