<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\ConnectionContext;
use app\shared\plugin\dto\provider\{InstanceOperationRequest, LifecycleResult};

/** 可选运维能力；未实现的插件不能展示或受理这些动作。 */
interface ProviderInstanceOperationsInterface
{
    /**
     * @return list<string> 明确支持的标准动作
     */
    public function instanceOperationActions(): array;

    /**
     * @param ConnectionContext $context 原连接
     * @param InstanceOperationRequest $request 固定请求
     * @return LifecycleResult 受理不等于完成
     */
    public function operateInstance(ConnectionContext $context, InstanceOperationRequest $request): LifecycleResult;

    /**
     * @param ConnectionContext $context 原连接
     * @param InstanceOperationRequest $request 原任务标识，不含密码
     * @return LifecycleResult 只能查询，不能重发
     */
    public function queryInstanceOperation(ConnectionContext $context, InstanceOperationRequest $request): LifecycleResult;
}
