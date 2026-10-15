<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** compute_flavors 持久化模型；公开输出由仓储白名单映射。 */
final class FlavorModel extends Model
{
    protected $table = 'compute_flavors';
    public $timestamps = true;
    protected $fillable = ['code', 'name', 'description', 'status', 'sort', 'zone_id', 'group_id', 'cpu', 'memory_mib', 'architecture', 'prices', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'zone_id' => 'integer',
        'group_id' => 'integer',
        'cpu' => 'integer',
        'memory_mib' => 'integer',
        'architecture' => 'string',
        'prices' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
    /** 可复用的资源关联。
     * @return BelongsToMany<ZoneModel, $this> 关联资源
     */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(ZoneModel::class, 'compute_flavor_zones', 'flavor_id', 'zone_id');
    }
}
