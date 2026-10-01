<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\member\domain\MemberAccount;
use app\shared\http\ApiErrorCode;
use Webman\Http\Request;

/** 会员身份仅保存在当前请求的上下文中。 */
final class MemberAuthenticationContext
{
    private const string KEY = 'hlyq.member.account';

    public function set(Request $request, MemberAccount $account): void
    {
        $request->context[self::KEY] = $account;
    }

    public function account(Request $request): MemberAccount
    {
        $account = $request->context[self::KEY] ?? null;
        if (!$account instanceof MemberAccount) {
            throw new BusinessException('会员登录状态已失效', ApiErrorCode::UNAUTHENTICATED, 401);
        }

        return $account;
    }
}
