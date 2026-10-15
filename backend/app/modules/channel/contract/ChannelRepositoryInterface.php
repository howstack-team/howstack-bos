<?php

declare(strict_types=1);

namespace app\modules\channel\contract;

/** 通道配置持久化边界；私密值只在配置校验用例中解密。 */
interface ChannelRepositoryInterface
{
    /**
     * @param string $category 类别
     * @param list<array<string, mixed>> $platforms 本地平台目录
     * @param array<string, mixed> $filters 已验证查询及分页
     * @return array{data: list<array<string, mixed>>, total: int} 合并未配置平台后的列表
     */
    public function catalog(string $category, array $platforms, array $filters): array;

    /**
     * @param string $category 类别
     * @param array<string, mixed> $filters 已验证的组合查询及分页
     * @return array{data: list<array<string, mixed>>, total: int} 完整数据集查询后的分页
     */
    public function list(string $category, array $filters): array;

    /**
     * @param string $category 类别
     * @param int $id 通道标识
     * @return array<string, mixed> 脱敏详情及历史配置声明
     */
    public function find(string $category, int $id): array;

    /**
     * @param string $category 类别
     * @param int $id 通道标识
     * @return array<string, string|int|bool> 内部配置，不得直接输出
     */
    public function configuration(string $category, int $id): array;

    /**
     * @param string $category 类别
     * @param int|null $id 创建为NULL
     * @param array<string, mixed> $data 已验证资料及白名单配置
     * @param array<string, mixed> $platform 已校验的平台声明
     * @return array<string, mixed> 已保存的脱敏详情
     */
    public function save(string $category, ?int $id, array $data, array $platform): array;

    /**
     * @param string $category 类别
     * @param int $id 通道标识
     * @param int $revision 乐观锁版本
     * @param int $status 状态：0禁用、1启用
     * @return array<string, mixed> 已更新详情
     */
    public function status(string $category, int $id, int $revision, int $status): array;

    /**
     * 只归档禁用通道，保留配置及历史身份。
     * @param string $category 类别
     * @param int $id 通道标识
     * @param int $revision 乐观锁版本
     * @return void
     */
    public function archive(string $category, int $id, int $revision): void;
}
