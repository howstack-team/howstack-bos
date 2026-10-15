<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\licensing\application\ManageLicenseService;
use app\modules\official_platform\contract\{InstallationIdentityInterface, OfficialPlatformClientInterface, OfficialPlatformStateStoreInterface};
use app\modules\official_platform\domain\SignedManifestVerifier;
use app\modules\official_platform\domain\ReleaseChangelog;
use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\software_maintenance\contract\{InstallationJobRepositoryInterface, InstallationLockInterface};
use Throwable;

/** 后端定时验签并保存提醒摘要；安装只使用确认时重新签发的清单。 */
final readonly class ManageSoftwareUpdatesService
{
    /**
     * @param AdminAccessControlService $access 权限
     * @param OfficialPlatformClientInterface $client 官网
     * @param OfficialPlatformStateStoreInterface $store 私有持久状态
     * @param InstallationIdentityInterface $identity 实例
     * @param SignedManifestVerifier $verifier 预置公钥
     * @param ManageLicenseService $licenses 商业期限
     * @param InstallationJobRepositoryInterface $jobs 任务
     * @param InstallationLockInterface $lock 全局安装锁
     * @param string $version 当前代码版本
     * @param int $checkIntervalSeconds 检查间隔，单位秒，限制在 6～24 小时
     */
    public function __construct(
        private AdminAccessControlService $access,
        private OfficialPlatformClientInterface $client,
        private OfficialPlatformStateStoreInterface $store,
        private InstallationIdentityInterface $identity,
        private SignedManifestVerifier $verifier,
        private ManageLicenseService $licenses,
        private InstallationJobRepositoryInterface $jobs,
        private InstallationLockInterface $lock,
        private string $version,
        private int $checkIntervalSeconds = 43200,
    ) {
    }

    /** @return array<string,mixed> 本地缓存和任务记录；不访问官网 */
    public function status(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.updates.view');

        return $this->cached() + [
            'jobs' => array_values(array_filter(
                $this->jobs->recent(),
                static fn (array $job): bool => $job['kind'] === 'system',
            )),
        ];
    }

    /** @return array<string,mixed> 仅供登录后的提醒读取，不返回安装清单或包地址 */
    public function notice(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.updates.view');
        $cached = $this->cached();
        $update = $cached['update'];

        return [
            'available' => $update['available'] ?? false,
            'current_version' => $this->version,
            'latest_version' => $update['target_version'] ?? null,
            'changelog' => $update['changelog'] ?? ReleaseChangelog::empty(),
            'force_update' => $update['force_update'] ?? false,
            'checked_at' => $update['checked_at'] ?? null,
            'last_attempt_at' => $cached['last_attempt_at'],
            'last_error' => $cached['last_error'],
        ];
    }

    /** @return array<string,mixed> 手动检查；失败保留上次成功结果并继续抛出错误 */
    public function check(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.updates.view');
        $this->access->requirePermission($actor, 'admin.updates.check');
        $this->refresh();

        return $this->status($actor);
    }

    /** @return void 固定维护任务调用；首次连接立即检查，失败至少间隔十五分钟重试 */
    public function refreshWhenDue(): void
    {
        $account = $this->client->localStatus();
        if (empty($account['configured']) || $this->jobs->active() !== null) {
            return;
        }
        $cached = $this->cached();
        $lastAttempt = strtotime((string) $cached['last_attempt_at']) ?: 0;
        $lastSuccess = strtotime((string) ($cached['update']['checked_at'] ?? '')) ?: 0;
        $interval = max(21600, min(86400, $this->checkIntervalSeconds));
        if ($lastAttempt > time() - 900 || $lastSuccess > time() - $interval) {
            return;
        }
        try {
            $this->refresh();
        } catch (Throwable) {
            // 安全错误已持久化；官网失败不能阻止已受理安装任务的执行。
        }
    }

    /**
     * @param AdminAccount $actor 管理员
     * @param string $id 幂等 UUID
     * @param string $hash 管理员明确确认的包摘要
     * @param array<string,string> $origin 审计来源
     * @param string $targetVersion 管理员明确确认的目标版本
     * @return array<string,mixed> 受理结果
     */
    public function start(AdminAccount $actor, string $id, string $hash, array $origin, string $targetVersion): array
    {
        $this->access->requirePermission($actor, 'admin.updates.view');
        $this->access->requirePermission($actor, 'admin.updates.execute');

        return $this->lock->synchronized(function () use ($actor, $id, $hash, $origin, $targetVersion): array {
            $existing = $this->jobs->find($id);
            if ($existing !== null) {
                $stored = json_decode($existing['manifest_json'], true, 32, JSON_THROW_ON_ERROR);
                if ($existing['kind'] !== 'system' || ($stored['sha256'] ?? '') !== $hash
                    || ($stored['version'] ?? '') !== $targetVersion) {
                    throw new OfficialPlatformException('IDEMPOTENCY_CONFLICT');
                }

                return $this->status($actor);
            }
            if ($this->jobs->active() !== null) {
                throw new OfficialPlatformException('INSTALLATION_BUSY');
            }
            // 缓存可以存在数小时；提交安装时始终重新获取并验签，不能使用过期清单。
            $manifest = $this->refresh();
            if ($manifest === null || !hash_equals($manifest['sha256'], $hash)
                || $manifest['version'] !== $targetVersion) {
                throw new OfficialPlatformException('RELEASE_CONTEXT_MISMATCH');
            }
            $this->jobs->create([
                'request_id' => $id,
                'kind' => 'system',
                'action' => 'update',
                'target_code' => 'howstack-bos',
                'version_id' => $manifest['version_id'],
                'from_version' => $this->version,
                'target_version' => $manifest['version'],
                'manifest_json' => json_encode($manifest, JSON_THROW_ON_ERROR),
                'actor_id' => $actor->id,
            ] + $origin);

            return $this->status($actor);
        });
    }

    /** @return array<string,mixed> 匹配当前连接和运行版本的白名单缓存 */
    private function cached(): array
    {
        $state = $this->store->read();
        $connectionId = $this->client->localStatus()['connection']['connection_id'] ?? '';
        $matches = fn (array $value): bool => ($value['current_version'] ?? '') === $this->version
            && $connectionId !== '' && ($value['connection_id'] ?? '') === $connectionId;
        $update = $state['software_update'] ?? null;
        if (!is_array($update) || !$matches($update)
            || !ReleaseChangelog::isValid($update['changelog'] ?? null)
            || !is_bool($update['force_update'] ?? null)) {
            $update = null;
        } else {
            $update = array_intersect_key($update, array_flip([
                'current_version', 'available', 'checked_at', 'target_version', 'changelog',
                'bytes', 'sha256', 'expires_at', 'force_update',
            ]));
        }
        $attempt = $state['software_update_attempt'] ?? [];
        $error = $state['software_update_error'] ?? [];

        return [
            'current_version' => $this->version,
            'update' => $update,
            'last_attempt_at' => $matches($attempt) ? ($attempt['checked_at'] ?? null) : null,
            'last_error' => $matches($error) ? ($error['message'] ?? null) : null,
        ];
    }

    /** @return array<string,mixed>|null 新的已验签清单；长期缓存仅保存版本摘要 */
    private function refresh(): ?array
    {
        $connectionId = $this->client->localStatus()['connection']['connection_id'] ?? '';
        $token = bin2hex(random_bytes(16));
        $attempt = [
            'connection_id' => $connectionId,
            'current_version' => $this->version,
            'checked_at' => date('Y-m-d H:i:s'),
            'token' => $token,
            'lease_until' => time() + 120,
        ];
        $this->store->synchronized(function () use ($attempt): void {
            $state = $this->store->read();
            $previous = $state['software_update_attempt'] ?? [];
            if (($previous['connection_id'] ?? '') === $attempt['connection_id']
                && ($previous['lease_until'] ?? 0) > time()) {
                throw new OfficialPlatformException('RATE_LIMITED');
            }
            $state['software_update_attempt'] = $attempt;
            $this->store->write($state);
        });
        try {
            $this->licenses->refreshWhenDue();
            $connection = $this->client->synchronize();
            $result = $this->client->checkUpdate();
            if (!is_bool($result['available'] ?? null) || ($result['current_version'] ?? '') !== $this->version) {
                throw new OfficialPlatformException('INVALID_RESPONSE');
            }
            $manifest = $result['available'] ? $this->verifier->release(
                $result['manifest'] ?? [],
                'system',
                $this->identity->id(),
                $connection['connection_id'],
                $this->version,
                edition: $this->licenses->releaseEdition(),
            ) : null;
            if ($manifest !== null) { $this->licenses->requireReleaseEntitlement($manifest); }
            $projection = [
                'current_version' => $this->version,
                'available' => $result['available'],
                'connection_id' => $connection['connection_id'],
                'checked_at' => date('Y-m-d H:i:s'),
                'target_version' => $manifest['version'] ?? null,
                'changelog' => $manifest['changelog'] ?? ReleaseChangelog::empty(),
                'force_update' => $manifest['force_update'] ?? false,
                'bytes' => $manifest['bytes'] ?? null,
                'sha256' => $manifest['sha256'] ?? null,
                'expires_at' => $manifest['expires_at'] ?? null,
            ];
            $this->store->synchronized(function () use ($projection, $token): void {
                $state = $this->store->read();
                if (($state['software_update_attempt']['token'] ?? '') !== $token
                    || ($this->client->localStatus()['connection']['connection_id'] ?? '') !== $projection['connection_id']) {
                    throw new OfficialPlatformException('RECONNECT_REQUIRED');
                }
                $state['software_update'] = $projection;
                $state['software_update_attempt']['lease_until'] = 0;
                unset($state['software_release'], $state['software_update_error']);
                $this->store->write($state);
            });

            return $manifest;
        } catch (Throwable $cause) {
            $error = $cause instanceof OfficialPlatformException ? $cause : new OfficialPlatformException('UPDATE_CHECK_FAILED', $cause);
            $this->store->synchronized(function () use ($error, $attempt, $token): void {
                $state = $this->store->read();
                if (($state['software_update_attempt']['token'] ?? '') !== $token) {
                    return;
                }
                $state['software_update_attempt']['lease_until'] = 0;
                $state['software_update_error'] = array_intersect_key($attempt, array_flip([
                    'connection_id', 'current_version', 'checked_at',
                ])) + ['reason' => $error->reason, 'message' => $error->getMessage()];
                $this->store->write($state);
            });
            throw $error;
        }
    }
}
