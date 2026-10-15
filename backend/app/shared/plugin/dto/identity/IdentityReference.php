<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\identity;

use InvalidArgumentException;

/** 宿主持久化的认证流水对应关系，不接受浏览器任意提交的上游认证号。 */
final readonly class IdentityReference
{
    /**
     * @param string $requestNo 本系统申请流水
     * @param string $certifyId 上游认证流水
     */
    public function __construct(public string $requestNo, public string $certifyId)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $requestNo)
            || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $certifyId)) {
            throw new InvalidArgumentException('认证流水无效');
        }
    }
}
