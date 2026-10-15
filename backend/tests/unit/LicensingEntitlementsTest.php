<?php

declare(strict_types=1);

namespace tests\unit;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\licensing\application\ManageLicenseService;
use app\modules\licensing\contract\LicenseClockInterface;
use app\modules\licensing\domain\{CapabilityCatalog, CapabilityDefinition, LicenseEntitlementPolicy};
use app\modules\official_platform\contract\{InstallationIdentityInterface, OfficialPlatformClientInterface, OfficialPlatformStateStoreInterface};
use app\modules\official_platform\domain\SignedManifestVerifier;
use app\modules\official_platform\exception\OfficialPlatformException;
use app\shared\entitlement\{EntitlementContext, EntitlementOperation};
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 真实 Ed25519/Secretbox、固定时钟和隔离内存状态，不访问官网。 */
final class LicensingEntitlementsTest extends TestCase
{
    private const INSTANCE = '00000000-0000-4000-8000-000000000001';
    private const CONNECTION = '00000000-0000-4000-8000-000000000002';
    private const NOW = 1800000000;
    private string $pair;
    private OfficialPlatformStateStoreInterface $store;
    private LicenseClockInterface $clock;
    private InstallationIdentityInterface $identity;
    private OfficialPlatformClientInterface $client;
    private array $certificate;

    protected function setUp(): void
    {
        $this->pair = sodium_crypto_sign_keypair();
        $this->store = new class implements OfficialPlatformStateStoreInterface {
            public array $state = [];
            public function read(): array
            {
                return $this->state;
            }
            public function write(array $state): void
            {
                $this->state = $state;
            }
            public function synchronized(callable $operation): mixed
            {
                return $operation();
            }
        };
        $this->clock = new class implements LicenseClockInterface {
            public int $timestamp = 1800000000;
            public function now(): int
            {
                return $this->timestamp;
            }
        };
        $this->identity = $this->createStub(InstallationIdentityInterface::class);
        $this->identity->method('id')->willReturn(self::INSTANCE);
        $this->client = $this->createStub(OfficialPlatformClientInterface::class);
        $this->client->method('localStatus')->willReturn(['connected' => false, 'connection' => null]);
        $this->certificate = [
            'schema_version' => 1, 'type' => 'software-license', 'software_code' => 'howstack-bos',
            'installation_id' => self::INSTANCE, 'connection_id' => self::CONNECTION,
            'license_no' => 'TEST', 'edition' => 'professional', 'edition_name' => '专业版',
            'status' => 'active', 'revision' => 1, 'issued_at' => self::NOW - 1,
            'offline_policy' => 'online_lease', 'offline_until' => self::NOW + 604799,
            'grants' => [['capability' => 'fixture.advanced', 'use_until' => self::NOW + 100,
                'updates_until' => self::NOW + 200, 'limits' => ['reports' => 10]]],
        ];
    }

    private function service(?CapabilityCatalog $catalog = null): ManageLicenseService
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([]);
        $catalog ??= new CapabilityCatalog([new CapabilityDefinition('fixture.advanced', '测试高级能力',
            array_fill_keys(array_column(EntitlementOperation::cases(), 'value'), 'admin.license.view'))]);
        return new ManageLicenseService(new AdminAccessControlService($access), $this->client, $this->store, $this->identity,
            $this->verifier(), $catalog, new SodiumSensitiveDataCipher(str_repeat('fixture-private-key', 4)), $this->clock,
            new LicenseEntitlementPolicy());
    }

    private function verifier(): SignedManifestVerifier
    {
        return new SignedManifestVerifier(['fixture' => base64_encode(sodium_crypto_sign_publickey($this->pair))], 536870912, ['fixture' => base64_encode(sodium_crypto_sign_publickey($this->pair))]);
    }

    private function signed(array $certificate): array
    {
        foreach ($certificate['grants'] ?? [] as $index => $grant) {
            $certificate['grants'][$index]['limits'] = (object) $grant['limits'];
        }
        $bytes = json_encode($certificate, JSON_THROW_ON_ERROR);
        return ['kid' => 'fixture', 'payload' => base64_encode($bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, sodium_crypto_sign_secretkey($this->pair)))];
    }

    private function install(): void
    {
        $state = $this->store->read();
        $state['license_certificate'] = $this->signed($this->certificate);
        $state['certificate_connection_id'] = self::CONNECTION;
        $this->store->write($state);
    }

    public function testReleaseSigningKeyCannotSignLicense(): void
    {
        $release = sodium_crypto_sign_keypair();
        $verifier = new SignedManifestVerifier(['release' => base64_encode(sodium_crypto_sign_publickey($release))], 67108864, ['fixture' => base64_encode(sodium_crypto_sign_publickey($this->pair))]);
        $bytes = json_encode($this->certificate, JSON_THROW_ON_ERROR);
        $envelope = ['kid' => 'release', 'payload' => base64_encode($bytes), 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, sodium_crypto_sign_secretkey($release)))];
        $this->expectException(OfficialPlatformException::class);
        $verifier->certificate($envelope, self::INSTANCE, self::CONNECTION, self::NOW);
    }

    public function testLicenseSigningKeyCannotSignRelease(): void
    {
        $release = sodium_crypto_sign_keypair();
        $verifier = new SignedManifestVerifier(['release' => base64_encode(sodium_crypto_sign_publickey($release))], 67108864, ['fixture' => base64_encode(sodium_crypto_sign_publickey($this->pair))]);
        $envelope = $this->signed(array_replace($this->certificate, ['type' => 'software-release']));
        $this->expectException(OfficialPlatformException::class);
        $verifier->release($envelope, 'system', self::INSTANCE, self::CONNECTION, '0.1.0');
    }

    public function testCommunityAndProductionCatalogRemainEmpty(): void
    {
        self::assertSame('community', $this->service()->current()['status']);
        self::assertSame([], $this->service()->current()['entitlements']);
        self::assertSame('ENTITLEMENT_NOT_GRANTED', $this->service()->decide('fixture.advanced', EntitlementOperation::StartNew)->reason);
        $actor = new AdminAccount(1, 'admin', '测试', 'hash', 1, null, true);
        self::assertSame([], (array) $this->service(new CapabilityCatalog())->capabilities($actor)['capabilities']);
    }

    public function testUseExpiryKeepsEditionAndIndependentUpdateRights(): void
    {
        $this->install();
        $service = $this->service();
        self::assertTrue($service->decide('fixture.advanced', EntitlementOperation::StartNew)->allowed);
        $this->clock->timestamp = self::NOW + 100;
        self::assertSame('expired', $service->current()['status']);
        self::assertSame('professional', $service->current()['edition']);
        self::assertSame('ENTITLEMENT_EXPIRED', $service->decide('fixture.advanced', EntitlementOperation::StartNew)->reason);
        self::assertTrue($service->decide('fixture.advanced', EntitlementOperation::DownloadUpdate,
            new EntitlementContext(releasePublishedAt: self::NOW + 150))->allowed);
        self::assertSame('UPDATE_ENTITLEMENT_EXPIRED', $service->decide('fixture.advanced', EntitlementOperation::DownloadUpdate,
            new EntitlementContext(releasePublishedAt: self::NOW + 201))->reason);
    }

    public function testSignedGrantsAreIndependentOfEditionAndUnknownFeaturesStayClosed(): void
    {
        $this->certificate['edition'] = 'custom';
        $this->certificate['grants'][] = ['capability' => 'not.implemented', 'use_until' => null, 'updates_until' => null, 'limits' => []];
        $this->install();
        self::assertTrue($this->service()->decide('fixture.advanced', EntitlementOperation::StartNew)->allowed);
        self::assertSame('CAPABILITY_NOT_IMPLEMENTED', $this->service()->decide('not.implemented', EntitlementOperation::StartNew)->reason);
        self::assertFalse($this->service()->current()['entitlements'][1]['implemented']);
        $this->certificate['revision'] = 2;
        $this->certificate['grants'][0]['use_until'] = null;
        $this->install();
        self::assertNull($this->service()->current()['entitlements'][0]['use_until']);
    }

    public function testLeaseEndBlocksNewWorkAndKeepsPrivateBusinessMarker(): void
    {
        $this->certificate['grants'][0]['use_until'] = null;
        $this->install();
        $state = $this->store->read();
        $state['business_marker'] = 'preserve';
        $this->store->write($state);
        $this->clock->timestamp = self::NOW + 604799;
        self::assertSame('offline_expired', $this->service()->current()['status']);
        self::assertSame('OFFLINE_VERIFICATION_EXPIRED', $this->service()->decide('fixture.advanced', EntitlementOperation::StartNew)->reason);
        self::assertSame('preserve', $this->store->read()['business_marker']);
    }

    public function testProofContinuesOriginalScopeAfterExpiryRevocationAndCertificateDamage(): void
    {
        $this->install();
        $service = $this->service();
        $hash = hash('sha256', 'scope');
        $proof = $service->createContinuationProof('fixture.advanced', new EntitlementContext('report:1', $hash));
        $context = new EntitlementContext('report:1', $hash, $proof);
        self::assertStringNotContainsString('TEST', $proof);
        self::assertStringNotContainsString($proof, json_encode($context, JSON_THROW_ON_ERROR));
        $this->clock->timestamp = self::NOW + 100;
        self::assertTrue($service->decide('fixture.advanced', EntitlementOperation::CompleteExisting, $context)->allowed);
        self::assertTrue($service->decide('fixture.advanced', EntitlementOperation::ReadExisting, $context)->allowed);
        self::assertFalse($service->decide('fixture.advanced', EntitlementOperation::StartNew, $context)->allowed);
        $this->certificate['revision'] = 2;
        $this->certificate['status'] = 'revoked';
        $this->install();
        self::assertTrue($service->decide('fixture.advanced', EntitlementOperation::MaintainExisting, $context)->allowed);
        self::assertSame('LICENSE_REVOKED', $service->decide('fixture.advanced', EntitlementOperation::Configure)->reason);
        foreach ([new EntitlementContext('report:2', $hash, $proof),
            new EntitlementContext('report:1', hash('sha256', 'expanded'), $proof),
            new EntitlementContext('report:1', $hash, substr($proof, 0, -5) . 'bad=='),
            new EntitlementContext()] as $invalid) {
            self::assertFalse($service->decide('fixture.advanced', EntitlementOperation::CompleteExisting, $invalid)->allowed);
        }
        $state = $this->store->read();
        $state['license_certificate']['signature'] = base64_encode(str_repeat('x', 64));
        $this->store->write($state);
        self::assertTrue($service->decide('fixture.advanced', EntitlementOperation::CompleteExisting, $context)->allowed);
        self::assertSame('unverified', $service->current()['status']);
    }

    public function testExpiredRightsCannotIssueNewProof(): void
    {
        $this->install();
        $this->clock->timestamp = self::NOW + 100;
        $this->expectException(BusinessException::class);
        $this->service()->createContinuationProof('fixture.advanced', new EntitlementContext('report:1', hash('sha256', 'scope')));
    }

    public function testClockRollbackRevisionReplayAndEqualRevisionConflictAreRejected(): void
    {
        $this->install();
        $service = $this->service();
        self::assertTrue($service->decide('fixture.advanced', EntitlementOperation::StartNew)->allowed);
        $this->clock->timestamp = self::NOW - 301;
        self::assertSame('clock_error', $service->current()['status']);
        $this->clock->timestamp = self::NOW;
        $this->certificate['grants'][0]['use_until'] = null;
        $this->install();
        self::assertSame('LICENSE_REPLAY_REJECTED', $service->current()['last_error']);
        $this->certificate['revision'] = 2;
        $this->certificate['status'] = 'revoked';
        $this->install();
        self::assertSame('revoked', $service->current()['status']);
        $this->certificate['revision'] = 1;
        $this->certificate['status'] = 'active';
        $this->install();
        self::assertSame('LICENSE_REPLAY_REJECTED', $service->current()['last_error']);
        self::assertFalse($service->decide('fixture.advanced', EntitlementOperation::StartNew)->allowed);
    }

    public function testModuleAndRbacRemainIndependent(): void
    {
        $this->install();
        $catalog = new CapabilityCatalog([new CapabilityDefinition('fixture.advanced', '测试', ['start_new' => 'admin.license.view'], false)]);
        self::assertSame('MODULE_NOT_AVAILABLE', $this->service($catalog)->decide('fixture.advanced', EntitlementOperation::StartNew)->reason);
        $actor = new AdminAccount(2, 'viewer', '测试', 'hash', 1, null, false);
        $items = (array) $this->service()->capabilities($actor)['capabilities'];
        self::assertFalse(((array) $items['fixture.advanced']['operations'])['start_new']);
        self::assertSame('PERMISSION_DENIED', ((array) $items['fixture.advanced']['reasons'])['start_new']);
        self::assertArrayNotHasKey('license_no', $items);
    }

    public function testRefreshRetainsRevocationWhenOldSignedCertificateIsReplayed(): void
    {
        $connection = ['connection_id' => self::CONNECTION, 'connection_mode' => 'licensed',
            'license_no' => 'TEST', 'edition_code' => 'professional'];
        $revoked = $this->certificate;
        $revoked['revision'] = 3;
        $revoked['status'] = 'revoked';
        $this->client = $this->createStub(OfficialPlatformClientInterface::class);
        $this->client->method('localStatus')->willReturn(['connected' => true, 'connection' => $connection]);
        $this->client->method('synchronize')->willReturn($connection);
        $this->client->method('certificate')->willReturnOnConsecutiveCalls($this->signed($revoked), $this->signed($this->certificate));
        $this->store->write(['connection' => $connection]);
        $actor = new AdminAccount(1, 'admin', '测试', 'hash', 1, null, true);
        $service = $this->service();
        self::assertSame('revoked', $service->refresh($actor)['status']);
        try {
            $service->refresh($actor);
            self::fail('旧证书不能覆盖已签名撤销');
        } catch (OfficialPlatformException $error) {
            self::assertSame('LICENSE_REPLAY_REJECTED', $error->reason);
        }
        self::assertSame('revoked', $service->current()['status']);
        self::assertSame(3, $service->current()['entitlement_revision']);
    }

    public function testUpdateOperationsRequireSignedReleaseContextAndDoNotInferPermanentRights(): void
    {
        $this->install();
        $service = $this->service();
        self::assertSame('RELEASE_CONTEXT_REQUIRED', $service->decide('fixture.advanced', EntitlementOperation::DownloadUpdate)->reason);
        self::assertSame(['reports' => 10], $service->decide('fixture.advanced', EntitlementOperation::StartNew)->limits);
        $this->expectException(BusinessException::class);
        $service->createContinuationProof('fixture.advanced', new EntitlementContext());
    }

    public function testAcceptedProofUsesTheSameIssuerClockToleranceAsAuthorization(): void
    {
        $this->certificate['issued_at'] = self::NOW + 100;
        $this->certificate['offline_until'] = self::NOW + 3600;
        $this->install();
        $service = $this->service();
        $hash = hash('sha256', 'scope');
        $proof = $service->createContinuationProof('fixture.advanced', new EntitlementContext('report:1', $hash));
        self::assertTrue($service->decide('fixture.advanced', EntitlementOperation::CompleteExisting,
            new EntitlementContext('report:1', $hash, $proof))->allowed);
    }

    #[DataProvider('invalidCertificates')]
    public function testMalformedCertificatesFailClosed(string $mutation): void
    {
        switch ($mutation) {
            case 'legacy':
                $this->certificate['expires_at'] = self::NOW + 500;
                break;
            case 'revision':
                $this->certificate['revision'] = 0;
                break;
            case 'lease':
                $this->certificate['offline_until'] = self::NOW + 604800;
                break;
            case 'offline':
                $this->certificate['offline_policy'] = 'permanent';
                break;
            case 'duplicate':
                $this->certificate['grants'][] = $this->certificate['grants'][0];
                break;
            case 'missing_use':
                unset($this->certificate['grants'][0]['use_until']);
                break;
            case 'float':
                $this->certificate['grants'][0]['limits']['reports'] = 1.5;
                break;
            case 'negative':
                $this->certificate['grants'][0]['limits']['reports'] = -1;
                break;
            case 'unknown_field':
                $this->certificate['grants'][0]['allow_expired'] = true;
                break;
            case 'bad_capability':
                $this->certificate['grants'][0]['capability'] = '../../escape';
                break;
            case 'null_name':
                $this->certificate['edition_name'] = null;
                break;
        }
        $this->expectException(OfficialPlatformException::class);
        $this->verifier()->certificate($this->signed($this->certificate), self::INSTANCE, self::CONNECTION, self::NOW);
    }

    public static function invalidCertificates(): array
    {
        return array_map(static fn (string $name): array => [$name], [
            'legacy', 'revision', 'lease', 'offline', 'duplicate', 'missing_use', 'float', 'negative', 'unknown_field', 'bad_capability', 'null_name',
        ]);
    }
}
