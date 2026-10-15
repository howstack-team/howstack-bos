<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** offers 持久化模型；公开输出由仓储白名单映射。 */
final class OfferModel extends Model
{
    protected $table = 'offers';
    public $timestamps = true;
    protected $fillable = ['product_id', 'code', 'name', 'description', 'status', 'draft', 'revision', 'published_version_id', 'created_at', 'updated_at'];
    protected $hidden = [];
    protected $casts = [
        'id' => 'integer',
        'product_id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'draft' => 'array',
        'revision' => 'integer',
        'published_version_id' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** @return BelongsTo 产品身份；列表显式预加载。 */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }
}
