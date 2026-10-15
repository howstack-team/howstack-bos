<?php

declare(strict_types=1);

namespace app\modules\api_documentation\middleware;

use app\exception\BusinessException;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\install\contract\InstallationStateRepositoryInterface;
use app\shared\http\ApiErrorCode;
use app\shared\http\ApiResponse;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 页面和原始契约都要求有效管理员会话及文档访问权限。 */
final readonly class DocumentationAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminAuthMiddleware $authentication,
        private InstallationStateRepositoryInterface $installation,
        private ApiResponse $responses,
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        $isPage = in_array($request->path(), ['/api-docs', '/api-docs/'], true);
        try {
            if (!config('api_documentation.enabled', true)) {
                throw new BusinessException('API 文档未开放', ApiErrorCode::NOT_FOUND, 404);
            }
            $response = $this->authentication->process($request, $handler);
        } catch (BusinessException $exception) {
            if ($isPage && $exception->httpStatus() === 401) {
                $response = new Response(302, ['Location' => '/' . $this->installation->adminPath() . '/login']);
            } elseif ($isPage) {
                $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $response = new Response($exception->httpStatus(), ['Content-Type' => 'text/html; charset=utf-8'],
                    '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>API 文档</title><p>' . $message . '</p></html>');
            } else {
                $response = $this->responses->error($request, $exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
            }
        }

        return $response->withHeaders(['Cache-Control' => 'no-store, private', 'Vary' => 'Cookie, Authorization',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin']);
    }
}
