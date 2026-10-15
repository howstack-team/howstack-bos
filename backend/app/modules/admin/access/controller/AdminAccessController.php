<?php

declare(strict_types=1);

namespace app\modules\admin\access\controller;

use app\modules\admin\access\application\AdminAccessManagementService;
use app\modules\admin\access\validation\AdminAccessValidator;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
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

/**
 * 提供运营后台的管理员、角色、部门和权限目录接口。
 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminAccessController
{
    /**
     * 初始化权限管理控制器。
     * @param AdminAccessManagementService $management 管理用例
     * @param AdminAuthenticationContext   $context    请求级管理员上下文
     * @param ApiResponse                  $responses  统一响应生成器
     */
    public function __construct(
        private AdminAccessManagementService $management,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    /**
     * 分页查询数据范围内的管理员。
     * @param Request $request 当前请求
     * @return Response 管理员分页
     */
    #[Get(path: '/administrators', name: 'admin.administrators.index')]
    public function administrators(Request $request): Response
    {
        $query = AdminAccessValidator::page($request->get());

        return $this->responses->success($request, $this->management->administrators(
            $this->context->account($request), $query['keyword'], $query['page'], $query['page_size'],
            TableListQueryValidator::validate($request->get(), [], ['last_login_at'], ['status' => ['0', '1']]),
        ));
    }

    /**
     * 查询指定管理员。
     * @param Request $request 当前请求
     * @param mixed   $id      路径管理员标识
     * @return Response 管理员资料
     */
    #[Get(path: '/administrators/{id:\d+}', name: 'admin.administrators.show')]
    public function administrator(Request $request, mixed $id): Response
    {
        return $this->responses->success($request, $this->management->administrator(
            $this->context->account($request), AdminAccessValidator::id($id),
        ));
    }

    /**
     * 创建管理员。
     * @param Request $request 当前请求
     * @return Response 新管理员资料
     */
    #[Post(path: '/administrators', name: 'admin.administrators.store')]
    public function createAdministrator(Request $request): Response
    {
        return $this->responses->success($request, $this->management->saveAdministrator(
            $this->context->account($request), null,
            AdminAccessValidator::administrator($request->post(), true),
        ), '创建成功', 201);
    }

    /**
     * 更新管理员。
     * @param Request $request 当前请求
     * @param mixed   $id      路径管理员标识
     * @return Response 已更新管理员资料
     */
    #[Put(path: '/administrators/{id:\d+}', name: 'admin.administrators.update')]
    public function updateAdministrator(Request $request, mixed $id): Response
    {
        return $this->responses->success($request, $this->management->saveAdministrator(
            $this->context->account($request), AdminAccessValidator::id($id),
            AdminAccessValidator::administrator($request->post(), false),
        ), '更新成功');
    }

    /**
     * 删除管理员。
     * @param Request $request 当前请求
     * @param mixed   $id      路径管理员标识
     * @return Response 删除结果
     */
    #[Delete(path: '/administrators/{id:\d+}', name: 'admin.administrators.destroy')]
    public function deleteAdministrator(Request $request, mixed $id): Response
    {
        $this->management->deleteAdministrator($this->context->account($request), AdminAccessValidator::id($id));

        return $this->responses->success($request, null, '删除成功');
    }

    /**
     * 列出角色。
     * @param Request $request 当前请求
     * @return Response 角色列表
     */
    #[Get(path: '/roles', name: 'admin.roles.index')]
    public function roles(Request $request): Response
    {
        return $this->responses->success($request, $this->management->roles($this->context->account($request)));
    }

    /**
     * 查询角色详情。
     * @param Request $request 当前请求
     * @param mixed   $id      路径角色标识
     * @return Response 角色资料
     */
    #[Get(path: '/roles/{id:\d+}', name: 'admin.roles.show')]
    public function role(Request $request, mixed $id): Response
    {
        return $this->responses->success($request, $this->management->role($this->context->account($request), AdminAccessValidator::id($id)));
    }

    /**
     * 创建角色。
     * @param Request $request 当前请求
     * @return Response 新角色资料
     */
    #[Post(path: '/roles', name: 'admin.roles.store')]
    public function createRole(Request $request): Response
    {
        return $this->responses->success($request, $this->management->saveRole(
            $this->context->account($request), null, AdminAccessValidator::role($request->post()),
        ), '创建成功', 201);
    }

    /**
     * 更新角色。
     * @param Request $request 当前请求
     * @param mixed   $id      路径角色标识
     * @return Response 已更新角色资料
     */
    #[Put(path: '/roles/{id:\d+}', name: 'admin.roles.update')]
    public function updateRole(Request $request, mixed $id): Response
    {
        return $this->responses->success($request, $this->management->saveRole(
            $this->context->account($request), AdminAccessValidator::id($id), AdminAccessValidator::role($request->post()),
        ), '更新成功');
    }

    /**
     * 删除未分配的角色。
     * @param Request $request 当前请求
     * @param mixed   $id      路径角色标识
     * @return Response 删除结果
     */
    #[Delete(path: '/roles/{id:\d+}', name: 'admin.roles.destroy')]
    public function deleteRole(Request $request, mixed $id): Response
    {
        $this->management->deleteRole($this->context->account($request), AdminAccessValidator::id($id));

        return $this->responses->success($request, null, '删除成功');
    }

    /**
     * 查询部门树节点。
     * @param Request $request 当前请求
     * @return Response 可见部门节点
     */
    #[Get(path: '/departments', name: 'admin.departments.index')]
    public function departments(Request $request): Response
    {
        return $this->responses->success($request, $this->management->departments($this->context->account($request)));
    }

    /**
     * 创建部门。
     * @param Request $request 当前请求
     * @return Response 新部门节点
     */
    #[Post(path: '/departments', name: 'admin.departments.store')]
    public function createDepartment(Request $request): Response
    {
        return $this->responses->success($request, $this->management->saveDepartment(
            $this->context->account($request), null, AdminAccessValidator::department($request->post()),
        ), '创建成功', 201);
    }

    /**
     * 更新部门名称与状态。
     * @param Request $request 当前请求
     * @param mixed   $id      路径部门标识
     * @return Response 更新后的部门节点
     */
    #[Put(path: '/departments/{id:\d+}', name: 'admin.departments.update')]
    public function updateDepartment(Request $request, mixed $id): Response
    {
        return $this->responses->success($request, $this->management->saveDepartment(
            $this->context->account($request), AdminAccessValidator::id($id), AdminAccessValidator::department($request->post()),
        ), '更新成功');
    }

    /**
     * 删除空部门。
     * @param Request $request 当前请求
     * @param mixed   $id      路径部门标识
     * @return Response 删除结果
     */
    #[Delete(path: '/departments/{id:\d+}', name: 'admin.departments.destroy')]
    public function deleteDepartment(Request $request, mixed $id): Response
    {
        $this->management->deleteDepartment($this->context->account($request), AdminAccessValidator::id($id));

        return $this->responses->success($request, null, '删除成功');
    }

    /**
     * 查询当前发布的权限目录。
     * @param Request $request 当前请求
     * @return Response 权限目录
     */
    #[Get(path: '/permissions', name: 'admin.permissions.index')]
    public function permissions(Request $request): Response
    {
        return $this->responses->success($request, $this->management->permissionCatalog($this->context->account($request)));
    }
}
