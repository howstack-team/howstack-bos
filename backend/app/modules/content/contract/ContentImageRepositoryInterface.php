<?php

declare(strict_types=1);

namespace app\modules\content\contract;

/** 只保存由内容编辑器创建的公开图片引用，不能传入任意存储对象。 */
interface ContentImageRepositoryInterface
{
    /** @param array<string, int|string> $image 已校验的图片引用 */
    public function reserve(array $image): void;

    public function mark(string $id, string $status): void;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;
}
