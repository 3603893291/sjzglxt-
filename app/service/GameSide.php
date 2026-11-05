<?php
declare (strict_types = 1);

namespace app\service;

use app\model\User;

/**
 * 端手分离（v5.36.0）
 *
 * 口径：
 *   - 端别只有两种：pc 端游 / mobile 手游。成员必须落在其中一端，新设置不再接受空串；
 *     内容上的空串表示「全队共享」。
 *   - 老数据的成员 game_side 可能仍是空串（升级前留下的未分类），只作兼容：
 *     这类老成员两端都能看，总开关也会因其未分端而打不开，分好端即恢复正常。
 *   - 总开关 side_split_on 默认关（不插 settings 默认行）；关了完全不看 game_side 列。
 *   - 八块内容各自可关掉分端：side_split_modules JSON 里写 "0" 的就是不分端；没写的按分端算。
 *   - 成员能不能跨端看：先看按端默认（side_lock_mobile / side_lock_pc，1=只能看本端），
 *     再按人例外（users.game_side_lock：1=只能看本端 / 0=允许看两端 / 空=跟随全队）。
 *     老数据的未分类成员两端通用，不受这两把锁影响。
 *   - 管理端页面不受限制（管理员要能配两端的公告与日程）；成员端接口必须过 allowed()。
 */
class GameSide
{
    public const PC     = 'pc';
    public const MOBILE = 'mobile';

    /** 模块键 => 中文名（前端设置页也读这一份顺序） */
    public static function moduleDefs(): array
    {
        return [
            'announce'    => '公告',
            'race'        => '撞车日程',
            'custom_race' => '自建房日程',
            'event'       => '日常活动',
            'training'    => '队内训练赛',
            'vote'        => '投票',
            'team'        => '战队小队',
            'duty'        => '值班表',
        ];
    }

    public static function on(): bool
    {
        return Setting::get('side_split_on', '0') === '1';
    }

    /** 跨租户路径（定时提醒、机器人）用：直读那个队的设置，不碰当前请求的缓存 */
    public static function onFor(int $tenantId): bool
    {
        return Setting::getFor($tenantId, 'side_split_on', '0') === '1';
    }

    /** @return array<string,bool> 每个模块当前是否分端 */
    public static function moduleMap(): array
    {
        return self::parseModules(Setting::get('side_split_modules', ''));
    }

    public static function moduleMapFor(int $tenantId): array
    {
        return self::parseModules(Setting::getFor($tenantId, 'side_split_modules', ''));
    }

    protected static function parseModules(string $raw): array
    {
        $saved = json_decode($raw === '' ? '{}' : $raw, true);
        if (!is_array($saved)) {
            $saved = [];
        }
        $map = [];
        foreach (array_keys(self::moduleDefs()) as $k) {
            // 没写过的键按「分端」算：总开关一开就该能直接用，不想分的再点掉
            $map[$k] = !array_key_exists($k, $saved) || (string)$saved[$k] === '1';
        }
        return $map;
    }

    public static function moduleOn(string $module): bool
    {
        if (!self::on()) {
            return false;
        }
        $map = self::moduleMap();
        return !empty($map[$module]);
    }

    public static function moduleOnFor(int $tenantId, string $module): bool
    {
        if (!self::onFor($tenantId)) {
            return false;
        }
        $map = self::moduleMapFor($tenantId);
        return !empty($map[$module]);
    }

    /** @return array{pc:bool,mobile:bool} 按端默认「只能看本端」 */
    public static function locks(): array
    {
        return [
            self::PC     => Setting::get('side_lock_pc', '0') === '1',
            self::MOBILE => Setting::get('side_lock_mobile', '0') === '1',
        ];
    }

    public static function userSide(?User $user = null): string
    {
        $user = $user ?: current_user();
        $v = $user ? (string)($user->game_side ?? '') : '';
        return in_array($v, [self::PC, self::MOBILE], true) ? $v : '';
    }

    public static function userLock(?User $user = null): string
    {
        $user = $user ?: current_user();
        $v = $user ? (string)($user->game_side_lock ?? '') : '';
        return in_array($v, ['0', '1'], true) ? $v : '';
    }

    /**
     * 成员能看哪几个专区。
     * 老数据未分类 => 两端；有本端 => 看按人例外，其次看按端默认。
     * @return string[]
     */
    public static function allowed(?User $user = null): array
    {
        $side = self::userSide($user);
        if ($side === '') {
            return [self::PC, self::MOBILE];
        }
        $lock = self::userLock($user);
        if ($lock === '1') {
            return [$side];
        }
        if ($lock === '0') {
            return [self::PC, self::MOBILE];
        }
        $locks = self::locks();
        return !empty($locks[$side]) ? [$side] : [self::PC, self::MOBILE];
    }

    /** 客户端传来的专区（请求头 X-Game-Side，其次 ?game_side=）；非法值当没传 */
    public static function requested(): string
    {
        $v = '';
        try {
            $v = (string)request()->header('X-Game-Side', '');
            if ($v === '') {
                $v = (string)request()->param('game_side', '');
            }
        } catch (\Throwable $e) {
            $v = '';
        }
        $v = strtolower(trim($v));
        return in_array($v, [self::PC, self::MOBILE], true) ? $v : '';
    }

    /**
     * 当前请求落在哪个专区。
     * 成员端：只能落在 allowed() 里，越权传另一端会被拉回本端。
     * 管理端（$admin=true）：允许切到任意一端，方便配两边的公告与日程。
     */
    public static function zone(?User $user = null, bool $admin = false): string
    {
        $req   = self::requested();
        $own   = self::userSide($user);
        $allow = self::allowed($user);
        if ($admin) {
            if ($req !== '') {
                return $req;
            }
            return $own !== '' ? $own : self::MOBILE;
        }
        if ($req !== '' && in_array($req, $allow, true)) {
            return $req;
        }
        if ($own !== '') {
            return in_array($own, $allow, true) ? $own : $allow[0];
        }
        return in_array(self::MOBILE, $allow, true) ? self::MOBILE : self::PC;
    }

    public static function zoneLabel(string $side): string
    {
        if ($side === self::PC) {
            return '端游专区';
        }
        if ($side === self::MOBILE) {
            return '手游专区';
        }
        return '全队共享';
    }

    public static function sideLabel(string $side): string
    {
        if ($side === self::PC) {
            return '端游';
        }
        if ($side === self::MOBILE) {
            return '手游';
        }
        return '未分类';
    }

    /** 把表单里的值收成合法端别；不认识的一律当全队共享（内容用；成员端必须另验非空） */
    public static function clean($v): string
    {
        $v = strtolower(trim((string)$v));
        return in_array($v, [self::PC, self::MOBILE], true) ? $v : '';
    }

    /**
     * 给查询加专区过滤：模块没开分端就原样返回（一行都不动）。
     * @param mixed $query think\db\Query
     */
    public static function scope($query, string $module, bool $admin = false, string $column = 'game_side')
    {
        if (!self::moduleOn($module)) {
            return $query;
        }
        return $query->whereIn($column, [self::zone(null, $admin), '']);
    }

    /** 数组已经取出来了，再按专区筛一遍（小列表/聚合用） */
    public static function filterRows(array $rows, string $module, bool $admin = false): array
    {
        if (!self::moduleOn($module)) {
            return $rows;
        }
        $z = self::zone(null, $admin);
        return array_values(array_filter($rows, static function ($r) use ($z) {
            $s = is_array($r) ? (string)($r['game_side'] ?? '') : (string)($r->game_side ?? '');
            return $s === '' || $s === $z;
        }));
    }

    /** 这一条内容当前成员能不能看（模块没分端 / 管理端 一律放行） */
    public static function canSee(string $module, string $itemSide, bool $admin = false): bool
    {
        if ($admin || !self::moduleOn($module)) {
            return true;
        }
        return $itemSide === '' || $itemSide === self::zone();
    }

    /** 能不能参与（报名/投票/入队）：老数据的未分类成员两边都能参与；有本端的只能参与本端或共享 */
    public static function canJoin(string $itemSide): bool
    {
        $side = self::userSide();
        return $side === '' || $itemSide === '' || $side === $itemSide;
    }

    /** 给 /api/auth/me 的整包状态 */
    public static function status(?User $user = null): array
    {
        $on = self::on();
        return [
            'on'          => $on,
            'modules'     => $on ? self::moduleMap() : array_fill_keys(array_keys(self::moduleDefs()), false),
            'module_labels' => self::moduleDefs(),
            'mine'        => self::userSide($user),
            'lock'        => self::userLock($user),
            'allowed'     => self::allowed($user),
            'locks'       => self::locks(),
        ];
    }
}
