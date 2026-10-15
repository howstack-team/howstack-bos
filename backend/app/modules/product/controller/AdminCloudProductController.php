<?php

declare(strict_types=1);

namespace app\modules\product\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\product\application\ManageCloudProductsService;
use app\modules\product\validation\CloudOfferValidator;
use app\shared\http\ApiResponse;
use app\shared\validation\TableListQueryValidator;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post, Put, Patch};
use support\{Request, Response};
use support\validation\Validator;

/** 产品身份、销售草稿、历史版本和后台配置报价预览。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/cloud-products')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminCloudProductController
{
    public function __construct(private ManageCloudProductsService $service, private CloudOfferValidator $validator,
        private AdminAuthenticationContext $context, private ApiResponse $responses) {}

    /** @param Request $request 请求 @return Response 分页 */
    #[Get(path: '', name: 'admin.cloud-products.index')]
    public function index(Request $request): Response
    {
        $q = Validator::make(['keyword' => $request->get('keyword', ''), 'page' => $request->get('page', 1), 'page_size' => $request->get('page_size', 10)],
            ['keyword' => 'string|max:128', 'page' => 'required|integer|min:1|max:100000', 'page_size' => 'required|integer|in:10,20,50,100'])->validate();
        return $this->responses->success($request, $this->service->paginate(
            $this->context->account($request), trim($q['keyword']), (int) $q['page'], (int) $q['page_size'],
            TableListQueryValidator::validate($request->get(), ['name' => 128, 'code' => 128], ['created_at'], ['status' => ['0', '1'], 'publication' => ['draft', 'published']]),
        ));
    }

    /** @param Request $request 请求 @return Response 配置引用 */
    #[Get(path: '/references', name: 'admin.cloud-products.references')]
    public function references(Request $request): Response { return $this->responses->success($request, $this->service->references($this->context->account($request))); }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 详情 */
    #[Get(path: '/{id:\d+}', name: 'admin.cloud-products.show')]
    public function show(Request $request, mixed $id): Response { return $this->responses->success($request, $this->service->detail($this->context->account($request), $this->id($id))); }

    /** @param Request $request 请求 @return Response 新方案 */
    #[Post(path: '', name: 'admin.cloud-products.store')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function store(Request $request): Response { return $this->responses->success($request, $this->service->save($this->context->account($request), null, $this->validator->validate($request->post())), httpStatus: 201); }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 修改结果 */
    #[Put(path: '/{id:\d+}', name: 'admin.cloud-products.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function update(Request $request, mixed $id): Response { return $this->responses->success($request, $this->service->save($this->context->account($request), $this->id($id), $this->validator->validate($request->post()))); }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 发布结果 */
    #[Post(path: '/{id:\d+}/publish', name: 'admin.cloud-products.publish')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function publish(Request $request, mixed $id): Response
    {
        $v = Validator::make($request->post(), ['revision' => 'required|integer|min:1', 'enable' => 'sometimes|boolean'])->validate();
        $actor = $this->context->account($request);
        $result = !empty($v['enable'])
            ? $this->service->publishAndEnable($actor, $this->id($id), (int) $v['revision'])
            : $this->service->publish($actor, $this->id($id), (int) $v['revision']);
        return $this->responses->success($request, $result);
    }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 启停结果 */
    #[Patch(path: '/{id:\d+}/status', name: 'admin.cloud-products.status')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function status(Request $request, mixed $id): Response
    {
        $v = Validator::make($request->post(), ['revision' => 'required|integer|min:1', 'status' => 'required|integer|in:0,1'])->validate();
        return $this->responses->success($request, $this->service->status($this->context->account($request), $this->id($id), (int) $v['revision'], (int) $v['status']));
    }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 历史版本 */
    #[Get(path: '/{id:\d+}/versions', name: 'admin.cloud-products.versions')]
    public function versions(Request $request, mixed $id): Response { return $this->responses->success($request, $this->service->versions($this->context->account($request), $this->id($id))); }

    /** @param Request $request 请求 @param mixed $id 标识 @return Response 联动或报价预览 */
    #[Post(path: '/{id:\d+}/preview', name: 'admin.cloud-products.preview')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function preview(Request $request, mixed $id): Response
    {
        $rules = ['mode' => 'required|in:options,price,delivery', 'version_id' => 'nullable|integer|min:1', 'selection' => 'present|array',
            'selection.duration_months' => 'sometimes|integer|min:1|max:120', 'selection.billing_mode' => 'sometimes|in:prepaid,usage', 'selection.network.billing_mode' => 'sometimes|in:fixed,traffic',
            'selection.billing_cycle' => 'sometimes|in:hour,day,month,year', 'selection.quantity' => 'sometimes|integer|min:1|max:100',
            'selection.login_method' => 'sometimes|in:password,ssh_key', 'selection.data_disks' => 'sometimes|array|max:32',
            'selection.system_disk' => 'sometimes|array', 'selection.network' => 'sometimes|array', 'selection.data_disks.*' => 'required|array'];
        foreach (['region_id', 'zone_id', 'line_id', 'flavor_id', 'image_id', 'protection_id', 'flavor_group_id', 'image_type_id'] as $field) $rules['selection.' . $field] = 'nullable|integer|min:0';
        foreach (['system_disk.type_id', 'system_disk.size_gib', 'network.egress_mbps', 'network.ingress_mbps', 'network.bandwidth_mbps', 'network.ipv4_count', 'data_disks.*.type_id', 'data_disks.*.size_gib'] as $field) $rules['selection.' . $field] = 'sometimes|integer|min:0|max:1000000';
        $v = Validator::make($request->post(), $rules)->validate();
        $selection = array_intersect_key($v['selection'], array_flip(['region_id', 'zone_id', 'line_id', 'flavor_id', 'image_id', 'protection_id', 'system_disk', 'data_disks', 'network', 'billing_cycle', 'quantity', 'login_method', 'flavor_group_id', 'image_type_id', 'duration_months', 'billing_mode']));
        foreach (['region_id', 'zone_id', 'line_id', 'flavor_id', 'image_id', 'protection_id', 'quantity', 'flavor_group_id', 'image_type_id', 'duration_months'] as $field) {
            if (isset($selection[$field])) $selection[$field] = (int) $selection[$field];
        }
        foreach (['system_disk' => ['type_id', 'size_gib'], 'network' => ['egress_mbps', 'ingress_mbps', 'bandwidth_mbps', 'ipv4_count']] as $field => $keys) {
            if (isset($selection[$field])) $selection[$field] = array_map('intval', array_intersect_key($selection[$field], array_flip($keys)));
        }
        if (isset($v['selection']['network']['billing_mode'])) $selection['network']['billing_mode'] = $v['selection']['network']['billing_mode'];
        if (isset($selection['data_disks'])) $selection['data_disks'] = array_values(array_map(static fn (array $disk): array => array_map('intval', array_intersect_key($disk, array_flip(['type_id', 'size_gib']))), $selection['data_disks']));
        if ($v['mode'] === 'delivery') return $this->responses->success($request, $this->service->deliveryPreview($this->context->account($request), $this->id($id), $selection, isset($v['version_id']) ? (int) $v['version_id'] : null));
        return $this->responses->success($request, $this->service->preview($this->context->account($request), $this->id($id), $selection,
            $v['mode'] === 'price', isset($v['version_id']) ? (int) $v['version_id'] : null));
    }

    /** @param mixed $id 外部参数 @return int 合法标识 */
    private function id(mixed $id): int { return (int) Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate()['id']; }
}
