<?php

declare(strict_types=1);

namespace app\modules\website\contract;

/** 官网公开图片的上传状态与存储修订引用。 */
interface WebsiteImageRepositoryInterface
{
    /** @param array<string, mixed> $image 上传元数据 */
    public function reserve(array $image): void;
    public function mark(string $id, string $status): void;
    /** @return array<string, mixed>|null 图片元数据 */
    public function find(string $id): ?array;
}
