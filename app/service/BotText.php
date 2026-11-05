<?php
declare (strict_types = 1);

namespace app\service;

/**
 * 机器人出站文本风控屏蔽（v5.8.0）
 *
 * QQ 消息触发风控会被吞掉或显示星号井号。这里在发送前主动把敏感词
 * 逐字替换成屏蔽字符（默认 *# 交替，模拟风控打码效果），
 * 牺牲个别词换取整条消息安全送达。
 *
 * 词库 = 内置默认词库 + 平台自定义（平台设置 bot_words，换行/逗号分隔）；
 * 屏蔽字符可在平台设置 bot_mask_char 自定义。
 */
class BotText
{
    /** 单条消息最大长度（QQ 群文本上限较宽，此处保守截断防超限） */
    private const MAX_LEN = 450;

    /** 内置默认词库（涉赌/涉黄/毒枪/广告引流/外挂类高频风控词，可在平台侧追加） */
    private const DEFAULT_WORDS = [
        // 涉赌
        '博彩', '赌场', '赌资', '彩票内部', '快三', 'pk10', '幸运飞艇', '北京赛车', '时时彩', '六合彩',
        // 广告引流 / 灰产
        '代充', '刷单', '兼职日结', '发票代开', '办证刻章', '假身份证', '银行卡四件套',
        // 毒品 / 枪支
        '冰毒', '大麻', '海洛因', '摇头丸', '迷幻药', '枪支出售', '军火', '弹夹', '消音器',
        // 涉黄
        '嫖娼', '卖淫', '援交', '裸聊', '约炮', '一夜情', '色情网站',
        // 资金灰产
        '高利贷', '网贷口子', '信用卡套现', '洗钱', '跑分',
        // 外挂（游戏内高危词）
        '外挂出售', '透视挂', '自瞄挂', '无后座挂', '卡挂',
    ];

    /**
     * 过滤出站文本：敏感词打码 + 控制字符清理 + 超长截断
     */
    public static function filter(string $text): string
    {
        $text = self::maskWords($text);
        if (mb_strlen($text) > self::MAX_LEN) {
            $text = mb_substr($text, 0, self::MAX_LEN) . '……(内容过长已截断)';
        }
        return $text;
    }

    /**
     * 只做「清控制符 + 敏感词打码」，不管长度（v5.30.1 拆出来的）。
     * 战队群与私聊自己有一条长度口径（500 字），要是借 filter() 那条 450 截断，
     * 用户会看见自己发的话莫名其妙短一截，还以为是敏感词吃掉了。
     */
    public static function maskWords(string $text): string
    {
        // 清理不可见控制字符（\n \r \t 保留）
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        foreach (self::words() as $w) {
            if ($w === '') {
                continue;
            }
            $text = str_ireplace($w, self::maskFor($w), $text);
        }
        return trim($text);
    }

    /** 某敏感词对应的等长屏蔽串（屏蔽字符循环取样，默认 *# 交替） */
    private static function maskFor(string $word): string
    {
        $mask = self::maskChars();
        $maskLen = mb_strlen($mask);
        $rep = '';
        $len = mb_strlen($word);
        for ($i = 0; $i < $len; $i++) {
            $rep .= mb_substr($mask, $i % $maskLen, 1);
        }
        return $rep;
    }

    /** 合并后的词库（请求内缓存） */
    private static function words(): array
    {
        static $words = null;
        if ($words === null) {
            $list = self::DEFAULT_WORDS;
            $extra = Setting::getPlatform('bot_words', '');
            foreach (preg_split('/[\n,，;；]+/u', $extra) as $w) {
                $w = trim($w);
                if ($w !== '' && mb_strlen($w) <= 32) {
                    $list[] = $w;
                }
            }
            $words = array_values(array_unique($list));
        }
        return $words;
    }

    /** 屏蔽字符（默认 *#；平台可自定义，如纯 *） */
    private static function maskChars(): string
    {
        $c = trim(Setting::getPlatform('bot_mask_char', ''));
        return $c !== '' ? mb_substr($c, 0, 4) : '*#';
    }
}
