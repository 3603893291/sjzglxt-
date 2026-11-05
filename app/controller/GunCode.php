<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\GunCode as GunCodeModel;
use app\model\GunCodeComment;
use think\facade\Cache;

/**
 * 改枪码（v2）：
 * - 字段：枪械名字(title) / 枪码内容(code) / 说明(description)，无游戏名称
 * - 发布即公开；用户可修改、上下架自己的；管理员可修改、上下架任何人的
 */
class GunCode extends AuthBase
{
    // 列表（默认只显示已发布；管理员可看全部含已下架）
    public function all()
    {
        $page    = max(1, (int)$this->request->get('page', 1));
        $size    = min(50, max(1, (int)$this->request->get('size', 10)));
        $keyword = trim((string)$this->request->get('keyword', ''));   // 按枪械名字搜索
        // v5.26.0：按武器类别筛。空或 all = 全部；认不出来的值一律当没筛（不报错、不越权）
        $category = trim((string)$this->request->get('category', ''));

        $me = current_user();

        $query = GunCodeModel::alias('g')
            ->leftJoin('users u', 'u.id = g.user_id')
            ->field('g.*, COALESCE(u.nickname, "已注销用户") AS author, u.username AS author_qq, (SELECT COUNT(*) FROM gun_code_comments c WHERE c.gun_code_id = g.id) AS comment_count');

        if (!is_admin()) {
            // 普通用户：看全部已发布的 + 自己的（含已下架，便于重新上架/修改）
            $query->where(function ($q) use ($me) {
                $q->where('g.status', 'published')->whereOr('g.user_id', $me->id);
            });
        }
        if ($keyword !== '') {
            $query->whereLike('g.title', '%' . $keyword . '%');
        }
        if ($category !== '' && $category !== 'all' && isset(GunCodeModel::CATEGORIES[$category])) {
            $query->where('g.category', $category);
        }

        $total = (clone $query)->count();
        $list  = $query->order('g.id', 'desc')
            ->page($page, $size)
            ->select();

        // 安全：作者 QQ 按 qq_full 权限脱敏（管理员无权限打码，普通用户仅后 4 位）
        // 必须先 toArray()——Collection 上按引用循环改不到原对象
        $list      = $list->toArray();
        $admin     = is_admin();
        $canFullQq = admin_can('qq_full');
        if (!$canFullQq) {
            foreach ($list as &$item) {
                $qq = $item['author_qq'] ?? '';
                $item['author_qq'] = $admin
                    ? mask_qq((string)$qq)
                    : ((strlen($qq) >= 4) ? '****' . substr($qq, -4) : '****');
            }
            unset($item);
        }

        return api_ok([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'size'     => $size,
            'me_id'    => $me->id,
            'is_admin' => is_admin(),
            'categories' => GunCodeModel::categoryOptions(),
        ]);
    }

    // 发布枪码（直接公开）
    public function create()
    {
        $user = current_user();

        // 防重复提交兜底：同一用户 3 秒内只能发布 1 次（前端慢响应连点会产生重复请求）
        $rateKey = 'gc_create_rate_' . $user->id;
        if (Cache::has($rateKey)) {
            return api_err('提交过于频繁，请稍后再试', 429, 429);
        }

        $data = $this->readPayload();
        if ($err = $this->validatePayload($data)) {
            return api_err($err);
        }

        $code = GunCodeModel::create([
            'user_id'     => $user->id,
            'title'       => $data['title'],
            'code'        => $data['code'],
            'description' => $data['description'],
            'category'    => $data['category'],
            'status'      => 'published',
        ]);

        Cache::set($rateKey, 1, 3);

        return api_ok(['id' => $code->id], '发布成功');
    }

    // 修改枪码内容（本人或管理员）
    public function update($id)
    {
        $code = GunCodeModel::find((int)$id);
        if (!$code) {
            return api_err('枪码不存在', 404);
        }
        if ($err = $this->checkOwner($code)) {
            return $err;
        }

        $data = $this->readPayload();
        if ($err = $this->validatePayload($data)) {
            return api_err($err);
        }

        $code->save([
            'title'       => $data['title'],
            'code'        => $data['code'],
            'description' => $data['description'],
            'category'    => $data['category'],
        ]);

        return api_ok([], '修改成功');
    }

    // 上架 / 下架（本人或管理员）
    public function toggleStatus($id)
    {
        $code = GunCodeModel::find((int)$id);
        if (!$code) {
            return api_err('枪码不存在', 404);
        }
        if ($err = $this->checkOwner($code)) {
            return $err;
        }

        $offline = $code->status === 'published';
        $code->save(['status' => $offline ? 'offline' : 'published']);
        return api_ok(['status' => $code->status], $offline ? '已下架' : '已上架');
    }

    // 删除枪码（本人或管理员，同时清理其下评论）
    public function remove($id)
    {
        $code = GunCodeModel::find((int)$id);
        if (!$code) {
            return api_err('枪码不存在', 404);
        }
        if ($err = $this->checkOwner($code)) {
            return $err;
        }
        GunCodeComment::where('gun_code_id', $code->id)->delete();
        $code->delete();
        return api_ok([], '删除成功');
    }

    // ============ 评论（v3.2） ============

    // 评论列表（含评论者昵称）
    public function comments($id)
    {
        $code = GunCodeModel::find((int)$id);
        if (!$code) {
            return api_err('枪码不存在', 404);
        }

        $list = GunCodeComment::alias('c')
            ->leftJoin('users u', 'u.id = c.user_id')
            ->where('c.gun_code_id', $code->id)
            ->field('c.id, c.user_id, c.content, c.created_at, COALESCE(u.nickname, "已注销用户") AS nickname, u.role AS user_role')
            ->order('c.id', 'asc')
            ->limit(500)
            ->select()
            ->toArray();

        return api_ok([
            'list'     => $list,
            'me_id'    => current_user()->id,
            'is_admin' => is_admin(),
            'total'    => count($list),
        ]);
    }

    // 发表评论（禁言用户拦截；3 秒限流防连点）
    public function addComment($id)
    {
        $user = current_user();

        if ((int)$user->muted === 1) {
            return api_err('你已被管理员禁言，暂时不能评论', 403, 403);
        }

        $code = GunCodeModel::find((int)$id);
        if (!$code) {
            return api_err('枪码不存在', 404);
        }

        // 防重复提交：同一用户 3 秒内只能评论 1 次
        $rateKey = 'gc_comment_rate_' . $user->id;
        if (Cache::has($rateKey)) {
            return api_err('评论过于频繁，请稍后再试', 429, 429);
        }

        $content = trim((string)$this->request->post('content', ''));
        if ($content === '') {
            return api_err('评论内容不能为空');
        }
        if (mb_strlen($content) > 300) {
            return api_err('评论最长 300 字');
        }

        $comment = GunCodeComment::create([
            'gun_code_id' => $code->id,
            'user_id'     => $user->id,
            'content'     => $content,
        ]);

        Cache::set($rateKey, 1, 3);

        return api_ok([
            'id'         => $comment->id,
            'user_id'    => $user->id,
            'nickname'   => $user->nickname,
            'content'    => $comment->content,
            'created_at' => $comment->created_at,
        ], '评论成功');
    }

    // 删除评论（管理员可删任何人的；用户可删自己的）
    public function deleteComment($cid)
    {
        $comment = GunCodeComment::find((int)$cid);
        if (!$comment) {
            return api_err('评论不存在', 404);
        }
        $user = current_user();
        if ($comment->user_id != $user->id && !is_admin()) {
            return api_err('只能删除自己的评论', 403, 403);
        }
        $comment->delete();
        return api_ok([], '评论已删除');
    }

    private function readPayload(): array
    {
        $cat = trim((string)$this->request->post('category', ''));
        return [
            'title'       => trim((string)$this->request->post('title', '')),
            'code'        => trim((string)$this->request->post('code', '')),
            'description' => trim((string)$this->request->post('description', '')),
            // 前端下拉里没有「全部」这一项；万一有人直接发 all，落到未分类而不是报错
            'category'    => ($cat === '' || $cat === 'all') ? 'other' : $cat,
        ];
    }

    private function validatePayload(array $data): ?string
    {
        if ($data['title'] === '' || mb_strlen($data['title']) > 128) {
            return '请填写枪械名字（不超过 128 字）';
        }
        if ($data['code'] === '') {
            return '请填写枪码内容';
        }
        if (mb_strlen($data['description']) > 500) {
            return '说明最长 500 字';
        }
        if (!isset(GunCodeModel::CATEGORIES[$data['category']])) {
            return '请选择正确的武器类别';
        }
        return null;
    }

    // 本人或管理员校验（宽松比较：不同数据库驱动下 id 可能返回 int/string）
    private function checkOwner(GunCodeModel $code)
    {
        $user = current_user();
        if ($code->user_id != $user->id && !is_admin()) {
            return api_err('只能操作自己的枪码', 403, 403);
        }
        return null;
    }
}
