<?php

declare(strict_types=1);

namespace tests\unit\modules\website;

use app\exception\BusinessException;
use app\modules\website\infrastructure\ZipThemePackageInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** 验证真实ZIP中的越界路径、链接、重复文件和压缩炸弹在发布前拒绝。 */
final class ZipThemePackageInstallerTest extends TestCase
{
    #[DataProvider('unsafeEntries')]
    public function testUnsafeArchiveEntriesAreRejected(string $name): void
    {
        $this->rejectArchive(static fn (ZipArchive $zip) => $zip->addFromString($name, '{}'));
    }

    public static function unsafeEntries(): array
    {
        return array_map(static fn (string $name): array => [$name], [
            '../outside.json', '/outside.json', 'theme/../outside.json', 'theme\\outside.json', 'theme//outside.json',
            'theme/.env', 'theme/run.php', 'theme/templates/shell.phtml', 'theme/install.sh', 'theme/assets/image.svg.php',
        ]);
    }

    public function testSymlinkEntryIsRejected(): void
    {
        $this->rejectArchive(static function (ZipArchive $zip): void {
            $zip->addFromString('theme/assets/link.svg', '/etc/passwd');
            $zip->setExternalAttributesName('theme/assets/link.svg', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
    }

    public function testHighlyCompressedContentIsRejected(): void
    {
        $this->rejectArchive(static fn (ZipArchive $zip) => $zip->addFromString('theme/assets/huge.css', str_repeat('a', 1048576)));
    }

    public function testCaseCollisionsAreRejected(): void
    {
        $this->rejectArchive(static function (ZipArchive $zip): void {
            $zip->addFromString('theme/assets/Logo.svg', '<svg/>');
            $zip->addFromString('theme/assets/logo.svg', '<svg/>');
        });
    }

    public function testMultipleThemeRootsAreRejected(): void
    {
        $this->rejectArchive(static function (ZipArchive $zip): void {
            $zip->addFromString('first/theme.json', '{}');
            $zip->addFromString('second/theme.json', '{}');
        });
    }

    public function testDirectoryFileCollisionIsRejected(): void
    {
        $this->rejectArchive(static function (ZipArchive $zip): void {
            $zip->addFromString('theme/assets/logo.svg', '<svg/>');
            $zip->addFromString('theme/assets/logo.svg/child.json', '{}');
        });
    }

    public function testTooManyFilesAreRejectedAndStagingIsRemoved(): void
    {
        $this->rejectArchive(static function (ZipArchive $zip): void {
            for ($index = 0; $index < 101; $index++) $zip->addFromString('theme/assets/' . $index . '.css', 'body{}');
        });
    }

    /** 失败包只存在于独立临时目录，不能生成安装源、公开资源或遗留暂存目录。 */
    private function rejectArchive(callable $build): void
    {
        $root = sys_get_temp_dir() . '/theme-zip-check-' . bin2hex(random_bytes(5));
        mkdir($root, 0700, true);
        $path = $root . '/theme.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $build($zip);
        $zip->close();
        try {
            try {
                (new ZipThemePackageInstaller($root . '/staging', '0.1.0'))->unpack($path);
                self::fail('非法主题包必须拒绝');
            } catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
            self::assertSame([], glob($root . '/staging/incoming-*') ?: []);
            self::assertFileDoesNotExist($root . '/outside.json');
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($root);
        }
    }
}
