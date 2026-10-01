<?php

declare(strict_types=1);

namespace app\modules\order\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** order_items 的持久化模型；接口输出由仓储显式映射。 */
final class OrderItemModel extends Model
{
    protected $table = 'order_items';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'line_no',
        'product_type',
        'product_type_version',
        'sales_version',
        'product_name',
        'configuration_snapshot',
        'quantity',
        'unit_price_minor',
        'total_minor',
        'created_at',
    ];

    protected $hidden = ['configuration_snapshot'];

    protected $casts = [
        'id' => 'integer',
        'order_id' => 'integer',
        'line_no' => 'integer',
        'product_type' => 'string',
        'product_type_version' => 'string',
        'sales_version' => 'string',
        'product_name' => 'string',
        'configuration_snapshot' => 'string',
        'quantity' => 'integer',
        'unit_price_minor' => 'integer',
        'total_minor' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** units 关系；列表调用必须显式预加载。 */
    public function units(): HasMany
    {
        return $this->hasMany(OrderItemUnitModel::class, 'order_item_id');
    }
}
