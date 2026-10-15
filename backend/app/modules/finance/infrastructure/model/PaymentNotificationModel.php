<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure\model;

use support\Model;

/** payment_notifications 的持久化模型；接口输出由仓储显式映射。 */
final class PaymentNotificationModel extends Model
{
    protected $table = 'payment_notifications';

    public $timestamps = false;

    protected $fillable = [
        'client_ip', 'client_ip_location', 'content_hash',
        'channel',
        'notification_id',
        'payment_id',
        'status',
        'received_at',
        'processed_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'channel' => 'string',
        'notification_id' => 'string',
        'payment_id' => 'integer',
        'status' => 'string',
        'received_at' => 'datetime:Y-m-d H:i:s',
        'processed_at' => 'datetime:Y-m-d H:i:s',
    ];
}
