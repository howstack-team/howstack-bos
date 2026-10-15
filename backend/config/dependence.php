<?php

declare(strict_types=1);

use app\modules\api_documentation\contract\DocumentationArtifactInterface;
use app\modules\api_documentation\infrastructure\FileDocumentationArtifacts;

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
use app\modules\system_update\application\GetSystemVersionService;
use app\modules\admin\access\application\AdminAccessControlService;
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
    \app\modules\file\contract\FileRegistryInterface::class => DI\autowire(\app\modules\file\infrastructure\DatabaseFileRegistry::class),
    \app\modules\website\contract\SiteThemeRepositoryInterface::class => DI\autowire(\app\modules\website\infrastructure\DatabaseSiteThemeRepository::class),
    \app\modules\website\contract\WebsiteRepositoryInterface::class => DI\autowire(\app\modules\website\infrastructure\DatabaseWebsiteRepository::class),
    \app\modules\website\contract\WebsiteImageRepositoryInterface::class => DI\autowire(\app\modules\website\infrastructure\DatabaseWebsiteImageRepository::class),
    \app\modules\website\contract\WebsitePathPolicyInterface::class => DI\autowire(\app\modules\website\infrastructure\HostWebsitePathPolicy::class),
    \app\modules\website\contract\ThemeRegistryInterface::class => DI\factory(static fn () => new \app\modules\website\infrastructure\FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), (string) config('version.current'))),
    \app\modules\website\contract\WebsiteRendererInterface::class => DI\factory(static fn () => new \app\modules\website\infrastructure\TwigWebsiteRenderer(runtime_path('twig-website'))),
    \app\modules\website\contract\ThemePackageInstallerInterface::class => DI\factory(static fn () => new \app\modules\website\infrastructure\ZipThemePackageInstaller(runtime_path('website-theme-install'), (string) config('version.current'))),
    \app\modules\content\contract\PublicNewsRepositoryInterface::class => DI\autowire(\app\modules\content\infrastructure\DatabasePublicNewsRepository::class),

    DocumentationArtifactInterface::class => DI\autowire(FileDocumentationArtifacts::class),
    \app\modules\member\contract\MemberRegistrationRepositoryInterface::class => DI\autowire(\app\modules\member\infrastructure\DatabaseMemberRegistrationRepository::class),
    \app\modules\member\contract\RegistrationRateLimiterInterface::class => DI\autowire(\app\modules\member\infrastructure\RedisRegistrationRateLimiter::class),
    \app\modules\member\application\SendRegistrationCodeService::class => DI\autowire()->constructorParameter('secret', (string) env('APP_KEY', '')),

    \app\modules\product\contract\ProductPurchaseInputInterface::class => DI\autowire(\app\modules\product\infrastructure\ProductPurchaseInputRegistry::class),
    \app\modules\order\contract\OrderManagementRepositoryInterface::class => DI\autowire(\app\modules\order\infrastructure\DatabaseOrderManagementRepository::class),
    \app\modules\product\contract\OrderSalesCatalogInterface::class => DI\autowire(\app\modules\product\infrastructure\DatabaseOrderSalesCatalog::class),
    \app\modules\product\contract\ProductPurchaseAvailabilityInterface::class => DI\autowire(\app\modules\product\infrastructure\ProductPurchaseAvailabilityRegistry::class),
    \app\modules\order\contract\MemberOrderRepositoryInterface::class => DI\autowire(\app\modules\order\infrastructure\DatabaseMemberOrderRepository::class),
    \app\modules\product\contract\ProductOrderCapacityInterface::class => DI\autowire(\app\modules\product\infrastructure\ProductOrderCapacityRegistry::class),

    \app\modules\compute\contract\ComputeInventoryRepositoryInterface::class => \DI\autowire(\app\modules\compute\infrastructure\DatabaseComputeInventoryRepository::class),
    \app\modules\official_platform\contract\InstallationIdentityInterface::class => DI\factory(
        static fn (): \app\modules\official_platform\infrastructure\FileInstallationIdentity => new \app\modules\official_platform\infrastructure\FileInstallationIdentity(
            (string) config('official_platform.private_directory'),
        ),
    ),
    \app\modules\official_platform\contract\OfficialPlatformStateStoreInterface::class => DI\factory(
        static fn (SensitiveDataCipherInterface $cipher): \app\modules\official_platform\infrastructure\DatabaseOfficialPlatformStateStore => new \app\modules\official_platform\infrastructure\DatabaseOfficialPlatformStateStore(
            $cipher,
            (string) config('official_platform.private_directory'),
        ),
    ),
    \app\modules\official_platform\contract\OfficialPlatformClientInterface::class => DI\factory(
        static fn (
            \GuzzleHttp\ClientInterface $http,
            \app\modules\official_platform\contract\OfficialPlatformStateStoreInterface $store,
            \app\modules\official_platform\contract\InstallationIdentityInterface $identity,
        ): \app\modules\official_platform\infrastructure\HlyqOfficialPlatformClient => new \app\modules\official_platform\infrastructure\HlyqOfficialPlatformClient(
            $http,
            $store,
            $identity,
            (array) config('official_platform'),
            (string) config('version.current'),
        ),
    ),
    \app\modules\official_platform\domain\SignedManifestVerifier::class => DI\factory(
        static fn (): \app\modules\official_platform\domain\SignedManifestVerifier => new \app\modules\official_platform\domain\SignedManifestVerifier(
            (array) config('official_platform.trusted_release_keys'),
            (int) config('official_platform.maximum_package_bytes'),
            (array) config('official_platform.trusted_license_keys'),
        ),
    ),
    \app\modules\software_maintenance\contract\InstallationJobRepositoryInterface::class => DI\autowire(\app\modules\software_maintenance\infrastructure\DatabaseInstallationJobRepository::class),
    \app\modules\software_maintenance\contract\InstallationLockInterface::class => DI\factory(
        static fn (): \app\modules\software_maintenance\infrastructure\FileInstallationLock => new \app\modules\software_maintenance\infrastructure\FileInstallationLock(
            (string) config('official_platform.private_directory'),
        ),
    ),
    \app\modules\licensing\contract\LicenseClockInterface::class => DI\autowire(\app\modules\licensing\infrastructure\SystemLicenseClock::class),
    \app\modules\licensing\domain\CapabilityCatalog::class => DI\factory(static fn (): \app\modules\licensing\domain\CapabilityCatalog => new \app\modules\licensing\domain\CapabilityCatalog()),
    \app\modules\licensing\application\ManageLicenseService::class => DI\autowire(),
    \app\shared\contract\CommercialFeatureGateInterface::class => DI\get(\app\modules\licensing\application\ManageLicenseService::class),
    \app\modules\software_maintenance\application\ManageSoftwareUpdatesService::class => DI\autowire()
        ->constructorParameter('version', DI\factory(static fn (): string => (string) config('version.current')))
        ->constructorParameter('checkIntervalSeconds', DI\factory(static fn (): int => (int) config('official_platform.update_check_interval_seconds', 43200))),
    \app\modules\application_center\application\ManageApplicationCenterService::class => DI\autowire()->constructorParameter('version', DI\factory(static fn (): string => (string) config('version.current'))),
    \app\modules\software_maintenance\application\RunInstallationJobService::class => DI\autowire()
        ->constructorParameter('private', DI\factory(static fn (): string => (string) config('official_platform.private_directory')))
        ->constructorParameter('version', DI\factory(static fn (): string => (string) config('version.current'))),
    \app\modules\software_maintenance\contract\ReleaseInstallerInterface::class => DI\autowire(\app\modules\software_maintenance\infrastructure\LocalReleaseInstaller::class)
        ->constructorParameter('root', DI\factory(static fn (): string => base_path()))
        ->constructorParameter('private', DI\factory(static fn (): string => (string) config('official_platform.private_directory')))
        ->constructorParameter('version', DI\factory(static fn (): string => (string) config('version.current')))
        ->constructorParameter('database', DI\factory(
            static fn (): \app\modules\system_update\domain\MigrationDatabaseConfiguration => \app\modules\system_update\domain\MigrationDatabaseConfiguration::fromArray(
                (array) config('database.connections.mysql', []),
            ),
        )),

    \app\modules\compute\contract\ComputeResourceRepositoryInterface::class => DI\autowire(\app\modules\compute\infrastructure\DatabaseComputeResourceRepository::class),
    \app\modules\product\contract\ProductTypeRegistryInterface::class => DI\autowire(\app\modules\product\infrastructure\ProductTypeRegistry::class),
    \app\modules\product\contract\ProductRepositoryInterface::class => DI\autowire(\app\modules\product\infrastructure\DatabaseProductRepository::class),
    \app\modules\product\contract\CloudDeliveryRuleRepositoryInterface::class => DI\autowire(\app\modules\product\infrastructure\DatabaseCloudDeliveryRuleRepository::class),
    \app\modules\provider\contract\ProviderRepositoryInterface::class => DI\autowire(\app\modules\provider\infrastructure\DatabaseProviderRepository::class),
    \app\modules\plugin\contract\PluginPackageRegistryInterface::class => DI\factory(
        static fn (): \app\modules\plugin\infrastructure\LocalPluginPackageRegistry => new \app\modules\plugin\infrastructure\LocalPluginPackageRegistry(
            base_path('plugins'),
            (string) config('version.current'),
        ),
    ),
    \app\modules\plugin\contract\PluginInstallationRepositoryInterface::class => DI\factory(
        static fn (\app\shared\contract\TransactionManagerInterface $transactions): \app\modules\plugin\infrastructure\DatabasePluginInstallationRepository => new \app\modules\plugin\infrastructure\DatabasePluginInstallationRepository(
            $transactions,
            [
                new \app\modules\provider\infrastructure\DatabaseProviderPluginUsageInspector(),
                new \app\modules\channel\infrastructure\DatabaseChannelPluginUsageInspector(),
                new \app\modules\finance\infrastructure\OnlinePaymentPluginUsageInspector(),
            ],
        ),
    ),
    \app\modules\provider\contract\ProviderAdapterRegistryInterface::class => DI\factory(
        static fn (
            \GuzzleHttp\ClientInterface $http,
            \app\modules\plugin\contract\PluginInstallationRepositoryInterface $installations,
            \app\modules\plugin\contract\PluginPackageRegistryInterface $packages,
        ): \app\modules\provider\infrastructure\LocalProviderAdapterRegistry => new \app\modules\provider\infrastructure\LocalProviderAdapterRegistry(
            base_path('plugins'),
            (string) config('version.current'),
            $http,
            $installations,
            $packages,
        ),
    ),
    \app\modules\provider\contract\ProviderBindingResolverInterface::class => DI\autowire(\app\modules\provider\application\ResolveProviderBindingService::class),
    \app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface::class => DI\autowire(\app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository::class),
    \app\modules\system_settings\contract\NotificationRobotSettingsRepositoryInterface::class => DI\autowire(\app\modules\system_settings\infrastructure\DatabaseNotificationRobotSettingsRepository::class),
    \app\modules\channel\contract\ChannelRepositoryInterface::class => DI\autowire(\app\modules\channel\infrastructure\DatabaseChannelRepository::class),
    \app\modules\channel\contract\ChannelPlatformRegistryInterface::class => DI\autowire(\app\modules\channel\infrastructure\InstalledChannelPlatformRegistry::class),
    \app\modules\channel\contract\mail\MailDeliveryRepositoryInterface::class => DI\autowire(\app\modules\channel\infrastructure\mail\DatabaseMailDeliveryRepository::class),
    \app\modules\channel\contract\mail\MailAdapterExecutorInterface::class => DI\autowire(\app\modules\channel\infrastructure\mail\InstalledMailAdapterExecutor::class),
    \app\modules\channel\infrastructure\storage\PrivateLocalObjectStore::class => DI\factory(
        static fn (): \app\modules\channel\infrastructure\storage\PrivateLocalObjectStore => new \app\modules\channel\infrastructure\storage\PrivateLocalObjectStore(
            (string) config('storage.private_root'), (string) getenv('APP_KEY'),
        ),
    ),
    \app\shared\plugin\contract\LocalObjectStoreInterface::class => DI\get(\app\modules\channel\infrastructure\storage\PrivateLocalObjectStore::class),
    \app\modules\channel\contract\storage\LocalObjectDownloadInterface::class => DI\get(\app\modules\channel\infrastructure\storage\PrivateLocalObjectStore::class),
    \app\modules\channel\contract\oauth\OAuthStateStoreInterface::class => DI\autowire(\app\modules\channel\infrastructure\oauth\RedisOAuthStateStore::class),
    \app\modules\channel\contract\captcha\CaptchaAttemptStoreInterface::class => DI\autowire(\app\modules\channel\infrastructure\captcha\RedisCaptchaAttemptStore::class),
    \app\shared\plugin\contract\ImageCaptchaInterface::class => DI\factory(
        static fn (): \app\modules\channel\infrastructure\captcha\WebmanImageCaptcha => new \app\modules\channel\infrastructure\captcha\WebmanImageCaptcha((string) getenv('APP_KEY')),
    ),
    \app\modules\channel\contract\TransactionAdapterFactoryInterface::class => DI\factory(
        static fn (
            \app\modules\plugin\contract\PluginPackageRegistryInterface $packages,
            \app\modules\channel\contract\ChannelPlatformRegistryInterface $platforms,
            \GuzzleHttp\ClientInterface $http,
            \app\shared\plugin\contract\LocalObjectStoreInterface $localStorage,
            \app\shared\plugin\contract\ImageCaptchaInterface $imageCaptcha,
            \app\modules\plugin\contract\PluginInstallationRepositoryInterface $installations,
        ): \app\modules\channel\infrastructure\InstalledTransactionAdapterFactory => new \app\modules\channel\infrastructure\InstalledTransactionAdapterFactory(
            $packages, $platforms, $http, $localStorage, $imageCaptcha, $installations,
        ),
    ),
    \app\modules\provider\contract\ProviderProvisioningPreviewInterface::class => DI\autowire(\app\modules\provider\domain\ProviderProvisioningPreview::class),
    \app\modules\provider\contract\ProviderBindingWriterInterface::class => DI\autowire(\app\modules\provider\infrastructure\DatabaseProviderBindingWriter::class),
    \GuzzleHttp\ClientInterface::class => DI\factory(static fn (): \GuzzleHttp\Client => new \GuzzleHttp\Client()),
    \app\shared\contract\TransactionManagerInterface::class => DI\autowire(\app\shared\infrastructure\DatabaseTransactionManager::class),
    \app\shared\contract\OutboxWriterInterface::class => DI\autowire(\app\modules\finance\infrastructure\DatabaseOutboxWriter::class),
    \app\modules\order\contract\OrderPaymentRepositoryInterface::class => DI\autowire(\app\modules\order\infrastructure\DatabaseOrderPaymentRepository::class),
    \app\modules\order\contract\OrderMemberReferenceInterface::class => DI\autowire(\app\modules\order\infrastructure\DatabaseOrderMemberReference::class),

    AdminAccessRepositoryInterface::class => DI\autowire(DatabaseAdminAccessRepository::class),
    \app\modules\admin\auth\contract\AdminMfaRepositoryInterface::class => DI\autowire(\app\modules\admin\auth\infrastructure\DatabaseAdminMfaRepository::class),
    \app\modules\admin\auth\contract\TotpInterface::class => DI\autowire(\app\modules\admin\auth\infrastructure\OtphpTotp::class),
    \app\modules\admin\auth\application\LoginAdminService::class => DI\autowire()->constructorParameter('mfa', DI\get(\app\modules\admin\auth\application\AdminMfaService::class)),
    AdminAccountRepositoryInterface::class => DI\autowire(DatabaseAdminAccountRepository::class),
    \app\modules\admin\profile\contract\AdminProfileRepositoryInterface::class => DI\autowire(\app\modules\admin\profile\infrastructure\DatabaseAdminProfileRepository::class),
    LoginLogRepositoryInterface::class => DI\autowire(DatabaseLoginLogRepository::class),
    OperationLogRepositoryInterface::class => DI\autowire(DatabaseOperationLogRepository::class),
    \app\modules\member\contract\MemberOAuthRepositoryInterface::class => DI\autowire(\app\modules\member\infrastructure\DatabaseMemberOAuthRepository::class),
    MemberRepositoryInterface::class => DI\autowire(DatabaseMemberRepository::class),
    \app\modules\member\contract\MemberSessionRepositoryInterface::class => DI\autowire(\app\modules\member\infrastructure\DatabaseMemberSessionRepository::class),
    \app\modules\member\contract\MemberAuthChallengeRepositoryInterface::class => DI\autowire(\app\modules\member\infrastructure\DatabaseMemberAuthChallengeRepository::class),
    \app\modules\member\application\MemberCodeAuthenticationService::class => DI\autowire()->constructorParameter('secret', (string) env('APP_KEY', '')),
    MemberAuthRepositoryInterface::class => DI\autowire(DatabaseMemberAuthRepository::class),
    MemberTokenServiceInterface::class => DI\factory(static function (): FirebaseMemberTokenService {
        return new FirebaseMemberTokenService(
            (string) config('authentication.member.secret', ''),
            (string) config('authentication.member.issuer', 'hlyq-idcbm'),
            (string) config('authentication.member.audience', 'hlyq-member'),
            (int) config('authentication.member.ttl', 7200),
            \support\Container::get(\app\modules\member\contract\MemberSessionRepositoryInterface::class),
            (int) config('authentication.member.remember_ttl', 2592000),
        );
    }),
    MemberCatalogRepositoryInterface::class => DI\autowire(DatabaseMemberCatalogRepository::class),
    RealNameAuthenticationRepositoryInterface::class => DI\autowire(DatabaseRealNameAuthenticationRepository::class),
    OrderRepositoryInterface::class => DI\autowire(DatabaseOrderRepository::class),
    \app\modules\business_analysis\contract\BusinessAnalysisRepositoryInterface::class => DI\autowire(\app\modules\business_analysis\infrastructure\DatabaseBusinessAnalysisRepository::class),
    ServiceInstanceRepositoryInterface::class => DI\autowire(DatabaseServiceInstanceRepository::class),
    SupportRepositoryInterface::class => DI\autowire(DatabaseSupportRepository::class),
    \app\modules\content\contract\ContentImageRepositoryInterface::class => DI\autowire(\app\modules\content\infrastructure\DatabaseContentImageRepository::class),
    ContentRepositoryInterface::class => DI\autowire(DatabaseContentRepository::class),
    FinanceRecordRepositoryInterface::class => DI\autowire(DatabaseFinanceRecordRepository::class),
    \app\modules\finance\contract\OnlinePaymentRepositoryInterface::class => DI\autowire(\app\modules\finance\infrastructure\DatabaseOnlinePaymentRepository::class),
    \app\modules\finance\contract\OnlinePaymentGatewayInterface::class => DI\autowire(\app\modules\finance\infrastructure\ChannelOnlinePaymentGateway::class),
    WalletLedgerRepositoryInterface::class => DI\autowire(DatabaseWalletLedgerRepository::class),
    SensitiveDataCipherInterface::class => DI\factory(static fn (): SodiumSensitiveDataCipher => new SodiumSensitiveDataCipher((string) getenv('APP_KEY'))),
    SystemSettingsRepositoryInterface::class => DI\autowire(DatabaseSystemSettingsRepository::class),
    \app\modules\system_settings\contract\AdminEntryRepositoryInterface::class => DI\autowire(FileInstallationStateRepository::class),
    \app\modules\system_settings\contract\AdminEntryPathPolicyInterface::class => DI\autowire(\app\modules\system_settings\infrastructure\HostAdminEntryPathPolicy::class),
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
    \app\modules\install\contract\InstallationAuthorizationRepositoryInterface::class => DI\autowire(\app\modules\install\infrastructure\FileInstallationAuthorizationRepository::class),
    InstallationStateRepositoryInterface::class => DI\autowire(FileInstallationStateRepository::class),
    RuntimeReloaderInterface::class => DI\autowire(WorkermanRuntimeReloader::class),
    CoreMigrationRunnerInterface::class => DI\autowire(PhinxCoreMigrationRunner::class),
    SystemMigrationLockInterface::class => DI\autowire(FileSystemMigrationLock::class),
    SystemUpdateRepositoryInterface::class => DI\autowire(PdoSystemUpdateRepository::class),
    GetSystemVersionService::class => DI\factory(static fn (AdminAccessControlService $access): GetSystemVersionService => new GetSystemVersionService(
        $access,
        (string) config('version.current', ''),
    )),
    \app\modules\message\contract\MessagePublisherInterface::class => DI\autowire(\app\modules\message\application\PublishMessageService::class),
    \app\modules\channel\contract\ChannelDisplayNameInterface::class => DI\autowire(\app\modules\channel\infrastructure\DatabaseChannelDisplayName::class),
    \app\modules\message\contract\MessageRepositoryInterface::class => DI\autowire(\app\modules\message\infrastructure\DatabaseMessageRepository::class),
    \app\modules\message\contract\MessageRecipientDirectoryInterface::class => DI\autowire(\app\modules\message\infrastructure\DatabaseMessageRecipientDirectory::class),
    \app\modules\message\contract\MessageTransportInterface::class => DI\autowire(\app\modules\message\infrastructure\InstalledMessageTransport::class),
    \app\modules\message\contract\MessageQueueInterface::class => DI\autowire(\app\modules\message\infrastructure\RedisMessageQueue::class),
    \app\modules\finance\contract\FinanceWorkflowRepositoryInterface::class => DI\autowire(\app\modules\finance\infrastructure\DatabaseFinanceWorkflowRepository::class),
    \app\modules\order\contract\OrderFinanceRepositoryInterface::class => DI\autowire(\app\modules\order\infrastructure\DatabaseOrderFinanceRepository::class),
];
