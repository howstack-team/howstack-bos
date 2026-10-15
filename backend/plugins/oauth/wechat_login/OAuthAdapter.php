<?php

declare(strict_types=1);

namespace plugins\oauth\wechat_login;

use app\shared\plugin\contract\OAuthAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\configuration\OAuthFields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\oauth\OAuthIdentity;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use Throwable;

/** 官方网站应用 OAuth；不使用全局 Session，也不向浏览器返回令牌。 */
final readonly class OAuthAdapter implements OAuthAdapterInterface
{
    /**
     * @param HttpClientInterface $http 宿主 HTTP 能力
     */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $v = $configuration->values;
        $appId = Fields::text($v, 'app_id');
        if (!preg_match('/^wx[a-f0-9]{16}$/D', $appId)) {
            throw new PluginException('应用 ID 无效', 'invalid_configuration');
        }
        return new Configuration([
            'app_id' => $appId, 'app_secret' => Fields::text($v, 'app_secret'),
            'redirect_url' => Fields::httpsCallback($v, 'redirect_url'),
        ]);
    }

    /** @inheritDoc */
    public function authorize(string $state, Configuration $configuration): string
    {
        OAuthFields::state($state);
        $c = $this->validateConfiguration($configuration)->values;
        return 'https://open.weixin.qq.com/connect/qrconnect?' . http_build_query([
            'appid' => $c['app_id'], 'redirect_uri' => $c['redirect_url'],
            'response_type' => 'code', 'scope' => 'snsapi_login', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986) . '#wechat_redirect';
    }

    /** @inheritDoc */
    public function authenticate(string $code, Configuration $configuration): OAuthIdentity
    {
        OAuthFields::code($code);
        $c = $this->validateConfiguration($configuration)->values;
        try {
            $reply = $this->http->get('https://api.weixin.qq.com/sns/oauth2/access_token', [
                'appid' => $c['app_id'], 'secret' => $c['app_secret'], 'code' => $code, 'grant_type' => 'authorization_code',
            ]);
            $token = $reply->json();
            if (!$reply->isSuccessful() || isset($token['errcode']) || !is_string($token['access_token'] ?? null)
                || !is_string($token['openid'] ?? null) || !is_int($token['expires_in'] ?? null) || $token['expires_in'] < 1
                || !in_array('snsapi_login', explode(',', $token['scope'] ?? ''), true)) {
                throw new PluginException('微信授权未完成', 'oauth_failed');
            }
            $reply = $this->http->get('https://api.weixin.qq.com/sns/userinfo', [
                'access_token' => $token['access_token'], 'openid' => $token['openid'], 'lang' => 'zh_CN',
            ]);
            $profile = $reply->json();
            if (!$reply->isSuccessful() || isset($profile['errcode']) || ($profile['openid'] ?? null) !== $token['openid']
                || (isset($profile['unionid'], $token['unionid']) && $profile['unionid'] !== $token['unionid'])) {
                throw new PluginException('微信身份校验失败', 'oauth_failed');
            }
            $avatar = $profile['headimgurl'] ?? null;
            return new OAuthIdentity('wechat_login', $c['app_id'], $token['openid'], $profile['unionid'] ?? $token['unionid'] ?? null,
                $profile['nickname'] ?? '', is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null);
        } catch (Throwable) {
            // 丢弃带有 secret/access_token 查询参数的异常链。
            throw new PluginException('微信登录未完成，请重新发起登录', 'oauth_failed');
        }
    }
}
