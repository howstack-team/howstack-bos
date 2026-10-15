<?php

declare(strict_types=1);

namespace app\modules\plugin\infrastructure\model;

use support\Model;

/** 生命周期审计只追加，不通过公开接口修改或删除。 */
final class PluginEventModel extends Model
{
    protected $table = 'business_plugin_events';
    public $timestamps = false;
    protected $fillable = ['plugin_id', 'action', 'from_state', 'to_state', 'actor_id',
        'client_ip', 'client_ip_location', 'package_hash', 'created_at'];
    protected $hidden = [];
    protected $casts = ['id' => 'integer', 'actor_id' => 'integer', 'created_at' => 'datetime:Y-m-d H:i:s'];
}
