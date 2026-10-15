<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;

final class MemberOAuthPendingModel extends Model
{
    protected $table = 'member_oauth_pending';
    protected $primaryKey = 'ticket_hash';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['ticket_hash', 'binding_hash', 'provider', 'app_id', 'subject', 'channel_id', 'channel_revision', 'remember_me', 'attempts', 'expires_at', 'created_at'];
    protected $hidden = ['ticket_hash', 'binding_hash', 'subject'];
    protected $casts = ['channel_id' => 'integer', 'channel_revision' => 'integer', 'remember_me' => 'boolean', 'attempts' => 'integer'];
}
