<?php

declare(strict_types=1);

namespace app\modules\service_instance\infrastructure\model;

use support\Model;

/** 生命周期任务只存稳定身份与执行状态，复用原供应任务的密文和资源映射。 */
final class LifecycleTaskModel extends Model
{
    protected $table = 'service_lifecycle_tasks';
    public $timestamps = true;
    protected $fillable = ['service_id', 'order_id', 'action', 'request_key', 'expected_expires_at', 'target_expires_at',
        'months', 'status', 'submitted_at', 'attempts', 'lease_token', 'lease_until', 'next_attempt_at', 'error_code', 'operation_context', 'password_encrypted', 'password_digest', 'credential_expires_at', 'provider_task_id'];
    protected $hidden = ['lease_token', 'password_encrypted', 'password_digest'];
    protected $casts = ['operation_context' => 'array', 'id' => 'integer', 'service_id' => 'integer', 'order_id' => 'integer', 'months' => 'integer', 'attempts' => 'integer'];
}
