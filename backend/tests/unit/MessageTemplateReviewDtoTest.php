<?php

declare(strict_types=1);

namespace tests\unit;

use app\shared\plugin\dto\template\{TemplateReviewResult, TemplateSubmission, TemplateSubmissionResult};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 模板输入、受理状态与审核状态保持明确边界，错误组合不能作为通过结果使用。 */
final class MessageTemplateReviewDtoTest extends TestCase
{
    public function testDeclaredVariablesKeepOrderAndStaticSamplesAreRedacted(): void
    {
        $template = new TemplateSubmission('template-7', 'sms', '通知', 'notification', '{{first}}：{{second}}', variables: ['first' => '0', 'second' => '示例']);
        self::assertSame(['first', 'second'], array_keys($template->variables));
        self::assertSame('0', $template->variables['first']);
        self::assertSame('{"redacted":true}', json_encode($template, JSON_THROW_ON_ERROR));
        self::assertSame(['redacted' => true], $template->__debugInfo());
        $this->expectException(\LogicException::class);
        serialize($template);
    }

    #[DataProvider('invalidTemplates')]
    public function testInvalidTemplateCannotReachAnAdapter(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TemplateSubmission(...$input);
    }

    /** @return iterable<string, array{array<string, mixed>}> 不合法的类别、文本与变量组合 */
    public static function invalidTemplates(): iterable
    {
        $base = ['key' => 'template-1', 'channel' => 'sms', 'name' => '通知', 'purpose' => 'notification', 'content' => '服务已开通'];
        yield 'unsupported channel' => [array_replace($base, ['channel' => 'payment'])];
        yield 'missing variable' => [array_replace($base, ['content' => '{{code}}'])];
        yield 'unrecognized placeholder' => [array_replace($base, ['content' => '{{ code }}', 'variables' => ['code' => '示例']])];
        yield 'sms html' => [array_replace($base, ['format' => 'html'])];
        yield 'sms subject' => [array_replace($base, ['subject' => '邮件标题'])];
        yield 'mail without subject' => [array_replace($base, ['channel' => 'mail'])];
        yield 'invalid sample' => [array_replace($base, ['content' => '{{code}}', 'variables' => ['code' => "值\n值"]])];
        yield 'invalid variable name' => [array_replace($base, ['variables' => ['account.token' => '示例']])];
        yield 'list instead of named variables' => [array_replace($base, ['variables' => ['示例']])];
        yield 'control character' => [array_replace($base, ['content' => "内容\0"] )];
        yield 'oversized email' => [array_replace($base, ['channel' => 'mail', 'subject' => '邮件标题', 'content' => str_repeat('x', 65537)])];
    }

    public function testAcceptedSubmissionMustHaveAQueryableReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TemplateSubmissionResult('accepted');
    }

    public function testSubmissionCannotDeclareReviewApproval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TemplateSubmissionResult('approved', 'application');
    }

    public function testApprovedReviewRequiresTheSendTemplateId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TemplateReviewResult('approved', 'application');
    }

    public function testUnknownIsNeitherRejectionNorApproval(): void
    {
        $submitted = new TemplateSubmissionResult('unknown');
        $review = new TemplateReviewResult('unknown', 'application');
        self::assertNull($submitted->providerReference);
        self::assertNull($review->providerTemplateId);
        self::assertSame('unknown', $review->status);
        $approved = new TemplateReviewResult('approved', 'application', 'SEND_TEMPLATE');
        self::assertNotSame($approved->providerReference, $approved->providerTemplateId);
    }
}
