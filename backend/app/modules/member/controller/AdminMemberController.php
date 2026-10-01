<?php

declare(strict_types=1);

namespace app\modules\member\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\member\application\MemberManagementService;
use app\modules\member\validation\MemberValidator;
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

/** 提供运营后台会员管理接口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminMemberController
{
    /** 初始化会员管理控制器。 */
    public function __construct(
        private MemberManagementService $management,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    /** 返回会员分页。 */
    #[Get(path: '/members', name: 'admin.members.index')]
    public function index(Request $request): Response
    {
        $query = MemberValidator::page($request->get());

        return $this->responses->success($request, $this->management->paginate(
            $this->context->account($request), $query['keyword'], $query['status'], $query['page'], $query['page_size'],
        ));
    }

    /** 返回指定会员资料。 */
    #[Get(path: '/members/{id:\d+}', name: 'admin.members.show')]
    public function show(Request $request, mixed $id): Response
    {
        return $this->responses->success($request, $this->management->member(
            $this->context->account($request), MemberValidator::id($id),
        ));
    }

    /** 创建会员。 */
    #[Post(path: '/members', name: 'admin.members.store')]
    public function store(Request $request): Response
    {
        return $this->responses->success($request, $this->management->save(
            $this->context->account($request), null, MemberValidator::member($request->post(), true),
        ), '创建成功', 201);
    }

    /** 更新会员资料。 */
    #[Put(path: '/members/{id:\d+}', name: 'admin.members.update')]
    public function update(Request $request, mixed $id): Response
    {
        return $this->responses->success($request, $this->management->save(
            $this->context->account($request), MemberValidator::id($id), MemberValidator::member($request->post(), false),
        ), '更新成功');
    }

    /** 删除会员。 */
    #[Delete(path: '/members/{id:\d+}', name: 'admin.members.destroy')]
    public function destroy(Request $request, mixed $id): Response
    {
        $this->management->delete($this->context->account($request), MemberValidator::id($id));

        return $this->responses->success($request, null, '删除成功');
    }
}
