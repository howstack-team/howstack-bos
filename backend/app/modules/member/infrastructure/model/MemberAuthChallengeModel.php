<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;

final class MemberAuthChallengeModel extends Model
{
    protected $table = 'member_auth_challenges';
    protected $primaryKey = 'challenge_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['challenge_id', 'purpose', 'method', 'member_id', 'password_version', 'target_hash',
        'binding_hash', 'code_hash', 'status', 'attempts', 'expires_at', 'created_at'];
    protected $hidden = ['password_version', 'target_hash', 'binding_hash', 'code_hash'];
    protected $casts = ['member_id' => 'integer', 'attempts' => 'integer'];
}
