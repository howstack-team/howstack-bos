<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** withdrawal_requests 的持久化模型；接口输出由仓储显式映射。 */
final class WithdrawalRequestModel extends Model
{
    protected $table = 'withdrawal_requests';

    public $timestamps = true;

    protected $fillable = [
        'withdrawal_no',
        'member_id',
        'source_type',
        'source_id',
        'amount_minor',
        'currency',
        'status',
        'payee_snapshot',
        'reason',
        'reviewer_id',
        'reviewed_at',
        'payout_reference',
        'payout_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = ['payee_snapshot'];

    protected $casts = [
        'id' => 'integer',
        'withdrawal_no' => 'string',
        'member_id' => 'integer',
        'source_type' => 'string',
        'source_id' => 'integer',
        'amount_minor' => 'integer',
        'currency' => 'string',
        'status' => 'string',
        'payee_snapshot' => 'string',
        'reason' => 'string',
        'reviewer_id' => 'integer',
        'reviewed_at' => 'datetime:Y-m-d H:i:s',
        'payout_reference' => 'string',
        'payout_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
