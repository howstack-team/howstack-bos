<?php

declare(strict_types=1);

namespace app\modules\application_center\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\official_platform\contract\{InstallationIdentityInterface, OfficialPlatformClientInterface};
use app\modules\official_platform\domain\SignedManifestVerifier;
use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\plugin\application\PluginLifecycleService;
use app\modules\software_maintenance\contract\{InstallationJobRepositoryInterface, InstallationLockInterface};
use app\shared\http\ApiErrorCode;

/** 已获取应用与本机生命周期管理；官网获取通过独立商店页面完成。 */
final readonly class ManageApplicationCenterService
{
    /**
     * @param AdminAccessControlService $access 权限
     * @param OfficialPlatformClientInterface $client 官网
     * @param InstallationIdentityInterface $identity 实例
     * @param SignedManifestVerifier $verifier 发布公钥
     * @param PluginLifecycleService $plugins 本地插件状态
     * @param InstallationJobRepositoryInterface $jobs 持久任务
     * @param InstallationLockInterface $lock 安装互斥
     * @param string $version 核心版本
     */
    public function __construct(
        private AdminAccessControlService $access,
        private OfficialPlatformClientInterface $client,
        private InstallationIdentityInterface $identity,
        private SignedManifestVerifier $verifier,
        private PluginLifecycleService $plugins,
        private InstallationJobRepositoryInterface $jobs,
        private InstallationLockInterface $lock,
        private string $version,
    ) {
    }

    /**
     * @param AdminAccount $actor 管理员
     * @return array<string,mixed> 权益、本地记录和官网报告分别展示
     */
    public function owned(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.app-center.view');
        $result = $this->client->owned();
        if (!is_array($result['items'] ?? null) || count($result['items']) > 2000) {
            throw new OfficialPlatformException('INVALID_RESPONSE');
        }
        $fields = ['application_id', 'name', 'code', 'kind', 'entitlement_status', 'use_expires_at',
            'available_version_id', 'available_version', 'force_update', 'reported_status', 'unavailable_reason'];
        $local = array_column($this->plugins->list(), null, 'id');
        $items = array_map(static function (array $item) use ($fields, $local): array {
            $plugin = ($item['kind'] ?? '') === 'plugin' ? ($local[$item['code']] ?? null) : null;
            $state = $plugin['state'] ?? 'not_installed';
            return array_intersect_key($item, array_flip($fields)) + [
                'local_version' => in_array($state, ['not_installed', 'uninstalled'], true) ? null : ($plugin['installed_version'] ?? null),
                'local_state' => $state,
                'local_revision' => $plugin['revision'] ?? 0,
                'local_category' => $plugin['category'] ?? null,
            ];
        }, $result['items']);
        return ['items' => $items, 'jobs' => array_values(array_filter($this->jobs->recent(), static fn (array $job): bool => $job['kind'] === 'application'))];
    }

    /**
     * @param AdminAccount $actor 管理员
     * @return array{store_url:string} 本机配置生成的官网商店链接，不请求商店目录
     */
    public function storeLink(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.app-center.view');

        return ['store_url' => $this->client->storeUrl()];
    }

    /**
     * 本地卸载不依赖官网可用性；保持插件引用、状态及乐观锁保护。
     * @param AdminAccount $actor 管理员
     * @param string $code 固定本地插件标识
     * @param int $revision 已确认的安装记录版本
     * @param array{client_ip:string,client_ip_location:string} $origin 审计来源
     * @return array<string,mixed> 新的本地安装状态；保留文件、配置和历史记录
     */
    public function uninstall(AdminAccount $actor, string $code, int $revision, array $origin): array
    {
        $this->access->requirePermission($actor, 'admin.app-center.view');
        $this->access->requirePermission($actor, 'admin.app-center.uninstall');

        return $this->lock->synchronized(function () use ($actor, $code, $revision, $origin): array {
            if ($this->jobs->active() !== null) {
                throw new BusinessException('安装或更新任务结束后才能卸载应用', ApiErrorCode::CONFLICT, 409);
            }

            return $this->plugins->transition($code, 'uninstall', $revision, $actor->id, $origin);
        });
    }

    /**
     * @param AdminAccount $actor 管理员
     * @param int $versionId 目标应用版本
     * @return array<string,mixed> 验签后用于明确确认的资料
     */
    public function prepare(AdminAccount $actor, int $versionId): array
    {
        $this->access->requirePermission($actor, 'admin.app-center.view');
        $manifest = $this->manifest($versionId);
        return array_intersect_key($manifest, array_flip(['application_id', 'application_code', 'version_id',
            'version', 'kind', 'permissions', 'bytes', 'sha256', 'expires_at', 'changelog', 'force_update']));
    }

    /**
     * @param AdminAccount $actor 管理员
     * @param int $versionId 目标
     * @param string $id 幂等UUID
     * @param string $hash 已确认包摘要
     * @param string $action install/update
     * @param array<string,string> $origin 审计
     * @return array<string,mixed> 本机受理
     */
    public function start(AdminAccount $actor, int $versionId, string $id, string $hash, string $action, array $origin): array
    {
        $this->access->requirePermission($actor, 'admin.app-center.view');
        $this->access->requirePermission($actor, $action === 'install' ? 'admin.app-center.install' : 'admin.app-center.update');
        return $this->lock->synchronized(function () use ($actor, $versionId, $id, $hash, $action, $origin): array {
            $existing = $this->jobs->find($id);
            if ($existing !== null) {
                if ($existing['kind'] !== 'application' || (int) $existing['version_id'] !== $versionId || $existing['action'] !== $action
                    || (json_decode($existing['manifest_json'], true)['sha256'] ?? '') !== $hash) {
                    throw new OfficialPlatformException('IDEMPOTENCY_CONFLICT');
                }
                return ['jobs' => $this->jobs->recent()];
            }
            $manifest = $this->manifest($versionId);
            if ($manifest['kind'] !== 'plugin' || $manifest['permissions'] !== []) {
                throw new BusinessException('当前版本仅支持符合供应插件 v1 规范的应用安装；主题及其他扩展尚未开放运行契约', ApiErrorCode::CONFLICT, 409);
            }
            if (!hash_equals($manifest['sha256'], $hash)) {
                throw new OfficialPlatformException('RELEASE_CONTEXT_MISMATCH');
            }
            $plugin = array_column($this->plugins->list(), null, 'id')[$manifest['application_code']] ?? null;
            if ($action === 'update' && ($plugin === null || !in_array($plugin['state'], ['disabled', 'installed'], true))) {
                throw new BusinessException('更新前请在插件管理中停止接单、排空任务并停用插件', ApiErrorCode::CONFLICT, 409);
            }
            if ($action === 'install' && $plugin !== null && !in_array($plugin['state'], ['not_installed', 'uninstalled'], true)) {
                throw new BusinessException('应用已经安装，请使用更新操作', ApiErrorCode::CONFLICT, 409);
            }
            $this->jobs->create(['request_id' => $id, 'kind' => 'application', 'action' => $action,
                'target_code' => $manifest['application_code'], 'version_id' => $versionId,
                'from_version' => $this->version, 'target_version' => $manifest['version'],
                'manifest_json' => json_encode($manifest, JSON_THROW_ON_ERROR), 'report_status' => 'none', 'actor_id' => $actor->id] + $origin);
            return ['jobs' => $this->jobs->recent()];
        });
    }

    /**
     * @param int $id 官网版本
     * @return array<string,mixed> 固定本实例版本上下文的已验证清单
     */
    private function manifest(int $id): array
    {
        $connection = $this->client->synchronize();
        return $this->verifier->release($this->client->applicationManifest($id), 'application', $this->identity->id(),
            $connection['connection_id'], $this->version, $id);
    }
}
