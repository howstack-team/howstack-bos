<?php

declare(strict_types=1);

namespace app\modules\content\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\content\application\ManageContentService;
use app\modules\content\validation\ContentValidator;
use app\shared\http\ApiResponse;
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
final readonly class AdminContentController
{
    public function __construct(private ManageContentService $service, private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    #[Get(path: '/content/{kind:help|news}/{resource:categories|entries}', name: 'admin.content.index')]
    public function index(Request $request, string $kind, string $resource): Response
    {
        $categories = $resource === 'categories';
        $query = ContentValidator::page($request->get(), $categories);
        return $this->responses->success($request, $this->service->paginate($this->context->account($request), $kind, $categories,
            $query['keyword'], $query['status'], $query['page'], $query['page_size']));
    }

    #[Get(path: '/content/{kind:help|news}/{resource:categories|entries}/{id:\d+}', name: 'admin.content.show')]
    public function show(Request $request, string $kind, string $resource, mixed $id): Response
    {
        return $this->responses->success($request, $this->service->detail($this->context->account($request),
            $kind, $resource === 'categories', ContentValidator::id($id)));
    }

    #[Post(path: '/content/{kind:help|news}/{resource:categories|entries}', name: 'admin.content.store')]
    public function store(Request $request, string $kind, string $resource): Response
    {
        $categories = $resource === 'categories';
        return $this->responses->success($request, $this->service->save($this->context->account($request), $kind,
            $categories, null, ContentValidator::body($request->post(), $categories)), '创建成功', 201);
    }

    #[Put(path: '/content/{kind:help|news}/{resource:categories|entries}/{id:\d+}', name: 'admin.content.update')]
    public function update(Request $request, string $kind, string $resource, mixed $id): Response
    {
        $categories = $resource === 'categories';
        return $this->responses->success($request, $this->service->save($this->context->account($request), $kind,
            $categories, ContentValidator::id($id), ContentValidator::body($request->post(), $categories)), '更新成功');
    }

    #[Delete(path: '/content/{kind:help|news}/{resource:categories|entries}/{id:\d+}', name: 'admin.content.destroy')]
    public function destroy(Request $request, string $kind, string $resource, mixed $id): Response
    {
        $this->service->delete($this->context->account($request), $kind, $resource === 'categories', ContentValidator::id($id));
        return $this->responses->success($request, null, '删除成功');
    }
}
