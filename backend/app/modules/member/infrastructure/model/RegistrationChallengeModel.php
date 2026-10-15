<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;

final class RegistrationChallengeModel extends Model
{
    protected $table = 'member_registration_challenges';
    public $timestamps = false;
    protected $fillable = ['challenge_id', 'method', 'target_hash', 'binding_hash', 'code_hash', 'status', 'attempts', 'expires_at', 'created_at'];
    protected $hidden = ['target_hash', 'binding_hash', 'code_hash'];
    protected $casts = ['attempts' => 'integer'];
}
