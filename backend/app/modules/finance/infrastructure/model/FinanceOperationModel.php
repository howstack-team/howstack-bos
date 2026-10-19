<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

final class FinanceOperationModel extends Model
{
    protected $table = 'finance_operations';
    public $timestamps = false;
    protected $fillable = ['client_ip', 'client_ip_location', 'request_key', 'request_hash', 'actor_id', 'resource', 'action', 'result_json', 'created_at'];
    protected $hidden = ['request_hash'];
    protected $casts = ['actor_id' => 'integer'];
}
