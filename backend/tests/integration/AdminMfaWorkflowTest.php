<?php

declare(strict_types=1);
namespace tests\integration;
use app\exception\BusinessException;
use app\modules\admin\auth\application\{AdminMfaService, LoginAdminService, RecordAdminLoginService};
use app\modules\admin\auth\contract\AuthenticationAuditLoggerInterface;
use app\modules\admin\auth\domain\{AdminLoginCredentials, AdminMfaLoginChallenge};
use app\modules\admin\auth\infrastructure\{DatabaseAdminMfaRepository, DatabaseAdminAccountRepository, DatabaseLoginLogRepository, OtphpTotp, FirebaseAdminAccessTokenService};
use app\shared\contract\IpLocationResolverInterface;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
final class AdminMfaWorkflowTest extends TestCase
{
    use IsolatedBusinessDatabase;
    private function fixture(): array
    {
        Db::table('admin_users')->where('id', 1)->update(['password_hash' => password_hash('TestMfa@123456', PASSWORD_DEFAULT), 'status' => 1]);
        $accounts = new DatabaseAdminAccountRepository();
        $locations = $this->createStub(IpLocationResolverInterface::class); $locations->method('resolve')->willReturn('本机');
        $logs = new RecordAdminLoginService(new DatabaseLoginLogRepository(), $locations);
        $limiter = $this->createStub(\app\modules\admin\auth\contract\AdminLoginRateLimiterInterface::class); $limiter->method('allows')->willReturn(true);
        $mfa = new AdminMfaService(new DatabaseAdminMfaRepository(), $accounts, new OtphpTotp(), new SodiumSensitiveDataCipher(str_repeat('test-mfa-key', 8)), $logs, $limiter);
        $tokens = new FirebaseAdminAccessTokenService(str_repeat('jwt-fixture', 8), 'fixture', 'admin', 3600);
        $audit = $this->createStub(AuthenticationAuditLoggerInterface::class);
        $login = new LoginAdminService($accounts, $tokens, $audit, $logs, $mfa);
        return [$mfa, $accounts, $tokens, $login];
    }
    private function bind(AdminMfaService $mfa, DatabaseAdminAccountRepository $accounts): array
    {
        $actor = $accounts->findById(1);
        $setup = $mfa->begin($actor, 'TestMfa@123456');
        // 绑定时消费前一有效时间步，当前时间步保留给随后登录验收。
        $codes = $mfa->confirm($actor, 'TestMfa@123456', $setup['setup_id'], TOTP::createFromSecret($setup['secret'])->at(time() - 30));
        return [$setup, $codes];
    }
    private function reject(int $status, callable $call): void
    {
        try { $call(); self::fail('应拒绝请求'); } catch (BusinessException $e) { self::assertSame($status, $e->httpStatus()); }
    }
    public function testPasswordAloneCannotIssueTokenAndBindingInvalidatesExistingTokens(): void
    {
        [$mfa, $accounts, $tokens, $login] = $this->fixture();
        $old = $accounts->findById(1); $jwt = $tokens->issue($old);
        [$setup] = $this->bind($mfa, $accounts);
        self::assertFalse($tokens->matchesAccount($tokens->parse($jwt->value), $accounts->findById(1)));
        $result = $login->execute(AdminLoginCredentials::fromValidatedInput('fixture-admin', 'TestMfa@123456', true), '127.0.0.1');
        self::assertInstanceOf(AdminMfaLoginChallenge::class, $result);
        self::assertSame(0, Db::table('admin_login_logs')->where('status', 1)->count());
        $verified = $mfa->verifyLogin($result->token, TOTP::createFromSecret($setup['secret'])->at(time()), false);
        self::assertTrue($verified['remember']);
        $complete = $login->complete($verified['account'], '127.0.0.1', 'fixture');
        self::assertTrue($tokens->matchesAccount($tokens->parse($complete->token->value), $verified['account']));
        self::assertSame(1, Db::table('admin_login_logs')->where('status', 1)->count());
        $this->reject(401, fn () => $mfa->verifyLogin($result->token, '000000', false));
        self::assertStringNotContainsString($setup['secret'], Db::table('admin_mfa_credentials')->value('secret_encrypted'));
    }
    public function testReplayRecoveryAndFiveWrongAttemptsArePersisted(): void
    {
        [$mfa, $accounts] = $this->fixture(); [$setup, $codes] = $this->bind($mfa, $accounts);
        $otp = TOTP::createFromSecret($setup['secret'])->at(time());
        $a = $mfa->challenge($accounts->findById(1), false); $mfa->verifyLogin($a->token, $otp, false);
        $b = $mfa->challenge($accounts->findById(1), false);
        $this->reject(422, fn () => $mfa->verifyLogin($b->token, $otp, false));
        $mfa->verifyLogin($b->token, $codes[0], true);
        self::assertSame(9, $mfa->status(1)['recovery_remaining']);
        $c = $mfa->challenge($accounts->findById(1), false);
        for ($i = 0; $i < 5; $i++) $this->reject(422, fn () => $mfa->verifyLogin($c->token, $codes[0], true));
        self::assertSame(5, (int) Db::table('admin_mfa_challenges')->value('attempts'));
        $this->reject(401, fn () => $mfa->verifyLogin($c->token, $codes[1], true));
        $stored = Db::table('admin_mfa_credentials')->value('recovery_hashes');
        foreach ($codes as $code) self::assertStringNotContainsString(str_replace('-', '', $code), $stored);
        self::assertGreaterThanOrEqual(6, Db::table('admin_login_logs')->where('failure_reason', 'mfa_invalid')->count());
    }
    public function testExpiryPasswordChangeAndDisabledAccountsInvalidateChallenge(): void
    {
        [$mfa, $accounts] = $this->fixture(); [, $codes] = $this->bind($mfa, $accounts);
        $a = $mfa->challenge($accounts->findById(1), false);
        Db::table('admin_mfa_challenges')->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);
        $this->reject(401, fn () => $mfa->verifyLogin($a->token, $codes[0], true));
        $b = $mfa->challenge($accounts->findById(1), false);
        Db::table('admin_users')->where('id', 1)->update(['password_hash' => password_hash('Changed@123456', PASSWORD_DEFAULT)]);
        $this->reject(401, fn () => $mfa->verifyLogin($b->token, $codes[0], true));
        $c = $mfa->challenge($accounts->findById(1), false);
        Db::table('admin_users')->where('id', 1)->update(['status' => 0]);
        $this->reject(401, fn () => $mfa->verifyLogin($c->token, $codes[0], true));
    }
    public function testManagementRequiresPasswordAndSecondFactorAndInvalidatesOldCodes(): void
    {
        [$mfa, $accounts, $tokens] = $this->fixture(); [, $codes] = $this->bind($mfa, $accounts);
        $actor = $accounts->findById(1); $jwt = $tokens->issue($actor);
        $this->reject(422, fn () => $mfa->change($actor, 'incorrect', $codes[0], true, false));
        self::assertSame(10, $mfa->status(1)['recovery_remaining']);
        $new = $mfa->change($actor, 'TestMfa@123456', $codes[0], true, false);
        self::assertCount(10, $new);
        self::assertFalse($tokens->matchesAccount($tokens->parse($jwt->value), $accounts->findById(1)));
        $challenge = $mfa->challenge($accounts->findById(1), false);
        $this->reject(422, fn () => $mfa->verifyLogin($challenge->token, $codes[1], true));
        $mfa->change($accounts->findById(1), 'TestMfa@123456', $new[0], true, true);
        self::assertFalse($mfa->status(1)['enabled']);
        self::assertSame(0, Db::table('admin_mfa_challenges')->count());
    }
    public function testSetupMustBeConfirmedWithCorrectPasswordAndCanExpire(): void
    {
        [$mfa, $accounts] = $this->fixture(); $actor = $accounts->findById(1);
        $this->reject(422, fn () => $mfa->begin($actor, 'wrong'));
        $setup = $mfa->begin($actor, 'TestMfa@123456');
        self::assertFalse($mfa->status(1)['enabled']); self::assertNull($mfa->challenge($actor, false));
        $code = TOTP::createFromSecret($setup['secret'])->at(time());
        $this->reject(409, fn () => $mfa->confirm($actor, 'TestMfa@123456', str_repeat('f', 64), $code));
        Db::table('admin_mfa_credentials')->update(['setup_expires_at' => date('Y-m-d H:i:s', time() - 1)]);
        $this->reject(409, fn () => $mfa->confirm($actor, 'TestMfa@123456', $setup['setup_id'], $code));
    }
    public function testConcurrentUseOfOneRecoveryCodeSucceedsOnlyOnce(): void
    {
        if (Db::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) self::markTestSkipped('需要独立MySQL与pcntl');
        [$mfa, $accounts] = $this->fixture(); [, $codes] = $this->bind($mfa, $accounts);
        $challenge = $mfa->challenge($accounts->findById(1), false);
        $this->testCapsule->getConnection()->disconnect(); $this->mysqlServer = null;
        $signal = tempnam(sys_get_temp_dir(), 'mfa-signal-'); unlink($signal); $files = []; $pids = [];
        for ($i = 0; $i < 2; $i++) {
            $file = tempnam(sys_get_temp_dir(), 'mfa-result-'); $files[] = $file; $pid = pcntl_fork();
            if ($pid === 0) {
                while (!file_exists($signal)) usleep(1000);
                try { $mfa->verifyLogin($challenge->token, $codes[0], true); file_put_contents($file, 'ok'); }
                catch (BusinessException $e) { file_put_contents($file, (string) $e->httpStatus()); }
                catch (\Throwable) { file_put_contents($file, 'error'); }
                $this->testCapsule->getConnection()->disconnect(); exit(0);
            }
            if ($pid < 0) throw new \RuntimeException('并发进程创建失败');
            $pids[] = $pid;
        }
        touch($signal);
        try {
            foreach ($pids as $pid) pcntl_waitpid($pid, $exit);
            $results = array_map(file_get_contents(...), $files); sort($results);
            self::assertSame(['401', 'ok'], $results);
            self::assertSame(9, $mfa->status(1)['recovery_remaining']);
        } finally {
            $cfg = (require dirname(__DIR__, 2) . '/config/database.php')['connections']['mysql'];
            $this->mysqlServer = new \PDO('mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'], $cfg['username'], $cfg['password']);
            unlink($signal); foreach ($files as $file) unlink($file);
        }
    }
    public function testSchemaContainsChineseCommentsAndSafeDefaults(): void
    {
        self::assertSame(0, (int) Db::table('admin_users')->value('auth_version'));
        if (Db::connection()->getDriverName() === 'mysql') {
            foreach (['orm_admin_mfa_credentials', 'orm_admin_mfa_challenges'] as $table) {
                $columns = Db::select('SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
                self::assertNotEmpty($columns);
                foreach ($columns as $col) self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $col->COLUMN_COMMENT);
            }
        }
    }
}
