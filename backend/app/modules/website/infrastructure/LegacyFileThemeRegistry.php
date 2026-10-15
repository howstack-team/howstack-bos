<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure;

use app\modules\website\contract\ThemeRegistryInterface;
use app\modules\website\validation\ThemeSchema;
use RuntimeException;

/** 主题源码只允许 JSON、Twig 和静态文件；发布使用摘要隔离，不覆盖旧资源。 */
final readonly class LegacyFileThemeRegistry implements ThemeRegistryInterface
{
    public function __construct(private string $root, private string $snapshots, private string $publicAssets, private string $coreVersion)
    {
    }

    /** @inheritDoc */
    public function read(string $id): array
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)) ThemeSchema::fail('theme_id', '主题标识无效');
        $path = $this->root . '/' . $id;
        if (!is_dir($path) || is_link($path)) ThemeSchema::fail('theme_id', '主题不存在');
        $files = $this->files($path);
        $manifest = $this->json($path . '/theme.json');
        if (array_diff(array_keys($manifest), ['id', 'name', 'version', 'theme_api', 'requires', 'templates', 'pages', 'settings_schema', 'settings_defaults', 'preview', 'cover', 'components']) !== []
            || ($manifest['id'] ?? '') !== $id || ($manifest['theme_api'] ?? '') !== '1'
            || !is_string($manifest['name'] ?? null) || trim($manifest['name']) === '' || mb_strlen($manifest['name']) > 80
            || !is_string($manifest['version'] ?? null) || strlen($manifest['version']) > 32 || !preg_match('/^\d+\.\d+\.\d+$/D', $manifest['version'])
            || !is_array($manifest['requires'] ?? null) || array_keys($manifest['requires']) !== ['core'] || !is_string($manifest['requires']['core'])) ThemeSchema::fail('manifest', '主题清单无效');
        $components = $manifest['components'] ?? ['website' => []];
        if (!is_array($components) || $components === [] || array_diff(array_keys($components), ['website', 'console']) !== []) {
            ThemeSchema::fail('manifest.components', '主题至少声明官网或会员中心组件');
        }
        $website = array_key_exists('website', $components);
        if (isset($manifest['components'])) {
            if ($website) {
                if (!is_array($components['website']) || ($components['website']['contract'] ?? '') !== '1'
                    || array_diff(array_keys($components['website']), ['contract', 'templates', 'pages', 'settings_schema', 'settings_defaults']) !== []) {
                    ThemeSchema::fail('manifest.components.website', '官网组件声明无效');
                }
                $manifest = array_replace($manifest, array_diff_key($components['website'], ['contract' => true]));
            }
        }
        $console = isset($components['console']) ? (new \app\modules\website\validation\LegacyConsoleThemeComponent())->validate($components['console'], $path, $files) : [];
        $range = $manifest['requires']['core'] ?? '';
        if (!preg_match('/^>=(\d+\.\d+\.\d+) <(\d+\.\d+\.\d+)$/D', $range, $matches)
            || version_compare($this->coreVersion, $matches[1], '<') || version_compare($this->coreVersion, $matches[2], '>=')) ThemeSchema::fail('manifest.requires', '主题与当前系统版本不匹配');
        if ($website && (!is_array($manifest['templates'] ?? null) || array_diff(array_keys($manifest['templates']), ['home']) !== [])) ThemeSchema::fail('manifest.templates', '第一阶段只开放首页核心模板');
        if ($website && !isset($manifest['templates']['home'])) ThemeSchema::fail('manifest.templates.home', '请声明首页模板');
        if ($website) $this->declaredFile($path, 'templates/' . $manifest['templates']['home'], $files, 'twig');
        $pages = $manifest['pages'] ?? [];
        if (!is_array($pages) || count($pages) > 32) ThemeSchema::fail('manifest.pages', '自定义页面最多32个');
        $schema = $website ? $this->json($this->declaredFile($path, $manifest['settings_schema'] ?? '', $files, 'json')) : ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
        $validator = new ThemeSchema();
        $validator->check($schema);
        $pageProperties = [];
        $paths = [];
        foreach ($pages as $pageId => $page) {
            if (!is_string($pageId) || $pageId === 'home' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $pageId) || !is_array($page)
                || array_diff(array_keys($page), ['path', 'template', 'title', 'settings_schema', 'seo']) !== []) ThemeSchema::fail('manifest.pages', '页面声明无效');
            self::validatePath($page['path'] ?? '');
            if (isset($paths[$page['path']])) ThemeSchema::fail('manifest.pages', '页面路径重复');
            $paths[$page['path']] = true;
            if (!is_string($page['title'] ?? null) || mb_strlen($page['title']) > 100) ThemeSchema::fail('manifest.pages', '页面标题无效');
            if (isset($page['seo']) && (!is_array($page['seo']) || array_diff(array_keys($page['seo']), ['description', 'keywords', 'indexable']) !== []
                || !is_string($page['seo']['keywords'] ?? '') || mb_strlen($page['seo']['keywords'] ?? '') > 255
                || !is_string($page['seo']['description'] ?? '') || mb_strlen($page['seo']['description'] ?? '') > 500 || !is_bool($page['seo']['indexable'] ?? true))) ThemeSchema::fail('manifest.pages', '页面 SEO 声明无效');
            $this->declaredFile($path, 'templates/' . ($page['template'] ?? ''), $files, 'twig');
            $child = $this->json($this->declaredFile($path, $page['settings_schema'] ?? '', $files, 'json'));
            $validator->check($child);
            $pageProperties[$pageId] = $child;
        }
        $settingsSchema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['global', 'pages'], 'properties' => [
            'global' => $schema, 'pages' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($pageProperties), 'properties' => $pageProperties],
        ]];
        $defaults = $website ? $this->json($this->declaredFile($path, $manifest['settings_defaults'] ?? '', $files, 'json')) : ['global' => [], 'pages' => []];
        $validator->validate($defaults, $settingsSchema, 'defaults');
        $cover = $manifest['cover'] ?? $manifest['preview'] ?? '';
        $this->declaredFile($path, $cover, $files);
        if (!in_array(pathinfo($cover, PATHINFO_EXTENSION), ['svg', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true)) ThemeSchema::fail('manifest.cover', '主题封面必须为图片');
        // 加载全部模板触发 Twig 语法与白名单检查，避免先发布坏模板。
        $sources = [];
        foreach ($files as $file) if (str_starts_with($file, 'templates/') && str_ends_with($file, '.twig')) $sources[substr($file, 10)] = file_get_contents($path . '/' . $file);
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader($sources), ['autoescape' => 'html', 'strict_variables' => true]);
        $twig->addExtension(new \Twig\Extension\SandboxExtension(new \Twig\Sandbox\SecurityPolicy(
            ['extends', 'include', 'block', 'if', 'for'], ['escape', 'e', 'default', 'length', 'join', 'date', 'upper', 'lower', 'number_format'], [], [], ['asset', 'route'],
        ), true));
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $name): string => $name));
        $twig->addFunction(new \Twig\TwigFunction('route', static fn (string $name): string => $name));
        foreach ($files as $file) if (str_starts_with($file, 'templates/') && str_ends_with($file, '.twig')) $twig->load(substr($file, 10))->unwrap()->ensureSecurityChecked();

        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($hash, $file . "\0" . file_get_contents($path . '/' . $file) . "\0");
        }
        return ['manifest' => $manifest, 'schema' => $settingsSchema, 'defaults' => $defaults, 'digest' => hash_final($hash), 'directory' => $path, 'website' => $website, 'console' => $console];
    }

    /** 静态自定义路径不能占用核心、接口、插件或资源命名空间。 */
    public static function validatePath(mixed $path, array $additionalPrefixes = []): void
    {
        if (!is_string($path) || strlen($path) > 180 || !preg_match('~^/[a-z][a-z0-9-]*(?:/[a-z][a-z0-9-]*){0,5}$~D', $path)) ThemeSchema::fail('manifest.pages.path', '仅支持小写静态页面路径');
        foreach ([...$additionalPrefixes, 'api', 'admin', 'console', 'install', 'api-docs', 'app', 'theme-assets', 'install-assets', 'news', 'help', 'products', 'storage', 'website-preview', 'console-assets'] as $prefix) {
            $prefix = '/' . trim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) ThemeSchema::fail('manifest.pages.path', '页面路径与系统入口冲突');
        }
    }

    /** @inheritDoc */
    public function pinned(string $id, string $version, string $digest): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) throw new RuntimeException('官网主题尚未发布，请执行网站主题发布命令');
        $snapshot = $this->snapshots . '/' . $digest;
        if (is_dir($snapshot)) {
            // 从快照重新校验；源码更新不会改变已启用页面或资源。
            $package = $this->readDirectory($snapshot, $id);
        } else {
            $package = $this->read($id);
            if (!hash_equals($package['digest'], $digest)) throw new RuntimeException('主题源码已变化，请先发布主题再更新官网');
            $this->publish($package);
            $package['directory'] = $snapshot . '/' . $id;
        }
        if (($package['manifest']['version'] ?? '') !== $version || !hash_equals($digest, $package['digest'])) throw new RuntimeException('主题快照完整性校验失败');
        $this->publish($package);
        return $package;
    }

    /** 校验快照时借助主题 ID 的独立临时映射，避免暴露任意路径读取。 */
    private function readDirectory(string $directory, string $id): array
    {
        // 快照内部保留主题 ID 目录，摘要目录只负责版本隔离。
        return (new self($directory, $this->snapshots, $this->publicAssets, $this->coreVersion))->read($id);
    }

    /** 发布已校验包；临时目录完成后原子更名，多个 Worker 可以安全竞争。
     * @param array<string, mixed> $package 校验结果
     */
    public function publish(array $package): void
    {
        $id = $package['manifest']['id'];
        $digest = $package['digest'];
        $target = $this->snapshots . '/' . $digest;
        if (!is_dir($this->snapshots) && !@mkdir($this->snapshots, 0750, true) && !is_dir($this->snapshots)) throw new RuntimeException('无法创建主题快照目录');
        if (!is_dir($target)) {
            $temporary = $target . '.' . bin2hex(random_bytes(6));
            foreach ($this->files($package['directory']) as $file) $this->copy($package['directory'] . '/' . $file, $temporary . '/' . $id . '/' . $file);
            $copied = $this->readDirectory($temporary, $id);
            if (!hash_equals($digest, $copied['digest'])) { $this->remove($temporary); throw new RuntimeException('发布期间主题源码发生变化，请重试'); }
            if (!@rename($temporary, $target)) {
                $this->remove($temporary);
                if (!is_dir($target)) throw new RuntimeException('无法发布主题快照');
            }
        }
        $assets = $this->publicAssets . '/' . $id . '/' . $package['manifest']['version'] . '-' . $digest;
        if (!is_dir($assets)) {
            $temporary = $assets . '.' . bin2hex(random_bytes(6));
            foreach ($this->files($target . '/' . $id) as $file) {
                if (str_starts_with($file, 'assets/')) $this->copy($target . '/' . $id . '/' . $file, $temporary . '/' . substr($file, 7));
                if (str_starts_with($file, 'console/')) $this->copy($target . '/' . $id . '/' . $file, $temporary . '/' . $file);
            }
            $cover = $package['manifest']['cover'] ?? $package['manifest']['preview'];
            $this->copy($target . '/' . $id . '/' . $cover, $temporary . '/cover.' . pathinfo($cover, PATHINFO_EXTENSION));
            if (!is_dir($temporary)) mkdir($temporary, 0755, true);
            if (!is_dir(dirname($assets)) && !@mkdir(dirname($assets), 0755, true) && !is_dir(dirname($assets))) throw new RuntimeException('无法创建主题资源目录');
            if (!@rename($temporary, $assets)) {
                $this->remove($temporary);
                if (!is_dir($assets)) throw new RuntimeException('无法发布主题资源');
            }
        }
        $cover = $package['manifest']['cover'] ?? $package['manifest']['preview'];
        $coverPath = $assets . '/cover.' . pathinfo($cover, PATHINFO_EXTENSION);
        if (!is_file($coverPath)) {
            $temporary = $coverPath . '.' . bin2hex(random_bytes(6));
            $this->copy($target . '/' . $id . '/' . $cover, $temporary);
            if (!@rename($temporary, $coverPath)) {
                @unlink($temporary);
                if (!is_file($coverPath)) throw new RuntimeException('无法发布主题封面');
            }
        }
    }

    /** @inheritDoc */
    public function list(): array
    {
        $packages = [];
        foreach (glob($this->root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', basename($directory))) continue;
            $packages[] = $this->read(basename($directory));
        }
        return $packages;
    }

    /** @return list<string> 排序后的有限文件集合 */
    private function files(string $directory): array
    {
        $files = [];
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($directory) + 1);
            if ($entry->isLink() || !$entry->isFile() || !preg_match('~^[a-zA-Z0-9_./-]+$~D', $relative)
                || str_contains($relative, '..') || !in_array(strtolower($entry->getExtension()), ['json', 'twig', 'html', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'woff2'], true)
                || (str_starts_with($relative, 'assets/') && !in_array(strtolower($entry->getExtension()), ['css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'woff2'], true))
                || $entry->getSize() > 10485760 || ($bytes += $entry->getSize()) > 104857600 || count($files) >= 1024) ThemeSchema::fail('package', '主题包存在不允许的文件、链接或超限内容');
            $files[] = $relative;
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, mixed> JSON 对象 */
    private function json(string $path): array
    {
        try {
            $text = file_get_contents($path);
            if ($text === false || strlen($text) > 262144) ThemeSchema::fail('package', '主题 JSON 不存在或过大');
            $value = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            $this->uniqueJsonKeys($text);
        } catch (\JsonException) {
            ThemeSchema::fail('package', '主题 JSON 无效');
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) ThemeSchema::fail('package', '主题声明必须是对象');
        return $value;
    }

    /** JSON 原生解析会覆盖重复键；清单必须明确拒绝重复页面/字段标识。 */
    private function uniqueJsonKeys(string $json): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $index => $token) {
            if ($token === '{' || $token === '[') $stack[] = ['object' => $token === '{', 'keys' => []];
            elseif ($token === '}' || $token === ']') array_pop($stack);
            elseif (str_starts_with($token, '"') && ($matches[0][$index + 1] ?? '') === ':' && ($stack[array_key_last($stack)]['object'] ?? false)) {
                $key = json_decode($token, true, 4, JSON_THROW_ON_ERROR);
                $top = array_key_last($stack);
                if (isset($stack[$top]['keys'][$key])) ThemeSchema::fail('package', '主题 JSON 存在重复字段或页面标识');
                $stack[$top]['keys'][$key] = true;
            }
        }
    }

    private function declaredFile(string $root, mixed $file, array $files, ?string $extension = null): string
    {
        if (!is_string($file) || !in_array($file, $files, true) || ($extension !== null && pathinfo($file, PATHINFO_EXTENSION) !== $extension)) ThemeSchema::fail('package', '声明文件不存在或类型不正确');
        return $root . '/' . $file;
    }

    private function copy(string $source, string $destination): void
    {
        if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) throw new RuntimeException('无法创建主题发布目录');
        if (!copy($source, $destination)) throw new RuntimeException('无法复制主题文件');
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($directory);
    }
}
