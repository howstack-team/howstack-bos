<?php

declare(strict_types=1);

namespace app\modules\install\domain;

use app\modules\install\exception\InstallationException;

/**
 * 表示经过完整校验的系统安装配置。
 */
final readonly class InstallationConfiguration
{
    /**
     * 当前安装器内置的软件使用协议版本。
     */
    public const AGREEMENT_VERSION = '1.0';

    private const RESERVED_PATHS = [
        'api',
        'console',
        'install',
        'install-assets',
        'assets',
        'plugin-assets',
    ];

    /**
     * 创建系统安装配置值对象。
     * @param DatabaseConfiguration $database        数据库与缓存配置
     * @param string                $siteTitle       网站标题
     * @param string                $siteDescription 网站描述
     * @param string                $siteUrl         网站地址
     * @param string                $adminPath       后台访问路径
     * @param string                $adminUsername   管理员账号
     * @param string                $adminNickname   管理员昵称
     * @param string                $adminPassword   管理员明文初始密码
     */
    public function __construct(
        public DatabaseConfiguration $database,
        public string $siteTitle,
        public string $siteDescription,
        public string $siteUrl,
        public string $adminPath,
        public string $adminUsername,
        public string $adminNickname,
        public string $adminPassword,
    ) {
    }

    /**
     * 从安装表单数据创建并校验系统安装配置。
     * @param array<string, mixed> $input 安装表单数据
     * @return self 已校验的系统安装配置
     * @throws InstallationException 协议、网站、后台路径或管理员配置不合法时抛出
     */
    public static function fromArray(array $input): self
    {
        if (($input['agreement_agreed'] ?? false) !== true) {
            throw new InstallationException('请先阅读并同意皓量云擎云业务运营系统软件使用协议');
        }

        $system = is_array($input['system'] ?? null) ? $input['system'] : [];
        $admin = is_array($input['admin'] ?? null) ? $input['admin'] : [];

        $siteTitle = trim((string) ($system['site_title'] ?? ''));
        $siteDescription = '';
        $siteUrl = rtrim(trim((string) ($system['site_url'] ?? '')), '/');
        $adminPath = strtolower(trim((string) ($system['admin_path'] ?? ''), " /\t\n\r\0\x0B"));
        $adminUsername = trim((string) ($admin['username'] ?? ''));
        $adminNickname = trim((string) ($admin['nickname'] ?? ''));
        $adminPassword = (string) ($admin['password'] ?? '');

        if (mb_strlen($siteTitle) < 2 || mb_strlen($siteTitle) > 120) {
            throw new InstallationException('网站标题长度必须在 2 至 120 个字符之间');
        }
        if (strlen($siteUrl) > 255 || !filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            throw new InstallationException('请输入有效的网站地址，例如 https://example.com');
        }
        $scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InstallationException('网站地址仅支持 http 或 https 协议');
        }
        if (!preg_match('/^[a-z][a-z0-9-]{2,31}$/', $adminPath)) {
            throw new InstallationException('后台路径须以小写字母开头，只能包含小写字母、数字和连字符，长度为 3 至 32 位');
        }
        if (in_array($adminPath, self::RESERVED_PATHS, true)) {
            throw new InstallationException('该后台路径为系统保留路径，请更换');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{5,31}$/', $adminUsername)) {
            throw new InstallationException('管理员账号须以英文字母开头，只能包含英文字母、数字和下划线，长度为 6 至 32 位');
        }
        if (mb_strlen($adminNickname) < 2 || mb_strlen($adminNickname) > 32) {
            throw new InstallationException('管理员昵称长度必须在 2 至 32 个字符之间');
        }
        if (strlen($adminPassword) < 8 || strlen($adminPassword) > 128) {
            throw new InstallationException('管理员密码长度必须在 8 至 128 个字符之间');
        }
        if (!preg_match('/[a-z]/', $adminPassword)
            || !preg_match('/[A-Z]/', $adminPassword)
            || !preg_match('/\d/', $adminPassword)
            || !preg_match('/[^a-zA-Z\d]/', $adminPassword)) {
            throw new InstallationException('管理员密码必须同时包含大写字母、小写字母、数字和特殊字符');
        }

        return new self(
            DatabaseConfiguration::fromArray($input),
            $siteTitle,
            $siteDescription,
            $siteUrl,
            $adminPath,
            $adminUsername,
            $adminNickname,
            $adminPassword,
        );
    }

    /**
     * 生成需要写入 .env 的运行配置。
     * @param string $appKey    应用密钥
     * @param string $jwtSecret JWT 签名密钥
     * @param bool   $installed 是否已经完成安装
     * @return array<string, string|int> 环境变量键值
     */
    public function environment(string $appKey, string $jwtSecret, bool $installed): array
    {
        return [
            'APP_NAME' => $this->siteTitle,
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => $appKey,
            'APP_INSTALLED' => $installed ? 'true' : 'false',
            'APP_URL' => $this->siteUrl,
            'ADMIN_PATH' => $this->adminPath,
            'SESSION_SECURE' => str_starts_with(strtolower($this->siteUrl), 'https://') ? 'true' : 'false',
            'JWT_SECRET' => $jwtSecret,
            'JWT_TTL' => 7200,
            'DB_HOST' => $this->database->mysqlHost,
            'DB_PORT' => $this->database->mysqlPort,
            'DB_NAME' => $this->database->mysqlDatabase,
            'DB_USER' => $this->database->mysqlUsername,
            'DB_PASSWORD' => $this->database->mysqlPassword,
            'DB_PREFIX' => $this->database->tablePrefix,
            'REDIS_HOST' => $this->database->redisHost,
            'REDIS_PORT' => $this->database->redisPort,
            'REDIS_USERNAME' => $this->database->redisUsername,
            'REDIS_PASSWORD' => $this->database->redisPassword,
            'REDIS_DATABASE' => $this->database->redisDatabase,
        ];
    }

    /**
     * 计算完整安装配置指纹。
     * @return string SHA-256 配置指纹
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            $this->databaseFingerprint(),
            $this->siteTitle,
            $this->siteDescription,
            $this->siteUrl,
            $this->adminPath,
            $this->adminUsername,
            $this->adminNickname,
            $this->adminPassword,
        ]));
    }

    /**
     * 计算数据库与缓存配置指纹。
     * @return string SHA-256 数据库配置指纹
     */
    public function databaseFingerprint(): string
    {
        return hash('sha256', implode("\0", [
            $this->database->mysqlHost,
            (string) $this->database->mysqlPort,
            $this->database->mysqlDatabase,
            $this->database->mysqlUsername,
            $this->database->mysqlPassword,
            $this->database->tablePrefix,
            $this->database->redisHost,
            (string) $this->database->redisPort,
            $this->database->redisUsername,
            $this->database->redisPassword,
            (string) $this->database->redisDatabase,
        ]));
    }
}
