<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\captcha\CaptchaContext;
use app\shared\plugin\dto\captcha\CaptchaChallenge;
use app\shared\plugin\dto\captcha\CaptchaProof;
use app\shared\plugin\dto\captcha\CaptchaResult;

/** v1 人机验证固定接口；插件只处理证明，不创建会话或执行业务。 */
interface CaptchaAdapterInterface extends ConfigurablePluginInterface
{
    /**
     * @param CaptchaContext $context 服务端绑定场景
     * @param Configuration $configuration 单次通道配置
     * @return CaptchaChallenge 公开初始化信息
     */
    public function initialize(CaptchaContext $context, Configuration $configuration): CaptchaChallenge;

    /**
     * @param CaptchaProof $proof 原始平台证明
     * @param CaptchaContext $context 服务端绑定场景
     * @param Configuration $configuration 单次通道配置
     * @return string 64 位 SHA256 指纹，稳定绑定平台票据及应用，供宿主拒绝重复提交
     */
    public function fingerprint(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): string;

    /**
     * @param CaptchaProof $proof 原始平台证明
     * @param CaptchaContext $context 服务端绑定场景
     * @param Configuration $configuration 单次通道配置
     * @return CaptchaResult 二次验证结果
     */
    public function verify(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): CaptchaResult;
}
