<?php

declare(strict_types=1);

namespace app\modules\service_instance\contract;

/** 运维登记和公开状态，执行复用生命周期任务队列。 */
interface InstanceOperationRepositoryInterface
{
    /**
     * @param int $id 服务
     * @return list<array<string,mixed>> 最近二十条公开运维记录
     */
    public function history(int $id): array;

    /**
     * @param int $id 服务
     * @return bool 包含续费和到期处理的任何未完成任务
     */
    public function busy(int $id): bool;

    /**
     * @param int $id 服务
     * @param string $key 身份作用域固定请求键
     * @return array<string,mixed>|null 内部幂等资料
     */
    public function existing(int $id, string $key): ?array;

    /**
     * @param array<string,mixed> $service 已加行锁的服务
     * @param array<string,mixed> $values 核定白名单与短期密文
     * @return array{id:int,status:string} 入队结果
     */
    public function enqueue(array $service, array $values): array;
}
