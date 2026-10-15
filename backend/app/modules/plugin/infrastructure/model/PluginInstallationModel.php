<?php

declare(strict_types=1);

namespace app\modules\plugin\infrastructure\model;

use support\Model;

/** 安装记录模型；公开资料仍由仓储显式选择，不完整序列化。 */
final class PluginInstallationModel extends Model
{
    protected $table = 'business_plugin_installations';
    public $timestamps = true;
    protected $fillable = ['plugin_id', 'category', 'version', 'package_hash', 'state', 'revision'];
    protected $hidden = [];
    protected $casts = ['id' => 'integer', 'plugin_id' => 'string', 'category' => 'string',
        'version' => 'string', 'package_hash' => 'string', 'state' => 'string', 'revision' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s', 'updated_at' => 'datetime:Y-m-d H:i:s'];
}
