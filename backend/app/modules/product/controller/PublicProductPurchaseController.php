<?php

declare(strict_types=1);

namespace app\modules\product\controller;

use app\modules\product\application\PublicProductPurchaseService;
use app\modules\order\validation\OrderSelectionValidator;
use app\shared\http\ApiResponse;
use support\annotation\route\{DisableDefaultRoute, RouteGroup, Get, Post};
use support\{Request, Response};
use support\validation\Validator;

/** 匿名读与无副作用的配置计算，不受会员会话保护。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/public/v1/products')]
final readonly class PublicProductPurchaseController
{
    public function __construct(private PublicProductPurchaseService $products, private ApiResponse $responses) {}

    #[Get(path: '', name: 'public.products.list')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->products->products());
    }

    #[Post(path: '/{id:\d+}/{action:options|quote}', name: 'public.products.configure')]
    public function configure(Request $request, mixed $id, mixed $action): Response
    {
        $data = Validator::make(['id' => $id, 'action' => $action, 'version_id' => $request->post('version_id')],
            ['id' => 'required|integer|min:1', 'action' => 'required|in:options,quote', 'version_id' => 'required|integer|min:1'])->validate();
        $selection = OrderSelectionValidator::clean($request->post('selection', []));
        $result = $data['action'] === 'options' ? $this->products->options((int) $data['id'], (int) $data['version_id'], $selection)
            : $this->products->quote((int) $data['id'], (int) $data['version_id'], $selection);
        return $this->responses->success($request, $result)->withHeader('Cache-Control', 'no-store');
    }
}
