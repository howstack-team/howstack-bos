<?php

declare(strict_types=1);

namespace app\modules\content\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** content_categories 的持久化模型；接口输出由仓储显式映射。 */
final class ContentCategoryModel extends Model
{
    protected $table = 'content_categories';

    public $timestamps = true;

    protected $fillable = [
        'kind',
        'name',
        'sort_order',
        'status',
        'created_at',
        'updated_at',
        'parent_id',
    ];

    protected $hidden = [];

    protected $casts = [
        'id' => 'integer',
        'kind' => 'string',
        'name' => 'string',
        'sort_order' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'parent_id' => 'integer',
    ];

    /** children 关系；列表调用必须显式预加载。 */
    public function children(): HasMany
    {
        return $this->hasMany(ContentCategoryModel::class, 'parent_id');
    }
}
