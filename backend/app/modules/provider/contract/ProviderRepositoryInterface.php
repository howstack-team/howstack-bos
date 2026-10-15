<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

/** 供应连接与已读取外部目录的持久化边界。 */
interface ProviderRepositoryInterface
{
    /** @return list<array<string, mixed>> 连接安全资料 */
    public function list(): array;
    /** @param int $id 连接 @return array<string, mixed>|null 安全资料 */
    public function find(int $id): ?array;
    /** @param int $id 连接 @return array<string, string|int|bool> 插件私有调用配置 */
    public function credentials(int $id): array;
    /** @param int|null $id 连接 @param array<string, mixed> $data 已验证字段 @return array<string, mixed> 安全资料 */
    public function save(?int $id, array $data): array;
    /** @param int $id 连接 @param int $revision 版本 @param int $status 启停 @return array<string, mixed> 安全资料 */
    public function setStatus(int $id, int $revision, int $status): array;
    /** @param int $id 连接 @param int $revision 测试开始时版本 @return void 测试成功记录 */
    public function markTested(int $id, int $revision): void;
    /** @param int $id 连接 @param string $kind 目录类型 @param int $revision 连接版本 @param list<array<string, mixed>> $entries 安全目录项 @return void 目录缓存 */
    public function cacheCatalog(int $id, string $kind, int $revision, array $entries): void;
    /** @param int $id 连接 @return list<array<string, mixed>> 映射记录 */
    public function mappings(int $id): array;
    /** @param int $id 连接 @return list<array<string, mixed>> 已读取的安全目录 */
    public function catalogEntries(int $id): array;
    /** @param int $id 连接 @param string $kind 本地资源种类 @param int $localId 本地资源 @param string $externalId 外部标识 @param string $catalogKind 插件声明的目录 @return void 保存映射 */
    public function map(int $id, string $kind, int $localId, string $externalId, string $catalogKind, int $scopeZoneId = 0): void;
}
