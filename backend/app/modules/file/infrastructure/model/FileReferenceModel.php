<?php

declare(strict_types=1);

namespace app\modules\file\infrastructure\model;

use Illuminate\Database\Eloquent\Builder;
use support\Model;

/** 联合键为 file_id/scope/owner；修改与删除显式按业务键查询，不按单一主键保存。 */
final class FileReferenceModel extends Model
{
    protected $table = 'file_references';
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['file_id', 'scope', 'owner', 'label', 'expires_at'];
    protected $hidden = [];
    protected $casts = [
        'file_id' => 'string', 'scope' => 'string', 'owner' => 'string',
        'label' => 'string', 'expires_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(static fn (Builder $query): Builder => $query
            ->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s')));
    }
}
