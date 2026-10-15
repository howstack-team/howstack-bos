<?php

declare(strict_types=1);

namespace app\modules\content\controller;

use app\exception\BusinessException;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\content\application\ContentImageService;
use app\modules\content\domain\ContentImage;
use app\shared\http\ApiErrorCode;
use app\shared\http\ApiResponse;
use support\annotation\Middleware;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

#[DisableDefaultRoute]
final readonly class ContentImageController
{
    public function __construct(private ContentImageService $images, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '/api/admin/v1/content/{kind:help|news}/images', name: 'admin.content.images.options')]
    #[Middleware(AdminAuthMiddleware::class)]
    public function options(Request $request, string $kind): Response
    {
        return $this->responses->success($request, $this->images->availability($this->context->account($request), $kind));
    }

    #[Post(path: '/api/admin/v1/content/{kind:help|news}/images', name: 'admin.content.images.upload')]
    #[Middleware(AdminAuthMiddleware::class)]
    public function upload(Request $request, string $kind): Response
    {
        $actor = $this->context->account($request);
        // 在读文件前校验内容编辑权限，防止无权限请求触发存储操作。
        $this->images->availability($actor, $kind);
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid() || $file->getSize() > ContentImage::MAX_BYTES) {
            throw new BusinessException('请选择不超过5MiB的图片文件', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['file' => ['图片文件无效或超出大小、类型及尺寸限制']]]);
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            throw new BusinessException('无法读取上传图片，请重试', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['file' => ['图片文件无效或超出大小、类型及尺寸限制']]]);
        }
        return $this->responses->success($request, $this->images->upload($actor, $kind, $content, (string) $file->getUploadName()), '图片上传成功', 201);
    }

    #[Get(path: '/api/public/v1/content/images/{id:[a-f0-9]{32}}', name: 'public.content.images')]
    public function show(Request $request, string $id): Response
    {
        return new Response(302, ['Location' => $this->images->url($id), 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
