<?php

declare(strict_types=1);

namespace app\modules\channel\application;

use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\shared\plugin\dto\template\{TemplateReviewReference, TemplateReviewResult, TemplateSubmission, TemplateSubmissionResult};
use app\shared\plugin\exception\PluginException;
use Throwable;

/** 宿主内部模板审核门面；调用方必须先校验权限、锁定模板版本并持久化申请及幂等记录。 */
final readonly class MessageTemplateReviewService
{
    /** @param ChannelExecutionConfiguration $configuration 原通道配置边界 @param TransactionAdapterFactoryInterface $adapters 固定类别加载器 */
    public function __construct(private ChannelExecutionConfiguration $configuration, private TransactionAdapterFactoryInterface $adapters) {}

    /**
     * @param int $channelId 已授权且启用的原通道
     * @param int $revision 本次申请绑定的通道修订
     * @param TemplateSubmission $template 已保存修订的模板快照
     * @return TemplateSubmissionResult 仅提交审核，不发送消息；unknown 时调用方不得重新提交
     * @throws PluginException result_unknown 表示插件执行异常，宿主必须按结果未知持久化并保留异常链
     */
    public function submit(int $channelId, int $revision, TemplateSubmission $template): TemplateSubmissionResult
    {
        [$id, $configuration] = $this->configuration->resolve($template->channel, $channelId, $revision, true);
        $adapter = $this->adapters->templateSubmission($template->channel, $id);
        try {
            return $adapter->submitTemplate($template, $configuration);
        } catch (Throwable $error) {
            throw new PluginException('模板提交结果未知，请核实原平台申请', 'result_unknown', $error);
        }
    }

    /**
     * @param int $channelId 宿主从申请记录读取的原通道，禁止以当前默认通道替代
     * @param int $revision 原申请绑定的通道修订
     * @param TemplateReviewReference $reference 原申请的审核引用
     * @return TemplateReviewResult 已停用通道仍可在配置未变化且插件可用时只读查询
     * @throws PluginException result_unknown 为查询异常，invalid_result 为申请引用不匹配
     */
    public function query(int $channelId, int $revision, TemplateReviewReference $reference): TemplateReviewResult
    {
        [$id, $configuration] = $this->configuration->resolve($reference->channel, $channelId, $revision, false);
        $adapter = $this->adapters->templateReviewQuery($reference->channel, $id);
        try {
            $result = $adapter->queryTemplateReview($reference, $configuration);
        } catch (Throwable $error) {
            throw new PluginException('模板审核状态未知，请稍后查询原平台申请', 'result_unknown', $error);
        }
        if ($result->providerReference !== $reference->providerReference) {
            throw new PluginException('模板审核结果与原申请不匹配', 'invalid_result');
        }
        return $result;
    }
}
