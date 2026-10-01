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
use support\validation\Validator;

/** 会员控制台登录、当前身份与退出。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/auth')]
final readonly class MemberAuthController
{
    public function __construct(
        private LoginMemberService $login,
        private MemberAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    #[Post(path: '/login', name: 'member.auth.login')]
    #[Middleware(MemberLoginRateLimitMiddleware::class)]
    public function login(Request $request): Response
    {
        $input = Validator::make(['mobile' => $request->post('mobile'), 'password' => $request->post('password')], [
            'mobile' => ['required', 'string', 'regex:/^\+?[0-9]{6,20}$/'], 'password' => 'required|string|min:1|max:1024',
        ])->validate();
        $result = $this->login->login($input['mobile'], $input['password']);

        return $this->noStore($this->responses->success($request, ['user' => $result['user']]))->cookie(
            $this->cookieName(), $result['token'], null, '/', '', $this->secureCookie(), true, 'Strict',
        );
    }

    #[Get(path: '/me', name: 'member.auth.me')]
    #[Middleware(MemberAuthMiddleware::class)]
    public function me(Request $request): Response
    {
        return $this->noStore($this->responses->success($request, ['user' => $this->context->account($request)->profile()]));
    }

    #[Post(path: '/logout', name: 'member.auth.logout')]
    public function logout(Request $request): Response
    {
        return $this->noStore($this->responses->success($request, null))->cookie(
            $this->cookieName(), '', 0, '/', '', $this->secureCookie(), true, 'Strict',
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
