<?php

declare(strict_types=1);

namespace app\modules\channel\application\captcha;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\modules\channel\contract\captcha\CaptchaAttemptStoreInterface;
use app\shared\plugin\dto\captcha\CaptchaContext;
use app\shared\plugin\dto\captcha\CaptchaProof;
use app\shared\plugin\dto\captcha\CaptchaResult;
use Throwable;

/** 供核心登录、注册等用例调用；插件不能签发会话，浏览器不能自行选择保护策略。 */
final readonly class CaptchaChannelService
{
    /**
     * @param ChannelExecutionConfiguration $configuration 已启用配置边界
     * @param TransactionAdapterFactoryInterface $adapters 固定插件入口
     * @param CaptchaAttemptStoreInterface $attempts 宿主一次性缓存
     */
    public function __construct(
        private ChannelExecutionConfiguration $configuration,
        private TransactionAdapterFactoryInterface $adapters,
        private CaptchaAttemptStoreInterface $attempts,
    ) {
    }

    /**
     * @param int $channelId 由核心场景策略指定的通道
     * @param int $revision 当前配置修订
     * @param string $binding 保存在服务端会话的随机值
     * @param string $purpose 由核心指定的场景
     * @param string $ip 可信代理处理后的 IP
     * @return array{challenge_id: string, expires_in: int, provider: string, parameters: array<string, string|int>} 公开挑战
     */
    public function initialize(int $channelId, int $revision, string $binding, string $purpose, string $ip): array
    {
        [$pluginId, $config] = $this->configuration->resolve('captcha', $channelId, $revision, true);
        $adapter = $this->adapters->captcha($pluginId);
        $context = $this->attempts->issue($channelId, $revision, $binding, $purpose, $ip);
        $challenge = $adapter->initialize($context, $config);
        return ['challenge_id' => $context->id, 'expires_in' => 300, 'provider' => $challenge->provider, 'parameters' => $challenge->parameters];
    }

    /**
     * @param string $challengeId 原挑战
     * @param CaptchaProof $proof 原始平台证明
     * @param string $binding 原服务端会话绑定
     * @param string $purpose 当前核心业务场景
     * @param string $ip 当前可信客户端 IP
     * @return CaptchaResult 仅 verified 继续同一次业务请求，不签发可重复使用通行证
     */
    public function verify(string $challengeId, CaptchaProof $proof, string $binding, string $purpose, string $ip): CaptchaResult
    {
        try {
            $context = new CaptchaContext($challengeId, $binding, $purpose, $ip);
            $attempt = $this->attempts->consume($context);
            if ($attempt === null) {
                return new CaptchaResult('rejected');
            }
            [$pluginId, $config] = $this->configuration->resolve('captcha', $attempt['channel_id'], $attempt['revision'], true);
            $adapter = $this->adapters->captcha($pluginId);
            if (!$this->attempts->reserveProof($pluginId, $adapter->fingerprint($proof, $context, $config))) {
                return new CaptchaResult('rejected');
            }
            return $adapter->verify($proof, $context, $config);
        } catch (Throwable) {
            // Redis、平台、配置修订或输入异常均不得被转换为验证通过。
            return new CaptchaResult('unknown');
        }
    }
}
