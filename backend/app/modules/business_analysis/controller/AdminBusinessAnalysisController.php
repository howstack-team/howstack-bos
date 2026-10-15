<?php

declare(strict_types=1);

namespace app\modules\business_analysis\controller;

use app\exception\BusinessException;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\business_analysis\application\GetBusinessAnalysisService;
use app\modules\business_analysis\validation\AnalysisQueryValidator;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, RouteGroup};
use support\{Request, Response};

/** 总览下的经营分析，只返回获得明确授权的全站聚合数据。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminBusinessAnalysisController
{
    public function __construct(
        private GetBusinessAnalysisService $analysis,
        private AdminAuthenticationContext $context,
        private ApiResponse $responses,
    ) {
    }

    /**
     * @param Request $request 已认证管理员请求
     * @return Response 含日期、统计口径、前期对比、连续趋势与分布的统一响应
     * @throws BusinessException 查询参数无效或缺少经营分析查看权限
     */
    #[Get(path: '/business-analysis', name: 'admin.business-analysis.show')]
    public function show(Request $request): Response
    {
        return $this->responses->success($request, $this->analysis->get(
            $this->context->account($request), AnalysisQueryValidator::validate($request->get()),
        ))->withHeader('Cache-Control', 'no-store, private');
    }
}
