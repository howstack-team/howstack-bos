<?php

declare(strict_types=1);

namespace app\shared\entitlement;

/** 本机权益判断结果；不包含原证书或秘密。 */
final readonly class EntitlementDecision
{
    /** @param array<string, int> $limits 已验签额度；具体占用必须由业务事务原子执行 */
    public function __construct(
        public bool $allowed,
        public string $reason,
        public string $entitlementStatus,
        public ?int $useUntil,
        public ?int $updatesUntil,
        public int $entitlementRevision,
        public array $limits = [],
    ) {
    }
}
