<?php

declare(strict_types=1);

namespace app\modules\member\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\member\application\MemberCatalogManagementService;
use app\modules\member\validation\MemberCatalogValidator;
use app\modules\member\validation\MemberValidator;
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

/** 提供会员分组与等级后台接口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminMemberCatalogController
{
    public function __construct(private MemberCatalogManagementService $management, private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    #[Get(path: '/member-catalog/options', name: 'admin.member-catalog.options')]
    public function options(Request $request): Response { return $this->responses->success($request, $this->management->options($this->context->account($request))); }

    #[Get(path: '/member-groups', name: 'admin.member-groups.index')]
    public function groups(Request $request): Response
    {
        $query = MemberCatalogValidator::page($request->get());
        return $this->responses->success($request, $this->management->groups(
            $this->context->account($request), $query['keyword'], $query['status'], $query['page'], $query['page_size'],
            TableListQueryValidator::validate($request->get(), ['name' => 64, 'code' => 64], ['sort_order', 'created_at']),
        ));
    }

    #[Post(path: '/member-groups', name: 'admin.member-groups.store')]
    public function storeGroup(Request $request): Response { return $this->responses->success($request, $this->management->saveGroup($this->context->account($request), null, MemberCatalogValidator::group($request->post())), '创建成功', 201); }

    #[Put(path: '/member-groups/{id:\d+}', name: 'admin.member-groups.update')]
    public function updateGroup(Request $request, mixed $id): Response { return $this->responses->success($request, $this->management->saveGroup($this->context->account($request), MemberValidator::id($id), MemberCatalogValidator::group($request->post())), '更新成功'); }

    #[Delete(path: '/member-groups/{id:\d+}', name: 'admin.member-groups.destroy')]
    public function destroyGroup(Request $request, mixed $id): Response { $this->management->deleteGroup($this->context->account($request), MemberValidator::id($id)); return $this->responses->success($request, null, '删除成功'); }

    #[Get(path: '/member-levels', name: 'admin.member-levels.index')]
    public function levels(Request $request): Response
    {
        $query = MemberCatalogValidator::page($request->get());
        return $this->responses->success($request, $this->management->levels(
            $this->context->account($request), $query['keyword'], $query['status'], $query['page'], $query['page_size'],
            TableListQueryValidator::validate($request->get(), ['name' => 64, 'code' => 64], ['level_order', 'growth_threshold', 'created_at']),
        ));
    }

    #[Post(path: '/member-levels', name: 'admin.member-levels.store')]
    public function storeLevel(Request $request): Response { return $this->responses->success($request, $this->management->saveLevel($this->context->account($request), null, MemberCatalogValidator::level($request->post())), '创建成功', 201); }

    #[Put(path: '/member-levels/{id:\d+}', name: 'admin.member-levels.update')]
    public function updateLevel(Request $request, mixed $id): Response { return $this->responses->success($request, $this->management->saveLevel($this->context->account($request), MemberValidator::id($id), MemberCatalogValidator::level($request->post())), '更新成功'); }

    #[Delete(path: '/member-levels/{id:\d+}', name: 'admin.member-levels.destroy')]
    public function destroyLevel(Request $request, mixed $id): Response { $this->management->deleteLevel($this->context->account($request), MemberValidator::id($id)); return $this->responses->success($request, null, '删除成功'); }
}
