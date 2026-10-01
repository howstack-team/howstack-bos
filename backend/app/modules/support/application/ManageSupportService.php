<?php

declare(strict_types=1);

namespace app\modules\support\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\support\contract\SupportRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 工单分类、快捷回复及工单会话的后台用例。 */
final readonly class ManageSupportService
{
    private const array CATALOG = [
        'types' => ['ticket_types', 'admin.tickets.types'],
        'quick-replies' => ['ticket_quick_replies', 'admin.tickets.quick-replies'],
    ];

    public function __construct(private SupportRepositoryInterface $repository, private AdminAccessControlService $access) {}

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function catalog(AdminAccount $actor, string $kind, string $keyword, ?int $status, int $page, int $pageSize): array
    {
        [$table, $permission] = $this->catalogSpec($kind);
        $this->access->requirePermission($actor, $permission . '.view');
        return $this->repository->catalog($table, $keyword, $status, $page, $pageSize);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function saveCatalog(AdminAccount $actor, string $kind, ?int $id, array $input): array
    {
        [$table, $permission] = $this->catalogSpec($kind);
        $this->access->requirePermission($actor, $permission . '.' . ($id === null ? 'create' : 'update'));
        if ($id !== null && $this->repository->catalogRow($table, $id) === null) throw $this->notFound();
        $data = ['sort_order' => (int) $input['sort_order'], 'status' => (int) $input['status']];
        if ($kind === 'types') {
            $code = trim((string) $input['code']);
            $name = trim((string) $input['name']);
            if ($name === '') throw new BusinessException('工单类型名称不能为空', ApiErrorCode::VALIDATION_FAILED, 422);
            if ($this->repository->typeCodeExists($code, $id)) throw new BusinessException('工单类型编码已存在', ApiErrorCode::CONFLICT, 409);
            $data += ['name' => $name, 'code' => $code];
        } else {
            $title = trim((string) $input['title']);
            $content = trim((string) $input['content']);
            if ($title === '' || $content === '') throw new BusinessException('快捷回复标题和内容不能为空', ApiErrorCode::VALIDATION_FAILED, 422);
            $data += ['title' => $title, 'content' => $content];
        }
        $savedId = $this->repository->saveCatalog($table, $id, $data);
        return $this->repository->catalogRow($table, $savedId) ?? throw $this->notFound();
    }

    public function deleteCatalog(AdminAccount $actor, string $kind, int $id): void
    {
        [$table, $permission] = $this->catalogSpec($kind);
        $this->access->requirePermission($actor, $permission . '.delete');
        if ($this->repository->catalogRow($table, $id) === null) throw $this->notFound();
        if ($kind === 'types' && $this->repository->typeInUse($id)) {
            throw new BusinessException('该类型已有工单，不能删除', ApiErrorCode::CONFLICT, 409);
        }
        $this->repository->deleteCatalog($table, $id);
    }

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function tickets(AdminAccount $actor, string $keyword, ?string $status, int $page, int $pageSize): array
    {
        $this->access->requirePermission($actor, 'admin.tickets.list.view');
        return $this->repository->tickets($keyword, $status, $page, $pageSize);
    }

    /** @return array{ticket: array<string, mixed>, messages: list<array<string, mixed>>} */
    public function ticket(AdminAccount $actor, int $id): array
    {
        $this->access->requirePermission($actor, 'admin.tickets.list.view');
        $ticket = $this->repository->ticket($id) ?? throw $this->notFound();
        return ['ticket' => $ticket, 'messages' => $this->repository->messages($id)];
    }

    public function reply(AdminAccount $actor, int $id, string $content): void
    {
        $this->access->requirePermission($actor, 'admin.tickets.list.reply');
        $content = trim($content);
        if ($content === '') throw new BusinessException('回复内容不能为空', ApiErrorCode::VALIDATION_FAILED, 422);
        if (!$this->repository->reply($id, $actor->id, $content)) {
            throw new BusinessException('工单不存在或已关闭', ApiErrorCode::CONFLICT, 409);
        }
    }

    /** @return array{string, string} */
    private function catalogSpec(string $kind): array
    {
        return self::CATALOG[$kind] ?? throw $this->notFound();
    }

    private function notFound(): BusinessException
    {
        return new BusinessException('记录不存在', ApiErrorCode::NOT_FOUND, 404);
    }
}
