<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** wallet_entries 的持久化模型；接口输出由仓储显式映射。 */
final class WalletEntryModel extends Model
{
    protected $table = 'wallet_entries';

    public $timestamps = false;

    protected $fillable = [
        'entry_no',
        'account_id',
        'member_id',
        'entry_type',
        'source_type',
        'source_id',
        'amount_minor',
        'available_after_minor',
        'created_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'entry_no' => 'string',
        'account_id' => 'integer',
        'member_id' => 'integer',
        'entry_type' => 'string',
        'source_type' => 'string',
        'source_id' => 'integer',
        'amount_minor' => 'integer',
        'available_after_minor' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];
}
