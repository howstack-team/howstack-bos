<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

/** 将标准交付配置与封存的供应对应关系合并，不执行外部调用。 */
interface ProviderProvisioningPreviewInterface
{
    /** @param array<string, mixed> $binding 已验证绑定 @param array<string, mixed> $configuration 标准配置 @return array<string, mixed> 完整且无凭据的交付参数预览。 */
    public function build(array $binding, array $configuration): array;
}
