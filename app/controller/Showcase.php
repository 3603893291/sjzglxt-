<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\BotText;
use app\service\ImageUpload;
use app\service\IpRegion;
use app\service\SeoMeta;
use app\service\Showcase as Show;
use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Request;

/**
 * 战队对外展示页（v5.20.0，访客可访问）
 *
 * 这是本系统第一个"把内容交给全互联网看"的页面，口径都收在这几行：
 *   · 服务端直出（render_page），不加载 React/Babel——公开页等不起浏览器内转译的十几秒，
 *     也只有这样 sitemap 里那条 /show 才是个能被收录的真地址；
 *   · 只渲染 Showcase::visible() 通过的站点：战队自己没开、被平台下架、站点被停用，一律 404；
 *   · 页面上不出现任何成员的 QQ、邮箱、IP、归属地；没填昵称的成员干脆不上页
 *     （user_display_name() 没昵称时会退回脱敏 QQ，那仍然是 QQ）；
 *   · 访客表单（内容投稿 / 入队意向）走"图形码 + honeypot + 停留时长 + 频率闸 + 敏感词"五道；
 *   · 提交意向不等于入队资格，成功文案里就写清楚还得注册并通过战队审核。
 */
class Showcase extends BaseController
{
    /** 投稿/意向正文上限（与建表的 VARCHAR(500) 一致） */
    const CONTENT_MAX = 500;

    /** 展示页：/{slug}/show（仅战队上下文；平台根路径没有"本队"可展示） */
    public function page()
    {
        if (TenantContext::isPlatform()) {
            return $this->notFound();
        }
        $tid = TenantContext::id();
        if (!Show::visible($tid)) {
            // 没开启 / 被平台下架 / 站点停用都回 404：不给搜索引擎留一个"存在但是空"的地址
            return $this->notFound();
        }
        $data = Show::pageData($tid);

        // 验证码的键与码在本次渲染里生成，图片由 /show/captcha/{key} 出，
        // 不发 cookie、不开会话——公开页不该给访客写任何东西。
        // 投稿与入队意向是两个独立表单，各发一枚：共用一枚的话提交其中一个
        // 就把另一个的码一起烧掉了
        $caps = [];
        foreach (array_values(Show::TYPES) as $t) {
            [$k, $code] = Show::captchaIssue();
            Cache::set('show_capimg_' . $k, $code, 600);
            $caps[$t] = $k;
        }

        return render_page('showcase.html', [
            'show'   => $data,
            'caps'   => $caps,
            'head'   => SeoMeta::head(
                $data['team']['name'] . ' 战队主页'
                    . ($data['team']['slogan'] !== '' ? ' - ' . $data['team']['slogan'] : ''),
                $data['team']['slogan']
            ),
        ]);
    }

    /** 公示大厅：平台根路径 /hall（战队上下文没有"各战队"可列） */
    public function hall()
    {
        if (!TenantContext::isPlatform()) {
            return $this->notFound();
        }
        if (!Show::hallOpen()) {
            return response('公示大厅当前未开放', 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        $kw = mb_substr(trim((string)Request::get('q', '')), 0, 30);
        // 带关键词时只扫一次（hallCards 内部逐站点读 settings，别为了算总数再扫第二遍）
        $cards = Show::hallCards($kw);
        return render_page('hall.html', [
            'cards'    => $cards,
            'totals'   => Show::hallTotals(count($cards)),
            'featured' => $this->featuredRows($cards),
            'board'    => Show::winBoard(5),
            'q'        => $kw,
            'head'     => SeoMeta::head('战队公示大厅', '平台认证战队一览：招募状态、成员规模与战队主页'),
        ]);
    }

    /** 焦点展示区 = 平台超管手动精选的那几支（查不到或已下架的 id 自动忽略） */
    protected function featuredRows(array $cards): array
    {
        $byTid = [];
        foreach ($cards as $c) {
            $byTid[$c['tid']] = $c;
        }
        $out = [];
        foreach (Show::featuredIds() as $tid) {
            if (isset($byTid[$tid])) {
                $out[] = $byTid[$tid];
            }
        }
        return $out;
    }

    /**
     * 图形验证码出图（SVG）。仓库里没有任何 .ttf，GD 的内置位图字体只有 15px 高，
     * 直接出 PNG 小得人眼都难认；这里把位图字体的每个亮点转成 SVG 方块并放大三倍，
     * 浏览器里想多大就多大，且图里只有几何图形、没有字符本身，脚本抓到图也读不出码文。
     * 缓存里没这张码时直接 404，不画空白图骗人。
     */
    public function captcha(string $key)
    {
        $code = (string)Cache::get('show_capimg_' . $key, '');
        if (!preg_match('/^[0-9a-f]{16}$/', $key) || !preg_match('/^[0-9A-Z]{4}$/', $code)) {
            return response('', 404);
        }
        return response($this->captchaSvg($code), 200, [
            'Content-Type'  => 'image/svg+xml',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 拼验证码 SVG：底框 + 两条干扰线 + 噪声点 + 四个带旋转的字符。
     * 码只从缓存取、绝不进 URL；颜色值全部来自本方法内的白名单常量。
     */
    protected function captchaSvg(string $code): string
    {
        // 浅色版：噪声用站内边框级灰，字符用站内文本级深色
        $noise = ['#c9d3de', '#d8dfe8', '#b7c2d0', '#c3cede'];
        $paint = ['#17233d', '#0f766e', '#3d4a63', '#0b5d57'];

        $svg = '<rect x="0.5" y="0.5" width="139" height="43" rx="6" fill="#f7f8fa" stroke="#d3dae3"/>';
        $svg .= '<line x1="0" y1="' . random_int(8, 36) . '" x2="140" y2="' . random_int(6, 38)
            . '" stroke="' . $noise[array_rand($noise)] . '" stroke-width="1.5" opacity="0.9"/>';
        $svg .= '<path d="M0 ' . random_int(18, 34) . ' Q35 ' . random_int(0, 14) . ' 70 '
            . random_int(16, 32) . ' T140 ' . random_int(10, 34)
            . '" fill="none" stroke="' . $noise[array_rand($noise)] . '" stroke-width="1.2"/>';
        for ($i = 0; $i < random_int(22, 30); $i++) {
            $svg .= '<circle cx="' . random_int(2, 138) . '" cy="' . random_int(2, 42)
                . '" r="' . (mt_rand(5, 12) / 10) . '" fill="' . $noise[array_rand($noise)]
                . '" opacity="' . (random_int(45, 80) / 100) . '"/>';
        }

        $cx = 6;
        for ($i = 0; $i < 4; $i++) {
            $glyph = $this->glyphBlocks($code[$i], 3);
            if ($glyph === null) {
                $cx += 24;
                continue;
            }
            [$blocks, $gw, $gh] = $glyph;
            $cy  = random_int(2, max(3, 42 - $gh));
            $rot = random_int(-10, 10);
            $svg .= '<g fill="' . $paint[$i] . '" transform="translate(' . $cx . ',' . $cy
                . ') rotate(' . $rot . ',' . ($gw / 2) . ',' . ($gh / 2) . ')">' . $blocks . '</g>';
            $cx += $gw + random_int(3, 8);
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="44" viewBox="0 0 140 44"'
            . ' role="img" aria-label="' . htmlspecialchars('图形验证码：输入图中的四个字符', ENT_QUOTES)
            . '">' . $svg . '</svg>';
    }

    /**
     * 用 GD 内置 5 号位图字体画一个字符，再把它每个亮点转成 $scale 倍大的 SVG 方块，
     * 四周空白裁掉。返回 [方块串, 宽, 高]；字符在字体里没有笔画时返回 null。
     *
     * @return array{0:string,1:int,2:int}|null
     */
    protected function glyphBlocks(string $ch, int $scale): ?array
    {
        $fw = imagefontwidth(5);
        $fh = imagefontheight(5);
        $im = imagecreatetruecolor($fw, $fh);
        imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
        // 内置位图字体只认 imagestring；imagecharup 在这套 GD 上画不出笔画
        imagestring($im, 5, 0, 0, $ch, imagecolorallocate($im, 255, 255, 255));

        $lit  = [];
        $minX = $minY = 999;
        $maxX = $maxY = -1;
        for ($y = 0; $y < $fh; $y++) {
            for ($x = 0; $x < $fw; $x++) {
                $p = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                if ($p['red'] < 110) {
                    continue;
                }
                $lit[] = [$x, $y];
                $minX = min($minX, $x); $maxX = max($maxX, $x);
                $minY = min($minY, $y); $maxY = max($maxY, $y);
            }
        }
        imagedestroy($im);
        if ($lit === []) {
            return null;
        }

        $dot = $scale - 0.3;
        $b   = '';
        foreach ($lit as $xy) {
            $b .= '<rect x="' . (($xy[0] - $minX) * $scale) . '" y="' . (($xy[1] - $minY) * $scale)
                . '" width="' . $dot . '" height="' . $dot . '"/>';
        }
        return [$b, ($maxX - $minX + 1) * $scale, ($maxY - $minY + 1) * $scale];
    }

    /**
     * 访客提交（内容投稿 / 入队意向）
     * POST /api/show/submit
     */
    public function submit()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $tid = TenantContext::id();
        if (!Show::visible($tid)) {
            return api_err('该战队当前不接受提交', 404, 404);
        }
        $cfg  = Show::config($tid);
        $type = (string)Request::post('type', 'intent');
        if (!in_array($type, Show::TYPES, true)) {
            return api_err('提交类型不正确');
        }
        if ((int)$cfg['modules'][$type] !== 1) {
            return api_err($type === 'post' ? '该战队当前不接受内容投稿' : '该战队当前不接受入队意向');
        }

        // ① honeypot：真人看不见这个输入框，脚本一律填它。填了就拒，且不说是哪个闸拦的
        if (trim((string)Request::post('website', '')) !== '') {
            return api_err('提交失败，请刷新页面后重试');
        }
        // ② 停留时长：表单里埋了渲染时刻，真人读一页要几秒，脚本通常毫秒级
        $ts = (int)Request::post('t', 0);
        if ($ts <= 0 || time() - $ts < 3 || time() - $ts > 3600) {
            return api_err('页面停留时间太短或太久，请重新填写', 400);
        }
        // ③ 图形码：同一个 IP 连错 8 次就锁 15 分钟，别让人拿字典磨验证码
        $ip     = client_ip();
        $capKey = (string)Request::post('captcha_key', '');
        $capIn  = (string)Request::post('captcha', '');
        if ((int)Cache::get('show_capfail_' . md5($ip), 0) >= 8) {
            return api_err('验证码连续错误过多，请 15 分钟后再试', 429, 429);
        }
        if (!Show::captchaCheck($capKey, $capIn)) {
            rate_bump('show_capfail_' . md5($ip), 900);
            return api_err('验证码不正确', 400);
        }
        // ④ 频率闸（与注册限流同口径：同 IP 每小时 3 次、每天 10 次）
        if ($block = Show::gateSubmit($tid, $ip)) {
            return api_err($block, 429, 429);
        }

        $qq   = preg_replace('/\D/', '', (string)Request::post('qq', ''));
        $nick = mb_substr(trim((string)Request::post('nick', '')), 0, 32);
        $pos  = mb_substr(trim((string)Request::post('position', '')), 0, 32);
        $text = mb_substr(trim((string)Request::post('content', '')), 0, self::CONTENT_MAX);

        if ($text === '') {
            return api_err($type === 'post' ? '请填写投稿内容' : '请写一两句自我介绍');
        }
        if ($nick === '') {
            return api_err('请填写游戏昵称');
        }
        if ($type === 'intent') {
            if (!is_valid_qq($qq)) {
                return api_err('请填写正确的 QQ 号，队长要用它联系你');
            }
            // 页面上真挂了岗位才出这个下拉：没挂时允许留空，但也不能凭空报一个不存在的岗位
            $allow   = array_column($cfg['positions'], 'name');
            $allow[] = '其他';
            if ($pos === '') {
                if ($cfg['positions'] !== []) {
                    return api_err('请选择意向岗位');
                }
            } elseif (!in_array($pos, $allow, true)) {
                return api_err('请选择意向岗位');
            }
        }

        // ⑤ 公开页内容先过平台敏感词再落库（命中打星号）：
        //    审核通过后这条就直接上互联网了，不能等上墙之后再洗
        $text = BotText::filter($text);
        $nick = BotText::filter($nick);
        $pos  = $pos !== '' ? BotText::filter($pos) : '';

        // ⑤' 投稿附图（可选）；驳回或裁剪时由 ShowcaseAdmin 与 trimSubmissions() 删文件
        $image = '';
        if ($type === 'post' && Request::file('image')) {
            [$image, $imgErr] = ImageUpload::save(Request::file('image'), 'showsub');
            if ($imgErr !== null) {
                return api_err($imgErr);
            }
            $image = (string)$image;
        }

        $id = (int)Db::name('show_submissions')->insertGetId([
            'tenant_id'  => $tid,
            'type'       => $type,
            'qq'         => is_valid_qq($qq) ? mb_substr($qq, 0, 16) : '',
            'nick'       => $nick,
            'position'   => $pos,
            'content'    => $text,
            'image'      => $image,
            'status'     => 'pending',
            'ip'         => $ip,
            'region'     => IpRegion::lookup($ip),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Show::trimSubmissions($tid);

        return api_ok(['id' => $id], $type === 'post'
            ? '投稿已收到，队长采纳后会出现在展示页上'
            : '意向已收到。这不代表已入队——请在页面下方的注册入口注册，并通过战队审核后才能登录');
    }

    protected function notFound()
    {
        return response('页面不存在', 404, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
