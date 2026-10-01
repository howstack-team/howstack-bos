<?php

declare(strict_types=1);

namespace app\modules\admin\access\infrastructure\model;

use support\Model;

/** admin_departments 的持久化模型；接口输出由仓储显式映射。 */
final class AdminDepartmentModel extends Model
{
    protected $table = 'admin_departments';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'sort_order',
        'description',
        'system_key',
        'parent_id',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'name' => 'string',
        'sort_order' => 'integer',
        'description' => 'string',
        'system_key' => 'string',
        'parent_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
