<?php

declare(strict_types=1);

namespace app\modules\support\infrastructure\model;

use support\Model;

/** ticket_messages 的持久化模型；接口输出由仓储显式映射。 */
final class TicketMessageModel extends Model
{
    protected $table = 'ticket_messages';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'author_type',
        'author_id',
        'content',
        'created_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'ticket_id' => 'integer',
        'author_type' => 'string',
        'author_id' => 'integer',
        'content' => 'string',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];
}
