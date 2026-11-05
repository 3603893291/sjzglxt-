<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\Storage;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 隐身巡查·只读台账（v5.16.0）
 *
 * 这是平台超管巡查战队站点时唯一能读的后台数据面，由 StealthRead 中间件保护：
 * 只有"确实是隐身巡查会话 + GET/HEAD"才进得来，普通成员与管理员账号都碰不到这组路由。
 *
 * 写法上的三条硬约束（防止这条通道反过来变成漏洞）：
 * 1. 每个端点一段独立只读 SQL，显式带 tenant_id，绝不复用管理端控制器/服务方法——
 *    因为若干 GET 路径里藏着写操作（BattleService::expireLazy、Transfer::settle 会真的改库）；
 * 2. 巡查态按约定给全量字段（完整 QQ / 邮箱 / 注册 IP 与归属地），代价是 StealthRead
 *    对这里的每一次调用都落一行审计日志，被巡查的站长在自己后台看得见；
 * 3. 不返回任何文件物理路径，也不开新的文件输出通道——巡查只看台账，不下载图本体。
 */
class Inspect extends BaseController
{
    /** 当前巡查目标租户 id（站点状态已由 ResolveTenant 校验：approved/paused/banned） */

    protected function tid(): int
    {
        return TenantContext::id();
    }

    /** 站点概览：各类计数与存储用量，不含个人信息；顺带输出巡查留痕提示 */
    public function overview()
    {
        $tid = $this->tid();
        $q   = fn(string $t) => Db::name($t)->where('tenant_id', $tid);
        return api_ok([
            'users_total'      => $q('users')->count(),
            'users_pending'    => $q('users')->where('status', 'pending')->count(),
            'users_banned'     => $q('users')->where('status', 'banned')->count(),
            'users_active_7d'  => $q('users')->where('last_active_at', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))->count(),
            'teams'            => $q('teams')->count(),
            'images_total'     => $q('images')->count(),
            'images_pending'   => $q('images')->where('status', 'pending')->count(),
            'images_rejected'  => $q('images')->where('status', 'rejected')->count(),
            'guides_total'     => $q('guides')->count(),
            'guides_draft'     => $q('guides')->where('status', '<>', 'published')->count(),
            'gun_codes'        => $q('gun_codes')->count(),
            'announcements'    => $q('announcements')->count(),
            'chat_7d'          => $q('chat_messages')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))->count(),
            'checkins_today'   => $q('checkins')->where('checkin_date', date('Y-m-d'))->count(),
            'blacklist'        => $q('blacklist')->count(),
            'pwd_reset_pending' => $q('password_resets')->count(),
            'battles_total'    => Db::name('battles')
                ->where(function ($w) use ($tid) {
                    $w->where('challenger_type', 'tenant')->where('challenger_id', $tid)
                      ->whereOr(function ($o) use ($tid) {
                          $o->where('defender_type', 'tenant')->where('defender_id', $tid);
                      });
                })->count(),
            'storage_used'     => Storage::used($tid),
            'storage_quota'    => Storage::quota($tid),
            'created_at'       => (string)Db::name('tenants')->where('id', $tid)->value('created_at'),
            'notice'           => '巡查期间的每一次读取都会写入该站点的操作日志（含访问者、时间与查询条件）',
        ]);
    }

    /**
     * 成员全量资料：完整 QQ / 昵称 / 邮箱 / 角色 / 状态 / 小队 / 注册 IP 与归属地 / 注册截图
     * 这是巡查通道里隐私最重的一根，StealthRead 对它的每一次调用都落审计。
     */
    public function members()
    {
        $tid  = $this->tid();
        $page = max(1, (int)$this->request->get('page', 1));
        $size = min(200, max(1, (int)$this->request->get('size', 50)));
        $status = trim((string)$this->request->get('status', ''));

        $query = Db::name('users')->where('tenant_id', $tid);
        if (in_array($status, ['pending', 'approved', 'banned'], true)) {
            $query->where('status', $status);
        }
        $total = (clone $query)->count();
        $rows  = $query->field('id, username, nickname, email, role, status, team_id, muted, reg_ip, reg_region, profile_image, last_active_at, created_at')
            ->order('id', 'asc')->page($page, $size)->select()->toArray();

        $teamNames = Db::name('teams')->where('tenant_id', $tid)->column('name', 'id');
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'             => (int)$r['id'],
                'qq'             => (string)$r['username'],
                'nickname'       => (string)$r['nickname'],
                'email'          => (string)$r['email'],
                'role'           => (string)$r['role'],
                'status'         => (string)$r['status'],
                'team_id'        => (int)$r['team_id'],
                'team_name'      => $teamNames[(int)$r['team_id']] ?? null,
                'muted'          => (int)$r['muted'],
                'reg_ip'         => (string)$r['reg_ip'],
                'reg_region'     => (string)$r['reg_region'],
                'profile_image'  => (string)$r['profile_image'],
                'last_active_at' => (string)$r['last_active_at'],
                'created_at'     => (string)$r['created_at'],
            ];
        }
        return api_ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    /** 待审核注册：注册佐证截图与来源 IP 都在取证范围内 */
    public function pending()
    {
        $tid = $this->tid();
        $rows = Db::name('users')->where('tenant_id', $tid)->where('status', 'pending')
            ->field('id, username, nickname, reg_ip, reg_region, profile_image, created_at')
            ->order('id', 'desc')->limit(200)->select()->toArray();
        return api_ok(['list' => $rows, 'count' => count($rows)]);
    }

    /** 黑名单档案（含完整 QQ，用于跨队前科核对） */
    public function blacklist()
    {
        $tid = $this->tid();
        $rows = Db::name('blacklist')->where('tenant_id', $tid)
            ->field('id, qq, nickname, reason, created_by, created_at')
            ->order('id', 'desc')->limit(200)->select()->toArray();
        return api_ok(['list' => $rows, 'count' => count($rows)]);
    }

    /** 待处理找回密码申请（不给密码哈希，只给申请事实），每次留痕 */
    public function passwordResets()
    {
        $tid  = $this->tid();
        $rows = Db::name('password_resets')->where('tenant_id', $tid)
            ->field('id, user_id, screenshot, request_ip, created_at')
            ->order('id', 'desc')->limit(200)->select()->toArray();
        $names = Db::name('users')->where('tenant_id', $tid)->column('nickname, username, role', 'id');
        $list = [];
        foreach ($rows as $r) {
            $u = $names[(int)$r['user_id']] ?? null;
            $list[] = [
                'id'           => (int)$r['id'],
                'user_id'      => (int)$r['user_id'],
                'nickname'     => $u ? (string)$u['nickname'] : '',
                'qq'           => (string)($u['username'] ?? ''),
                'has_screenshot' => (string)$r['screenshot'] !== '',
                'request_ip'   => (string)$r['request_ip'],
                'created_at'   => (string)$r['created_at'],
                'stale'        => strtotime((string)$r['created_at']) < time() - 72 * 3600,
            ];
        }
        return api_ok(['list' => $list, 'count' => count($list)]);
    }

    /** 站长操作日志（不含巡查自身产生的噪声） */
    public function logs()
    {
        $tid   = $this->tid();
        $limit = min(200, max(1, (int)$this->request->get('limit', 100)));
        $rows  = Db::name('admin_logs')->where('tenant_id', $tid)
            ->where('action', '<>', '隐身巡查')
            ->field('id, admin_id, admin_name, action, detail, created_at')
            ->order('id', 'desc')->limit($limit)->select()->toArray();
        return api_ok(['list' => $rows, 'count' => count($rows)]);
    }

    /** 图片台账：只给元信息，不给文件路径，也不开文件输出通道 */
    public function images()
    {
        $tid    = $this->tid();
        $status = trim((string)$this->request->get('status', ''));
        $query  = Db::name('images')->where('tenant_id', $tid);
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }
        $rows = $query->field('id, user_id, title, status, created_at')
            ->order('id', 'desc')->limit(200)->select()->toArray();
        $who = Db::name('users')->where('tenant_id', $tid)->column('nickname, username', 'id');
        $list = [];
        foreach ($rows as $r) {
            $u = $who[(int)$r['user_id']] ?? null;
            $list[] = [
                'id'         => (int)$r['id'],
                'title'      => (string)$r['title'],
                'status'     => (string)$r['status'],
                'uploader'   => $u ? (string)$u['nickname'] : '已注销用户',
                'uploader_qq' => (string)($u['username'] ?? ''),
                'created_at' => (string)$r['created_at'],
            ];
        }
        return api_ok(['list' => $list, 'count' => count($list)]);
    }

    /** 战绩台账（20 项指标原值 + 成员身份全量） */
    public function playerStats()
    {
        $tid  = $this->tid();
        $page = max(1, (int)$this->request->get('page', 1));
        $size = min(200, max(1, (int)$this->request->get('size', 50)));
        $total = (int)Db::name('player_stats')->where('tenant_id', $tid)->count();
        $rows = Db::name('player_stats')->where('tenant_id', $tid)
            ->order('user_id', 'asc')->page($page, $size)->select()->toArray();
        $who = Db::name('users')->where('tenant_id', $tid)->column('nickname, username', 'id');
        $list = [];
        foreach ($rows as $r) {
            $u = $who[(int)$r['user_id']] ?? null;
            $row = [
                'user_id'   => (int)$r['user_id'],
                'nickname'  => $u ? (string)$u['nickname'] : '',
                'qq'        => (string)($u['username'] ?? ''),
                'game_pid'  => (string)$r['game_pid'],
                'uploaded'  => (string)($r['updated_at'] ?? ''),
            ];
            unset($r['id'], $r['tenant_id'], $r['user_id'], $r['game_pid'], $r['updated_at']);
            $list[] = $row + ['stats' => $r];
        }
        return api_ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    /**
     * 本队相关约战（裸 SQL 只读）
     * 特意不走 BattleService：它的列表/详情会顺带跑 expireLazy() 把到期约战置为 expired，
     * 巡查应当真正"只看不动"。
     */
    public function battles()
    {
        $tid  = $this->tid();
        $rows = Db::name('battles')
            ->where(function ($w) use ($tid) {
                $w->where(function ($o) use ($tid) {
                    $o->where('challenger_type', 'tenant')->where('challenger_id', $tid);
                })->whereOr(function ($o) use ($tid) {
                    $o->where('defender_type', 'tenant')->where('defender_id', $tid);
                });
            })
            ->order('id', 'desc')->limit(100)->select()->toArray();

        $names = Db::name('tenants')->column('name', 'id');
        $ext   = Db::name('external_teams')->column('name', 'id');
        $list  = [];
        foreach ($rows as $b) {
            $bid = (int)$b['id'];
            $cnt = Db::name('battle_signups')->where('battle_id', $bid)
                ->field('side, COUNT(*) AS n')->group('side')->select()->toArray();
            $ca = 0; $cb = 0;
            foreach ($cnt as $c) {
                if ($c['side'] === 'a') { $ca = (int)$c['n']; } else { $cb = (int)$c['n']; }
            }
            $nameOf = function (string $type, int $id) use ($names, $ext) {
                return $type === 'tenant' ? (string)($names[$id] ?? ('租户#' . $id))
                    : (string)($ext[$id] ?? ('外部#' . $id));
            };
            $list[] = [
                'id'            => $bid,
                'title'         => (string)$b['title'],
                'status'        => (string)$b['status'],
                'battle_type'   => (string)($b['battle_type'] ?? 'official'),
                'series_format' => (string)($b['series_format'] ?? ''),
                'game_mode'     => (string)$b['game_mode'],
                'battle_time'   => (string)($b['battle_time'] ?? ''),
                'room_info'     => (string)($b['room_info'] ?? ''),
                'roster_limit'  => (int)($b['roster_limit'] ?? 0),
                'result'        => (string)($b['result'] ?? ''),
                'result_note'   => (string)($b['result_note'] ?? ''),
                'adv_side'      => (string)($b['adv_side'] ?? ''),
                'side'          => ((int)$b['challenger_id'] === $tid && $b['challenger_type'] === 'tenant') ? 'a' : 'b',
                'team_a'        => $nameOf((string)$b['challenger_type'], (int)$b['challenger_id']),
                'team_b'        => $nameOf((string)$b['defender_type'], (int)$b['defender_id']),
                'count_a'       => $ca,
                'count_b'       => $cb,
                'created_by'    => (string)$b['created_by'],
                'created_at'    => (string)$b['created_at'],
            ];
        }
        return api_ok(['list' => $list, 'count' => count($list)]);
    }
}
