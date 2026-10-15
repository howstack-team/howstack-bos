<?php

declare(strict_types=1);

namespace plugins\mail\smtp\app;

use PHPMailer\PHPMailer\SMTP;

/** 记录 DATA 阶段；连接/认证失败明确失败，DATA 后断线不冒充安全重试。 */
final class TrackedSmtp extends SMTP
{
    public bool $dataStarted = false;
    public bool $accepted = false;
    public bool $rejected = false;

    /** @param string $msg_data MIME 邮件 @return bool 服务器确认接收 */
    public function data($msg_data)
    {
        $this->dataStarted = true;
        $result = parent::data($msg_data);
        $this->accepted = $result;
        $code = (int) ($this->getError()['smtp_code'] ?? 0);
        $this->rejected = !$result && $code >= 400 && $code <= 599;
        return $result;
    }
}
