<?php

declare(strict_types=1);

namespace app\modules\api_documentation\infrastructure;

use app\exception\BusinessException;
use app\modules\api_documentation\contract\DocumentationArtifactInterface;
use app\shared\http\ApiErrorCode;

/** 仅读取发行包中位于 public 之外的固定文档产物。 */
final class FileDocumentationArtifacts implements DocumentationArtifactInterface
{
    public function page(): string
    {
        return $this->read('index.html');
    }

    public function specification(string $group): string
    {
        if (!in_array($group, ['admin', 'console', 'public', 'partner'], true)) {
            throw new BusinessException('文档分组不存在', ApiErrorCode::NOT_FOUND, 404);
        }

        return $this->read('specs/' . $group . '.json');
    }

    private function read(string $relativePath): string
    {
        $path = base_path() . '/resources/api-docs/' . $relativePath;
        if (!is_file($path)) {
            throw new BusinessException('API 文档尚未构建，请联系管理员', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new BusinessException('API 文档暂时不可用', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        }

        return $contents;
    }
}
