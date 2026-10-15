<?php

declare(strict_types=1);

namespace app\modules\channel\contract\storage;

use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;

/** 宿主固定签名下载能力，不对插件暴露文件路径。 */
interface LocalObjectDownloadInterface
{
    /**
     * @param string $namespace 逻辑分区
     * @param ObjectKey $key 对象键
     * @param int $expires 到期时间
     * @param string $signature HMAC
     * @return ObjectUpload 验证后的文件
     * @throws \app\shared\plugin\exception\PluginException 签名或对象无效
     */
    public function download(string $namespace, ObjectKey $key, int $expires, string $signature): ObjectUpload;
}
