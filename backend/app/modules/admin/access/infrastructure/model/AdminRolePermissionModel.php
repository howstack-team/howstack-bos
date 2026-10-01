<?php

declare(strict_types=1);

namespace app\modules\admin\access\infrastructure\model;

use support\Model;

/** admin_role_permissions 的持久化模型；接口输出由仓储显式映射。 */
final class AdminRolePermissionModel extends Model
{
    protected $table = 'admin_role_permissions';

    public $timestamps = false;

    // 关系表使用复合主键，只通过受条件约束的查询读写。
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'role_id',
        'permission_code',
    ];

    protected $hidden = [];

    protected $casts = [
        'role_id' => 'integer',
        'permission_code' => 'string',
    ];
}
