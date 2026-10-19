<?php

declare(strict_types=1);
namespace app\modules\service_instance\infrastructure\model;
use support\Model;
/** 原子付款 Outbox；连接和上下文禁止被默认序列化。 */
final class ProvisioningTaskModel extends Model
{
    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo 首次服务关联，仅核验退款候选 */
    public function refundService(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServiceInstanceModel::class, 'service_id')->whereColumn('service_instances.origin_order_unit_id', 'service_provisioning_tasks.order_unit_id');
    }

    protected $table = 'service_provisioning_tasks';
    public $timestamps = true;
    protected $guarded = ['id'];
    protected $hidden = ['connection_encrypted', 'context', 'lease_token'];
    protected $casts = ['id' => 'integer', 'order_id' => 'integer', 'order_unit_id' => 'integer', 'service_id' => 'integer', 'attempts' => 'integer', 'connection_id' => 'integer', 'context' => 'array', 'addresses' => 'array'];
}
