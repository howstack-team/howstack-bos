<?php

declare(strict_types=1);

namespace app\modules\plugin\infrastructure;

/** 可公开的静态包校验错误；不公开加载器与第三方运行异常。 */
final class PluginPackageValidationException extends \RuntimeException {}
