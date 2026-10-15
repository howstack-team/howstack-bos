<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;

/** compute_zones 持久化模型；公开输出由仓储白名单映射。 */
final class ZoneModel extends Model
{
    protected $table = 'compute_zones';
    public $timestamps = true;
    protected $fillable = ['code', 'name', 'description', 'status', 'sort', 'region_id', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'region_id' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
