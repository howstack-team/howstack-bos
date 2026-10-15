<?php

declare(strict_types=1);

namespace tests\unit\modules\content\application;

use app\modules\content\domain\ContentHtml;
use app\modules\content\domain\ContentImage;
use app\exception\BusinessException;
use PHPUnit\Framework\TestCase;

final class ContentHtmlTest extends TestCase
{
    public function testRemovesExecutableHtmlAndKeepsSupportedFormatting(): void
    {
        $html = ContentHtml::sanitize('<h2 style="text-align:center;position:fixed">公告</h2><script>alert(1)</script><iframe src="https://evil.test"></iframe><p><strong>加粗</strong><u>下划线</u><s>删除线</s></p><a href="javascript:alert(1)">链接</a><img src="https://example.test/a.png" onerror="alert(1)"><table><tr><th>项目</th><td>正常</td></tr></table>');
        self::assertStringContainsString('text-align:center', $html);
        self::assertStringContainsString('<strong>加粗</strong>', $html);
        self::assertStringContainsString('<table>', $html);
        foreach (['script', 'iframe', 'onerror', 'javascript:', 'position:', 'alert('] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $html);
        }
    }

    public function testRejectsEmptyRichTextButAcceptsImageOnlyContent(): void
    {
        self::assertFalse(ContentHtml::hasContent(ContentHtml::sanitize('<p><br>&nbsp;</p>')));
        self::assertFalse(ContentHtml::hasContent(ContentHtml::sanitize('<script>alert(1)</script>')));
        self::assertTrue(ContentHtml::hasContent(ContentHtml::sanitize('<img src="/api/public/v1/content/images/' . str_repeat('a', 32) . '">')));
        self::assertTrue(ContentHtml::hasContent(ContentHtml::sanitize('<p>正文</p>')));
    }

    public function testImageChecksActualBytesAndFilename(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jFjkAAAAASUVORK5CYII=');
        self::assertSame(['mime' => 'image/png', 'extension' => 'png'], ContentImage::validate($png, '上传.PNG'));
        $this->expectException(BusinessException::class);
        ContentImage::validate($png, 'image.php');
    }

    public function testDisguisedExecutableImageIsRejected(): void
    {
        $this->expectException(BusinessException::class);
        ContentImage::validate('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 'photo.png');
    }
}
