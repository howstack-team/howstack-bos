<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** invoice_allocations 的持久化模型；接口输出由仓储显式映射。 */
final class InvoiceAllocationModel extends Model
{
    protected $table = 'invoice_allocations';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'order_unit_id',
        'amount_minor',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'invoice_id' => 'integer',
        'order_unit_id' => 'integer',
        'amount_minor' => 'integer',
    ];
}
