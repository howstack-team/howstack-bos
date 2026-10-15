<?php

declare(strict_types=1);

namespace app\modules\compute\contract;

/** 类型专属资源仓储；返回受控配置数据，不暴露模型。 */
interface ComputeResourceRepositoryInterface
{
    /** @param string $kind 固定资源种类 @return list<array<string, mixed>> 资源记录 */
    public function list(string $kind): array;
    /** @param string $kind 资源种类 @param int $id 内部标识 @return array<string, mixed>|null 资源记录 */
    public function find(string $kind, int $id): ?array;
    /** @param string $kind 资源种类 @param int|null $id 编辑标识 @param array<string, mixed> $data 白名单字段 @return array<string, mixed> 保存结果 */
    public function save(string $kind, ?int $id, array $data): array;
}
