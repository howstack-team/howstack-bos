<?php

declare(strict_types=1);

namespace app\modules\licensing\domain;

use app\shared\entitlement\{EntitlementContext, EntitlementDecision, EntitlementOperation};

/** 纯策略：时钟、验签、原业务证明和模块事实由宿主提供。 */
final readonly class LicenseEntitlementPolicy
{
    /**
     * @param array<string, mixed>|null $grant 已验签权益
     * @param bool $proofValid 已核实且绑定当前业务范围的历史受理证明
     */
    public function decide(
        ?CapabilityDefinition $definition,
        EntitlementOperation $operation,
        ?array $grant,
        string $status,
        int $revision,
        int $now,
        EntitlementContext $context,
        bool $proofValid,
    ): EntitlementDecision {
        $useUntil = $grant['use_until'] ?? null;
        $updatesUntil = $grant['updates_until'] ?? null;
        $reason = match (true) {
            $definition === null => 'CAPABILITY_NOT_IMPLEMENTED',
            !$definition->installed => 'MODULE_NOT_AVAILABLE',
            !isset($definition->permissions[$operation->value]) => 'OPERATION_NOT_SUPPORTED',
            default => null,
        };
        if ($reason !== null) {
            return new EntitlementDecision(false, $reason, $status, $useUntil, $updatesUntil, $revision, $grant['limits'] ?? []);
        }
        $continuation = in_array($operation, [
            EntitlementOperation::CompleteExisting, EntitlementOperation::MaintainExisting,
        ], true);
        if ($continuation || ($operation === EntitlementOperation::ReadExisting && $proofValid)) {
            return new EntitlementDecision($proofValid, $proofValid ? 'CONTINUATION_ALLOWED' : 'CONTINUATION_PROOF_REQUIRED',
                $status, $useUntil, $updatesUntil, $revision, $grant['limits'] ?? []);
        }
        $reason = match ($status) {
            'revoked' => 'LICENSE_REVOKED',
            'offline_expired' => 'OFFLINE_VERIFICATION_EXPIRED',
            'clock_error' => 'LICENSE_CLOCK_ERROR',
            'unverified' => 'LICENSE_UNVERIFIED',
            'community' => 'ENTITLEMENT_NOT_GRANTED',
            default => null,
        };
        if ($reason === null && $grant === null) {
            $reason = 'ENTITLEMENT_NOT_GRANTED';
        }
        if ($reason === null && $operation === EntitlementOperation::DownloadUpdate) {
            $published = $context->releasePublishedAt;
            $reason = match (true) {
                $published === null || $published > $now + 300 => 'RELEASE_CONTEXT_REQUIRED',
                $updatesUntil !== null && $published > $updatesUntil => 'UPDATE_ENTITLEMENT_EXPIRED',
                default => null,
            };
        } elseif ($reason === null && ($status === 'expired' || ($useUntil !== null && $useUntil <= $now))) {
            $reason = 'ENTITLEMENT_EXPIRED';
        }
        return new EntitlementDecision($reason === null, $reason ?? 'ALLOWED', $status, $useUntil, $updatesUntil, $revision, $grant['limits'] ?? []);
    }
}
