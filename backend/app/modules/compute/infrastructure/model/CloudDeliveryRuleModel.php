<?php

declare(strict_types=1);

namespace app\modules\compute\infrastructure\model;

use support\Model;

/** 交付配置仅引用连接身份，不保存连接秘密。 */
final class CloudDeliveryRuleModel extends Model
{
    protected $table = 'cloud_delivery_rules';
    public $timestamps = true;
    protected $fillable = ['parameter_mapping', 'product_id', 'name', 'description', 'connection_id', 'line_ids', 'resource_ids', 'priority', 'status', 'revision', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = ['id' => 'integer', 'product_id' => 'integer', 'name' => 'string', 'description' => 'string', 'connection_id' => 'integer',
        'parameter_mapping' => 'array', 'line_ids' => 'array', 'resource_ids' => 'array', 'priority' => 'integer', 'status' => 'integer', 'revision' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s', 'updated_at' => 'datetime:Y-m-d H:i:s'];
}
