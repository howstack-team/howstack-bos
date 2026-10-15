<?php

declare(strict_types=1);
namespace app\modules\admin\auth\controller;
use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\application\{AdminMfaService, AdminAuthenticationContext};
use app\modules\admin\auth\contract\{AdminAccountRepositoryInterface, AdminAccessTokenServiceInterface, AdminLoginRateLimiterInterface};
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\admin\auth\validation\AdminMfaValidator;
use app\shared\http\{ApiResponse, ApiErrorCode};
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Post, RouteGroup};
use support\{Request, Response};
/** 本人验证器管理；没有为其他账号远程关闭MFA的HTTP入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/profile/mfa')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminMfaController
{
    public function __construct(private AdminMfaService $mfa, private AdminAuthenticationContext $context, private AdminAccessControlService $access,
        private AdminAccountRepositoryInterface $accounts, private AdminAccessTokenServiceInterface $tokens, private AdminLoginRateLimiterInterface $limiter,
        private ApiResponse $responses) {}
    #[Get(path: '', name: 'admin.profile.mfa.show')]
    public function status(Request $request): Response
    {
        $actor = $this->context->account($request);
        $this->access->requirePermission($actor, 'admin.profile.view');
        return $this->responses->success($request, $this->mfa->status($actor->id))->withHeader('Cache-Control', 'no-store');
    }
    #[Post(path: '/setup', name: 'admin.profile.mfa.setup')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function setup(Request $request): Response
    {
        $actor = $this->actor($request);
        $data = AdminMfaValidator::input($request->post(), code: false);
        return $this->responses->success($request, $this->mfa->begin($actor, $data['current_password']))->withHeader('Cache-Control', 'no-store');
    }
    #[Post(path: '/enable', name: 'admin.profile.mfa.enable')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function enable(Request $request): Response
    {
        $actor = $this->actor($request);
        $data = AdminMfaValidator::input($request->post(), setup: true);
        $codes = $this->mfa->confirm($actor, $data['current_password'], $data['setup_id'], $data['code']);
        return $this->updated($request, $actor, $codes);
    }
    #[Post(path: '/disable', name: 'admin.profile.mfa.disable')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function disable(Request $request): Response { return $this->change($request, true); }
    #[Post(path: '/recovery-codes', name: 'admin.profile.mfa.recovery')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function recovery(Request $request): Response { return $this->change($request, false); }
    private function change(Request $request, bool $disable): Response
    {
        $actor = $this->actor($request);
        $data = AdminMfaValidator::input($request->post());
        $codes = $this->mfa->change($actor, $data['current_password'], $data['code'], $data['recovery'], $disable);
        return $this->updated($request, $actor, $codes);
    }
    private function actor(Request $request): AdminAccount
    {
        $actor = $this->context->account($request);
        $this->access->requirePermission($actor, 'admin.profile.mfa');
        if (!$this->limiter->allows('admin-mfa:' . $actor->id, 10, 300)) throw new BusinessException('安全操作过于频繁，请5分钟后重试', ApiErrorCode::TOO_MANY_REQUESTS, 429);
        return $actor;
    }
    /** 认证修订已变更，给当前浏览器换新凭证；其他浏览器旧凭证全部失效。 */
    private function updated(Request $request, AdminAccount $actor, array $codes): Response
    {
        $fresh = $this->accounts->findById($actor->id);
        $token = $this->tokens->issue($fresh);
        return $this->responses->success($request, ['recovery_codes' => $codes] + $this->mfa->status($actor->id), '两步验证设置已更新')
            ->cookie((string) config('authentication.admin.cookie_name', 'hlyq_admin_token'), $token->value, null, '/', '', (bool) config('authentication.admin.secure_cookie', false), true, 'Strict')
            ->withHeader('Cache-Control', 'no-store');
    }
}
