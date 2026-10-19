<?php

declare(strict_types=1);

namespace app\modules\service_instance\contract;

/** 只记录已认证操作者、目标和结果，禁止记录VNC及系统凭据。 */
interface VncAuditLoggerInterface
{
    /**
     * @param string $actor 认证身份作用域
     * @param int $id 实例
     * @param string $outcome issued/failed
     * @return void 登记安全访问事件
     */
    public function record(string $actor, int $id, string $outcome): void;
}
