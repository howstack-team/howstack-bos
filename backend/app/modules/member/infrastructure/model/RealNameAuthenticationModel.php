<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;

/** member_real_name_authentications 的持久化模型；接口输出由仓储显式映射。 */
final class RealNameAuthenticationModel extends Model
{
    protected $table = 'member_real_name_authentications';

    public $timestamps = true;

    protected $fillable = [
        'member_id',
        'authentication_type',
        'status',
        'subject_name',
        'id_type',
        'id_number_ciphertext',
        'id_number_hash',
        'id_number_masked',
        'company_name',
        'registration_code_ciphertext',
        'registration_code_hash',
        'registration_code_masked',
        'legal_representative',
        'upgrade_from_id',
        'reviewed_by',
        'rejection_reason',
        'submitted_at',
        'reviewed_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = ['id_number_ciphertext', 'id_number_hash', 'registration_code_ciphertext', 'registration_code_hash'];

    protected $casts = [
        'id' => 'integer',
        'member_id' => 'integer',
        'authentication_type' => 'string',
        'status' => 'string',
        'subject_name' => 'string',
        'id_type' => 'string',
        'id_number_ciphertext' => 'string',
        'id_number_hash' => 'string',
        'id_number_masked' => 'string',
        'company_name' => 'string',
        'registration_code_ciphertext' => 'string',
        'registration_code_hash' => 'string',
        'registration_code_masked' => 'string',
        'legal_representative' => 'string',
        'upgrade_from_id' => 'integer',
        'reviewed_by' => 'integer',
        'rejection_reason' => 'string',
        'submitted_at' => 'datetime:Y-m-d H:i:s',
        'reviewed_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
