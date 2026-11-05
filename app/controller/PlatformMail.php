<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\Mailer;
use app\service\MailBlast;
use app\service\Setting;
use think\facade\Db;

/**
 * 平台邮件群发（v5.31.0）：只有平台超级管理员能用。
 *
 *   GET  /api/platform/admin/mail/config        平台自己那一套发信账号 + 限速参数（授权码只回是否已填）
 *   POST /api/platform/admin/mail/config        保存（授权码留空＝不改，填 "CLEAR" 才是清空）
 *   POST /api/platform/admin/mail/recipients    收件分组勾选 → 人数、覆盖几支队、没邮箱几个、三条脱敏抽样
 *   POST /api/platform/admin/mail/render        把 {team} {name} 这类变量换成真人真队名看一眼（取名单里一站长一成员）
 *   POST /api/platform/admin/mail/preview       先给自己发一封：排版对不对、能不能发出去，这一封就是门禁
 *   POST /api/platform/admin/mail/blast         建批（必须先预览过这一份标题+正文）
 *   POST /api/platform/admin/mail/step          队列推进一步（一批 batch 封，每封隔 gap 毫秒）
 *   POST /api/platform/admin/mail/stop          暂停（在飞那封还是发完，只是不再认领新的）
 *   POST /api/platform/admin/mail/resume        继续暂停中的批次
 *   POST /api/platform/admin/mail/requeue       把卡在「已认领」的死行重排回待发（要人明确点）
 *   GET  /api/platform/admin/mail/blasts        历史批次（最近 30 批 + 每批的结果数）
 *   GET  /api/platform/admin/mail/blast         某一批的进度与逐封明细（可按状态筛、翻页）
 *
 * 三条护栏（口径来自需求方当场点的）：
 *   ① 没预览过的正文建不了批次：门禁认的是「这一份标题+正文」，改一个字就得重新预览（30 分钟内有效）。
 *   ② 逐封限速：每批封数与每封间隔是平台配置，不是一口气循环发完；HTTP 一次只推进一步，
 *      页面关了只是暂停，回来接着点就从认领处继续，同一批次同一人只有一行（uk_blast_uid 挡着）。
 *      总开关（p_mail_enabled）关掉后连这一步也不许再推 —— 关就是停了，不是只挡新建。
 *   ③ 整本名单不出服务器：勾选分组时只回人数与脱敏抽样；逐封地址只有这一页的明细列表读得到，
 *      每次读都记一行平台操作日志。战队侧的接口一个都不碰这两张表。
 */
class PlatformMail extends BaseController
{
    /** 明细一页多少行 */
    private const PAGE = 30;
    private const PAGE_MAX = 100;

    /** @return \think\Response|null 不是平台超管就返回这个 403 */
    private function gate()
    {
        if (!function_exists('is_platform_super') || !\is_platform_super()) {
            return api_err('需要平台超级管理员登录态', 403, 403);
        }
        return null;
    }

    private function actor(): array
    {
        $u = \current_platform_user();
        if (!$u) {
            return [0, '平台超管'];
        }
        return [(int)$u->id, mb_substr((string)($u->username ?: $u->nickname ?: '平台超管'), 0, 64)];
    }

    /** 平台侧动作落 admin_logs(tenant_id=0)：谁建的批、谁停的、谁看了明细 */
    private function audit(string $action, string $detail): void
    {
        try {
            [$actorId, $actorName] = $this->actor();
            Db::name('admin_logs')->insert([
                'tenant_id'  => 0,
                'admin_id'   => $actorId,
                'admin_name' => $actorName,
                'action'     => mb_substr($action, 0, 32),
                'detail'     => mb_substr($detail, 0, 300),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 审计写不进去不能反过来把发送做一半
        }
    }

    // ==================== 配置 ====================

    /**
     * GET /api/platform/admin/mail/config
     * 授权码不回明文，只回「有没有填过」：这一页刷新一次就把密码印到前端上是做不到安全的事。
     * 填 CLEAR 才清空，留空保存＝保持原样（跟战队侧那套凭据一个用法）。
     */
    public function config()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $c = Mailer::platformConfig();
        return api_ok([
            'enabled'   => MailBlast::enabled() ? 1 : 0,
            'host'      => $c['smtp_host'],
            'port'      => (int)$c['smtp_port'],
            'user'      => $c['smtp_user'],
            'has_pass'  => $c['smtp_pass'] !== '' ? 1 : 0,
            'from'      => $c['smtp_from'],
            'secure'    => $c['smtp_secure'],
            'secure_effective' => $c['smtp_secure'] !== '' ? $c['smtp_secure'] : ((int)$c['smtp_port'] === 465 ? 'ssl' : 'tls'),
            'preview'   => Setting::getPlatform('p_mail_preview'),
            'batch'     => MailBlast::num('p_mail_batch', MailBlast::BATCH_RANGE[0], MailBlast::BATCH_RANGE[1]),
            'gap'       => MailBlast::num('p_mail_gap_ms', MailBlast::GAP_RANGE[0], MailBlast::GAP_RANGE[1]),
            'max'       => MailBlast::num('p_mail_max', MailBlast::MAX_RANGE[0], MailBlast::MAX_RANGE[1]),
            'ready'     => Mailer::platformConfigured() ? 1 : 0,
            'groups'    => MailBlast::GROUPS,
            'vars'      => MailBlast::VARS,
            'subject_max' => MailBlast::SUBJECT_MAX,
            'body_max'  => MailBlast::BODY_MAX,
        ]);
    }

    /** POST /api/platform/admin/mail/config */
    public function configSave()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $p = $this->request->post();
        $host = trim((string)($p['host'] ?? ''));
        $user = trim((string)($p['user'] ?? ''));
        $port = (int)($p['port'] ?? 465);
        if ($port < 1 || $port > 65535) {
            return api_err('端口号要在 1-65535 之间');
        }
        if ($host !== '' && $user !== '' && !Mailer::isEmail($user)) {
            return api_err('邮箱账号要填完整地址（如 123456@qq.com），信封地址必须与登录账号一致');
        }
        $preview = trim((string)($p['preview'] ?? ''));
        if ($preview !== '' && !Mailer::isEmail($preview)) {
            return api_err('预览收件地址不是一个合法的邮箱');
        }

        Setting::setPlatform('p_mail_enabled', ((string)($p['enabled'] ?? '1')) === '0' ? '0' : '1');
        Setting::setPlatform('p_mail_host', $host);
        Setting::setPlatform('p_mail_port', (string)$port);
        Setting::setPlatform('p_mail_user', $user);
        $pass = Mailer::normalizeSecret((string)($p['pass'] ?? ''));
        if ($pass === 'CLEAR') {
            Setting::setPlatform('p_mail_pass', '');
        } elseif ($pass !== '') {
            Setting::setPlatform('p_mail_pass', $pass);
        }
        Setting::setPlatform('p_mail_from', trim((string)($p['from'] ?? '')));
        $secure = strtolower(trim((string)($p['secure'] ?? '')));
        if (!in_array($secure, ['', 'ssl', 'tls', 'none'], true)) {
            return api_err('加密方式只有 不加密 / SSL / STARTTLS 三种（留空＝按端口猜：465 走 SSL，其它走 STARTTLS）');
        }
        Setting::setPlatform('p_mail_secure', $secure);
        Setting::setPlatform('p_mail_preview', $preview);
        Setting::setPlatform('p_mail_batch', (string)(int)($p['batch'] ?? 8));
        Setting::setPlatform('p_mail_gap_ms', (string)(int)($p['gap'] ?? 300));
        Setting::setPlatform('p_mail_max', (string)(int)($p['max'] ?? 3000));

        $this->audit('邮件群发-配置', '保存平台发信账号 ' . ($host !== '' ? $host . ':' . $port : '（未填服务器）')
            . '，账号 ' . ($user === '' ? '未填' : MailBlast::mask($user))
            . '，加密 ' . ($secure === '' ? '按端口' : $secure));
        return api_ok(['config' => $this->configData()], '已保存');
    }

    /** 保存后回给前端的那一份（授权码依旧不落明文） */
    private function configData(): array
    {
        $c = Mailer::platformConfig();
        return [
            'enabled'  => MailBlast::enabled() ? 1 : 0,
            'host'     => $c['smtp_host'],
            'port'     => (int)$c['smtp_port'],
            'user'     => $c['smtp_user'],
            'has_pass' => $c['smtp_pass'] !== '' ? 1 : 0,
            'from'     => $c['smtp_from'],
            'secure'   => $c['smtp_secure'],
            'secure_effective' => $c['smtp_secure'] !== '' ? $c['smtp_secure'] : ((int)$c['smtp_port'] === 465 ? 'ssl' : 'tls'),
            'preview'  => Setting::getPlatform('p_mail_preview'),
            'batch'    => MailBlast::num('p_mail_batch', MailBlast::BATCH_RANGE[0], MailBlast::BATCH_RANGE[1]),
            'gap'      => MailBlast::num('p_mail_gap_ms', MailBlast::GAP_RANGE[0], MailBlast::GAP_RANGE[1]),
            'max'      => MailBlast::num('p_mail_max', MailBlast::MAX_RANGE[0], MailBlast::MAX_RANGE[1]),
            'ready'    => Mailer::platformConfigured() ? 1 : 0,
        ];
    }

    // ==================== 收件人 ====================

    /** POST /api/platform/admin/mail/recipients {groups, include_banned?} —— 只回人数与脱敏抽样，名单不出服务器 */
    public function recipients()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $groups = MailBlast::normalizeGroups($this->request->post('groups', ''));
        if ($groups === []) {
            return api_err('至少勾一个收件分组');
        }
        $stats = MailBlast::stats($groups, $this->includeBanned());
        $stats['max'] = MailBlast::num('p_mail_max', MailBlast::MAX_RANGE[0], MailBlast::MAX_RANGE[1]);
        $stats['over'] = $stats['total'] > $stats['max'] ? 1 : 0;
        return api_ok($stats);
    }

    /**
     * POST /api/platform/admin/mail/render {subject, body, groups}
     * 把变量换成真人真队名给平台看一眼：取名单里一个站长 + 一个成员，各渲染一份。
     * 这一步不发消息、也不动门禁 —— 门禁认的还是你写的那一份模板（改一个字照样要重新预览）。
     */
    public function renderSamples()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $subject = trim((string)$this->request->post('subject', ''));
        $body    = (string)$this->request->post('body', '');
        $stop = $this->checkDraft($subject, $body);
        if ($stop !== null) {
            return api_err($stop);
        }
        $groups = MailBlast::normalizeGroups($this->request->post('groups', ''));
        if ($groups === []) {
            $groups = array_keys(MailBlast::GROUPS);
        }
        $list = MailBlast::samples($subject, $body, $groups, 2, MailBlast::siteHost(), $this->includeBanned());
        if ($list === []) {
            return api_err('这两组里一个人都没有，拼不出示例（先去上面勾收件分组）');
        }
        return api_ok([
            'list'   => $list,
            'has_vars' => MailBlast::usesVars($subject . "\n" . $body) ? 1 : 0,
            'vars'   => MailBlast::VARS,
        ]);
    }

    // ==================== 预览（门禁） ====================

    /**
     * POST /api/platform/admin/mail/preview {subject, body, groups?, host?, port?, user?, pass?, from?}
     *
     * 表单里的 SMTP 值可以带着未保存就试（第一次配那台账号就靠这一步），没填的那几项回落到已保存的。
     * 这一封发成功才记门禁：标题+正文一模一样才允许建批，中途改了字就得重新预览。
     * 正文开头会加一句「这是预览」—— 免得平台自己收到一封分不清是不是正式那封。
     */
    public function preview()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        if (!MailBlast::enabled()) {
            return api_err('平台邮件群发目前是关闭状态，先在这页打开总开关', 403, 403);
        }
        $subject = trim((string)$this->request->post('subject', ''));
        $body    = (string)$this->request->post('body', '');
        $stop    = $this->checkDraft($subject, $body);
        if ($stop !== null) {
            return api_err($stop);
        }
        [$actorId, $actorName] = $this->actor();
        $to = MailBlast::previewTo();
        if (!Mailer::isEmail($to)) {
            return api_err('预览收件地址还没填：这封要先发到你自己的邮箱看排版（下面「预览收件地址」那一栏）');
        }
        $config = $this->overrideFromPost();
        if ($config['smtp_host'] === '' || $config['smtp_user'] === '' || $config['smtp_pass'] === '') {
            return api_err('平台发信账号没配齐：服务器 / 邮箱账号 / 授权码三项都要填');
        }

        $isHtml = Mailer::looksLikeHtml($body);
        $head   = "这是平台自己看的预览，正式发送不带这一行（收件人看到的是下面这份）\n\n";
        $foot   = $this->previewVarsFoot($subject, $body, $isHtml);
        $err = Mailer::send($to, $subject, ($isHtml
            ? $this->previewHtmlHead() . $body . $foot
            : $head . $body . ($foot === '' ? '' : "\n\n" . $foot)), $config, $isHtml);
        if ($err !== null) {
            $this->audit('邮件群发-预览', '发往 ' . MailBlast::mask($to) . ' 失败：' . MailBlast::cleanError($err, $config));
            return api_err('预览没发出去：' . Mailer::friendlyError(MailBlast::cleanError($err, $config)));
        }
        MailBlast::markPreviewed($actorId, $subject, $body);
        $this->audit('邮件群发-预览', '发往 ' . MailBlast::mask($to) . ' 成功，按' . ($isHtml ? 'HTML' : '纯文本')
            . '发；标题「' . mb_substr($subject, 0, 40) . '」');
        return api_ok([
            'to'      => $to,
            'is_html' => $isHtml ? 1 : 0,
            'format'  => $isHtml ? 'HTML' : '纯文本',
            'tip'     => '预览已发到 ' . $to . '。确认排版没问题再点正式发送——这一份标题与正文在 30 分钟内有效，改一个字都要重新预览。'
                . ($foot === '' ? '' : '你用了变量，所以预览那封末尾多附了一段「换成这个人自己的值之后」的样子，正式发送不带这一栏。'),
        ], '预览已发出，去你自己的邮箱看一眼');
    }

    /** HTML 那一路没法插一句纯文本，就把提示做成最上面一行 */
    private function previewHtmlHead(): string
    {
        return "<div style=\"font-size:12px;color:#888;margin-bottom:12px\">这是平台自己看的预览，"
            . "正式发送不带这一行（收件人看到的是下面这份）</div>\n";
    }

    /**
     * 预览那封末尾附一段「换成真人之后长什么样」（v5.36.31）。
     * 只在正文或标题真的用了 {team} 这类变量时才附，没用变量的那封预览保持原样一个字不多。
     * 这一行只有平台自己收到，正式发送不经过这里。
     */
    private function previewVarsFoot(string $subject, string $body, bool $isHtml): string
    {
        if (!MailBlast::usesVars($subject . "\n" . $body)) {
            return '';
        }
        $groups = MailBlast::normalizeGroups($this->request->post('groups', ''));
        if ($groups === []) {
            $groups = array_keys(MailBlast::GROUPS);
        }
        $list = MailBlast::samples($subject, $body, $groups, 2, MailBlast::siteHost(), $this->includeBanned());
        if ($list === []) {
            return '';
        }
        $one = static function (array $s) use ($isHtml): string {
            $who = $s['who'] === 'owner' ? '站长与管理员' : '普通成员';
            $line1 = '示例：发给「' . $s['name'] . '」（' . $who . ' · ' . $s['team'] . '）';
            $line2 = '标题：' . $s['subject'];
            if ($isHtml) {
                return '<div style="font-size:12px;color:#555;margin-top:6px">'
                    . htmlspecialchars($line1, ENT_QUOTES, 'UTF-8') . '<br />'
                    . htmlspecialchars($line2, ENT_QUOTES, 'UTF-8') . '<br />'
                    . $s['body'] . '</div>';
            }
            return $line1 . "\n" . $line2 . "\n" . $s['body'];
        };
        $parts = array_map($one, $list);
        if ($isHtml) {
            return "\n<div style=\"margin-top:18px;padding:12px 14px;background:#f6f7fb;"
                . "border:1px solid #e3e4ea;border-radius:8px\">"
                . "<div style=\"font-size:12px;color:#888;margin-bottom:6px\">"
                . "这一段只有预览里有：下面是「变量换成这个人自己的值」之后发出去的样子，正式发送不带这一栏</div>"
                . implode('<hr style="border:none;border-top:1px dashed #d5d7e0;margin:10px 0" />', $parts)
                . '</div>';
        }
        return "———————————————\n这一段只有预览里有：下面是「变量换成这个人自己的值」之后发出去的样子，正式发送不带这一栏\n\n"
            . implode("\n\n———————————————\n\n", $parts);
    }

    // ==================== 建批与队列 ====================

    /** POST /api/platform/admin/mail/blast {subject, body, groups} */
    public function blast()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        if (!MailBlast::enabled()) {
            return api_err('平台邮件群发目前是关闭状态，先在这页打开总开关', 403, 403);
        }
        if (!Mailer::platformConfigured()) {
            return api_err('平台发信账号没配齐：服务器 / 邮箱账号 / 授权码三项都要填好并保存');
        }
        $subject = trim((string)$this->request->post('subject', ''));
        $body    = (string)$this->request->post('body', '');
        $groups  = MailBlast::normalizeGroups($this->request->post('groups', ''));
        $stop = $this->checkDraft($subject, $body);
        if ($stop !== null) {
            return api_err($stop);
        }
        if ($groups === []) {
            return api_err('至少勾一个收件分组');
        }
        [$actorId, $actorName] = $this->actor();
        if (!MailBlast::previewedBefore($actorId, $subject, $body)) {
            return api_err('这一份还没预览过：先点【先发一封给我看】，在自己的邮箱里确认排版，再回来点正式发送');
        }

        $includeBanned = $this->includeBanned();
        $stats = MailBlast::stats($groups, $includeBanned);
        $max   = MailBlast::num('p_mail_max', MailBlast::MAX_RANGE[0], MailBlast::MAX_RANGE[1]);
        if ($stats['total'] === 0) {
            return api_err('这两组里一个人都没有（要么站点都还没上人，要么勾的分组为空）');
        }
        if ($stats['total'] > $max) {
            return api_err('这批要发 ' . $stats['total'] . ' 人，超过单批上限 ' . $max
                . ' 人：请调低上限或分开发（在这页最下面改）');
        }

        try {
            $blastId = MailBlast::create($groups, $subject, $body, $actorId, $actorName, $includeBanned);
        } catch (\Throwable $e) {
            $this->audit('邮件群发-建批', '失败：' . mb_substr($e->getMessage(), 0, 200));
            return api_err('建批失败：' . mb_substr($e->getMessage(), 0, 160), 500, 500);
        }
        // 门禁一次性：这批已经建出来了，同一份预览不能再建第二个批（免得手滑点两下发两遍）
        MailBlast::clearPreview($actorId, $subject, $body);
        $this->audit('邮件群发-建批', '批次 #' . $blastId . '：' . $stats['total'] . ' 人（站长与管理员 '
            . $stats['owner'] . '、成员 ' . $stats['member'] . '），覆盖 ' . $stats['teams'] . ' 支队，'
            . '没邮箱 ' . $stats['no_mail'] . ' 人'
            . ($includeBanned && $stats['banned_people'] > 0
                ? '，其中被封站点 ' . $stats['banned_teams'] . ' 支 ' . $stats['banned_people'] . ' 人（销毁前告知）' : '')
            . '，标题「' . mb_substr($subject, 0, 40) . '」');
        return api_ok(['blast_id' => $blastId, 'progress' => MailBlast::progress($blastId)], '批次已建好，点「开始发送」就按限速一封封走');
    }

    /** POST /api/platform/admin/mail/step {blast_id} */
    public function step()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        if (!MailBlast::enabled()) {
            // 总开关一关，已经在跑的批次也必须停下来：
            // 这一档是急停用的，不能只挡「新建」而让旧批次照发
            return api_err('平台邮件群发目前是关闭状态，队列不再往下发（要在这页打开总开关）', 403, 403);
        }
        $blastId = (int)$this->request->post('blast_id', 0);
        $row = Db::name('mail_blasts')->where('id', $blastId)->find();
        if (!$row) {
            return api_err('批次不存在', 404, 404);
        }
        if (!Mailer::platformConfigured()) {
            return api_err('平台发信账号没配齐，队列停在原地');
        }
        // 队列每步只发几封，不逐封写操作日志（一批三千封会把日志刷满）；
        // 开跑与跑完这两件各记一行，谁点的、发了多少都能从批次表与明细表对上
        $wasQueued = (string)$row['status'] === 'queued';
        $out = MailBlast::step($blastId);
        if (($out['error'] ?? 0) === 1) {
            return api_err((string)($out['msg'] ?? '这一步没跑成'));
        }
        if ($wasQueued && (int)$out['tried'] > 0) {
            $this->audit('邮件群发-开跑', '批次 #' . $blastId . ' 开始发送，计划 ' . $out['target'] . ' 封');
        }
        if ((string)$out['status'] === 'done' && (string)$row['status'] !== 'done' && (int)$out['tried'] > 0) {
            $this->audit('邮件群发-跑完', '批次 #' . $blastId . ' 成功 ' . $out['sent'] . ' 封，失败 '
                . $out['failed'] . ' 封，跳过 ' . $out['skipped'] . ' 封，站内信 ' . $out['notices'] . ' 条');
        }
        return api_ok($out);
    }

    /** POST /api/platform/admin/mail/stop {blast_id} */
    public function stop()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $blastId = (int)$this->request->post('blast_id', 0);
        if (!MailBlast::stop($blastId)) {
            return api_err('这一批不在待发或发送中，暂停不了');
        }
        $this->audit('邮件群发-暂停', '批次 #' . $blastId . ' 暂停（在飞的那封仍会发完）');
        return api_ok(['progress' => MailBlast::progress($blastId)], '已暂停，随时可以继续');
    }

    /** POST /api/platform/admin/mail/resume {blast_id} */
    public function resume()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        if (!MailBlast::enabled()) {
            return api_err('平台邮件群发目前是关闭状态', 403, 403);
        }
        $blastId = (int)$this->request->post('blast_id', 0);
        if (!MailBlast::resume($blastId)) {
            return api_err('这一批不是暂停状态');
        }
        $this->audit('邮件群发-继续', '批次 #' . $blastId . ' 继续发送');
        return api_ok(['progress' => MailBlast::progress($blastId)], '继续了');
    }

    /** POST /api/platform/admin/mail/requeue {blast_id} —— 卡在「已认领」的死行，要人明确点一下才重排 */
    public function requeue()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $blastId = (int)$this->request->post('blast_id', 0);
        $n = MailBlast::requeueStuck($blastId);
        $this->audit('邮件群发-重排', '批次 #' . $blastId . ' 把 ' . $n . ' 封卡在「已认领」的重新排回待发');
        return api_ok(['moved' => $n, 'progress' => MailBlast::progress($blastId)], $n > 0 ? ('已重排 ' . $n . ' 封') : '没有在飞的行');
    }

    // ==================== 记录与明细 ====================

    /** GET /api/platform/admin/mail/blasts —— 发送记录：最近 30 批 */
    public function blasts()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $rows = Db::name('mail_blasts')->order('id', 'desc')->limit(30)->select()->toArray();
        $list = [];
        foreach ($rows as $b) {
            $id = (int)$b['id'];
            $c  = $this->tally($id);
            $list[] = [
                'id'          => $id,
                'subject'     => (string)$b['subject'],
                'groups'      => str_replace(MailBlast::BANNED_MARK, '', (string)$b['recv_groups']),
                'include_banned' => MailBlast::hadBannedMark((string)$b['recv_groups']) ? 1 : 0,
                'groupNames'  => $this->groupNames((string)$b['recv_groups']),
                'is_html'     => (int)$b['is_html'],
                'target'      => (int)$b['target'],
                'status'      => (string)$b['status'],
                'statusName'  => MailBlast::STATUS[(string)$b['status']] ?? (string)$b['status'],
                'actor'       => (string)$b['actor_name'],
                'created_at'  => (string)$b['created_at'],
                'started_at'  => (string)($b['started_at'] ?? ''),
                'finished_at' => (string)($b['finished_at'] ?? ''),
                'body_len'    => mb_strlen((string)$b['body']),
            ] + $c;
        }
        return api_ok(['list' => $list, 'enabled' => MailBlast::enabled() ? 1 : 0]);
    }

    /**
     * GET /api/platform/admin/mail/blast?blast_id=&status=&page=
     * 进度 + 逐封明细。这一行是全站唯一能看到完整收件地址的接口，所以每次读都记一行日志。
     */
    public function blastDetail()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $blastId = (int)$this->request->get('blast_id', 0);
        $b = Db::name('mail_blasts')->where('id', $blastId)->find();
        if (!$b) {
            return api_err('批次不存在', 404, 404);
        }
        $want  = (string)$this->request->get('status', '');
        $page  = max(1, (int)$this->request->get('page', 1));
        $q = $this->itemQuery($blastId, $want);
        $total = (int)$q->count();
        $size  = min(self::PAGE_MAX, max(1, (int)$this->request->get('size', self::PAGE)));
        $rows  = $this->itemQuery($blastId, $want)->order('id', 'asc')->limit(($page - 1) * $size, $size)->select()->toArray();

        $teams = Db::name('tenants')->column('name', 'id');
        $uids  = array_column($rows, 'uid');
        $users = $uids === [] ? [] : Db::name('users')->whereIn('id', $uids)
            ->column('id,nickname,username,status', 'id');
        $list = [];
        foreach ($rows as $it) {
            $u = $users[(int)$it['uid']] ?? null;
            $list[] = [
                'uid'    => (int)$it['uid'],
                'name'   => $u ? user_display_name($u) : '已注销用户',
                'team'   => (string)($teams[(int)$it['tenant_id']] ?? '未知战队'),
                'email'  => (string)$it['email'],
                'who'    => (string)$it['who'],
                'status' => (string)$it['status'],
                'notice' => (int)$it['notice'],
                'err'    => (string)$it['err'],
                'sent_at' => (string)($it['sent_at'] ?? ''),
            ];
        }
        $this->audit('邮件群发-看明细', '批次 #' . $blastId . ' 第 ' . $page . ' 页（' . count($list) . ' 行，筛 '
            . ($want === '' ? '全部' : $want) . '）');
        return api_ok([
            'blast'    => [
                'id'      => $blastId,
                'subject' => (string)$b['subject'],
                'body'    => (string)$b['body'],
                'is_html' => (int)$b['is_html'],
                'status'  => (string)$b['status'],
                'target'  => (int)$b['target'],
                'created_at' => (string)$b['created_at'],
            ],
            'progress' => MailBlast::progress($blastId),
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'size'     => $size,
        ]);
    }

    // ==================== 私有 ====================

    /**
     * 明细查询：筛状态只认这五个值（别的串一律当「不筛」），
     * 数总数与取行各建一次 —— 同一个 Query 对象查完再取会踩 ThinkPHP 复用 options 的坑。
     */
    private function itemQuery(int $blastId, string $want)
    {
        $q = Db::name('mail_blast_items')->where('blast_id', $blastId);
        if (in_array($want, ['pending', 'sending', 'sent', 'failed', 'skipped'], true)) {
            $q->where('status', $want);
        }
        return $q;
    }

    /** 每批的状态计数（列表页一行一批，一次查一小组） */
    private function tally(int $blastId): array
    {
        $rows = Db::name('mail_blast_items')->where('blast_id', $blastId)
            ->field('status, COUNT(*) AS n')->group('status')->select()->toArray();
        $by = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            $k = (string)$r['status'];
            if (isset($by[$k])) { $by[$k] = (int)$r['n']; }
        }
        return [
            'n_pending' => $by['pending'],
            'n_sending' => $by['sending'],
            'n_sent'    => $by['sent'],
            'n_failed'  => $by['failed'],
            'n_skipped' => $by['skipped'],
            'n_notice'  => (int)Db::name('mail_blast_items')->where('blast_id', $blastId)->where('notice', 1)->count(),
        ];
    }

    private function groupNames(string $csv): string
    {
        $out = [];
        foreach (MailBlast::normalizeGroups($csv) as $g) {
            $out[] = MailBlast::GROUPS[$g];
        }
        return implode(' + ', $out);
    }

    /**
     * 这颗勾只认 1：销毁前的告知是唯一会带上被封站点的路，默认必须是不带。
     * 数据范围类的开关不接受「非空就算开」，否则前端多传个空串就把口径改了。
     */
    private function includeBanned(): bool
    {
        return (string)$this->request->post('include_banned', '0') === '1';
    }

    /** 标题与正文的形：预览与建批共用同一套校验，别让两边各写一遍 */
    private function checkDraft(string $subject, string $body): ?string
    {
        if ($subject === '') {
            return '邮件标题不能空着';
        }
        if (mb_strlen($subject) > MailBlast::SUBJECT_MAX) {
            return '标题最长 ' . MailBlast::SUBJECT_MAX . ' 字，现在 ' . mb_strlen($subject) . ' 字';
        }
        if (trim($body) === '') {
            return '正文不能空着';
        }
        if (mb_strlen($body) > MailBlast::BODY_MAX) {
            return '正文最长 ' . MailBlast::BODY_MAX . ' 字，现在 ' . mb_strlen($body) . ' 字';
        }
        return null;
    }

    /**
     * 表单里的 SMTP 值覆盖到已保存的那一份上（只有填了才覆盖）——
     * 第一次配平台账号时「还没保存就想先看一眼」就靠这个；授权码不回显，留空＝用已保存的。
     */
    private function overrideFromPost(): array
    {
        $p    = $this->request->post();
        $cfg  = Mailer::platformConfig();
        $pick = static function (string $k, string $fallback) use ($p) {
            $v = trim((string)($p[$k] ?? ''));
            return $v === '' ? $fallback : $v;
        };
        $cfg['smtp_host'] = $pick('host', $cfg['smtp_host']);
        $port = $pick('port', $cfg['smtp_port']);
        $cfg['smtp_port'] = $port;
        $cfg['smtp_user'] = $pick('user', $cfg['smtp_user']);
        $pass = Mailer::normalizeSecret((string)($p['pass'] ?? ''));
        if ($pass !== '' && $pass !== 'CLEAR') {
            $cfg['smtp_pass'] = $pass;
        }
        $cfg['smtp_from'] = $pick('from', $cfg['smtp_from']);
        $sec = strtolower(trim((string)($p['secure'] ?? '')));
        if (in_array($sec, ['ssl', 'tls', 'none'], true)) {
            $cfg['smtp_secure'] = $sec;
        }
        return $cfg;
    }
}
