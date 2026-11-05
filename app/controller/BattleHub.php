<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BattleHubClient;
use app\service\BattleNotice;

/**
 * 约战中心连接器（v5.4.0，session 鉴权）
 *
 * 单租户开源部署的接入端：本站用户凭本站登录态操作，服务端持 external Key
 * 代调 Hub 的 /api/open/battles（Key 不出前端）。未接入时接口统一返回
 * 「约战中心未接入」，前端据此隐藏相关入口。
 */
class BattleHub extends AuthBase
{
    /** 客户端结果转响应 */
    protected function relay(array $r)
    {
        return $r['ok'] ? api_ok($r['data'], $r['msg']) : api_err($r['msg']);
    }

    /** 连接状态探测（未接入不算错误，前端据此隐藏入口） */
    public function status()
    {
        $cfg = BattleHubClient::config();
        return api_ok(['enabled' => $cfg['enabled'], 'url' => $cfg['enabled'] ? $cfg['url'] : '']);
    }

    /** 本站相关约战列表（5 秒本地缓存） */
    public function battles()
    {
        return $this->relay(BattleHubClient::battles());
    }

    /** 约战详情（含各局地图与攻守） */
    public function detail($id)
    {
        return $this->relay(BattleHubClient::detail((int)$id));
    }

    /** 平台地图池（Ban 选择用） */
    public function mapPool()
    {
        return $this->relay(BattleHubClient::mapPool());
    }

    // ---------- 管理员动作（battle 权限） ----------

    protected function adminGuard()
    {
        if (!admin_can('battle')) {
            return api_err('无权限：需要约战管理权限', 403, 403);
        }
        return null;
    }

    public function accept($id)
    {
        if ($r = $this->adminGuard()) return $r;
        $res = BattleHubClient::action((int)$id, 'accept');
        // 本站在主站侧只是「外部战队」，成立公告发不到本站库：回传成功后取详情本地补发一条
        if (!empty($res['ok'])) {
            try {
                $d = BattleHubClient::detail((int)$id);
                if (!empty($d['ok']) && $d['data']) BattleNotice::publishLocal($d['data']);
            } catch (\Throwable $ex) {
                \think\facade\Log::warning('[BattleNotice] ' . $ex->getMessage());
            }
        }
        return $this->relay($res);
    }

    public function reject($id)
    {
        if ($r = $this->adminGuard()) return $r;
        return $this->relay(BattleHubClient::action((int)$id, 'reject'));
    }

    public function cancel($id)
    {
        if ($r = $this->adminGuard()) return $r;
        return $this->relay(BattleHubClient::action((int)$id, 'cancel'));
    }

    /** 提交禁用地图（0=放弃） */
    public function ban($id)
    {
        if ($r = $this->adminGuard()) return $r;
        $mapId = (int)$this->request->post('map_id', 0);
        return $this->relay(BattleHubClient::ban((int)$id, $mapId));
    }

    /** 管理员从本站用户勾选提交出战名单（上报昵称，不报 QQ）/ 移除条目 */
    public function roster($id)
    {
        if ($r = $this->adminGuard()) return $r;
        $removeId = (int)$this->request->post('remove_id', 0);
        if ($removeId > 0) {
            return $this->relay(BattleHubClient::roster((int)$id, ['remove_id' => $removeId]));
        }
        // 勾选的是本站 user_id，服务端翻译成昵称上报（脱敏）
        $userIds = array_values(array_filter(array_map('intval', (array)$this->request->post('add_user_ids', []))));
        if ($userIds === []) return api_err('请选择要出战的本站成员');
        $names = \app\model\User::whereIn('id', $userIds)
            ->where('status', 'approved')->column('nickname,username', 'id');
        $list = [];
        foreach ($userIds as $uid) {
            if (!isset($names[$uid])) continue;   // 非本站/未过审静默跳过（模型自带租户作用域）
            $list[] = mb_substr((string)($names[$uid]['nickname'] ?: $names[$uid]['username']), 0, 32);
        }
        $list = array_values(array_unique(array_filter($list)));
        if ($list === []) return api_err('所选成员均不可出战（需为本队已通过审核用户）');
        return $this->relay(BattleHubClient::signup((int)$id, $list));
    }

    /** 普通用户为自己报名 / 退出（昵称上报） */
    public function signup($id)
    {
        $user = current_user();
        if (!$user) return api_err('未登录', 401, 401);
        if ($user->status !== 'approved') return api_err('账号未通过审核，不能报名');
        $name = mb_substr((string)($user->nickname ?: $user->username), 0, 32);
        $action = (string)$this->request->post('action', 'join') === 'leave' ? 'remove' : 'add';
        $r = BattleHubClient::signup((int)$id, [$name], $action);
        if ($r['ok']) {
            return api_ok([], $action === 'remove' ? '已退出出战名单' : '报名成功，等待开赛');
        }
        return api_err($r['msg']);
    }

    /** 训练赛小局录入（双方管理员自裁决；JSON 透传，截图上传需到 Hub 站内操作） */
    public function roundResult($id, $rid)
    {
        if ($r = $this->adminGuard()) return $r;
        $body = ['result' => (string)$this->request->post('result', '')];
        foreach (['attack_secs', 'occupy_count', 'occupy_ratio'] as $k) {
            $v = $this->request->post($k, '');
            if ($v !== '' && $v !== null) $body[$k] = (int)$v;
        }
        return $this->relay(BattleHubClient::roundResult((int)$id, (int)$rid, $body));
    }

    /** 决胜局选图/选边（优势方管理员操作，choice=pick_map|pick_side） */
    public function decider($id)
    {
        if ($r = $this->adminGuard()) return $r;
        $body = ['choice' => (string)$this->request->post('choice', '')];
        if ($body['choice'] === 'pick_map') {
            $body['map_id'] = (int)$this->request->post('map_id', 0);
        } elseif ($body['choice'] === 'pick_side') {
            $body['side'] = (string)$this->request->post('side', '');
        }
        return $this->relay(BattleHubClient::decider((int)$id, $body));
    }
}
