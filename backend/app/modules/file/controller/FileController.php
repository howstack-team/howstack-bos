<?php

declare(strict_types=1);
namespace app\modules\file\controller;

use app\exception\BusinessException;
use app\modules\admin\auth\application\AdminAuthenticationContext;
use app\modules\admin\auth\middleware\AdminAuthMiddleware;
use app\modules\file\application\ManageFilesService;
use app\shared\http\{ApiResponse,ApiErrorCode};
use app\shared\http\middleware\SameOriginWriteMiddleware;
use support\annotation\Middleware;
use support\annotation\route\{DisableDefaultRoute,Get,Post};
use support\{Request,Response};
use app\modules\file\validation\FileInputValidator;

#[DisableDefaultRoute]
final readonly class FileController
{
    public function __construct(private ManageFilesService $files, private AdminAuthenticationContext $auth, private ApiResponse $responses, private FileInputValidator $validator) {}
    #[Get(path: '/api/admin/v1/files', name: 'admin.files.list')]
    #[Middleware(AdminAuthMiddleware::class)]
    public function index(Request $request): Response { return $this->responses->success($request, $this->files->list($this->auth->account($request), $request->get())); }
    #[Get(path: '/api/admin/v1/files/statistics', name: 'admin.files.statistics')]
    #[Middleware(AdminAuthMiddleware::class)]
    public function statistics(Request $request): Response
    {
        return $this->responses->success($request, $this->files->statistics($this->auth->account($request)));
    }
    #[Post(path: '/api/admin/v1/files', name: 'admin.files.upload')]
    #[Middleware(AdminAuthMiddleware::class, SameOriginWriteMiddleware::class)]
    public function upload(Request $request): Response
    {
        $actor = $this->auth->account($request);
        // 上传权限在读取磁盘内容及调用对象存储之前检查。
        $this->files->authorizeUpload($actor);
        $file = $this->validator->upload($request->file('file'), $request->post());
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) throw new BusinessException('无法读取文件', ApiErrorCode::VALIDATION_FAILED, 422);
        return $this->responses->success($request, $this->files->upload($actor,$bytes,(string)$file->getUploadName()), '文件上传成功', 201);
    }
    #[Post(path: '/api/admin/v1/files/delete', name: 'admin.files.delete')]
    #[Middleware(AdminAuthMiddleware::class, SameOriginWriteMiddleware::class)]
    public function delete(Request $request): Response
    {
        $input = $request->post();
        return $this->responses->success($request, $this->files->delete($this->auth->account($request),$this->validator->deletion($input)));
    }
    #[Get(path: '/api/public/v1/files/{id:[a-f0-9]{32}}', name: 'public.files.show')]
    public function show(Request $request, string $id): Response
    {
        return new Response(302, ['Location'=>$this->files->publicUrl($id),'Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
