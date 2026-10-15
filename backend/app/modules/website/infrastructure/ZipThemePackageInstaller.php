<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure;

use app\exception\BusinessException;
use app\modules\website\contract\ThemePackageInstallerInterface;
use app\modules\website\validation\ThemeSchema;
use app\shared\http\ApiErrorCode;
use RuntimeException;
use ZipArchive;

/** 逐文件读取 ZIP，不使用 extractTo，不执行安装钩子或 Composer。 */
final readonly class ZipThemePackageInstaller implements ThemePackageInstallerInterface
{
    public const MAX_ARCHIVE_BYTES = 26214400;
    private const MAX_FILE_BYTES = 10485760;
    private const MAX_TOTAL_BYTES = 104857600;

    public function __construct(private string $staging, private string $coreVersion)
    {
    }

    /** @inheritDoc */
    public function unpack(string $archive): array
    {
        if (!class_exists(ZipArchive::class)) throw new BusinessException('主题安装需要 PHP ZIP 扩展，请先安装并启用', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        if (!is_file($archive) || filesize($archive) > self::MAX_ARCHIVE_BYTES || (new \finfo(FILEINFO_MIME_TYPE))->file($archive) !== 'application/zip') ThemeSchema::fail('file', '请选择不超过25MiB的主题ZIP包');
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) ThemeSchema::fail('file', '无法读取主题ZIP包');
        $temporary = $this->staging . '/incoming-' . bin2hex(random_bytes(12));
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 2048) ThemeSchema::fail('file', '主题包条目数量超限');
            $files = [];
            $total = 0;
            $id = null;
            // 先验证全部条目，再写入私有暂存目录。
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) ThemeSchema::fail('file', 'ZIP条目损坏');
                $name = $stat['name'];
                if (strlen($name) > 240 || !preg_match('~^[a-z][a-z0-9_-]{0,63}/[a-zA-Z0-9_./-]*$~D', $name)
                    || str_contains($name, '..') || str_contains($name, '//') || ($stat['encryption_method'] ?? 0) !== 0) ThemeSchema::fail('file', '主题包包含非法路径或加密条目');
                $root = explode('/', $name)[0];
                if ($id !== null && $id !== $root) ThemeSchema::fail('file', '主题ZIP包必须只包含一个主题标识目录');
                $id = $root;
                if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) ThemeSchema::fail('file', 'ZIP文件属性无效');
                $type = ($attributes >> 16) & 0170000;
                if ($opsys === ZipArchive::OPSYS_UNIX && !in_array($type, [0, 0100000, 0040000], true)) ThemeSchema::fail('file', '主题包不允许符号链接或特殊文件');
                $key = strtolower(rtrim($name, '/'));
                if (isset($files[$key])) ThemeSchema::fail('file', '主题包包含重复路径');
                $directory = str_ends_with($name, '/');
                if (($type === 0040000 && !$directory) || ($type === 0100000 && $directory)) ThemeSchema::fail('file', '主题包条目类型无效');
                if (!$directory && (!in_array(pathinfo($name, PATHINFO_EXTENSION), ['json', 'twig', 'html', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'woff2'], true)
                    || str_contains($name, '/.') || $stat['size'] > self::MAX_FILE_BYTES || ($total += $stat['size']) > self::MAX_TOTAL_BYTES
                    || $stat['size'] > max(1, $stat['comp_size']) * 200)) ThemeSchema::fail('file', '主题包包含不允许的文件或解压内容超限');
                $files[$key] = ['name' => $name, 'directory' => $directory, 'size' => $stat['size'], 'crc' => $stat['crc']];
            }
            foreach ($files as $entry) {
                $parent = dirname(rtrim($entry['name'], '/'));
                while ($parent !== '.') {
                    if (isset($files[strtolower($parent)]) && !$files[strtolower($parent)]['directory']) ThemeSchema::fail('file', '主题包文件与目录路径冲突');
                    $parent = dirname($parent);
                }
            }
            if (!mkdir($temporary, 0700, true)) throw new RuntimeException('无法创建主题安装暂存目录');
            $count = 0;
            foreach ($files as $entry) {
                if ($entry['directory']) continue;
                if (++$count > 1024) ThemeSchema::fail('file', '主题包最多1024个文件');
                $stream = $zip->getStream($entry['name']);
                if ($stream === false) ThemeSchema::fail('file', '无法读取ZIP文件内容');
                try { $bytes = stream_get_contents($stream, self::MAX_FILE_BYTES + 1); }
                finally { fclose($stream); }
                if ($bytes === false || strlen($bytes) !== $entry['size'] || strlen($bytes) > self::MAX_FILE_BYTES || sprintf('%u', crc32($bytes)) !== sprintf('%u', $entry['crc'])) ThemeSchema::fail('file', 'ZIP文件大小或完整性校验失败');
                $path = $temporary . '/' . $entry['name'];
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) throw new RuntimeException('无法创建主题安装目录');
                if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('无法写入主题安装文件');
            }
            if (!is_file($temporary . '/' . $id . '/theme.json')) ThemeSchema::fail('manifest', '缺少 theme.json');
            try {
                $manifest = json_decode(file_get_contents($temporary . '/' . $id . '/theme.json'), true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new BusinessException('theme.json 不是有效的 JSON', ApiErrorCode::VALIDATION_FAILED, 422, previous: $error);
            }
            if (isset($manifest['components']) || isset($manifest['templates'])) ThemeSchema::fail('manifest', '请按 website/ 与 console/ 目录重新交付主题');
            $registry = new FileThemeRegistry($temporary, $temporary . '/snapshots', $temporary . '/assets', $this->coreVersion);
            try { return $registry->read($id ?? '') + ['staging_directory' => $temporary]; }
            catch (\Twig\Error\Error $error) { throw new BusinessException('主题模板语法或允许的能力校验失败', ApiErrorCode::VALIDATION_FAILED, 422, previous: $error); }
        } catch (\Throwable $error) {
            $this->remove($temporary);
            throw $error;
        } finally { $zip->close(); }
    }

    /** @inheritDoc */
    public function discard(array $package): void
    {
        $directory = $package['staging_directory'] ?? '';
        if (!is_string($directory) || !preg_match('~^' . preg_quote($this->staging, '~') . '/incoming-[a-f0-9]{24}$~D', $directory)) throw new RuntimeException('安装暂存目录无效');
        $this->remove($directory);
    }

    /** 只清理本次随机暂存目录。 */
    private function remove(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
