<?php

declare(strict_types=1);

namespace app\modules\content\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\storage\StorageChannelService;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\content\contract\ContentImageRepositoryInterface;
use app\modules\content\domain\ContentImage;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;

/** 公告/帮助公开图片复用存储通道；正文保存稳定地址，不保存临时签名 URL。 */
final readonly class ContentImageService
{
    public function __construct(
        private ChannelRepositoryInterface $channels,
        private ContentImageRepositoryInterface $images,
        private StorageChannelService $storage,
        private AdminAccessControlService $access,
    ) {
    }

    /** @return array{available: bool, message: string} */
    public function availability(AdminAccount $actor, string $kind): array
    {
        $this->authorize($actor, $kind);
        return ['available' => $this->defaultChannel() !== null, 'message' => '请先配置并启用默认存储通道'];
    }

    /** @return array{url: string, alt: string} */
    public function upload(AdminAccount $actor, string $kind, string $content, string $filename): array
    {
        $this->authorize($actor, $kind);
        $type = ContentImage::validate($content, $filename);
        $channel = $this->defaultChannel() ?? throw new BusinessException('请先配置并启用默认存储通道', ApiErrorCode::CONFLICT, 409);
        $id = bin2hex(random_bytes(16));
        $key = new ObjectKey('content/images/' . $id . '.' . $type['extension']);
        $this->images->reserve([
            'id' => $id, 'original_name' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 200), 'extension' => $type['extension'], 'type' => 'image', 'visibility' => 'public', 'namespace' => 'content', 'origin' => $kind, 'channel_id' => $channel['id'], 'channel_revision' => $channel['revision'],
            'object_key' => $key->value, 'mime_type' => $type['mime'], 'byte_size' => strlen($content),
            'created_by' => $actor->id, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            $result = $this->storage->put($channel['id'], $channel['revision'], new ObjectUpload($key, $content, $type['mime']));
        } catch (\Throwable $error) {
            $this->images->mark($id, 'unknown');
            throw $error;
        }
        $this->images->mark($id, $result->status);
        if ($result->status !== 'stored') {
            throw new BusinessException('图片上传未完成，请检查存储通道状态', ApiErrorCode::CONFLICT, 409);
        }
        return ['url' => '/api/public/v1/content/images/' . $id, 'alt' => mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, 200)];
    }

    /** 只允许读取持久化且已完成的公开图片；不接收对象键或客户端传来的通道。 */
    public function url(string $id): string
    {
        $image = $this->images->find($id);
        if ($image === null || $image['status'] !== 'stored') {
            throw new BusinessException('图片不存在或未完成上传', ApiErrorCode::NOT_FOUND, 404);
        }
        return $this->storage->signedUrl($image['channel_id'], $image['channel_revision'], new ObjectKey($image['object_key']))->url;
    }

    /** @return array{id: int, revision: int}|null */
    private function defaultChannel(): ?array
    {
        $rows = $this->channels->list('storage', ['page' => 1, 'page_size' => 100, 'status' => 1]);
        foreach ($rows['data'] as $row) {
            if ($row['is_default'] && $row['configuration_current'] && $row['platform_available']) {
                return ['id' => $row['id'], 'revision' => $row['revision']];
            }
        }
        return null;
    }

    private function authorize(AdminAccount $actor, string $kind): void
    {
        if (!in_array($kind, ['help', 'news'], true)) {
            throw new BusinessException('内容类型不存在', ApiErrorCode::NOT_FOUND, 404);
        }
        $prefix = 'admin.content.' . $kind . '-entries.';
        $permissions = $this->access->permissions($actor);
        if (!$actor->isFounder && !in_array($prefix . 'create', $permissions, true) && !in_array($prefix . 'update', $permissions, true)) {
            throw new BusinessException('无权上传内容图片', ApiErrorCode::FORBIDDEN, 403);
        }
    }
}
