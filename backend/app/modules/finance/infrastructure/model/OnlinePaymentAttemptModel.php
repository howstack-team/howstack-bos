<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

final class OnlinePaymentAttemptModel extends Model
{
    protected $table = 'online_payment_attempts';
    protected $primaryKey = 'payment_id';
    public $incrementing = false;
    public $timestamps = true;
    protected $fillable = ['payment_id', 'member_id', 'channel_id', 'channel_revision', 'plugin_id', 'package_hash', 'configuration_encrypted', 'request_key', 'request_hash', 'notify_token', 'notify_url', 'qr_code', 'expires_at', 'reconcile_until', 'lease_token', 'lease_until', 'next_check_at', 'checked_at', 'check_count', 'last_error_code', 'client_ip', 'client_ip_location', 'created_at', 'updated_at'];
    protected $hidden = ['configuration_encrypted', 'notify_token', 'lease_token', 'request_hash'];
    protected $casts = ['payment_id' => 'integer', 'member_id' => 'integer', 'channel_id' => 'integer', 'channel_revision' => 'integer', 'check_count' => 'integer'];
}
