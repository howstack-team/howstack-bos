<?php

declare(strict_types=1);

namespace app\modules\admin\access\infrastructure\model;

use support\Model;

/** admin_user_roles 的持久化模型；接口输出由仓储显式映射。 */
final class AdminUserRoleModel extends Model
{
    protected $table = 'admin_user_roles';

    public $timestamps = false;

    // 关系表使用复合主键，只通过受条件约束的查询读写。
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'admin_user_id',
        'role_id',
    ];

    protected $hidden = [];

    protected $casts = [
        'admin_user_id' => 'integer',
        'role_id' => 'integer',
    ];
}
