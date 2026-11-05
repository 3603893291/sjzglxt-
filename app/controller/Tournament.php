<?php
declare(strict_types=1);

namespace app\controller;

use app\service\TenantContext;
use app\service\TournamentBinding;
use app\service\TournamentClient;
use think\facade\Db;

/**
 * 赛事中心·租户代理（v5.5.0，session 鉴权；v5.5.1 支持多站点绑定）
 *
 * 租户队不登录赛事站门户——经主站代理调赛事站 Open API。
 * 查询端点仅需登录；报名/撤回/名单/小队/上场勾选需管理员 + tournament 权限。
 * 站点来源：site_id 指定某条绑定（平台共享库/租户自增）；未指定则回退历史默认配置。
 */
class Tournament extends AuthBase
{
    /** tournament 权限守卫（真超管经 admin_perms_of 恒持） */
    protected function guard()
    {
        if (!admin_can('tournament')) {
            return api_err('无权限：需要赛事中心权限', 403, 403);
        }
        return null;
    }

    /** 客户端结果转响应 */
    protected function relay(array $r)
    {
        return $r['ok'] ? api_ok($r['data'], $r['msg']) : api_err($r['msg']);
    }

    /** 本租户身份三元组（通道=租户，历史默认站点用） */
    protected function party(): array
    {
        $tid = TenantContext::id();
        $name = (string)(Db::name('tenants')->where('id', $tid)->value('name') ?: '租户#' . $tid);
        return ['type' => 'tenant', 'id' => $tid, 'name' => $name];
    }

    /**
     * 解析前端传入的 site_id 绑定；返回命中站点 + 其身份。
     * 未传 site_id → 回退默认配置，站点参数为空。
     * 传了但不可用 → 返回错误数组（['error'=>msg]）。
     */
    protected function resolveSite(): array
    {
        $tid   = TenantContext::id();
        $sid   = (int)$this->request->param('site_id', 0) ?: (int)$this->request->post('site_id', 0);
        if ($sid <= 0) {
            return ['site' => null, 'party' => $this->party()];
        }
        $site = TournamentBinding::site($sid, $tid);
        if (!$site) {
            return ['error' => '该赛事中心绑定不可用'];
        }
        return ['site' => ['url' => $site['url'], 'key' => $site['key']], 'party' => TournamentBinding::party($site, $tid)];
    }

    /** 客户端结果转响应（带站点/身份） */
    protected function relayS(array $r, array $siteCtx)
    {
        if (isset($siteCtx['error'])) return api_err($siteCtx['error']);
        return $this->relay($r);
    }

    // ---------- 查询（登录即可） ----------

    /** 我可用到的赛事中心绑定列表 + 各站可报名赛事概览 */
    public function bindings()
    {
        $tid = TenantContext::id();
        $list = TournamentBinding::listFor($tid);
        $out = [];
        foreach ($list as $b) {
            $site = ['url' => rtrim((string)$b['base_url'], '/'), 'key' => (string)$b['api_key']];
            $r = TournamentClient::tournaments($site);
            $out[] = [
                'id'          => (int)$b['id'],
                'name'        => (string)$b['name'],
                'source'      => (string)$b['source'],
                'channel'     => (string)$b['channel'],
                'url'         => (string)$b['base_url'],
                'connected'   => $r['ok'],
                'tournaments' => $r['ok'] ? (array)($r['data']['tournaments'] ?? []) : [],
            ];
        }
        return api_ok(['bindings' => $out]);
    }

    /** 租户自增绑定列表（含待确认/已确认，供管理） */
    public function bindingsMine()
    {
        $tid = TenantContext::id();
        $rows = Db::name('tournament_bindings')
            ->where('source', 'tenant')->where('tenant_id', $tid)
            ->order('id', 'desc')->select()->toArray();
        foreach ($rows as &$r) {
            $r['key_set'] = (string)$r['api_key'] !== '';
            $r['api_key'] = ''; // 不出服务端
        }
        return api_ok(['bindings' => $rows]);
    }

    /** 保存绑定（连接状态/接入状态） */
    public function tournaments()
    {
        $cfg = TournamentBinding::site($this->request->param('site_id', 0) ?: 0, TenantContext::id());
        if (!$cfg) {
            $legacy = TournamentClient::config();
            if (!$legacy['enabled']) {
                return api_ok(['enabled' => false, 'tournaments' => []], '未接入赛事中心');
            }
            $r = TournamentClient::tournaments();
            if (!$r['ok']) return api_err($r['msg']);
            $data = is_array($r['data']) ? $r['data'] : [];
            $data['enabled'] = true;
            return api_ok($data);
        }
        $r = TournamentClient::tournaments(['url' => $cfg['url'], 'key' => $cfg['key']]);
        if (!$r['ok']) return api_err($r['msg']);
        $data = is_array($r['data']) ? $r['data'] : [];
        $data['enabled'] = true;
        return api_ok($data);
    }

    /** 赛事公开详情（含对阵树与名次） */
    public function detail($id)
    {
        $c = $this->resolveSite();
        if (isset($c['error'])) return api_err($c['error']);
        return $this->relayS(TournamentClient::detail((int)$id, $c['site']), $c);
    }

    /** 本主体在各赛事的报名状态（仅返回已报名的） */
    public function my()
    {
        $c = $this->resolveSite();
        if (isset($c['error'])) return api_err($c['error']);
        $r = TournamentClient::tournaments($c['site']);
        if (!$r['ok']) return api_ok(['entries' => []], $r['msg']);
        $entries = [];
        foreach ((array)($r['data']['tournaments'] ?? []) as $t) {
            $s = TournamentClient::entryStatus((int)$t['id'], $c['party']['type'], $c['party']['id'], $c['site']);
            if ($s['ok'] && isset($s['data']['entry'])) {
                $e = $s['data']['entry'];
                $e['tournament_name'] = (string)($t['name'] ?? '');
                $entries[] = $e;
            }
        }
        return api_ok(['entries' => $entries]);
    }

    // ---------- 管理员动作（tournament 权限） ----------

    /** 以本主体名义报名（队名=主体名，联系人=当前管理员昵称） */
    public function apply()
    {
        if ($r = $this->guard()) return $r;
        $c = $this->resolveSite();
        if (isset($c['error'])) return api_err($c['error']);
        $tid = (int)$this->request->post('tournament_id', 0);
        if ($tid <= 0) return api_err('请选择赛事');
        $u = current_user();
        $r = TournamentClient::apply([
            'tournament_id' => $tid,
            'entry_type'    => $c['party']['type'],
            'party_id'      => $c['party']['id'],
            'team_name'     => $c['party']['name'],
            'contact_name'  => $u ? (string)($u->nickname ?: $u->username) : '',
            'contact_value' => '',
        ], $c['site']);
        return $this->relayS($r, $c);
    }

    public function withdraw()
    {
        if ($r = $this->guard()) return $r;
        $c = $this->resolveSite();
        if (isset($c['error'])) return api_err($c['error']);
        return $this->relayS(TournamentClient::withdraw(
            (int)$this->request->post('tournament_id', 0), $c['party']['type'], $c['party']['id'], $c['site']
        ), $c);
    }

    /** 名单保存（前端传昵称数组，整表覆盖） */
    public function roster()
    {
        if ($r = $this->guard()) return $r;
        $c = $this->resolveSite();
        if (isset($c['error'])) return api_err($c['error']);
        $items = $this->request->post('roster', []);
        if (!is_array($items)) return api_err('名单格式无效');
        $names = array_values(array_unique(array_filter(array_map(function ($v) {
            return mb_substr(trim((string)$v), 0, 32);
        }, $items))));
        return $this->relayS(TournamentClient::roster(
            (int)$this->request->post('tournament_id', 0), $c['party']['type'], $c['party']['id'], $names, $c['site']
        ), $c);
    }

    /** 小队保存（JSON 透传，结构校验在赛事站） */
    public function squads()
    {
        if ($r = $this->guard()) return $r;
        $c = $this->resolveSite();
        if (isset($c['error'])) return api_err($c['error']);
        $squads = $this->request->post('squads', []);
        if (!is_array($squads)) return api_err('小队格式无效');
        return $this->relayS(TournamentClient::squads(
            (int)$this->request->post('tournament_id', 0), $c['party']['type'], $c['party']['id'], $squads, $c['site']
        ), $c);
    }

    /** 上场小队勾选（manual 赛制） */
    public function matchSquads()
    {
        if ($r = $this->guard()) return $r;
        $c = $this->resolveSite();
        if (isset($c['error'])) return api_err($c['error']);
        $ids = $this->request->post('squad_ids', []);
        if (!is_array($ids)) return api_err('勾选格式无效');
        return $this->relayS(TournamentClient::matchSquads(
            (int)$this->request->post('tournament_id', 0), $c['party']['type'], $c['party']['id'],
            (int)$this->request->post('match_id', 0), array_values(array_map('intval', $ids)), $c['site']
        ), $c);
    }

    // ---------- 租户自增绑定管理（tournament 权限） ----------

    /** 添加外部赛事中心绑定（保存后待确认，不立即启用） */
    public function bindingAdd()
    {
        if ($r = $this->guard()) return $r;
        $rs = TournamentBinding::addTenant($this->request->post(), TenantContext::id());
        if ((int)$rs[0] <= 0) return api_err($rs[1] ?? '添加失败');
        return api_ok(['binding_id' => (int)$rs[0]], '已保存，需确认授权后启用');
    }

    /** 授权确认（勾选「允许数据发往该站点」后启用） */
    public function bindingConfirm()
    {
        if ($r = $this->guard()) return $r;
        $rs = TournamentBinding::confirmTenant(
            (int)$this->request->post('binding_id', 0), TenantContext::id()
        );
        if ((int)$rs[0] <= 0) return api_err($rs[1] ?? '操作失败');
        return api_ok([], $rs[1] ?? '已启用');
    }

    /** 删除租户自增绑定 */
    public function bindingRemove()
    {
        if ($r = $this->guard()) return $r;
        if (!TournamentBinding::removeTenant(
            (int)$this->request->post('binding_id', 0), TenantContext::id()
        )) {
            return api_err('绑定不存在');
        }
        return api_ok([], '已删除');
    }
}
