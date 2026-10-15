<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** compute_lines 持久化模型；公开输出由仓储白名单映射。 */
final class LineModel extends Model
{
    protected $table = 'compute_lines';
    public $timestamps = true;
    protected $attributes = ['test_ip' => ''];
    protected $fillable = ['code', 'name', 'test_ip', 'description', 'status', 'sort', 'zone_id', 'network_configuration', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'network_configuration' => 'array',

        'code' => 'string',
        'name' => 'string',
        'test_ip' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'zone_id' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
    /** 可复用的资源关联。
     * @return BelongsToMany<ZoneModel, $this> 关联资源
     */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(ZoneModel::class, 'compute_line_zones', 'line_id', 'zone_id');
    }
}
