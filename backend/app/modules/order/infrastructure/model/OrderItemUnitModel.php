<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure\model;

use support\Model;

/** order_item_units 的持久化模型；接口输出由仓储显式映射。 */
final class OrderItemUnitModel extends Model
{
    protected $table = 'order_item_units';

    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'unit_index',
        'service_id',
        'allocated_minor',
        'refunded_minor',
        'reserved_refund_minor',
        'created_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'order_item_id' => 'integer',
        'unit_index' => 'integer',
        'service_id' => 'integer',
        'allocated_minor' => 'integer',
        'refunded_minor' => 'integer',
        'reserved_refund_minor' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];
}
