<?php

declare(strict_types=1);

namespace tests\integration;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\sms\SmsMessage;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\identity\IdentityRequest;
use app\shared\plugin\dto\identity\IdentityReference;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/support/MockChannelHttp.php';

/** 官方 SDK 模型及签名运行测试，网络为替身，不使用真实厂商凭据。 */
final class CloudChannelsTest extends TestCase
{
    /** 按宿主规则加载插件自己的生产依赖，测试不得依赖主 Composer 偶然提供 SDK。 */
    protected function setUp(): void
    {
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/sms/aliyun_sms',
            'plugins\\sms\\aliyun_sms\\',
        );
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/sms/tencent_sms',
            'plugins\\sms\\tencent_sms\\',
        );
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/mail/aliyun_dm',
            'plugins\\mail\\aliyun_dm\\',
        );
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/mail/tencent_ses',
            'plugins\\mail\\tencent_ses\\',
        );
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/identity/tencent_faceid',
            'plugins\\identity\\tencent_faceid\\',
        );
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/storage/aliyun_oss',
            'plugins\\storage\\aliyun_oss\\',
        );
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/storage/tencent_cos',
            'plugins\\storage\\tencent_cos\\',
        );
        \app\modules\plugin\infrastructure\PluginDependencyLoader::load(
            dirname(__DIR__, 2) . '/plugins/payment/wechat_native',
            'plugins\\payment\\wechat_native\\',
        );
    }

    /**
     * @param array<string, mixed> $data 官方结构
     * @return Response 测试响应
     */
    private function json(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testAliSmsUsesSdkModelSignedSingleRecipientAndTemplate(): void
    {
        $http = \tests\support\MockChannelHttp::create([$this->json(['RequestId' => 'request', 'Code' => 'OK', 'BizId' => 'receipt'])], $history);
        $adapter = new \plugins\sms\aliyun_sms\SmsAdapter($http);
        $result = $adapter->send(new SmsMessage('EVENT-1', '+8613800000000', 'SMS_100', ['code' => '123456']), new Configuration([
            'access_key_id' => 'test-id', 'access_key_secret' => 'test-secret', 'sign_name' => '测试签名',
        ]));
        self::assertSame('accepted', $result->status);
        parse_str((string) $history[0]['request']->getBody(), $data);
        self::assertSame('SendSms', $data['Action']);
        self::assertSame('+8613800000000', $data['PhoneNumbers']);
        self::assertSame(['code' => '123456'], json_decode($data['TemplateParam'], true));
        $signature = $data['Signature'];
        unset($data['Signature']);
        self::assertSame(\Darabonba\OpenApi\Utils::getRPCSignature($data, 'POST', 'test-secret'), $signature);
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function testTencentSmsPreservesParameterOrderAndChecksRecipient(): void
    {
        $responses = [
            $this->json(['Response' => ['RequestId' => 'request', 'SendStatusSet' => [['Code' => 'Ok', 'PhoneNumber' => '+8613800000000', 'SerialNo' => 'receipt']]]]),
            $this->json(['Response' => ['RequestId' => 'request', 'SendStatusSet' => [['Code' => 'Ok', 'PhoneNumber' => '+8613900000000', 'SerialNo' => 'receipt']]]]),
        ];
        $http = \tests\support\MockChannelHttp::create($responses, $history);
        $adapter = new \plugins\sms\tencent_sms\SmsAdapter($http);
        $config = new Configuration(['secret_id' => 'id', 'secret_key' => 'secret', 'region' => 'ap-guangzhou', 'sdk_app_id' => '1400000000', 'sign_name' => '签名']);
        $message = new SmsMessage('EVENT-1', '+8613800000000', '123', ['a', 'b']);
        self::assertSame('accepted', $adapter->send($message, $config)->status);
        self::assertSame('unknown', $adapter->send($message, $config)->status);
        $request = $history[0]['request'];
        $data = json_decode((string) $request->getBody(), true);
        self::assertSame(['a', 'b'], $data['TemplateParamSet']);
        self::assertSame('SendSms', $request->getHeaderLine('X-TC-Action'));
        self::assertSame('2021-01-11', $request->getHeaderLine('X-TC-Version'));
        self::assertStringStartsWith('TC3-HMAC-SHA256 Credential=id/', $request->getHeaderLine('Authorization'));
        $timestamp = $request->getHeaderLine('X-TC-Timestamp');
        $date = gmdate('Y-m-d', (int) $timestamp);
        $canonical = "POST\n/\n\ncontent-type:application/json\nhost:sms.tencentcloudapi.com\n\ncontent-type;host\n" . hash('sha256', (string) $request->getBody());
        $toSign = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $date . "/sms/tc3_request\n" . hash('sha256', $canonical);
        $dateKey = hash_hmac('sha256', $date, 'TC3secret', true);
        $serviceKey = hash_hmac('sha256', 'sms', $dateKey, true);
        $signingKey = hash_hmac('sha256', 'tc3_request', $serviceKey, true);
        self::assertStringEndsWith('Signature=' . hash_hmac('sha256', $toSign, $signingKey), $request->getHeaderLine('Authorization'));
    }

    public function testSubmailSignsNamedVariablesAndSmsbaoDoesNotDoubleEncode(): void
    {
        $http = \tests\support\MockChannelHttp::create([$this->json(['status' => 'success', 'send_id' => 'send-1']), new Response(200, [], '0')], $history);
        $submail = new \plugins\sms\submail_sms\SmsAdapter($http);
        self::assertSame('accepted', $submail->send(new SmsMessage('E1', '+8613800000000', 'project', ['code' => '123']), new Configuration(['app_id' => 'appid', 'app_key' => 'appkey']))->status);
        parse_str((string) $history[0]['request']->getBody(), $data);
        self::assertSame('13800000000', $data['to']);
        self::assertSame(['code' => '123'], json_decode($data['vars'], true));
        $signature = $data['signature'];
        unset($data['signature'], $data['vars']);
        ksort($data);
        self::assertSame(md5('appidappkey' . urldecode(http_build_query($data)) . 'appidappkey'), $signature);
        $smsbao = new \plugins\sms\smsbao\SmsAdapter($http);
        self::assertSame('accepted', $smsbao->send(new SmsMessage('E2', '+8613800000000', text: '【测试】验证码 123 & +'), new Configuration(['username' => 'account', 'api_key' => 'key']))->status);
        parse_str($history[1]['request']->getUri()->getQuery(), $query);
        self::assertSame('【测试】验证码 123 & +', $query['c']);
        self::assertSame('key', $query['p']);
    }

    public function testUnknownSmsResponseDoesNotRetryAndDoesNotLeakExceptionQuery(): void
    {
        $http = \tests\support\MockChannelHttp::create([new \RuntimeException('secret leaked in URL')], $history);
        $adapter = new \plugins\sms\smsbao\SmsAdapter($http);
        $result = $adapter->send(new SmsMessage('E2', '+8613800000000', text: '【测试】验证码'), new Configuration(['username' => 'account', 'api_key' => 'key']));
        self::assertSame('unknown', $result->status);
        self::assertCount(1, $history);
        self::assertStringNotContainsString('secret', json_encode($result));
    }

    public function testCloudMailUsesSingleRecipientAndSesApprovedTemplate(): void
    {
        $http = \tests\support\MockChannelHttp::create([$this->json(['RequestId' => 'request', 'EnvId' => 'mail']), $this->json(['Response' => ['RequestId' => 'request', 'MessageId' => 'mail']])], $history);
        $message = new MailMessage('d225d6ef-95ea-4d52-a619-48a4f4d938b1', 'to@example.test', '主题', '正文', '<p>正文</p>');
        $ali = new \plugins\mail\aliyun_dm\MailAdapter($http);
        self::assertSame('accepted', $ali->send($message, new Configuration(['access_key_id' => 'id', 'access_key_secret' => 'secret', 'region' => 'cn-hangzhou', 'from_email' => 'from@example.test', 'from_name' => '发件人']))->status);
        parse_str((string) $history[0]['request']->getBody(), $rpc);
        self::assertSame('to@example.test', $rpc['ToAddress']);
        self::assertSame('SingleSendMail', $rpc['Action']);
        $tc = new \plugins\mail\tencent_ses\MailAdapter($http);
        self::assertSame('accepted', $tc->send($message, new Configuration(['secret_id' => 'id', 'secret_key' => 'secret', 'region' => 'ap-guangzhou', 'from_email' => 'from@example.test', 'template_id' => 123]))->status);
        $data = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame(['to@example.test'], $data['Destination']);
        self::assertSame(123, $data['Template']['TemplateID']);
        self::assertSame(['text' => '正文', 'html' => '<p>正文</p>', 'subject' => '主题'], json_decode($data['Template']['TemplateData'], true));
        self::assertArrayNotHasKey('Simple', $data);
    }

    public function testFaceidRequiresExplicitSuccessfulTextCodeAndReturnsNoPersonalData(): void
    {
        $http = \tests\support\MockChannelHttp::create([
            $this->json(['Response' => ['RequestId' => 'r1', 'BizToken' => 'TOKEN', 'Url' => 'https://faceid.qq.com/start']]),
            $this->json(['Response' => ['RequestId' => 'r2', 'Text' => ['ErrCode' => 0, 'Name' => '测试用户', 'IdCard' => '11010519491231002X']]]),
            $this->json(['Response' => ['RequestId' => 'r3', 'Text' => []]]),
        ], $history);
        $adapter = new \plugins\identity\tencent_faceid\IdentityAdapter($http);
        $config = new Configuration(['secret_id' => 'id', 'secret_key' => 'key', 'region' => 'ap-guangzhou', 'rule_id' => '1']);
        $result = $adapter->initialize(new IdentityRequest('REAL1', '测试用户', '11010519491231002X', 'https://merchant.example.test/return'), $config);
        self::assertSame('pending', $result->status);
        $reference = new IdentityReference('REAL1', $result->certifyId);
        $verified = $adapter->query($reference, $config);
        self::assertSame('verified', $verified->status);
        self::assertStringNotContainsString('110105', json_encode($verified));
        self::assertSame('unknown', $adapter->query($reference, $config)->status);
        $data = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame('1', $data['InfoType']);
    }

    public function testOssV2UsesSdkSigningUploadDeleteAndPresign(): void
    {
        $http = \tests\support\MockChannelHttp::create([new Response(200, ['ETag' => '"5d41402abc4b2a76b9719d911017c592"', 'x-oss-request-id' => 'req']), new Response(204, ['x-oss-request-id' => 'req'])], $history);
        $adapter = new \plugins\storage\aliyun_oss\StorageAdapter($http);
        $config = new Configuration(['access_key_id' => 'id', 'access_key_secret' => 'secret', 'region' => 'cn-hangzhou', 'bucket' => 'test-bucket']);
        $key = new ObjectKey('private/hello.txt');
        self::assertSame('stored', $adapter->put(new ObjectUpload($key, 'hello', 'text/plain'), $config)->status);
        self::assertSame('hello', (string) $history[0]['request']->getBody());
        self::assertStringStartsWith('OSS4-HMAC-SHA256', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('deleted', $adapter->delete($key, $config)->status);
        $url = $adapter->signedUrl($key, 120, $config);
        self::assertStringContainsString('x-oss-signature=', $url->url);
        self::assertStringStartsWith('https://test-bucket.oss-cn-hangzhou.aliyuncs.com/', $url->url);
        self::assertCount(2, $history);
    }

    public function testCosSignsHostAndContentAndPreservesProcessTimezone(): void
    {
        $http = \tests\support\MockChannelHttp::create([new Response(200), new Response(204)], $history);
        $adapter = new \plugins\storage\tencent_cos\StorageAdapter($http);
        $config = new Configuration(['access_key_id' => 'id', 'access_key_secret' => 'secret', 'region' => 'ap-guangzhou', 'bucket' => 'test-1250000000']);
        $key = new ObjectKey('private/test.txt');
        $timezone = date_default_timezone_get();
        self::assertSame('stored', $adapter->put(new ObjectUpload($key, 'hello', 'text/plain'), $config)->status);
        self::assertStringContainsString('q-sign-algorithm=sha1', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame(base64_encode(md5('hello', true)), $history[0]['request']->getHeaderLine('Content-MD5'));
        self::assertSame('deleted', $adapter->delete($key, $config)->status);
        self::assertStringContainsString('q-signature=', $adapter->signedUrl($key, 120, $config)->url);
        self::assertSame($timezone, date_default_timezone_get());
    }

    public function testQiniuUsesObjectScopedUploadTokenAndPrivateSignedDownload(): void
    {
        $http = \tests\support\MockChannelHttp::create([$this->json(['key' => 'private/test.txt', 'hash' => 'hash']), new Response(200)], $history);
        $adapter = new \plugins\storage\qiniu_storage\StorageAdapter($http);
        $config = new Configuration(['access_key' => 'id', 'secret_key' => 'secret', 'region' => 'z1', 'bucket' => 'test', 'download_base' => 'https://files.example.test']);
        $key = new ObjectKey('private/test.txt');
        self::assertSame('stored', $adapter->put(new ObjectUpload($key, 'hello', 'text/plain'), $config)->status);
        self::assertSame('upload-z1.qiniup.com', $history[0]['request']->getUri()->getHost());
        self::assertStringContainsString('hello', (string) $history[0]['request']->getBody());
        preg_match('/name="token"\r\n(?:[^\r]+\r\n)*\r\n([^\r]+)/', (string) $history[0]['request']->getBody(), $token);
        $parts = explode(':', $token[1]);
        $policy = json_decode(base64_decode(strtr($parts[2], '-_', '+/'), true), true);
        self::assertSame('test:private/test.txt', $policy['scope']);
        self::assertSame(strtr(base64_encode(hash_hmac('sha1', $parts[2], 'secret', true)), '+/', '-_'), $parts[1]);
        self::assertSame('text/plain', $policy['mimeLimit']);
        self::assertSame(ObjectUpload::MAX_BYTES, $policy['fsizeLimit']);
        self::assertSame('deleted', $adapter->delete($key, $config)->status);
        self::assertStringStartsWith('Qiniu ', $history[1]['request']->getHeaderLine('Authorization'));
        $canonical = 'POST ' . $history[1]['request']->getUri()->getPath() . "\nHost: rs.qiniu.com\nContent-Type: application/x-www-form-urlencoded\n\n";
        self::assertSame('Qiniu id:' . strtr(base64_encode(hash_hmac('sha1', $canonical, 'secret', true)), '+/', '-_'), $history[1]['request']->getHeaderLine('Authorization'));
        $signed = $adapter->signedUrl($key, 120, $config);
        [$download, $token] = explode('&token=', $signed->url);
        self::assertSame('id:' . strtr(base64_encode(hash_hmac('sha1', $download, 'secret', true)), '+/', '-_'), $token);
        self::assertStringContainsString('?e=' . $signed->expiresAt, $download);
    }

    public function testStorageKeysAndUntrustedDomainsAreRejectedBeforeNetwork(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectKey('../secret.env');
    }
}
