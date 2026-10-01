<?php

declare(strict_types=1);

namespace app\modules\admin\access\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** admin_users 的持久化模型；接口输出由仓储显式映射。 */
final class AdminUserModel extends Model
{
    protected $table = 'admin_users';

    public $timestamps = true;

    protected $fillable = [
        'username',
        'nickname',
        'employee_no',
        'mobile',
        'email',
        'description',
        'department_id',
        'created_by',
        'is_founder',
        'password_hash',
        'status',
        'last_login_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'id' => 'integer',
        'username' => 'string',
        'nickname' => 'string',
        'employee_no' => 'string',
        'mobile' => 'string',
        'email' => 'string',
        'description' => 'string',
        'department_id' => 'integer',
        'created_by' => 'integer',
        'is_founder' => 'boolean',
        'password_hash' => 'string',
        'status' => 'integer',
        'last_login_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** roleAssignments 关系；列表调用必须显式预加载。 */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(AdminUserRoleModel::class, 'admin_user_id');
    }
}
