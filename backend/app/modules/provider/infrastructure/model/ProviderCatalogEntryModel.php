<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure\model;

use support\Model;

/** provider_catalog_entries 持久化模型；公开输出由仓储白名单映射。 */
final class ProviderCatalogEntryModel extends Model
{
    protected $table = 'provider_catalog_entries';
    public $timestamps = true;
    protected $fillable = ['connection_id', 'kind', 'external_id', 'name', 'metadata', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'connection_id' => 'integer',
        'kind' => 'string',
        'external_id' => 'string',
        'name' => 'string',
        'metadata' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
