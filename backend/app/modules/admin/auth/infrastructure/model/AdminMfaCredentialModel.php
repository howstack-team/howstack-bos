<?php

declare(strict_types=1);
namespace app\modules\admin\auth\infrastructure\model;
use support\Model;
/** 管理员MFA私有持久化模型，禁止直接序列化到API。 */
final class AdminMfaCredentialModel extends Model
{
    protected $table = 'admin_mfa_credentials';
    protected $primaryKey = 'admin_user_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['admin_user_id', 'secret_encrypted', 'pending_encrypted', 'setup_hash', 'setup_expires_at', 'last_counter', 'recovery_hashes', 'enabled_at', 'created_at', 'updated_at'];
    protected $hidden = ['admin_user_id', 'secret_encrypted', 'pending_encrypted', 'setup_hash', 'setup_expires_at', 'last_counter', 'recovery_hashes', 'enabled_at', 'created_at', 'updated_at'];
    protected $casts = ['admin_user_id' => 'integer', 'last_counter' => 'integer'];
}
