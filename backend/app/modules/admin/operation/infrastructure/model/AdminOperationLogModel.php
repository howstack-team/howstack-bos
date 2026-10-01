<?php

declare(strict_types=1);

namespace app\modules\admin\operation\infrastructure\model;

use support\Model;

/** admin_operation_logs 的持久化模型；接口输出由仓储显式映射。 */
final class AdminOperationLogModel extends Model
{
    protected $table = 'admin_operation_logs';

    public $timestamps = false;

    protected $fillable = [
        'admin_user_id',
        'operator_username',
        'operator_nickname',
        'module',
        'operation',
        'method',
        'request_url',
        'status',
        'http_status',
        'client_ip',
        'client_ip_location',
        'duration_ms',
        'browser',
        'operating_system',
        'request_id',
        'request_params',
        'operated_at',
    ];

    protected $hidden = ['request_params'];

    protected $casts = [
        'id' => 'integer',
        'admin_user_id' => 'integer',
        'operator_username' => 'string',
        'operator_nickname' => 'string',
        'module' => 'string',
        'operation' => 'string',
        'method' => 'string',
        'request_url' => 'string',
        'status' => 'integer',
        'http_status' => 'integer',
        'client_ip' => 'string',
        'client_ip_location' => 'string',
        'duration_ms' => 'integer',
        'browser' => 'string',
        'operating_system' => 'string',
        'request_id' => 'string',
        'request_params' => 'string',
        'operated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
