<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\DataExport;
use app\service\TenantContext;
use app\service\XlsxWriter;

/**
 * 一键导出表格（v5.34.0）
 *
 * 四张表拉成 Excel 或 CSV：成员名单、成员战绩、签到记录、活动报名。
 * 与「数据迁移」那一页的整站导出是两件事 —— 那一张是把整站搬去另一个站点，要走平台审核、
 * 一天一次；这一张是队长日常拉个表看一眼或发给别人，点了就下。
 *
 * 三条口径（他定的，别改）：
 * ① QQ 永远打码，持有 qq_full 权限也一样 —— 文件是要发出去的，站内视野管不到下载之后；
 * ② 本人的「对外展示 / 战绩公开」开关不影响这里，那是访客页的事；
 * ③ 能导哪几张按现有权限分档，与站内看那张表的权限同一把闸。
 */
class ExportAdmin extends AdminBase
{
    /** 管理端那一页：四张表各是什么、现在这个人能导哪几张、各有几行 */
    public function index()
    {
        $tid = (int)TenantContext::id();
        return api_ok([
            'kinds' => DataExport::summary($tid),
            'cap'   => DataExport::ROW_CAP,
            'note'  => '导出文件里 QQ 号一律打码；一次最多 ' . DataExport::ROW_CAP . ' 行。',
        ]);
    }

    /**
     * 真的那张表：GET /api/admin/export/file?kind=members&fmt=xlsx
     *
     * xlsx 是手写的最小 OOXML（这台机器上没有表格库，也不为这一件事加依赖）；
     * fmt=csv 是同一条数据的兜底格式，Excel 打不开 xlsx 时用那一档。
     */
    public function file()
    {
        $kind = (string)$this->request->get('kind', '');
        $fmt  = strtolower((string)$this->request->get('fmt', 'xlsx'));
        if (!isset(DataExport::KINDS[$kind])) {
            return api_err('没有这张表', 404, 404);
        }
        if ($fmt !== 'xlsx' && $fmt !== 'csv') {
            return api_err('格式只认 xlsx 或 csv', 400, 400);
        }
        if (!DataExport::can($kind)) {
            return api_err('你没有看这张表的权限，也就导不了', 403, 403);
        }

        $tid  = (int)TenantContext::id();
        $data = DataExport::build($kind, $tid);
        if ($data['rows'] === []) {
            return api_err('这张表现在没有数据，没什么可导');
        }

        $label = (string)$data['label'];
        $full  = TenantContext::slug() . '-' . $label . '-' . date('Ymd') . '.' . $fmt;
        // 键名是 ASCII 的，用它当 fallback 文件名：把中文从文件名里抠掉只剩一串横杠，
        // 只认 ASCII 的老实现下载下来就成了「bx1--20261019.xlsx」这种没法认的东西
        $ascii = TenantContext::slug() . '-' . $kind . '-' . date('Ymd') . '.' . $fmt;
        if ($fmt === 'csv') {
            $body = XlsxWriter::csv($data['cols'], $data['rows']);
            $mime = 'text/csv; charset=utf-8';
        } else {
            $body = XlsxWriter::xlsx($label, $data['cols'], $data['rows']);
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        admin_log('数据导出', $label . ' ' . $data['total'] . ' 行（' . $fmt . '）'
            . ($data['capped'] ? '，只落了前 ' . DataExport::ROW_CAP . ' 行' : ''));

        // Content-Type 走 header 而不是 contentType()：框架那个方法固定往后拼「; charset=utf-8」，
        // 传进去的串自己带了 charset 就会拼成两截（浏览器实测看到的 text/csv; charset=utf-8; charset=utf-8 就是这么来的），
        // 而二进制那档压根不该带 charset
        return \think\Response::create($body)
            ->header(['Content-Type' => $mime,
                'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\''
                    . rawurlencode($full),
                'Cache-Control' => 'no-store']);
    }
}
