<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure\model;

use support\Model;

/** provider_connections 持久化模型；公开输出由仓储白名单映射。 */
final class ProviderConnectionModel extends Model
{
    protected $table = 'provider_connections';
    public $timestamps = true;
    protected $fillable = ['name', 'adapter_id', 'status', 'capabilities', 'credentials_encrypted', 'tested_at', 'revision', 'created_at', 'updated_at'];
    protected $hidden = ['credentials_encrypted'];
    protected $casts = [
        'id' => 'integer',
        'name' => 'string',
        'adapter_id' => 'string',
        'status' => 'integer',
        'capabilities' => 'array',
        'credentials_encrypted' => 'string',
        'tested_at' => 'datetime:Y-m-d H:i:s',
        'revision' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
