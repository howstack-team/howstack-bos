<?php

declare(strict_types=1);
namespace app\modules\provider\contract;
use app\shared\plugin\dto\provider\ProvisionResult;
/** 生产调用边界，固定原连接和插件包，不读取当前资源映射。 */
interface ProviderProvisioningGatewayInterface
{
    /** @param array<string,mixed> $binding 封存绑定 @param array<string,mixed> $configuration 标准配置 @return array<string,mixed> 固定且加密的连接 */
    public function capture(array $binding, array $configuration): array;
    /** @param array<string,mixed> $task 已领取任务 @param string $password 初始密码 @return ProvisionResult 受检结果，未知不得重复创建 */
    public function execute(array $task, #[\SensitiveParameter] string $password): ProvisionResult;
}
