<?php

declare(strict_types=1);

namespace tests\unit\controller;

use app\controller\FrontendAssetController;
use app\controller\FrontendController;
use app\modules\install\contract\InstallationStateRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use support\Request;

/** 校验路径迁移保留自定义后台入口，并且不扩大公开文件边界。 */
final class FrontendControllerTest extends TestCase
{
    #[DataProvider('legacyPaths')]
    public function testLegacyRedirectUsesCorrectPageOrAssetBase(
        string $app,
        string $path,
        string $expected,
    ): void {
        $state = $this->createStub(InstallationStateRepositoryInterface::class);
        $state->method('adminPath')->willReturn('manage-center');
        $request = new Request("GET /app/{$app}/{$path}?tab=base&page=2 HTTP/1.1\r\nHost: example.test\r\n\r\n");

        $response = (new FrontendController($state))->redirectLegacy($request, $app, $path);

        self::assertSame(308, $response->getStatusCode());
        self::assertSame($expected . '?tab=base&page=2', $response->getHeader('Location'));
    }

    /** @return iterable<string, array{string, string, string}> 旧页面与资源书签 */
    public static function legacyPaths(): iterable
    {
        yield 'admin root' => ['admin', '', '/manage-center'];
        yield 'admin nested page' => ['admin', 'products/cloud-servers', '/manage-center/products/cloud-servers'];
        yield 'console login' => ['console', 'login', '/console/login'];
        yield 'console root' => ['console', '', '/console'];
        yield 'fixed admin asset' => ['admin', 'assets/index-test.js', '/admin/assets/index-test.js'];
        yield 'admin logo' => ['admin', 'logo.png', '/admin/logo.png'];
    }

    public function testCustomAdminPathDoesNotExposeDefaultOrLookalikePaths(): void
    {
        $state = $this->createStub(InstallationStateRepositoryInterface::class);
        $state->method('adminPath')->willReturn('manage-center');
        $controller = new FrontendController($state);

        foreach (['admin/login', 'manage-center-other/login', 'app/admin/login', 'api/private'] as $path) {
            self::assertSame(404, $controller->admin($path)->getStatusCode());
        }
    }

    #[DataProvider('unsafeAssets')]
    public function testAssetControllerRejectsNonPublicFiles(string $app, string $path): void
    {
        $request = new Request("GET /admin/assets/test HTTP/1.1\r\nHost: example.test\r\n\r\n");
        self::assertSame(404, (new FrontendAssetController())->show($request, $app, $path)->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> 非构建资源与路径穿越 */
    public static function unsafeAssets(): iterable
    {
        yield 'private environment' => ['admin', '../../.env'];
        yield 'nested traversal' => ['console', 'assets/../../.env'];
        yield 'encoded traversal' => ['admin', 'assets/%2e%2e%2f.env'];
        yield 'html entry through assets' => ['admin', 'index.html'];
        yield 'source map' => ['console', 'assets/index.js.map'];
        yield 'other application' => ['install', 'logo.png'];
    }
}
