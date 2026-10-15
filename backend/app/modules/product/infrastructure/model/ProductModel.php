<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure\model;

use support\Model;

/** products 持久化模型；公开输出由仓储白名单映射。 */
final class ProductModel extends Model
{
    protected $table = 'products';
    public $timestamps = true;
    protected $fillable = ['name', 'definition_key', 'description', 'workspace_configuration_id', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'workspace_configuration_id' => 'integer',
        'name' => 'string',
        'definition_key' => 'string',
        'description' => 'string',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
