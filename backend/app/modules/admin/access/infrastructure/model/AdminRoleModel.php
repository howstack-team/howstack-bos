<?php

declare(strict_types=1);

namespace app\modules\admin\access\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** admin_roles 的持久化模型；接口输出由仓储显式映射。 */
final class AdminRoleModel extends Model
{
    protected $table = 'admin_roles';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'code',
        'sort_order',
        'description',
        'data_scope',
        'status',
        'is_system',
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
        'data_scope' => 'string',
        'status' => 'integer',
        'is_system' => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** permissions 关系；列表调用必须显式预加载。 */
    public function permissions(): HasMany
    {
        return $this->hasMany(AdminRolePermissionModel::class, 'role_id');
    }

    /** departmentAssignments 关系；列表调用必须显式预加载。 */
    public function departmentAssignments(): HasMany
    {
        return $this->hasMany(AdminRoleDepartmentModel::class, 'role_id');
    }
    /** 同模块授权查询，通过关系表限定管理员。 */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(AdminUserModel::class, 'admin_user_roles', 'role_id', 'admin_user_id');
    }
}
