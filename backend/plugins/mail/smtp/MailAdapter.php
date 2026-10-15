<?php

declare(strict_types=1);

namespace plugins\mail\smtp;

use app\shared\plugin\contract\MailAdapterInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use PHPMailer\PHPMailer\PHPMailer;
use plugins\mail\smtp\app\SmtpConfiguration;
use plugins\mail\smtp\app\TrackedSmtp;
use Throwable;

/** SMTP 的配置与发送逻辑属于插件，宿主负责权限、配置保存与发送记录。 */
final readonly class MailAdapter implements MailAdapterInterface
{
    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        return SmtpConfiguration::normalize($configuration);
    }

    /** @inheritDoc */
    public function send(MailMessage $message, Configuration $configuration): MailResult
    {
        $config = $this->validateConfiguration($configuration)->values;
        $smtp = new TrackedSmtp();
        $smtp->Timeout = $smtp->Timelimit = $config['timeout'];
        // 常驻进程下每次新建客户端，避免凭据、收件人和连接跨请求复用。
        $mail = new PHPMailer(true);
        $mail->setSMTPInstance($smtp);
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->SMTPKeepAlive = false;
        $mail->SMTPAutoTLS = false;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
        $mail->Host = $config['host'];
        $mail->Port = $config['port'];
        $mail->Timeout = $config['timeout'];
        $mail->SMTPSecure = match ($config['encryption']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        $mail->SMTPAuth = $config['auth'];
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->MessageID = $message->messageId();

        try {
            $mail->setFrom($config['from_email'], $config['from_name']);
            if ($config['reply_to'] !== '') {
                $mail->addReplyTo($config['reply_to']);
            }
            $mail->addAddress($message->recipient);
            $mail->Subject = $message->subject;
            $mail->isHTML($message->html !== '');
            $mail->Body = $message->html !== '' ? $message->html : $message->text;
            $mail->AltBody = $message->html !== '' ? $message->text : '';
            $mail->send();
            return new MailResult('accepted', $message->messageId());
        } catch (Throwable) {
            // 不输出 ErrorInfo、异常链或 SMTP 回包；DATA 后断线不能安全重发。
            $status = $smtp->accepted ? 'accepted' : ($smtp->dataStarted && !$smtp->rejected ? 'unknown' : 'failed');
            return new MailResult($status, $message->messageId());
        } finally {
            $mail->smtpClose();
        }
    }
}
