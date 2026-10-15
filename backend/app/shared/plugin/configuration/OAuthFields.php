<?php

declare(strict_types=1);

namespace app\shared\plugin\configuration;

use app\shared\plugin\exception\PluginException;

/** 固定字段校验，不持有 Session、令牌或当前用户。 */
final class OAuthFields
{
    /**
     * @param string $state 宿主随机状态
     * @return void
     * @throws PluginException 状态无效
     */
    public static function state(string $state): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) {
            throw new PluginException('第三方登录状态无效', 'invalid_request');
        }
    }

    /**
     * @param string $code 授权码
     * @return void
     * @throws PluginException 授权码无效
     */
    public static function code(string $code): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,512}$/D', $code)) {
            throw new PluginException('第三方登录授权码无效', 'invalid_request');
        }
    }
}
