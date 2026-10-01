<?php

declare(strict_types=1);

namespace app\modules\support\infrastructure\model;

use support\Model;

/** tickets 的持久化模型；接口输出由仓储显式映射。 */
final class TicketModel extends Model
{
    protected $table = 'tickets';

    public $timestamps = true;

    protected $fillable = [
        'ticket_no',
        'member_id',
        'type_id',
        'subject',
        'content',
        'priority',
        'status',
        'created_at',
        'updated_at',
        'closed_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'ticket_no' => 'string',
        'member_id' => 'integer',
        'type_id' => 'integer',
        'subject' => 'string',
        'content' => 'string',
        'priority' => 'string',
        'status' => 'string',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'closed_at' => 'datetime:Y-m-d H:i:s',
    ];
}
