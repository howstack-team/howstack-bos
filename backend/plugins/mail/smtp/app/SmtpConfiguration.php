<?php

declare(strict_types=1);

namespace plugins\mail\smtp\app;

use app\shared\plugin\dto\Configuration;
use InvalidArgumentException;

/** SMTP v1 固定配置；拒绝多主机、协议前缀、URL、端口拼接及头部注入。 */
final class SmtpConfiguration
{
    /** @param Configuration $configuration 当前通道配置 @return Configuration 带默认值的规范配置 @throws InvalidArgumentException 配置错误 */
    public static function normalize(Configuration $configuration): Configuration
    {
        $values = $configuration->values;
        if (array_diff(array_keys($values), ['host', 'port', 'encryption', 'auth', 'username', 'password', 'from_email', 'from_name', 'reply_to', 'timeout']) !== []) {
            throw new InvalidArgumentException('SMTP 配置字段无效');
        }
        $values += ['port' => 465, 'encryption' => 'ssl', 'auth' => true, 'username' => '', 'password' => '', 'from_name' => '', 'reply_to' => '', 'timeout' => 10];
        $host = $values['host'] ?? '';
        if (!is_string($host) || strlen($host) > 253
            || (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            || !preg_match('/^[A-Za-z0-9.-]+$/D', $host)) {
            throw new InvalidArgumentException('SMTP 服务器只填写域名或 IPv4 地址');
        }
        foreach (['port' => [1, 65535], 'timeout' => [1, 30]] as $key => [$min, $max]) {
            if (!is_int($values[$key]) || $values[$key] < $min || $values[$key] > $max) {
                throw new InvalidArgumentException('SMTP 端口或超时无效');
            }
        }
        if (!in_array($values['encryption'], ['ssl', 'tls', 'none'], true) || !is_bool($values['auth'])) {
            throw new InvalidArgumentException('SMTP 加密或认证方式无效');
        }
        foreach (['username' => 128, 'password' => 2048, 'from_name' => 128] as $key => $max) {
            if (!is_string($values[$key]) || mb_strlen($values[$key]) > $max
                || preg_match('/[\x00-\x1f\x7f]/', $values[$key])) {
                throw new InvalidArgumentException('SMTP 登录凭据或发件名称格式无效');
            }
        }
        if ($values['auth'] && ($values['username'] === '' || $values['password'] === '')) {
            throw new InvalidArgumentException('启用 SMTP 认证时必须填写登录账号和密码或授权码');
        }
        foreach (['from_email', 'reply_to'] as $key) {
            $email = $values[$key] ?? '';
            if (!is_string($email) || ($key === 'from_email' && $email === '')
                || strlen($email) > 254 || preg_match('/[\x00-\x1f\x7f]/', $email)
                || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                throw new InvalidArgumentException('发件邮箱或回复邮箱无效');
            }
        }
        return new Configuration($values);
    }
}
