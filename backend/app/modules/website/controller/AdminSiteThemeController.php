<?php

declare(strict_types=1);

namespace app\modules\website\controller;

use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\website\application\ManageSiteThemeService;
use app\modules\website\validation\ThemeSchema;
use app\shared\http\ApiResponse;
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute, Get, Put, Post, Delete, RouteGroup};
use support\Request;
use support\Response;
use support\validation\Validator;
use Webman\Http\UploadFile;

/** 统一主题管理HTTP边界，操作权限由用例再次检查。 */
#[DisableDefaultRoute]
#[RouteGroup('/api/admin/v1/themes')]
#[Middleware(AdminAuthMiddleware::class)]
final readonly class AdminSiteThemeController
{
    public function __construct(private ManageSiteThemeService $service, private AdminAuthenticationContext $context, private ApiResponse $responses)
    {
    }

    #[Get(path: '', name: 'admin.themes.list')]
    public function index(Request $request): Response
    {
        return $this->responses->success($request, $this->service->catalog($this->context->account($request)));
    }

    #[Get(path: '/bindings', name: 'admin.themes.bindings')]
    public function bindings(Request $request): Response
    {
        return $this->responses->success($request, $this->service->bindings($this->context->account($request)));
    }

    #[Put(path: '/bindings', name: 'admin.themes.update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function bind(Request $request): Response
    {
        $input = Validator::make($request->post(), ['revision' => 'required|integer|min:1', 'release_id' => 'required|integer|min:1', 'mobile_enabled' => 'required|boolean'])->validate();
        $input['revision'] = (int) $input['revision'];
        $input['release_id'] = (int) $input['release_id'];
        return $this->responses->success($request, $this->service->bind($this->context->account($request), $input), '主题已切换');
    }

    #[Post(path: '/install', name: 'admin.themes.install')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function install(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file instanceof UploadFile || !$file->isValid() || $file->getSize() > 26214400 || strtolower(pathinfo((string) $file->getUploadName(), PATHINFO_EXTENSION)) !== 'zip') {
            ThemeSchema::fail('file', '请选择不超过25MiB的主题ZIP包');
        }
        return $this->responses->success($request, $this->service->install($this->context->account($request), $file->getPathname()), '主题已安装或更新', 201);
    }

    #[Get(path: '/{id:\d+}/components/{component:website|pc|mobile}/settings', name: 'admin.themes.settings')]
    public function settings(Request $request, string $id, string $component): Response
    {
        return $this->responses->success($request, $this->service->configuration($this->context->account($request), $this->id($id), $component));
    }

    #[Put(path: '/{id:\d+}/components/{component:website|pc|mobile}/settings', name: 'admin.themes.settings-update')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function save(Request $request, string $id, string $component): Response
    {
        $input = Validator::make($request->post(), ['revision' => 'required|integer|min:1', 'settings' => 'present|array'])->validate();
        $input['revision'] = (int) $input['revision'];
        return $this->responses->success($request, $this->service->saveConfiguration($this->context->account($request), $this->id($id), $component, $input));
    }

    #[Delete(path: '/{id:\d+}', name: 'admin.themes.uninstall')]
    #[Middleware(SameOriginWriteMiddleware::class)]
    public function uninstall(Request $request, string $id): Response
    {
        $this->service->uninstall($this->context->account($request), $this->id($id));
        return $this->responses->success($request, null, '主题已卸载');
    }

    private function id(string $id): int
    {
        return (int) Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:2147483647'])->validate()['id'];
    }
}
