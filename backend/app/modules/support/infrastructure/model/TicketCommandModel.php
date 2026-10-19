<?php

declare(strict_types=1);

namespace app\modules\support\infrastructure\model;

use support\Model;

/** 工单命令去重，不存储正文或HTTP报文。 */
final class TicketCommandModel extends Model
{
    protected $table = 'ticket_commands';
    public $timestamps = false;
    protected $fillable = ['actor_type', 'actor_id', 'request_key', 'action', 'payload_hash', 'ticket_id', 'message_id', 'created_at'];
    protected $hidden = ['payload_hash', 'request_key'];
    protected $casts = ['id' => 'integer', 'actor_id' => 'integer', 'ticket_id' => 'integer', 'message_id' => 'integer'];
}
