<?php

declare(strict_types=1);

namespace app\modules\member\controller;

use app\modules\member\application\LoginMemberService;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\MemberAuthMiddleware;
use app\modules\member\middleware\MemberLoginRateLimitMiddleware;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\modules\member\application\MemberCodeAuthenticationService;
use app\modules\member\contract\MemberTokenServiceInterface;
use app\modules\member\validation\MemberCodeAuthValidator;
use app\modules\member\middleware\MemberWriteOriginMiddleware;
use app\shared\contract\ClientIpResolverInterface;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 会员控制台登录、当前身份与退出。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/auth')]
final readonly class MemberAuthController
{
    public function __construct(
        private LoginMemberService $login,
        private MemberAuthenticationContext $context,
        private ApiResponse $responses,
        private MemberCodeAuthenticationService $codes,
        private MemberTokenServiceInterface $tokens,
        private ClientIpResolverInterface $ips,
        private \app\modules\member\application\MemberOAuthAuthenticationService $oauth,
    ) {
    }

    #[Post(path: '/login', name: 'member.auth.login')]
    #[Middleware(MemberWriteOriginMiddleware::class, MemberLoginRateLimitMiddleware::class)]
    public function login(Request $request): Response
    {
        $input = MemberCodeAuthValidator::passwordLogin($request->post());
        $result = $this->login->login($input['account'], $input['password'], (bool) $input['remember_me']);

        return $this->sessionResponse($request, $result, (bool) $input['remember_me']);
    }

    #[Get(path: '/me', name: 'member.auth.me')]
    #[Middleware(MemberAuthMiddleware::class)]
    public function me(Request $request): Response
    {
        return $this->noStore($this->responses->success($request, ['user' => $this->context->account($request)->profile()]));
    }

    #[Post(path: '/logout', name: 'member.auth.logout')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function logout(Request $request): Response
    {
        $this->tokens->revoke((string) $request->cookie($this->cookieName(), ''));
        return $this->noStore($this->responses->success($request, null))->cookie(
            $this->cookieName(), '', 0, '/', '', $this->secureCookie(), true, 'Strict',
        );
    }

    #[Get(path: '/options', name: 'member.auth.options')]
    public function options(Request $request): Response
    {
        $binding = (string) $request->cookie('hlyq_auth_binding', '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $binding)) $binding = bin2hex(random_bytes(32));
        return $this->noStore($this->responses->success($request, array_replace($this->codes->options(), ['oauth_providers' => $this->oauth->providers()])))
            ->cookie('hlyq_auth_binding', $binding, time() + 1800, '/', '', $this->secureCookie(), true, 'Strict');
    }

    #[Post(path: '/login-code', name: 'member.auth.login-code')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function loginCode(Request $request): Response
    {
        $input = MemberCodeAuthValidator::input($request->post(), true, false);
        return $this->noStore($this->responses->success($request, $this->codes->send('login', $input['mobile'], $this->binding($request), $this->ips->resolve($request))));
    }

    #[Post(path: '/login-sms', name: 'member.auth.login-sms')]
    #[Middleware(MemberWriteOriginMiddleware::class, MemberLoginRateLimitMiddleware::class)]
    public function loginSms(Request $request): Response
    {
        $input = MemberCodeAuthValidator::input($request->post(), true, true);
        $result = $this->codes->login($input['mobile'], $input['challenge_id'], $input['code'], $this->binding($request), $this->ips->resolve($request), (bool) $input['remember_me']);
        return $this->sessionResponse($request, $result, (bool) $input['remember_me']);
    }

    #[Post(path: '/password-reset-code', name: 'member.auth.password-reset-code')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function resetCode(Request $request): Response
    {
        $input = MemberCodeAuthValidator::input($request->post(), false, false);
        return $this->noStore($this->responses->success($request, $this->codes->send('password_reset', $input['account'], $this->binding($request), $this->ips->resolve($request))));
    }

    #[Post(path: '/password-reset', name: 'member.auth.password-reset')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function resetPassword(Request $request): Response
    {
        $input = MemberCodeAuthValidator::input($request->post(), false, true);
        $this->codes->reset($input['account'], $input['challenge_id'], $input['code'], $input['password'], $this->binding($request), $this->ips->resolve($request));
        return $this->noStore($this->responses->success($request, null));
    }

    private function binding(Request $request): string
    {
        $binding = (string) $request->cookie('hlyq_auth_binding', '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $binding)) {
            throw new BusinessException('认证会话已失效，请刷新页面重试', ApiErrorCode::FORBIDDEN, 403);
        }
        return $binding;
    }

    /** @param array{user: array<string, mixed>, token: string} $result 服务端会话结果 */
    private function sessionResponse(Request $request, array $result, bool $remember): Response
    {
        return $this->noStore($this->responses->success($request, ['user' => $result['user']]))->cookie(
            $this->cookieName(), $result['token'], $remember ? time() + (int) config('authentication.member.remember_ttl', 2592000) : null,
            '/', '', $this->secureCookie(), true, 'Strict',
        );
    }

    private function cookieName(): string
    {
        return (string) config('authentication.member.cookie_name', 'hlyq_member_token');
    }

    private function secureCookie(): bool
    {
        return (bool) config('authentication.member.secure_cookie', false);
    }

    private function noStore(Response $response): Response
    {
        return $response->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
