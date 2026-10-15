<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\auth\application;

use app\modules\admin\auth\application\LoginAdminService;
use app\modules\admin\auth\application\RecordAdminLoginService;
use app\modules\admin\auth\contract\AdminAccessTokenServiceInterface;
use app\modules\admin\auth\contract\AdminAccountRepositoryInterface;
use app\modules\admin\auth\contract\AuthenticationAuditLoggerInterface;
use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use app\modules\admin\auth\domain\AccessTokenClaims;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\domain\AdminLoginCredentials;
use app\modules\admin\auth\domain\IssuedAccessToken;
use app\modules\admin\auth\exception\AdminAuthenticationErrorCode;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\shared\contract\IpLocationResolverInterface;
use PHPUnit\Framework\TestCase;

/**
 * 验证管理员账号密码登录的核心分支。
 */
final class LoginAdminServiceTest extends TestCase
{
    /**
     * 验证有效账号密码会签发令牌并记录登录。
     * @return void
     */
    public function testValidCredentialsIssueTokenAndRecordLogin(): void
    {
        $account = $this->account(status: 1);
        $repository = new FakeAdminAccountRepository($account);
        $tokens = new FakeAdminAccessTokenService();
        $audit = new FakeAuthenticationAuditLogger();
        $logs = new FakeLoginLogRepository();
        $service = new LoginAdminService($repository, $tokens, $audit, $this->recorder($logs), $this->mfa());

        $result = $service->execute($this->credentials(), '127.0.0.1');

        self::assertSame($account, $result->account);
        self::assertSame('signed.jwt.token', $result->token->value);
        self::assertSame(1, $repository->recordedAdminId);
        self::assertSame(1, $audit->succeededCount);
        self::assertSame(0, $audit->failedCount);
        self::assertCount(1, $logs->records);
        self::assertSame(1, $logs->records[0]['status']);
    }

    /**
     * 验证密码错误时返回统一认证错误且不签发令牌。
     * @return void
     */
    public function testInvalidPasswordReturnsGenericAuthenticationError(): void
    {
        $repository = new FakeAdminAccountRepository($this->account(status: 1));
        $tokens = new FakeAdminAccessTokenService();
        $audit = new FakeAuthenticationAuditLogger();
        $logs = new FakeLoginLogRepository();
        $service = new LoginAdminService($repository, $tokens, $audit, $this->recorder($logs), $this->mfa());

        try {
            $service->execute(
                AdminLoginCredentials::fromValidatedInput('admin_user', 'Wrong123!'),
                '127.0.0.1',
            );
            self::fail('密码错误时应拒绝登录');
        } catch (AdminAuthenticationException $exception) {
            self::assertSame(AdminAuthenticationErrorCode::INVALID_CREDENTIALS, $exception->errorCode());
            self::assertSame(401, $exception->httpStatus());
        }

        self::assertSame(0, $tokens->issuedCount);
        self::assertSame(1, $audit->failedCount);
        self::assertCount(1, $logs->records);
        self::assertSame(0, $logs->records[0]['status']);
    }

    /**
     * 验证不存在的账号走统一失败分支，且假哈希与当前密码哈希策略一致。
     * @return void
     */
    public function testUnknownAccountUsesCurrentPasswordHashSettings(): void
    {
        $tokens = new FakeAdminAccessTokenService();
        $audit = new FakeAuthenticationAuditLogger();
        $logs = new FakeLoginLogRepository();
        $service = new LoginAdminService(
            new FakeAdminAccountRepository(null),
            $tokens,
            $audit,
            $this->recorder($logs),
            $this->mfa(),
        );

        try {
            $service->execute($this->credentials(), '127.0.0.1');
            self::fail('不存在的账号应拒绝登录');
        } catch (AdminAuthenticationException $exception) {
            self::assertSame(AdminAuthenticationErrorCode::INVALID_CREDENTIALS, $exception->errorCode());
        }

        self::assertSame(0, $tokens->issuedCount);
        self::assertSame(1, $audit->failedCount);
        self::assertCount(1, $logs->records);
        self::assertSame(0, $logs->records[0]['status']);

        $dummyHash = (new \ReflectionClass(LoginAdminService::class))->getConstant('DUMMY_PASSWORD_HASH');
        self::assertIsString($dummyHash);
        self::assertSame(password_get_info(password_hash('hash-settings-probe', PASSWORD_DEFAULT)), password_get_info($dummyHash));
    }

    /**
     * 验证停用账号即使密码正确也不能登录。
     * @return void
     */
    public function testDisabledAccountCannotLogin(): void
    {
        $repository = new FakeAdminAccountRepository($this->account(status: 0));
        $service = new LoginAdminService(
            $repository,
            new FakeAdminAccessTokenService(),
            new FakeAuthenticationAuditLogger(),
            $this->recorder(),
            $this->mfa(),
        );

        $this->expectException(AdminAuthenticationException::class);
        $this->expectExceptionCode(AdminAuthenticationErrorCode::ACCOUNT_DISABLED);

        $service->execute($this->credentials(), '127.0.0.1');
    }

    /**
     * 创建测试管理员账号。
     * @param int $status 管理员状态
     * @return AdminAccount 测试管理员账号
     */
    private function account(int $status): AdminAccount
    {
        return new AdminAccount(
            1,
            'admin_user',
            '超级管理员',
            password_hash('Secure123!', PASSWORD_DEFAULT),
            $status,
            null,
        );
    }

    /**
     * 创建合法测试登录凭据。
     * @return AdminLoginCredentials 合法登录凭据
     */
    private function credentials(): AdminLoginCredentials
    {
        return AdminLoginCredentials::fromValidatedInput('admin_user', 'Secure123!');
    }

    private function mfa(): \app\modules\admin\auth\application\AdminMfaService
    {
        $repository = $this->createStub(\app\modules\admin\auth\contract\AdminMfaRepositoryInterface::class);
        $repository->method('locked')->willReturn(null); // 本组用例只模拟未启用MFA；完整流程由数据库集成测试覆盖。
        $limiter = $this->createStub(\app\modules\admin\auth\contract\AdminLoginRateLimiterInterface::class);
        $limiter->method('allows')->willReturn(true);
        return new \app\modules\admin\auth\application\AdminMfaService($repository,
            new FakeAdminAccountRepository(null), new \app\modules\admin\auth\infrastructure\OtphpTotp(),
            new \app\shared\infrastructure\SodiumSensitiveDataCipher(str_repeat('unit-fixture-key', 5)), $this->recorder(), $limiter);
    }

    private function recorder(?FakeLoginLogRepository $logs = null): RecordAdminLoginService
    {
        return new RecordAdminLoginService($logs ?? new FakeLoginLogRepository(), new FakeIpLocationResolver());
    }
}

/** 为认证单元测试提供不访问数据库的日志仓储。 */
final class FakeLoginLogRepository implements LoginLogRepositoryInterface
{
    public array $records = [];

    public function append(array $record): void
    {
        $this->records[] = $record;
    }

    /** @param array<string, string> $filters 独立文本条件，使用 AND 组合 */
    public function paginate(
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        return ['data' => $this->records, 'total' => count($this->records)];
    }

    public function countBefore(string $cutoff): int
    {
        return 0;
    }

    public function deleteBatchBefore(string $cutoff, int $limit): int
    {
        return 0;
    }
}

final class FakeIpLocationResolver implements IpLocationResolverInterface
{
    public function resolve(string $ip): string
    {
        return '本机';
    }
}

/**
 * 提供可观察写入结果的内存管理员仓储。
 */
final class FakeAdminAccountRepository implements AdminAccountRepositoryInterface
{
    public ?int $recordedAdminId = null;

    /**
     * 初始化内存管理员仓储。
     * @param AdminAccount|null $account 固定返回的管理员账号
     */
    public function __construct(private readonly ?AdminAccount $account)
    {
    }

    /**
     * 按账号查询管理员。
     * @param string $username 管理员账号
     * @return AdminAccount|null 固定管理员账号
     */
    public function findByUsername(string $username): ?AdminAccount
    {
        return $this->account?->username === $username ? $this->account : null;
    }

    /**
     * 按标识查询管理员。
     * @param int $adminId 管理员标识
     * @return AdminAccount|null 固定管理员账号
     */
    public function findById(int $adminId): ?AdminAccount
    {
        return $this->account?->id === $adminId ? $this->account : null;
    }

    /**
     * 记录管理员最近登录时间。
     * @param int    $adminId  管理员标识
     * @param string $loggedAt 登录时间
     * @return void
     */
    public function recordSuccessfulLogin(int $adminId, string $loggedAt): void
    {
        $this->recordedAdminId = $adminId;
    }
}

/**
 * 提供固定令牌的管理员令牌测试替身。
 */
final class FakeAdminAccessTokenService implements AdminAccessTokenServiceInterface
{
    public function matchesAccount(AccessTokenClaims $claims, AdminAccount $account): bool
    {
        return $claims->adminId === $account->id;
    }

    public int $issuedCount = 0;

    /**
     * 签发固定测试令牌。
     * @param AdminAccount $account 管理员账号
     * @return IssuedAccessToken 固定测试令牌
     */
    public function issue(AdminAccount $account): IssuedAccessToken
    {
        ++$this->issuedCount;

        return new IssuedAccessToken('signed.jwt.token', 2_000_000_000);
    }

    /**
     * 解析固定测试令牌。
     * @param string $token JWT 原始值
     * @return AccessTokenClaims 固定测试声明
     */
    public function parse(string $token): AccessTokenClaims
    {
        return new AccessTokenClaims(1, str_repeat('a', 32), 1_900_000_000, 2_000_000_000);
    }
}

/**
 * 统计认证审计事件的测试替身。
 */
final class FakeAuthenticationAuditLogger implements AuthenticationAuditLoggerInterface
{
    public int $succeededCount = 0;
    public int $failedCount = 0;

    /**
     * 统计登录成功事件。
     * @param AdminAccount $account       管理员账号
     * @param string       $clientAddress 客户端连接地址
     * @return void
     */
    public function loginSucceeded(AdminAccount $account, string $clientAddress): void
    {
        ++$this->succeededCount;
    }

    /**
     * 统计登录失败事件。
     * @param string $username      管理员账号输入
     * @param string $clientAddress 客户端连接地址
     * @param string $reason        失败原因
     * @return void
     */
    public function loginFailed(string $username, string $clientAddress, string $reason): void
    {
        ++$this->failedCount;
    }
}
