<?php

declare(strict_types=1);

namespace app\modules\content\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\content\contract\ContentRepositoryInterface;
use app\modules\content\domain\ContentHtml;
use app\shared\http\ApiErrorCode;

/** 根据固定内容类型维护分类、文档与公告。 */
final readonly class ManageContentService
{
    public function __construct(private ContentRepositoryInterface $repository, private AdminAccessControlService $access) {}

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function paginate(
        AdminAccount $actor,
        string $kind,
        bool $categories,
        string $keyword,
        ?string $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $this->authorize($actor, $kind, $categories, 'view');
        return $this->repository->paginate($kind, $categories, $keyword, $status, $page, $pageSize, $filters);
    }

    /** @return array<string, mixed> */
    public function detail(AdminAccount $actor, string $kind, bool $categories, int $id): array
    {
        $this->authorize($actor, $kind, $categories, 'view');
        return $this->repository->find($kind, $categories, $id) ?? throw $this->notFound();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(AdminAccount $actor, string $kind, bool $categories, ?int $id, array $input): array
    {
        $this->authorize($actor, $kind, $categories, $id === null ? 'create' : 'update');
        $existing = $id === null ? null : ($this->repository->find($kind, $categories, $id) ?? throw $this->notFound());
        $data = ['kind' => $kind, 'sort_order' => (int) $input['sort_order']];
        if ($categories) {
            $name = trim((string) $input['name']);
            if ($name === '') throw new BusinessException('分类名称不能为空', ApiErrorCode::VALIDATION_FAILED, 422);
            $parentId = isset($input['parent_id']) ? (int) $input['parent_id'] : null;
            if ($parentId !== null) {
                if ($kind !== 'help' || $parentId === $id) {
                    throw new BusinessException('只能为帮助分类选择一级分类作为上级', ApiErrorCode::VALIDATION_FAILED, 422);
                }
                $parent = $this->repository->find($kind, true, $parentId);
                if ($parent === null || $parent['parent_id'] !== null) {
                    throw new BusinessException('上级分类必须是同类型的一级分类', ApiErrorCode::CONFLICT, 409);
                }
                if ($id !== null && $this->repository->categoryHasChildren($id)) {
                    throw new BusinessException('已有子分类的一级分类不能改为二级分类', ApiErrorCode::CONFLICT, 409);
                }
            }
            if ($this->repository->categoryNameExists($kind, $name, $parentId, $id)) {
                throw new BusinessException('同级分类名称已存在', ApiErrorCode::CONFLICT, 409);
            }
            $data += ['name' => $name, 'parent_id' => $parentId, 'status' => (int) $input['status']];
        } else {
            $categoryId = (int) $input['category_id'];
            $category = $this->repository->find($kind, true, $categoryId);
            if ($category === null) throw new BusinessException('分类不存在或类型不匹配', ApiErrorCode::CONFLICT, 409);
            $status = (string) $input['status'];
            $title = trim((string) $input['title']);
            $body = trim((string) $input['body']);
            $format = (string) ($input['body_format'] ?? $existing['body_format'] ?? 'markdown');
            if (!in_array($format, ['markdown', 'html'], true) || ($kind === 'help' && $format !== 'markdown')) {
                throw new BusinessException('帮助文档使用 Markdown，公告支持 Markdown 或富文本', ApiErrorCode::VALIDATION_FAILED, 422);
            }
            if ($format === 'html') {
                $body = ContentHtml::sanitize($body);
                if (!ContentHtml::hasContent($body)) {
                    throw new BusinessException('请输入有效的正文内容', ApiErrorCode::VALIDATION_FAILED, 422);
                }
            }
            if ($title === '' || $body === '') throw new BusinessException('标题和正文不能为空', ApiErrorCode::VALIDATION_FAILED, 422);
            if ($status === 'published' && $category['status'] !== 1) {
                throw new BusinessException('只能发布到启用的分类', ApiErrorCode::CONFLICT, 409);
            }
            if ($status === 'published' && $category['parent_id'] !== null) {
                $parent = $this->repository->find($kind, true, $category['parent_id']);
                if ($parent === null || $parent['status'] !== 1) {
                    throw new BusinessException('上级分类未启用，不能发布内容', ApiErrorCode::CONFLICT, 409);
                }
            }
            $data += [
                'category_id' => $categoryId, 'title' => $title,
                'summary' => trim((string) ($input['summary'] ?? '')),
                'body' => $body, 'body_format' => $format, 'status' => $status,
                'published_at' => $status === 'published' ? ($existing['published_at'] ?? date('Y-m-d H:i:s')) : null,
            ];
        }
        $savedId = $this->repository->save($categories, $id, $data);
        return $this->repository->find($kind, $categories, $savedId) ?? throw $this->notFound();
    }

    public function delete(AdminAccount $actor, string $kind, bool $categories, int $id): void
    {
        $this->authorize($actor, $kind, $categories, 'delete');
        if ($this->repository->find($kind, $categories, $id) === null) throw $this->notFound();
        if ($categories && $this->repository->categoryInUse($id)) {
            throw new BusinessException('分类下已有内容或子分类，不能删除', ApiErrorCode::CONFLICT, 409);
        }
        $this->repository->delete($categories, $id);
    }

    private function authorize(AdminAccount $actor, string $kind, bool $categories, string $action): void
    {
        if (!in_array($kind, ['help', 'news'], true)) throw $this->notFound();
        $this->access->requirePermission($actor, 'admin.content.' . $kind . ($categories ? '-categories' : '-entries') . '.' . $action);
    }

    private function notFound(): BusinessException
    {
        return new BusinessException('内容记录不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
