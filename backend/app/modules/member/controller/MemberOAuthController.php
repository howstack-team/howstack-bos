<?php

declare(strict_types=1);

namespace app\modules\member\controller;

use app\exception\BusinessException;
use app\modules\member\application\MemberOAuthAuthenticationService;
use app\modules\member\middleware\{MemberLoginRateLimitMiddleware, MemberWriteOriginMiddleware};
use app\modules\member\validation\{MemberOAuthValidator, MemberCodeAuthValidator};
use app\shared\http\{ApiResponse, ApiErrorCode};
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Post, RouteGroup};
use support\{Request, Response};

#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/auth/oauth')]
final readonly class MemberOAuthController
{
    public function __construct(private MemberOAuthAuthenticationService $oauth, private ApiResponse $responses) {}

    #[Post(path: '/start', name: 'member.auth.oauth.start')]
    #[Middleware(MemberWriteOriginMiddleware::class, MemberLoginRateLimitMiddleware::class)]
    public function start(Request $request): Response
    {
        $input = MemberOAuthValidator::start($request->post());
        $mobile = preg_match('/Mobile|Android|iPhone|iPad/i', (string) $request->header('user-agent', '')) === 1;
        return $this->noStore($this->responses->success($request, ['url' => $this->oauth->start($input['provider'], $input['remember_me'], $this->binding($request), $mobile)]));
    }

    #[Post(path: '/complete', name: 'member.auth.oauth.complete')]
    #[Middleware(MemberWriteOriginMiddleware::class, MemberLoginRateLimitMiddleware::class)]
    public function complete(Request $request): Response
    {
        $input = MemberOAuthValidator::complete($request->post());
        $result = $this->oauth->complete($input['state'], $input['code'], $this->binding($request));
        if ($result['status'] === 'authenticated') return $this->session($request, $result);
        return $this->noStore($this->responses->success($request, ['status' => 'binding_required', 'provider' => $result['provider'], 'expires_in' => $result['expires_in']]))
            ->cookie('hlyq_oauth_pending', $result['ticket'], time() + 600, '/', '', $this->secure(), true, 'Strict');
    }

    #[Get(path: '/pending', name: 'member.auth.oauth.pending')]
    public function pending(Request $request): Response
    {
        return $this->noStore($this->responses->success($request, $this->oauth->pending($this->ticket($request), $this->binding($request))));
    }

    #[Post(path: '/bind', name: 'member.auth.oauth.bind')]
    #[Middleware(MemberWriteOriginMiddleware::class, MemberLoginRateLimitMiddleware::class)]
    public function bind(Request $request): Response
    {
        $input = MemberCodeAuthValidator::passwordLogin($request->post());
        return $this->session($request, $this->oauth->bind($this->ticket($request), $this->binding($request), $input['account'], $input['password']));
    }

    /** @param array<string, mixed> $result 私有会话令牌只写HttpOnly Cookie */
    private function session(Request $request, array $result): Response
    {
        return $this->noStore($this->responses->success($request, ['status' => 'authenticated', 'user' => $result['user']]))
            ->cookie((string) config('authentication.member.cookie_name', 'hlyq_member_token'), $result['token'],
                $result['remember_me'] ? time() + (int) config('authentication.member.remember_ttl', 2592000) : null, '/', '', $this->secure(), true, 'Strict')
            ->cookie('hlyq_oauth_pending', '', 0, '/', '', $this->secure(), true, 'Strict');
    }

    private function binding(Request $request): string
    {
        $binding = (string) $request->cookie('hlyq_auth_binding', '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $binding)) throw new BusinessException('认证会话已失效，请刷新页面重试', ApiErrorCode::FORBIDDEN, 403);
        return $binding;
    }
    private function ticket(Request $request): string { return (string) $request->cookie('hlyq_oauth_pending', ''); }
    private function secure(): bool { return (bool) config('authentication.member.secure_cookie', false); }
    private function noStore(Response $response): Response { return $response->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']); }
}
