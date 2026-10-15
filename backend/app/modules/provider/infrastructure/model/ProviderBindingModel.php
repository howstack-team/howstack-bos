<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure\model;

use support\Model;

/** provider_bindings 持久化模型；公开输出由仓储白名单映射。 */
final class ProviderBindingModel extends Model
{
    protected $table = 'provider_bindings';
    public $timestamps = true;
    protected $fillable = ['zone_bindings', 'parameter_mapping', 'offer_version_id', 'connection_id', 'mapping', 'capability_snapshot', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'offer_version_id' => 'integer',
        'connection_id' => 'integer',
        'parameter_mapping' => 'array', 'mapping' => 'array',
        'zone_bindings' => 'array',
        'capability_snapshot' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
