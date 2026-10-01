<?php

declare(strict_types=1);

namespace app\modules\system_settings\infrastructure\model;

use support\Model;

/** system_settings 的持久化模型；接口输出由仓储显式映射。 */
final class SystemSettingModel extends Model
{
    protected $table = 'system_settings';

    public $timestamps = true;

    protected $fillable = [
        'setting_key',
        'setting_value',
        'is_public',
        'created_at',
        'updated_at',
        'category_id',
    ];

    protected $hidden = ['setting_value'];

    protected $casts = [
        'id' => 'integer',
        'setting_key' => 'string',
        'setting_value' => 'string',
        'is_public' => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'category_id' => 'integer',
    ];
}
