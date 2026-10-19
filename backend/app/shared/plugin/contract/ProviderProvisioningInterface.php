<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\ConnectionContext;
use app\shared\plugin\dto\provider\{ProvisionRequest, ProvisionResult};

/** 可选的生产契约；清单声明生产能力时必须实现，查询不得创建资源。 */
interface ProviderProvisioningInterface
{
    /** @param ConnectionContext $context 原连接配置 @param ProvisionRequest $request 单实例固定请求 @return ProvisionResult 已受理或已确认的结果 */
    public function provision(ConnectionContext $context, ProvisionRequest $request): ProvisionResult;
    /** @param ConnectionContext $context 原连接配置 @param ProvisionRequest $request 原请求及外部标识 @return ProvisionResult 查询结果，未找到不能视为创建失败 */
    public function queryProvision(ConnectionContext $context, ProvisionRequest $request): ProvisionResult;
}
