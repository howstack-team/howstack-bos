<?php

declare(strict_types=1);

namespace app\modules\message_configuration\infrastructure;

use app\exception\BusinessException;
use app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface;
use app\modules\message_configuration\infrastructure\model\MessageConfigurationModel;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;
use Illuminate\Database\QueryException;

/** 原子保存整条消息的所有渠道，修订冲突时不覆盖另一管理员的修改。 */
final readonly class DatabaseMessageConfigurationRepository implements MessageConfigurationRepositoryInterface
{
    public function __construct(
        private SensitiveDataCipherInterface $cipher,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function all(string $audience): array
    {
        return MessageConfigurationModel::query()->where('audience', $audience)->get()
            ->mapWithKeys(fn (MessageConfigurationModel $row): array => [$row->message_type => $this->values($row)])->all();
    }

    public function get(string $audience, string $code): array
    {
        $row = MessageConfigurationModel::query()->where('audience', $audience)->where('message_type', $code)->first();
        return $row === null ? ['revision' => 0, 'configurations' => []] : $this->values($row);
    }

    public function save(string $audience, string $code, int $revision, array $configurations): void
    {
        try {
            $this->transactions->run(function () use ($audience, $code, $revision, $configurations): void {
                $row = MessageConfigurationModel::query()->where('audience', $audience)->where('message_type', $code)->lockForUpdate()->first();
                if (($row?->revision ?? 0) !== $revision) {
                    throw new BusinessException('消息配置已变化，请重新打开后编辑', ApiErrorCode::CONFLICT, 409);
                }
                $row ??= new MessageConfigurationModel();
                $row->fill([
                    'audience' => $audience, 'message_type' => $code,
                    'configuration_encrypted' => $this->cipher->encrypt(json_encode($configurations, JSON_THROW_ON_ERROR)),
                    'revision' => $revision + 1,
                ])->save();
            });
        } catch (QueryException $error) {
            if (in_array((string) ($error->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
                throw new BusinessException('消息配置已变化，请重新打开后编辑', ApiErrorCode::CONFLICT, 409, previous: $error);
            }
            throw $error;
        }
    }

    /** @return array{revision: int, configurations: array<string, mixed>} 内部配置 */
    private function values(MessageConfigurationModel $row): array
    {
        return ['revision' => $row->revision,
            'configurations' => json_decode($this->cipher->decrypt($row->configuration_encrypted), true, flags: JSON_THROW_ON_ERROR)];
    }
}
