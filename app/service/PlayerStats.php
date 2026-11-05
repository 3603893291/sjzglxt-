<?php
declare(strict_types=1);

namespace app\service;

/**
 * 成员战绩读数助手（v5.11.0）
 *
 * 字段口径的唯一来源是 common.php 的 player_stat_defs()；
 * 本服务负责把它翻译成接口数据：parse 校验成员上传的指标，fields 给前端渲染表头，
 * metrics 给前端取数。上传方（本人）与两处读取方（本人 / 管理员）共用同一份口径。
 */
class PlayerStats
{
    /**
     * 校验上传的 20 项指标。
     *
     * 整行覆盖语义：未提供 / 传 null / 传空串 → 该列置空（一次上传就是一张完整快照，
     * 不做「只改其中一项」的增量更新）。非法值不中断，累积成带中文列名的错误列表一次返回，
     * 便于成员一次就看清所有填错的字段。
     *
     * @return array{0:array,1:string[]} [可落库的列值, 错误信息列表]
     */
    public static function parse(array $input): array
    {
        $values = [];
        $errs   = [];
        foreach (player_stat_defs() as $col => $def) {
            $raw = $input[$col] ?? null;
            if ($raw === null || $raw === '') {
                $values[$col] = null;
                continue;
            }
            if (is_bool($raw) || is_array($raw) || !is_numeric($raw)) {
                $errs[] = $def['label'] . '（' . $col . '）必须是数字';
                continue;
            }
            $num  = (float)$raw;
            $max  = $def['type'] === 'rate' ? 100 : (int)$def['max'];
            $unit = $def['type'] === 'rate' ? '%' : ($def['unit'] ?? '');
            if ($num < 0) {
                $errs[] = $def['label'] . '（' . $col . '）不能为负数';
                continue;
            }
            if ($num > $max) {
                $errs[] = $def['label'] . '（' . $col . '）超出上限 ' . $max . $unit;
                continue;
            }
            $values[$col] = $def['type'] === 'int' ? (int)round($num) : round($num, 2);
        }
        return [$values, $errs];
    }

    /** 指标列名 → 数值（NULL 原样保留，表示该项未同步；PDO 回读的 DECIMAL 是字符串，需归一） */
    public static function metrics($row): array
    {
        $out = [];
        foreach (player_stat_defs() as $col => $def) {
            $v = is_object($row) ? ($row->$col ?? null) : ($row[$col] ?? null);
            if ($v === null || $v === '') {
                $out[$col] = null;
                continue;
            }
            $out[$col] = $def['type'] === 'int' ? (int)$v : round((float)$v, 2);
        }
        return $out;
    }

    /**
     * 字段说明书：中文名 / 小数位 / 取值区间 / 单位后缀。
     * 前端表头与单元格格式直接按它渲染，新增指标不用改前端。
     */
    public static function fields(): array
    {
        $out = [];
        foreach (player_stat_defs() as $col => $def) {
            $isRate = $def['type'] === 'rate';
            $out[] = [
                'key'      => $col,
                'label'    => $def['label'],
                'type'     => $def['type'] === 'int' ? 'int' : 'float',
                'decimals' => player_stat_decimals($def['type']),
                'min'      => 0,
                'max'      => $isRate ? 100 : $def['max'],
                'suffix'   => $isRate ? '%' : ($def['unit'] ?? ''),
                'note'     => $isRate ? '百分比数值，65.5 表示 65.5%' : '',
            ];
        }
        return $out;
    }
}
