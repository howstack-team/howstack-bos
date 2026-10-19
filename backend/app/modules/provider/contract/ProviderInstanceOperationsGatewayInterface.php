<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

/** 只读原供应插件能力，不在 HTTP 事务中调用外部平台。 */
interface ProviderInstanceOperationsGatewayInterface
{
    /**
     * @param array<string,mixed> $source 原交付来源
     * @return list<string> 支持的标准动作，插件已改变则拒绝
     */
    public function actions(array $source): array;
}
