<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;

/** member_groups 的持久化模型；接口输出由仓储显式映射。 */
final class MemberGroupModel extends Model
{
    protected $table = 'member_groups';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'code',
        'sort_order',
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
        'sort_order' => 'integer',
        'description' => 'string',
        'status' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
