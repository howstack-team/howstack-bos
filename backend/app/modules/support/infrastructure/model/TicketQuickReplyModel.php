<?php

declare(strict_types=1);

namespace app\modules\support\infrastructure\model;

use support\Model;

/** ticket_quick_replies 的持久化模型；接口输出由仓储显式映射。 */
final class TicketQuickReplyModel extends Model
{
    protected $table = 'ticket_quick_replies';

    public $timestamps = true;

    protected $fillable = [
        'title',
        'content',
        'sort_order',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'title' => 'string',
        'content' => 'string',
        'sort_order' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
