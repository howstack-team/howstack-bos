<?php

declare(strict_types=1);

namespace app\modules\content\infrastructure\model;

use support\Model;

/** content_entries 的持久化模型；接口输出由仓储显式映射。 */
final class ContentEntryModel extends Model
{
    protected $table = 'content_entries';

    public $timestamps = true;

    protected $fillable = [
        'kind',
        'category_id',
        'title',
        'summary',
        'body',
        'sort_order',
        'status',
        'published_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'kind' => 'string',
        'category_id' => 'integer',
        'title' => 'string',
        'summary' => 'string',
        'body' => 'string',
        'sort_order' => 'integer',
        'status' => 'string',
        'published_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
