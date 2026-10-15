<?php

declare(strict_types=1);

namespace app\modules\api_documentation\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\api_documentation\application\ViewApiDocumentationService;
use support\Request;
use support\Response;
use support\annotation\route\DisableDefaultRoute;

/** 非 /api 文档资源返回原始 OpenAPI，业务接口仍使用统一响应信封。 */
#[DisableDefaultRoute]
final readonly class ApiDocumentationController
{
    public function __construct(
        private AdminAuthenticationContext $context,
        private ViewApiDocumentationService $documentation,
    ) {
    }

    public function page(Request $request): Response
    {
        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; worker-src 'self' blob:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        ], $this->documentation->page($this->context->account($request)));
    }

    public function specification(Request $request, string $group): Response
    {
        return new Response(200, ['Content-Type' => 'application/json; charset=utf-8'],
            $this->documentation->specification($this->context->account($request), $group));
    }
}
