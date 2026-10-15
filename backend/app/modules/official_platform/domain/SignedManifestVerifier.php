<?php

declare(strict_types=1);

namespace app\modules\official_platform\domain;

use app\modules\official_platform\exception\OfficialPlatformException;

/** 仅信任交付预置的Ed25519公钥，先验原始字节再读取JSON字段。 */
final readonly class SignedManifestVerifier
{
    /**
     * @param array<string,string> $trustedKeys kid对应Base64公钥
     * @param int $maximumBytes 包大小上限
     */
    public function __construct(
        private array $trustedKeys,
        private int $maximumBytes = 536870912,
        private array $trustedLicenseKeys = [],
    ) {
    }

    /**
     * @param array<string,mixed> $envelope 签名信封
     * @param string $installationId 本机身份
     * @param string $connectionId 本连接
     * @return array<string,mixed> 已验签的商业证书，期限由本机每次检查
     */
    public function certificate(array $envelope, string $installationId, string $connectionId, ?int $now = null): array
    {
        $now ??= time();
        $certificate = $this->decode($envelope, 'software-license', $installationId, $connectionId);
        // 保留 JSON 容器语义，不能把 [] 当作额度对象或把数字键对象当作 grants 数组。
        $document = json_decode(base64_decode($envelope['payload'], true), false, 32, JSON_THROW_ON_ERROR);
        if (array_diff(array_keys($certificate), [
            'schema_version', 'type', 'software_code', 'installation_id', 'connection_id', 'license_no',
            'edition', 'edition_name', 'status', 'revision', 'issued_at', 'offline_policy', 'offline_until', 'grants',
        ]) !== [] || !is_string($certificate['edition'] ?? null)
            || !preg_match('/^[a-z][a-z0-9_-]{0,15}$/D', $certificate['edition'])
            || $certificate['edition'] === 'community'
            || (array_key_exists('edition_name', $certificate) && (!is_string($certificate['edition_name'])
                || trim($certificate['edition_name']) === '' || mb_strlen($certificate['edition_name']) > 120))
            || !in_array($certificate['status'] ?? '', ['active', 'expired', 'revoked'], true)
            || !is_string($certificate['license_no'] ?? null) || strlen($certificate['license_no']) > 40
            || $certificate['license_no'] === '' || !is_int($certificate['issued_at'] ?? null)
            || !is_int($certificate['revision'] ?? null) || $certificate['revision'] < 1
            || ($certificate['offline_policy'] ?? '') !== 'online_lease'
            || !is_int($certificate['offline_until'] ?? null)
            || $certificate['issued_at'] < 1 || $certificate['issued_at'] > $now + 300
            || $certificate['offline_until'] <= $certificate['issued_at']
            || $certificate['offline_until'] > $certificate['issued_at'] + 604800
            || !is_array($certificate['grants'] ?? null) || !array_is_list($certificate['grants'])
            || !is_array($document->grants ?? null)
            || count($certificate['grants']) > 128 || array_key_exists('expires_at', $certificate)) {
            throw new OfficialPlatformException('INVALID_LICENSE_CERTIFICATE');
        }
        $seen = [];
        foreach ($certificate['grants'] as $index => $grant) {
            if (!is_array($grant) || array_diff(array_keys($grant), ['capability', 'use_until', 'updates_until', 'limits']) !== []
                || !is_string($grant['capability'] ?? null) || strlen($grant['capability']) > 96
                || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $grant['capability'])
                || isset($seen[$grant['capability']]) || !array_key_exists('use_until', $grant)
                || !array_key_exists('updates_until', $grant) || !is_array($grant['limits'] ?? null)
                || !($document->grants[$index]->limits ?? null) instanceof \stdClass
                || count($grant['limits']) > 32) {
                throw new OfficialPlatformException('INVALID_LICENSE_CERTIFICATE');
            }
            foreach (['use_until', 'updates_until'] as $field) {
                if ($grant[$field] !== null && (!is_int($grant[$field]) || $grant[$field] < 1)) {
                    throw new OfficialPlatformException('INVALID_LICENSE_CERTIFICATE');
                }
            }
            foreach ($grant['limits'] as $name => $value) {
                if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)
                    || !is_int($value) || $value < 0 || $value > 2147483647) {
                    throw new OfficialPlatformException('INVALID_LICENSE_CERTIFICATE');
                }
            }
            $seen[$grant['capability']] = true;
        }
        return $certificate;
    }

    /**
     * @param array<string,mixed> $envelope 信封
     * @param string $kind system/application
     * @param string $installationId 安装
     * @param string $connectionId 连接
     * @param string $version 实际运行版本
     * @param int|null $versionId 应用版本
     * @param string $edition 本机经验证级别
     * @return array<string,mixed> 可用于下载预检的短期发布清单
     */
    public function release(array $envelope, string $kind, string $installationId, string $connectionId, string $version, ?int $versionId = null, string $edition = 'community'): array
    {
        $release = $this->decode($envelope, $kind === 'system' ? 'software-release' : 'application-release', $installationId, $connectionId);
        if (!is_int($release['bytes'] ?? null) || $release['bytes'] < 1 || $release['bytes'] > $this->maximumBytes
            || !is_string($release['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $release['sha256'])
            || !is_string($release['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/D', $release['version'])
            || ($release['channel'] ?? '') !== 'stable' || !is_int($release['issued_at'] ?? null)
            || !is_int($release['expires_at'] ?? null) || $release['issued_at'] > time() + 300
            || $release['expires_at'] <= time() || $release['expires_at'] <= $release['issued_at']
            || $release['expires_at'] - $release['issued_at'] > 600) {
            throw new OfficialPlatformException('INVALID_RELEASE_MANIFEST');
        }
        if (!ReleaseChangelog::isValid($release['changelog'] ?? null)
            || !is_bool($release['force_update'] ?? null)) {
            throw new OfficialPlatformException('INVALID_RELEASE_MANIFEST');
        }
        if ($kind === 'system') {
            if (($release['role'] ?? '') !== 'system' || !is_int($release['first_published_at'] ?? null) || $release['first_published_at'] < 1 || $release['first_published_at'] > time() + 300
                || !is_string($release['required_capability'] ?? null) || ($release['required_capability'] !== '' && !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $release['required_capability']))
                || ($release['edition'] ?? '') !== $edition || ($release['from_version'] ?? '') !== $version
                || version_compare($release['version'], $version, '<=') || !is_int($release['package_id'] ?? null) || $release['package_id'] < 1
                || !is_int($release['version_id'] ?? null) || $release['version_id'] < 1
                || !in_array($release['platform'] ?? '', ['universal', strtolower(PHP_OS_FAMILY)], true)
                || !in_array($release['architecture'] ?? '', ['universal', php_uname('m')], true)
                || !in_array($release['package_type'] ?? '', ['full', 'incremental'], true)
                || ($release['download_path'] ?? '') !== '/api/client/v1/software-packages/'
                    . ($release['package_type'] === 'incremental' ? 'updates/' : 'installations/') . $release['package_id'] . '?' . http_build_query(['architecture' => php_uname('m'), 'current_version' => $version, 'platform' => strtolower(PHP_OS_FAMILY), 'role' => 'system'], '', '&', PHP_QUERY_RFC3986)) {
                throw new OfficialPlatformException('RELEASE_CONTEXT_MISMATCH');
            }
        } else {
            $release['min_software_version'] ??= '';
            $release['max_software_version'] ??= '';
            if (!is_int($release['application_id'] ?? null) || $release['application_id'] < 1
                || ($release['version_id'] ?? null) !== $versionId || !in_array($release['kind'] ?? '', ['plugin', 'theme'], true)
                || !is_string($release['application_code'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $release['application_code'])
                || ($release['download_path'] ?? '') !== '/api/client/v1/application-versions/' . $versionId . '/package?current_version=' . rawurlencode($version)
                || !is_array($release['permissions'] ?? null) || !array_is_list($release['permissions'])
                || !is_string($release['min_software_version'] ?? null) || !is_string($release['max_software_version'] ?? null)
                || ($release['min_software_version'] !== '' && version_compare($version, $release['min_software_version'], '<'))
                || ($release['max_software_version'] !== '' && version_compare($version, $release['max_software_version'], '>'))) {
                throw new OfficialPlatformException('RELEASE_CONTEXT_MISMATCH');
            }
            foreach ($release['permissions'] as $permission) {
                if (!is_string($permission) || strlen($permission) > 128) {
                    throw new OfficialPlatformException('INVALID_RELEASE_MANIFEST');
                }
            }
        }
        return $release;
    }

    /**
     * @param array<string,mixed> $envelope 不可信信封
     * @param string $type 业务类型
     * @param string $installationId 安装
     * @param string $connectionId 连接
     * @return array<string,mixed> 未重新序列化的验签载荷
     */
    private function decode(array $envelope, string $type, string $installationId, string $connectionId): array
    {
        foreach (['kid', 'payload', 'signature'] as $field) {
            if (!is_string($envelope[$field] ?? null)) {
                throw new OfficialPlatformException('UNTRUSTED_SIGNATURE');
            }
        }
        $keys = $type === 'software-license' ? $this->trustedLicenseKeys : $this->trustedKeys;
        $key = base64_decode($keys[$envelope['kid']] ?? '', true);
        $bytes = base64_decode($envelope['payload'], true);
        $signature = base64_decode($envelope['signature'], true);
        if (!is_string($key) || strlen($key) !== 32 || !is_string($bytes) || strlen($bytes) > 65536
            || !is_string($signature) || strlen($signature) !== 64 || !sodium_crypto_sign_verify_detached($signature, $bytes, $key)) {
            throw new OfficialPlatformException('UNTRUSTED_SIGNATURE');
        }
        try {
            $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new OfficialPlatformException('INVALID_MANIFEST', $error);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 || ($data['type'] ?? '') !== $type
            || ($data['software_code'] ?? '') !== 'howstack-bos' || ($data['installation_id'] ?? '') !== $installationId
            || ($data['connection_id'] ?? '') !== $connectionId || !InstanceUuid::isValid($connectionId)) {
            throw new OfficialPlatformException('MANIFEST_CONTEXT_MISMATCH');
        }
        return $data;
    }
}
