<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\provider;

use InvalidArgumentException;

/** failed 仅表示供应方明确拒绝且未创建资源；结果不明必须使用 pending/unknown。 */
final readonly class ProvisionResult
{
    /** @param string $status pending/active/failed/unknown @param string|null $externalId 外部实例标识 @param list<string> $addresses 实例 IP 地址 */
    public function __construct(public string $status, public ?string $externalId = null, public array $addresses = [])
    {
        if (!in_array($status, ['pending', 'active', 'failed', 'unknown'], true)
            || ($externalId !== null && ($externalId === '' || strlen($externalId) > 128))
            || ($status === 'active' && $externalId === null) || ($status === 'failed' && ($externalId !== null || $addresses !== []))
            || count($addresses) > 64) throw new InvalidArgumentException('供应开通结果无效');
        foreach ($addresses as $ip) if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) throw new InvalidArgumentException('供应实例地址无效');
    }
}
