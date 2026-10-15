<?php

declare(strict_types=1);

namespace app\shared\entitlement;

/** 商业权益判断的操作语义，不能用版本名称代替。 */
enum EntitlementOperation: string
{
    case ReadExisting = 'read_existing';
    case Configure = 'configure';
    case StartNew = 'start_new';
    case CompleteExisting = 'complete_existing';
    case MaintainExisting = 'maintain_existing';
    case DownloadUpdate = 'download_update';
}
