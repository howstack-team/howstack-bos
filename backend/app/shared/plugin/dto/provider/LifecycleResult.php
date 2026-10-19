<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\provider;

/** 受理与完成分开；unknown/failed 均不授权核心改为成功。 */
final readonly class LifecycleResult
{
    /**
     * @param string $status succeeded/pending/unknown/failed
     * @param string|null $confirmedExpiresAt renew 成功必须返回已确认的绝对到期时间
     * @param string|null $providerTaskId 原供应任务标识
     * @param string|null $powerStatus 最近确认电源状态on/off
     */
    public function __construct(
        public string $status,
        public ?string $confirmedExpiresAt = null,
        public ?string $providerTaskId = null,
        public ?string $powerStatus = null,
    ) {
        if (!in_array($status, ['succeeded', 'pending', 'unknown', 'failed'], true)
            || ($providerTaskId !== null && ($providerTaskId === '' || strlen($providerTaskId) > 128 || preg_match('/[\x00-\x1F\x7F]/', $providerTaskId)))
            || ($powerStatus !== null && !in_array($powerStatus, ['on', 'off', 'unknown'], true))) {
            throw new \InvalidArgumentException('无效生命周期结果');
        }
    }
}
