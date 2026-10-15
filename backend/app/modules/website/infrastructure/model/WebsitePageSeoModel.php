<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure\model;

use support\Model;

/** 页面SEO以scope与page_id联合唯一，所有更新通过条件查询完成。 */
final class WebsitePageSeoModel extends Model
{
    protected $table = 'website_page_seo';
    public $incrementing = false;
    public $timestamps = true;
    protected $fillable = ['scope', 'page_id', 'title', 'description', 'keywords', 'indexable'];
    protected $hidden = [];
    protected $casts = ['indexable' => 'boolean'];
}
