<?php

declare(strict_types=1);

namespace app\shared\plugin\configuration;

use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpsUrl;

/** 仅提供通道字段的稳定格式校验，平台专有规则仍在各插件内。 */
final class ChannelFields
{
    /**
     * @param array<string, mixed> $values 本次字段
     * @param string $key 字段名
     * @param int $max 字符长度上限
     * @param bool $required 是否必填
     * @return string 已验证文本，不自动裁剪秘密值
     * @throws PluginException 文本格式无效
     */
    public static function text(array $values, string $key, int $max = 128, bool $required = true): string
    {
        $value = $values[$key] ?? '';
        if (!is_string($value) || ($required && trim($value) === '') || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new PluginException('通道字段无效：' . $key, 'invalid_configuration');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $values 本次字段
     * @param string $key 字段名
     * @param array<string> $choices 允许值
     * @return string 已验证选项
     * @throws PluginException 未声明选项
     */
    public static function choice(array $values, string $key, array $choices): string
    {
        $value = self::text($values, $key);
        if (!in_array($value, $choices, true)) {
            throw new PluginException('通道选项无效：' . $key, 'invalid_configuration');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $values 本次字段
     * @param string $key 字段名
     * @return string 受限地域标识
     * @throws PluginException 不是地域标识
     */
    public static function region(array $values, string $key = 'region'): string
    {
        $value = self::text($values, $key, 64);
        if (!preg_match('/^[a-z]{2}-[a-z]+(?:-[a-z0-9]+)*$/D', $value)) {
            throw new PluginException('地域标识无效', 'invalid_configuration');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $values 本次字段
     * @param string $key 字段名
     * @return string 无注入字符的邮箱
     * @throws PluginException 邮箱无效
     */
    public static function email(array $values, string $key): string
    {
        $value = self::text($values, $key, 254);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new PluginException('发件邮箱无效', 'invalid_configuration');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $values 本次字段
     * @param string $key 字段名
     * @return string HTTPS 站点基础地址，不含查询或片段
     * @throws PluginException 地址无效
     */
    public static function httpsBase(array $values, string $key): string
    {
        $value = self::text($values, $key, 512);
        if (!HttpsUrl::valid($value) || parse_url($value, PHP_URL_QUERY) !== null) {
            throw new PluginException('存储访问域名无效', 'invalid_configuration');
        }
        return rtrim($value, '/');
    }

    /**
     * @param array<string, mixed> $values 字段
     * @param string $key 字段名
     * @return string 未改写的固定 HTTPS 回调地址
     * @throws PluginException 地址无效
     */
    public static function httpsCallback(array $values, string $key): string
    {
        $value = self::text($values, $key, 512);
        if (!HttpsUrl::valid($value) || parse_url($value, PHP_URL_QUERY) !== null) {
            throw new PluginException('登录回调地址无效', 'invalid_configuration');
        }
        return $value;
    }
}
