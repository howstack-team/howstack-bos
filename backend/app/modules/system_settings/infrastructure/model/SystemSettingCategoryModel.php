<?php

declare(strict_types=1);

namespace app\modules\system_settings\infrastructure\model;

use support\Model;

/** system_setting_categories 的持久化模型；接口输出由仓储显式映射。 */
final class SystemSettingCategoryModel extends Model
{
    protected $table = 'system_setting_categories';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'sort_order' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
