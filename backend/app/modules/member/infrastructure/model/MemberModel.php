<?php

declare(strict_types=1);

namespace app\modules\member\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** members 的持久化模型；接口输出由仓储显式映射。 */
final class MemberModel extends Model
{
    protected $table = 'members';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'mobile_verified_at',
        'email_verified_at',
        'nickname',
        'group_id',
        'level_id',
        'mobile',
        'email',
        'password_hash',
        'status',
        'description',
        'last_login_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'id' => 'integer',
        'public_id' => 'string',
        'mobile_verified_at' => 'datetime:Y-m-d H:i:s',
        'email_verified_at' => 'datetime:Y-m-d H:i:s',
        'nickname' => 'string',
        'group_id' => 'integer',
        'level_id' => 'integer',
        'mobile' => 'string',
        'email' => 'string',
        'password_hash' => 'string',
        'status' => 'integer',
        'description' => 'string',
        'last_login_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $member): void {
            $member->public_id ??= \app\modules\member\domain\PublicMemberId::generate();
        });
        static::updating(static function (self $member): void {
            if ($member->isDirty('public_id')) throw new \LogicException('公开会员编号不得修改');
            foreach (['mobile', 'email'] as $field) {
                if ($member->isDirty($field)) $member->{$field . '_verified_at'} = null;
            }
        });
    }

    /** group 关系；列表调用必须显式预加载。 */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MemberGroupModel::class, 'group_id');
    }

    /** level 关系；列表调用必须显式预加载。 */
    public function level(): BelongsTo
    {
        return $this->belongsTo(MemberLevelModel::class, 'level_id');
    }
}
