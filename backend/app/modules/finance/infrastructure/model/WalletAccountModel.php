<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** wallet_accounts 的持久化模型；接口输出由仓储显式映射。 */
final class WalletAccountModel extends Model
{
    protected $table = 'wallet_accounts';

    public $timestamps = true;

    protected $fillable = [
        'member_id',
        'currency',
        'fund_type',
        'available_minor',
        'frozen_minor',
        'version',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'member_id' => 'integer',
        'currency' => 'string',
        'fund_type' => 'string',
        'available_minor' => 'integer',
        'frozen_minor' => 'integer',
        'version' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** fundingLots 关系；列表调用必须显式预加载。 */
    public function fundingLots(): HasMany
    {
        return $this->hasMany(WalletFundingLotModel::class, 'account_id');
    }
}
