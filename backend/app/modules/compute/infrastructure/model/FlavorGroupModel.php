<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;

/** compute_flavor_groups 持久化模型；公开输出由仓储白名单映射。 */
final class FlavorGroupModel extends Model
{
    protected $table = 'compute_flavor_groups';
    public $timestamps = true;
    protected $fillable = ['code', 'name', 'description', 'status', 'sort', 'cpu_model', 'base_clock_mhz', 'boost_clock_mhz', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'cpu_model' => 'string',
        'base_clock_mhz' => 'integer',
        'boost_clock_mhz' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
