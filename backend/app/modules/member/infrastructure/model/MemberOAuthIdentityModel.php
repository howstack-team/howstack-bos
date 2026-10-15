<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;

final class MemberOAuthIdentityModel extends Model
{
    protected $table = 'member_oauth_identities';
    protected $primaryKey = 'subject';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['provider', 'app_id', 'subject', 'member_id', 'created_at'];
    protected $hidden = ['subject'];
    protected $casts = ['member_id' => 'integer'];
}
