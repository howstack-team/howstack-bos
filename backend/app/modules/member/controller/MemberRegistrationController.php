<?php

declare(strict_types=1);

namespace app\modules\member\controller;

use app\modules\member\application\{RegisterMemberService, SendRegistrationCodeService};
use app\modules\member\middleware\MemberWriteOriginMiddleware;
use app\modules\member\validation\MemberRegistrationValidator;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\shared\contract\ClientIpResolverInterface;
use app\shared\http\{ApiResponse, ApiErrorCode};
use app\exception\BusinessException;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};

#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/auth')]
final readonly class MemberRegistrationController
{
    private const string COOKIE = 'hlyq_registration_session';

    public function __construct(private GetSystemSettingsService $settings, private RegisterMemberService $registration,
        private SendRegistrationCodeService $codes, private ClientIpResolverInterface $ips, private ApiResponse $responses) {
    }

    #[Get(path: '/registration', name: 'member.auth.registration')]
    public function options(Request $request): Response
    {
        $binding = (string) $request->cookie(self::COOKIE, '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $binding)) $binding = bin2hex(random_bytes(32));
        return $this->noStore($this->responses->success($request, $this->settings->registration()))
            ->cookie(self::COOKIE, $binding, time() + 1800, '/', '', (bool) config('authentication.member.secure_cookie', false), true, 'Strict');
    }

    #[Post(path: '/registration-code', name: 'member.auth.registration-code')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function code(Request $request): Response
    {
        $input = MemberRegistrationValidator::input($request->post(), false);
        return $this->noStore($this->responses->success($request, $this->codes->send($input['method'], $input['account'], $this->binding($request), $this->ips->resolve($request))));
    }

    #[Post(path: '/register', name: 'member.auth.register')]
    #[Middleware(MemberWriteOriginMiddleware::class)]
    public function register(Request $request): Response
    {
        $input = MemberRegistrationValidator::input($request->post(), true);
        $profile = $this->registration->register($input, $this->binding($request), $this->ips->resolve($request));
        return $this->noStore($this->responses->success($request, ['user' => $profile]));
    }

    private function binding(Request $request): string
    {
        $binding = (string) $request->cookie(self::COOKIE, '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $binding)) throw new BusinessException('注册会话已失效，请刷新页面重试', ApiErrorCode::FORBIDDEN, 403);
        return $binding;
    }

    private function noStore(Response $response): Response
    {
        return $response->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
