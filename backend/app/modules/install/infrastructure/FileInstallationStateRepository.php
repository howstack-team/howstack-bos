<?php

declare(strict_types=1);

namespace app\modules\install\infrastructure;

use app\modules\install\contract\InstallationStateRepositoryInterface;
use app\modules\install\domain\InstallationConfiguration;
use app\modules\install\exception\InstallationException;
use app\exception\BusinessException;
use app\modules\system_settings\contract\AdminEntryRepositoryInterface;
use app\shared\http\ApiErrorCode;
use Closure;
use Throwable;

/**
 * 使用受控文件和文件锁保存在线安装状态及运行配置。
 */
final class FileInstallationStateRepository implements InstallationStateRepositoryInterface, AdminEntryRepositoryInterface
{
    private const LOCK_TTL_SECONDS = 1800;
    private const PHASES = ['database', 'system', 'configuration', 'finalize'];

    /** 可注入隔离安装目录，生产环境沿用 Webman 项目目录。 */
    public function __construct(private readonly ?string $directory = null)
    {
    }

    /** @inheritDoc */
    public function changeAdminPath(string $expected, string $path): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{2,31}$/D', $path)) {
            throw new BusinessException('后台访问路径格式无效', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $this->withLock(function () use ($expected, $path): void {
            if (!$this->isInstalled()) throw new BusinessException('请先完成系统安装', ApiErrorCode::CONFLICT, 409);
            if (!hash_equals($this->adminPath(), $expected)) {
                throw new BusinessException('后台路径已被其他管理员修改，请重新加载后再保存', ApiErrorCode::CONFLICT, 409);
            }
            if ($path !== $expected) $this->writeEnvironment(['ADMIN_PATH' => $path]);
        });
    }

    /**
     * 判断 .env 是否已标记系统安装完成。
     * @return bool 已安装时返回 true
     */
    public function isInstalled(): bool
    {
        return strtolower($this->readEnvironmentValue('APP_INSTALLED')) === 'true';
    }

    /**
     * 读取并校验后台访问路径。
     * @return string 合法后台路径，配置无效时返回 admin
     */
    public function adminPath(): string
    {
        $path = strtolower(trim($this->readEnvironmentValue('ADMIN_PATH'), " /\t\n\r\0\x0B"));

        return preg_match('/^[a-z][a-z0-9-]{2,31}$/', $path) ? $path : 'admin';
    }

    /**
     * 在文件锁内申请执行指定安装阶段。
     * @param string $token                    安装会话令牌
     * @param string $databaseFingerprint      数据库配置指纹
     * @param string $configurationFingerprint 完整配置指纹
     * @param string $phase                    安装阶段标识
     * @return void
     * @throws InstallationException 会话、指纹、锁或阶段顺序不合法时抛出
     */
    public function claim(
        string $token,
        string $databaseFingerprint,
        string $configurationFingerprint,
        string $phase,
    ): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InstallationException('安装会话无效，请刷新安装页面', 403);
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $databaseFingerprint)
            || !preg_match('/^[a-f0-9]{64}$/', $configurationFingerprint)) {
            throw new InstallationException('安装配置指纹无效', 400);
        }
        $phaseIndex = array_search($phase, self::PHASES, true);
        if ($phaseIndex === false) {
            throw new InstallationException('未知的安装阶段');
        }

        $this->withLock(function () use (
            $token,
            $databaseFingerprint,
            $configurationFingerprint,
            $phaseIndex,
        ): void {
            if ($this->isInstalled()) {
                throw new InstallationException('系统已经安装，禁止重复执行安装', 409);
            }

            $activePath = $this->installRuntimePath() . '/active.json';
            $active = $this->readJson($activePath);
            $tokenHash = hash('sha256', $token);
            $isExpired = ((int) ($active['updated_at'] ?? 0)) < time() - self::LOCK_TTL_SECONDS;

            if ($active !== [] && !$isExpired && !hash_equals((string) ($active['token_hash'] ?? ''), $tokenHash)) {
                throw new InstallationException('另一个安装会话正在执行，请稍后重试', 423);
            }

            $completed = $active !== [] && !$isExpired && is_array($active['completed'] ?? null)
                ? array_values(array_intersect(self::PHASES, $active['completed']))
                : [];

            if (in_array('database', $completed, true)
                && !hash_equals((string) ($active['database_fingerprint'] ?? ''), $databaseFingerprint)) {
                throw new InstallationException('数据库阶段已完成，不能再修改 MySQL 或 Redis 配置', 409);
            }
            if (in_array('system', $completed, true)
                && !hash_equals((string) ($active['configuration_fingerprint'] ?? ''), $configurationFingerprint)) {
                throw new InstallationException('系统数据阶段已完成，不能再修改网站或管理员配置', 409);
            }

            if ($phaseIndex > count($completed)) {
                $requiredPhase = self::PHASES[count($completed)] ?? self::PHASES[0];
                throw new InstallationException("安装阶段顺序无效，请先完成 {$requiredPhase} 阶段", 409);
            }

            $this->writeJson($activePath, [
                'token_hash' => $tokenHash,
                'database_fingerprint' => $databaseFingerprint,
                'configuration_fingerprint' => $configurationFingerprint,
                'completed' => $completed,
                'updated_at' => time(),
            ]);
        });
    }

    /**
     * 在文件锁内记录指定安装阶段已完成。
     * @param string $token 安装会话令牌
     * @param string $phase 安装阶段标识
     * @return void
     * @throws InstallationException 会话失效、阶段不合法或状态写入失败时抛出
     */
    public function complete(string $token, string $phase): void
    {
        $this->withLock(function () use ($token, $phase): void {
            $activePath = $this->installRuntimePath() . '/active.json';
            $active = $this->readJson($activePath);
            if ($active === [] || !hash_equals((string) ($active['token_hash'] ?? ''), hash('sha256', $token))) {
                throw new InstallationException('安装会话已失效，请返回安装页面重试', 409);
            }

            $completed = is_array($active['completed'] ?? null)
                ? array_values(array_intersect(self::PHASES, $active['completed']))
                : [];
            if (!in_array($phase, $completed, true)) {
                $expected = self::PHASES[count($completed)] ?? '';
                if ($phase !== $expected || $phase === 'finalize') {
                    throw new InstallationException('无法记录无效的安装阶段', 409);
                }
                $completed[] = $phase;
            }

            $active['completed'] = $completed;
            $active['updated_at'] = time();
            $this->writeJson($activePath, $active);
        });
    }

    /**
     * 将尚未标记完成的应用配置写入 .env。
     * @param InstallationConfiguration $configuration 已校验的安装配置
     * @param string                    $appKey        应用密钥
     * @param string                    $jwtSecret     JWT 签名密钥
     * @return void
     * @throws InstallationException .env 读取或写入失败时抛出
     */
    public function writeConfiguration(
        InstallationConfiguration $configuration,
        string $appKey,
        string $jwtSecret,
    ): void {
        $this->writeEnvironment($configuration->environment($appKey, $jwtSecret, false));
    }

    /**
     * 写入安装完成记录并清理活动安装状态。
     * @param InstallationConfiguration $configuration 已校验的安装配置
     * @param string                    $token         安装会话令牌
     * @return void
     * @throws InstallationException 会话失效、锁定或状态写入失败时抛出
     */
    public function finalize(InstallationConfiguration $configuration, string $token): void
    {
        $this->withLock(function () use ($configuration, $token): void {
            $activePath = $this->installRuntimePath() . '/active.json';
            $active = $this->readJson($activePath);
            $expectedHash = hash('sha256', $token);

            if ($active === [] || !hash_equals((string) ($active['token_hash'] ?? ''), $expectedHash)) {
                throw new InstallationException('安装会话已失效，请返回安装页面重试', 409);
            }

            $installedAt = date(DATE_ATOM);
            $this->writeJson($this->installRuntimePath() . '/installed.json', [
                'version' => (string) config('version.current', '0.1.0'),
                'site_title' => $configuration->siteTitle,
                'admin_path' => $configuration->adminPath,
                'admin_username' => $configuration->adminUsername,
                'admin_nickname' => $configuration->adminNickname,
                'agreement_version' => InstallationConfiguration::AGREEMENT_VERSION,
                'agreement_accepted_at' => $installedAt,
                'installed_at' => $installedAt,
            ]);

            $this->writeEnvironment([
                'APP_INSTALLED' => 'true',
                'INSTALLED_AT' => $installedAt,
            ]);

            if (is_file($activePath)) {
                @unlink($activePath);
            }
        });
    }

    /**
     * 合并并原子写入环境变量。
     * @param array<string, string|int> $values 待更新环境变量
     * @return void
     * @throws InstallationException .env 无法读取或原子写入失败时抛出
     */
    private function writeEnvironment(array $values): void
    {
        $path = ($this->directory ?? base_path(false)) . '/.env';
        $existing = is_file($path) ? file_get_contents($path) : '';
        if ($existing === false) {
            throw new InstallationException('系统配置文件读取失败，请检查文件权限后重试', 500);
        }

        $remaining = $values;
        // 仅拆分真实换行；无 UTF-8 标记的 \R 会将中文里的 0x85 字节误当换行。
        $lines = $existing === '' ? [] : (preg_split('/\r\n|\n|\r/', rtrim($existing)) ?: []);
        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $matches)) {
                continue;
            }
            $key = $matches[1];
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $lines[$index] = $key . '=' . $this->encodeEnvironmentValue((string) $values[$key]);
            unset($remaining[$key]);
        }

        if ($lines !== [] && $remaining !== []) {
            $lines[] = '';
        }
        foreach ($remaining as $key => $value) {
            $lines[] = $key . '=' . $this->encodeEnvironmentValue((string) $value);
        }

        $content = implode(PHP_EOL, $lines) . PHP_EOL;
        $this->atomicWrite($path, $content, 0600);
    }

    /**
     * 从 .env 读取单个环境变量。
     * @param string $key 环境变量名称
     * @return string 解码后的环境变量值，不存在或不可读取时返回空字符串
     */
    private function readEnvironmentValue(string $key): string
    {
        $path = ($this->directory ?? base_path(false)) . '/.env';
        if (!is_file($path)) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (!preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)\s*$/', $line, $matches)) {
                    continue;
                }

                return $this->decodeEnvironmentValue(trim($matches[1]));
            }
        } finally {
            fclose($handle);
        }

        return '';
    }

    /**
     * 将环境变量值编码为双引号字符串。
     * @param string $value 原始环境变量值
     * @return string 可安全写入 .env 的值
     */
    private function encodeEnvironmentValue(string $value): string
    {
        return '"' . str_replace(
            ["\\", '"', "\n", "\r"],
            ["\\\\", '\\"', '\\n', '\\r'],
            $value,
        ) . '"';
    }

    /**
     * 解码从 .env 读取的环境变量值。
     * @param string $value 环境变量原始文本
     * @return string 解码后的环境变量值
     */
    private function decodeEnvironmentValue(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            return stripcslashes(substr($value, 1, -1));
        }

        return trim($value, "'\"");
    }

    /**
     * 获取并按需创建安装运行目录。
     * @return string 安装运行目录绝对路径
     * @throws InstallationException 安装运行目录无法创建时抛出
     */
    private function installRuntimePath(): string
    {
        $path = ($this->directory === null ? runtime_path() : $this->directory . '/runtime') . '/install';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new InstallationException('无法创建安装运行目录，请检查系统目录写入权限', 500);
        }

        return $path;
    }

    /**
     * 在独占文件锁内执行安装状态操作。
     * @param Closure(): void $operation 需要串行执行的操作
     * @return void
     * @throws InstallationException 锁文件无法创建或锁无法获取时抛出
     */
    private function withLock(Closure $operation): void
    {
        $handle = fopen($this->installRuntimePath() . '/operation.lock', 'c+');
        if ($handle === false) {
            throw new InstallationException('无法创建安装锁文件', 500);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new InstallationException('无法获取安装锁，请稍后重试', 423);
            }
            $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 读取 JSON 状态文件。
     * @param string $path JSON 文件绝对路径
     * @return array<string, mixed> 解码后的状态，不存在或无效时返回空数组
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 序列化并原子写入 JSON 状态文件。
     * @param string               $path JSON 文件绝对路径
     * @param array<string, mixed> $data 状态数据
     * @return void
     * @throws InstallationException 状态无法序列化或写入时抛出
     */
    private function writeJson(string $path, array $data): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (Throwable $exception) {
            throw new InstallationException('安装状态序列化失败', 500, $exception);
        }

        $this->atomicWrite($path, $json . PHP_EOL, 0600);
    }

    /**
     * 通过同目录临时文件原子替换目标文件。
     * @param string $path        目标文件绝对路径
     * @param string $contents    待写入内容
     * @param int    $permissions 文件权限
     * @return void
     * @throws InstallationException 临时文件创建、内容写入或替换失败时抛出
     */
    private function atomicWrite(string $path, string $contents, int $permissions): void
    {
        $directory = dirname($path);
        $temporary = tempnam($directory, '.hlyq-install-');
        if ($temporary === false) {
            throw new InstallationException('无法创建临时配置文件', 500);
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new InstallationException('写入安装配置失败', 500);
            }
            chmod($temporary, $permissions);
            if (!rename($temporary, $path)) {
                throw new InstallationException('替换安装配置失败', 500);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
