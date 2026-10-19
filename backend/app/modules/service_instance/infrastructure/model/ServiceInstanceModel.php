<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure\model;

use support\Model;

/** service_instances 的持久化模型；接口输出由仓储显式映射。 */
final class ServiceInstanceModel extends Model
{
    /** @return \Illuminate\Database\Eloquent\Relations\HasOne 首次交付任务 */
    public function provisioningTask(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProvisioningTaskModel::class, 'service_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany 生命周期及提醒去重记录 */
    public function lifecycleTasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LifecycleTaskModel::class, 'service_id');
    }

    protected $table = 'service_instances';

    public $timestamps = true;

    protected $fillable = [
        'instance_no',
        'product_type',
        'member_id',
        'display_name',
        'status',
        'activated_at',
        'expires_at',
        'created_at',
        'updated_at',
        'origin_order_unit_id',
        'power_status', 'power_checked_at', 'installed_image', 'initial_password_disabled_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'installed_image' => 'array',
        'id' => 'integer',
        'instance_no' => 'string',
        'product_type' => 'string',
        'member_id' => 'integer',
        'display_name' => 'string',
        'status' => 'string',
        'activated_at' => 'datetime:Y-m-d H:i:s',
        'expires_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'origin_order_unit_id' => 'integer',
    ];
}
