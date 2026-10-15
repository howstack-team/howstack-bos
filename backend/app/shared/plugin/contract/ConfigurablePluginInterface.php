<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\exception\PluginException;

/** 公共配置接口；只补充平台规则，没有外部副作用。 */
interface ConfigurablePluginInterface
{
    /**
     * 规范化宿主已校验的配置。
     * @param Configuration $configuration 当前配置
     * @return Configuration 规范配置，字段仍由宿主再校验
     * @throws PluginException 平台规则不满足
     */
    public function validateConfiguration(Configuration $configuration): Configuration;
}
