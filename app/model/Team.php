<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class Team extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;

    protected $name = 'teams';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // 队员
    public function members()
    {
        return $this->hasMany(User::class, 'team_id', 'id');
    }

    // 队长
    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_id', 'id');
    }
}
