<?php

declare(strict_types=1);

namespace tests\unit\modules\install\validation;

use app\modules\install\exception\InstallationException;
use app\modules\install\validation\InstallRequestValidator;
use PHPUnit\Framework\TestCase;

/**
 * 验证在线安装请求的场景规则、白名单和错误结构。
 */
final class InstallRequestValidatorTest extends TestCase
{
    /**
     * 验证连接测试场景只返回数据库与缓存配置。
     * @return void
     */
    public function testConnectionsSceneReturnsNormalizedWhitelist(): void
    {
        $validated = InstallRequestValidator::make([
            'configuration' => $this->validConfiguration(),
            'unexpected' => 'ignored',
        ])->validateConnections();

        self::assertSame('127.0.0.1', $validated['mysql']['host']);
        self::assertSame('hlyq_idcbm', $validated['mysql']['database']);
        self::assertSame(3306, $validated['mysql']['port']);
        self::assertSame(0, $validated['redis']['database']);
        self::assertArrayNotHasKey('system', $validated);
    }

    /**
     * 验证执行场景会规范化完整安装配置。
     * @return void
     */
    public function testExecutionSceneNormalizesConfiguration(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['system']['site_url'] = ' https://example.com/ ';
        $configuration['system']['admin_path'] = ' /Manage-Center/ ';
        $validated = InstallRequestValidator::make([
            'phase' => ' database ',
            'configuration' => $configuration,
        ])->validateExecution();

        self::assertSame('database', $validated['phase']);
        self::assertSame('https://example.com', $validated['configuration']['system']['site_url']);
        self::assertSame('manage-center', $validated['configuration']['system']['admin_path']);
    }

    /**
     * 验证非法连接配置返回按字段组织的统一错误。
     * @return void
     */
    public function testInvalidConnectionsReturnStructuredErrors(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['mysql']['port'] = 70000;
        $configuration['redis']['database'] = 256;

        try {
            InstallRequestValidator::make([
                'configuration' => $configuration,
            ])->validateConnections();
            self::fail('非法连接配置应被拒绝');
        } catch (InstallationException $exception) {
            self::assertSame(422, $exception->httpStatus());
            self::assertSame([
                'errors' => [
                    'configuration.mysql.port' => ['MySQL 端口必须在 1 至 65535 之间'],
                    'configuration.redis.database' => ['Redis 数据库编号必须在 0 至 255 之间'],
                ],
            ], $exception->data());
        }
    }

    /**
     * 验证完整安装必须同意协议并使用已知阶段。
     * @return void
     */
    public function testExecutionRequiresAgreementAndKnownPhase(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['agreement_agreed'] = false;

        try {
            InstallRequestValidator::make([
                'phase' => 'unknown',
                'configuration' => $configuration,
            ])->validateExecution();
            self::fail('未知阶段和未同意协议应被拒绝');
        } catch (InstallationException $exception) {
            $errors = $exception->data()['errors'] ?? [];

            self::assertSame(['未知的安装阶段'], $errors['phase']);
            self::assertSame(
                ['请先阅读并同意皓量云擎云业务运营系统软件使用协议'],
                $errors['configuration.agreement_agreed'],
            );
        }
    }

    /**
     * 创建合法的完整安装配置输入。
     * @return array<string, mixed> 合法安装配置
     */
    private function validConfiguration(): array
    {
        return [
            'agreement_agreed' => true,
            'mysql' => [
                'host' => ' 127.0.0.1 ',
                'port' => '3306',
                'database' => ' hlyq_idcbm ',
                'username' => ' root ',
                'password' => '',
            ],
            'redis' => [
                'host' => ' 127.0.0.1 ',
                'port' => '6379',
                'password' => '',
                'database' => '0',
            ],
            'system' => [
                'site_title' => '皓量云擎云业务运营系统',
                'site_url' => 'https://example.com',
                'admin_path' => 'manage-center',
            ],
            'admin' => [
                'username' => 'admin_user',
                'nickname' => '超级管理员',
                'password' => 'Aa1!aaaa',
            ],
        ];
    }
}
