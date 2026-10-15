<?php

declare(strict_types=1);

namespace tests\unit\modules\install\application;

use app\modules\install\application\{InstallationAuthorizationService, InstallApplicationService};
use app\modules\install\contract\{InstallationStateRepositoryInterface, EnvironmentInspectorInterface, ConnectionProbeInterface, InstallationDatabaseInterface, RuntimeReloaderInterface};
use app\modules\install\controller\InstallController;
use app\modules\install\exception\InstallationException;
use app\modules\install\infrastructure\FileInstallationAuthorizationRepository;
use app\shared\http\ApiResponse;
use PHPUnit\Framework\TestCase;
use support\Request;
use Workerman\Protocols\Http\Session;

final class InstallationAuthorizationTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { $this->directory = sys_get_temp_dir() . '/howstack-install-key-' . bin2hex(random_bytes(8)); }
    protected function tearDown(): void { foreach (glob($this->directory . '/*') ?: [] as $path) unlink($path); if (is_dir($this->directory)) rmdir($this->directory); }

    private function fixture(bool $installed = false): array
    {
        $state = $this->createStub(InstallationStateRepositoryInterface::class);
        $state->method('isInstalled')->willReturn($installed);
        $repository = new FileInstallationAuthorizationRepository($this->directory);
        return [new InstallationAuthorizationService($repository, $state), $repository, $state];
    }

    private function denied(callable $call): void
    {
        try { $call(); self::fail('不应允许匿名或失效授权'); }
        catch (InstallationException $exception) { self::assertContains($exception->httpStatus(), [403, 409]); }
    }

    public function testOnlyServerKeyAuthorizesAndRotationExpiryRevocationFailClosed(): void
    {
        [$service, $keys] = $this->fixture();
        $this->denied(fn () => $service->authorize(str_repeat('a', 64)));
        $key = $service->issue();
        $proof = $service->authorize($key);
        $service->assertProof($proof);
        self::assertStringNotContainsString($key, file_get_contents($this->directory . '/authorization.json'));
        self::assertSame(0600, fileperms($this->directory . '/authorization.json') & 0777);
        $next = $service->issue();
        $this->denied(fn () => $service->assertProof($proof));
        $service->assertProof($service->authorize($next));
        $keys->issue(hash('sha256', $next), time() - 1);
        $this->denied(fn () => $service->authorize($next));
        $service->revoke();
        self::assertFileDoesNotExist($this->directory . '/authorization.json');
    }

    public function testInstalledSystemCannotIssueOrReuseAuthorization(): void
    {
        [$service] = $this->fixture(true);
        $this->denied(fn () => $service->issue());
        $this->denied(fn () => $service->authorize(str_repeat('a', 64)));
    }

    public function testAnonymousVisitorSessionCannotBootstrapOrReachInstallationSinks(): void
    {
        [$authorization, , $state] = $this->fixture();
        $environment = $this->createMock(EnvironmentInspectorInterface::class);
        $environment->expects(self::never())->method('inspect');
        $probe = $this->createMock(ConnectionProbeInterface::class);
        $probe->expects(self::never())->method('test');
        $database = $this->createStub(InstallationDatabaseInterface::class);
        $installer = new InstallApplicationService($environment, $probe, $database, $state, $this->createStub(RuntimeReloaderInterface::class));
        $controller = new InstallController($installer, $state, new ApiResponse(), $authorization);
        foreach (['bootstrap', 'randomDefaults', 'testConnections', 'execute'] as $action) {
            $request = new Request("POST /api/install/test HTTP/1.1\r\nHost: localhost\r\nX-Install-Token: " . str_repeat('b', 64) . "\r\n\r\n");
            $session = new Session(bin2hex(random_bytes(16)));
            $session->set('installer.token', str_repeat('b', 64));
            $request->context['session'] = $session;
            $this->denied(fn () => $controller->$action($request));
            $session->flush();
        }
    }
}
