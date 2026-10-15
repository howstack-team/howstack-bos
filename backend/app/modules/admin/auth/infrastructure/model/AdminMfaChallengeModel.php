<?php

declare(strict_types=1);
namespace app\modules\admin\auth\infrastructure\model;
use support\Model;
/** 管理员MFA私有持久化模型，禁止直接序列化到API。 */
final class AdminMfaChallengeModel extends Model
{
    protected $table = 'admin_mfa_challenges';
    protected $primaryKey = 'token_hash';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['token_hash', 'admin_user_id', 'credential_version', 'remember', 'attempts', 'expires_at', 'created_at'];
    protected $hidden = ['token_hash', 'admin_user_id', 'credential_version', 'remember', 'attempts', 'expires_at', 'created_at'];
    protected $casts = ['admin_user_id' => 'integer', 'remember' => 'boolean', 'attempts' => 'integer'];
}
