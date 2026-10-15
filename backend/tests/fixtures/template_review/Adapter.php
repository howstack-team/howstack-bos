<?php

declare(strict_types=1);

namespace plugins\__CATEGORY__\__PLUGIN_ID__;

use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\template\{TemplateReviewReference, TemplateReviewResult, TemplateSubmission, TemplateSubmissionResult};
use app\shared\plugin\exception\PluginException;
use Throwable;

/** 审核协议测试骨架；仅由测试的 MockHandler 提供外部响应。 */
final readonly class __ENTRY__ implements __INTERFACES__
{
    public function __construct(private HttpClientInterface $http) {}

    public function validateConfiguration(Configuration $configuration): Configuration { return $configuration; }

    public function send(__MESSAGE__ $message, Configuration $configuration): __SEND_RESULT__
    {
        throw new PluginException('审核测试不得发送消息', 'unexpected_send');
    }

    public function submitTemplate(TemplateSubmission $template, Configuration $configuration): TemplateSubmissionResult
    {
        try {
            $response = $this->http->post($configuration->values['api_url'] . '/templates', [
                'request_key' => $template->key, 'name' => $template->name, 'purpose' => $template->purpose,
                'content' => $template->content, 'subject' => $template->subject, 'format' => $template->format,
                'variables' => $template->variables, 'description' => $template->description,
            ], ['Authorization' => 'Bearer ' . $configuration->values['api_key']]);
            if ($response->status === 400) return new TemplateSubmissionResult('failed', reason: '模板内容不符合平台要求');
            if (!$response->isSuccessful()) return new TemplateSubmissionResult('unknown');
            $data = $response->json();
            return new TemplateSubmissionResult('accepted', $data['reference']);
        } catch (Throwable) {
            return new TemplateSubmissionResult('unknown');
        }
    }

    public function queryTemplateReview(TemplateReviewReference $reference, Configuration $configuration): TemplateReviewResult
    {
        try {
            $response = $this->http->get($configuration->values['api_url'] . '/templates/' . rawurlencode($reference->providerReference), [],
                ['Authorization' => 'Bearer ' . $configuration->values['api_key']]);
            if ($response->status === 404) return new TemplateReviewResult('not_found', $reference->providerReference);
            if (!$response->isSuccessful()) return new TemplateReviewResult('unknown', $reference->providerReference);
            $data = $response->json();
            return new TemplateReviewResult($data['status'], $data['reference'] ?? $reference->providerReference, $data['template_id'] ?? null,
                $data['status'] === 'rejected' ? '模板内容不符合平台要求' : '');
        } catch (Throwable) {
            return new TemplateReviewResult('unknown', $reference->providerReference);
        }
    }
}
