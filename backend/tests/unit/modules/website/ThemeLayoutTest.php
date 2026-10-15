<?php

declare(strict_types=1);
namespace tests\unit\modules\website;
use app\exception\BusinessException;
use app\modules\website\infrastructure\ZipThemePackageInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class ThemeLayoutTest extends TestCase
{
    #[DataProvider('layouts')]
    public function testCoverage(array $types, bool $valid): void
    {
        $files = ['theme.json' => '{"id":"example","name":"示例","version":"1.0.0","theme_api":"1","requires":{"core":">=0.1.0 <1.0.0"},"cover":"cover.svg"}', 'cover.svg' => '<svg/>'];
        foreach ($types as $type) $files['console/' . $type . '/index.html'] = '<html><head></head><body>SPA</body></html>';
        if ($types === ['website']) { unset($files['console/website/index.html']); $files['website/index.html.twig'] = '<h1>{{ site.name }}</h1>'; $files['website/errors/404.html.twig'] = '<h1>404</h1>'; }
        if (in_array($types, [['old-website'], ['old-mixed']], true)) {
            unset($files['console/' . $types[0] . '/index.html']);
            $files['template/index.html.twig'] = '<h1>旧官网</h1>';
            $files['template/errors/404.html.twig'] = '<h1>404</h1>';
            if ($types === ['old-mixed']) $files['console/pc/index.html'] = '<html><head></head><body>PC</body></html>';
        }
        if ($types === ['broken']) { unset($files['console/broken/index.html']);  $files['console/pc/assets/main.js'] = 'console.log(1)'; }
        if ($types === ['invalid-json']) $files['theme.json'] = '{invalid-json';
        $root = sys_get_temp_dir() . '/theme-layout-' . bin2hex(random_bytes(6)); mkdir($root);
        $zip = new \ZipArchive(); $zip->open($root . '/theme.zip', \ZipArchive::CREATE);
        foreach ($files as $name => $text) $zip->addFromString('example/' . $name, $text); $zip->close();
        $installer = new ZipThemePackageInstaller($root . '/stage', '0.1.0');
        try {
            try { $package = $installer->unpack($root . '/theme.zip'); self::assertTrue($valid, '不完整入口应拒绝安装'); $installer->discard($package); }
            catch (BusinessException $error) { self::assertFalse($valid, $error->getMessage()); self::assertSame(422, $error->httpStatus()); }
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); rmdir($root);
        }
    }
    public static function layouts(): array
    {
        return [[['responsive'], false], [['pc', 'mobile'], true], [['responsive', 'pc'], false], [['responsive', 'mobile'], false], [['pc'], true], [['mobile'], false], [[], false], [['desktop', 'mobile'], false], [['broken'], false], [['website'], true], [['old-website'], false], [['old-mixed'], false], [['invalid-json'], false]];
    }
}
