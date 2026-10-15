<?php

declare(strict_types=1);

namespace app\modules\website\infrastructure;

use app\modules\website\contract\WebsiteRendererInterface;

/** 通过实现 Webman View 接口的专用适配器调用 Twig，不改变系统 Raw 视图。 */
final readonly class TwigWebsiteRenderer implements WebsiteRendererInterface
{
    public function __construct(private string $cache)
    {
    }

    /** @inheritDoc */
    public function render(array $package, string $template, array $context, array $routes): string
    {
        return ThemeView::render($template, ['package' => $package, 'context' => $context, 'routes' => $routes, 'cache' => $this->cache]);
    }
}
