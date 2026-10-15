<?php

declare(strict_types=1);

namespace app\modules\admin\auth\middleware;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\contract\AdminAccessTokenServiceInterface;
use app\modules\admin\auth\contract\AdminAccountRepositoryInterface;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 验证运营后台 JWT 并建立请求级管理员上下文。
 */
final readonly class AdminAuthMiddleware implements MiddlewareInterface
{
    /**
     * 初始化管理员认证中间件。
     * @param AdminAccessTokenServiceInterface $tokens  管理员令牌服务
     * @param AdminAccountRepositoryInterface  $accounts 管理员账号仓储
     * @param AdminAuthenticationContext       $context  请求级管理员上下文
     */
    public function __construct(
        private AdminAccessTokenServiceInterface $tokens,
        private AdminAccountRepositoryInterface $accounts,
        private AdminAuthenticationContext $context,
    ) {
    }

    /**
     * 验证管理员登录状态并继续处理请求。
     * @param Request  $request 当前 HTTP 请求
     * @param callable $handler 下游请求处理器
     * @return Response 下游响应
     * @throws AdminAuthenticationException 令牌无效、账号不存在或账号停用时抛出
     */
    public function process(Request $request, callable $handler): Response
    {
        $token = $this->resolveToken($request);
        if ($token === '') {
            throw AdminAuthenticationException::invalidToken();
        }

        $claims = $this->tokens->parse($token);
        $account = $this->accounts->findById($claims->adminId);
        if ($account === null || !$this->tokens->matchesAccount($claims, $account)) {
            throw AdminAuthenticationException::invalidToken();
        }
        if (!$account->isActive()) {
            throw AdminAuthenticationException::accountDisabled();
        }

        $this->context->set($request, $account);

        return $handler($request);
    }

    /**
     * 从 HttpOnly Cookie 或 Bearer Header 读取访问令牌。
     * @param Request $request 当前 HTTP 请求
     * @return string JWT 原始值，不存在时返回空字符串
     */
    private function resolveToken(Request $request): string
    {
        $cookieName = (string) config('authentication.admin.cookie_name', 'hlyq_admin_token');
        $cookieToken = $request->cookie($cookieName, '');
        if (is_string($cookieToken) && $cookieToken !== '') {
            return $cookieToken;
        }

        $authorization = trim((string) $request->header('authorization', ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }
}
