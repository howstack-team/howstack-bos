<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\member\contract\{MemberRegistrationRepositoryInterface, RegistrationRateLimiterInterface};
use app\modules\system_settings\application\GetSystemSettingsService;
use app\shared\http\ApiErrorCode;

final readonly class RegisterMemberService
{
    public function __construct(private GetSystemSettingsService $settings, private MemberRegistrationRepositoryInterface $members,
        private SendRegistrationCodeService $codes, private RegistrationRateLimiterInterface $limiter) {
    }

    /** 仅创建账号；注册成功后由用户登录，不依赖令牌签发成功来提交账号。 */
    public function register(array $input, string $binding, string $ip): array
    {
        $method = $input['method']; $target = $input['account'];
        $policy = $this->settings->registration();
        if (!$policy[$method . '_enabled']) throw new BusinessException('此注册方式已关闭，请选择其他方式', ApiErrorCode::FORBIDDEN, 403);
        $this->limiter->check('register', $method . ':' . $target, $ip);
        if ($this->members->exists($method, $target)) throw new BusinessException('该账号已注册，请直接登录', ApiErrorCode::CONFLICT, 409);
        if (strlen($input['password']) > 72) throw new BusinessException('密码最多72字节，请缩短密码', ApiErrorCode::VALIDATION_FAILED, 422);
        $verified = (bool) $policy[$method . '_verification'];
        if ($verified) $this->codes->verify($input['challenge_id'], $method, $target, $binding, $input['code']);
        return $this->members->create($method, $target, password_hash($input['password'], PASSWORD_DEFAULT), $verified)->profile();
    }
}
