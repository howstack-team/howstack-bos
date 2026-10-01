<?php

declare(strict_types=1);

namespace app\modules\admin\auth\infrastructure\model;

use support\Model;

/** admin_login_logs 的持久化模型；接口输出由仓储显式映射。 */
final class AdminLoginLogModel extends Model
{
    protected $table = 'admin_login_logs';

    public $timestamps = false;

    protected $fillable = [
        'admin_user_id',
        'username',
        'nickname',
        'status',
        'client_ip',
        'client_ip_location',
        'browser',
        'operating_system',
        'failure_reason',
        'logged_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'admin_user_id' => 'integer',
        'username' => 'string',
        'nickname' => 'string',
        'status' => 'integer',
        'client_ip' => 'string',
        'client_ip_location' => 'string',
        'browser' => 'string',
        'operating_system' => 'string',
        'failure_reason' => 'string',
        'logged_at' => 'datetime:Y-m-d H:i:s',
    ];
}
