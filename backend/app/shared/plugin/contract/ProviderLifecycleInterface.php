<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\ConnectionContext;
use app\shared\plugin\dto\provider\{LifecycleRequest, LifecycleResult};

/** 可选能力：仅提供全部三种动作的实现才开放自动续费与到期管控。 */
interface ProviderLifecycleInterface
{
    /**
     * @param ConnectionContext $context 首次开通时固定的连接配置
     * @param LifecycleRequest $request 已付款或到期策略产生的请求
     * @return LifecycleResult 受理或确证结果
     */
    public function lifecycle(ConnectionContext $context, LifecycleRequest $request): LifecycleResult;

    /**
     * @param ConnectionContext $context 原连接
     * @param LifecycleRequest $request 原请求，不得重复提交有副作用操作
     * @return LifecycleResult 查询结果；不存在或超时保持 unknown
     */
    public function queryLifecycle(ConnectionContext $context, LifecycleRequest $request): LifecycleResult;
}
