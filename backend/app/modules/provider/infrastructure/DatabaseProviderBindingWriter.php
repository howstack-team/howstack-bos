<?php

declare(strict_types=1);

namespace app\modules\provider\infrastructure;

use app\modules\provider\contract\ProviderBindingWriterInterface;
use app\modules\provider\infrastructure\model\ProviderBindingModel;
use LogicException;

/** 发布事务内封存供应绑定。 */
final class DatabaseProviderBindingWriter implements ProviderBindingWriterInterface
{
    public function write(int $versionId, array $binding): void
    {
        if ((new ProviderBindingModel())->getConnection()->transactionLevel() < 1) throw new LogicException('供应绑定必须与销售版本一起提交');
        ProviderBindingModel::query()->create(['offer_version_id' => $versionId] + $binding);
    }
}
