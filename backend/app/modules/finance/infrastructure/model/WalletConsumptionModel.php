<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** wallet_consumptions 的持久化模型；接口输出由仓储显式映射。 */
final class WalletConsumptionModel extends Model
{
    protected $table = 'wallet_consumptions';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'funding_lot_id',
        'consumed_minor',
        'restored_minor',
        'created_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'order_id' => 'integer',
        'funding_lot_id' => 'integer',
        'consumed_minor' => 'integer',
        'restored_minor' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];
}
