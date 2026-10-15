<?php

declare(strict_types=1);
namespace app\modules\file\infrastructure;

use app\exception\BusinessException;
use app\modules\file\contract\FileRegistryInterface;
use app\modules\file\infrastructure\model\FileAssetModel;
use app\shared\http\ApiErrorCode;
use support\Db;

final readonly class DatabaseFileRegistry implements FileRegistryInterface
{
    public function reserve(array $file): void { FileAssetModel::query()->create($file); }
    public function mark(string $id, string $status): void { FileAssetModel::query()->where('id', $id)->where('status', 'pending')->update(['status' => $status]); }
    public function find(string $id): ?array { $file=FileAssetModel::query()->find($id); return $file?->only(array_keys($file->getAttributes())); }
    private function activeReferences(): \Illuminate\Database\Query\Builder
    {
        return Db::table('file_references')->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s')));
    }
    public function references(string $id): array
    {
        return $this->activeReferences()->where('file_id', $id)->orderBy('scope')->orderBy('owner')->get(['scope', 'owner', 'label'])->map(static fn ($row) => (array) $row)->all();
    }
    public function paginate(array $filters): array
    {
        $query = FileAssetModel::query()->whereNull('deleted_at')->where('visibility', 'public');
        foreach (['type', 'origin', 'status', 'namespace'] as $key) if (($filters[$key] ?? '') !== '') $query->where($key, $filters[$key]);
        if (($filters['name'] ?? '') !== '') {
            $pattern = '%' . strtr($filters['name'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query->whereRaw("original_name LIKE ? ESCAPE '!'", [$pattern]);
        }
        $total = (clone $query)->count();
        $sort = in_array($filters['sort_by'] ?? '', ['byte_size', 'created_at'], true) ? $filters['sort_by'] : 'created_at';
        $order = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $models = $query->orderBy($sort, $order)->orderBy('id', $order)->forPage($filters['page'], $filters['page_size'])->get();
        // 批量读取展示名称；历史文件仍绑定自己的通道和修订，归档通道也保留名称。
        $channelNames = Db::table('channel_connections')->where('category', 'storage')
            ->whereIn('id', $models->pluck('channel_id')->unique()->all())->pluck('name', 'id');
        // 上传身份仍以原管理员ID为准，展示名称按整页批量读取，不排除已禁用账号。
        $uploaders = Db::table('admin_users')->whereIn('id', $models->pluck('created_by')->unique()->all())
            ->get(['id', 'nickname', 'username'])->keyBy('id');
        $rows = $models->map(function (FileAssetModel $model) use ($channelNames, $uploaders): array {
            $row = $model->only(['id', 'original_name', 'extension', 'type', 'mime_type', 'byte_size', 'origin', 'status', 'created_by', 'created_at', 'namespace', 'channel_id', 'channel_revision']);
            $row['channel_name'] = $channelNames->get($model->channel_id);
            $uploader = $uploaders->get($model->created_by);
            $row['created_by_name'] = $uploader === null ? null : (trim($uploader->nickname) ?: trim($uploader->username) ?: null);
            $row['references'] = $this->references($row['id']);
            $row['url'] = $row['status'] === 'stored' ? self::url($row) : null;
            $row['deletable'] = $row['references'] === [] && in_array($row['status'], ['stored', 'failed', 'unknown', 'delete_failed'], true);
            return $row;
        })->all();
        return ['data' => $rows, 'total' => (int) $total, 'page' => $filters['page'], 'page_size' => $filters['page_size']];
    }
    public function statistics(): array
    {
        $groups = FileAssetModel::query()->whereNull('deleted_at')->where('visibility', 'public')
            ->selectRaw("type, COUNT(*) AS file_count, SUM(CASE WHEN status='stored' THEN byte_size ELSE 0 END) AS stored_bytes")
            ->groupBy('type')->get()->keyBy('type');
        $types = [];
        foreach (['image', 'document', 'video', 'audio', 'other'] as $type) {
            $row = $groups->get($type);
            $types[] = ['type' => $type, 'count' => (int) ($row?->file_count ?? 0), 'byte_size' => (int) ($row?->stored_bytes ?? 0)];
        }
        return ['count' => array_sum(array_column($types, 'count')), 'byte_size' => array_sum(array_column($types, 'byte_size')), 'types' => $types];
    }
    public static function url(array $file): string
    {
        return '/api/public/v1/' . match ($file['namespace']) { 'website' => 'website/images/', 'content' => 'content/images/', default => 'files/' } . $file['id'];
    }
    public function syncReferences(string $scope, string $owner, string $label, array $ids, ?string $expiresAt = null): void
    {
        Db::transaction(function () use ($scope, $owner, $label, $ids, $expiresAt): void {
            $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $file = FileAssetModel::query()->where('id', $id)->lockForUpdate()->first();
                if ($file === null) throw new BusinessException('引用的文件不存在，请重新选择素材', ApiErrorCode::VALIDATION_FAILED, 422);
                if ($file->status !== 'stored' || $file->visibility !== 'public' || $file->deleted_at !== null) throw new BusinessException('引用的文件不可用，请重新选择素材', ApiErrorCode::CONFLICT, 409);
            }
            Db::table('file_references')->where('scope', $scope)->where('owner', $owner)->delete();
            foreach ($ids as $id) Db::table('file_references')->insert(['file_id' => $id, 'scope' => $scope, 'owner' => $owner, 'label' => mb_substr($label, 0, 200), 'expires_at' => $expiresAt]);
        });
    }
    public function beginDelete(array $ids): array
    {
        return Db::transaction(function () use ($ids): array {
            sort($ids, SORT_STRING); $files = [];
            foreach ($ids as $id) {
                $file = FileAssetModel::query()->where('id', $id)->lockForUpdate()->first();
                if ($file === null || $file->visibility !== 'public') throw new BusinessException('文件不存在', ApiErrorCode::NOT_FOUND, 404);
                if ($file->deleted_at !== null) continue;
                if (!in_array($file->status, ['stored', 'failed', 'unknown', 'delete_failed'], true)) throw new BusinessException('文件正在处理中，请稍后再试', ApiErrorCode::CONFLICT, 409);
                // 使用当前读，避免多文件删除在等待文件锁后沿用更早的引用快照。
                if ($this->activeReferences()->where('file_id', $id)->lockForUpdate()->first() !== null) throw new BusinessException('文件已被使用，请先解除引用；本次未删除任何文件', ApiErrorCode::CONFLICT, 409);
                $files[] = $file->getAttributes();
                $file->status = 'deleting'; $file->save();
            }
            return $files;
        });
    }
    public function finishDelete(string $id, bool $confirmed): void
    {
        FileAssetModel::query()->where('id', $id)->where('status', 'deleting')->update(['status' => $confirmed ? 'deleted' : 'delete_failed', 'deleted_at' => $confirmed ? date('Y-m-d H:i:s') : null]);
    }
}
