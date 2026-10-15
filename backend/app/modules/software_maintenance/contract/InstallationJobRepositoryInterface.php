<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\contract;

/** 持久执行记录；调用方持有全局安装文件锁，不能并行更改运行代码。 */
interface InstallationJobRepositoryInterface
{
    /**
     * @param array<string,mixed> $job 已授权且已验签的固定目标
     * @return array<string,mixed> 幂等任务
     */
    public function create(array $job): array;

    /** @return list<array<string,mixed>> 最近任务的公开资料 */
    public function recent(): array;

    /** @return list<array<string,mixed>> 最早未回报的应用终态任务，不受页面最近记录数量限制 */
    public function pendingReports(): array;

    /**
     * @param string $id 请求UUID
     * @return array<string,mixed>|null 内部完整资料
     */
    public function find(string $id): ?array;

    /** @return array<string,mixed>|null 未终结任务，恢复时先核对阶段 */
    public function active(): ?array;

    /**
     * @param string $id 请求UUID
     * @param array<string,mixed> $changes 固定执行字段
     * @return void
     */
    public function update(string $id, array $changes): void;
}
