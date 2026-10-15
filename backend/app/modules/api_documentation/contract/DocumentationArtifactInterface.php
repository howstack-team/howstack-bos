<?php

declare(strict_types=1);

namespace app\modules\api_documentation\contract;

/** 构建产物读取边界；请求期间不解析源码或生成契约。 */
interface DocumentationArtifactInterface
{
    /** 返回已构建的文档 HTML。 */
    public function page(): string;

    /** 返回固定分组的 OpenAPI JSON。 */
    public function specification(string $group): string;
}
