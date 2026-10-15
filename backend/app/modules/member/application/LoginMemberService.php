<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\member\contract\MemberAuthRepositoryInterface;
use app\modules\member\contract\MemberTokenServiceInterface;
use app\shared\http\ApiErrorCode;

/** 校验会员凭据并签发与后台权限隔离的令牌。 */
final readonly class LoginMemberService
{
    public function __construct(
        private MemberAuthRepositoryInterface $members,
        private MemberTokenServiceInterface $tokens,
    ) {
    }

    /** @return array{user: array{id: string, mobile: ?string, email: ?string, nickname: string}, token: string} */
    public function login(string $identifier, string $password, bool $remember = false): array
    {
        $identifier = trim($identifier);
        $account = str_contains($identifier, '@') ? $this->members->findByEmail(strtolower($identifier)) : $this->members->findByMobile($identifier);
        if (!\app\modules\member\domain\MemberPassword::matches($account, $password)) {
            throw new BusinessException('账号或密码错误', ApiErrorCode::UNAUTHENTICATED, 401);
        }
        $token = $this->tokens->issue($account, $remember);
        $this->members->recordLogin($account->id);

        return ['user' => $account->profile(), 'token' => $token];
    }
}
