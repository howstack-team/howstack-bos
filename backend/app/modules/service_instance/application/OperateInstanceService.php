<?php

declare(strict_types=1);

namespace app\modules\service_instance\application;

use app\exception\BusinessException;
use app\modules\provider\contract\ProviderInstanceOperationsGatewayInterface;
use app\modules\service_instance\contract\{ServiceLifecycleRepositoryInterface, InstanceOperationRepositoryInterface, InstanceImageOptionsInterface};
use app\shared\contract\{TransactionManagerInterface, SensitiveDataCipherInterface};
use app\shared\http\ApiErrorCode;
use app\shared\plugin\exception\PluginException;

/** 核验本人或已授权管理员；HTTP 只原子登记持久任务，不直接调用供应商。 */
final readonly class OperateInstanceService
{
    /**
     * @param ServiceLifecycleRepositoryInterface $services 原服务
     * @param InstanceOperationRepositoryInterface $operations 运维任务
     * @param InstanceImageOptionsInterface $images 类型镜像校验
     * @param ProviderInstanceOperationsGatewayInterface $gateway 原插件能力
     * @param SensitiveDataCipherInterface $cipher 短期密码
     * @param TransactionManagerInterface $transactions 原子登记
     * @param \app\modules\provider\contract\ProviderVncGatewayInterface $vnc 可选VNC能力
     */
    public function __construct(
        private ServiceLifecycleRepositoryInterface $services,
        private InstanceOperationRepositoryInterface $operations,
        private InstanceImageOptionsInterface $images,
        private ProviderInstanceOperationsGatewayInterface $gateway,
        private SensitiveDataCipherInterface $cipher,
        private TransactionManagerInterface $transactions,
        private \app\modules\provider\contract\ProviderVncGatewayInterface $vnc,
    ) {
    }

    /**
     * @param int|null $memberId 会员本人或后台已核权null
     * @param int $id 服务
     * @return array<string,mixed> 不包含供应映射和秘密
     */
    public function options(?int $memberId, int $id): array
    {
        $service = $this->owned($memberId, $id);
        $actions = [];
        if ($this->ready($service)) {
            try {
                $actions = $this->gateway->actions($service['source']);
            } catch (PluginException) {
                $actions = [];
            }
        }
        $vncAvailable = false;
        if ($this->ready($service)) {
            try {
                $vncAvailable = $this->vnc->supported($service['source']);
            } catch (\Throwable) {
                $vncAvailable = false;
            }
        }
        $images = [];
        if (in_array('reinstall', $actions, true)) {
            foreach ($this->images->images($service['source']['context']) as $image) {
                unset($image['external_id']);
                $images[] = $image;
            }
        }
        $history = $this->operations->history($id);
        $busy = $this->operations->busy($id);
        return ['vnc_available' => $vncAvailable, 'actions' => $actions, 'images' => $images, 'history' => $history, 'busy' => $busy,
            'installed_image' => isset($service['installed_image']) ? json_decode($service['installed_image'], true, flags: JSON_THROW_ON_ERROR) : null,
            'power_status' => $service['power_status'] ?? null, 'power_checked_at' => $service['power_checked_at'] ?? null];
    }

    /**
     * @param int|null $memberId 本人或已授权后台
     * @param int $id 服务
     * @param array<string,mixed> $input 白名单请求
     * @param string $actor 服务端认证身份
     * @return array{id:int,status:string} 已受理任务
     */
    public function submit(?int $memberId, int $id, #[\SensitiveParameter] array $input, string $actor): array
    {
        return $this->transactions->run(function () use ($memberId, $id, $input, $actor): array {
            $service = $this->owned($memberId, $id, true);
            $key = 'op-' . substr(hash('sha256', $actor . ':' . $id . ':' . $input['request_key']), 0, 56);
            $old = $this->operations->existing($id, $key);
            if ($old !== null) {
                $same = $old['action'] === $input['action']
                    && (int) ($old['operation_context']['image']['id'] ?? 0) === (int) ($input['image_id'] ?? 0)
                    && ($old['password_digest'] === null ? empty($input['password']) : password_verify($input['password'] ?? '', $old['password_digest']));
                if (!$same) {
                    throw new BusinessException('同一请求标识不能更改操作参数', ApiErrorCode::CONFLICT, 409);
                }
                return ['id' => (int) $old['id'], 'status' => $old['status']];
            }
            if (!$this->ready($service)) {
                throw new BusinessException('实例未开通、已到期或不可操作', ApiErrorCode::CONFLICT, 409);
            }
            try {
                $actions = $this->gateway->actions($service['source']);
            } catch (PluginException $error) {
                throw new BusinessException('原供应插件不可用或已变化，请联系管理员', ApiErrorCode::CONFLICT, 409, previous: $error);
            }
            if (!in_array($input['action'], $actions, true)) {
                throw new BusinessException('原供应插件不支持此操作', ApiErrorCode::CONFLICT, 409);
            }
            $context = ['actor' => $actor];
            if ($input['action'] === 'reinstall') {
                $image = null;
                foreach ($this->images->images($service['source']['context']) as $candidate) {
                    if ($candidate['id'] === $input['image_id']) {
                        $image = $candidate;
                        break;
                    }
                }
                if ($image === null) {
                    throw new BusinessException('所选镜像不满足实例配置或没有原供应映射', ApiErrorCode::VALIDATION_FAILED, 422);
                }
                $context['image'] = $image;
            }
            $secret = $input['password'] ?? null;
            return $this->operations->enqueue($service, ['request_key' => $key, 'action' => $input['action'],
                'operation_context' => $context, 'password_encrypted' => $secret === null ? null : $this->cipher->encrypt($secret),
                'password_digest' => $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT),
                'credential_expires_at' => $secret === null ? null : date('Y-m-d H:i:s', time() + 86400)]);
        });
    }

    /**
     * @param int|null $memberId 本人或已授权后台
     * @param int $id 服务
     * @param bool $lock 行锁
     * @return array<string,mixed> 内部服务资料
     */
    private function owned(?int $memberId, int $id, bool $lock = false): array
    {
        $service = $this->services->service($id, $lock);
        if (($memberId !== null && $service['member_id'] !== $memberId) || $service['product_type'] !== 'cloud_server') {
            throw new BusinessException('云服务器不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        return $service;
    }

    /**
     * @param array<string,mixed> $service 原服务
     * @return bool 订阅与原供应均已就绪
     */
    private function ready(array $service): bool
    {
        return $service['status'] === 'active' && $service['expires_at'] !== null && $service['expires_at'] > date('Y-m-d H:i:s')
            && ($service['source']['status'] ?? null) === 'succeeded' && !empty($service['source']['external_id']);
    }
}
