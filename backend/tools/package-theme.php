<?php

declare(strict_types=1);

/** 开发者本地打包，不在安装请求中执行命令或编译代码。 */
require dirname(__DIR__) . '/vendor/autoload.php';
if ($argc !== 3 || !is_dir($argv[1])) {
    fwrite(STDERR, "用法：php backend/tools/package-theme.php <主题目录> <输出ZIP>\n");
    exit(1);
}
$directory = realpath($argv[1]);
$id = basename($directory);
$outputDirectory = realpath(dirname($argv[2]));
if ($outputDirectory === false || $outputDirectory === $directory || str_starts_with($outputDirectory, $directory . '/')) {
    throw new RuntimeException('输出ZIP必须位于主题目录之外');
}
$registry = new app\modules\website\infrastructure\FileThemeRegistry(dirname($directory), sys_get_temp_dir() . '/theme-package-snapshots', sys_get_temp_dir() . '/theme-package-assets', '0.1.0');
$registry->read($id);
if (file_exists($argv[2])) {
    fwrite(STDERR, "输出文件已存在，请指定新的ZIP文件名\n");
    exit(1);
}
$archive = new ZipArchive();
if ($archive->open($argv[2], ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('无法创建ZIP');
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
    $archive->addFile($file->getPathname(), $id . '/' . substr($file->getPathname(), strlen($directory) + 1));
}
$archive->close();
echo "主题ZIP已生成\n";
