<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** compute_disk_types 持久化模型；公开输出由仓储白名单映射。 */
final class DiskTypeModel extends Model
{
    protected $table = 'compute_disk_types';
    public $timestamps = true;
    protected $fillable = ['code', 'name', 'description', 'status', 'sort', 'zone_id', 'min_gib', 'max_gib', 'step_gib', 'max_count', 'prices', 'zone_prices', 'read_mbps', 'write_mbps', 'read_iops', 'write_iops', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'zone_id' => 'integer',
        'min_gib' => 'integer',
        'max_gib' => 'integer',
        'step_gib' => 'integer',
        'max_count' => 'integer',
        'prices' => 'array',
        'zone_prices' => 'array',
        'read_mbps' => 'integer',
        'write_mbps' => 'integer',
        'read_iops' => 'integer',
        'write_iops' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
    /** 可复用的资源关联。
     * @return BelongsToMany<ZoneModel, $this> 关联资源
     */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(ZoneModel::class, 'compute_disk_type_zones', 'disk_type_id', 'zone_id');
    }
}
