<?php

declare(strict_types=1);
namespace app\modules\website\infrastructure\model;
use support\Model;
/** 整套主题单例选择，默认主题用于缺失组件回退。 */
final class SiteThemeBindingModel extends Model
{
    protected $table = 'site_theme_bindings';
    protected $fillable = ['release_id', 'default_release_id', 'mobile_enabled', 'revision'];
    public $timestamps = true;
    protected $hidden = [];
    protected $casts = ['id' => 'integer', 'release_id' => 'integer', 'default_release_id' => 'integer', 'mobile_enabled' => 'boolean', 'revision' => 'integer'];
}
