<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\provider;

/** 固定单实例生命周期请求；绝对到期时间不能由插件自行滚动累加。 */
final readonly class LifecycleRequest
{
    /**
     * @param string $requestKey 不变的幂等标识
     * @param string $externalId 原供应实例标识
     * @param string $hostname 首次创建的稳定主机名，用于校验资源归属
     * @param string $action renew/suspend/resume
     * @param string $targetExpiresAt 核心核定的绝对到期时间
     * @param int $months 续费月份，暂停及恢复为零
     * @param string|null $expectedExpiresAt 原到期时间，供插件核对订阅期限，不得自行覆盖核心目标
     */
    public function __construct(
        public string $requestKey,
        public string $externalId,
        public string $hostname,
        public string $action,
        public string $targetExpiresAt,
        public int $months = 0,
        public ?string $expectedExpiresAt = null,
    ) {
        if (!in_array($action, ['renew', 'suspend', 'resume'], true) || $externalId === ''
            || ($action === 'renew' ? ($months < 1 || $months > 120) : $months !== 0)) {
            throw new \InvalidArgumentException('无效生命周期请求');
        }
    }
}
