<?php

declare(strict_types=1);

namespace app\modules\service_instance\application;

use app\exception\BusinessException;
use app\modules\provider\contract\ProviderVncGatewayInterface;
use app\modules\service_instance\contract\{ServiceLifecycleRepositoryInterface, VncAuditLoggerInterface};
use app\shared\http\ApiErrorCode;
use app\shared\plugin\exception\PluginException;

/** 鉴权、资格检查和单次签发；不持有数据库事务或缓存VNC凭据。 */
final readonly class OpenInstanceVncService
{
    /**
     * @param ServiceLifecycleRepositoryInterface $services 原服务
     * @param ProviderVncGatewayInterface $gateway 原供应VNC能力
     * @param VncAuditLoggerInterface $audit 安全访问事件
     */
    public function __construct(private ServiceLifecycleRepositoryInterface $services, private ProviderVncGatewayInterface $gateway, private VncAuditLoggerInterface $audit)
    {
    }

    /**
     * @param int|null $memberId 本人或后台已核权null
     * @param int $id 实例
     * @return array{allowed:bool} 不含凭据的持续访问检查
     */
    public function access(?int $memberId, int $id): array
    {
        $service = $this->eligible($memberId, $id);
        try {
            $supported = $this->gateway->supported($service['source']);
        } catch (\Throwable) {
            $supported = false;
        }
        if (!$supported) {
            throw new BusinessException('原供应插件尚未支持VNC或已变化，请联系管理员', ApiErrorCode::CONFLICT, 409);
        }
        return ['allowed' => true];
    }

    /**
     * @param int|null $memberId 本人或已授权后台
     * @param int $id 实例
     * @param string $actor 认证身份作用域
     * @return array{websocket_url:string,password:string,connect_timeout_seconds:int,session_timeout_seconds:int} 只允许认证POST响应且禁止缓存
     */
    public function open(?int $memberId, int $id, string $actor): array
    {
        $before = $this->eligible($memberId, $id);
        try {
            $connection = $this->gateway->open($before['source']);
            $after = $this->eligible($memberId, $id);
            if (!$this->gateway->supported($after['source'])) {
                throw new BusinessException('供应插件已停用或VNC能力已变化', ApiErrorCode::CONFLICT, 409);
            }
            foreach (['adapter_id', 'package_hash', 'external_id', 'request_key', 'connection_encrypted'] as $field) {
                if (($before['source'][$field] ?? null) !== ($after['source'][$field] ?? null) || $before['member_id'] !== $after['member_id']) {
                    throw new BusinessException('实例归属或供应绑定已变化，请重新打开控制台', ApiErrorCode::CONFLICT, 409);
                }
            }
        } catch (BusinessException $error) {
            $this->audit->record($actor, $id, 'failed');
            throw $error;
        } catch (\Throwable $error) {
            $this->audit->record($actor, $id, 'failed');
            $message = $error instanceof PluginException && $error->reason === 'adapter_changed'
                ? '原供应插件已变化，请联系管理员核查' : '无法获取VNC连接，请检查供应商的VNC外部访问配置';
            throw new BusinessException($message, ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        }
        $this->audit->record($actor, $id, 'issued');
        return ['websocket_url' => $connection->websocketUrl, 'password' => $connection->password,
            'connect_timeout_seconds' => 30, 'session_timeout_seconds' => min(900, max(1, strtotime($after['expires_at']) - time()))];
    }

    /**
     * @param int|null $memberId 本人或后台已授权
     * @param int $id 实例
     * @return array<string,mixed> 已核验的内部资料
     */
    private function eligible(?int $memberId, int $id): array
    {
        $service = $this->services->service($id);
        if (($memberId !== null && $service['member_id'] !== $memberId) || $service['product_type'] !== 'cloud_server') {
            throw new BusinessException('云服务器不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        if ($service['status'] !== 'active' || !$service['expires_at'] || $service['expires_at'] <= date('Y-m-d H:i:s')
            || ($service['source']['status'] ?? null) !== 'succeeded' || empty($service['source']['external_id'])) {
            throw new BusinessException('实例未开通、已暂停或已到期，无法连接VNC', ApiErrorCode::CONFLICT, 409);
        }
        return $service;
    }
}
