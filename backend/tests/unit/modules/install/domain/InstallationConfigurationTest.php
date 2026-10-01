<?php

declare(strict_types=1);

namespace tests\unit\modules\install\domain;

use app\modules\install\domain\InstallationConfiguration;
use app\modules\install\exception\InstallationException;
use PHPUnit\Framework\TestCase;

/**
 * 验证系统安装配置的规范化与校验规则。
 */
final class InstallationConfigurationTest extends TestCase
{
    /**
     * 验证合法安装配置会被正确规范化。
     * @return void
     */
    public function testValidConfigurationIsNormalized(): void
    {
        $configuration = InstallationConfiguration::fromArray($this->validInput());

        self::assertSame('manage-center', $configuration->adminPath);
        self::assertSame('https://example.com', $configuration->siteUrl);
        self::assertSame('hlyq_', $configuration->database->tablePrefix);
        self::assertSame('', $configuration->database->redisUsername);
        self::assertSame('皓量云擎云业务运营系统', $configuration->siteTitle);
        self::assertSame('', $configuration->siteDescription);
        self::assertSame('超级管理员', $configuration->adminNickname);
        $environment = $configuration->environment('app-key', 'jwt-secret', false);
        self::assertSame('true', $environment['SESSION_SECURE']);
        self::assertSame('jwt-secret', $environment['JWT_SECRET']);
        self::assertSame(7200, $environment['JWT_TTL']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $configuration->databaseFingerprint());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $configuration->fingerprint());
    }

    /**
     * 验证未同意软件使用协议时拒绝创建配置。
     * @return void
     */
    public function testSoftwareAgreementMustBeAccepted(): void
    {
        $input = $this->validInput();
        $input['agreement_agreed'] = false;

        $this->expectException(InstallationException::class);
        $this->expectExceptionMessage('请先阅读并同意');

        InstallationConfiguration::fromArray($input);
    }

    /**
     * 验证系统保留路径不能作为后台路径。
     * @return void
     */
    public function testReservedAdminPathIsRejected(): void
    {
        $input = $this->validInput();
        $input['system']['admin_path'] = 'install';

        $this->expectException(InstallationException::class);
        $this->expectExceptionMessage('系统保留路径');

        InstallationConfiguration::fromArray($input);
    }

    /**
     * 验证弱管理员密码会被拒绝。
     * @return void
     */
    public function testWeakAdministratorPasswordIsRejected(): void
    {
        $input = $this->validInput();
        $input['admin']['password'] = 'only-lowercase-password';

        $this->expectException(InstallationException::class);
        $this->expectExceptionMessage('管理员密码必须同时包含');

        InstallationConfiguration::fromArray($input);
    }

    /**
     * 验证管理员密码不得少于八位。
     * @return void
     */
    public function testAdministratorPasswordShorterThanEightCharactersIsRejected(): void
    {
        $input = $this->validInput();
        $input['admin']['password'] = 'Aa1!abc';

        $this->expectException(InstallationException::class);
        $this->expectExceptionMessage('8 至 128');

        InstallationConfiguration::fromArray($input);
    }

    /**
     * 验证管理员账号长度及首字符规则。
     * @return void
     */
    public function testAdministratorUsernameMustStartWithLetterAndContainAtLeastSixCharacters(): void
    {
        $input = $this->validInput();
        $input['admin']['username'] = '1abcd5';

        $this->expectException(InstallationException::class);
        $this->expectExceptionMessage('以英文字母开头');

        InstallationConfiguration::fromArray($input);
    }

    /**
     * 验证安装器忽略自定义表前缀和 Redis ACL 用户名。
     * @return void
     */
    public function testInstallerIgnoresCustomTablePrefixAndRedisAclUsername(): void
    {
        $input = $this->validInput();
        $input['mysql']['prefix'] = 'custom_';
        $input['redis']['username'] = 'acl-user';

        $configuration = InstallationConfiguration::fromArray($input);

        self::assertSame('hlyq_', $configuration->database->tablePrefix);
        self::assertSame('', $configuration->database->redisUsername);
    }

    /**
     * 创建一份合法的安装配置输入。
     * @return array<string, mixed> 合法安装配置输入
     */
    private function validInput(): array
    {
        return [
            'agreement_agreed' => true,
            'mysql' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'hlyq_idcbm',
                'username' => 'root',
                'password' => 'database-password',
            ],
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => '',
                'database' => 0,
            ],
            'system' => [
                'site_title' => '皓量云擎云业务运营系统',
                'site_url' => 'https://example.com/',
                'admin_path' => '/Manage-Center/',
            ],
            'admin' => [
                'username' => 'admin_user',
                'nickname' => '超级管理员',
                'password' => 'Aa1!aaaa',
            ],
        ];
    }
}
