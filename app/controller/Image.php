<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\Image as ImageModel;
use app\service\ImageUpload;
use think\facade\Cache;

/**
 * 游戏图片：上传（待审核）、公开展示（已审核）、我的上传
 */
class Image extends AuthBase
{
    // 公开展示（仅审核通过）
    public function all()
    {
        $page = max(1, (int)$this->request->get('page', 1));
        $size = min(50, max(1, (int)$this->request->get('size', 12)));

        $query = ImageModel::alias('i')
            ->leftJoin('users u', 'u.id = i.user_id')
            ->where('i.status', 'approved')
            ->field('i.id, i.title, i.file, i.created_at, COALESCE(u.nickname, "已注销用户") AS uploader, u.username AS uploader_qq');

        $total = (clone $query)->count();
        $list  = $query->order('i.id', 'desc')
            ->page($page, $size)
            ->select();

        // 替换为图片代理地址（防止直链泄露文件名 / 绕过审核鉴权）
        // 注意：必须先 toArray()——Collection 上按引用循环改不到原对象
        $list = $list->toArray();
        $admin     = is_admin();
        $canFullQq = admin_can('qq_full');
        foreach ($list as &$item) {
            $item['img_url'] = '/api/images/raw/' . $item['id'];
            unset($item['file']); // 不暴露物理路径
            // 安全：上传者 QQ 按 qq_full 权限脱敏（管理员无权限打码，普通用户仅后 4 位）
            $qq = $item['uploader_qq'] ?? '';
            $item['uploader_qq'] = $canFullQq ? $qq : ($admin
                ? mask_qq((string)$qq)
                : ((strlen($qq) >= 4) ? '****' . substr($qq, -4) : '****'));
        }
        unset($item);

        return api_ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    // 我的上传（含审核状态，暴露 file 给自己用）
    public function my()
    {
        $user = current_user();
        $list = ImageModel::where('user_id', $user->id)
            ->order('id', 'desc')
            ->select();

        // 自己的人上传图片给代理地址
        $list = $list->toArray();
        foreach ($list as &$item) {
            $item['img_url'] = '/api/images/raw/' . $item['id'];
            unset($item['file']); // 统一走代理，不暴露物理路径
        }
        unset($item);

        return api_ok(['list' => $list]);
    }

    // 上传图片（进入待审核）
    public function upload()
    {
        // 上传频率限制：同一用户每分钟最多上传 3 张
        $user  = current_user();
        $rateKey = 'upload_rate_' . $user->id;
        if ((int)Cache::get($rateKey, 0) >= 3) {
            return api_err('上传过于频繁，请稍后再试', 429, 429);
        }

        $title = trim((string)$this->request->post('title', ''));

        if ($title === '' || mb_strlen($title) > 128) {
            return api_err('请填写图片标题（不超过 128 字）');
        }

        // 用无参 file() 取数组：未选文件时 file('file') 会抛异常导致 500，这里要给友好提示
        $upFiles = $this->request->file();
        [$path, $err] = ImageUpload::save($upFiles['file'] ?? null, 'images');
        if ($err !== null) {
            return api_err($err);
        }

        $image = ImageModel::create([
            'user_id' => $user->id,
            'title'   => $title,
            'file'    => $path,
            'status'  => 'pending',
        ]);

        // 上传计数器 +1
        Cache::set($rateKey, (int)Cache::get($rateKey, 0) + 1, 60);

        return api_ok(['id' => $image->id, 'file' => $path], '上传成功，等待管理员审核');
    }

    // 图片代理输出：鉴权后返回图片二进制，禁止直链绕过审核
    public function raw($id)
    {
        $image = ImageModel::find((int)$id);
        // v5.2.0 跨租户隔离；v5.7.1(L4) 注释更正：think-orm 的 find() 本身会应用租户全局作用域，
        // 下面的显式校验是纵深防御（即使未来作用域被误关也拦得住），两层都保留、缺一不可
        if (!$image || (int)$image->tenant_id !== \app\service\TenantContext::id()) {
            return response('', 404);
        }

        // 仅 approved 的图片公开；pending/rejected 仅本人或管理员可看
        if ($image->status !== 'approved') {
            $me = current_user();
            // v5.15.0(S-L3)：待审/驳回截图属于他人上传内容，管理员侧沿用既有口径
            // （screenshots=「用户上传截图可查看」，image_review=图片审核），
            // 零权限的挂名管理员不再能看到别人没通过审核的截图
            $canSee = admin_can('screenshots') || admin_can('image_review');
            if (!$me || ($me->id != $image->user_id && !$canSee)) {
                return response('', 403);
            }
        }

        $file = $image->file;
        // 安全：只允许输出上传目录内的文件，防止数据库被污染后路径穿越读任意文件
        if (strpos($file, '/storage/uploads/') !== 0 || strpos($file, '..') !== false) {
            return response('', 404);
        }
        $abs  = public_path() . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $file), DIRECTORY_SEPARATOR);
        if (!is_file($abs)) {
            return response('', 404);
        }

        $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mimes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
        $mime = $mimes[$ext] ?? 'application/octet-stream';

        // v5.10.0：?thumb=1 开启查看时压缩（列表缩略图场景），不改动磁盘文件
        // 默认不开：详情页直接读原文件，避免重复编码开销
        if ($this->request->get('thumb') === '1' && \app\service\Setting::getPlatform('image_compress_enabled', '1') === '1') {
            $maxW = max(320, min(2560, (int)$this->request->get('w', 1280)));
            $q    = max(40, min(95, (int)$this->request->get('q', 78)));
            $view = \app\service\ImageCompressor::compressForView($abs, $maxW, $q);
            if ($view !== null) {
                return response($view['body'])->header([
                    'Content-Type'  => $view['mime'],
                    'Cache-Control' => 'public, max-age=86400',   // 缩略图缓存 1 天
                    'X-Content-Type-Options' => 'nosniff',
                    'Vary'          => 'Accept',
                ]);
            }
        }

        // 二进制 MIME 不带 charset（contentType() 会强拼 charset=utf-8，改用 header 直设）
        return response(file_get_contents($abs))->header([
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=604800',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
