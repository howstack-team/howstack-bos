<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

use app\shared\plugin\dto\provider\{LifecycleRequest, LifecycleResult};

/** 宿主内部桥接可选生命周期能力，原适配器无需增加空方法。 */
interface ProviderLifecycleAdapterInterface
{
    /** @return bool 入口是否真实实现可选契约 */
    public function supportsLifecycle(): bool;

    /**
     * @param array<string,mixed> $config 原连接
     * @param LifecycleRequest $request 固定请求
     * @param bool $query 仅查询
     * @return LifecycleResult 标准结果
     */
    public function lifecycle(array $config, LifecycleRequest $request, bool $query): LifecycleResult;
}
