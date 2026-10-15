<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\channel\application\{ChannelExecutionConfiguration, oauth\OAuthChannelService};
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\member\contract\{MemberAuthRepositoryInterface, MemberOAuthRepositoryInterface, MemberTokenServiceInterface};
use app\modules\member\domain\{MemberAccount, MemberPassword};
use app\shared\http\ApiErrorCode;
use app\shared\plugin\exception\PluginException;

/** 会员第三方登录边界；供应令牌不离开通道层，首次授权必须验证平台账号。 */
final readonly class MemberOAuthAuthenticationService
{
    private const array PROVIDERS = ['qq' => 'qq_login', 'wechat' => 'wechat_login'];

    public function __construct(private ChannelRepositoryInterface $channels, private OAuthChannelService $oauth,
        private ChannelExecutionConfiguration $configuration, private MemberOAuthRepositoryInterface $identities,
        private MemberAuthRepositoryInterface $members, private MemberTokenServiceInterface $tokens)
    {
    }

    /** @return list<string> 只展示已启用、已安装且配置有效的登录渠道。 */
    public function providers(): array
    {
        return array_keys($this->available());
    }

    public function start(string $provider, bool $remember, string $binding, bool $mobile): string
    {
        $row = $this->available()[$provider] ?? null;
        if ($row === null) throw new BusinessException('该第三方登录方式暂不可用', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        try {
            $url = $this->oauth->authorize($row['id'], $row['revision'], $binding, $remember);
        } catch (PluginException) {
            throw new BusinessException('第三方登录授权暂不可用，请稍后重试', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        }
        // QQ 官方H5展示参数，仅影响平台登录UI，不修改回调与state。
        if ($mobile && $provider === 'qq') $url .= '&display=mobile';
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host !== ($provider === 'qq' ? 'graph.qq.com' : 'open.weixin.qq.com')) {
            throw new BusinessException('第三方授权地址无效', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        }
        return $url;
    }

    /** @return array{status: string, provider?: string, expires_in?: int, ticket?: string, user?: array<string, mixed>, token?: string, remember_me?: bool} */
    public function complete(string $state, string $code, string $binding): array
    {
        try {
            $attempt = $this->oauth->completeAttempt($state, $code, $binding);
        } catch (PluginException) {
            throw new BusinessException('第三方授权已过期或未完成，请重新登录', ApiErrorCode::CONFLICT, 409);
        }
        $identity = $attempt['identity'];
        if (!in_array($identity->provider, self::PROVIDERS, true)) throw new BusinessException('第三方登录方式不受支持', ApiErrorCode::FORBIDDEN, 403);
        $memberId = $this->identities->memberId($identity);
        if ($memberId !== null) {
            $member = $this->members->findById($memberId);
            if ($member === null || $member->status !== 1) throw new BusinessException('此账号暂不可登录，请联系管理员', ApiErrorCode::FORBIDDEN, 403);
            return $this->session($member, $attempt['remember_me']);
        }
        $ticket = bin2hex(random_bytes(32));
        $this->identities->issue(hash('sha256', $ticket), hash('sha256', $binding), $identity, $attempt);
        return ['status' => 'binding_required', 'provider' => array_search($identity->provider, self::PROVIDERS, true),
            'expires_in' => 600, 'ticket' => $ticket];
    }

    /** @return array{provider: string, expires_in: int} */
    public function pending(string $ticket, string $binding): array
    {
        $row = $this->requirePending($ticket, $binding);
        return ['provider' => array_search($row['provider'], self::PROVIDERS, true), 'expires_in' => max(1, strtotime($row['expires_at']) - time())];
    }

    /** @return array{status: string, user: array<string, mixed>, token: string, remember_me: bool} */
    public function bind(string $ticket, string $binding, string $account, string $password): array
    {
        $pending = $this->requirePending($ticket, $binding);
        $member = str_contains($account, '@') ? $this->members->findByEmail(strtolower($account)) : $this->members->findByMobile($account);
        $verified = MemberPassword::matches($member, $password) ? $member : null;
        $bound = $this->identities->bind(hash('sha256', $ticket), hash('sha256', $binding), $verified);
        if ($bound === null) throw new BusinessException('账号或密码错误，或关联请求已失效', ApiErrorCode::VALIDATION_FAILED, 422,
            ['errors' => ['password' => ['账号或密码错误，或关联请求已失效']]]);
        return $this->session($bound, $pending['remember_me']);
    }

    /** @return array<string, mixed> */
    private function requirePending(string $ticket, string $binding): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) throw $this->expired();
        $row = $this->identities->pending(hash('sha256', $ticket), hash('sha256', $binding));
        if ($row === null || !in_array($row['provider'], self::PROVIDERS, true)) throw $this->expired();
        [$provider, $config] = $this->configuration->resolve('oauth', $row['channel_id'], $row['channel_revision'], true);
        if ($provider !== $row['provider'] || ($config->values['app_id'] ?? null) !== $row['app_id']) throw $this->expired();
        return $row;
    }

    private function expired(): BusinessException
    {
        return new BusinessException('第三方账号关联已失效，请重新授权登录', ApiErrorCode::CONFLICT, 409);
    }

    /** @return array{status: string, user: array<string, mixed>, token: string, remember_me: bool} */
    private function session(MemberAccount $member, bool $remember): array
    {
        $token = $this->tokens->issue($member, $remember);
        $this->members->recordLogin($member->id);
        return ['status' => 'authenticated', 'user' => $member->profile(), 'token' => $token, 'remember_me' => $remember];
    }

    /** @return array<string, array{id: int, revision: int}> 每个平台按后台排序取第一个可用通道，状态封存原通道修订。 */
    private function available(): array
    {
        $result = [];
        for ($page = 1; ; $page++) {
            $rows = $this->channels->list('oauth', ['status' => 1, 'page' => $page, 'page_size' => 100, 'sort_field' => 'sort_order', 'sort_order' => 'asc']);
            foreach ($rows['data'] as $row) {
                $provider = array_search($row['plugin_id'], self::PROVIDERS, true);
                if ($provider === false || isset($result[$provider]) || !$row['platform_available'] || !$row['configuration_current']) continue;
                $result[$provider] = ['id' => (int) $row['id'], 'revision' => (int) $row['revision']];
            }
            if ($page * 100 >= $rows['total']) break;
        }
        return $result;
    }
}
