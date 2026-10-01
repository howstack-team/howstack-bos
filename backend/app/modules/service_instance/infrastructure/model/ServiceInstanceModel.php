<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure\model;

use support\Model;

/** service_instances 的持久化模型；接口输出由仓储显式映射。 */
final class ServiceInstanceModel extends Model
{
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
    ];

    protected $hidden = [];

    protected $casts = [
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
