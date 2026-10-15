<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** compute_protections 持久化模型；公开输出由仓储白名单映射。 */
final class ProtectionModel extends Model
{
    protected $table = 'compute_protections';
    public $timestamps = true;
    protected $fillable = ['code', 'name', 'description', 'status', 'sort', 'line_id', 'prices', 'capacity_gbps', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'capacity_gbps' => 'integer',

        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'line_id' => 'integer',
        'prices' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
    /** 可复用的资源关联。
     * @return BelongsToMany<LineModel, $this> 关联资源
     */
    public function lines(): BelongsToMany
    {
        return $this->belongsToMany(LineModel::class, 'compute_protection_lines', 'protection_id', 'line_id');
    }
}
