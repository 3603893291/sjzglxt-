<?php
declare (strict_types = 1);

namespace app\service;

use ZipArchive;

/**
 * 表格文件生成（v5.34.0）：手写最小 xlsx + 同一份数据的 CSV
 *
 * 为什么自己写：这台机器上没有 phpspreadsheet（vendor 里只有框架自己那几套），
 * 而他不想再多一个依赖、也不给联网装包 —— xlsx 本身就是一包 XML + zip，够写得出来。
 *
 * 两条护栏：
 * ① CSV 一直是备选格式。xlsx 是按 OOXML 最小集写的，本机没有 Excel 可以真打开验证，
 *    万一他的 Excel/WPS 不认，切 CSV 这一路一定有东西可下载 —— 这条在升级说明里写明白，不藏着。
 * ② 单元格文本来自用户填的昵称与活动标题，所以要清掉 XML 1.0 里根本不合法的字符
 *    （0x00–0x08、0x0B、0x0C、0x0E–0x1F），否则整个包打不开；这跟 XSS 无关，是格式合法性。
 */
class XlsxWriter
{
    /** XML 1.0 不允许出现的控制字符（制表、换行、回车留着） */
    private const ILLEGAL_XML = '/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u';

    /**
     * 生成一份 xlsx 的字节串
     *
     * @param string $sheetName 工作表名（超 31 字符会被截，Excel 不允许更长）
     * @param array  $cols      [['label'=>'场次','width'=>10,'num'=>true], ...]
     * @param array  $rows      每行一个数组，顺序与 $cols 对齐
     */
    public static function xlsx(string $sheetName, array $cols, array $rows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'yysxlsx');
        if ($tmp === false) {
            throw new \RuntimeException('临时文件创建失败，导不出表格');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('打包 xlsx 失败');
        }
        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($cols, $rows));
        $zip->close();

        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** 同一份数据出 CSV：UTF-8 带 BOM，Excel 双击不乱码 */
    public static function csv(array $cols, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn($c) => self::text((string)$c['label']), array_values($cols)));
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $i => $_c) {
                $v    = $r[$i] ?? '';
                $line[] = is_float($v) || is_int($v) ? (string)$v : self::csvCell(self::text((string)$v));
            }
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * CSV 里以 = + - @ 开头的单元格会被 Excel 当公式执行，而昵称/活动标题是用户自己填的
     * —— 前面补一个单引号，看上去还在、但不会被求值。
     */
    private static function csvCell(string $v): string
    {
        if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) {
            return "'" . $v;
        }
        return $v;
    }

    // ---------------- 各个部件 ----------------

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars(self::sheetName($sheetName), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** 两种字体（0 常规、1 加粗给表头）、两种单元格样式，够用 */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function sheet(array $cols, array $rows): string
    {
        $cols  = array_values($cols);
        $xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>';

        if ($cols !== []) {
            $xml .= '<cols>';
            foreach ($cols as $i => $c) {
                $n     = $i + 1;
                $w     = (int)($c['width'] ?? 14) ?: 14;
                $xml  .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData><row r="1">';
        foreach ($cols as $i => $c) {
            $xml .= self::cell(self::ref($i + 1, 1), (string)($c['label'] ?? ''), false, true);
        }
        $xml .= '</row>';

        $r = 1;
        foreach ($rows as $row) {
            $r++;
            $xml .= '<row r="' . $r . '">';
            foreach ($cols as $i => $c) {
                $v       = $row[$i] ?? '';
                $xml    .= self::cell(self::ref($i + 1, $r), $v, !empty($c['num']), false);
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    /** 数字走 <v>，文本走 inlineStr —— 数字让 Excel 能排序、能求和 */
    private static function cell(string $ref, $value, bool $num, bool $bold): string
    {
        if ($num && is_numeric($value)) {
            return '<c r="' . $ref . '"' . ($bold ? ' s="1"' : '') . '><v>' . (0 + $value) . '</v></c>';
        }
        $t = self::text((string)$value);
        if ($t === '') {
            return '<c r="' . $ref . '"' . ($bold ? ' s="1"' : '') . '/>';
        }
        return '<c r="' . $ref . '"' . ($bold ? ' s="1"' : '') . ' t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</t></is></c>';
    }

    /** A1 → 列号 1 行号 1；第 28 列是 AB */
    private static function ref(int $col, int $row): string
    {
        $s = '';
        while ($col > 0) {
            $m = ($col - 1) % 26;
            $s = chr(65 + $m) . $s;
            $col = intdiv($col - 1, 26);
        }
        return $s . $row;
    }

    /** Excel 对工作表名只有三条硬要求：不空、不超 31 字、不含 : \ / ? * [ ] */
    private static function sheetName(string $name): string
    {
        $clean = preg_replace('/[:\\\\\/\?\*\[\]]/u', ' ', $name) ?? 'Sheet';
        $clean = trim((string)$clean);
        if ($clean === '') {
            $clean = 'Sheet';
        }
        return mb_substr($clean, 0, 31);
    }

    private static function text(string $v): string
    {
        $v = preg_replace(self::ILLEGAL_XML, '', $v) ?? '';
        return trim($v);
    }
}
