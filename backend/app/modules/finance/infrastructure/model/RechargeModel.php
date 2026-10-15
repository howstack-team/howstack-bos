<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** recharges 的持久化模型；接口输出由仓储显式映射。 */
final class RechargeModel extends Model
{
    protected $table = 'recharges';

    public $timestamps = true;

    protected $fillable = [
        'external_reference', 'operator_id', 'remark', 'review_reason',
        'recharge_no',
        'member_id',
        'amount_minor',
        'currency',
        'channel',
        'status',
        'paid_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'external_reference' => 'string',
        'operator_id' => 'integer',
        'remark' => 'string',
        'review_reason' => 'string',
        'id' => 'integer',
        'recharge_no' => 'string',
        'member_id' => 'integer',
        'amount_minor' => 'integer',
        'currency' => 'string',
        'channel' => 'string',
        'status' => 'string',
        'paid_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function fundingLot(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WalletFundingLotModel::class, 'recharge_id');
    }
}
