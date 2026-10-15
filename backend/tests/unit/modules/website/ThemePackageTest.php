<?php

declare(strict_types=1);

namespace tests\unit\modules\website;

use app\exception\BusinessException;
use app\modules\website\infrastructure\FileThemeRegistry;
use app\modules\website\infrastructure\ThemeView;
use app\modules\website\validation\ThemeSchema;
use app\modules\website\validation\WebsiteSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 路由边界、声明校验及模板沙箱均不依赖业务数据库。 */
final class ThemePackageTest extends TestCase
{
    #[DataProvider('invalidPaths')]
    public function testCustomPathsCannotTakeOverHostRoutes(string $path): void
    {
        $this->expectException(BusinessException::class);
        FileThemeRegistry::validatePath($path, ['private-admin']);
    }

    public static function invalidPaths(): array
    {
        return array_map(static fn (string $path): array => [$path], ['/api/login', '/admin', '/private-admin/settings', '/console/new', '/theme-assets/image', '/Company', '//company', '/company/../admin', '/company%2fadmin', '/company?query=1', '/company/{id}', '/company\\team', '/products/test', '/website-preview/test']);
    }

    public function testCustomNestedPageDoesNotNeedCorePageEnumeration(): void
    {
        FileThemeRegistry::validatePath('/company/team');
        $schema = new ThemeSchema();
        $schema->check(WebsiteSettings::schema());
        $schema->validate(WebsiteSettings::defaults(), WebsiteSettings::schema());
        self::assertTrue(is_subclass_of(ThemeView::class, \Webman\View::class));
    }

    public function testHostPathPolicyRejectsDynamicAdministratorPath(): void
    {
        $state = $this->createStub(\app\modules\install\contract\InstallationStateRepositoryInterface::class);
        $state->method('adminPath')->willReturn('manage');
        $this->expectException(BusinessException::class);
        (new \app\modules\website\infrastructure\HostWebsitePathPolicy($state))->check(['manifest' => ['pages' => ['custom' => ['path' => '/manage/settings']]]]);
    }

    public function testWebsiteAuditDoesNotIncludePreviewContentOrToken(): void
    {
        $sanitizer = new \app\modules\admin\operation\application\OperationLogPayloadSanitizer();
        $audit = json_decode($sanitizer->params('website', 'minimal', ['revision' => 2, 'theme_revision' => 1, 'digest' => str_repeat('a', 64),
            'theme_settings' => ['secret' => 'PRIVATE'], 'website_settings' => ['navigation' => []], 'token' => 'PRIVATE-TOKEN']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('minimal', $audit['theme_id']);
        self::assertSame(2, $audit['revision']);
        self::assertStringNotContainsString('PRIVATE', json_encode($audit));
    }

    #[DataProvider('invalidSettings')]
    public function testUnknownFieldsAndInvalidTypesAreRejected(array $value): void
    {
        $this->expectException(BusinessException::class);
        (new ThemeSchema())->validate($value, WebsiteSettings::schema());
    }

    public static function invalidSettings(): array
    {
        $defaults = WebsiteSettings::defaults();
        return [[$defaults + ['script' => 'alert(1)']], [array_replace($defaults, ['navigation' => 'wrong'])], [array_replace($defaults, ['navigation' => array_fill(0, 13, $defaults['navigation'][0])])], [array_replace($defaults, ['logo_image_id' => '../../secret'])]];
    }

    public function testUnboundedOrExternalSchemaIsRejected(): void
    {
        $this->expectException(BusinessException::class);
        (new ThemeSchema())->check(['type' => 'object', 'additionalProperties' => false, 'properties' => [], '$ref' => 'https://example.test/schema']);
    }

    public function testSnapshotPinsTemplatesAndAssetsAcrossSourceChanges(): void
    {
        $temporary = sys_get_temp_dir() . '/website-theme-' . bin2hex(random_bytes(5));
        mkdir($temporary . '/source', 0755, true);
        $source = dirname(__DIR__, 4) . '/themes/default';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $target = $temporary . '/source/default/' . substr($file->getPathname(), strlen($source) + 1);
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
            copy($file->getPathname(), $target);
        }
        try {
            $registry = new FileThemeRegistry($temporary . '/source', $temporary . '/private', $temporary . '/assets', '0.1.0');
            $package = $registry->read('default');
            $pinned = $registry->pinned('default', $package['manifest']['version'], $package['digest']);
            file_put_contents($temporary . '/source/default/website/pages/company.html.twig', '{{ missing|raw }}');
            $again = $registry->pinned('default', $package['manifest']['version'], $package['digest']);
            self::assertSame($package['digest'], $again['digest']);
            self::assertSame($pinned['directory'], $again['directory']);
            self::assertFileExists($temporary . '/assets/default/' . $package['manifest']['version'] . '-' . $package['digest'] . '/css/main.css');
            $this->expectException(\Twig\Sandbox\SecurityNotAllowedFilterError::class);
            $registry->read('default');
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($temporary);
        }
    }
}
