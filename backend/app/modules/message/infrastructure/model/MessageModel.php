<?php

declare(strict_types=1);

namespace app\modules\message\infrastructure\model;

use support\Model;

/** 消息模块持久化模型；接口必须通过仓储显式映射。 */
final class MessageModel extends Model
{
    protected $table = 'messages';
    public $timestamps = false;
    protected $fillable = ['event_key', 'scene', 'purpose', 'business_type', 'business_id', 'created_at'];
    protected $hidden = ['payload_encrypted', 'lease_key'];
    protected $casts = ['event_key' => 'string', 'scene' => 'string', 'purpose' => 'string', 'business_type' => 'string', 'business_id' => 'integer', 'created_at' => 'string'];
}
