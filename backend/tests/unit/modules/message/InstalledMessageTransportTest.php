<?php

declare(strict_types=1);

namespace tests\unit\modules\message;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\modules\channel\contract\mail\MailAdapterExecutorInterface;
use app\modules\message\infrastructure\InstalledMessageTransport;
use app\modules\system_settings\contract\NotificationRobotSettingsRepositoryInterface;
use app\shared\plugin\contract\SmsAdapterInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use app\shared\plugin\dto\sms\SmsMessage;
use app\shared\plugin\dto\sms\SmsResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** 真实宿主桥接与 DTO，通过隔离厂商替身核对发送参数；不访问外部服务。 */
final class InstalledMessageTransportTest extends TestCase
{
    private const string KEY = 'd6ae3c31-581c-481a-96b5-565b7891dc94';

    private function configuration(int $revision = 2): ChannelExecutionConfiguration
    {
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('find')->willReturn(['revision' => $revision, 'configuration_current' => true,
            'platform_available' => true, 'status' => 1, 'plugin_id' => 'test_provider']);
        $channels->method('configuration')->willReturn(['token' => 'isolated-test-token']);
        return new ChannelExecutionConfiguration($channels);
    }

    private function robots(): NotificationRobotSettingsRepositoryInterface
    {
        $robots = $this->createStub(NotificationRobotSettingsRepositoryInterface::class);
        $robots->method('get')->willReturn(['revision' => 3, 'robots' => []]);
        return $robots;
    }

    private function payload(): array
    {
        return ['channel_id' => 7, 'channel_revision' => 2, 'title' => '测试主题', 'body' => '测试内容',
            'address' => '13800138001', 'template_id' => 'TEST_TEMPLATE', 'parameters' => ['order_no' => 'ORDER-TEST']];
    }

    public function testSmsUsesInstalledAdapterAndPreservesRequestKeyAndTemplateParameters(): void
    {
        $sms = $this->createMock(SmsAdapterInterface::class);
        $sms->expects(self::once())->method('send')->willReturnCallback(static function (SmsMessage $message, Configuration $configuration): SmsResult {
            self::assertSame(self::KEY, $message->key);
            self::assertSame('+8613800138001', $message->recipient);
            self::assertSame('TEST_TEMPLATE', $message->templateId);
            self::assertSame(['order_no' => 'ORDER-TEST'], $message->parameters);
            return new SmsResult('accepted', $message->key, 'isolated-receipt');
        });
        $adapters = $this->createMock(TransactionAdapterFactoryInterface::class);
        $adapters->expects(self::once())->method('sms')->with('test_provider')->willReturn($sms);
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        $transport = new InstalledMessageTransport($this->configuration(), $this->createStub(MailAdapterExecutorInterface::class),
            $adapters, $http, $this->robots());
        self::assertSame(['status' => 'accepted', 'error' => '', 'retry' => false], $transport->send('sms', self::KEY, $this->payload()));
    }

    public function testMailUsesStableMessageIdAndTimeoutIsUnknown(): void
    {
        $mail = $this->createMock(MailAdapterExecutorInterface::class);
        $calls = 0;
        $mail->expects(self::exactly(2))->method('send')->willReturnCallback(static function (string $plugin, Configuration $configuration, MailMessage $message) use (&$calls): MailResult {
            self::assertSame('<' . self::KEY . '@howstack.local>', $message->messageId());
            self::assertSame('recipient@example.invalid', $message->recipient);
            if (++$calls === 2) throw new \RuntimeException('模拟超时含敏感响应，不能返回给管理日志');
            return new MailResult('accepted', $message->messageId());
        });
        $transport = new InstalledMessageTransport($this->configuration(), $mail, $this->createStub(TransactionAdapterFactoryInterface::class),
            $this->createStub(ClientInterface::class), $this->robots());
        $payload = array_replace($this->payload(), ['address' => 'recipient@example.invalid']);
        self::assertSame('accepted', $transport->send('email', self::KEY, $payload)['status']);
        self::assertSame(['status' => 'unknown', 'error' => 'transport_outcome_unknown', 'retry' => false], $transport->send('email', self::KEY, $payload));
    }

    public function testChangedChannelRevisionStopsBeforeAnyExternalRequest(): void
    {
        $mail = $this->createMock(MailAdapterExecutorInterface::class);
        $mail->expects(self::never())->method('send');
        $adapters = $this->createMock(TransactionAdapterFactoryInterface::class);
        $adapters->expects(self::never())->method('sms');
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        $transport = new InstalledMessageTransport($this->configuration(3), $mail, $adapters, $http, $this->robots());
        self::assertSame(['status' => 'failed', 'error' => 'channel_or_recipient_invalid', 'retry' => false], $transport->send('sms', self::KEY, $this->payload()));
    }

    public function testFixedRobotEndpointsSignaturesAndNoRedirectOrTlsBypass(): void
    {
        $urls = ['dingtalk' => 'https://oapi.dingtalk.com/robot/send?access_token=TEST_TOKEN',
            'wecom' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=TEST_KEY',
            'feishu' => 'https://open.feishu.cn/open-apis/bot/v2/hook/00000000-0000-4000-8000-000000000000'];
        foreach ($urls as $channel => $url) {
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::once())->method('request')->willReturnCallback(static function (string $method, string $actualUrl, array $options) use ($channel, $url): Response {
                self::assertSame('POST', $method);
                self::assertTrue($options['verify']);
                self::assertFalse($options['allow_redirects']);
                self::assertSame(10, $options['timeout']);
                if ($channel === 'dingtalk') {
                    parse_str(parse_url($actualUrl, PHP_URL_QUERY), $query);
                    self::assertSame(base64_encode(hash_hmac('sha256', $query['timestamp'] . "\nTEST_SECRET", 'TEST_SECRET', true)), $query['sign']);
                } elseif ($channel === 'feishu') {
                    self::assertSame($url, $actualUrl);
                    self::assertSame(base64_encode(hash_hmac('sha256', '', $options['json']['timestamp'] . "\nTEST_SECRET", true)), $options['json']['sign']);
                } else self::assertSame($url, $actualUrl);
                return new Response(200, [], json_encode([$channel === 'feishu' ? 'code' : 'errcode' => 0], JSON_THROW_ON_ERROR));
            });
            $transport = new InstalledMessageTransport($this->configuration(), $this->createStub(MailAdapterExecutorInterface::class),
                $this->createStub(TransactionAdapterFactoryInterface::class), $http, $this->robots());
            self::assertSame('accepted', $transport->send($channel, self::KEY, ['title' => '测试', 'body' => '通知', 'robot_revision' => 3,
                'robot' => ['webhook_url' => $url, 'sign_secret' => 'TEST_SECRET']])['status']);
        }
    }

    public function testRobotChangedRevisionInvalidResponseAndRejectionAreDistinct(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturn(new Response(200, [], 'invalid'), new Response(200, [], '{"errcode":40014}'));
        $transport = new InstalledMessageTransport($this->configuration(), $this->createStub(MailAdapterExecutorInterface::class),
            $this->createStub(TransactionAdapterFactoryInterface::class), $http, $this->robots());
        $payload = ['title' => '测试', 'body' => '通知', 'robot_revision' => 2,
            'robot' => ['webhook_url' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=TEST_KEY']];
        self::assertSame('robot_configuration_changed', $transport->send('wecom', self::KEY, $payload)['error']);
        $payload['robot_revision'] = 3;
        self::assertSame('unknown', $transport->send('wecom', self::KEY, $payload)['status']);
        self::assertSame(['status' => 'failed', 'error' => 'robot_rejected', 'retry' => false], $transport->send('wecom', self::KEY, $payload));
    }
}
