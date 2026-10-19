<?php

declare(strict_types=1);
namespace app\modules\service_instance\infrastructure\model;
use support\Model;
/** 初始密码独立加密保存，永不默认序列化。 */
final class ProvisioningCredentialModel extends Model
{
    protected $table = 'service_provisioning_credentials';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['password_encrypted'];
    protected $casts = ['id' => 'integer', 'order_id' => 'integer'];
}
