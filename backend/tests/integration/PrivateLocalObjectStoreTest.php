<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\channel\infrastructure\storage\PrivateLocalObjectStore;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\exception\PluginException;
use PHPUnit\Framework\TestCase;

/** 私有目录真实文件操作，测试仅写入临时专属根目录。 */
final class PrivateLocalObjectStoreTest extends TestCase
{
    private string $root;
    private PrivateLocalObjectStore $store;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/howstack-storage-test-' . bin2hex(random_bytes(8));
        $this->store = new PrivateLocalObjectStore($this->root, str_repeat('k', 64));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testPrivateWriteSignedReadAtomicReplacementAndIdempotentDelete(): void
    {
        $key = new ObjectKey('members/1/private.txt');
        $this->store->put('uploads', new ObjectUpload($key, "hello\x00world", 'text/plain'));
        $path = $this->root . '/uploads/' . hash('sha256', $key->value) . '.object';
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(0700, fileperms(dirname($path)) & 0777);
        $url = $this->store->signedUrl('uploads', $key, 120);
        parse_str(parse_url($url->url, PHP_URL_QUERY), $query);
        $object = $this->store->download($query['namespace'], new ObjectKey($query['key']), (int) $query['expires'], $query['signature']);
        self::assertSame("hello\x00world", $object->content);
        self::assertSame('text/plain', $object->contentType);
        $this->store->put('uploads', new ObjectUpload($key, '', 'text/plain'));
        self::assertSame('', $this->store->download($query['namespace'], $key, (int) $query['expires'], $query['signature'])->content);
        self::assertCount(1, glob($this->root . '/uploads/*'));
        $this->store->delete('uploads', $key);
        $this->store->delete('uploads', $key);
        self::assertFileDoesNotExist($path);
    }

    public function testSignatureCannotBeReusedForAnotherNamespaceKeyOrLifetime(): void
    {
        $key = new ObjectKey('private.txt');
        $this->store->put('uploads', new ObjectUpload($key, 'hello', 'text/plain'));
        parse_str(parse_url($this->store->signedUrl('uploads', $key, 120)->url, PHP_URL_QUERY), $query);
        $cases = [
            ['other', $key, (int) $query['expires']],
            ['uploads', new ObjectKey('other.txt'), (int) $query['expires']],
            ['uploads', $key, (int) $query['expires'] + 1],
            ['uploads', $key, time() - 1],
        ];
        foreach ($cases as [$namespace, $candidate, $expires]) {
            try {
                $this->store->download($namespace, $candidate, $expires, $query['signature']);
                self::fail('签名不得用于其他对象');
            } catch (PluginException $error) {
                self::assertSame('storage_access_denied', $error->reason);
            }
        }
    }

    public function testSymlinkCannotOverwriteExternalFileOrReadAnotherObject(): void
    {
        $key = new ObjectKey('private.txt');
        $this->store->put('uploads', new ObjectUpload($key, 'original', 'text/plain'));
        $other = new ObjectKey('other.txt');
        $original = $this->root . '/uploads/' . hash('sha256', $key->value) . '.object';
        $link = $this->root . '/uploads/' . hash('sha256', $other->value) . '.object';
        symlink($original, $link);
        try {
            $this->store->put('uploads', new ObjectUpload($other, 'evil', 'text/plain'));
            self::fail('不得写入软链接');
        } catch (PluginException $error) {
            self::assertSame('storage_failed', $error->reason);
        }
        parse_str(parse_url($this->store->signedUrl('uploads', $key, 120)->url, PHP_URL_QUERY), $query);
        self::assertSame('original', $this->store->download('uploads', $key, (int) $query['expires'], $query['signature'])->content);
        self::assertTrue(is_link($link));
    }

    public function testInvalidNamespaceAndOversizedObjectAreRejected(): void
    {
        try {
            $this->store->put('../outside', new ObjectUpload(new ObjectKey('test.txt'), 'test', 'text/plain'));
            self::fail('不得穿越分区');
        } catch (PluginException) {
            self::assertDirectoryDoesNotExist($this->root);
        }
        $this->expectException(\InvalidArgumentException::class);
        new ObjectUpload(new ObjectKey('test.txt'), str_repeat('x', ObjectUpload::MAX_BYTES + 1), 'text/plain');
    }
}
