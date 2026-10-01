<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** finance_outbox 的持久化模型；接口输出由仓储显式映射。 */
final class FinanceOutboxModel extends Model
{
    protected $table = 'finance_outbox';

    public $timestamps = false;

    protected $fillable = [
        'event_key',
        'event_type',
        'aggregate_id',
        'payload',
        'status',
        'attempts',
        'created_at',
        'processed_at',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'id' => 'integer',
        'event_key' => 'string',
        'event_type' => 'string',
        'aggregate_id' => 'integer',
        'payload' => 'string',
        'status' => 'string',
        'attempts' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'processed_at' => 'datetime:Y-m-d H:i:s',
    ];
}
