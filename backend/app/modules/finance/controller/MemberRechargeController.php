<?php

declare(strict_types=1);

namespace app\modules\finance\controller;

use app\modules\finance\application\OnlineRechargeService;
use app\modules\finance\validation\OnlineRechargeValidator;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\middleware\{MemberAuthMiddleware, MemberWriteOriginMiddleware};
use app\modules\finance\middleware\RechargeRateLimitMiddleware;
use app\shared\contract\{ClientIpResolverInterface, IpLocationResolverInterface};
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Post, RouteGroup};
use support\{Request, Response};

#[DisableDefaultRoute]
#[RouteGroup('/api/console/v1/recharges')]
#[Middleware(MemberAuthMiddleware::class)]
final readonly class MemberRechargeController
{
    public function __construct(private OnlineRechargeService $recharges, private MemberAuthenticationContext $context,
        private ApiResponse $responses, private ClientIpResolverInterface $ips, private IpLocationResolverInterface $locations) {}
    #[Get(path: '/options', name: 'member.recharges.options')]
    public function options(Request $request): Response { return $this->response($request, $this->recharges->options()); }
    #[Get(path: '', name: 'member.recharges.list')]
    public function listing(Request $request): Response {
        $p = OnlineRechargeValidator::page($request->get());
        return $this->response($request, $this->recharges->listing($this->context->account($request)->id, (int) $p['page'], (int) $p['page_size']));
    }
    #[Post(path: '', name: 'member.recharges.create')]
    #[Middleware(MemberWriteOriginMiddleware::class, RechargeRateLimitMiddleware::class)]
    public function create(Request $request): Response {
        $v = OnlineRechargeValidator::create($request->post()); $ip = $this->ips->resolve($request);
        return $this->response($request, $this->recharges->create($this->context->account($request)->id, (int) $v['channel_id'], (int) $v['amount_minor'], $v['request_key'], ['client_ip' => $ip, 'client_ip_location' => $this->locations->resolve($ip)]));
    }
    #[Get(path: '/{id:\d+}', name: 'member.recharges.detail')]
    public function detail(Request $request, mixed $id): Response { return $this->response($request, $this->recharges->detail($this->context->account($request)->id, OnlineRechargeValidator::id($id))); }
    #[Post(path: '/{id:\d+}/query', name: 'member.recharges.query')]
    #[Middleware(MemberWriteOriginMiddleware::class, RechargeRateLimitMiddleware::class)]
    public function query(Request $request, mixed $id): Response { return $this->response($request, $this->recharges->reconcile($this->context->account($request)->id, OnlineRechargeValidator::id($id))); }
    #[Post(path: '/{id:\d+}/close', name: 'member.recharges.close')]
    #[Middleware(MemberWriteOriginMiddleware::class, RechargeRateLimitMiddleware::class)]
    public function close(Request $request, mixed $id): Response { return $this->response($request, $this->recharges->reconcile($this->context->account($request)->id, OnlineRechargeValidator::id($id), true)); }
    private function response(Request $request, mixed $data): Response { return $this->responses->success($request, $data)->withHeaders(['Cache-Control' => 'no-store']); }
}
