<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure\model;

use support\Model;

/** 统一主题管理的持久化记录。 */
final class SiteThemeReleaseModel extends Model
{
    protected $table = 'site_theme_releases';
    protected $fillable = ['theme_id', 'name', 'version', 'digest', 'components', 'installed_by', 'installed_at'];
    public $timestamps = true;
    protected $hidden = [];
    protected $casts = ['id' => 'integer', 'components' => 'array', 'installed_by' => 'integer'];
}
