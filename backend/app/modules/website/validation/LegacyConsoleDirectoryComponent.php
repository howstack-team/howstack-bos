<?php

declare(strict_types=1);
namespace app\modules\website\validation;

/** 仅供历史目录快照与已执行迁移重放，不用于新包安装。 */
final class LegacyConsoleDirectoryComponent
{
    public const TYPES = ['responsive', 'pc', 'mobile'];
    public function validate(mixed $component, string $directory, array $files): array
    {
        if (!is_array($component) || array_diff(array_keys($component), ['routes']) !== []) ThemeSchema::fail('console', '会员中心仅声明 routes，入口按目录识别');
        $routes = $component['routes'] ?? ['auth.login', 'auth.register', 'console.overview', 'account.profile'];
        if (!is_array($routes) || !array_is_list($routes) || count($routes) > 64 || count(array_unique($routes, SORT_REGULAR)) !== count($routes)
            || array_diff(['auth.login', 'auth.register', 'console.overview', 'account.profile'], $routes) !== []) ThemeSchema::fail('console.routes', '会员中心须实现登录、注册、总览及个人资料');
        foreach ($routes as $route) if (!is_string($route) || !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $route)) ThemeSchema::fail('console.routes', '路由名称无效');
        foreach (glob($directory . '/console/*', GLOB_ONLYDIR) ?: [] as $folder) if (!in_array(basename($folder), self::TYPES, true)) ThemeSchema::fail('console', '展示类型仅支持 responsive、pc、mobile');
        $result = [];
        foreach (self::TYPES as $type) {
            if (!is_dir($directory . '/console/' . $type)) continue;
            $index = 'console/' . $type . '/index.html';
            if (!in_array($index, $files, true)) ThemeSchema::fail('console.' . $type, '已提供的模板须包含 index.html');
            $html = file_get_contents($directory . '/' . $index);
            if (!preg_match('/<head\b[^>]*>/i', $html) || preg_match('/<base\b/i', $html) || str_contains($html, 'console-theme-config')) ThemeSchema::fail('console.' . $type, 'HTML须有head，不能预置base或系统配置节点');
            $schemaFile = 'console/' . $type . '/settings.schema.json';
            $schema = in_array($schemaFile, $files, true) ? json_decode(file_get_contents($directory . '/' . $schemaFile), true, 32, JSON_THROW_ON_ERROR) : ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
            $validator = new ThemeSchema();
            if (!is_array($schema) || ($schema['type'] ?? '') !== 'object') ThemeSchema::fail('console.settings', '展示配置必须是对象');
            $validator->check($schema); $defaults = $validator->defaults($schema); $validator->validate($defaults, $schema, 'defaults');
            $result[$type] = ['index' => $index, 'routes' => $routes, 'schema' => $schema, 'defaults' => $defaults];
        }
        if (!isset($result['responsive']) && (!isset($result['pc']) || !isset($result['mobile']))) ThemeSchema::fail('console', '会员中心必须提供 responsive，或同时提供 pc 和 mobile');
        return $result;
    }
}
