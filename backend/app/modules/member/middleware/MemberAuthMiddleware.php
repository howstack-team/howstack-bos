<?php

declare(strict_types=1);

namespace app\modules\member\middleware;

use app\exception\BusinessException;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\contract\MemberAuthRepositoryInterface;
use app\modules\member\contract\MemberTokenServiceInterface;
use app\shared\http\ApiErrorCode;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 每次请求重新核对会员状态和密码版本。 */
final readonly class MemberAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MemberTokenServiceInterface $tokens,
        private MemberAuthRepositoryInterface $members,
        private MemberAuthenticationContext $context,
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        $cookieName = (string) config('authentication.member.cookie_name', 'hlyq_member_token');
        $token = (string) $request->cookie($cookieName, '');
        if ($token === '') {
            throw new BusinessException('会员登录状态已失效', ApiErrorCode::UNAUTHENTICATED, 401);
        }
        $memberId = $this->tokens->parse($token);
        $account = $this->members->findById($memberId);
        if ($account === null || $account->status !== 1 || !$this->tokens->matchesPasswordVersion($token, $account)) {
            throw new BusinessException('会员登录状态已失效', ApiErrorCode::UNAUTHENTICATED, 401);
        }
        $this->context->set($request, $account);

        return $handler($request);
    }
}
