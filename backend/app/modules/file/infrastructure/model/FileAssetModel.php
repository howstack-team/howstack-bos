<?php

declare(strict_types=1);
namespace app\modules\file\infrastructure\model;
use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FileAssetModel extends Model
{
    protected $table = 'file_assets';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['id','original_name','extension','type','namespace','visibility','origin','mime_type','byte_size','object_key','channel_id','channel_revision','created_by','status','created_at','deleted_at'];
    protected $hidden = ['object_key'];
    protected $casts = ['channel_id' => 'integer', 'channel_revision' => 'integer', 'byte_size' => 'integer', 'created_by' => 'integer'];

    public function activeReferences(): HasMany
    {
        return $this->hasMany(FileReferenceModel::class, 'file_id')->active()
            ->select(['file_id', 'scope', 'owner', 'label'])->orderBy('scope')->orderBy('owner');
    }
}
