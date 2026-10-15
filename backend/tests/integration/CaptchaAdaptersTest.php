<?php

declare(strict_types=1);

namespace tests\integration;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\captcha\CaptchaContext;
use app\shared\plugin\dto\captcha\CaptchaProof;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use tests\support\MockChannelHttp;

require_once dirname(__DIR__) . '/support/MockChannelHttp.php';

/** 官方协议、真实 SDK 请求模型和模拟 HTTP；不发送真实厂商请求。 */
final class CaptchaAdaptersTest extends TestCase
{
    /** 按宿主规则加载插件自己的生产依赖，测试不得依赖主 Composer 偶然提供 SDK。 */
    protected function setUp(): void
    {
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/captcha/tencent_captcha',
            'plugins\\captcha\\tencent_captcha\\',
        );
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/captcha/aliyun_captcha',
            'plugins\\captcha\\aliyun_captcha\\',
        );
    }

    /** @return CaptchaContext 虚构且固定的服务端场景 */
    private function context(): CaptchaContext
    {
        return new CaptchaContext(str_repeat('a', 64), str_repeat('b', 64), 'member.login', '198.51.100.20');
    }

    /** @param array<string, mixed> $data 模拟 JSON @return Response 回复 */
    private function reply(array $data): Response
    {
        return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testGeetestUsesHmacAndBindsVerifiedResultToLotNumber(): void
    {
        $lot = str_repeat('a', 32);
        $http = MockChannelHttp::create([
            $this->reply(['status' => 'success', 'result' => 'success', 'captcha_args' => ['lot_number' => $lot]]),
            $this->reply(['status' => 'success', 'result' => 'success', 'captcha_args' => ['lot_number' => str_repeat('b', 32)]]),
            $this->reply(['status' => 'success', 'result' => 'fail']),
            new \RuntimeException('secret must not escape'),
        ], $history);
        $adapter = new \plugins\captcha\geetest_v4\CaptchaAdapter($http);
        $config = new Configuration(['captcha_id' => str_repeat('c', 32), 'captcha_key' => 'SECRET']);
        $proof = new CaptchaProof(['lot_number' => $lot, 'captcha_output' => 'opaque+output=', 'pass_token' => 'TOKEN', 'gen_time' => (string) time()]);
        $challenge = $adapter->initialize($this->context(), $config);
        self::assertSame(['captchaId' => str_repeat('c', 32), 'product' => 'bind'], $challenge->parameters);
        self::assertSame('verified', $adapter->verify($proof, $this->context(), $config)->status);
        parse_str((string) $history[0]['request']->getBody(), $params);
        self::assertSame(hash_hmac('sha256', $lot, 'SECRET'), $params['sign_token']);
        self::assertSame('opaque+output=', $params['captcha_output']);
        self::assertSame('gcaptcha4.geetest.com', $history[0]['request']->getUri()->getHost());
        self::assertSame('unknown', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame('rejected', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame('unknown', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertCount(4, $history);
        $expired = new CaptchaProof($proof->values + []);
        $expired = new CaptchaProof(array_replace($expired->values, ['gen_time' => (string) (time() - 600)]));
        self::assertSame('rejected', $adapter->verify($expired, $this->context(), $config)->status);
        self::assertCount(4, $history);
    }

    public function testYidunMatchesOfficialSortedSignatureAndDoesNotAcceptTruthyStrings(): void
    {
        $http = MockChannelHttp::create([
            $this->reply(['error' => 0, 'result' => true]), $this->reply(['error' => 0, 'result' => false]),
            $this->reply(['error' => 0, 'result' => 'true']), $this->reply(['error' => 500, 'result' => true]),
        ], $history);
        $adapter = new \plugins\captcha\yidun_captcha\CaptchaAdapter($http);
        $config = new Configuration(['captcha_id' => 'CAPTCHA', 'secret_id' => 'ID', 'secret_key' => 'SECRET']);
        $proof = new CaptchaProof(['validate' => 'original+proof==']);
        self::assertSame('verified', $adapter->verify($proof, $this->context(), $config)->status);
        parse_str((string) $history[0]['request']->getBody(), $params);
        $signature = $params['signature'];
        unset($params['signature']);
        ksort($params, SORT_STRING);
        $raw = '';
        foreach ($params as $key => $value) {
            $raw .= $key . $value;
        }
        self::assertSame(md5($raw . 'SECRET'), $signature);
        self::assertSame('original+proof==', $params['validate']);
        self::assertSame($this->context()->id, $params['user']);
        self::assertSame('https', $history[0]['request']->getUri()->getScheme());
        self::assertSame('rejected', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame('unknown', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame('unknown', $adapter->verify($proof, $this->context(), $config)->status);
    }

    public function testTencentRequiresCodeOneUsesTrustedIpAndRejectsDisasterTickets(): void
    {
        $http = MockChannelHttp::create([
            $this->reply(['Response' => ['CaptchaCode' => 1, 'RequestId' => 'REQUEST']]),
            $this->reply(['Response' => ['CaptchaCode' => 0, 'RequestId' => 'REQUEST']]),
            $this->reply(['Response' => ['CaptchaCode' => '1', 'RequestId' => 'REQUEST']]),
        ], $history);
        $adapter = new \plugins\captcha\tencent_captcha\CaptchaAdapter($http);
        $config = new Configuration(['captcha_app_id' => '199999999', 'app_secret_key' => 'APP_SECRET', 'secret_id' => 'ID', 'secret_key' => 'SECRET']);
        $proof = new CaptchaProof(['ticket' => 'ticket-original', 'randstr' => '@random']);
        self::assertSame('verified', $adapter->verify($proof, $this->context(), $config)->status);
        $params = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame(9, $params['CaptchaType']);
        self::assertSame(199999999, $params['CaptchaAppId']);
        self::assertSame('198.51.100.20', $params['UserIp']);
        self::assertSame('APP_SECRET', $params['AppSecretKey']);
        self::assertFalse($history[0]['request']->hasHeader('X-TC-Region'));
        self::assertStringContainsString('TC3-HMAC-SHA256', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('rejected', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame('unknown', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame('rejected', $adapter->verify(new CaptchaProof(['ticket' => 'trerror_fake', 'randstr' => '@random']), $this->context(), $config)->status);
        self::assertCount(3, $history);
        self::assertSame(['appId' => '199999999'], $adapter->initialize($this->context(), $config)->parameters);
        self::assertSame($adapter->fingerprint($proof, $this->context(), $config), $adapter->fingerprint(new CaptchaProof(['ticket' => 'ticket-original', 'randstr' => 'modified']), $this->context(), $config));
    }

    public function testAliyunPreservesRawProofBindsServerSceneAndRejectsTestMode(): void
    {
        $http = MockChannelHttp::create([
            $this->reply(['RequestId' => 'REQUEST', 'Success' => true, 'Code' => 'Success', 'Result' => ['VerifyResult' => true, 'VerifyCode' => 'T001']]),
            $this->reply(['RequestId' => 'REQUEST', 'Success' => true, 'Code' => 'Success', 'Result' => ['VerifyResult' => true, 'VerifyCode' => 'T005']]),
            $this->reply(['RequestId' => 'REQUEST', 'Success' => true, 'Code' => 'Success', 'Result' => ['VerifyResult' => false, 'VerifyCode' => 'F001']]),
            $this->reply(['RequestId' => 'REQUEST', 'Success' => true, 'Code' => 'Success', 'Result' => ['VerifyResult' => 'true', 'VerifyCode' => 'T001']]),
        ], $history);
        $adapter = new \plugins\captcha\aliyun_captcha\CaptchaAdapter($http);
        $config = new Configuration(['access_key_id' => 'ID', 'access_key_secret' => 'SECRET', 'scene_id' => 'SERVER_SCENE', 'prefix' => 'PREFIX', 'region' => 'sgp']);
        $raw = "{\"sceneId\":\"CLIENT_SCENE\", \"data\":\"original+proof==\"}";
        $proof = new CaptchaProof(['captcha_verify_param' => $raw]);
        self::assertSame('verified', $adapter->verify($proof, $this->context(), $config)->status);
        parse_str((string) $history[0]['request']->getBody(), $params);
        self::assertSame($raw, $params['CaptchaVerifyParam']);
        self::assertSame('SERVER_SCENE', $params['SceneId']);
        self::assertSame('VerifyIntelligentCaptcha', $params['Action']);
        self::assertSame('2023-03-05', $params['Version']);
        self::assertSame('captcha.ap-southeast-1.aliyuncs.com', $history[0]['request']->getUri()->getHost());
        self::assertSame('rejected', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame('rejected', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame('unknown', $adapter->verify($proof, $this->context(), $config)->status);
        self::assertSame(['SceneId' => 'SERVER_SCENE', 'prefix' => 'PREFIX', 'region' => 'sgp'], $adapter->initialize($this->context(), $config)->parameters);
    }

    public function testImageAdapterUsesOnlyRestrictedHostCapability(): void
    {
        $images = $this->createMock(\app\shared\plugin\contract\ImageCaptchaInterface::class);
        $images->expects(self::once())->method('generate')->with($this->context(), 5, 160, 50)->willReturn('data:image/jpeg;base64,TEST');
        $images->expects(self::once())->method('verify')->with($this->context(), 'abc23')->willReturn(true);
        $http = MockChannelHttp::create([], $history);
        $adapter = new \plugins\captcha\image_captcha\CaptchaAdapter($http, $images);
        $config = new Configuration(['length' => 5, 'width' => 160, 'height' => 50]);
        self::assertSame(['image' => 'data:image/jpeg;base64,TEST'], $adapter->initialize($this->context(), $config)->parameters);
        self::assertSame('verified', $adapter->verify(new CaptchaProof(['answer' => 'abc23']), $this->context(), $config)->status);
        self::assertSame([], $history);
    }

    public function testMalformedProofAndSecretSerializationAreRejected(): void
    {
        $proof = new CaptchaProof(['ticket' => 'TOP_SECRET']);
        self::assertSame('{"redacted":true}', json_encode($proof));
        self::assertSame('{"redacted":true}', json_encode($this->context()));
        $this->expectException(\LogicException::class);
        serialize($proof);
    }
}
