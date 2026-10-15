<?php

declare(strict_types=1);
namespace app\modules\website\infrastructure;

/** 仅供前向迁移：旧快照保持不动，生成符合新目录的独立版本。 */
final readonly class UpgradeInstalledThemePackage
{
    public function convert(array $package, string $root, string $version, array $default): array
    {
        $id = $package['manifest']['id']; $target = $root . '/' . $id;
        mkdir($target, 0750, true);
        $manifest = array_intersect_key($package['manifest'], array_flip(['id', 'name', 'theme_api', 'requires']));
        $manifest['version'] = $version;
        $cover = $package['manifest']['cover'] ?? $package['manifest']['preview'];
        $manifest['cover'] = 'cover.' . pathinfo($cover, PATHINFO_EXTENSION);
        $this->copy($package['directory'] . '/' . $cover, $target . '/' . $manifest['cover']);
        if ($package['website']) {
            $this->copy($package['directory'] . '/templates', $target . '/template');
            if (is_dir($package['directory'] . '/assets')) $this->copy($package['directory'] . '/assets', $target . '/template/assets');
            $pages = $package['manifest']['pages'] ?? [];
            foreach ($pages as &$page) unset($page['settings_schema']); unset($page);
            if ($pages !== []) $this->json($target . '/template/pages.json', $pages);
            $this->json($target . '/template/settings.schema.json', $package['schema'] + ['default' => $package['defaults']]);
        }
        foreach ($package['console'] as $type => $entry) {
            $newType = $type === 'desktop' ? 'pc' : $type;
            $parent = dirname($entry['index']);
            $this->copy($package['directory'] . '/' . $parent, $target . '/console/' . $newType);
            if (basename($entry['index']) !== 'index.html') $this->copy($package['directory'] . '/' . $entry['index'], $target . '/console/' . $newType . '/index.html');
            $this->json($target . '/console/' . $newType . '/settings.schema.json', $entry['schema'] + ['default' => $entry['defaults']]);
            $manifest['console']['routes'] = $entry['routes'];
        }
        if ($id === 'default' && $package['console'] === []) {
            $this->copy($default['directory'] . '/console', $target . '/console');
            $manifest['console'] = $default['manifest']['console'];
        }
        $this->json($target . '/theme.json', $manifest);
        return ['directory' => $target];
    }
    private function json(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    private function copy(string $source, string $target): void
    {
        if (is_dir($source)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) $this->copy($file->getPathname(), $target . '/' . substr($file->getPathname(), strlen($source) + 1));
            return;
        }
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0750, true);
        if (!copy($source, $target)) throw new \RuntimeException('无法迁移主题资源');
    }
}
