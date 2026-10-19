<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

use app\shared\plugin\dto\provider\LifecycleResult;

/** 生命周期使用首次供应身份，不重新选择默认连接。 */
interface ProviderLifecycleGatewayInterface
{
    /**
     * @param array<string,mixed> $source 原交付任务
     * @param string $action 核验具体动作，默认为续费
     * @return void 无能力时在付款或提交外呼意图前拒绝
     */
    public function assertSupported(array $source, string $action = 'renew'): void;

    /**
     * @param array<string,mixed> $task 带原连接及固定目标的内部任务
     * @return LifecycleResult 事务外供应结果
     */
    public function execute(array $task): LifecycleResult;
}
