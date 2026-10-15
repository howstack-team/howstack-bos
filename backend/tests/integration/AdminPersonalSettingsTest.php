<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\exception\AdminAuthenticationException;
use app\modules\admin\auth\infrastructure\DatabaseAdminAccountRepository;
use app\modules\admin\auth\infrastructure\FirebaseAdminAccessTokenService;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\admin\operation\application\OperationLogPayloadSanitizer;
use app\modules\admin\profile\application\ManageAdminProfileService;
use app\modules\admin\profile\infrastructure\DatabaseAdminProfileRepository;
use app\modules\admin\profile\validation\AdminProfileValidator;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use PHPUnit\Framework\TestCase;
use support\Db;
use support\Request;
use support\Response;
use tests\support\IsolatedBusinessDatabase;

/** 在独立数据库验证个人资料、密码与认证边界，支持真实 MySQL。 */
final class AdminPersonalSettingsTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testProfileUsesSelfAndFieldWhitelist(): void
    {
        $actor = $this->admin('self_admin');
        $other = $this->admin('other_admin');
        $data = AdminProfileValidator::profile([
            'id' => $other->id, 'nickname' => '越权修改', 'status' => 0,
            'is_founder' => false, 'password_hash' => 'injected',
            'employee_no' => ' STAFF-001 ', 'mobile' => ' 13800138000 ', 'email' => ' staff@example.com ',
        ]);
        $result = $this->service()->update($actor, $data);
        self::assertSame('STAFF-001', $result['employee_no']);
        self::assertSame('13800138000', $result['mobile']);
        self::assertSame('staff@example.com', $result['email']);
        self::assertSame('测试管理员', $result['nickname']);
        self::assertArrayNotHasKey('password_hash', $result);
        self::assertSame(1, (int) Db::table('admin_users')->where('id', $actor->id)->value('status'));
        self::assertNull(Db::table('admin_users')->where('id', $other->id)->value('email'));
        $cleared = $this->service()->update($actor, AdminProfileValidator::profile([
            'employee_no' => '', 'email' => null, 'mobile' => '   ',
        ]));
        self::assertNull($cleared['employee_no']);
        self::assertNull($cleared['mobile']);
        self::assertNull($cleared['email']);
    }

    public function testWrongOrUnchangedPasswordDoesNotWrite(): void
    {
        $actor = $this->admin();
        foreach ([['wrong-password', 'next-password'], ['original-password', 'original-password']] as [$old, $new]) {
            try {
                $this->service()->changePassword($actor, $old, $new);
                self::fail('应当拒绝错误或未改变的密码');
            } catch (BusinessException $error) {
                self::assertSame(422, $error->httpStatus());
            }
            self::assertSame($actor->passwordHash, Db::table('admin_users')->where('id', $actor->id)->value('password_hash'));
        }
    }

    public function testDuplicateEmployeeNumberDoesNotPartiallySave(): void
    {
        $actor = $this->admin('self_admin');
        $other = $this->admin('other_admin');
        $this->service()->update($other, AdminProfileValidator::profile(['employee_no' => 'EXISTING']));
        try {
            $this->service()->update($actor, AdminProfileValidator::profile([
                'employee_no' => 'EXISTING', 'email' => 'should-not-save@example.com',
            ]));
            self::fail('重复工号应被拒绝');
        } catch (BusinessException $error) {
            self::assertSame('工号已被其他系统用户使用', $error->getMessage());
            self::assertSame(422, $error->httpStatus());
        }
        self::assertNull(Db::table('admin_users')->where('id', $actor->id)->value('email'));
    }

    public function testPasswordChangeRevokesAllOldTokensAndRejectsStaleWrite(): void
    {
        $actor = $this->admin();
        $tokens = new FirebaseAdminAccessTokenService(str_repeat('x', 96), 'test', 'admin', 7200);
        $first = $tokens->issue($actor);
        $second = $tokens->issue($actor);
        $context = new AdminAuthenticationContext();
        $middleware = new AdminAuthMiddleware($tokens, new DatabaseAdminAccountRepository(), $context);
        $this->service()->changePassword($actor, 'original-password', 'next-password-123');
        $updated = (new DatabaseAdminAccountRepository())->findById($actor->id);
        self::assertTrue(password_verify('next-password-123', $updated->passwordHash));
        self::assertFalse(password_verify('original-password', $updated->passwordHash));
        foreach ([$first, $second] as $token) {
            try {
                $middleware->process($this->request($token->value), static fn () => new Response(200));
                self::fail('旧登录令牌必须被拒绝');
            } catch (AdminAuthenticationException $error) {
                self::assertSame(401, $error->httpStatus());
            }
        }
        $new = $tokens->issue($updated);
        $response = $middleware->process($this->request($new->value), static fn () => new Response(200));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse((new DatabaseAdminProfileRepository())->replacePassword($actor->id, $actor->passwordHash, 'invalid'));
        self::assertTrue(password_verify('next-password-123', Db::table('admin_users')->where('id', $actor->id)->value('password_hash')));
    }

    public function testMissingPermissionsCannotReadOrWrite(): void
    {
        $actor = $this->admin(founder: false);
        foreach (['profile', 'update', 'password'] as $operation) {
            try {
                match ($operation) {
                    'profile' => $this->service()->profile($actor),
                    'update' => $this->service()->update($actor, AdminProfileValidator::profile(['email' => 'x@example.com'])),
                    'password' => $this->service()->changePassword($actor, 'original-password', 'new-password'),
                };
                self::fail('无权限操作必须拒绝');
            } catch (BusinessException $error) {
                self::assertSame(403, $error->httpStatus());
            }
        }
        self::assertNull(Db::table('admin_users')->where('id', $actor->id)->value('email'));
        self::assertSame($actor->passwordHash, Db::table('admin_users')->where('id', $actor->id)->value('password_hash'));
    }

    public function testPasswordAndContactValidation(): void
    {
        foreach ([
            ['email' => 'invalid'], ['mobile' => 'abc'], ['employee_no' => str_repeat('a', 33)],
        ] as $input) {
            try {
                AdminProfileValidator::profile($input);
                self::fail('无效资料必须拒绝');
            } catch (\Webman\Exception\BusinessException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        foreach ([['short', 'short'], ['new-password', 'different-password'], [str_repeat('密', 25), str_repeat('密', 25)]] as [$new, $confirm]) {
            try {
                AdminProfileValidator::password(['current_password' => 'original-password', 'new_password' => $new, 'new_password_confirmation' => $confirm]);
                self::fail('无效密码必须拒绝');
            } catch (\Webman\Exception\BusinessException | BusinessException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }

    public function testWriteOriginAndAuditDoNotExposeCredentials(): void
    {
        $middleware = new SameOriginWriteMiddleware();
        $response = $middleware->process(new Request("PUT /api/admin/v1/profile HTTP/1.1\r\nHost: localhost:8989\r\nOrigin: http://localhost:8989\r\n\r\n"), static fn () => new Response(200));
        self::assertSame(200, $response->getStatusCode());
        foreach (['', 'Origin: https://evil.example' . "\r\n"] as $origin) {
            try {
                $middleware->process(new Request("POST /api/admin/v1/profile/password HTTP/1.1\r\nHost: localhost:8989\r\n{$origin}\r\n"), static fn () => new Response(200));
                self::fail('非同源必须拒绝');
            } catch (BusinessException $error) {
                self::assertSame(403, $error->httpStatus());
            }
        }
        self::assertSame('[]', (new OperationLogPayloadSanitizer())->params('profile', 'password', [
            'current_password' => 'secret', 'new_password' => 'new-secret', 'email' => 'private@example.com',
        ]));
    }

    private function admin(string $username = 'personal_admin', bool $founder = true): AdminAccount
    {
        $hash = password_hash('original-password', PASSWORD_DEFAULT);
        $id = $this->row('admin_users', [
            'username' => $username, 'nickname' => '测试管理员', 'password_hash' => $hash,
            'status' => 1, 'is_founder' => (int) $founder,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return new AdminAccount($id, $username, '测试管理员', $hash, 1, null, $founder);
    }

    private function service(): ManageAdminProfileService
    {
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([]);

        return new ManageAdminProfileService(new DatabaseAdminProfileRepository(), new AdminAccessControlService($grants));
    }

    private function request(string $token): Request
    {
        return new Request("GET /api/admin/v1/profile HTTP/1.1\r\nAuthorization: Bearer {$token}\r\n\r\n");
    }
}
