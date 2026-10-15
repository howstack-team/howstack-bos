<?php

declare(strict_types=1);

namespace app\modules\official_platform\infrastructure\model;

use support\Model;

/** 私有连接状态；不使用模型序列化作为公开接口。 */
final class OfficialPlatformStateModel extends Model
{
    protected $table = 'official_platform_state';
    public $timestamps = false;
    protected $fillable = ['id', 'encrypted_state', 'updated_at'];
    protected $hidden = ['encrypted_state'];
    protected $casts = ['id' => 'integer'];
}
