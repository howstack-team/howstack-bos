<?php

declare(strict_types=1);
namespace app\modules\file\contract;

/** 文件记录及引用的统一边界；业务写入在同一数据库事务内同步引用。 */
interface FileRegistryInterface
{
    public function reserve(array $file): void;
    public function mark(string $id, string $status): void;
    public function find(string $id): ?array;
    public function paginate(array $filters): array;
    public function statistics(): array;
    public function references(string $id): array;
    public function syncReferences(string $scope, string $owner, string $label, array $ids, ?string $expiresAt = null): void;
    public function beginDelete(array $ids): array;
    public function finishDelete(string $id, bool $confirmed): void;
}
