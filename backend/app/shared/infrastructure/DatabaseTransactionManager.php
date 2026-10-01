<?php

declare(strict_types=1);

namespace app\shared\infrastructure;

use app\shared\contract\TransactionManagerInterface;
use support\Db;

/** 每次执行获取当前默认连接，模型与仓储不得另选连接。 */
final class DatabaseTransactionManager implements TransactionManagerInterface
{
    public function run(callable $operation): mixed
    {
        return Db::connection()->transaction($operation);
    }
}
