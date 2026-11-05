<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\User;
use app\service\GameSide;
use app\service\Setting;
use think\facade\Db;

/**
 * 端手分离（v5.36.0）
 *   - 总开关与八块模块开关、按端默认锁：只有队长（真超管）能改。
 *   - 成员端别分类与按人例外：放在用户管理里，持「查看成员名单」权限即可。
 */
class GameSideAdmin extends BaseController
{
    // GET /api/admin/game-side
    public function index()
    {
        if (!$this->isSuper()) {
            return api_err('只有队长本人能改端手游分离', 403, 403);
        }
        $counts = Db::name('users')->where('tenant_id', \app\service\TenantContext::id())
            ->where('status', 'approved')
            ->field("SUM(CASE WHEN game_side='pc' THEN 1 ELSE 0 END) AS pc,
                     SUM(CASE WHEN game_side='mobile' THEN 1 ELSE 0 END) AS mobile,
                     SUM(CASE WHEN game_side='' OR game_side IS NULL THEN 1 ELSE 0 END) AS none")
            ->find() ?: [];
        return api_ok([
            'on'      => GameSide::on(),
            'modules' => GameSide::moduleMap(),
            'labels'  => GameSide::moduleDefs(),
            'locks'   => GameSide::locks(),
            'counts'  => [
                'pc'     => (int)($counts['pc'] ?? 0),
                'mobile' => (int)($counts['mobile'] ?? 0),
                'none'   => (int)($counts['none'] ?? 0),
            ],
            'note'    => '端手分离默认关闭。每位成员必须选端游或手游（没有「不分类」），还有人没选时打不开总开关。打开后可以按块选择分端；内容标「全队」则两个专区都能看到。',
        ]);
    }

    // POST /api/admin/game-side/save
    public function save()
    {
        if (!$this->isSuper()) {
            return api_err('只有队长本人能改端手游分离', 403, 403);
        }
        $on = $this->request->post('on', null);
        $modules = $this->request->post('modules', null);
        $lockPc = $this->request->post('lock_pc', null);
        $lockMobile = $this->request->post('lock_mobile', null);

        if ($on !== null) {
            $turnOn = (string)$on === '1';
            // v5.36.0：成员必须落在端游或手游，没有「不分类」这一档；
            // 还有人没分端时不给开，否则这些人会同时漂在两个专区里
            if ($turnOn) {
                $left = (int)Db::name('users')
                    ->where('tenant_id', \app\service\TenantContext::id())
                    ->where('status', 'approved')
                    ->whereNotIn('game_side', [GameSide::PC, GameSide::MOBILE])
                    ->count();
                if ($left > 0) {
                    return api_err('还有 ' . $left . ' 位成员没选端游/手游，请先到「成员管理 → 端别」给他们分好端，再回来打开', 400);
                }
            }
            Setting::set('side_split_on', $turnOn ? '1' : '0');
        }
        if ($modules !== null) {
            if (!is_array($modules)) {
                return api_err('模块开关格式不正确');
            }
            $defs = array_keys(GameSide::moduleDefs());
            $clean = [];
            foreach ($defs as $k) {
                $clean[$k] = array_key_exists($k, $modules) && (string)$modules[$k] === '1' ? '1' : '0';
            }
            Setting::set('side_split_modules', json_encode($clean, JSON_UNESCAPED_UNICODE));
        }
        if ($lockPc !== null) {
            Setting::set('side_lock_pc', (string)$lockPc === '1' ? '1' : '0');
        }
        if ($lockMobile !== null) {
            Setting::set('side_lock_mobile', (string)$lockMobile === '1' ? '1' : '0');
        }
        return api_ok([
            'on'      => GameSide::on(),
            'modules' => GameSide::moduleMap(),
            'locks'   => GameSide::locks(),
        ], '已保存');
    }

    // POST /api/admin/users/:id/game-side  { game_side: pc|mobile|'', lock: ''|'0'|'1' }
    public function saveUser($id)
    {
        if (!admin_can('member_view')) {
            return api_err('没有查看成员名单的权限', 403, 403);
        }
        $user = User::find((int)$id);
        if (!$user) {
            return api_err('成员不存在', 404, 404);
        }
        $side = GameSide::clean($this->request->post('game_side', ''));
        if ($side === '') {
            return api_err('端别必须选端游或手游，不能留空');
        }
        $lock = (string)$this->request->post('lock', '');
        if (!in_array($lock, ['', '0', '1'], true)) {
            return api_err('跨端可见的取值不正确');
        }
        $user->game_side = $side;
        $user->game_side_lock = $lock;
        $user->save();
        Db::name('admin_logs')->insert([
            'tenant_id'  => \app\service\TenantContext::id(),
            'admin_id'   => (int)(current_user()->id ?? 0),
            'admin_name' => (string)(current_user()->nickname ?? ''),
            'action'     => '端手游分类',
            'detail'     => '成员 ' . $user->nickname . '（' . $user->username . '）→ '
                . GameSide::sideLabel($side) . ($lock === '1' ? '·只能看本端' : ($lock === '0' ? '·允许看两端' : '')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return api_ok([
            'id'        => (int)$user->id,
            'game_side' => $side,
            'lock'      => (string)$user->game_side_lock,
        ], '已保存');
    }

    private function isSuper(): bool
    {
        return is_super_admin();
    }
}
