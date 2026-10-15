<?php

declare(strict_types=1);

namespace app\modules\finance\contract;

use app\shared\plugin\dto\payment\{PaymentResult, PaymentNotification};
use app\shared\plugin\dto\Configuration;

interface OnlinePaymentGatewayInterface
{
    /** @return list<array{id: int, name: string, plugin_id: string}> */
    public function channels(): array;
    /** @return array{plugin_id: string, package_hash: string, channel_revision: int, configuration: Configuration} */
    public function capture(int $channelId): array;
    /** @param array<string, mixed> $attempt 核心私有执行快照，不得输出到HTTP */
    public function execute(string $operation, array $attempt): PaymentResult;
    /** @param array<string, mixed> $attempt @param array<string, string>|PaymentNotification $notification */
    public function verify(array $attempt, array|PaymentNotification $notification): PaymentResult;
}
