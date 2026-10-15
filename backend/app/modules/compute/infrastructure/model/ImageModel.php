<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** compute_images 持久化模型；公开输出由仓储白名单映射。 */
final class ImageModel extends Model
{
    protected $table = 'compute_images';
    public $timestamps = true;
    protected $fillable = ['code', 'name', 'description', 'status', 'sort', 'zone_id', 'type_id', 'architecture', 'min_disk_gib', 'login_methods', 'min_cpu', 'min_memory_mib', 'default_username', 'default_port', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'default_username' => 'string',
        'default_port' => 'integer',

        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'zone_id' => 'integer',
        'type_id' => 'integer',
        'architecture' => 'string',
        'min_disk_gib' => 'integer',
        'login_methods' => 'array',
        'min_cpu' => 'integer',
        'min_memory_mib' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
    /** 可复用的资源关联。
     * @return BelongsToMany<ZoneModel, $this> 关联资源
     */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(ZoneModel::class, 'compute_image_zones', 'image_id', 'zone_id');
    }
}
