<?php

declare(strict_types=1);

namespace app\modules\message\infrastructure\model;

use support\Model;

/** 消息模块持久化模型；接口必须通过仓储显式映射。 */
final class MessageDeliveryAttemptModel extends Model
{
    protected $table = 'message_delivery_attempts';
    public $timestamps = false;
    protected $fillable = ['delivery_id', 'attempt_no', 'status', 'error_code', 'duration_ms', 'created_at', 'finished_at'];
    protected $hidden = ['payload_encrypted', 'lease_key'];
    protected $casts = ['delivery_id' => 'integer', 'attempt_no' => 'integer', 'status' => 'string', 'error_code' => 'string', 'duration_ms' => 'integer', 'created_at' => 'string', 'finished_at' => 'string'];
}
