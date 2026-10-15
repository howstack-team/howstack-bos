<?php

declare(strict_types=1);

namespace app\modules\channel\controller;

use app\modules\channel\application\storage\DownloadLocalObjectService;
use support\annotation\route\DisableDefaultRoute;
use support\annotation\route\Get;
use app\modules\channel\validation\LocalObjectDownloadValidator;
use app\shared\http\ByteRangeParser;
use support\Request;
use support\Response;

/** 固定签名文件下载入口；二进制响应是 JSON API 的明确例外，不执行上传内容。 */
#[DisableDefaultRoute]
final readonly class LocalStorageController
{
    /**
     * @param DownloadLocalObjectService $files 下载用例
     * @param LocalObjectDownloadValidator $validator 签名参数校验
     */
    public function __construct(
        private DownloadLocalObjectService $files,
        private LocalObjectDownloadValidator $validator,
        private ByteRangeParser $ranges = new ByteRangeParser(),
    )
    {
    }

    /**
     * @param Request $request 签名查询
     * @return Response 二进制附件
     */
    #[Get(path: '/api/public/v1/storage/local', name: 'public.storage.local')]
    public function download(Request $request): Response
    {
        $query = $this->validator->validate($request->get());
        $file = $this->files->download($query);
        $size = strlen($file->content);
        $headers = [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => (str_starts_with($file->contentType, 'audio/') || str_starts_with($file->contentType, 'video/') ? 'inline' : 'attachment')
                . "; filename*=UTF-8''" . rawurlencode(basename($file->key->value)),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            // Workerman 根据实际响应体生成 Content-Length，避免重复声明。
            'Accept-Ranges' => 'bytes',
        ];
        // 签名授权完成后才解析范围；未提供资源校验器时，If-Range 请求返回完整内容。
        $range = $request->header('if-range') === null
            ? $this->ranges->parse((string) $request->header('range', ''), $size)
            : null;
        if ($range === false) {
            return new Response(416, array_merge($headers, ['Content-Range' => 'bytes */' . $size]), '');
        }
        if ($range !== null) {
            $headers['Content-Range'] = 'bytes ' . $range['start'] . '-' . ($range['start'] + $range['length'] - 1) . '/' . $size;
            return new Response(206, $headers, substr($file->content, $range['start'], $range['length']));
        }
        return new Response(200, $headers, $file->content);
    }
}
