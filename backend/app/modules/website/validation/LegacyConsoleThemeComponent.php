<?php

declare(strict_types=1);

namespace app\modules\website\validation;

/** 会员中心只接受预构建HTML和静态资源与公开展示配置，不执行服务器端代码。 */
final class LegacyConsoleThemeComponent
{
    public const TYPES = ['responsive', 'desktop', 'mobile'];

    /** @return array<string, array<string, mixed>> 已校验的各类型入口 */
    public function validate(mixed $component, string $directory, array $files): array
    {
        if (!is_array($component) || ($component['contract'] ?? '') !== '1'
            || array_diff(array_keys($component), ['contract', 'entries']) !== []
            || !is_array($component['entries'] ?? null) || $component['entries'] === []
            || array_diff(array_keys($component['entries']), self::TYPES) !== []) {
            ThemeSchema::fail('components.console', '请声明会员中心契约1及有效展示类型');
        }
        $result = [];
        foreach ($component['entries'] as $type => $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['index', 'routes', 'settings_schema', 'settings_defaults']) !== []) {
                ThemeSchema::fail('components.console.entries', '会员中心入口声明无效');
            }
            $this->file($entry['index'] ?? null, $files, ['html']);
            $html = file_get_contents($directory . '/' . $entry['index']);
            if (!preg_match('/<head\b[^>]*>/i', $html) || preg_match('/<base\b/i', $html) || str_contains($html, 'console-theme-config')) {
                ThemeSchema::fail('components.console.index', 'HTML须包含head，且不能预置base或系统配置节点');
            }
            $routes = $entry['routes'] ?? null;
            if (!is_array($routes) || !array_is_list($routes) || count($routes) > 64
                || count(array_unique($routes, SORT_REGULAR)) !== count($routes)
                || array_diff(['auth.login', 'auth.register', 'console.overview', 'account.profile'], $routes) !== []) {
                ThemeSchema::fail('components.console.routes', '会员中心须实现登录、注册、总览及个人资料页面');
            }
            foreach ($routes as $route) {
                if (!is_string($route) || !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $route)) {
                    ThemeSchema::fail('components.console.routes', '路由名称无效');
                }
            }
            $empty = ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
            $schema = isset($entry['settings_schema']) ? $this->json($directory, $entry['settings_schema'], $files) : $empty;
            $defaults = isset($entry['settings_defaults']) ? $this->json($directory, $entry['settings_defaults'], $files) : [];
            if (($schema['type'] ?? '') !== 'object') {
                ThemeSchema::fail('components.console.settings_schema', '展示配置必须是对象');
            }
            $validator = new ThemeSchema();
            $validator->check($schema);
            $validator->validate($defaults, $schema, 'defaults');
            $result[$type] = $entry + ['schema' => $schema, 'defaults' => $defaults];
        }
        return $result;
    }

    private function file(mixed $name, array $files, array $extensions): void
    {
        if (!is_string($name) || !str_starts_with($name, 'console/') || !in_array($name, $files, true)
            || !in_array(pathinfo($name, PATHINFO_EXTENSION), $extensions, true)) {
            ThemeSchema::fail('components.console.file', '会员中心声明文件须位于console目录且类型正确');
        }
    }

    private function json(string $directory, mixed $name, array $files): array
    {
        $this->file($name, $files, ['json']);
        try {
            $value = json_decode(file_get_contents($directory . '/' . $name), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            ThemeSchema::fail('components.console.settings', '展示配置JSON无效');
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            ThemeSchema::fail('components.console.settings', '展示配置须为JSON对象');
        }
        return $value;
    }
}
