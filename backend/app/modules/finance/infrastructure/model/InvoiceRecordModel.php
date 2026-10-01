<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** invoice_records 的持久化模型；接口输出由仓储显式映射。 */
final class InvoiceRecordModel extends Model
{
    protected $table = 'invoice_records';

    public $timestamps = true;

    protected $fillable = [
        'invoice_no',
        'member_id',
        'order_id',
        'red_of_id',
        'amount_minor',
        'currency',
        'invoice_type',
        'title',
        'status',
        'external_number',
        'attachment_path',
        'reviewer_id',
        'reviewed_at',
        'issued_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'invoice_no' => 'string',
        'member_id' => 'integer',
        'order_id' => 'integer',
        'red_of_id' => 'integer',
        'amount_minor' => 'integer',
        'currency' => 'string',
        'invoice_type' => 'string',
        'title' => 'string',
        'status' => 'string',
        'external_number' => 'string',
        'attachment_path' => 'string',
        'reviewer_id' => 'integer',
        'reviewed_at' => 'datetime:Y-m-d H:i:s',
        'issued_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
