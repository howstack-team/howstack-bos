<?php

declare(strict_types=1);

namespace app\modules\admin\auth\application;

use app\modules\admin\auth\contract\AdminAccessTokenServiceInterface;
use app\modules\admin\auth\contract\AdminAccountRepositoryInterface;
use app\modules\admin\auth\contract\AuthenticationAuditLoggerInterface;
use app\modules\admin\auth\domain\AdminLoginCredentials;
use app\modules\admin\auth\domain\AdminLoginResult;
use app\modules\admin\auth\domain\AdminMfaLoginChallenge;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\exception\AdminAuthenticationException;

/**
 * 校验管理员账号密码并签发后台访问令牌。
 */
final readonly class LoginAdminService
{
    // 假哈希不是密钥；按当前PHP的PASSWORD_DEFAULT算法与成本初始化，避免版本切换造成枚举时序差异。
    private string $dummyPasswordHash;

    /**
     * 初始化管理员登录服务。
     * @param AdminAccountRepositoryInterface       $accounts    管理员账号仓储
     * @param AdminAccessTokenServiceInterface      $tokens      管理员令牌服务
     * @param AuthenticationAuditLoggerInterface    $auditLogger 认证审计记录器
     */
    public function __construct(
        private AdminAccountRepositoryInterface $accounts,
        private AdminAccessTokenServiceInterface $tokens,
        private AuthenticationAuditLoggerInterface $auditLogger,
        private RecordAdminLoginService $loginLogs,
        private AdminMfaService $mfa,
    ) {
        $this->dummyPasswordHash = password_hash(self::class, PASSWORD_DEFAULT);
    }

    /**
     * 执行管理员账号密码登录。
     * 不存在的账号也会执行一次密码哈希校验，降低账号枚举的时序差异。
     * @param AdminLoginCredentials $credentials   已校验登录凭据
     * @param string                $clientAddress 客户端连接地址
     * @return AdminLoginResult 登录成功结果
     * @throws AdminAuthenticationException 账号密码错误或账号停用时抛出
     */
    public function execute(AdminLoginCredentials $credentials, string $clientAddress, string $userAgent = ''): AdminLoginResult|AdminMfaLoginChallenge
    {
        $account = $this->accounts->findByUsername($credentials->username);
        $passwordHash = $account?->passwordHash ?? $this->dummyPasswordHash;
        $passwordMatches = password_verify($credentials->password, $passwordHash);

        if ($account === null || !$passwordMatches) {
            $this->auditLogger->loginFailed($credentials->username, $clientAddress, 'invalid_credentials');
            $this->loginLogs->record($account, $credentials->username, false, $clientAddress, $userAgent, 'invalid_credentials');
            throw AdminAuthenticationException::invalidCredentials();
        }

        if (!$account->isActive()) {
            $this->auditLogger->loginFailed($account->username, $clientAddress, 'account_disabled');
            $this->loginLogs->record($account, $account->username, false, $clientAddress, $userAgent, 'account_disabled');
            throw AdminAuthenticationException::accountDisabled();
        }

        $challenge = $this->mfa->challenge($account, $credentials->remember);
        if ($challenge !== null) return $challenge;
        return $this->complete($account, $clientAddress, $userAgent);
    }

    /** 仅在完整认证成功后调用，统一签发和成功审计。 */
    public function complete(AdminAccount $account, string $clientAddress, string $userAgent): AdminLoginResult
    {
        $token = $this->tokens->issue($account);
        $this->accounts->recordSuccessfulLogin($account->id, date('Y-m-d H:i:s'));
        $this->auditLogger->loginSucceeded($account, $clientAddress);
        $this->loginLogs->record($account, $account->username, true, $clientAddress, $userAgent);

        return new AdminLoginResult($account, $token);
    }
}
