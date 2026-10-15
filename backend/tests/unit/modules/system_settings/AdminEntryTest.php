<?php

declare(strict_types=1);

namespace tests\unit\modules\system_settings;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\install\infrastructure\FileInstallationStateRepository;
use app\modules\system_settings\application\ManageAdminEntryService;
use app\modules\system_settings\contract\{AdminEntryPathPolicyInterface, AdminEntryRepositoryInterface};
use app\modules\system_settings\infrastructure\HostAdminEntryPathPolicy;
use app\modules\website\contract\{ThemeRegistryInterface, SiteThemeRepositoryInterface};
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Webman\Route;
use Webman\Route\Route as RouteDefinition;

/** 覆盖入口变更的权限、真实文件原子更新、并发冲突及主题/宿主路由冲突。 */
final class AdminEntryTest extends TestCase
{
    public function testFileUpdateIsVisibleToOtherReadersAndPreservesConfiguration(): void
    {
        $directory = sys_get_temp_dir() . '/howstack-admin-entry-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $file = $directory . '/.env';
        file_put_contents($file, "# 保留配置\nAPP_INSTALLED=true\nADMIN_PATH=admin\nAPP_KEY=sample-secret\nADMIN_PATH=admin\nDB_HOST=localhost\n");
        try {
            $first = new FileInstallationStateRepository($directory);
            $second = new FileInstallationStateRepository($directory);
            $first->changeAdminPath('admin', 'manage-center');
            self::assertSame('manage-center', $second->adminPath());
            self::assertTrue($second->isInstalled());
            self::assertSame(0600, fileperms($file) & 0777);
            $saved = file_get_contents($file);
            self::assertStringContainsString('APP_KEY=sample-secret', $saved);
            self::assertStringContainsString('# 保留配置', $saved);
            self::assertSame(2, substr_count($saved, 'ADMIN_PATH="manage-center"'));
            try { $second->changeAdminPath('admin', 'stale-entry'); self::fail('旧入口不能覆盖新入口'); }
            catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
            self::assertSame($saved, file_get_contents($file));
            $second->changeAdminPath('manage-center', 'admin');
            self::assertSame('admin', $first->adminPath());
            foreach (['../entry', 'ABC', 'abc\nDB_HOST=evil'] as $invalid) {
                try { $first->changeAdminPath('admin', $invalid); self::fail('非法路径不能写入'); }
                catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
            }
            self::assertSame('admin', $first->adminPath());
        } finally {
            unlink($file);
            @unlink($directory . '/runtime/install/operation.lock');
            @rmdir($directory . '/runtime/install');
            @rmdir($directory . '/runtime');
            rmdir($directory);
        }
    }

    public function testServiceRejectsReadOnlyAndUpdateWithoutViewBeforeWriting(): void
    {
        foreach ([[], ['admin.page.settings.view'], ['admin.page.settings.update']] as $permissions) {
            $repository = $this->createMock(AdminEntryRepositoryInterface::class);
            $repository->expects(self::never())->method('changeAdminPath');
            $paths = $this->createMock(AdminEntryPathPolicyInterface::class);
            $paths->expects(self::never())->method('check');
            try { $this->service($repository, $paths, $permissions)->save($this->actor(), ['admin_path' => 'manage-center', 'current_admin_path' => 'admin']); self::fail('缺少权限必须拒绝'); }
            catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        }
    }

    public function testValidationRejectsMalformedInputAndDoesNotNormalizeIt(): void
    {
        $repository = $this->createMock(AdminEntryRepositoryInterface::class);
        $repository->expects(self::never())->method('changeAdminPath');
        $paths = $this->createMock(AdminEntryPathPolicyInterface::class);
        $paths->expects(self::never())->method('check');
        $service = $this->service($repository, $paths);
        foreach ([[], ['admin_path' => '/manage'], ['admin_path' => 'Manage'], ['admin_path' => ' manage '], ['admin_path' => str_repeat('a', 33)], ['admin_path' => 'ab'], ['admin_path' => 123], ['admin_path' => 'manage', 'secret' => 'value']] as $values) {
            try { $service->save($this->actor(), $values + ['current_admin_path' => 'admin']); self::fail('非法表单不能写入'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
    }

    public function testServiceReadsCurrentEntryAndSavesWithCompare(): void
    {
        $repository = $this->createMock(AdminEntryRepositoryInterface::class);
        $repository->method('adminPath')->willReturn('admin');
        $repository->expects(self::once())->method('changeAdminPath')->with('admin', 'manage-center');
        $paths = $this->createMock(AdminEntryPathPolicyInterface::class);
        $paths->expects(self::once())->method('check')->with('manage-center');
        $service = $this->service($repository, $paths);
        self::assertSame(['admin_path' => 'admin'], $service->get($this->actor()));
        self::assertSame(['admin_path' => 'manage-center'], $service->save($this->actor(), ['admin_path' => 'manage-center', 'current_admin_path' => 'admin']));
    }

    public function testPolicyProtectsReservedRoutesInstalledThemePagesAndPublicResources(): void
    {
        $website = $this->createStub(SiteThemeRepositoryInterface::class);
        $website->method('themes')->willReturn([['theme_id' => 'default', 'version' => '1.1.0', 'digest' => str_repeat('a', 64)]]);
        $themes = $this->createMock(ThemeRegistryInterface::class);
        $themes->expects(self::atLeastOnce())->method('pinned')->with('default', '1.1.0', str_repeat('a', 64))->willReturn(['manifest' => ['pages' => [['path' => '/company'], ['path' => '/careers/list']]]]);
        $policy = new HostAdminEntryPathPolicy($website, $themes);
        $routes = new ReflectionProperty(Route::class, 'allRoutes');
        $previous = $routes->getValue();
        $routes->setValue(null, [new RouteDefinition(['GET'], '/tenant-area[/{path:.+}]', static fn () => null)]);
        try {
            foreach (['api', 'api-docs', 'console', 'install', 'app', 'theme-assets', 'website-preview', 'company', 'careers', 'tenant-area', 'api-docs'] as $path) {
                try { $policy->check($path); self::fail('冲突路径不能覆盖现有功能'); }
                catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
            }
            $policy->check('manage-center');
            $policy->check('admin');
            self::assertTrue(true);
        } finally { $routes->setValue(null, $previous); }
    }

    private function actor(): AdminAccount
    {
        return new AdminAccount(1, 'admin', '管理员', 'hash', 1, null);
    }

    private function service(AdminEntryRepositoryInterface $repository, AdminEntryPathPolicyInterface $paths, array $permissions = ['admin.page.settings.view', 'admin.page.settings.update']): ManageAdminEntryService
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([['permissions' => $permissions, 'data_scope' => 'self', 'department_ids' => []]]);
        return new ManageAdminEntryService($repository, $paths, new AdminAccessControlService($access));
    }
}
