<?php

declare(strict_types=1);

namespace app\modules\message\application;

use app\modules\message\contract\MessageRepositoryInterface;
use app\modules\message\validation\MessageQueryValidator;
use InvalidArgumentException;

/** 账户身份仅来自认证上下文，不接受前端传入其他收件人的主键。 */
final readonly class MessageInboxService
{
    public function __construct(private MessageRepositoryInterface $messages, private MessageQueryValidator $queries)
    {
    }

    public function list(string $audience, int $accountId, array $query): array
    {
        $this->identity($audience, $accountId);
        return $this->messages->inbox($audience, $accountId, $this->queries->query($query));
    }

    public function summary(string $audience, int $accountId): array
    {
        $this->identity($audience, $accountId);
        return $this->messages->summary($audience, $accountId);
    }

    public function read(string $audience, int $accountId, ?int $id): void
    {
        $this->identity($audience, $accountId);
        $this->messages->read($audience, $accountId, $id);
    }

    private function identity(string $audience, int $id): void
    {
        if (!in_array($audience, ['customer', 'admin'], true) || $id < 1) {
            throw new InvalidArgumentException('收件人身份无效');
        }
    }
}
