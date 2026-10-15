<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\application;

use app\modules\licensing\application\ManageLicenseService;
use app\modules\official_platform\contract\{InstallationIdentityInterface, OfficialPlatformClientInterface};
use app\modules\official_platform\domain\SignedManifestVerifier;
use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\software_maintenance\contract\{InstallationJobRepositoryInterface, InstallationLockInterface, ReleaseInstallerInterface};
use Throwable;

/** 专用CLI执行，崩溃后以持久阶段决定恢复；不把官网报告当安装事实。 */
final readonly class RunInstallationJobService
{
    /**
     * @param InstallationJobRepositoryInterface $jobs 任务
     * @param InstallationLockInterface $lock 全局锁
     * @param OfficialPlatformClientInterface $client 官网
     * @param InstallationIdentityInterface $identity 实例
     * @param SignedManifestVerifier $verifier 公钥
     * @param ManageLicenseService $licenses 有效级别
     * @param ReleaseInstallerInterface $installer 固定安装流程
     * @param string $private 私有目录
     * @param string $version 核心版本
     */
    public function __construct(
        private InstallationJobRepositoryInterface $jobs,
        private InstallationLockInterface $lock,
        private OfficialPlatformClientInterface $client,
        private InstallationIdentityInterface $identity,
        private SignedManifestVerifier $verifier,
        private ManageLicenseService $licenses,
        private ReleaseInstallerInterface $installer,
        private string $private,
        private string $version,
    ) {
    }

    /** @return void 每轮至多执行一个固定目标，包含重启后的实际状态核对 */
    public function tick(): void
    {
        $this->lock->synchronized(function (): void {
            $job = $this->jobs->active();
            if ($job === null) {
                $this->retryReports();
                return;
            }
            $id = $job['request_id'];
            if ($job['status'] === 'recovery_required') {
                return;
            }
            if ($job['status'] === 'awaiting_restart') {
                if ($this->installer->confirm($job)) {
                    $this->jobs->update($id, ['status' => 'succeeded', 'phase' => 'complete', 'message' => '安装完成，运行状态已核实', 'lease_until' => null]);
                    $this->report($job, 'succeeded');
                }
                return;
            }
            if ($job['status'] === 'running') {
                if ($job['lease_until'] !== null && strtotime($job['lease_until']) > time()) {
                    return;
                }
                $dangerous = in_array($job['phase'], ['installing', 'migrating', 'restarting'], true)
                    || ($job['kind'] === 'application' && $job['phase'] === 'backup');
                $this->jobs->update($id, ['status' => $dangerous ? 'recovery_required' : 'failed', 'error_code' => 'EXECUTION_INTERRUPTED',
                    'message' => $dangerous ? '执行中断，请根据备份核实文件与数据库状态后人工恢复' : '执行中断，未进入文件替换阶段，请重新检查并创建任务']);
                $this->report($job, 'failed', 'EXECUTION_INTERRUPTED');
                return;
            }
            $this->jobs->update($id, ['status' => 'running', 'lease_until' => date('Y-m-d H:i:s', time() + 600), 'backup_key' => $id]);
            $phase = 'downloading';
            try {
                $expected = json_decode($job['manifest_json'], true, 32, JSON_THROW_ON_ERROR);
                $connection = $this->client->synchronize();
                if ($job['kind'] === 'system') {
                    $this->jobs->update($id, ['report_status' => 'pending']);
                    $this->client->registerSoftwareOperation($id, $expected);
                    $envelope = $this->client->softwareOperationManifest($id);
                } else {
                    $envelope = $this->client->applicationManifest((int)$job['version_id']);
                }
                $manifest = $this->verifier->release($envelope, $job['kind'], $this->identity->id(),
                    $connection['connection_id'], $this->version, $job['version_id'] === null ? null : (int) $job['version_id'],
                    $this->licenses->releaseEdition());
                if ($job['kind'] === 'system') { $this->licenses->requireReleaseEntitlement($manifest); }
                foreach (['version', 'sha256', 'bytes', 'connection_id', 'download_path'] as $field) {
                    if ($manifest[$field] !== $expected[$field]) {
                        throw new OfficialPlatformException('RELEASE_CONTEXT_MISMATCH');
                    }
                }
                if ($job['kind'] === 'application') {
                    // 先持久化待回报状态；官网登记按任务 UUID 幂等，响应丢失也不能重新安装。
                    $this->jobs->update($id, ['report_status' => 'pending']);
                    $this->client->registerOperation($id, (int) $job['version_id'], $job['action']);
                }
                $this->jobs->update($id, ['phase' => $phase, 'message' => '正在下载并校验发布包']);
                $this->report($job, 'downloading');
                $directory = $this->private . '/jobs/' . $id;
                // 私有目录能力属于基础设施，不在这里自行创建文件或解压。
                $archive = $directory . '/package.zip';
                $this->installerDirectory($directory);
                $this->client->download($manifest, $archive);
                $this->installer->install($job, $manifest, $archive, function (string $next, string $message) use ($id, &$phase, $job): void {
                    $phase = $next;
                    $this->jobs->update($id, ['phase' => $next, 'message' => $message, 'lease_until' => date('Y-m-d H:i:s', time() + 600)]);
                    if ($next === 'installing') {
                        $this->report($job, 'installing');
                    }
                });
                $this->jobs->update($id, ['status' => 'awaiting_restart', 'phase' => 'restarting',
                    'message' => '文件与迁移已完成，正在完整重启并核实运行状态', 'lease_until' => null]);
                $this->installer->restart();
            } catch (Throwable $error) {
                $recovery = $phase === 'migrating' || ($error instanceof OfficialPlatformException && $error->reason === 'FILE_RECOVERY_FAILED');
                $code = $error instanceof OfficialPlatformException ? $error->reason : 'INSTALLATION_FAILED';
                if (($this->jobs->find($id)['status'] ?? '') === 'awaiting_restart') {
                    $this->jobs->update($id, ['error_code' => 'RESTART_REQUIRED', 'message' => '安装已完成，请由维护人员完整重启Webman后核实状态']);
                    return;
                }
                $this->jobs->update($id, ['status' => $recovery ? 'recovery_required' : 'failed', 'error_code' => $code,
                    'message' => $recovery ? '更新未完成，已保留维护状态及备份，请由维护人员恢复' : '安装未完成，原运行文件保持或已恢复，请核对后重新创建任务', 'lease_until' => null]);
                $this->report($job, 'failed', $code);
            }
        });
    }

    /**
     * @param string $directory 固定任务目录
     * @return void 委托私有目录端口
     */
    private function installerDirectory(string $directory): void
    {
        // 下一步由安装器创建；下载器也只接受已有私有目标目录。
        $this->installer->prepareDirectory($directory);
    }

    /**
     * @param array<string,mixed> $job 本机任务
     * @param string $status 实际阶段
     * @param string $code 安全错误码
     * @return void 报告失败保留为待重试，不改变本机安装结果
     */
    private function report(array $job, string $status, string $code = ''): void
    {
        if ( ($this->jobs->find($job['request_id'])['report_status'] ?? 'none') === 'none') {
            return;
        }
        try {
            if ($job['kind'] === 'system') { $this->client->reportSoftwareOperation($job['request_id'], $status, $code); }
            else { $this->client->reportOperation($job['request_id'], $status, $code); }
            if (in_array($status, ['succeeded', 'failed'], true)) {
                $this->jobs->update($job['request_id'], ['report_status' => 'reported']);
            }
        } catch (OfficialPlatformException $error) {
            // 官网明确未登记该 UUID 时没有待释放的安装名额，不阻塞后续旧任务回报。
            $this->jobs->update($job['request_id'], [
                'report_status' => $error->reason === 'OPERATION_NOT_FOUND' ? 'none' : 'pending',
            ]);
        }
    }

    /** @return void 未回报的终态按受理顺序幂等重发，失败不重复安装 */
    private function retryReports(): void
    {
        foreach ($this->jobs->pendingReports() as $job) {
            if ($job['report_status'] === 'pending'
                && in_array($job['status'], ['succeeded', 'failed', 'recovery_required'], true)) {
                $this->report($job, $job['status'] === 'succeeded' ? 'succeeded' : 'failed', $job['error_code'] ?? '');
                break;
            }
        }
    }
}
