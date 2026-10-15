<?php

declare(strict_types=1);

namespace app\modules\website\controller;

use app\modules\website\application\WebsiteImageService;
use support\annotation\route\{DisableDefaultRoute, Get};
use support\Request;
use support\Response;

/** 公开访问只能使用已登记图片 ID；内部通道配置永不返回。 */
#[DisableDefaultRoute]
final readonly class WebsiteImageController
{
    public function __construct(private WebsiteImageService $images)
    {
    }

    #[Get(path: '/api/public/v1/website/images/{id:[a-f0-9]{32}}', name: 'public.website.images')]
    /** 按已登记图片标识返回存储签名地址。
     * @param Request $request 当前请求
     * @param string $id 随机图片标识
     * @return Response 302重定向
     */
    public function show(Request $request, string $id): Response
    {
        return new Response(302, ['Location' => $this->images->redirectUrl($id), 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
