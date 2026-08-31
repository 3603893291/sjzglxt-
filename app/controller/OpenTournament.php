<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\middleware\ApiKeyAuth;
use app\service\TournamentClient;
use think\facade\Db;

/**
 * 赛事中心·连接器透传（v5.5.0，X-Api-Key 鉴权）
 *
 * 开源单租户站（连接器）凭 external Key 经主站代理报名赛事：
 * 连接器 → 主站（本控制器）→ 赛事站 Open API。
 * 身份固定为 Key 绑定的外部战队（通道=外部），查询端点同样开放。
 */
class OpenTournament extends BaseController
{
    /** 外部战队身份守卫（必须持 external Key） */
    protected function guard()
    {
        if (ApiKeyAuth::$externalTeamId <= 0) {
            return api_err('仅外部战队 Key 可经此通道操作赛事', 403, 403);
        }
        return null;
    }

    /** 客户端结果转响应 */
    protected function relay(array $r)
    {
        return $r['ok'] ? api_ok($r['data'], $r['msg']) : api_err($r['msg']);
    }

    /** 本队身份三元组（通道=外部） */
    protected function party(): array
    {
        $id = ApiKeyAuth::$externalTeamId;
        $name = (string)(Db::name('external_teams')->where('id', $id)->value('name') ?: '外部战队#' . $id);
        return ['type' => 'external', 'id' => $id, 'name' => $name];
    }

    // ---------- 查询 ----------

    public function tournaments()
    {
        $cfg = TournamentClient::config();
        if (!$cfg['enabled']) return api_err('未接入赛事中心');
        return $this->relay(TournamentClient::tournaments());
    }

    public function detail($id)
    {
        $cfg = TournamentClient::config();
        if (!$cfg['enabled']) return api_err('未接入赛事中心');
        return $this->relay(TournamentClient::detail((int)$id));
    }

    /** 本队在各赛事的报名状态（仅返回已报名的） */
    public function my()
    {
        $cfg = TournamentClient::config();
        if (!$cfg['enabled']) return api_err('未接入赛事中心');
        $p = $this->party();
        $r = TournamentClient::tournaments();
        if (!$r['ok']) return api_err($r['msg']);
        $entries = [];
        foreach ((array)($r['data']['tournaments'] ?? []) as $t) {
            $s = TournamentClient::entryStatus((int)$t['id'], $p['type'], $p['id']);
            if ($s['ok'] && isset($s['data']['entry'])) {
                $e = $s['data']['entry'];
                $e['tournament_name'] = (string)($t['name'] ?? '');
                $entries[] = $e;
            }
        }
        return api_ok(['entries' => $entries]);
    }

    // ---------- 动作 ----------

    /** 以本外部战队名义报名（队名=登记名，身份在 Key 签发时已核验） */
    public function apply()
    {
        if ($r = $this->guard()) return $r;
        $tid = (int)$this->request->post('tournament_id', 0);
        if ($tid <= 0) return api_err('请选择赛事');
        $p = $this->party();
        return $this->relay(TournamentClient::apply([
            'tournament_id' => $tid,
            'entry_type'    => $p['type'],
            'party_id'      => $p['id'],
            'team_name'     => $p['name'],
            'contact_name'  => '',
            'contact_value' => '',
        ]));
    }

    public function withdraw()
    {
        if ($r = $this->guard()) return $r;
        $p = $this->party();
        return $this->relay(TournamentClient::withdraw(
            (int)$this->request->post('tournament_id', 0), $p['type'], $p['id']
        ));
    }

    public function roster()
    {
        if ($r = $this->guard()) return $r;
        $items = $this->request->post('roster', []);
        if (!is_array($items)) return api_err('名单格式无效');
        $names = array_values(array_unique(array_filter(array_map(function ($v) {
            return mb_substr(trim((string)$v), 0, 32);
        }, $items))));
        $p = $this->party();
        return $this->relay(TournamentClient::roster(
            (int)$this->request->post('tournament_id', 0), $p['type'], $p['id'], $names
        ));
    }

    public function squads()
    {
        if ($r = $this->guard()) return $r;
        $squads = $this->request->post('squads', []);
        if (!is_array($squads)) return api_err('小队格式无效');
        $p = $this->party();
        return $this->relay(TournamentClient::squads(
            (int)$this->request->post('tournament_id', 0), $p['type'], $p['id'], $squads
        ));
    }

    public function matchSquads()
    {
        if ($r = $this->guard()) return $r;
        $ids = $this->request->post('squad_ids', []);
        if (!is_array($ids)) return api_err('勾选格式无效');
        $p = $this->party();
        return $this->relay(TournamentClient::matchSquads(
            (int)$this->request->post('tournament_id', 0), $p['type'], $p['id'],
            (int)$this->request->post('match_id', 0), array_values(array_map('intval', $ids))
        ));
    }
}
