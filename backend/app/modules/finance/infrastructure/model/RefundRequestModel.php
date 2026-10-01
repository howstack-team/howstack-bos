<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** refund_requests 的持久化模型；接口输出由仓储显式映射。 */
final class RefundRequestModel extends Model
{
    protected $table = 'refund_requests';

    public $timestamps = true;

    protected $fillable = [
        'refund_no',
        'member_id',
        'source_type',
        'source_id',
        'payment_id',
        'amount_minor',
        'currency',
        'destination',
        'status',
        'reason',
        'reviewer_id',
        'reviewed_at',
        'completed_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'refund_no' => 'string',
        'member_id' => 'integer',
        'source_type' => 'string',
        'source_id' => 'integer',
        'payment_id' => 'integer',
        'amount_minor' => 'integer',
        'currency' => 'string',
        'destination' => 'string',
        'status' => 'string',
        'reason' => 'string',
        'reviewer_id' => 'integer',
        'reviewed_at' => 'datetime:Y-m-d H:i:s',
        'completed_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
