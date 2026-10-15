<?php

declare(strict_types=1);

namespace app\modules\website\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\website\application\ManageWebsiteService;
use app\modules\website\application\WebsiteImageService;
use app\modules\website\validation\ThemeSchema;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use app\shared\validation\PublicImage;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Put, Post, RouteGroup};
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

/** 官网管理 HTTP 边界；每个用例执行 view/update 权限检查。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/website')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminWebsiteController
{
    public function __construct(private ManageWebsiteService $website, private WebsiteImageService $images, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '/settings', name: 'admin.website.settings')]
    /** 读取官网设置及公共品牌元数据。
     * @param Request $request 当前请求
     * @return Response 统一JSON响应
     */
    public function settings(Request $request): Response
    {
        return $this->responses->success($request, $this->website->settings($this->context->account($request)));
    }

    #[Get(path: '/base', name: 'admin.website.base')]
    public function base(Request $request): Response
    {
        return $this->responses->success($request, $this->website->section($this->context->account($request), 'base'));
    }

    #[Get(path: '/navigation', name: 'admin.website.navigation')]
    public function navigation(Request $request): Response
    {
        return $this->responses->success($request, $this->website->section($this->context->account($request), 'navigation'));
    }

    #[Put(path: '/base', name: 'admin.website.base-update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    /** 以修订号保存官网完整设置。
     * @param Request $request 当前请求
     * @return Response 统一JSON响应
     */
    public function updateBase(Request $request): Response
    {
        return $this->responses->success($request, $this->website->updateSection($this->context->account($request), 'base', $request->post()));
    }

    #[Put(path: '/navigation', name: 'admin.website.navigation-update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function updateNavigation(Request $request): Response
    {
        return $this->responses->success($request, $this->website->updateSection($this->context->account($request), 'navigation', $request->post()));
    }

    #[Get(path: '/themes/{id:[a-z][a-z0-9_-]{0,63}}/pages', name: 'admin.website.pages')]
    /** 返回导航可选择的核心与自定义页面。
     * @param Request $request 当前请求
     * @param string $id 已启用主题标识
     * @return Response 统一JSON响应
     */
    public function pages(Request $request, string $id): Response
    {
        return $this->responses->success($request, $this->website->pages($this->context->account($request), $id));
    }

    #[Get(path: '/images/availability', name: 'admin.website.images-availability')]
    /** 返回默认存储是否可用及配置引导。
     * @param Request $request 当前请求
     * @return Response 统一JSON响应
     */
    public function availability(Request $request): Response
    {
        return $this->responses->success($request, $this->images->availability($this->context->account($request)));
    }

    #[Post(path: '/images', name: 'admin.website.images-upload')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    /** 将已校验图片写入默认存储通道。
     * @param Request $request 当前请求
     * @return Response 统一JSON响应
     */
    public function upload(Request $request): Response
    {
        $actor = $this->context->account($request);
        $this->images->availability($actor);
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid() || $file->getSize() > PublicImage::MAX_BYTES) ThemeSchema::fail('file', '请选择不超过5MiB的有效图片');
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) ThemeSchema::fail('file', '无法读取上传图片');
        return $this->responses->success($request, $this->images->upload($actor, $bytes, (string) $file->getUploadName()), '图片上传成功', 201);
    }
}
