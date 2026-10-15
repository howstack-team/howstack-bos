<?php

declare(strict_types=1);

namespace app\modules\website\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\application\storage\StorageChannelService;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\website\contract\WebsiteImageRepositoryInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\storage\ObjectKey;
use app\shared\plugin\dto\storage\ObjectUpload;
use app\shared\validation\PublicImage;

/** 官网图片复用默认存储通道，不允许客户端决定存储对象键或通道账号。 */
final readonly class WebsiteImageService
{
    public function __construct(private ChannelRepositoryInterface $channels, private WebsiteImageRepositoryInterface $images, private StorageChannelService $storage, private AdminAccessControlService $access)
    {
    }

    /** @return array{available: bool, message: string, configure_path: string} 存储就绪提示 */
    public function availability(AdminAccount $actor): array
    {
        $this->authorize($actor);
        $available = $this->defaultChannel() !== null;
        return ['available' => $available, 'message' => $available ? '' : '请先配置并启用默认存储通道', 'configure_path' => '/channels/storage'];
    }

    /** @return array{id: string, url: string, alt: string} 稳定公开图片引用 */
    public function upload(AdminAccount $actor, string $content, string $filename): array
    {
        $this->authorize($actor);
        $type = PublicImage::validate($content, $filename);
        $channel = $this->defaultChannel() ?? throw new BusinessException('请先配置并启用默认存储通道', ApiErrorCode::CONFLICT, 409);
        $id = bin2hex(random_bytes(16));
        $key = new ObjectKey('website/images/' . $id . '.' . $type['extension']);
        $this->images->reserve(['id' => $id, 'original_name' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 200), 'extension' => $type['extension'], 'type' => 'image', 'visibility' => 'public', 'namespace' => 'website', 'origin' => 'website', 'channel_id' => $channel['id'], 'channel_revision' => $channel['revision'], 'object_key' => $key->value,
            'mime_type' => $type['mime'], 'byte_size' => strlen($content), 'created_by' => $actor->id, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
        try {
            $result = $this->storage->put($channel['id'], $channel['revision'], new ObjectUpload($key, $content, $type['mime']));
        } catch (\Throwable $error) {
            $this->images->mark($id, 'unknown');
            throw $error;
        }
        $this->images->mark($id, $result->status);
        if ($result->status !== 'stored') throw new BusinessException('图片上传未完成，请检查存储通道', ApiErrorCode::CONFLICT, 409);
        return ['id' => $id, 'url' => self::publicUrl($id), 'alt' => mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, 200)];
    }

    /** 持久化字段只保存标识；浏览器访问时生成当前可用的签名地址。 */
    public function redirectUrl(string $id): string
    {
        $image = $this->images->find($id);
        if ($image === null || $image['status'] !== 'stored') throw new BusinessException('图片不存在或未上传完成', ApiErrorCode::NOT_FOUND, 404);
        return $this->storage->signedUrl($image['channel_id'], $image['channel_revision'], new ObjectKey($image['object_key']))->url;
    }

    /** 配置中不能引用失败、未知或任意未登记图片。 */
    public function requireStored(string $id, string $field): void
    {
        if ($id === '') return;
        if (($this->images->find($id)['status'] ?? '') !== 'stored') \app\modules\website\validation\ThemeSchema::fail($field, '请选择已上传完成的官网图片');
    }

    /** @return string 稳定公开地址，空标识返回空字符串 */
    public static function publicUrl(string $id): string
    {
        return $id === '' ? '' : '/api/public/v1/website/images/' . $id;
    }

    /** @return array{id: int, revision: int}|null 当前默认且可用的存储通道 */
    private function authorize(AdminAccount $actor): void
    {
        $permissions = $this->access->permissions($actor);
        foreach (['admin.website.base', 'admin.page.website'] as $prefix) {
            if (in_array($prefix . '.view', $permissions, true) && in_array($prefix . '.update', $permissions, true)) return;
        }
        $this->access->requirePermission($actor, 'admin.website.base.update');
        $this->access->requirePermission($actor, 'admin.website.base.view');
    }

    private function defaultChannel(): ?array
    {
        foreach ($this->channels->list('storage', ['page' => 1, 'page_size' => 100, 'status' => 1])['data'] as $row) {
            if ($row['is_default'] && $row['configuration_current'] && $row['platform_available']) return ['id' => $row['id'], 'revision' => $row['revision']];
        }
        return null;
    }
}
