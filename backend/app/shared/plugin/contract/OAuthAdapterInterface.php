<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\oauth\OAuthIdentity;

/** 第三方登录 v1；state 的生成、浏览器绑定、原子消费及会员会话由宿主处理。 */
interface OAuthAdapterInterface extends ConfigurablePluginInterface
{
    /**
     * @param string $state 宿主生成的一次性状态
     * @param Configuration $configuration 本次配置
     * @return string 固定官方授权页
     */
    public function authorize(string $state, Configuration $configuration): string;

    /**
     * @param string $code 已验证 state 后的授权码
     * @param Configuration $configuration 同一应用配置
     * @return OAuthIdentity 经服务端平台验证的身份，不含访问令牌
     */
    public function authenticate(string $code, Configuration $configuration): OAuthIdentity;
}
