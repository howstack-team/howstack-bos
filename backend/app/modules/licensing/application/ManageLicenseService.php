<?php

declare(strict_types=1);

namespace app\modules\licensing\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\licensing\contract\LicenseClockInterface;
use app\modules\licensing\domain\{CapabilityCatalog, LicenseEntitlementPolicy};
use app\modules\official_platform\contract\{InstallationIdentityInterface, OfficialPlatformClientInterface, OfficialPlatformStateStoreInterface};
use app\modules\official_platform\domain\SignedManifestVerifier;
use app\modules\official_platform\exception\OfficialPlatformException;
use app\shared\contract\{CommercialFeatureGateInterface, SensitiveDataCipherInterface};
use app\shared\entitlement\{EntitlementContext, EntitlementDecision, EntitlementOperation};
use app\shared\http\ApiErrorCode;
use JsonException;
use RuntimeException;

/** 权益来自签名证书，能力与到期策略来自宿主代码；所有判断均在本机完成。 */
final readonly class ManageLicenseService implements CommercialFeatureGateInterface
{
    /**
     * @param AdminAccessControlService $access 本机管理员权限
     * @param OfficialPlatformClientInterface $client 官网固定协议客户端
     * @param OfficialPlatformStateStoreInterface $store 加密状态及跨 Worker 互斥
     * @param InstallationIdentityInterface $identity 持久安装身份
     * @param SignedManifestVerifier $verifier 预置公钥验签
     * @param CapabilityCatalog $catalog 实际接入的能力目录
     * @param SensitiveDataCipherInterface $cipher 原业务证明认证加密
     * @param LicenseClockInterface $clock 可测试时钟
     * @param LicenseEntitlementPolicy $policy 纯权益策略
     */
    public function __construct(
        private AdminAccessControlService $access,
        private OfficialPlatformClientInterface $client,
        private OfficialPlatformStateStoreInterface $store,
        private InstallationIdentityInterface $identity,
        private SignedManifestVerifier $verifier,
        private CapabilityCatalog $catalog,
        private SensitiveDataCipherInterface $cipher,
        private LicenseClockInterface $clock,
        private LicenseEntitlementPolicy $policy,
    ) {
    }

    /** @return array<string, mixed> 仅授权查看权限可读取购买版本与逐项期限 */
    public function status(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.license.view');
        $license = $this->current();
        $account = $this->client->localStatus();
        $connection = $account['connection'] ?? [];
        if ($license['status'] === 'community' && !empty($account['connected'])
            && ($connection['connection_mode'] ?? '') === 'community'
            && is_string($connection['edition_name'] ?? null) && trim($connection['edition_name']) !== '') {
            $license['edition_name'] = trim($connection['edition_name']);
        }
        return $license + ['account' => $account];
    }

    /** @return array<string, mixed> 本机公开投影；不因使用到期抹去购买版本 */
    public function current(): array
    {
        $snapshot = $this->snapshot();
        $certificate = $snapshot['certificate'];
        $entitlements = [];
        foreach ($certificate['grants'] ?? [] as $grant) {
            $definition = $this->catalog->find($grant['capability']);
            $entitlements[] = [
                'capability' => $grant['capability'],
                'name' => $definition?->name ?? $grant['capability'],
                'implemented' => $definition !== null,
                'installed' => $definition?->installed ?? false,
                'status' => $this->grantStatus($snapshot['status'], $grant, $snapshot['now']),
                'use_until' => $this->date($grant['use_until']),
                'updates_until' => $this->date($grant['updates_until']),
                'limits' => (object) $grant['limits'],
            ];
        }
        return [
            'software_code' => 'howstack-bos',
            'edition' => $certificate['edition'] ?? ($snapshot['status'] === 'community' ? 'community' : 'unknown'),
            'edition_name' => $certificate['edition_name'] ?? ($certificate !== null ? '商业版' : ($snapshot['status'] === 'community' ? '社区版' : '待核实')),
            'status' => $snapshot['status'],
            'status_text' => match ($snapshot['status']) {
                'community' => '无需商业授权',
                'active' => '授权有效',
                'expired' => '使用权益已到期',
                'revoked' => '授权已撤销',
                'offline_expired' => '联网验证期限已到',
                'clock_error' => '系统时间异常，请校准时间',
                default => '授权证书未通过验证',
            },
            'license_no' => $certificate['license_no'] ?? null,
            'offline_until' => $certificate === null ? null : $this->date($certificate['offline_until']),
            'verified_at' => $snapshot['state']['license_checked_at'] ?? null,
            'installation_id' => $this->identity->id(),
            'entitlement_revision' => $certificate['revision'] ?? 0,
            'entitlements' => $entitlements,
            'last_error' => $snapshot['error'] ?? ($snapshot['state']['license_error'] ?? null),
        ];
    }

    /** @return array<string, mixed> 登录后读取的最小操作投影，无授权编号和连接详情 */
    public function capabilities(AdminAccount $actor): array
    {
        $snapshot = $this->snapshot();
        $permissions = $this->access->permissions($actor);
        $items = [];
        foreach ($this->catalog->all() as $id => $definition) {
            $operations = [];
            $reasons = [];
            foreach ($definition->permissions as $operation => $permission) {
                $decision = $this->decision($id, EntitlementOperation::from($operation), new EntitlementContext(), $snapshot);
                $permitted = in_array($permission, $permissions, true);
                $operations[$operation] = $decision->allowed && $permitted;
                $reasons[$operation] = $permitted ? $decision->reason : 'PERMISSION_DENIED';
            }
            $items[$id] = [
                'installed' => $definition->installed,
                'status' => $this->grantStatus($snapshot['status'], $this->grant($snapshot['certificate'], $id), $snapshot['now']),
                'operations' => (object) $operations,
                'reasons' => (object) $reasons,
            ];
        }
        $deadlines = [];
        if ($snapshot['certificate'] !== null) {
            $deadlines[] = $snapshot['certificate']['offline_until'];
            foreach ($snapshot['certificate']['grants'] as $grant) {
                if ($grant['use_until'] !== null) {
                    $deadlines[] = $grant['use_until'];
                }
            }
        }
        $future = array_filter($deadlines, static fn (int $value): bool => $value > $snapshot['now']);
        return [
            'capabilities' => (object) $items,
            'entitlement_revision' => $snapshot['certificate']['revision'] ?? 0,
            'next_check_at' => $future === [] ? null : $this->date(min($future)),
            'next_check_in_seconds' => $future === [] ? null : max(0, min($future) - $snapshot['now']),
        ];
    }

    /** @inheritDoc */
    public function decide(string $capability, EntitlementOperation $operation, ?EntitlementContext $context = null): EntitlementDecision
    {
        return $this->decision($capability, $operation, $context ?? new EntitlementContext(), $this->snapshot());
    }

    /** @inheritDoc */
    public function require(string $capability, EntitlementOperation $operation, ?EntitlementContext $context = null): void
    {
        $decision = $this->decide($capability, $operation, $context);
        if (!$decision->allowed) {
            $this->deny($decision, $capability, $operation);
        }
    }

    /** @return never 使用同一次判断，避免截止时刻二次读取产生不一致 */
    private function deny(EntitlementDecision $decision, string $capability, EntitlementOperation $operation): never
    {
        throw new BusinessException(match ($decision->reason) {
            'CAPABILITY_NOT_IMPLEMENTED' => '该能力尚未接入当前系统',
            'MODULE_NOT_AVAILABLE' => '所需模块未安装或不可用',
            'ENTITLEMENT_EXPIRED' => '此功能的使用权益已到期',
            'UPDATE_ENTITLEMENT_EXPIRED' => '目标版本超出此功能的更新权益期限',
            'OFFLINE_VERIFICATION_EXPIRED' => '请联网重新验证商业授权',
            'LICENSE_REVOKED' => '商业授权已撤销，不能办理新的高级操作',
            'LICENSE_CLOCK_ERROR' => '系统时间异常，请校准后重新验证授权',
            'CONTINUATION_PROOF_REQUIRED' => '缺少与原业务范围匹配的有效受理证明',
            default => '当前授权或操作范围不允许此功能',
        }, ApiErrorCode::FORBIDDEN, 403, data: [
            'reason' => $decision->reason, 'capability' => $capability, 'operation' => $operation->value,
        ]);
    }

    /** @inheritDoc */
    public function createContinuationProof(string $capability, EntitlementContext $context): string
    {
        if ($context->businessReference === '' || $context->configurationHash === '') {
            throw new BusinessException('生成受理证明必须提供固定业务引用及配置摘要', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $snapshot = $this->snapshot();
        $decision = $this->decision($capability, EntitlementOperation::StartNew, $context, $snapshot);
        if (!$decision->allowed) {
            $this->deny($decision, $capability, EntitlementOperation::StartNew);
        }
        // 证明携带原始签名材料，后续续费、断连或当前证书失效不改变已受理范围。
        return $this->cipher->encrypt(json_encode([
            'type' => 'accepted-entitlement-operation', 'schema_version' => 1,
            'installation_id' => $this->identity->id(), 'capability' => $capability,
            'business_reference' => $context->businessReference, 'configuration_hash' => $context->configurationHash,
            'accepted_at' => $snapshot['now'], 'certificate' => $snapshot['state']['license_certificate'],
            'connection_id' => $snapshot['state']['certificate_connection_id'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return string 签名更新清单的版本上下文；不是业务功能授权 */
    public function releaseEdition(): string
    {
        $snapshot = $this->snapshot();
        if (!in_array($snapshot['status'], ['active', 'expired'], true)) {
            return 'community';
        }
        return $snapshot['certificate']['edition'];
    }

    /** @param array<string,mixed> $manifest 已验签系统清单 @return void 更新按首次发布时间核对，不以今天替代发行时间 */
    public function requireReleaseEntitlement(array $manifest): void
    {
        $capability = $manifest['required_capability'] ?? '';
        if ($capability === '') { return; }
        $snapshot = $this->snapshot();
        $grant = $this->grant($snapshot['certificate'], $capability);
        if (!in_array($snapshot['status'], ['active', 'expired'], true) || $grant === null
            || ($grant['updates_until'] !== null && $manifest['first_published_at'] > $grant['updates_until'])) {
            throw new OfficialPlatformException('UPDATE_NOT_ENTITLED');
        }
    }

    /** @return array<string, mixed> 主动刷新仍要求独立授权管理权限 */
    public function refresh(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.license.view');
        $this->access->requirePermission($actor, 'admin.license.manage');
        $this->refreshCertificate();
        return $this->status($actor);
    }

    /** @return void 断网保留有效证书；接近租约截止时提前刷新 */
    public function refreshWhenDue(): void
    {
        $account = $this->client->localStatus();
        if (empty($account['connected']) || ($account['connection']['connection_mode'] ?? '') !== 'licensed') {
            return;
        }
        $now = $this->clock->now();
        $deadline = strtotime((string) ($this->current()['offline_until'] ?? '')) ?: 0;
        $due = $this->store->synchronized(function () use ($deadline, $now): bool {
            $state = $this->store->read();
            $attempt = (int) ($state['license_refresh_attempt_at'] ?? 0);
            $verified = strtotime((string) ($state['license_checked_at'] ?? '')) ?: 0;
            if ($attempt > $now - 900 || ($verified > $now - 21600 && $deadline > $now + 1800)) {
                return false;
            }
            $state['license_refresh_attempt_at'] = $now;
            $this->store->write($state);
            return true;
        });
        if ($due) {
            try {
                $this->refreshCertificate();
            } catch (OfficialPlatformException) {
                // 已保存安全失败原因，不能删除上次证书或自动重放业务。
            }
        }
    }

    private function refreshCertificate(): void
    {
        try {
            $connection = $this->client->synchronize();
            if ($connection['connection_mode'] === 'community') {
                return;
            }
            $envelope = $this->client->certificate();
            $now = $this->clock->now();
            $verified = $this->verifier->certificate($envelope, $this->identity->id(), $connection['connection_id'], $now);
            if ($verified['license_no'] !== $connection['license_no'] || $verified['edition'] !== $connection['edition_code']) {
                throw new OfficialPlatformException('INVALID_LICENSE_CERTIFICATE');
            }
            $this->store->synchronized(function () use ($envelope, $verified, $connection, $now): void {
                $state = $this->store->read();
                if (($state['connection']['connection_id'] ?? '') !== $connection['connection_id']) {
                    throw new OfficialPlatformException('RECONNECT_REQUIRED');
                }
                $this->verifyTrust($state, $verified, $envelope, $connection['connection_id'], $now);
                $state['license_certificate'] = $envelope;
                $state['certificate_connection_id'] = $connection['connection_id'];
                $state['license_checked_at'] = date('Y-m-d H:i:s', $now);
                $state['license_error'] = null;
                $state['license_trust'][$connection['connection_id']] = [
                    'revision' => $verified['revision'], 'digest' => hash('sha256', $envelope['payload']),
                ];
                $state['license_seen_at'] = max($now, (int) ($state['license_seen_at'] ?? 0));
                $this->store->write($state);
            });
        } catch (OfficialPlatformException $error) {
            $this->store->synchronized(function () use ($error): void {
                $state = $this->store->read();
                $state['license_error'] = $error->reason;
                $this->store->write($state);
            });
            throw $error;
        }
    }

    /** @return array{certificate: ?array, state: array, status: string, now: int, error: ?string} 私有快照 */
    private function snapshot(): array
    {
        $state = $this->store->read();
        $now = $this->clock->now();
        $certificate = null;
        $status = 'community';
        $error = null;
        if (!empty($state['license_certificate'])) {
            try {
                $connectionId = $state['certificate_connection_id'] ?? '';
                if (!is_string($connectionId) || (isset($state['connection']['connection_id'])
                    && $state['connection']['connection_id'] !== $connectionId)) {
                    throw new OfficialPlatformException('MANIFEST_CONTEXT_MISMATCH');
                }
                $certificate = $this->verifier->certificate($state['license_certificate'], $this->identity->id(), $connectionId,
                    max($now, (int) ($state['license_seen_at'] ?? 0)));
                $this->verifyTrust($state, $certificate, $state['license_certificate'], $connectionId, $now);
                $this->observe($state, $certificate, $connectionId, $now);
                $usable = array_filter($certificate['grants'], static fn (array $grant): bool =>
                    $grant['use_until'] === null || $grant['use_until'] > $now);
                $status = match (true) {
                    $certificate['status'] === 'revoked' => 'revoked',
                    $certificate['offline_until'] <= $now => 'offline_expired',
                    $certificate['status'] === 'expired' || ($certificate['grants'] !== [] && $usable === []) => 'expired',
                    default => 'active',
                };
            } catch (OfficialPlatformException $cause) {
                $error = $cause->reason;
                $status = $cause->reason === 'LICENSE_CLOCK_ERROR' ? 'clock_error' : 'unverified';
                // 签名/上下文失败的载荷不能再作为公开权益或操作判断输入。
                if ($status !== 'clock_error') {
                    $certificate = null;
                }
            }
        }
        return compact('certificate', 'state', 'status', 'now', 'error');
    }

    /** @return void 每分钟最多登记一次可信时间，不缓存请求身份或决策 */
    private function observe(array $state, array $certificate, string $connectionId, int $now): void
    {
        if ($now <= (int) ($state['license_seen_at'] ?? 0) + 60
            && isset($state['license_trust'][$connectionId])) {
            return;
        }
        try {
            $this->store->synchronized(function () use ($certificate, $connectionId, $now, $state): void {
                $latest = $this->store->read();
                if (($latest['license_certificate'] ?? null) !== $state['license_certificate']) {
                    throw new OfficialPlatformException('LICENSE_STATE_CHANGED');
                }
                $this->verifyTrust($latest, $certificate, $state['license_certificate'], $connectionId, $now);
                $latest['license_trust'][$connectionId] = [
                    'revision' => $certificate['revision'], 'digest' => hash('sha256', $state['license_certificate']['payload']),
                ];
                $latest['license_seen_at'] = max($now, (int) ($latest['license_seen_at'] ?? 0));
                $this->store->write($latest);
            });
        } catch (OfficialPlatformException $error) {
            if ($error->reason !== 'RATE_LIMITED') {
                throw $error;
            }
            // 其他 Worker 正在维护状态，本次继续使用已验证快照，后续读取补记时间。
        }
    }

    /** @param array<string, mixed> $snapshot 当前请求内快照 */
    private function decision(string $id, EntitlementOperation $operation, EntitlementContext $context, array $snapshot): EntitlementDecision
    {
        $proof = $context->continuationProof !== null && $this->proofValid($id, $context, $snapshot['now']);
        $grant = $this->grant($snapshot['certificate'], $id);
        $status = $snapshot['status'] === 'active' ? $this->grantStatus('active', $grant, $snapshot['now']) : $snapshot['status'];
        return $this->policy->decide($this->catalog->find($id), $operation,
            $grant, $status, $snapshot['certificate']['revision'] ?? 0,
            $snapshot['now'], $context, $proof);
    }

    private function proofValid(string $id, EntitlementContext $context, int $now): bool
    {
        if ($context->businessReference === '' || $context->configurationHash === '' || $context->continuationProof === null) {
            return false;
        }
        try {
            $proof = json_decode($this->cipher->decrypt($context->continuationProof), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($proof) || ($proof['type'] ?? '') !== 'accepted-entitlement-operation'
                || ($proof['schema_version'] ?? 0) !== 1 || ($proof['installation_id'] ?? '') !== $this->identity->id()
                || ($proof['capability'] ?? '') !== $id || ($proof['business_reference'] ?? '') !== $context->businessReference
                || ($proof['configuration_hash'] ?? '') !== $context->configurationHash
                || !is_int($proof['accepted_at'] ?? null) || $proof['accepted_at'] < 1 || $proof['accepted_at'] > $now + 300
                || !is_array($proof['certificate'] ?? null) || !is_string($proof['connection_id'] ?? null)) {
                return false;
            }
            $certificate = $this->verifier->certificate($proof['certificate'], $this->identity->id(), $proof['connection_id'], $proof['accepted_at']);
            $grant = $this->grant($certificate, $id);
            return $certificate['status'] === 'active' && $grant !== null
                && $certificate['offline_until'] > $proof['accepted_at']
                && ($grant['use_until'] === null || $grant['use_until'] > $proof['accepted_at']);
        } catch (RuntimeException | JsonException $error) {
            // 无效加密证明是可预期的拒绝，不能把解密异常或证书输出给调用者。
            return false;
        }
    }

    /** @param array<string, mixed> $state 私有高水位 @param array<string, mixed> $certificate 已验签载荷 */
    private function verifyTrust(array $state, array $certificate, array $envelope, string $connectionId, int $now): void
    {
        if ($now < (int) ($state['license_seen_at'] ?? 0) - 300) {
            throw new OfficialPlatformException('LICENSE_CLOCK_ERROR');
        }
        $trust = $state['license_trust'][$connectionId] ?? null;
        if ($trust !== null && ($certificate['revision'] < $trust['revision']
            || ($certificate['revision'] === $trust['revision'] && !hash_equals($trust['digest'], hash('sha256', $envelope['payload']))))) {
            throw new OfficialPlatformException('LICENSE_REPLAY_REJECTED');
        }
    }

    /** @return array<string, mixed>|null */
    private function grant(?array $certificate, string $id): ?array
    {
        foreach ($certificate['grants'] ?? [] as $grant) {
            if ($grant['capability'] === $id) {
                return $grant;
            }
        }
        return null;
    }

    private function grantStatus(string $status, ?array $grant, int $now): string
    {
        if ($grant === null) {
            return 'not_granted';
        }
        if ($status !== 'active') {
            return $status;
        }
        return $grant['use_until'] !== null && $grant['use_until'] <= $now ? 'expired' : 'active';
    }

    private function date(?int $timestamp): ?string
    {
        return $timestamp === null ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
