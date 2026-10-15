<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure\storage;

use app\modules\channel\contract\storage\LocalObjectDownloadInterface;
use app\shared\plugin\contract\LocalObjectStoreInterface;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\dto\storage\StorageUrl;
use app\shared\plugin\exception\PluginException;
use Throwable;

/** 私有目录、逻辑键哈希、原子文件替换和限时 HMAC；不公开任意路径读取能力。 */
final readonly class PrivateLocalObjectStore implements LocalObjectStoreInterface, LocalObjectDownloadInterface
{
    /**
     * @param string $root 服务端固定私有根目录
     * @param string $signingKey 宿主秘密，禁止传入插件配置
     */
    public function __construct(private string $root, #[\SensitiveParameter] private string $signingKey)
    {
        if (!str_starts_with($root, '/') || str_contains($root, chr(0)) || strlen($signingKey) < 32) {
            throw new PluginException('本地存储服务端配置无效', 'invalid_configuration');
        }
    }

    /** @inheritDoc */
    public function put(string $namespace, ObjectUpload $upload): void
    {
        $path = $this->path($namespace, $upload->key, true);
        $temporary = dirname($path) . '/.' . bin2hex(random_bytes(16)) . '.tmp';
        $stream = null;
        try {
            $metadata = json_encode(['key' => $upload->key->value, 'type' => $upload->contentType, 'size' => strlen($upload->content)], JSON_THROW_ON_ERROR);
            $blob = pack('N', strlen($metadata)) . $metadata . $upload->content;
            $stream = fopen($temporary, 'x+b');
            if ($stream === false || !chmod($temporary, 0600)) {
                throw new PluginException('本地文件写入失败', 'storage_failed');
            }
            $offset = 0;
            while ($offset < strlen($blob)) {
                $written = fwrite($stream, substr($blob, $offset));
                if ($written === false || $written === 0) {
                    throw new PluginException('本地文件写入未完成', 'storage_failed');
                }
                $offset += $written;
            }
            if (!fflush($stream)) {
                throw new PluginException('本地文件写入未完成', 'storage_failed');
            }
            fclose($stream);
            $stream = null;
            // 受信任目录仍拒绝已有软链接，防止覆盖外部对象。
            $this->path($namespace, $upload->key, false);
            if (!rename($temporary, $path)) {
                throw new PluginException('本地文件替换失败', 'storage_failed');
            }
        } catch (Throwable) {
            throw new PluginException('本地文件写入失败', 'storage_failed');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @inheritDoc */
    public function delete(string $namespace, ObjectKey $key): void
    {
        $path = $this->path($namespace, $key, false);
        if (is_file($path) && !unlink($path)) {
            throw new PluginException('本地文件删除失败', 'storage_failed');
        }
    }

    /** @inheritDoc */
    public function signedUrl(string $namespace, ObjectKey $key, int $seconds): StorageUrl
    {
        StorageUrl::validateLifetime($seconds);
        $this->path($namespace, $key, false);
        $expires = time() + $seconds;
        return new StorageUrl($key, '/api/public/v1/storage/local?' . http_build_query([
            'namespace' => $namespace, 'key' => $key->value, 'expires' => $expires,
            'signature' => $this->signature($namespace, $key, $expires),
        ], '', '&', PHP_QUERY_RFC3986), $expires);
    }

    /** @inheritDoc */
    public function download(string $namespace, ObjectKey $key, int $expires, string $signature): ObjectUpload
    {
        $this->validateNamespace($namespace);
        if ($expires < time() || $expires > time() + 3600 || !preg_match('/^[a-f0-9]{64}$/D', $signature)
            || !hash_equals($this->signature($namespace, $key, $expires), $signature)) {
            throw new PluginException('文件链接已失效或签名无效', 'storage_access_denied');
        }
        $path = $this->path($namespace, $key, false);
        if (!is_file($path) || filesize($path) > ObjectUpload::MAX_BYTES + 2052) {
            throw new PluginException('文件不存在或不可用', 'storage_not_found');
        }
        $stream = null;
        try {
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new PluginException('文件不可用', 'storage_not_found');
            }
            $prefix = fread($stream, 4);
            if (strlen($prefix) !== 4) {
                throw new PluginException('文件内容无效', 'storage_failed');
            }
            $length = unpack('Nlength', $prefix)['length'];
            if ($length < 1 || $length > 2048) {
                throw new PluginException('文件内容无效', 'storage_failed');
            }
            $metadata = json_decode(fread($stream, $length), true, 8, JSON_THROW_ON_ERROR);
            $body = stream_get_contents($stream, ObjectUpload::MAX_BYTES + 1);
            if (($metadata['key'] ?? null) !== $key->value || !is_string($body)
                || ($metadata['size'] ?? null) !== strlen($body) || !is_string($metadata['type'] ?? null)) {
                throw new PluginException('文件内容无效', 'storage_failed');
            }
            return new ObjectUpload($key, $body, $metadata['type']);
        } catch (Throwable) {
            throw new PluginException('文件不存在或不可用', 'storage_not_found');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param string $namespace 分区
     * @param ObjectKey $key 逻辑键
     * @param int $expires 到期时间
     * @return string 固定用途签名
     */
    private function signature(string $namespace, ObjectKey $key, int $expires): string
    {
        $derivedKey = hash_hmac('sha256', 'howstack.local-storage.v1', $this->signingKey, true);
        return hash_hmac('sha256', $namespace . "\n" . $key->value . "\n" . $expires, $derivedKey);
    }

    /**
     * @param string $namespace 分区
     * @return void
     * @throws PluginException 分区无效
     */
    private function validateNamespace(string $namespace): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $namespace)) {
            throw new PluginException('存储分区无效', 'invalid_request');
        }
    }

    /**
     * @param string $namespace 分区
     * @param ObjectKey $key 对象键
     * @param bool $create 是否创建目录
     * @return string 宿主内部私有路径
     */
    private function path(string $namespace, ObjectKey $key, bool $create): string
    {
        $this->validateNamespace($namespace);
        // 检查固定根目录每个已存在的父路径，不允许软链接。
        $current = '';
        foreach (explode('/', trim($this->root, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new PluginException('本地存储目录无效', 'storage_failed');
            }
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new PluginException('本地存储目录不可使用软链接', 'storage_failed');
            }
        }
        $directory = rtrim($this->root, '/') . '/' . $namespace;
        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new PluginException('本地存储分区不可用', 'storage_failed');
        }
        if ($create && !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PluginException('本地存储分区创建失败', 'storage_failed');
        }
        $path = $directory . '/' . hash('sha256', $key->value) . '.object';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new PluginException('本地存储对象不可用', 'storage_failed');
        }
        return $path;
    }
}
