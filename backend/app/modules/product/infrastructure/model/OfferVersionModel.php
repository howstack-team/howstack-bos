<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure\model;

use support\Model;

/** offer_versions 持久化模型；公开输出由仓储白名单映射。 */
final class OfferVersionModel extends Model
{
    protected $table = 'offer_versions';
    public $timestamps = true;
    protected $fillable = ['offer_id', 'version', 'type_version', 'snapshot', 'snapshot_hash', 'published_by', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'offer_id' => 'integer',
        'version' => 'integer',
        'type_version' => 'string',
        'snapshot' => 'array',
        'snapshot_hash' => 'string',
        'published_by' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
