<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;

/** 网络能力范围；售价仍在销售方案中维护。 */
final class NetworkPolicyModel extends Model
{
    protected $table = 'compute_network_policies';
    public $timestamps = true;
    protected $fillable = ['code', 'name', 'description', 'status', 'sort', 'bandwidth_min', 'bandwidth_max', 'bandwidth_step', 'ip_min', 'ip_max', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = ['id' => 'integer', 'code' => 'string', 'name' => 'string', 'description' => 'string', 'status' => 'integer', 'sort' => 'integer',
        'bandwidth_min' => 'integer', 'bandwidth_max' => 'integer', 'bandwidth_step' => 'integer', 'ip_min' => 'integer', 'ip_max' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s', 'updated_at' => 'datetime:Y-m-d H:i:s'];
}
