<?php

declare(strict_types=1);

namespace app\modules\service_instance\contract;

/** 产品类型负责重装镜像与实际配置限制；公开结果由应用层再投影。 */
interface InstanceImageOptionsInterface
{
    /**
     * @param array<string,mixed> $context 原订单供应上下文
     * @return list<array<string,mixed>> 已核定镜像及原映射
     */
    public function images(array $context): array;
}
