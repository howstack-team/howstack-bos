<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\template;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** 模板审核输入；只包含静态模板和示例值，不携带实际会员、密码或验证码。 */
final readonly class TemplateSubmission implements JsonSerializable
{
    /**
     * @param string $key 宿主按模板修订、通道及本次申请预留的稳定请求键，不替代数据库幂等
     * @param string $channel sms 或 mail
     * @param string $name 模板名称，最多 128 个字符
     * @param string $purpose verification、notification、marketing 或 system，由插件映射平台类型
     * @param string $content 正文；占位符统一为 {{name}}，插件转换为厂商语法
     * @param string $subject 邮件标题，短信必须为空
     * @param string $format text 或 html；短信仅允许 text
     * @param array<string, string> $variables 按声明顺序给出的变量和静态示例，最多 32 项
     * @param string $description 模板用途说明；无需审核材料的厂商可忽略
     * @throws InvalidArgumentException 模板或占位符不符合契约
     */
    public function __construct(
        public string $key,
        public string $channel,
        public string $name,
        public string $purpose,
        public string $content,
        public string $subject = '',
        public string $format = 'text',
        public array $variables = [],
        public string $description = '',
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key)
            || !in_array($channel, ['sms', 'mail'], true)
            || !in_array($purpose, ['verification', 'notification', 'marketing', 'system'], true)
            || !in_array($format, ['text', 'html'], true)
            || trim($name) === '' || mb_strlen($name) > 128 || trim($content) === ''
            || strlen($content) > 65536 || mb_strlen($subject) > 256 || mb_strlen($description) > 1000
            || ($channel === 'sms' && ($format !== 'text' || $subject !== '' || mb_strlen($content) > 2048))
            || ($channel === 'mail' && trim($subject) === '')
            || count($variables) > 32 || ($variables !== [] && array_is_list($variables))) {
            throw new InvalidArgumentException('模板提交输入无效');
        }
        foreach ([$name, $content, $subject, $description] as $value) {
            if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('模板文本无效');
            }
        }
        foreach ($variables as $variable => $sample) {
            if (!is_string($variable) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $variable)
                || !is_string($sample) || mb_strlen($sample) > 256 || preg_match('//u', $sample) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $sample)) {
                throw new InvalidArgumentException('模板变量无效');
            }
        }
        $text = $subject . "\n" . $content;
        preg_match_all('/\{\{([A-Za-z][A-Za-z0-9_]{0,63})\}\}/', $text, $matches);
        $remaining = preg_replace('/\{\{([A-Za-z][A-Za-z0-9_]{0,63})\}\}/', '', $text);
        if (array_diff($matches[1], array_keys($variables)) !== []
            || str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
            throw new InvalidArgumentException('模板占位符必须声明并使用 {{name}} 格式');
        }
    }

    /** @return array{redacted: true} 模板及示例不进入普通日志 */
    public function jsonSerialize(): array { return ['redacted' => true]; }

    /** @return array{redacted: true} 调试输出不包含模板内容 */
    public function __debugInfo(): array { return ['redacted' => true]; }

    /** @return never @throws LogicException 队列应保存宿主持久化的模板、申请和修订引用 */
    public function __serialize(): array { throw new LogicException('模板提交输入不能序列化，请保存模板申请引用'); }
}
