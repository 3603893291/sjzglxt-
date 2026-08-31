<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class VoteOption extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'vote_options';

    protected $autoWriteTimestamp = false;
}
