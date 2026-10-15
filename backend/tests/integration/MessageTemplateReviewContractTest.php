<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\channel\application\{ChannelExecutionConfiguration, MessageTemplateReviewService};
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\channel\infrastructure\{InstalledChannelPlatformRegistry, InstalledTransactionAdapterFactory};
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\modules\plugin\infrastructure\LocalPluginPackageRegistry;
use app\shared\plugin\dto\template\{TemplateReviewReference, TemplateSubmission};
use app\shared\plugin\exception\PluginException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\{Request, Response};
use PHPUnit\Framework\TestCase;

/** 加载、实际调用、账号隔离及未知结果测试；不使用真实厂商或账号。 */
final class MessageTemplateReviewContractTest extends TestCase
{
    private string $root;
    private LocalPluginPackageRegistry $packages;
    private array $installed;
    private array $connections;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/howstack-template-review-' . bin2hex(random_bytes(6));
        foreach (['review_sms' => ['sms', true, true], 'review_mail' => ['mail', true, true],
            'submit_mail' => ['mail', true, false], 'query_sms' => ['sms', false, true], 'plain_sms' => ['sms', false, false]] as $id => [$category, $submit, $query]) {
            $this->package($category, $id, $submit, $query);
        }
        $this->packages = new LocalPluginPackageRegistry($this->root, '0.1.0');
        self::assertSame([], $this->packages->failures());
        foreach ($this->packages->packages() as $id => $package) {
            $this->installed[$id] = ['category' => $package['category'], 'state' => 'enabled', 'package_hash' => $package['package_hash']];
        }
        $this->connections = [
            'sms:1' => ['plugin_id' => 'review_sms', 'revision' => 3, 'configuration_current' => true, 'platform_available' => true, 'status' => 1],
            'sms:2' => ['plugin_id' => 'review_sms', 'revision' => 5, 'configuration_current' => true, 'platform_available' => true, 'status' => 1],
            'mail:1' => ['plugin_id' => 'review_mail', 'revision' => 3, 'configuration_current' => true, 'platform_available' => true, 'status' => 1],
        ];
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testCapabilitiesComeFromOptionalInterfacesAndRemainIndependent(): void
    {
        [$factory, $platforms] = $this->runtime([]);
        self::assertSame(['submit' => true, 'query' => true], $platforms->definition('mail', 'review_mail')['template_review']);
        self::assertSame(['submit' => true, 'query' => false], $platforms->definition('mail', 'submit_mail')['template_review']);
        self::assertSame(['submit' => false, 'query' => true], $platforms->definition('sms', 'query_sms')['template_review']);
        self::assertSame(['submit' => false, 'query' => false], $platforms->definition('sms', 'plain_sms')['template_review']);
        $factory->templateSubmission('mail', 'submit_mail');
        $factory->templateReviewQuery('sms', 'query_sms');
        foreach ([['mail', 'submit_mail', 'templateReviewQuery'], ['sms', 'query_sms', 'templateSubmission'], ['sms', 'plain_sms', 'templateSubmission'], ['payment', 'review_sms', 'templateSubmission']] as [$category, $id, $method]) {
            try { $factory->{$method}($category, $id); self::fail('未实现或类别不支持的能力必须拒绝'); }
            catch (PluginException $error) { self::assertSame('capability_unsupported', $error->reason); }
        }
    }

    public function testBothChannelsSubmitAndQueryWithOriginalAccountAndStableRequestKey(): void
    {
        $history = [];
        [$factory, , $channels] = $this->runtime([
            new Response(202, [], '{"reference":"application-1"}'),
            new Response(200, [], '{"status":"pending"}'),
            new Response(202, [], '{"reference":"application-2"}'),
            new Response(200, [], '{"status":"approved","template_id":"SMS_FINAL_1"}'),
            new Response(202, [], '{"reference":"mail-application"}'),
            new Response(200, [], '{"status":"approved","template_id":"mail-template"}'),
        ], $history);
        $service = new MessageTemplateReviewService(new ChannelExecutionConfiguration($channels), $factory);
        $request = new TemplateSubmission('template-revision-7', 'sms', '验证码模板', 'verification', '您的验证码为 {{code}}', variables: ['code' => '123456']);
        self::assertSame('accepted', $service->submit(1, 3, $request)->status);
        self::assertSame('pending', $service->query(1, 3, new TemplateReviewReference('sms', 'application-1'))->status);
        self::assertSame('application-2', $service->submit(2, 5, $request)->providerReference);
        self::assertSame('SMS_FINAL_1', $service->query(2, 5, new TemplateReviewReference('sms', 'application-2'))->providerTemplateId);
        $mail = new TemplateSubmission('mail-revision-2', 'mail', '邮件模板', 'notification', '<p>{{name}}，您好</p>', '服务通知', 'html', ['name' => '示例用户']);
        self::assertSame('accepted', $service->submit(1, 3, $mail)->status);
        self::assertSame('mail-template', $service->query(1, 3, new TemplateReviewReference('mail', 'mail-application'))->providerTemplateId);
        self::assertCount(6, $history);
        self::assertSame(['POST', 'GET', 'POST', 'GET', 'POST', 'GET'], array_map(static fn (array $row): string => $row['request']->getMethod(), $history));
        self::assertSame('Bearer account-1', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer account-2', $history[2]['request']->getHeaderLine('Authorization'));
        self::assertSame('review-2.example.test', $history[2]['request']->getUri()->getHost());
        self::assertSame($request->key, json_decode((string) $history[0]['request']->getBody(), true)['request_key']);
        self::assertSame($request->key, json_decode((string) $history[2]['request']->getBody(), true)['request_key']);
        self::assertSame('html', json_decode((string) $history[4]['request']->getBody(), true)['format']);
    }

    public function testTimeoutAndUnknownStatusDoNotBecomeRejectionOrTriggerResubmission(): void
    {
        $history = [];
        [$factory, , $channels] = $this->runtime([
            new ConnectException('模拟超时', new Request('POST', 'https://review.example.test/templates')),
            new Response(200, [], '{"status":"vendor_unrecognized"}'),
            new Response(400, [], '{"raw_secret":"do-not-expose"}'),
            new Response(404),
            new Response(200, [], '{"status":"rejected","raw_reason":"do-not-expose"}'),
        ], $history);
        $service = new MessageTemplateReviewService(new ChannelExecutionConfiguration($channels), $factory);
        $request = new TemplateSubmission('template-1', 'sms', '通知', 'notification', '服务已开通');
        self::assertSame('unknown', $service->submit(1, 3, $request)->status);
        self::assertSame('unknown', $service->query(1, 3, new TemplateReviewReference('sms', 'application'))->status);
        $failed = $service->submit(1, 3, $request);
        self::assertSame('failed', $failed->status);
        self::assertStringNotContainsString('do-not-expose', $failed->reason);
        self::assertSame('not_found', $service->query(1, 3, new TemplateReviewReference('sms', 'application'))->status);
        self::assertSame('rejected', $service->query(1, 3, new TemplateReviewReference('sms', 'application'))->status);
        self::assertCount(5, $history, '每个方法只发起一次外部请求，未知状态不能隐式重新提交');
    }

    public function testDisabledChannelPermitsQueryButRejectsNewSubmissionAndStaleRevision(): void
    {
        $history = [];
        [$factory, , $channels] = $this->runtime([new Response(200, [], '{"status":"pending"}')], $history);
        $this->connections['sms:1']['status'] = 0;
        $service = new MessageTemplateReviewService(new ChannelExecutionConfiguration($channels), $factory);
        self::assertSame('pending', $service->query(1, 3, new TemplateReviewReference('sms', 'application'))->status);
        try { $service->submit(1, 3, new TemplateSubmission('template-1', 'sms', '通知', 'notification', '服务已开通')); self::fail('停用通道不能接收新审核申请'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        try { $service->query(1, 2, new TemplateReviewReference('sms', 'application')); self::fail('不能使用其他修订的账号配置查询'); }
        catch (BusinessException) { self::assertCount(1, $history); }
    }

    public function testDisabledOrChangedPluginCannotExecuteReview(): void
    {
        [$factory, $platforms] = $this->runtime([]);
        $this->installed['review_mail']['state'] = 'disabled';
        self::assertNull($platforms->definition('mail', 'review_mail'));
        try { $factory->templateSubmission('mail', 'review_mail'); self::fail('停用插件不得执行'); }
        catch (PluginException $error) { self::assertSame('plugin_unavailable', $error->reason); }
        $this->installed['review_sms']['package_hash'] = str_repeat('a', 64);
        $this->expectException(PluginException::class);
        $factory->templateReviewQuery('sms', 'review_sms');
    }

    public function testQueryCannotReturnAnotherApplicationReference(): void
    {
        [$factory, , $channels] = $this->runtime([new Response(200, [], '{"status":"approved","reference":"other-application","template_id":"template-1"}')]);
        $service = new MessageTemplateReviewService(new ChannelExecutionConfiguration($channels), $factory);
        try { $service->query(1, 3, new TemplateReviewReference('sms', 'application')); self::fail('查询结果必须匹配原申请'); }
        catch (PluginException $error) { self::assertSame('invalid_result', $error->reason); }
    }

    public function testUnexpectedAdapterExceptionKeepsUnknownSemanticsAndCause(): void
    {
        [, , $channels] = $this->runtime([]);
        $cause = new \RuntimeException('模拟平台响应解析失败');
        $adapter = $this->createStub(\app\shared\plugin\contract\SubmitTemplateAdapterInterface::class);
        $adapter->method('submitTemplate')->willThrowException($cause);
        $factory = $this->createStub(\app\modules\channel\contract\TransactionAdapterFactoryInterface::class);
        $factory->method('templateSubmission')->willReturn($adapter);
        $query = $this->createStub(\app\shared\plugin\contract\QueryTemplateReviewAdapterInterface::class);
        $query->method('queryTemplateReview')->willThrowException($cause);
        $factory->method('templateReviewQuery')->willReturn($query);
        $service = new MessageTemplateReviewService(new ChannelExecutionConfiguration($channels), $factory);
        foreach ([
            fn () => $service->submit(1, 3, new TemplateSubmission('template-1', 'sms', '通知', 'notification', '服务已开通')),
            fn () => $service->query(1, 3, new TemplateReviewReference('sms', 'application')),
        ] as $call) {
            try { $call(); self::fail('异常不能被当作已确认失败'); }
            catch (PluginException $error) {
                self::assertSame('result_unknown', $error->reason);
                self::assertSame($cause, $error->getPrevious());
                self::assertStringNotContainsString($cause->getMessage(), $error->getMessage());
            }
        }
    }

    /** @param list<mixed> $responses MockHandler 响应 @param array|null $history 无外部请求的历史 @return array{InstalledTransactionAdapterFactory, InstalledChannelPlatformRegistry, ChannelRepositoryInterface} 测试执行链 */
    private function runtime(array $responses, ?array &$history = null): array
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        if ($history !== null) $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);
        $installations = $this->createStub(PluginInstallationRepositoryInterface::class);
        $installations->method('all')->willReturnCallback(fn (): array => $this->installed);
        $platforms = new InstalledChannelPlatformRegistry($this->packages, $installations, $http);
        $factory = new InstalledTransactionAdapterFactory($this->packages, $platforms, $http);
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('find')->willReturnCallback(fn (string $category, int $id): array => $this->connections[$category . ':' . $id]);
        $channels->method('configuration')->willReturnCallback(static fn (string $category, int $id): array => ['api_url' => 'https://review-' . $id . '.example.test', 'api_key' => 'account-' . $id]);
        return [$factory, $platforms, $channels];
    }

    /** @param string $category 固定类别 @param string $id 测试包身份 @param bool $submit 提交接口 @param bool $query 查询接口 */
    private function package(string $category, string $id, bool $submit, bool $query): void
    {
        $folder = $this->root . '/' . $category . '/' . $id;
        mkdir($folder, 0700, true);
        $type = $category === 'sms' ? 'Sms' : 'Mail';
        $entry = $type . 'Adapter';
        $interfaces = ['\\app\\shared\\plugin\\contract\\' . $entry . 'Interface'];
        if ($submit) $interfaces[] = '\\app\\shared\\plugin\\contract\\SubmitTemplateAdapterInterface';
        if ($query) $interfaces[] = '\\app\\shared\\plugin\\contract\\QueryTemplateReviewAdapterInterface';
        $source = strtr(file_get_contents(dirname(__DIR__) . '/fixtures/template_review/Adapter.php'), [
            '__CATEGORY__' => $category, '__PLUGIN_ID__' => $id, '__ENTRY__' => $entry, '__INTERFACES__' => implode(', ', $interfaces),
            '__MESSAGE__' => '\\app\\shared\\plugin\\dto\\' . $category . '\\' . $type . 'Message',
            '__SEND_RESULT__' => '\\app\\shared\\plugin\\dto\\' . $category . '\\' . $type . 'Result',
        ]);
        file_put_contents($folder . '/' . $entry . '.php', $source);
        if (!class_exists('plugins\\' . $category . '\\' . $id . '\\' . $entry, false)) require $folder . '/' . $entry . '.php';
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/plugins/' . $category . '/' . ($category === 'sms' ? 'aliyun_sms' : 'smtp') . '/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['id'] = $id;
        $manifest['network'] = ['transport' => 'https'];
        file_put_contents($folder . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($folder . '/configuration.schema.json', json_encode(['schema_version' => '1.0', 'scope' => 'connection', 'fields' => [
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'url', 'required' => true, 'max_length' => 512],
            ['key' => 'api_key', 'label' => '密钥', 'type' => 'password', 'required' => true, 'max_length' => 128],
        ]], JSON_THROW_ON_ERROR));
    }
}
