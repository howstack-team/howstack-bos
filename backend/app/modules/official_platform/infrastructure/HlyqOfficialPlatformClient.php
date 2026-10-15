<?php

declare(strict_types=1);

namespace app\modules\official_platform\infrastructure;

use app\modules\official_platform\contract\{InstallationIdentityInterface, OfficialPlatformClientInterface, OfficialPlatformStateStoreInterface};
use app\modules\official_platform\domain\ClientRequestProof;
use app\modules\official_platform\exception\OfficialPlatformException;
use GuzzleHttp\ClientInterface;
use app\modules\official_platform\domain\InstanceUuid;
use Throwable;

/** 官网v1客户端；每次调用读取私有状态，不在常驻实例缓存连接秘密。 */
final readonly class HlyqOfficialPlatformClient implements OfficialPlatformClientInterface
{
    private const string SOFTWARE = 'howstack-bos';

    /**
     * @param ClientInterface $http 宿主HTTP
     * @param OfficialPlatformStateStoreInterface $store 私有状态
     * @param InstallationIdentityInterface $identity 稳定安装
     * @param array<string,mixed> $configuration 固定源站及下载范围
     * @param string $version 当前代码版本
     */
    public function __construct(
        private ClientInterface $http,
        private OfficialPlatformStateStoreInterface $store,
        private InstallationIdentityInterface $identity,
        private array $configuration,
        private string $version,
    ) {
    }

    /** @inheritDoc */
    public function localStatus(): array
    {
        $state = $this->store->read();
        $matching = ($state['scope'] ?? '') === $this->scope();
        $connection = $matching ? ($state['connection'] ?? null) : null;
        return ['configured' => trim($this->configuration['origin'] ?? '') !== '', 'origin' => rtrim($this->configuration['origin'] ?? '', '/'),
            'software_code' => self::SOFTWARE, 'installation_id' => $this->identity->id(), 'connected' => $connection !== null && empty($state['disconnected']),
            'reconnect_required' => false, 'connection' => $connection, 'checked_at' => $matching ? ($state['checked_at'] ?? null) : null];
    }

    /** @inheritDoc */
    public function activate(string $code): array
    {
        return $this->store->synchronized(function () use ($code): array {
            $result = $this->send('POST', '/api/client/v1/licenses/activate', ['activation_code' => $code,
                'name' => '皓量云擎云业务运营系统', 'domain' => $this->configuration['domain'] ?? '']);
            $connection = $this->connectionProjection($result['connection'] ?? []);
            $state = $this->store->read();
            unset($state['credentials'], $state['pairing'], $state['pairing_error'], $state['disconnected']);
            $state['scope'] = $this->scope(); $state['connection'] = $connection; $state['checked_at'] = date('Y-m-d H:i:s');
            $this->store->write($state);
            return $connection;
        });
    }

    /** @inheritDoc */
    public function synchronize(): array
    {
        $connection = $this->connectionProjection($this->send('GET', '/api/client/v1/connection'));
        $this->store->synchronized(function () use ($connection): void {
            $state = $this->store->read();
            unset($state['credentials'], $state['pairing'], $state['pairing_error']);
            $state['scope'] = $this->scope(); $state['connection'] = $connection; $state['checked_at'] = date('Y-m-d H:i:s');
            $this->store->write($state);
        });
        return $connection;
    }

    /** @inheritDoc */
    public function disconnect(): void
    {
        $this->send('POST', '/api/client/v1/connection/disconnect');
        $this->synchronize();
        $this->store->synchronized(function (): void {
            $state = $this->store->read(); $state['disconnected'] = true;
            unset($state['credentials'], $state['pairing']); $this->store->write($state);
        });
    }

    /** @inheritDoc */
    public function owned(): array
    {
        return $this->send('GET', $this->query('/api/client/v1/applications', ['current_version' => $this->version]));
    }

    /** @inheritDoc */
    public function storeUrl(): string
    {
        return $this->origin('portal_origin') . '/applications';
    }

    /** @inheritDoc */
    public function certificate(): array
    {
        return $this->send('POST', '/api/client/v1/license-certificate/refresh');
    }

    /** @inheritDoc */
    public function checkUpdate(): array
    {
        return $this->send('GET', $this->query('/api/client/v1/software-updates', [
            'current_version' => $this->version, 'platform' => strtolower(PHP_OS_FAMILY), 'architecture' => php_uname('m'), 'role' => 'system',
        ]));
    }

    /** @inheritDoc */
    public function applicationManifest(int $versionId): array
    {
        return $this->send('GET', $this->query('/api/client/v1/application-versions/' . $versionId . '/manifest', ['current_version' => $this->version]));
    }

    /** @inheritDoc */
    public function registerOperation(string $requestId, int $versionId, string $action): void
    {
        $this->send('POST', '/api/client/v1/installation-operations', [
            'request_id' => $requestId, 'version_id' => $versionId, 'action' => $action, 'current_version' => $this->version,
        ]);
    }

    /** @inheritDoc */
    public function reportOperation(string $requestId, string $status, string $errorCode = ''): void
    {
        $this->send('POST', '/api/client/v1/installation-operations/' . $requestId . '/reports', [
            'status' => $status, 'error_code' => $errorCode, 'current_version' => $this->version,
        ]);
    }

    /** @inheritDoc */
    public function registerSoftwareOperation(string $id, array $manifest): void
    {
        $this->send('POST', '/api/client/v1/software-installation-operations', ['request_id' => $id, 'package_id' => $manifest['package_id'], 'sha256' => $manifest['sha256'],
            'current_version' => $manifest['from_version'], 'platform' => $manifest['platform'], 'architecture' => $manifest['architecture'], 'role' => 'system']);
    }

    /** @inheritDoc */
    public function softwareOperationManifest(string $id): array
    {
        return $this->send('GET', '/api/client/v1/software-installation-operations/' . $id . '/manifest');
    }

    /** @inheritDoc */
    public function reportSoftwareOperation(string $id, string $status, string $errorCode = ''): void
    {
        $this->send('POST', '/api/client/v1/software-installation-operations/' . $id . '/reports', ['status' => $status, 'error_code' => $errorCode]);
    }

    /** @inheritDoc */
    public function download(array $manifest, string $destination): void
    {
        if (file_exists($destination) || is_link($destination) || !is_dir(dirname($destination))) {
            throw new OfficialPlatformException('INVALID_DOWNLOAD_PATH');
        }
        $temporary = $destination . '.part';
        if (file_exists($temporary) || is_link($temporary)) {
            throw new OfficialPlatformException('INVALID_DOWNLOAD_PATH');
        }
        $uri = $manifest['download_path'];
        $url = $this->origin() . $uri;
        $headers = $this->headers('GET', $uri, '');
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
        chmod($temporary, 0600);
        try {
            for ($hop = 0; ; $hop++) {
                $response = $this->http->request('GET', $url, [
                    'headers' => $headers, 'allow_redirects' => false, 'verify' => true,
                    'http_errors' => false, 'connect_timeout' => 5, 'timeout' => 120, 'stream' => true,
                ]);
                if (in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true)) {
                    $url = $response->getHeaderLine('Location');
                    $parts = parse_url($url);
                    $response->getBody()->close();
                    if ($hop >= 3 || ($parts['scheme'] ?? '') !== 'https' || (isset($parts['user']) || isset($parts['pass']))
                        || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443
                        || !in_array(strtolower($parts['host'] ?? ''), $this->configuration['download_hosts'] ?? [], true)) {
                        throw new OfficialPlatformException('UNTRUSTED_DOWNLOAD_REDIRECT');
                    }
                    $headers = ['Accept' => 'application/octet-stream'];
                    continue;
                }
                if ($response->getStatusCode() !== 200) {
                    $response->getBody()->close();
                    throw new OfficialPlatformException('DOWNLOAD_DENIED');
                }
                $total = 0;
                $body = $response->getBody();
                while (!$body->eof()) {
                    $chunk = $body->read(65536);
                    if ($chunk === '' && !$body->eof()) {
                        throw new OfficialPlatformException('TRANSPORT_ERROR');
                    }
                    $total += strlen($chunk);
                    if ($total > $manifest['bytes'] || fwrite($handle, $chunk) !== strlen($chunk)) {
                        throw new OfficialPlatformException('PACKAGE_INTEGRITY_ERROR');
                    }
                }
                $body->close();
                break;
            }
            fclose($handle);
            $handle = null;
            if ($total !== $manifest['bytes'] || !hash_equals($manifest['sha256'], hash_file('sha256', $temporary))) {
                throw new OfficialPlatformException('PACKAGE_INTEGRITY_ERROR');
            }
            if (!link($temporary, $destination)) {
                throw new OfficialPlatformException('STATE_IO_ERROR');
            }
        } catch (OfficialPlatformException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new OfficialPlatformException('TRANSPORT_ERROR', $error);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @param array<string,mixed> $data 不可信远程响应
     * @return array<string,mixed> 固定白名单投影
     */
    private function connectionProjection(array $data): array
    {
        if (($data['software_code'] ?? '') !== self::SOFTWARE || ($data['installation_id'] ?? '') !== $this->identity->id()
            || !is_string($data['connection_id'] ?? null) || !InstanceUuid::isValid($data['connection_id']) || !in_array($data['connection_mode'] ?? '', ['community', 'licensed'], true)
            || !is_string($data['edition_code'] ?? null)
            || !preg_match('/^[a-z][a-z0-9_-]{0,15}$/D', $data['edition_code'])
            || (isset($data['edition_name']) && (!is_string($data['edition_name']) || mb_strlen($data['edition_name']) > 120))
            || !is_bool($data['license_valid'] ?? null) || !is_string($data['account_name'] ?? null)
            || strlen($data['account_name']) > 256 || ($data['status'] ?? '') !== 'active'
            || (isset($data['license_no']) && (!is_string($data['license_no']) || strlen($data['license_no']) > 40))
            || (isset($data['license_expires_at']) && (!is_string($data['license_expires_at']) || strlen($data['license_expires_at']) > 40))) {
            throw new OfficialPlatformException('INVALID_RESPONSE');
        }
        $projection = array_intersect_key($data, array_flip([
            'connection_id', 'installation_id', 'software_code', 'connection_mode', 'edition_code', 'edition_name',
            'license_no', 'license_valid', 'license_expires_at', 'account_name', 'status',
        ]));
        $projection['edition_name'] = trim($data['edition_name'] ?? '') !== '' ? $data['edition_name'] : $data['edition_code'];

        return $projection;
    }

    /** @return string 源站、软件与身份组成的私有凭证作用域 */
    private function scope(): string
    {
        return hash('sha256', rtrim($this->configuration['origin'] ?? '', '/') . '|howstack-bos|' . $this->identity->id());
    }

    /**
     * @param string $path 固定路径
     * @param array<string,int|string> $parameters 筛选
     * @return string 签名共用的有序URI
     */
    private function query(string $path, array $parameters): string
    {
        ksort($parameters);
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        return $path . ($query === '' ? '' : '?' . $query);
    }

    /**
     * 校验固定 API 或官网页面源站；页面地址留空时沿用 API 源站。
     * @param string $key 固定配置字段 origin 或 portal_origin
     * @return string 受信固定源站，不允许路径、用户信息或未经许可的 HTTP
     * @throws OfficialPlatformException 未配置或源站格式不合法
     */
    private function origin(string $key = 'origin'): string
    {
        $configuredOrigin = $this->configuration[$key] ?? '';
        if ($key === 'portal_origin' && trim($configuredOrigin) === '') {
            $configuredOrigin = $this->configuration['origin'] ?? '';
        }
        $origin = rtrim($configuredOrigin, '/');
        if ($origin === '') {
            throw new OfficialPlatformException('NOT_CONFIGURED');
        }
        $parts = parse_url($origin);
        $local = ($this->configuration['allow_local_http'] ?? false) && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
        if (!is_array($parts) || empty($parts['host']) || (!$local && ($parts['scheme'] ?? '') !== 'https')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) {
            throw new OfficialPlatformException('INVALID_CONFIGURATION');
        }
        return $origin;
    }

    /**
     * @param string $method 方法
     * @param string $uri 原始URI
     * @param string $body 原始包体
     * @return array<string,string> 本次新签名
     */
    private function headers(string $method, string $uri, string $body): array
    {
        $timestamp = (string)time(); $nonce = bin2hex(random_bytes(24));
        return ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Howstack-Software' => self::SOFTWARE,
            'X-Howstack-Installation' => $this->identity->id(), 'X-Howstack-Public-Key' => $this->identity->publicKey(),
            'X-Howstack-Timestamp' => $timestamp, 'X-Howstack-Nonce' => $nonce,
            'X-Howstack-Signature' => $this->identity->sign(ClientRequestProof::canonical(self::SOFTWARE, $this->identity->id(), $method, $uri, $body, $timestamp, $nonce))];
    }

    /**
     * @param string $method 固定方法
     * @param string $uri 固定v1URI
     * @param array<string,mixed>|null $input 数据
     * @return array<string,mixed> 已验证响应外层
     */
    private function send(string $method, string $uri, ?array $input = null): array
    {
        $body = $input === null ? '' : json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            $response = $this->http->request($method, $this->origin() . $uri, [
                'headers' => $this->headers($method, $uri, $body), 'body' => $body,
                'allow_redirects' => false, 'verify' => true, 'http_errors' => false,
                'connect_timeout' => 3, 'timeout' => 8, 'stream' => true,
            ]);
            $stream = $response->getBody();
            $raw = '';
            try {
                // 网络流一次读取可能只返回部分字节；完整读取后才能解析响应。
                while (!$stream->eof()) {
                    $chunk = $stream->read(65536);
                    if ($chunk === '' && !$stream->eof()) {
                        throw new OfficialPlatformException('TRANSPORT_ERROR');
                    }
                    $raw .= $chunk;
                    if (strlen($raw) > 2097152) {
                        throw new OfficialPlatformException('INVALID_RESPONSE');
                    }
                }
            } finally {
                $stream->close();
            }
            try {
                $envelope = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new OfficialPlatformException(
                    $response->getStatusCode() === 404 ? 'ENDPOINT_NOT_AVAILABLE' : 'INVALID_RESPONSE',
                    $error,
                );
            }
            if (!is_array($envelope) || !is_string($envelope['code'] ?? null)) {
                throw new OfficialPlatformException('INVALID_RESPONSE');
            }
            // 只转换路由缺失；无商业证书等业务 404 仍保留官网错误码。
            if ($response->getStatusCode() === 404 && $envelope['code'] === 'NOT_FOUND'
                && in_array(explode('?', $uri, 2)[0], [
                    '/api/client/v1/software-updates',
                    '/api/client/v1/license-certificate',
                ], true)) {
                throw new OfficialPlatformException('ENDPOINT_NOT_AVAILABLE');
            }
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || $envelope['code'] !== 'OK') {
                throw new OfficialPlatformException($envelope['code']);
            }
            if (($envelope['data'] ?? null) === null) {
                return [];
            }
            if (!is_array($envelope['data'])) {
                throw new OfficialPlatformException('INVALID_RESPONSE');
            }
            return $envelope['data'];
        } catch (OfficialPlatformException $error) {
            throw $error;
        } catch (\JsonException $error) {
            throw new OfficialPlatformException('INVALID_RESPONSE', $error);
        } catch (Throwable $error) {
            throw new OfficialPlatformException('TRANSPORT_ERROR', $error);
        }
    }
}
