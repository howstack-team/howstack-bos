<?php

declare(strict_types=1);

namespace app\modules\channel\contract\captcha;

use app\shared\plugin\dto\captcha\CaptchaContext;

/** 宿主挑战与证明去重存储，不持久化平台秘密或原始证明。 */
interface CaptchaAttemptStoreInterface
{
    /**
     * @param int $channelId 已启用通道
     * @param int $revision 配置修订
     * @param string $binding 服务端会话绑定
     * @param string $purpose 业务场景
     * @param string $ip 可信客户端 IP
     * @return CaptchaContext 五分钟挑战
     */
    public function issue(int $channelId, int $revision, string $binding, string $purpose, string $ip): CaptchaContext;

    /**
     * @param CaptchaContext $context 原会话、场景及 IP
     * @return array{channel_id: int, revision: int}|null 核对绑定后原子消费
     */
    public function consume(CaptchaContext $context): ?array;

    /**
     * @param string $pluginId 固定平台
     * @param string $fingerprint 稳定票据 SHA256
     * @return bool 原子预留十分钟，重复或未知结果不重用票据
     */
    public function reserveProof(string $pluginId, string $fingerprint): bool;
}
