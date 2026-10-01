<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** orders 的持久化模型；接口输出由仓储显式映射。 */
final class OrderModel extends Model
{
    protected $table = 'orders';

    public $timestamps = true;

    protected $fillable = [
        'order_no',
        'member_id',
        'member_nickname_snapshot',
        'order_type',
        'status',
        'payment_status',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'payable_minor',
        'paid_minor',
        'refunded_minor',
        'remark',
        'paid_at',
        'completed_at',
        'cancelled_at',
        'created_at',
        'updated_at',
        'member_mobile_snapshot',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'order_no' => 'string',
        'member_id' => 'integer',
        'member_nickname_snapshot' => 'string',
        'order_type' => 'string',
        'status' => 'string',
        'payment_status' => 'string',
        'currency' => 'string',
        'subtotal_minor' => 'integer',
        'discount_minor' => 'integer',
        'payable_minor' => 'integer',
        'paid_minor' => 'integer',
        'refunded_minor' => 'integer',
        'remark' => 'string',
        'paid_at' => 'datetime:Y-m-d H:i:s',
        'completed_at' => 'datetime:Y-m-d H:i:s',
        'cancelled_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'member_mobile_snapshot' => 'string',
    ];

    /** items 关系；列表调用必须显式预加载。 */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItemModel::class, 'order_id');
    }
}
