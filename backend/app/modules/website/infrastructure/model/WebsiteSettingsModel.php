<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure\model;

use support\Model;

/** 官网设置只有 id=1 一条；品牌文案由系统设置提供。 */
final class WebsiteSettingsModel extends Model
{
    protected $table = 'website_settings';
    public $timestamps = true;
    protected $fillable = ['active_release_id', 'settings', 'revision'];
    protected $hidden = [];
    protected $casts = ['id' => 'integer', 'active_release_id' => 'integer', 'settings' => 'array', 'revision' => 'integer'];
}
