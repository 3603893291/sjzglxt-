<?php
declare (strict_types = 1);

namespace app\service;

use app\controller\Dashboard;
use think\facade\Db;

/**
 * 个人黑名单（v5.30.1）
 *
 * 三条口径，改之前先看清：
 *   1) 只对拉黑者本人生效。A 拉黑 B，只有 A 的视角里 B 的私聊与个人主页不见了；
 *      B 本人、别的成员、平台侧看到的都还是原样，B 也不会被告知。这不是封禁，是"我不想看见你"。
 *   2) 只能拉其它战队的账号。同队的人本来就在一套治理里（队长、管理、禁言），
 *      成员之间的事不该由一个私人名单来切。
 *   3) 每人最多 10 个。
 *
 * 拉黑之后的效果落在两处读取路径上：私聊（会话列表与正文）、个人主页（v5.32.0 起公共交流区下线，
 * 原先挡别人发的帖与公共发言那两条没有对象了 —— 这不是删功能，是那两处没了）。
 * 写入路径只有一条：send 时双方任一把对方拉黑了就发不出去。
 */
class Block
{
    /** 每人最多拉多少个 */
    const MAX = 10;

    /** @var array<int,int[]> 请求内缓存：拉黑者 => 被拉黑的 id 列表 */
    protected static $cache = [];

    /** 我拉黑了谁（同一次请求里会话列表与逐条都要问，所以只查一次库） */
    public static function ids(int $uid): array
    {
        if (isset(self::$cache[$uid])) {
            return self::$cache[$uid];
        }
        $ids = [];
        if ($uid > 0) {
            foreach (Db::name('user_blocks')->where('user_id', $uid)->column('blocked_user_id') as $b) {
                $ids[] = (int)$b;
            }
        }
        self::$cache[$uid] = $ids;
        return $ids;
    }

    /** 这个人被我拉黑了吗 */
    public static function has(int $uid, int $target): bool
    {
        return in_array($target, self::ids($uid), true);
    }

    /** 对方把我拉黑了吗（反向查，不进缓存——一次发送查一次就够） */
    public static function blockedMe(int $uid, int $peer): bool
    {
        if ($peer <= 0) {
            return false;
        }
        return (bool)Db::name('user_blocks')
            ->where('user_id', $peer)->where('blocked_user_id', $uid)->find();
    }

    /** 拉黑。成功返回 [null, 提示语]，失败返回 [错误语, null] */
    public static function add(int $uid, int $tenantId, int $target): array
    {
        if ($target <= 0 || $target === $uid) {
            return ['要拉黑的对象不对', null];
        }
        $u = Db::name('users')->where('id', $target)->find();
        if (!$u) {
            return ['没有这个账号', null];
        }
        if ((int)$u['tenant_id'] === $tenantId) {
            return ['黑名单只拉其它战队的人。本队成员的事请找队长或管理员', null];
        }
        if (self::has($uid, $target)) {
            return ['这个人已经在你的黑名单里了', null];
        }
        if (count(self::ids($uid)) >= self::MAX) {
            return ['黑名单最多 ' . self::MAX . ' 个人，先放一个出来', null];
        }
        Db::name('user_blocks')->insert([
            'tenant_id'       => $tenantId,
            'user_id'         => $uid,
            'blocked_user_id' => $target,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        self::forget($uid);
        return [null, '已拉黑：他的私聊和你看不到他的个人主页；要恢复就在黑名单里点放出'];
    }

    /** 解除拉黑 */
    public static function remove(int $uid, int $target): array
    {
        $row = Db::name('user_blocks')
            ->where('user_id', $uid)->where('blocked_user_id', $target)->find();
        if (!$row) {
            return ['他没在你的黑名单里', null];
        }
        Db::name('user_blocks')->where('id', (int)$row['id'])->delete();
        self::forget($uid);
        return [null, '已放出黑名单'];
    }

    /** 我的名单：显示名 + 队名 + 在不在线，一个 QQ 号都不出 */
    public static function listOf(int $uid): array
    {
        $ids = self::ids($uid);
        if ($ids === []) {
            return [];
        }
        $rows  = Db::name('users')->whereIn('id', $ids)
            ->column('id,nickname,tenant_id,status,last_active_at', 'id');
        $teams = Db::name('tenants')->column('name', 'id');
        $cut   = date('Y-m-d H:i:s', time() - Dashboard::ONLINE_WINDOW);
        $out   = [];
        foreach ($ids as $id) {
            $u = $rows[$id] ?? null;
            if (!$u) {
                continue;                    // 账号注销了：这行已经没有对象可拉，从名单里消失
            }
            $out[] = [
                'id'     => (int)$u['id'],
                'name'   => user_display_name($u),
                'team'   => (string)($teams[(int)$u['tenant_id']]['name'] ?? '其它战队'),
                'online' => (string)$u['last_active_at'] !== '' && (string)$u['last_active_at'] > $cut ? 1 : 0,
            ];
        }
        return $out;
    }

    /** 清掉这个人的请求内缓存（加/删之后必须调，否则同一请求里读到的还是旧名单） */
    public static function forget(int $uid): void
    {
        unset(self::$cache[$uid]);
    }
}
