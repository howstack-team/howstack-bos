<?php

declare(strict_types=1);

namespace tests\unit\modules\content\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\application\storage\StorageChannelService;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\modules\content\application\ContentImageService;
use app\modules\content\contract\ContentImageRepositoryInterface;
use app\shared\plugin\contract\StorageAdapterInterface;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\dto\storage\StorageResult;
use app\shared\plugin\dto\storage\StorageUrl;
use PHPUnit\Framework\TestCase;

final class ContentImageServiceTest extends TestCase
{
    public function testNoEditPermissionCannotReadOptionsOrUpload(): void
    {
        [$service, $images, $adapter, $channels] = $this->fixture(true);
        $channels->expects(self::never())->method('list');
        $images->expects(self::never())->method('reserve');
        $adapter->expects(self::never())->method('put');
        $this->expectException(BusinessException::class);
        $service->upload(new AdminAccount(7, 'operator', '运营', 'hash', 1, null), 'news', $this->png(), 'image.png');
    }

    public function testNoDefaultStorageDoesNotWriteAnything(): void
    {
        [$service, $images, $adapter, $channels] = $this->fixture();
        $channels->method('list')->willReturn(['data' => [], 'total' => 0]);
        $images->expects(self::never())->method('reserve');
        $adapter->expects(self::never())->method('put');
        self::assertFalse($service->availability($this->actor(), 'news')['available']);
        $this->expectException(BusinessException::class);
        $service->upload($this->actor(), 'news', $this->png(), 'image.png');
    }

    public function testUploadSealsChannelAndReturnsOnlyStablePublicUrl(): void
    {
        [$service, $images, $adapter, $channels] = $this->fixture();
        $channels->method('list')->willReturn(['data' => [$this->channel()], 'total' => 1]);
        $reserved = [];
        $images->expects(self::once())->method('reserve')->willReturnCallback(static function (array $row) use (&$reserved): void { $reserved = $row; });
        $adapter->expects(self::once())->method('put')->willReturnCallback(static fn (ObjectUpload $file) => new StorageResult('stored', $file->key));
        $images->expects(self::once())->method('mark')->with(self::isString(), 'stored');
        $result = $service->upload($this->actor(), 'news', $this->png(), '../image.png');
        self::assertSame('/api/public/v1/content/images/' . $reserved['id'], $result['url']);
        self::assertSame('content/images/' . $reserved['id'] . '.png', $reserved['object_key']);
        self::assertSame(3, $reserved['channel_id']);
        self::assertSame(2, $reserved['channel_revision']);
        self::assertSame('image/png', $reserved['mime_type']);
        self::assertSame(1, $reserved['created_by']);
    }

    public function testUnknownUploadIsRetainedWithoutPublishingOrAutomaticReplay(): void
    {
        [$service, $images, $adapter, $channels] = $this->fixture();
        $channels->method('list')->willReturn(['data' => [$this->channel()], 'total' => 1]);
        $adapter->expects(self::once())->method('put')->willReturnCallback(static fn (ObjectUpload $file) => new StorageResult('unknown', $file->key));
        $images->expects(self::once())->method('mark')->with(self::isString(), 'unknown');
        $this->expectException(BusinessException::class);
        $service->upload($this->actor(), 'news', $this->png(), 'image.png');
    }

    public function testPublicReadRejectsUnfinishedObjects(): void
    {
        [$service, $images, $adapter] = $this->fixture();
        $images->expects(self::once())->method('find')->willReturn(['status' => 'pending']);
        $adapter->expects(self::never())->method('signedUrl');
        $this->expectException(BusinessException::class);
        $service->url(str_repeat('a', 32));
    }

    public function testPublicReadSignsOnlyOriginalPersistedObject(): void
    {
        [$service, $images, $adapter] = $this->fixture();
        $images->expects(self::once())->method('find')->willReturn(['status' => 'stored', 'channel_id' => 3, 'channel_revision' => 2, 'object_key' => 'content/images/fixed.png']);
        $adapter->expects(self::once())->method('signedUrl')->with(self::callback(static fn (ObjectKey $key): bool => $key->value === 'content/images/fixed.png'), 300, self::anything())
            ->willReturn(new StorageUrl(new ObjectKey('content/images/fixed.png'), 'https://storage.example.test/fixed.png?signature=test', time() + 300));
        self::assertSame('https://storage.example.test/fixed.png?signature=test', $service->url(str_repeat('a', 32)));
    }

    private function fixture(bool $mockChannels = false): array
    {
        $channels = $mockChannels ? $this->createMock(ChannelRepositoryInterface::class) : $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('find')->willReturn($this->channel());
        $channels->method('configuration')->willReturn([]);
        $images = $this->createMock(ContentImageRepositoryInterface::class);
        $adapter = $this->createMock(StorageAdapterInterface::class);
        $factory = $this->createStub(TransactionAdapterFactoryInterface::class);
        $factory->method('storage')->willReturn($adapter);
        $storage = new StorageChannelService(new ChannelExecutionConfiguration($channels), $factory);
        $grants = $this->createStub(AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([]);
        return [new ContentImageService($channels, $images, $storage, new AdminAccessControlService($grants)), $images, $adapter, $channels];
    }

    private function channel(): array
    {
        return ['id' => 3, 'revision' => 2, 'plugin_id' => 'local', 'is_default' => true, 'configuration_current' => true, 'platform_available' => true, 'status' => 1];
    }

    private function actor(): AdminAccount
    {
        return new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true);
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jFjkAAAAASUVORK5CYII=');
    }
}
