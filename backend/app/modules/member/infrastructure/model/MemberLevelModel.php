<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;

/** member_levels 的持久化模型；接口输出由仓储显式映射。 */
final class MemberLevelModel extends Model
{
    protected $table = 'member_levels';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'code',
        'level_order',
        'growth_threshold',
        'description',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'name' => 'string',
        'code' => 'string',
        'level_order' => 'integer',
        'growth_threshold' => 'integer',
        'description' => 'string',
        'status' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
