<?php

declare(strict_types=1);

namespace tests\unit\middleware;

use app\exception\BusinessException;
use app\middleware\CommunityReleaseMiddleware;
use app\modules\website\domain\WebsiteNavigationPolicy;
use PHPUnit\Framework\TestCase;
use support\Request;
use support\Response;

/** 首发边界必须拒绝新受理，同时允许原业务收尾继续经过鉴权链。 */
final class CommunityReleaseMiddlewareTest extends TestCase
{
    public function testDeferredWritesNeverReachBusinessHandler(): void
    {
        foreach ([
            ['POST', '/api/admin/v1/finance/invoices'],
            ['POST', '/api/admin/v1/themes/install'], ['DELETE', '/api/admin/v1/themes/12'],
            ['POST', '/api/admin/v1/member-levels'], ['PUT', '/api/admin/v1/member-levels/3'],
            ['DELETE', '/api/admin/v1/member-levels/3'],
            ['POST', '/api/admin/v1/application-center/install'], ['POST', '/api/admin/v1/application-center/prepare'],
            ['POST', '/api/admin/v1/system-updates/check'], ['POST', '/api/admin/v1/system-updates/start'],
            ['POST', '/api/admin/v1/official-platform/license/activate'],
        ] as [$method, $path]) {
            $called = false;
            try {
                (new CommunityReleaseMiddleware())->process(new Request("{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n"), function () use (&$called): Response { $called = true; return new Response(); });
                self::fail($path . ' 不应受理');
            } catch (BusinessException $error) {
                self::assertSame(404, $error->httpStatus());
                self::assertFalse($called);
            }
        }
    }

    public function testReadsCallbacksAndExistingWorkContinueThroughNormalAuthorization(): void
    {
        foreach ([
            ['GET', '/api/admin/v1/finance/invoices'], ['GET', '/api/admin/v1/member-levels'],
            ['POST', '/api/admin/v1/finance/invoices/1/red'], ['POST', '/api/admin/v1/finance/withdrawals/1/payout'],
            ['POST', '/api/admin/v1/finance/withdrawals/'], ['POST', '/api/admin/v1/finance/recharges'], ['POST', '/api/admin/v1/finance/refunds'],
            ['POST', '/api/console/v1/orders/1/pay'], ['POST', '/payment-notify/fixed-test'],
            ['POST', '/api/admin/v1/application-center/uninstall'], ['POST', '/api/admin/v1/tickets/1/replies'],
            ['PUT', '/api/admin/v1/themes/bindings'], ['PUT', '/api/admin/v1/themes/1/components/website/settings'],
            ['POST', '/api/admin/v1/official-platform/connection/disconnect'],
        ] as [$method, $path]) {
            $response = (new CommunityReleaseMiddleware())->process(new Request("{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n"), fn (): Response => new Response(403, [], 'normal authorization'));
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('normal authorization', $response->rawBody());
        }
    }

    public function testWebsiteSkipsDeferredLocalLinksWithoutRemovingOtherSitesOrTickets(): void
    {
        $policy = new WebsiteNavigationPolicy();
        foreach (['https://example.test/console/finance/invoices?from=nav', 'https://example.test/console/account/security/', '/console/services/physical-servers/1', 'https://example.test/console/finance/%72efunds'] as $url) {
            self::assertFalse($policy->allows($url, 'https://example.test'));
        }
        foreach (['/console/support/tickets', 'https://example.test/console/services/cloud-servers', 'https://another.test/console/finance/invoices', '/products/cloud-server', '/company', '/console/account/messages', '/console/finance/transactions'] as $url) {
            self::assertTrue($policy->allows($url, 'https://example.test'));
        }
    }
}
