<?php

declare(strict_types=1);

namespace app\modules\product\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\product\application\{GetCloudServerWorkspaceService, ManageCloudDeliveryRulesService};
use app\modules\product\validation\CloudDeliveryRuleValidator;
use app\modules\product\validation\CloudOfferValidator;
use app\modules\product\application\ManageCloudWorkspaceConfigurationService;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post, Put};
use support\{Request, Response};
use support\validation\Validator;

#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/cloud-servers')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminCloudServerWorkspaceController
{
    public function __construct(
        private GetCloudServerWorkspaceService $workspace,
        private ManageCloudDeliveryRulesService $rules,
        private CloudDeliveryRuleValidator $validator,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
        private ManageCloudWorkspaceConfigurationService $configurationService,
        private CloudOfferValidator $offerValidator,
        private \app\modules\product\application\GetCloudConfigurationSectionService $sections,
        private \app\modules\product\application\SaveCloudWorkspaceConfigurationService $liveConfiguration,
    ) {
    }

    /** @param Request $request 请求 @return Response 真实配置统计。 */
    #[Get(path: '/workspace', name: 'admin.cloud-servers.workspace')]
    public function workspace(Request $request): Response
    {
        return $this->responses->success($request, $this->workspace->get($this->context->account($request)));
    }

    /** @return Response 三级菜单页面的授权配置数据，不初始化或修改数据。 */
    #[Get(path: '/sections/{section}', name: 'admin.cloud-servers.sections.show')]
    public function section(Request $request, string $section): Response
    {
        return $this->responses->success($request, $this->sections->get($this->context->account($request), $section));
    }

    /** @return Response 固定配置入口；GET不会初始化草稿或选用历史配置。 */
    #[Get(path: '/configuration', name: 'admin.cloud-servers.configuration.show')]
    public function configuration(Request $request): Response
    {
        return $this->responses->success($request, $this->configurationService->get($this->context->account($request)));
    }

    /** @return Response 社区配置保存结果；完整配置自动生效，缺项明确返回说明。 */
    #[Put(path: '/configuration/live', name: 'admin.cloud-servers.configuration.save-live')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function saveLiveConfiguration(Request $request): Response
    {
        $input = $request->post();
        $selection = Validator::make($input, [
            'configuration_id' => 'present|nullable|integer|min:1',
            'expected_configuration_id' => 'present|nullable|integer|min:1',
        ])->validate();
        return $this->responses->success($request, $this->liveConfiguration->save(
            $this->context->account($request),
            $selection['configuration_id'] === null ? null : (int) $selection['configuration_id'],
            $this->offerValidator->validate($input),
            $selection['expected_configuration_id'] === null ? null : (int) $selection['expected_configuration_id'],
        ));
    }

    /** @return Response 原子保存当前配置，保留其他销售配置及历史版本。 */
    #[Put(path: '/configuration', name: 'admin.cloud-servers.configuration.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function saveConfiguration(Request $request): Response
    {
        $input = $request->post();
        $selection = Validator::make($input, [
            'configuration_id' => 'present|nullable|integer|min:1',
            'expected_configuration_id' => 'present|nullable|integer|min:1',
        ])->validate();
        $data = $this->offerValidator->validate($input);
        return $this->responses->success($request, $this->configurationService->save(
            $this->context->account($request),
            $selection['configuration_id'] === null ? null : (int) $selection['configuration_id'],
            $data,
            $selection['expected_configuration_id'] === null ? null : (int) $selection['expected_configuration_id'],
        ));
    }

    /** @param Request $request 请求 @return Response 规则列表。 */
    #[Get(path: '/delivery-rules', name: 'admin.cloud-servers.delivery-rules.index')]
    public function rules(Request $request): Response
    {
        return $this->responses->success($request, $this->rules->all($this->context->account($request)));
    }

    /** @param Request $request 请求 @return Response 规则编辑引用。 */
    #[Get(path: '/delivery-rules/references', name: 'admin.cloud-servers.delivery-rules.references')]
    public function references(Request $request): Response
    {
        return $this->responses->success($request, $this->rules->references($this->context->account($request)));
    }

    /** @param Request $request 请求 @return Response 新规则。 */
    #[Post(path: '/delivery-rules', name: 'admin.cloud-servers.delivery-rules.store')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function store(Request $request): Response
    {
        return $this->responses->success($request, $this->rules->save($this->context->account($request), null, $this->validator->validate($request->post())), httpStatus: 201);
    }

    /** @param Request $request 请求 @param mixed $id 规则标识 @return Response 编辑结果。 */
    #[Put(path: '/delivery-rules/{id:\d+}', name: 'admin.cloud-servers.delivery-rules.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request, mixed $id): Response
    {
        $value = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return $this->responses->success($request, $this->rules->save($this->context->account($request), (int) $value['id'], $this->validator->validate($request->post())));
    }
}
