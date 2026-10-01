<?php

declare(strict_types=1);

namespace app\shared\contract;

/** 默认业务连接上的事务边界，不覆盖外部调用。 */
interface TransactionManagerInterface
{
    /**
     * @template T
     * @param callable(): T $operation 需要原子提交的用例
     * @return T 用例结果
     */
    public function run(callable $operation): mixed;
}
