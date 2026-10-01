<?php

declare(strict_types=1);

namespace app\modules\admin\auth\contract;

use app\exception\BusinessException;

/**
 * 在多个认证 Worker 之间共享同一客户端的登录尝试计数。
 */
interface AdminLoginRateLimiterInterface
{
    /**
     * 计入一次登录尝试，并判断当前窗口是否仍允许认证。
     * @param string $clientIp      统一解析后的客户端 IP
     * @param int    $limit         窗口内最多允许的尝试次数，包含成功与失败
     * @param int    $windowSeconds 固定计数窗口秒数
     * @return bool 是否允许继续认证
     * @throws BusinessException 共享计数存储不可用时抛出，不允许降级放行
     */
    public function allows(string $clientIp, int $limit, int $windowSeconds): bool;
}
