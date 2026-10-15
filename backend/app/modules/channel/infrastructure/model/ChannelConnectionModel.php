<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure\model;

use support\Model;

/** 通道连接模型；响应由仓储显式投影，禁止序列化完整模型。 */
final class ChannelConnectionModel extends Model
{
    protected $table = 'channel_connections';
    protected $fillable = [
        'category', 'name', 'plugin_id', 'platform_snapshot', 'secret_keys', 'configuration_encrypted',
        'status', 'sort_order', 'description', 'revision', 'deleted_at',
    ];
    protected $hidden = ['configuration_encrypted'];
    protected $casts = [
        'id' => 'integer', 'status' => 'integer', 'sort_order' => 'integer', 'revision' => 'integer',
        'platform_snapshot' => 'array', 'secret_keys' => 'array', 'deleted_at' => 'datetime',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
    ];
}
