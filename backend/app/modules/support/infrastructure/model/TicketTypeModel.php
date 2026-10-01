<?php

declare(strict_types=1);

namespace app\modules\support\infrastructure\model;

use support\Model;

/** ticket_types 的持久化模型；接口输出由仓储显式映射。 */
final class TicketTypeModel extends Model
{
    protected $table = 'ticket_types';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'code',
        'sort_order',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'name' => 'string',
        'code' => 'string',
        'sort_order' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
