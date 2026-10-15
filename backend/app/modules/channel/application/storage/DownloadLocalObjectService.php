<?php

declare(strict_types=1);

namespace app\modules\channel\application\storage;

use app\modules\channel\contract\storage\LocalObjectDownloadInterface;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\plugin\exception\PluginException;
use app\exception\BusinessException;

/** 下载只依赖签名授权；前置生成链接的业务必须检查对象归属。 */
final readonly class DownloadLocalObjectService
{
    /**
     * @param LocalObjectDownloadInterface $files 固定宿主能力
     */
    public function __construct(private LocalObjectDownloadInterface $files)
    {
    }

    /**
     * @param array{namespace: string, key: string, expires: int|string, signature: string} $query 验证后的查询
     * @return ObjectUpload 已签名授权的文件
     */
    public function download(array $query): ObjectUpload
    {
        try {
            return $this->files->download($query['namespace'], new ObjectKey($query['key']), (int) $query['expires'], $query['signature']);
        } catch (PluginException | \InvalidArgumentException) {
            throw new BusinessException('文件链接无效、已过期或文件不存在', httpStatus: 403);
        }
    }
}
