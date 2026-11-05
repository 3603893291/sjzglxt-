<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\Guide as GuideModel;
use app\service\ImageUpload;

/**
 * 游戏攻略（v3.7：用户自发布文章页，支持封面图 + 上下架）
 * 权限：作者可编辑/上下架/删除自己的攻略；管理员可操作任何攻略
 */
class Guide extends AuthBase
{
    // 列表：普通用户看已发布 + 自己的；管理员看全部
    public function all()
    {
        $me   = current_user();
        $page = max(1, (int)$this->request->get('page', 1));
        $size = min(50, max(1, (int)$this->request->get('size', 12)));

        $query = GuideModel::alias('g')
            ->leftJoin('users u', 'u.id = g.user_id')
            ->field('g.id, g.user_id, g.title, g.content, g.image, g.status, g.created_at, g.updated_at,
                     COALESCE(u.nickname, "已注销用户") AS author, u.username AS author_qq');

        if (!is_admin()) {
            $query->where(function ($q) use ($me) {
                $q->where('g.status', 'published')->whereOr('g.user_id', $me->id);
            });
        }

        $total = (clone $query)->count();
        $list  = $query->order('g.id', 'desc')->page($page, $size)->select()->toArray();

        // 封面走代理地址，不暴露物理路径；作者 QQ 按 qq_full 权限脱敏（管理员无权限打码，普通用户仅后 4 位）
        $admin     = is_admin();
        $canFullQq = admin_can('qq_full');
        foreach ($list as &$item) {
            $item['cover_url'] = $item['image'] !== '' ? '/api/guides/raw/' . $item['id'] : '';
            $item['summary']   = mb_substr(trim((string)$item['content']), 0, 80);
            unset($item['image'], $item['content']); // 列表不带正文，省流量
            $qq = $item['author_qq'] ?? '';
            $item['author_qq'] = $canFullQq ? $qq : ($admin
                ? mask_qq((string)$qq)
                : ((strlen($qq) >= 4) ? '****' . substr($qq, -4) : '****'));
        }
        unset($item);

        return api_ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    // 详情：已发布的任何人可看；下架的仅作者与管理员可看
    public function detail($id)
    {
        $me    = current_user();
        $guide = GuideModel::find((int)$id);
        if (!$guide) {
            return api_err('攻略不存在', 404);
        }
        if ($guide->status !== 'published' && $guide->user_id != $me->id && !is_admin()) {
            return api_err('该攻略已下架', 403, 403);
        }

        $author = \app\model\User::find($guide->user_id);
        $qq     = $author ? (string)$author->username : '';
        // 作者 QQ 按 qq_full 权限脱敏，与列表策略一致
        $qqShown = admin_can('qq_full') ? $qq : (is_admin()
            ? mask_qq((string)$qq)
            : ((strlen($qq) >= 4) ? '****' . substr($qq, -4) : '****'));
        return api_ok([
            'id'         => $guide->id,
            'user_id'    => $guide->user_id,
            'title'      => $guide->title,
            'content'    => $guide->content,
            'status'     => $guide->status,
            'cover_url'  => $guide->image !== '' ? '/api/guides/raw/' . $guide->id : '',
            'author'     => $author ? ($author->nickname ?: $qqShown) : '已注销用户',
            'author_qq'  => $qqShown,
            'created_at' => $guide->created_at,
            'updated_at' => $guide->updated_at,
        ]);
    }

    // 发布攻略（multipart：标题 + 正文 + 可选封面图）
    public function create()
    {
        $me      = current_user();
        $title   = trim((string)$this->request->post('title', ''));
        $content = trim((string)$this->request->post('content', ''));

        if ($title === '' || mb_strlen($title) > 128) {
            return api_err('请填写攻略标题（不超过 128 字）');
        }
        if ($content === '') {
            return api_err('请填写攻略内容');
        }
        if (mb_strlen($content) > 20000) {
            return api_err('攻略内容最长 20000 字');
        }

        // 封面图可选；必须用无参 file() 取数组，空文件框不会 500
        $imagePath = '';
        $files = $this->request->file();
        if ($files['file'] ?? null) {
            [$imagePath, $err] = ImageUpload::save($files['file'], 'guides');
            if ($err !== null) {
                return api_err($err);
            }
        }

        $guide = GuideModel::create([
            'user_id' => $me->id,
            'title'   => $title,
            'content' => $content,
            'image'   => $imagePath,
            'status'  => 'published',
        ]);
        return api_ok(['id' => $guide->id], '攻略发布成功');
    }

    // 编辑攻略（仅作者本人或管理员）
    public function update($id)
    {
        $me      = current_user();
        $guide   = GuideModel::find((int)$id);
        $title   = trim((string)$this->request->post('title', ''));
        $content = trim((string)$this->request->post('content', ''));

        if (!$guide) {
            return api_err('攻略不存在', 404);
        }
        if ($guide->user_id != $me->id && !is_admin()) {
            return api_err('只能编辑自己发布的攻略', 403, 403);
        }
        if ($title === '' || mb_strlen($title) > 128) {
            return api_err('请填写攻略标题（不超过 128 字）');
        }
        if ($content === '' || mb_strlen($content) > 20000) {
            return api_err('攻略内容不能为空且最长 20000 字');
        }

        $data = ['title' => $title, 'content' => $content];

        // 换封面：上传新图则替换旧图（旧物理文件一并删除）
        $files = $this->request->file();
        if ($files['file'] ?? null) {
            [$imagePath, $err] = ImageUpload::save($files['file'], 'guides');
            if ($err !== null) {
                return api_err($err);
            }
            if ($guide->image !== '') {
                ImageUpload::delete((string)$guide->image);
            }
            $data['image'] = $imagePath;
        }

        $guide->save($data);
        return api_ok([], '攻略已更新');
    }

    // 上架 / 下架（仅作者本人或管理员）
    public function toggle($id)
    {
        $me    = current_user();
        $guide = GuideModel::find((int)$id);
        if (!$guide) {
            return api_err('攻略不存在', 404);
        }
        if ($guide->user_id != $me->id && !is_admin()) {
            return api_err('只能操作自己发布的攻略', 403, 403);
        }

        $newStatus = $guide->status === 'published' ? 'offline' : 'published';
        $guide->save(['status' => $newStatus]);
        return api_ok(['status' => $newStatus], $newStatus === 'published' ? '已上架' : '已下架');
    }

    // 删除攻略（仅作者本人或管理员，连封面物理文件一起删）
    public function remove($id)
    {
        $me    = current_user();
        $guide = GuideModel::find((int)$id);
        if (!$guide) {
            return api_err('攻略不存在', 404);
        }
        if ($guide->user_id != $me->id && !is_admin()) {
            return api_err('只能删除自己发布的攻略', 403, 403);
        }

        if ($guide->image !== '') {
            ImageUpload::delete((string)$guide->image);
        }
        $guide->delete();
        return api_ok([], '攻略已删除');
    }

    // 封面图代理输出（鉴权后返回二进制，禁止直链）
    public function raw($id)
    {
        $guide = GuideModel::find((int)$id);
        // v5.2.0：跨租户隔离——find() 不走全局作用域，显式校验归属，禁止按 ID 越站读图
        if (!$guide || (int)$guide->tenant_id !== \app\service\TenantContext::id()) {
            return response('', 404);
        }
        if ($guide->image === '') {
            return response('', 404);
        }

        // 下架攻略的封面仅作者与管理员可见
        if ($guide->status !== 'published') {
            $me = current_user();
            if (!$me || ($me->id != $guide->user_id && !is_admin())) {
                return response('', 403);
            }
        }

        $file = (string)$guide->image;
        // 安全：只允许输出上传目录内的文件，防数据库被污染后路径穿越
        if (strpos($file, '/storage/uploads/') !== 0 || strpos($file, '..') !== false) {
            return response('', 404);
        }
        $abs = public_path() . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $file), DIRECTORY_SEPARATOR);
        if (!is_file($abs)) {
            return response('', 404);
        }

        $ext   = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mimes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
        $mime  = $mimes[$ext] ?? 'application/octet-stream';

        // 二进制 MIME 不带 charset（contentType() 会强拼 charset=utf-8，改用 header 直设）
        return response(file_get_contents($abs))->header([
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=604800',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
