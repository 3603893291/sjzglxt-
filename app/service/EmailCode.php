<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 邮箱验证码（v5.36.34：自助找回密码走这一条路）
 *
 * email_codes 是 v1 就在的一张全局表：只有 email / code / used / expired_at，没有 tenant_id。
 * 这里的口径是「码绑邮箱、人绑战队」——调用方先在当前战队把成员查出来，再按他的 QQ 号算出邮箱，
 * 码只对那个邮箱有效；改哪个账号这件事由调用方在战队作用域里定，本服务不管租户。
 *
 * 一次性：校验通过即置 used=1，同一码兑不出第二次。
 * 只留最新：重新发码时把这邮箱名下还没用的旧码一并作废，避免「三条码都还活着」的窗口。
 * 清账：作废时顺手删掉这个邮箱已经用过的旧码，表不会一直长。
 */
class EmailCode
{
    /** 有效期：与站内其它一次性码（机器人绑定码、巡查令牌）同口径 */
    public const TTL_SEC = 600;

    /** 连点保护：同一个人两次索取之间至少隔这么多秒 */
    public const RESEND_AFTER = 60;

    private static function randomCode(): string
    {
        return (string)random_int(100000, 999999);
    }

    /**
     * 发一张码。
     * @return ?string 码本身（调用方拿去写进邮件）；邮箱不合法或撞了 8 次码没排下来时返回 null
     */
    public static function issue(string $email, int $ttl = self::TTL_SEC): ?string
    {
        if ($email === '' || !Mailer::isEmail($email)) {
            return null;
        }
        $now  = date('Y-m-d H:i:s');
        $exp  = date('Y-m-d H:i:s', time() + max(60, $ttl));
        // 这邮箱名下已经用掉的老码没有取证价值（谁在哪天重置了密码记在 admin_logs 里），顺手删掉
        Db::name('email_codes')->where('email', $email)->where('used', 1)->delete();
        // 还没用的旧码当场作废：不然邮件里躺着三条都还能兑
        Db::name('email_codes')->where('email', $email)->where('used', 0)->update(['used' => 1]);
        for ($i = 0; $i < 8; $i++) {
            $code = self::randomCode();
            $dup  = Db::name('email_codes')
                ->where('email', $email)->where('code', $code)
                ->where('used', 0)->where('expired_at', '>', $now)->find();
            if ($dup) {
                continue;   // 六位数字撞上还在有效期里的上一条，换一张
            }
            Db::name('email_codes')->insert([
                'email'      => $email,
                'code'       => $code,
                'used'       => 0,
                'expired_at' => $exp,
                'created_at' => $now,
            ]);
            return $code;
        }
        return null;
    }

    /**
     * 对码。命中即作废（一次性）。
     * 邮箱没填对、码形如不对、没有未过期的码、码不匹配 —— 一律 false，不区分原因。
     */
    public static function verify(string $email, string $code): bool
    {
        $code = trim($code);
        if ($email === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $row = Db::name('email_codes')
            ->where('email', $email)->where('used', 0)
            ->where('expired_at', '>', date('Y-m-d H:i:s'))
            ->order('id', 'desc')->find();
        if (!$row || !hash_equals((string)$row['code'], $code)) {
            return false;
        }
        Db::name('email_codes')->where('id', (int)$row['id'])->update(['used' => 1]);
        return true;
    }
}
