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
