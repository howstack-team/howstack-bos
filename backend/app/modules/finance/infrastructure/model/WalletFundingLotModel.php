<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** wallet_funding_lots 的持久化模型；接口输出由仓储显式映射。 */
final class WalletFundingLotModel extends Model
{
    protected $table = 'wallet_funding_lots';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'recharge_id',
        'credited_minor',
        'remaining_minor',
        'created_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'account_id' => 'integer',
        'recharge_id' => 'integer',
        'credited_minor' => 'integer',
        'remaining_minor' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];
}
