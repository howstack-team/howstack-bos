<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\provider;

/** 已开通实例的固定供应身份，不能接受浏览器传入的上游标识。 */
final readonly class InstanceIdentity
{
    /**
     * @param string $externalId 原供应实例标识
     * @param string $hostname 首次创建的固定主机名
     */
    public function __construct(public string $externalId, public string $hostname)
    {
        if ($externalId === '' || $hostname === '') {
            throw new \InvalidArgumentException('实例供应身份无效');
        }
    }
}
