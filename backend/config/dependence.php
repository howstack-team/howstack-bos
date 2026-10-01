<?php

declare(strict_types=1);

use app\modules\admin\auth\contract\AdminAccessTokenServiceInterface;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository;
use app\modules\admin\auth\contract\AdminAccountRepositoryInterface;
use app\modules\admin\auth\contract\AuthenticationAuditLoggerInterface;
use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use app\modules\admin\auth\infrastructure\DatabaseAdminAccountRepository;
use app\modules\admin\auth\infrastructure\FirebaseAdminAccessTokenService;
use app\modules\admin\auth\infrastructure\MonologAuthenticationAuditLogger;
use app\modules\admin\auth\infrastructure\DatabaseLoginLogRepository;
use app\modules\admin\operation\contract\OperationLogRepositoryInterface;
use app\modules\admin\operation\infrastructure\DatabaseOperationLogRepository;
use app\modules\member\contract\MemberRepositoryInterface;
use app\modules\member\contract\MemberAuthRepositoryInterface;
use app\modules\member\contract\MemberTokenServiceInterface;
use app\modules\member\infrastructure\DatabaseMemberRepository;
use app\modules\member\infrastructure\DatabaseMemberAuthRepository;
use app\modules\member\infrastructure\FirebaseMemberTokenService;
use app\modules\member\contract\MemberCatalogRepositoryInterface;
use app\modules\member\infrastructure\DatabaseMemberCatalogRepository;
use app\modules\member\contract\RealNameAuthenticationRepositoryInterface;
use app\modules\member\infrastructure\DatabaseRealNameAuthenticationRepository;
use app\modules\order\contract\OrderRepositoryInterface;
use app\modules\order\infrastructure\DatabaseOrderRepository;
use app\modules\service_instance\contract\ServiceInstanceRepositoryInterface;
use app\modules\service_instance\infrastructure\DatabaseServiceInstanceRepository;
use app\modules\support\contract\SupportRepositoryInterface;
use app\modules\support\infrastructure\DatabaseSupportRepository;
use app\modules\content\contract\ContentRepositoryInterface;
use app\modules\content\infrastructure\DatabaseContentRepository;
use app\modules\finance\contract\FinanceRecordRepositoryInterface;
use app\modules\finance\infrastructure\DatabaseFinanceRecordRepository;
use app\modules\finance\contract\WalletLedgerRepositoryInterface;
use app\modules\finance\infrastructure\DatabaseWalletLedgerRepository;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use app\shared\contract\ClientIpResolverInterface;
use app\shared\contract\IpLocationResolverInterface;
use app\shared\infrastructure\TrustedProxyClientIpResolver;
use app\shared\infrastructure\Ip2RegionLocationResolver;
use app\modules\install\contract\ConnectionProbeInterface;
use app\modules\install\contract\EnvironmentInspectorInterface;
use app\modules\install\contract\InstallationDatabaseInterface;
use app\modules\install\contract\InstallationStateRepositoryInterface;
use app\modules\install\contract\RuntimeReloaderInterface;
use app\modules\install\infrastructure\ConnectionProbe;
use app\modules\install\infrastructure\EnvironmentInspector;
use app\modules\install\infrastructure\FileInstallationStateRepository;
use app\modules\install\infrastructure\PdoInstallationDatabase;
use app\modules\install\infrastructure\WorkermanRuntimeReloader;
use app\modules\system_update\contract\CoreMigrationRunnerInterface;
use app\modules\system_update\contract\SystemMigrationLockInterface;
use app\modules\system_update\contract\SystemUpdateRepositoryInterface;
use app\modules\system_update\infrastructure\FileSystemMigrationLock;
use app\modules\system_update\infrastructure\PdoSystemUpdateRepository;
use app\modules\system_update\infrastructure\PhinxCoreMigrationRunner;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use app\modules\system_settings\infrastructure\DatabaseSystemSettingsRepository;
use app\modules\admin\auth\contract\AdminLoginRateLimiterInterface;
use app\modules\admin\auth\infrastructure\WebmanAdminLoginRateLimiter;
use support\Log;

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    \app\shared\contract\TransactionManagerInterface::class => DI\autowire(\app\shared\infrastructure\DatabaseTransactionManager::class),
    \app\shared\contract\OutboxWriterInterface::class => DI\autowire(\app\modules\finance\infrastructure\DatabaseOutboxWriter::class),
    \app\modules\order\contract\OrderPaymentRepositoryInterface::class => DI\autowire(\app\modules\order\infrastructure\DatabaseOrderPaymentRepository::class),
    \app\modules\order\contract\OrderMemberReferenceInterface::class => DI\autowire(\app\modules\order\infrastructure\DatabaseOrderMemberReference::class),

    AdminAccessRepositoryInterface::class => DI\autowire(DatabaseAdminAccessRepository::class),
    AdminAccountRepositoryInterface::class => DI\autowire(DatabaseAdminAccountRepository::class),
    LoginLogRepositoryInterface::class => DI\autowire(DatabaseLoginLogRepository::class),
    OperationLogRepositoryInterface::class => DI\autowire(DatabaseOperationLogRepository::class),
    MemberRepositoryInterface::class => DI\autowire(DatabaseMemberRepository::class),
    MemberAuthRepositoryInterface::class => DI\autowire(DatabaseMemberAuthRepository::class),
    MemberTokenServiceInterface::class => DI\factory(static function (): FirebaseMemberTokenService {
        return new FirebaseMemberTokenService(
            (string) config('authentication.member.secret', ''),
            (string) config('authentication.member.issuer', 'hlyq-idcbm'),
            (string) config('authentication.member.audience', 'hlyq-member'),
            (int) config('authentication.member.ttl', 7200),
        );
    }),
    MemberCatalogRepositoryInterface::class => DI\autowire(DatabaseMemberCatalogRepository::class),
    RealNameAuthenticationRepositoryInterface::class => DI\autowire(DatabaseRealNameAuthenticationRepository::class),
    OrderRepositoryInterface::class => DI\autowire(DatabaseOrderRepository::class),
    ServiceInstanceRepositoryInterface::class => DI\autowire(DatabaseServiceInstanceRepository::class),
    SupportRepositoryInterface::class => DI\autowire(DatabaseSupportRepository::class),
    ContentRepositoryInterface::class => DI\autowire(DatabaseContentRepository::class),
    FinanceRecordRepositoryInterface::class => DI\autowire(DatabaseFinanceRecordRepository::class),
    WalletLedgerRepositoryInterface::class => DI\autowire(DatabaseWalletLedgerRepository::class),
    SensitiveDataCipherInterface::class => DI\factory(static fn (): SodiumSensitiveDataCipher => new SodiumSensitiveDataCipher((string) getenv('APP_KEY'))),
    SystemSettingsRepositoryInterface::class => DI\autowire(DatabaseSystemSettingsRepository::class),
    AdminLoginRateLimiterInterface::class => DI\factory(static function (): WebmanAdminLoginRateLimiter {
        $identity = implode('|', [
            (string) config('authentication.admin.issuer'),
            (string) config('database.connections.mysql.database'),
            (string) config('database.connections.mysql.prefix'),
        ]);
        return new WebmanAdminLoginRateLimiter('hlyq-admin-login:' . hash('sha256', $identity));
    }),
    ClientIpResolverInterface::class => DI\factory(static function (): TrustedProxyClientIpResolver {
        return new TrustedProxyClientIpResolver((array) config('client_ip.trusted_proxies', []));
    }),
    IpLocationResolverInterface::class => DI\factory(static function (): Ip2RegionLocationResolver {
        $configured = (string) config('client_ip.ipv6_database', '');
        $ipv6 = $configured !== '' ? $configured : base_path() . '/vendor/bin/ip2data/ip2region_v6.xdb';
        return new Ip2RegionLocationResolver(new \Ip2Region('file', null, $ipv6), $ipv6);
    }),
    AdminAccessTokenServiceInterface::class => DI\factory(static function (GetSystemSettingsService $settings): FirebaseAdminAccessTokenService {
        return new FirebaseAdminAccessTokenService(
            (string) config('authentication.admin.secret', ''),
            (string) config('authentication.admin.issuer', 'hlyq-idcbm'),
            (string) config('authentication.admin.audience', 'hlyq-admin'),
            (int) config('authentication.admin.ttl', 7200),
            $settings,
        );
    }),
    AuthenticationAuditLoggerInterface::class => DI\factory(static function (): MonologAuthenticationAuditLogger {
        return new MonologAuthenticationAuditLogger(Log::channel('audit'));
    }),
    EnvironmentInspectorInterface::class => DI\autowire(EnvironmentInspector::class),
    ConnectionProbeInterface::class => DI\autowire(ConnectionProbe::class),
    InstallationDatabaseInterface::class => DI\autowire(PdoInstallationDatabase::class),
    InstallationStateRepositoryInterface::class => DI\autowire(FileInstallationStateRepository::class),
    RuntimeReloaderInterface::class => DI\autowire(WorkermanRuntimeReloader::class),
    CoreMigrationRunnerInterface::class => DI\autowire(PhinxCoreMigrationRunner::class),
    SystemMigrationLockInterface::class => DI\autowire(FileSystemMigrationLock::class),
    SystemUpdateRepositoryInterface::class => DI\autowire(PdoSystemUpdateRepository::class),
];
