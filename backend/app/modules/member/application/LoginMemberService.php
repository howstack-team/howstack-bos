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
    private const string DUMMY_PASSWORD_HASH = '$2y$12$yQV9LRqZiZ713M/aLmGQtulk/tMe2T2A5496vpdz/AUUeijRMjlX6';

    public function __construct(
        private MemberAuthRepositoryInterface $members,
        private MemberTokenServiceInterface $tokens,
    ) {
    }

    /** @return array{user: array{id: int, mobile: string, nickname: string}, token: string} */
    public function login(string $mobile, string $password): array
    {
        $account = $this->members->findByMobile($mobile);
        $matches = password_verify($password, $account?->passwordHash ?? self::DUMMY_PASSWORD_HASH);
        if ($account === null || !$matches || $account->status !== 1) {
            throw new BusinessException('账号或密码错误', ApiErrorCode::UNAUTHENTICATED, 401);
        }
        $token = $this->tokens->issue($account);
        $this->members->recordLogin($account->id);

        return ['user' => $account->profile(), 'token' => $token];
    }
}
