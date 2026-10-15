<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\application\captcha\CaptchaChannelService;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\modules\channel\infrastructure\captcha\RedisCaptchaAttemptStore;
use app\modules\channel\infrastructure\captcha\WebmanImageCaptcha;
use app\shared\plugin\contract\CaptchaAdapterInterface;
use app\shared\plugin\dto\captcha\CaptchaContext;
use app\shared\plugin\dto\captcha\CaptchaProof;
use app\shared\plugin\dto\captcha\CaptchaResult;
use PHPUnit\Framework\TestCase;
use support\Redis;

/** 真实 Redis/GD 与宿主用例；只操作随机测试挑战，不使用真实厂商凭据。 */
final class CaptchaHostTest extends TestCase
{
    /** @var list<string> 本测试创建的缓存键 */
    private array $keys = [];

    protected function setUp(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') {
            self::markTestSkipped('显式允许本地基础设施测试时才访问 Redis');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            Redis::del($key);
        }
    }

    /** @return CaptchaContext 随机且与业务隔离的挑战 */
    private function context(): CaptchaContext
    {
        $context = new CaptchaContext(bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), 'test.login', '2001:db8::' . bin2hex(random_bytes(2)));
        $this->keys[] = 'channel:captcha:image:' . $context->id;
        return $context;
    }

    public function testAttemptIsBoundToSessionPurposeAndIpAndConsumedOnce(): void
    {
        $store = new RedisCaptchaAttemptStore();
        $source = $this->context();
        $context = $store->issue(7, 2, $source->browserBinding, $source->purpose, $source->clientIp);
        $key = 'channel:captcha:attempt:' . $context->id;
        $this->keys[] = $key;
        $this->keys[] = 'channel:captcha:rate:' . hash('sha256', $context->clientIp);
        self::assertGreaterThan(0, Redis::ttl($key));
        self::assertLessThanOrEqual(300, Redis::ttl($key));
        $cached = Redis::get($key);
        self::assertStringNotContainsString($context->browserBinding, $cached);
        self::assertStringNotContainsString($context->clientIp, $cached);
        foreach ([
            new CaptchaContext($context->id, bin2hex(random_bytes(32)), $context->purpose, $context->clientIp),
            new CaptchaContext($context->id, $context->browserBinding, 'test.register', $context->clientIp),
            new CaptchaContext($context->id, $context->browserBinding, $context->purpose, '198.51.100.10'),
        ] as $wrong) {
            self::assertNull($store->consume($wrong));
        }
        self::assertSame(['channel_id' => 7, 'revision' => 2], $store->consume($context));
        self::assertNull($store->consume($context));
        $fingerprint = hash('sha256', random_bytes(32));
        $this->keys[] = 'channel:captcha:proof:geetest_v4:' . $fingerprint;
        self::assertTrue($store->reserveProof('geetest_v4', $fingerprint));
        self::assertFalse($store->reserveProof('geetest_v4', $fingerprint));
        self::assertLessThanOrEqual(600, Redis::ttl(end($this->keys)));
    }

    public function testImageIsActualJpegAndIncorrectAnswerConsumesChallenge(): void
    {
        $image = new WebmanImageCaptcha('testing-application-key-only');
        $context = $this->context();
        $uri = $image->generate($context, 5, 160, 50);
        self::assertStringStartsWith('data:image/jpeg;base64,', $uri);
        $bytes = base64_decode(substr($uri, strlen('data:image/jpeg;base64,')), true);
        $size = getimagesizefromstring($bytes);
        self::assertSame([160, 50, IMAGETYPE_JPEG], array_slice($size, 0, 3));
        $key = 'channel:captcha:image:' . $context->id;
        $cached = json_decode(Redis::get($key), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(64, strlen($cached['answer']));
        self::assertFalse($image->verify(new CaptchaContext($context->id, bin2hex(random_bytes(32)), $context->purpose, $context->clientIp), 'wrong'));
        self::assertNotFalse(Redis::get($key));
        self::assertFalse($image->verify($context, 'impossible-answer'));
        self::assertFalse((bool) Redis::exists($key));
        self::assertFalse($image->verify($context, 'impossible-answer'));
    }

    public function testCorrectImageAnswerIsSingleUseAndExpiredImageFails(): void
    {
        $keyMaterial = 'testing-application-key-only';
        $image = new WebmanImageCaptcha($keyMaterial);
        $context = $this->context();
        $key = 'channel:captcha:image:' . $context->id;
        Redis::set($key, json_encode([
            'binding' => hash('sha256', $context->browserBinding . ':' . $context->purpose . ':' . $context->clientIp),
            'answer' => hash_hmac('sha256', $context->id . ':abc23', $keyMaterial),
        ], JSON_THROW_ON_ERROR), 'EX', 300);
        self::assertTrue($image->verify($context, ' ABC23 '));
        self::assertFalse($image->verify($context, 'abc23'));
        self::assertFalse($image->verify($this->context(), 'abc23'));
    }

    public function testHostConsumesProofBeforeVerificationAndRejectsChangedRevision(): void
    {
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $row = ['revision' => 2, 'configuration_current' => true, 'platform_available' => true, 'status' => 1, 'plugin_id' => 'geetest_v4'];
        $channels->method('find')->willReturnCallback(static function () use (&$row): array {
            return $row;
        });
        $channels->method('configuration')->willReturn([]);
        $adapter = $this->createMock(CaptchaAdapterInterface::class);
        $fingerprint = hash('sha256', random_bytes(32));
        $this->keys[] = 'channel:captcha:proof:geetest_v4:' . $fingerprint;
        $adapter->method('fingerprint')->willReturn($fingerprint);
        $adapter->expects(self::once())->method('verify')->willReturn(new CaptchaResult('verified'));
        $factory = $this->createStub(TransactionAdapterFactoryInterface::class);
        $factory->method('captcha')->willReturn($adapter);
        $store = new RedisCaptchaAttemptStore();
        $host = new CaptchaChannelService(new ChannelExecutionConfiguration($channels), $factory, $store);
        $source = $this->context();
        $this->keys[] = 'channel:captcha:rate:' . hash('sha256', $source->clientIp);
        $create = function () use ($source, $store): CaptchaContext {
            $context = $store->issue(7, 2, $source->browserBinding, $source->purpose, $source->clientIp);
            $this->keys[] = 'channel:captcha:attempt:' . $context->id;
            return $context;
        };
        $verify = static fn (CaptchaContext $context): CaptchaResult => $host->verify($context->id, new CaptchaProof(['lot_number' => 'opaque']), $context->browserBinding, $context->purpose, $context->clientIp);
        $context = $create();
        self::assertSame('verified', $verify($context)->status);
        self::assertSame('rejected', $verify($context)->status);
        self::assertSame('rejected', $verify($create())->status);
        $changed = $create();
        $row['revision'] = 3;
        self::assertSame('unknown', $verify($changed)->status);
        self::assertSame('rejected', $verify($changed)->status);
    }
}
