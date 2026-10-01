<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** payment_transactions 的持久化模型；接口输出由仓储显式映射。 */
final class PaymentTransactionModel extends Model
{
    protected $table = 'payment_transactions';

    public $timestamps = true;

    protected $fillable = [
        'payment_no',
        'member_id',
        'purpose',
        'purpose_id',
        'channel',
        'channel_trade_no',
        'amount_minor',
        'currency',
        'status',
        'paid_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'payment_no' => 'string',
        'member_id' => 'integer',
        'purpose' => 'string',
        'purpose_id' => 'integer',
        'channel' => 'string',
        'channel_trade_no' => 'string',
        'amount_minor' => 'integer',
        'currency' => 'string',
        'status' => 'string',
        'paid_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
