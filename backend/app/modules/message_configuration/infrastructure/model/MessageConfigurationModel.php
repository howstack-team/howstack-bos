<?php

declare(strict_types=1);

namespace app\modules\message_configuration\infrastructure\model;

use support\Model;

/** 事件的所有渠道配置共用修订锁，敏感配置整体加密。 */
final class MessageConfigurationModel extends Model
{
    public $timestamps = true;

    protected $table = 'message_configurations';
    protected $fillable = ['audience', 'message_type', 'configuration_encrypted', 'revision'];
    protected $hidden = ['configuration_encrypted'];
    protected $casts = ['id' => 'integer', 'revision' => 'integer'];
}
