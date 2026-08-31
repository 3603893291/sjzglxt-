<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class User extends Model
{
    // v4.0.0：多租户行级隔离（查询自动附 tenant_id，写入自动补 tenant_id）
    use \app\model\concern\BelongsToTenant;

    protected $name = 'users';

    // 自动时间戳（datetime 类型，字段名 created_at / updated_at）
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // 密码永不输出到 JSON
    protected $hidden = ['password'];

    // 所属战队
    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }
}
