<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure\model;

use support\Model;

/** provider_resource_mappings 持久化模型；公开输出由仓储白名单映射。 */
final class ProviderResourceMappingModel extends Model
{
    protected $table = 'provider_resource_mappings';
    public $timestamps = true;
    protected $fillable = ['connection_id', 'resource_kind', 'local_id', 'external_id', 'scope_zone_id', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'connection_id' => 'integer',
        'resource_kind' => 'string',
        'local_id' => 'integer',
        'scope_zone_id' => 'integer',
        'external_id' => 'string',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
