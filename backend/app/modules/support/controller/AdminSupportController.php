<?php

declare(strict_types=1);

namespace app\modules\support\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\support\application\ManageSupportService;
use app\modules\support\validation\SupportValidator;
use app\shared\http\ApiResponse;
use app\shared\validation\TableListQueryValidator;
use support\annotation\Middleware;
use support\annotation\route\Delete;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\Put;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminSupportController
{
    public function __construct(private ManageSupportService $service, private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    #[Get(path: '/ticket-catalog/{kind:types|quick-replies}', name: 'admin.ticket-catalog.index')]
    public function catalog(Request $request, string $kind): Response
    {
        $query = SupportValidator::page($request->get());
        return $this->responses->success($request, $this->service->catalog(
            $this->context->account($request), $kind,
            $query['keyword'], $query['status'] === null ? null : (int) $query['status'], $query['page'], $query['page_size'],
            TableListQueryValidator::validate($request->get(), ['name' => 64, 'code' => 64, 'title' => 128, 'content' => 128], ['sort_order', 'created_at']),
        ));
    }

    #[Post(path: '/ticket-catalog/{kind:types|quick-replies}', name: 'admin.ticket-catalog.store')]
    public function store(Request $request, string $kind): Response
    {
        return $this->responses->success($request, $this->service->saveCatalog($this->context->account($request), $kind,
            null, SupportValidator::catalog($kind, $request->post())), '创建成功', 201);
    }

    #[Put(path: '/ticket-catalog/{kind:types|quick-replies}/{id:\d+}', name: 'admin.ticket-catalog.update')]
    public function update(Request $request, string $kind, mixed $id): Response
    {
        return $this->responses->success($request, $this->service->saveCatalog($this->context->account($request), $kind,
            SupportValidator::id($id), SupportValidator::catalog($kind, $request->post())), '更新成功');
    }

    #[Delete(path: '/ticket-catalog/{kind:types|quick-replies}/{id:\d+}', name: 'admin.ticket-catalog.destroy')]
    public function destroy(Request $request, string $kind, mixed $id): Response
    {
        $this->service->deleteCatalog($this->context->account($request), $kind, SupportValidator::id($id));
        return $this->responses->success($request, null, '删除成功');
    }

    #[Get(path: '/tickets', name: 'admin.tickets.index')]
    public function tickets(Request $request): Response
    {
        $query = SupportValidator::page($request->get(), true);
        return $this->responses->success($request, $this->service->tickets(
            $this->context->account($request),
            $query['keyword'], $query['status'], $query['page'], $query['page_size'],
            TableListQueryValidator::validate($request->get(), ['ticket_no' => 64, 'subject' => 128, 'member_mobile' => 20, 'member_nickname' => 64], ['updated_at', 'created_at'], ['priority' => ['low', 'normal', 'high', 'urgent']]),
        ));
    }

    #[Get(path: '/tickets/{id:\d+}', name: 'admin.tickets.show')]
    public function ticket(Request $request, mixed $id): Response
    {
        return $this->responses->success($request, $this->service->ticket($this->context->account($request), SupportValidator::id($id)));
    }

    #[Post(path: '/tickets/{id:\d+}/replies', name: 'admin.tickets.reply')]
    public function reply(Request $request, mixed $id): Response
    {
        $this->service->reply($this->context->account($request), SupportValidator::id($id), SupportValidator::reply($request->post()));
        return $this->responses->success($request, null, '回复成功');
    }
}
