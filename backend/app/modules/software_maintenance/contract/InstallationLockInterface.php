<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\contract;

/** 单安装实例的安装、受理与连接变更互斥锁。 */
interface InstallationLockInterface
{
    /**
     * @template T
     * @param callable():T $operation 操作
     * @return T 执行结果
     */
    public function synchronized(callable $operation): mixed;
}
