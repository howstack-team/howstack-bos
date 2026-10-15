<?php

declare(strict_types=1);
namespace app\modules\website\infrastructure;

use app\modules\website\contract\WebsiteImageRepositoryInterface;
use app\modules\file\contract\FileRegistryInterface;

/** 业务图片入口引用统一文件库，保留原公开图片地址。 */
final readonly class DatabaseWebsiteImageRepository implements WebsiteImageRepositoryInterface
{
    public function __construct(private FileRegistryInterface $files) {}
    public function reserve(array $image): void { $this->files->reserve($image); }
    public function mark(string $id, string $status): void { $this->files->mark($id, $status); }
    public function find(string $id): ?array
    {
        $file = $this->files->find($id);
        return $file !== null && $file['type'] === 'image' && $file['visibility'] === 'public' && $file['deleted_at'] === null ? $file : null;
    }
}
