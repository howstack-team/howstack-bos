<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;

final class MemberSessionModel extends Model
{
    protected $table = 'member_sessions';
    public $timestamps = false;
    protected $fillable = ['session_hash', 'member_id', 'password_version', 'expires_at', 'created_at'];
    protected $hidden = ['session_hash', 'password_version'];
    protected $casts = ['member_id' => 'integer'];
}
