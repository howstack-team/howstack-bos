<?php

declare(strict_types=1);

namespace tests\unit\modules\system_settings;

use app\exception\BusinessException;
use app\exception\Handler;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\application\ManageSystemSettingsService;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use app\modules\system_settings\controller\SystemSettingsController;
use app\modules\system_settings\validation\SystemSettingsValidator;
use app\shared\http\ApiResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use support\Request;
use Throwable;

/** 固定设置契约、权限、异常响应和实时配置读取的回归验证。 */
final class SystemSettingsTest extends TestCase
{
    public function testRegistrationSettingsRemainCompleteAndPermissionControlled(): void
    {
        $reader = $this->createStub(SystemSettingsRepositoryInterface::class);
        $reader->method('values')->willReturn(['registration.mobile_enabled' => '1', 'registration.email_enabled' => '1', 'registration.mobile_verification' => '0', 'registration.email_verification' => '1']);
        $values = ['mobile_enabled' => 1, 'email_enabled' => 1, 'mobile_verification' => 0, 'email_verification' => 1];
        self::assertSame($values, $this->service($reader)->get($this->actor(true), 'registration'));
        $validator = new SystemSettingsValidator();
        self::assertSame($values, $validator->values('registration', $values));
        foreach ([$values + ['public_id' => '11111111'], array_replace($values, ['email_enabled' => true]), ['mobile_enabled' => 1]] as $invalid) {
            try { $validator->values('registration', $invalid); self::fail('应拒绝非法开关或不完整设置'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
        $repository = $this->createMock(SystemSettingsRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $this->expectException(BusinessException::class);
        $this->service($repository)->save($this->actor(), 'registration', $values);
    }

    /**
     * 基础设置允许清空可选字段，且只保存约定的原始键。
     * @return void
     */
    public function testSavesOnlyFixedBaseKeys(): void
    {
        $repository = $this->createMock(SystemSettingsRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with('base', [
            'site.title' => '测试网站',
            'site.url' => 'https://example.com', 'site.copyright' => '',
            'site.icp_record' => '', 'site.public_security_record' => '', 'site.business_license' => '',
        ]);
        $values = $this->service($repository)->save($this->actor(true), 'base', [
            'site_title' => ' 测试网站 ',
            'site_url' => 'https://example.com', 'copyright' => '',
            'icp_record' => '', 'public_security_record' => '', 'business_license' => '',
        ]);
        self::assertSame('测试网站', $values['site_title']);
        self::assertArrayNotHasKey('site_keywords', $values);
        self::assertArrayNotHasKey('site_subtitle', $values);
    }

    /** 已删除的副标题不能通过旧请求重新写回设置表。 */
    public function testRemovedSubtitleIsNotReturnedAndCannotBeSaved(): void
    {
        $repository = $this->createMock(SystemSettingsRepositoryInterface::class);
        $repository->method('values')->willReturn(['site.title' => '测试平台', 'site.url' => 'https://example.test', 'site.subtitle' => '旧副标题']);
        $repository->expects(self::never())->method('save');
        $service = $this->service($repository);
        $base = $service->get($this->actor(true), 'base');
        self::assertArrayNotHasKey('site_subtitle', $base);
        $this->expectException(BusinessException::class);
        $service->save($this->actor(true), 'base', $base + ['site_subtitle' => '不能写入']);
    }

    /**
     * 安全字段边界或额外键错误不能触发写入。
     * @param array<string, mixed> $input 非法输入
     * @return void
     */
    #[DataProvider('invalidSecurity')]
    public function testRejectsInvalidSecurityWithoutWriting(array $input): void
    {
        $repository = $this->createMock(SystemSettingsRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $this->expectException(BusinessException::class);
        $this->service($repository)->save($this->actor(true), 'security', $input);
    }

    /** @return iterable<string, array{array<string, mixed>}> 非法完整表单 */
    public static function invalidSecurity(): iterable
    {
        $valid = ['session_expire' => 120, 'login_attempts' => 10, 'login_window' => 60];
        foreach (['session_expire' => [4, 1441], 'login_attempts' => [0, 101], 'login_window' => [9, 3601]] as $field => $values) {
            foreach ($values as $value) {
                yield $field . $value => [array_replace($valid, [$field => $value])];
            }
        }
        yield 'extra private key' => [$valid + ['admin.path' => 'other']];
        yield 'fractional' => [array_replace($valid, ['session_expire' => 10.5])];
        yield 'boolean' => [array_replace($valid, ['login_attempts' => true])];
        yield 'numeric string' => [array_replace($valid, ['login_attempts' => '10'])];
        yield 'missing field' => [['session_expire' => 120, 'login_attempts' => 10]];
    }

    /**
     * JSON 整数保存为数据库字符串，响应保持原生整数。
     * @return void
     */
    public function testNormalizesSecurityValues(): void
    {
        $repository = $this->createMock(SystemSettingsRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with('security', [
            'security.session_expire' => '5', 'security.login_attempts' => '1', 'security.login_window' => '10',
        ]);
        self::assertSame(['session_expire' => 5, 'login_attempts' => 1, 'login_window' => 10],
            $this->service($repository)->save($this->actor(true), 'security', [
                'session_expire' => 5, 'login_attempts' => 1, 'login_window' => 10,
            ]));
    }

    /**
     * 页面查看权限不能用于保存配置。
     * @return void
     */
    public function testReadOnlyPermissionCannotSave(): void
    {
        $repository = $this->createMock(SystemSettingsRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $repository->expects(self::once())->method('values')->with('base')->willReturn(['site.title' => '只读网站']);
        $service = $this->service($repository, ['admin.page.settings.view']);
        self::assertSame('只读网站', $service->get($this->actor(), 'base')['site_title']);
        $this->expectException(BusinessException::class);
        $service->save($this->actor(), 'security', []);
    }

    /**
     * 常驻门面读取最新值，不缓存上一次登录的策略。
     * @return void
     */
    public function testSecurityIsReadFreshAndCorruptionFailsClosed(): void
    {
        $repository = $this->createMock(SystemSettingsRepositoryInterface::class);
        $repository->expects(self::exactly(3))->method('values')->with('security')->willReturnOnConsecutiveCalls(
            ['security.session_expire' => '120', 'security.login_attempts' => '10', 'security.login_window' => '60'],
            ['security.session_expire' => '30', 'security.login_attempts' => '5', 'security.login_window' => '120'],
            ['security.session_expire' => 'invalid'],
        );
        $service = new GetSystemSettingsService($repository);
        self::assertSame(120, $service->security()['session_expire']);
        self::assertSame(30, $service->security()['session_expire']);
        $this->expectException(RuntimeException::class);
        $service->security();
    }

    /**
     * HTTP 边界和统一异常处理器保持状态码、错误包络及请求追踪。
     * @param string $scenario 场景
     * @param int    $status 预期 HTTP 状态码
     * @param int    $code 预期业务码
     * @return void
     */
    #[DataProvider('responseScenarios')]
    public function testControllerResponseContract(string $scenario, int $status, int $code): void
    {
        $repository = $this->createStub(SystemSettingsRepositoryInterface::class);
        if ($scenario === 'database') {
            $repository->method('values')->willThrowException(new RuntimeException('SQL secret should stay private'));
        } else {
            $repository->method('values')->willReturn(['site.title' => '测试网站']);
        }
        $responses = new ApiResponse();
        $context = new AdminAuthenticationContext();
        $request = new Request("GET /api/admin/v1/settings/base HTTP/1.1\r\nHost: localhost\r\n\r\n");
        if ($scenario !== 'unauthenticated') {
            $context->set($request, $this->actor($scenario !== 'forbidden'));
        }
        $controller = new SystemSettingsController($this->service($repository), $context, $responses);
        try {
            $response = $scenario === 'validation'
                ? $controller->update($request, 'security')
                : $controller->show($request, $scenario === 'category' ? 'internal' : 'base');
        } catch (Throwable $exception) {
            $response = (new Handler(new NullLogger(), false, $responses))->render($request, $exception);
        }
        $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, $payload['code']);
        self::assertSame(['code', 'message', 'data', 'request_id'], array_keys($payload));
        self::assertSame($payload['request_id'], $response->getHeader('X-Request-Id'));
        self::assertStringNotContainsString('SQL secret', $response->rawBody());
        if ($scenario === 'validation') {
            self::assertArrayHasKey('session_expire', $payload['data']['errors']);
        }
    }

    /** @return iterable<string, array{string, int, int}> 响应场景 */
    public static function responseScenarios(): iterable
    {
        yield 'success' => ['success', 200, 0];
        yield 'unauthenticated' => ['unauthenticated', 401, 40102];
        yield 'forbidden' => ['forbidden', 403, 40300];
        yield 'validation' => ['validation', 422, 42200];
        yield 'category' => ['category', 422, 42200];
        yield 'database' => ['database', 500, 50000];
    }

    /**
     * 构造仅依赖仓储边界的真实应用服务。
     * @param SystemSettingsRepositoryInterface $repository 设置替身
     * @param list<string>                      $permissions 权限
     * @return ManageSystemSettingsService 用例
     */
    private function service(SystemSettingsRepositoryInterface $repository, array $permissions = []): ManageSystemSettingsService
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([
            ['permissions' => $permissions, 'data_scope' => 'self', 'department_ids' => []],
        ]);

        return new ManageSystemSettingsService($repository, new GetSystemSettingsService($repository),
            new AdminAccessControlService($access), new SystemSettingsValidator());
    }

    /**
     * 构造请求身份。
     * @param bool $founder 是否创始管理员
     * @return AdminAccount 请求管理员
     */
    private function actor(bool $founder = false): AdminAccount
    {
        return new AdminAccount(1, 'admin', '管理员', 'hash', 1, null, $founder);
    }
}
