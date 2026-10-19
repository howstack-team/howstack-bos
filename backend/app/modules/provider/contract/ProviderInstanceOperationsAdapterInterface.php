<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

use app\shared\plugin\dto\provider\{InstanceOperationRequest, LifecycleResult};

/** 宿主对可选实例运维 SDK 的桥接。 */
interface ProviderInstanceOperationsAdapterInterface
{
    /**
     * @return list<string> 固定能力白名单
     */
    public function instanceOperationActions(): array;

    /**
     * @param array<string,mixed> $config 原连接
     * @param InstanceOperationRequest $request 固定请求
     * @param bool $query 只读查单
     * @return LifecycleResult 明确结果
     */
    public function instanceOperation(array $config, InstanceOperationRequest $request, bool $query): LifecycleResult;
}
