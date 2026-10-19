<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 产品身份、销售草稿和不可变发布版本的持久化边界。 */
interface ProductRepositoryInterface
{
    /** @return array{configuration: ?array, choices: list<array>, default_configuration_id: ?int} 只读工作台入口，不自动选择多份历史配置。 */
    public function workspaceConfiguration(): array;

    /** @param int|null $id 明确选择的配置 @param array<string, mixed> $data 已验证草稿 @param int|null $expectedId 页面读取的默认指针 @return array<string, mixed> 在产品锁内保存并选用，避免首次创建竞态。 */
    public function saveWorkspaceConfiguration(?int $id, array $data, ?int $expectedId): array;

    /**
     * @return array{id: int, name: string} 构造时明确限定的产品身份；查询不写库。
     */
    public function fixedProduct(): array;
    /** @return array{total: int, published: int, enabled: int} 当前产品销售配置统计。 */
    public function overview(): array;
    /**
     * @param string $keyword 搜索 @param int $page 页码 @param int $size 条数 @return array{data: list<array<string, mixed>>, total: int} 分页
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        string $keyword,
        int $page,
        int $size,
        array $filters = [],
    ): array;
    /** @param int $id 销售方案 @return array<string, mixed>|null 完整后台详情 */
    public function find(int $id): ?array;
    /** @param int|null $id 方案 @param array<string, mixed> $data 白名单草稿 @return array<string, mixed> 保存结果 */
    public function save(?int $id, array $data): array;
    /** @param int $id 方案 @param int $revision 乐观版本 @param string $typeVersion 类型契约 @param array<string, mixed> $snapshot 配置快照 @param array<string, mixed> $binding 供应绑定 @param int $actorId 操作人 @return array<string, mixed> 发布结果 */
    public function publish(int $id, int $revision, string $typeVersion, array $snapshot, array $binding, int $actorId): array;
    /** @param int $id 方案 @param int $revision 乐观版本 @param int $status 启停 @return array<string, mixed> 更新结果 */
    public function setStatus(int $id, int $revision, int $status): array;
    /** @param int $id 方案 @return list<array<string, mixed>> 不可变历史版本 */
    public function versions(int $id): array;
}
