<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure\model;

use support\Model;

/** 分类设置行由迁移预置，写入时加锁保证同类别最多一个默认通道。 */
final class ChannelCategorySettingModel extends Model
{
    protected $table = 'channel_category_settings';
    public $timestamps = false;
    protected $fillable = ['default_channel_id'];
    protected $casts = ['id' => 'integer', 'default_channel_id' => 'integer'];
}
