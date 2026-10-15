<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;

/** compute_image_types 持久化模型；公开输出由仓储白名单映射。 */
final class ImageTypeModel extends Model
{
    protected $table = 'compute_image_types';
    public $timestamps = true;
    protected $fillable = ['code', 'name', 'description', 'status', 'sort', 'icon_url', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'icon_url' => 'string',

        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
