<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;

/** compute_regions 持久化模型；公开输出由仓储白名单映射。 */
final class RegionModel extends Model
{
    protected $table = 'compute_regions';
    public $timestamps = true;
    protected $attributes = ['country_code' => ''];
    protected $fillable = ['code', 'name', 'country_code', 'description', 'status', 'sort', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'country_code' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
