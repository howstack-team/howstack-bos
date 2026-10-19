<?php

declare(strict_types=1);

namespace app\modules\service_instance\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\service_instance\application\ViewServiceInstancesService;
use app\shared\http\ApiResponse;
use app\shared\validation\TableListQueryValidator;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use support\validation\Validator;

/** 运营后台实例查询及受独立权限保护的开通处置入口。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminServiceInstanceController
{
    public function __construct(
        private ViewServiceInstancesService $instances,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
        private \app\modules\service_instance\application\ManageServiceProvisioningService $provisioning,
    ) {
    }

    #[Get(path: '/service-instances', name: 'admin.service-instances.index')]
    public function index(Request $request): Response
    {
        $query = Validator::make([
            'product_type' => $request->get('product_type'),
            'keyword' => $request->get('keyword', ''),
            'status' => $request->get('status'),
            'page' => $request->get('page', 1),
            'page_size' => $request->get('page_size', 20),
        ], [
            'product_type' => 'required|string|in:cloud_server,physical_server,server_colocation',
            'keyword' => 'string|max:64',
            'status' => 'nullable|string|in:provisioning,active,suspended,expired,terminated,failed',
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|min:1|max:100',
        ])->validate();

        return $this->responses->success($request, $this->instances->paginate(
            $this->context->account($request), $query['product_type'], trim($query['keyword']),
            $query['status'], (int) $query['page'], (int) $query['page_size'],
            TableListQueryValidator::validate($request->get(), ['instance_no' => 64, 'display_name' => 128, 'member_mobile' => 20, 'member_nickname' => 64], ['created_at', 'activated_at', 'expires_at']),
        ));
    }

    #[Get(path: '/service-instances/{id:\d+}', name: 'admin.service-instances.show')]
    public function show(Request $request, mixed $id): Response
    {
        $validated = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();

        return $this->responses->success($request, $this->instances->detail(
            $this->context->account($request), (int) $validated['id'],
        ));
    }
    /** @param Request $request 请求 @param mixed $id 服务标识 @return Response 排队结果 */
    #[Post(path: '/service-instances/{id:\d+}/retry-provisioning', name: 'admin.service-instances.retry-provisioning')]
    #[Middleware(\app\shared\http\middleware\SameOriginWriteMiddleware::class)]
    public function retryProvisioning(Request $request, mixed $id): Response
    {
        $v = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        $this->provisioning->retry($this->context->account($request), (int) $v['id']);
        return $this->responses->success($request, null);
    }

    /** @param Request $request 请求 @param mixed $id 服务标识 @return Response 人工核查结果，原因由操作审计保存 */
    #[Post(path: '/service-instances/{id:\d+}/confirm-not-created', name: 'admin.service-instances.confirm-not-created')]
    #[Middleware(\app\shared\http\middleware\SameOriginWriteMiddleware::class)]
    public function confirmNotCreated(Request $request, mixed $id): Response
    {
        $body = $request->post();
        foreach (['reason', 'evidence_reference'] as $field) {
            if (is_string($body[$field] ?? null)) {
                $body[$field] = trim($body[$field]);
            }
        }
        $validator = Validator::make(['id' => $id] + $body, ['id' => 'required|integer|min:1', 'acknowledged' => 'required|accepted', 'reason' => 'required|string|min:5|max:500', 'evidence_reference' => 'required|string|max:160'])->toIlluminate();
        if ($validator->fails()) {
            throw new \app\exception\BusinessException('请填写核查说明和凭证', \app\shared\http\ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $validator->errors()->toArray()]);
        }
        $v = $validator->validated();
        $this->provisioning->confirmNotCreated($this->context->account($request), (int) $v['id'], $v['reason'], $v['evidence_reference']);
        return $this->responses->success($request, null);
    }

}
