<?php

declare(strict_types=1);

namespace app\modules\channel\application\oauth;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\modules\channel\contract\oauth\OAuthStateStoreInterface;
use app\shared\plugin\dto\oauth\OAuthIdentity;
use app\shared\plugin\configuration\OAuthFields;
use app\shared\plugin\exception\PluginException;

/** 核心会员认证用例调用；生成/消费 state 后才访问平台，返回身份不自行创建会员或管理员会话。 */
final readonly class OAuthChannelService
{
    /**
     * @param ChannelExecutionConfiguration $configuration 通道边界
     * @param TransactionAdapterFactoryInterface $adapters 固定接口
     * @param OAuthStateStoreInterface $states 一次性状态
     */
    public function __construct(
        private ChannelExecutionConfiguration $configuration,
        private TransactionAdapterFactoryInterface $adapters,
        private OAuthStateStoreInterface $states,
    ) {
    }

    /**
     * @param int $channelId 已启用通道
     * @param int $revision 原修订
     * @param string $browserBinding 服务端会话随机绑定值
     * @return string 官方授权链接
     */
    public function authorize(int $channelId, int $revision, string $browserBinding, ?bool $remember = null): string
    {
        [$id, $config] = $this->configuration->resolve('oauth', $channelId, $revision, true);
        $adapter = $this->adapters->oauth($id);
        $state = $this->states->issue(['channel_id' => $channelId, 'revision' => $revision] + ($remember === null ? [] : ['remember_me' => $remember]), $browserBinding);
        return $adapter->authorize($state, $config);
    }

    /**
     * @param string $state 回调状态
     * @param string $code 授权码
     * @param string $browserBinding 原服务端会话绑定
     * @return OAuthIdentity 经平台验证的身份
     */
    public function complete(string $state, string $code, string $browserBinding): OAuthIdentity
    {
        return $this->completeAttempt($state, $code, $browserBinding)['identity'];
    }

    /** @return array{identity: OAuthIdentity, channel_id: int, revision: int, remember_me: bool} */
    public function completeAttempt(string $state, string $code, string $browserBinding): array
    {
        OAuthFields::code($code);
        $attempt = $this->states->consume($state, $browserBinding);
        if ($attempt === null) {
            throw new PluginException('登录状态已过期、已使用或会话不匹配，请重新发起登录', 'oauth_failed');
        }
        [$id, $config] = $this->configuration->resolve('oauth', $attempt['channel_id'], $attempt['revision'], true);
        $identity = $this->adapters->oauth($id)->authenticate($code, $config);
        if ($identity->provider !== $id || $identity->appId !== ($config->values['app_id'] ?? null)) {
            throw new PluginException('第三方身份与授权应用不匹配', 'oauth_failed');
        }
        return ['identity' => $identity, 'channel_id' => $attempt['channel_id'], 'revision' => $attempt['revision'], 'remember_me' => $attempt['remember_me'] ?? false];
    }
}
