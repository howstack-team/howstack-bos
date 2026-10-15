<?php

declare(strict_types=1);

namespace tests\unit\modules\api_documentation;

use app\exception\BusinessException;
use app\exception\Handler;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\access\domain\PermissionCatalog;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\contract\AdminAccessTokenServiceInterface;
use app\modules\admin\auth\contract\AdminAccountRepositoryInterface;
use app\modules\admin\auth\domain\AccessTokenClaims;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\api_documentation\application\ViewApiDocumentationService;
use app\modules\api_documentation\controller\ApiDocumentationController;
use app\modules\api_documentation\contract\DocumentationArtifactInterface;
use app\modules\api_documentation\infrastructure\FileDocumentationArtifacts;
use app\modules\api_documentation\middleware\DocumentationAccessMiddleware;
use app\modules\install\contract\InstallationStateRepositoryInterface;
use app\shared\http\ApiResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use support\Request;
use Webman\Config;

/** 文档页面、原始契约与角色撤销使用同一认证及权限边界。 */
final class ApiDocumentationTest extends TestCase
{
    private string|false $originalFlag;

    protected function setUp(): void
    {
        $this->originalFlag = getenv('API_DOCS_ENABLED');
        putenv('API_DOCS_ENABLED=true');
        Config::load(config_path(), ['route']);
    }

    protected function tearDown(): void
    {
        putenv($this->originalFlag === false ? 'API_DOCS_ENABLED' : 'API_DOCS_ENABLED=' . $this->originalFlag);
        Config::load(config_path(), ['route']);
    }

    public function testAnonymousPageRedirectsToConfiguredLoginButSpecificationReturns401(): void
    {
        [$middleware, $controller] = $this->pipeline([]);
        $page = $middleware->process($this->request('/api-docs', false), $controller->page(...));
        self::assertSame(302, $page->getStatusCode());
        self::assertSame('/private-admin/login', $page->getHeader('Location'));
        $spec = $middleware->process($this->request('/api-docs/specs/admin.json', false),
            static fn (Request $request) => $controller->specification($request, 'admin'));
        self::assertSame(401, $spec->getStatusCode());
        self::assertSame(['code', 'message', 'data', 'request_id'], array_keys(json_decode($spec->rawBody(), true)));
        self::assertSame('no-store, private', $spec->getHeader('Cache-Control'));
        self::assertSame('', $page->rawBody());
    }

    public function testAuthenticatedUserWithoutPermissionCannotReadPageOrSpecification(): void
    {
        [$middleware, $controller] = $this->pipeline([]);
        $page = $middleware->process($this->request('/api-docs'), $controller->page(...));
        $spec = $middleware->process($this->request('/api-docs/specs/admin.json'),
            static fn (Request $request) => $controller->specification($request, 'admin'));
        self::assertSame(403, $page->getStatusCode());
        self::assertSame(403, $spec->getStatusCode());
        self::assertStringNotContainsString('openapi', $spec->rawBody());
    }

    public function testGrantedPermissionReturnsRawSpecificationAndLocalPageWithNoCaching(): void
    {
        [$middleware, $controller] = $this->pipeline(['admin.api-docs.view']);
        $page = $middleware->process($this->request('/api-docs'), $controller->page(...));
        $spec = $middleware->process($this->request('/api-docs/specs/admin.json'),
            static fn (Request $request) => $controller->specification($request, 'admin'));
        self::assertSame(200, $page->getStatusCode());
        self::assertSame(200, $spec->getStatusCode());
        self::assertSame('3.1.0', json_decode($spec->rawBody(), true)['openapi']);
        self::assertArrayNotHasKey('code', json_decode($spec->rawBody(), true));
        self::assertSame('no-store, private', $page->getHeader('Cache-Control'));
        self::assertSame('Cookie, Authorization', $spec->getHeader('Vary'));
        self::assertStringContainsString("connect-src 'self'", $page->getHeader('Content-Security-Policy'));
        self::assertContains('admin.api-docs.view', array_keys(PermissionCatalog::all()));
    }

    public function testPermissionRevocationTakesEffectOnNextSpecificationRequest(): void
    {
        $repository = $this->createMock(AdminAccessRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('grantsForAdmin')->willReturnOnConsecutiveCalls(
            [['permissions' => ['admin.api-docs.view']]], [],
        );
        [$middleware, $controller] = $this->pipeline([], $repository);
        $handler = static fn (Request $request) => $controller->specification($request, 'console');
        self::assertSame(200, $middleware->process($this->request('/api-docs/specs/console.json'), $handler)->getStatusCode());
        self::assertSame(403, $middleware->process($this->request('/api-docs/specs/console.json'), $handler)->getStatusCode());
    }

    public function testDisabledDocumentationIsNotExposedEvenToAuthenticatedUser(): void
    {
        foreach (['false', '0'] as $disabled) {
            putenv('API_DOCS_ENABLED=' . $disabled);
            Config::load(config_path(), ['route']);
            [$middleware, $controller] = $this->pipeline(['admin.api-docs.view']);
            self::assertSame(404, $middleware->process($this->request('/api-docs'), $controller->page(...))->getStatusCode());
        }
    }

    public function testArtifactReaderRejectsTraversalBeforeReadingAnyFile(): void
    {
        $reader = new FileDocumentationArtifacts();
        try {
            $reader->specification('../../.env');
            self::fail('不能访问任意文件');
        } catch (BusinessException $exception) {
            self::assertSame(404, $exception->httpStatus());
        }
    }

    public function testUnexpectedDocumentationErrorDoesNotExposeStackEvenInDebugMode(): void
    {
        $handler = new Handler(new NullLogger(), true, new ApiResponse());
        foreach (['/api-docs', '/api-docs/specs/admin.json'] as $path) {
            $response = $handler->render($this->request($path), new RuntimeException('internal password=secret'));
            self::assertSame(500, $response->getStatusCode());
            self::assertStringNotContainsString('secret', $response->rawBody());
            self::assertSame(50000, json_decode($response->rawBody(), true)['code']);
        }
    }

    /** @param list<string> $permissions @return array{DocumentationAccessMiddleware, ApiDocumentationController} */
    private function pipeline(array $permissions, ?AdminAccessRepositoryInterface $repository = null): array
    {
        $tokens = $this->createStub(AdminAccessTokenServiceInterface::class);
        $tokens->method('parse')->willReturn(new AccessTokenClaims(7, 'test', time(), time() + 60));
        $tokens->method('matchesAccount')->willReturn(true);
        $accounts = $this->createStub(AdminAccountRepositoryInterface::class);
        $accounts->method('findById')->willReturn(new AdminAccount(7, 'staff01', '测试', 'hash', 1, null));
        if ($repository === null) {
            $repository = $this->createStub(AdminAccessRepositoryInterface::class);
            $repository->method('grantsForAdmin')->willReturn([['permissions' => $permissions]]);
        }
        $artifacts = $this->createStub(DocumentationArtifactInterface::class);
        $artifacts->method('page')->willReturn('<!doctype html><title>API 文档</title>');
        $artifacts->method('specification')->willReturn('{"openapi":"3.1.0","paths":{}}');
        $context = new AdminAuthenticationContext();
        $installation = $this->createStub(InstallationStateRepositoryInterface::class);
        $installation->method('adminPath')->willReturn('private-admin');

        return [new DocumentationAccessMiddleware(new AdminAuthMiddleware($tokens, $accounts, $context), $installation, new ApiResponse()),
            new ApiDocumentationController($context, new ViewApiDocumentationService(new AdminAccessControlService($repository), $artifacts))];
    }

    private function request(string $path, bool $authenticated = true): Request
    {
        return new Request("GET $path HTTP/1.1\r\nHost: example.test\r\n"
            . ($authenticated ? "Authorization: Bearer test\r\n" : '') . "\r\n");
    }
}
