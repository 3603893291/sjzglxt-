<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class GunCode extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'gun_codes';

    /**
     * 武器类别固定九个（v5.26.0，三角洲行动的枪种口径）。
     * 「全部」只是列表上的筛选项，不入库、也不给创建和编辑用；
     * 之前创建过的枪码不用回填——建表默认值就是 other，界面上显示「未分类」。
     */
    public const CATEGORIES = [
        'other'   => '未分类',
        'smg'     => '冲锋枪',
        'ar'      => '突击步枪',
        'pistol'  => '手枪',
        'special' => '特殊武器',
        'sniper'  => '狙击步枪',
        'dmr'     => '精准射手步枪',
        'lmg'     => '轻机枪',
        'shotgun' => '霰弹枪',
    ];

    /** 给前端的选项（按上面顺序）：筛选条与下拉都从这里出，不再各处抄一份 */
    public static function categoryOptions(): array
    {
        $o = [];
        foreach (self::CATEGORIES as $k => $name) {
            $o[] = ['key' => $k, 'name' => $name];
        }
        return $o;
    }

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
