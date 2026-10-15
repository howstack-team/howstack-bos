<?php

declare(strict_types=1);

namespace plugins\oauth\qq_login;

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
        if (!preg_match('/^[0-9]{5,20}$/D', $appId)) {
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
        return 'https://graph.qq.com/oauth2.0/authorize?' . http_build_query([
            'client_id' => $c['app_id'], 'redirect_uri' => $c['redirect_url'],
            'response_type' => 'code', 'scope' => 'get_user_info', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @inheritDoc */
    public function authenticate(string $code, Configuration $configuration): OAuthIdentity
    {
        OAuthFields::code($code);
        $c = $this->validateConfiguration($configuration)->values;
        try {
            $reply = $this->http->get('https://graph.qq.com/oauth2.0/token', [
                'grant_type' => 'authorization_code', 'client_id' => $c['app_id'], 'client_secret' => $c['app_secret'],
                'code' => $code, 'redirect_uri' => $c['redirect_url'],
            ]);
            $token = [];
            parse_str($reply->body, $token);
            if (!$reply->isSuccessful() || !is_string($token['access_token'] ?? null)
                || !preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $token['access_token'])
                || !ctype_digit((string) ($token['expires_in'] ?? '')) || (int) $token['expires_in'] < 1) {
                throw new PluginException('QQ 授权未完成', 'oauth_failed');
            }
            $reply = $this->http->get('https://graph.qq.com/oauth2.0/me', ['access_token' => $token['access_token']]);
            // 官方接口为 callback(JSON)，只解析 JSON，不执行 JavaScript。
            if (!$reply->isSuccessful() || !preg_match('/^\s*callback\s*\(\s*(\{.*\})\s*\)\s*;?\s*$/sD', $reply->body, $matches)) {
                throw new PluginException('QQ 身份响应无效', 'oauth_failed');
            }
            $identity = json_decode($matches[1], true, 16, JSON_THROW_ON_ERROR);
            if (($identity['client_id'] ?? null) !== $c['app_id'] || !is_string($identity['openid'] ?? null)) {
                throw new PluginException('QQ 应用身份不匹配', 'oauth_failed');
            }
            $reply = $this->http->get('https://graph.qq.com/user/get_user_info', [
                'access_token' => $token['access_token'], 'oauth_consumer_key' => $c['app_id'], 'openid' => $identity['openid'],
            ]);
            $profile = $reply->json();
            if (!$reply->isSuccessful() || ($profile['ret'] ?? null) !== 0) {
                throw new PluginException('QQ 用户资料获取失败', 'oauth_failed');
            }
            $avatar = $profile['figureurl_qq_2'] ?? null;
            return new OAuthIdentity('qq_login', $c['app_id'], $identity['openid'], $identity['unionid'] ?? null,
                $profile['nickname'] ?? '', is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null);
        } catch (Throwable) {
            throw new PluginException('QQ 登录未完成，请重新发起登录', 'oauth_failed');
        }
    }
}
