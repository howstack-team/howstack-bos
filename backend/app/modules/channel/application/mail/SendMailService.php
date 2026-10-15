<?php

declare(strict_types=1);

namespace app\modules\channel\application\mail;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\contract\mail\MailAdapterExecutorInterface;
use app\modules\channel\contract\mail\MailDeliveryRepositoryInterface;
use app\modules\channel\validation\ChannelValidator;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use InvalidArgumentException;
use Throwable;

/** 邮件业务发送及管理员测试；相同请求不重复执行，未知结果不自动重试。 */
final readonly class SendMailService
{
    /**
     * @param MailDeliveryRepositoryInterface $deliveries 原子领取
     * @param MailAdapterExecutorInterface $executor 固定插件
     * @param AdminAccessControlService $access 后台权限
     * @param ChannelValidator $validator 通道身份版本
     */
    public function __construct(
        private MailDeliveryRepositoryInterface $deliveries,
        private MailAdapterExecutorInterface $executor,
        private AdminAccessControlService $access,
        private ChannelValidator $validator,
    ) {
    }

    /**
     * @param AdminAccount $actor 已认证管理员
     * @param mixed $id 邮件通道
     * @param array<string, mixed> $input 测试收件邮箱、UUID及配置版本
     * @return array<string, string> 安全发送结果
     */
    public function test(AdminAccount $actor, mixed $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.channels.mail.view');
        $this->access->requirePermission($actor, 'admin.channels.mail.test');
        if (array_diff(array_keys($input), ['recipient', 'request_key', 'revision']) !== []) {
            throw new BusinessException('测试发送参数无效', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        try {
            if (!is_string($input['recipient'] ?? null) || !is_string($input['request_key'] ?? null)) {
                throw new InvalidArgumentException();
            }
            $message = new MailMessage($input['request_key'], trim($input['recipient']), 'SMTP 邮件通道测试',
                '这是一封由云业务运营后台发送的 SMTP 测试邮件。收到此邮件表示当前邮件通道已能正常发送。');
        } catch (InvalidArgumentException) {
            throw new BusinessException('请填写有效的收件邮箱和发送请求标识', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        return $this->dispatch($this->validator->id($id), $this->validator->revision($input), $message, $actor->id)->toArray();
    }

    /**
     * @param MailMessage $message 含业务事件 UUID 的邮件
     * @return MailResult 默认通道发送结果
     */
    public function sendDefault(MailMessage $message): MailResult
    {
        $channel = $this->deliveries->defaultChannel();
        return $this->send($channel['id'], $channel['revision'], $message);
    }

    /**
     * @param int $channelId 启用邮件通道
     * @param int $revision 预期配置版本
     * @param MailMessage $message 单封业务邮件
     * @return MailResult SMTP 接收结果
     */
    public function send(int $channelId, int $revision, MailMessage $message): MailResult
    {
        return $this->dispatch($channelId, $revision, $message, null);
    }

    /**
     * @param int $id 通道
     * @param int $revision 配置版本
     * @param MailMessage $message 邮件
     * @param int|null $testActor 测试允许禁用通道
     * @return MailResult 固定结果
     */
    private function dispatch(int $id, int $revision, MailMessage $message, ?int $testActor): MailResult
    {
        $claim = $this->deliveries->claim($id, $revision, $message, $testActor);
        if ($claim['cached'] !== null) {
            return $claim['cached'];
        }
        try {
            $result = $this->executor->send($claim['plugin_id'], $claim['configuration'], $message);
            if ($result->messageId !== $message->messageId()) {
                throw new InvalidArgumentException('邮件插件返回了不同请求的结果');
            }
        } catch (Throwable) {
            // 插件可能在发送后抛出；宿主不能断言邮件没有产生副作用。
            $result = new MailResult('unknown', $message->messageId());
        }
        $this->deliveries->finish($message->key, $result);
        return $result;
    }
}
