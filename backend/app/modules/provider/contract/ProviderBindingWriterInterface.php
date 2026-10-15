<?php

declare(strict_types=1);

namespace app\modules\provider\contract;

/** 必须参与销售发布事务，不独立提交。 */
interface ProviderBindingWriterInterface
{
    /** @param int $versionId 销售版本 @param array<string, mixed> $binding 已校验绑定 @return void 持久化 */
    public function write(int $versionId, array $binding): void;
}
