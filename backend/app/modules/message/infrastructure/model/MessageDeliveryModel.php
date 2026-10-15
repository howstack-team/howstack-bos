<?php

declare(strict_types=1);

namespace app\modules\message\infrastructure\model;

use support\Model;

/** 消息模块持久化模型；接口必须通过仓储显式映射。 */
final class MessageDeliveryModel extends Model
{
    protected $table = 'message_deliveries';
    public $timestamps = false;
    protected $fillable = ['recipient_id', 'request_key', 'channel', 'channel_name', 'payload_encrypted', 'status', 'attempts', 'next_attempt_at', 'queue_after', 'lease_key', 'lease_until', 'error_code', 'created_at', 'updated_at'];
    protected $hidden = ['payload_encrypted', 'lease_key'];
    protected $casts = ['recipient_id' => 'integer', 'request_key' => 'string', 'channel' => 'string', 'channel_name' => 'string', 'payload_encrypted' => 'string', 'status' => 'string', 'attempts' => 'integer', 'next_attempt_at' => 'string', 'queue_after' => 'string', 'lease_key' => 'string', 'lease_until' => 'string', 'error_code' => 'string', 'created_at' => 'string', 'updated_at' => 'string'];
}
