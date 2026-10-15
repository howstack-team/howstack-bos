<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\application\oauth\OAuthChannelService;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\modules\channel\infrastructure\oauth\RedisOAuthStateStore;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\exception\PluginException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use support\Redis;
use tests\support\MockChannelHttp;

require_once dirname(__DIR__) . '/support/MockChannelHttp.php';

/** OAuth 官方协议和生产 Redis 原子状态；不创建真实会员或管理员登录。 */
final class OAuthChannelsTest extends TestCase
{
    private function wechatConfig(): Configuration
    {
        return new Configuration(['app_id' => 'wx1234567890abcdef', 'app_secret' => 'secret', 'redirect_url' => 'https://merchant.example.test/oauth/wechat']);
    }

    /**
     * @param array<string, mixed> $data 回复
     * @return Response JSON 回复
     */
    private function reply(array $data): Response
    {
        return new Response(200, [], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testWechatAuthorizesWebsiteScopeAndVerifiesProfileMatchesToken(): void
    {
        $http = MockChannelHttp::create([
            $this->reply(['access_token' => 'TOKEN', 'expires_in' => 7200, 'openid' => 'OPENID', 'unionid' => 'UNIONID', 'scope' => 'snsapi_login']),
            $this->reply(['openid' => 'OPENID', 'unionid' => 'UNIONID', 'nickname' => '测试用户', 'headimgurl' => 'https://images.example.test/avatar']),
        ], $history);
        $adapter = new \plugins\oauth\wechat_login\OAuthAdapter($http);
        $url = $adapter->authorize(str_repeat('a', 64), $this->wechatConfig());
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('snsapi_login', $query['scope']);
        self::assertSame('https://merchant.example.test/oauth/wechat', $query['redirect_uri']);
        self::assertSame(str_repeat('a', 64), $query['state']);
        $identity = $adapter->authenticate('CODE', $this->wechatConfig());
        self::assertSame('OPENID', $identity->subject);
        self::assertSame('UNIONID', $identity->unionId);
        self::assertStringNotContainsString('TOKEN', json_encode($identity));
        self::assertSame('api.weixin.qq.com', $history[0]['request']->getUri()->getHost());
    }

    public function testWechatRejectsMismatchedUserAndDropsCredentialBearingException(): void
    {
        $http = MockChannelHttp::create([
            $this->reply(['access_token' => 'TOKEN', 'expires_in' => 7200, 'openid' => 'OPENID', 'scope' => 'snsapi_login']),
            $this->reply(['openid' => 'OTHER', 'nickname' => '其他用户']),
        ], $history);
        try {
            (new \plugins\oauth\wechat_login\OAuthAdapter($http))->authenticate('CODE', $this->wechatConfig());
            self::fail('平台用户不匹配不得通过');
        } catch (PluginException $error) {
            self::assertSame('oauth_failed', $error->reason);
            self::assertNull($error->getPrevious());
        }
    }

    public function testQqUsesOfficialFormTokenAndParsesJsonpWithoutExecutingIt(): void
    {
        $http = MockChannelHttp::create([
            new Response(200, [], 'access_token=TOKEN&expires_in=7776000&refresh_token=REFRESH'),
            new Response(200, [], 'callback( {"client_id":"12345678","openid":"OPENID"} );'),
            $this->reply(['ret' => 0, 'nickname' => 'QQ 用户', 'figureurl_qq_2' => 'http://images.example.test/avatar']),
        ], $history);
        $adapter = new \plugins\oauth\qq_login\OAuthAdapter($http);
        $config = new Configuration(['app_id' => '12345678', 'app_secret' => 'secret', 'redirect_url' => 'https://merchant.example.test/oauth/qq']);
        $identity = $adapter->authenticate('CODE', $config);
        self::assertSame('qq_login', $identity->provider);
        self::assertSame('12345678', $identity->appId);
        self::assertSame('OPENID', $identity->subject);
        self::assertNull($identity->avatar);
        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame($config->values['redirect_url'], $query['redirect_uri']);
        self::assertStringNotContainsString('TOKEN', json_encode($identity));
    }

    public function testQqRejectsCallbackFromDifferentApplication(): void
    {
        $http = MockChannelHttp::create([
            new Response(200, [], 'access_token=TOKEN&expires_in=7776000'),
            new Response(200, [], 'callback({"client_id":"99999999","openid":"OPENID"});'),
        ], $history);
        $this->expectException(PluginException::class);
        (new \plugins\oauth\qq_login\OAuthAdapter($http))->authenticate('CODE', new Configuration(['app_id' => '12345678', 'app_secret' => 'secret', 'redirect_url' => 'https://merchant.example.test/oauth/qq']));
    }

    public function testProductionStateIsBrowserBoundExpiresAndCanOnlyBeConsumedOnce(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') {
            self::markTestSkipped('显式允许本地基础设施测试时才访问 Redis');
        }
        $states = new RedisOAuthStateStore();
        $binding = str_repeat('b', 64);
        $state = $states->issue(['channel_id' => 1, 'revision' => 2], $binding);
        try {
            self::assertLessThanOrEqual(300, Redis::ttl('channel:oauth:v1:' . $state));
            self::assertNull($states->consume($state, str_repeat('c', 64)));
            self::assertSame(['channel_id' => 1, 'revision' => 2], $states->consume($state, $binding));
            self::assertNull($states->consume($state, $binding));
        } finally {
            Redis::del('channel:oauth:v1:' . $state);
        }
    }

    public function testHostRejectsInvalidStateBeforeExchangingCode(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') {
            self::markTestSkipped('使用本地 Redis 进行一次性状态测试');
        }
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $adapters = $this->createMock(TransactionAdapterFactoryInterface::class);
        $adapters->expects(self::never())->method('oauth');
        $service = new OAuthChannelService(new ChannelExecutionConfiguration($channels), $adapters, new RedisOAuthStateStore());
        $this->expectException(PluginException::class);
        $service->complete(str_repeat('d', 64), 'CODE', str_repeat('b', 64));
    }
}
