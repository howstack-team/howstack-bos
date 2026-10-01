<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** refund_attempts 的持久化模型；接口输出由仓储显式映射。 */
final class RefundAttemptModel extends Model
{
    protected $table = 'refund_attempts';

    public $timestamps = false;

    protected $fillable = [
        'refund_id',
        'channel',
        'channel_refund_no',
        'status',
        'error_code',
        'created_at',
        'confirmed_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'refund_id' => 'integer',
        'channel' => 'string',
        'channel_refund_no' => 'string',
        'status' => 'string',
        'error_code' => 'string',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'confirmed_at' => 'datetime:Y-m-d H:i:s',
    ];
}
