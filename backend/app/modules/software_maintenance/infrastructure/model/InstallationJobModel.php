<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\infrastructure\model;

use support\Model;

/** 本机维护任务模型，不通过模型事件隐式执行外部调用。 */
final class InstallationJobModel extends Model
{
    protected $table = 'software_installation_jobs';
    public $timestamps = false;
    protected $fillable = ['request_id', 'kind', 'action', 'target_code', 'version_id', 'from_version', 'target_version',
        'status', 'phase', 'manifest_json', 'message', 'error_code', 'backup_key', 'report_status', 'lease_until',
        'actor_id', 'client_ip', 'client_ip_location', 'created_at', 'updated_at'];
    protected $hidden = ['manifest_json'];
    protected $casts = ['id' => 'integer', 'version_id' => 'integer', 'actor_id' => 'integer'];
}
