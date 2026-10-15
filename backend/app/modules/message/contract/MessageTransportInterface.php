<?php

declare(strict_types=1);

namespace app\modules\message\contract;

interface MessageTransportInterface
{
    /** @return array{status:string,error:string,retry:bool} accepted不等于最终送达；不返回原始厂商响应 */
    public function send(string $channel, string $requestKey, array $payload): array;
}
