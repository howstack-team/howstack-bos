<?php

declare(strict_types=1);

namespace app\modules\finance\controller;

use app\modules\finance\application\OnlineRechargeService;
use app\shared\contract\{ClientIpResolverInterface, IpLocationResolverInterface};
use app\shared\plugin\dto\payment\PaymentNotification;
use support\annotation\route\{DisableDefaultRoute, Post};
use support\{Request, Response};

/** 第三方协议回调独立于/api统一封装，持久入账后才返回平台规定的ACK。 */
#[DisableDefaultRoute]
final readonly class PaymentNotificationController
{
    public function __construct(private OnlineRechargeService $recharges, private ClientIpResolverInterface $ips, private IpLocationResolverInterface $locations, private \app\shared\http\ApiResponse $responses) {}
    #[Post(path: '/payment-notify/{token:[a-f0-9]{64}}', name: 'payment.notification')]
    public function receive(Request $request, string $token): Response
    {
        $requestId = $this->responses->requestId($request);
        $raw = $request->rawBody();
        $json = str_contains(strtolower((string) $request->header('content-type', '')), 'application/json');
        try {
            if ($json) $notification = new PaymentNotification($raw, $request->header());
            else {
                if (!str_contains(strtolower((string) $request->header('content-type', '')), 'application/x-www-form-urlencoded') || strlen($raw) > 65536) throw new \InvalidArgumentException('通知格式无效');
                $notification = [];
                foreach (explode('&', $raw) as $pair) {
                    [$key, $value] = array_pad(explode('=', $pair, 2), 2, ''); $key = urldecode($key);
                    if ($key === '' || isset($notification[$key]) || str_contains($key, '[') || count($notification) >= 128) throw new \InvalidArgumentException('通知参数无效');
                    $notification[$key] = urldecode($value);
                }
            }
            $ip = $this->ips->resolve($request);
            $plugin = $this->recharges->notify($token, $notification, $raw, ['client_ip' => $ip, 'client_ip_location' => $this->locations->resolve($ip)]);
            return $plugin === 'json' ? new Response(204, ['Cache-Control' => 'no-store', 'X-Request-Id' => $requestId], '') : new Response(200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Request-Id' => $requestId], 'success');
        } catch (\Throwable) {
            \support\Log::warning('支付通知未处理完成，平台将按协议重试；请核查支付状态及原商户配置', ['request_id' => $requestId, 'method' => 'POST', 'path' => '/payment-notify/{token}']);
            // 不记录未经验证的正文、签名材料、收款人资料或商户配置。
            return $json ? new Response(500, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'X-Request-Id' => $requestId], '{"code":"FAIL","message":"通知处理未完成"}')
                : new Response(500, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Request-Id' => $requestId], 'failure');
        }
    }
}
