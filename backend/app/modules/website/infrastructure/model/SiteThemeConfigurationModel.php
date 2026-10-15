<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure\model;

use support\Model;

/** 统一主题管理的持久化记录。 */
final class SiteThemeConfigurationModel extends Model
{
    protected $table = 'site_theme_configurations';
    protected $fillable = ['release_id', 'component', 'settings', 'revision'];
    public $timestamps = true;
    protected $hidden = [];
    protected $casts = ['id' => 'integer', 'release_id' => 'integer', 'settings' => 'array', 'revision' => 'integer'];
}
