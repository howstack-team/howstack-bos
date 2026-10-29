<?php

declare(strict_types=1);

namespace app\modules\install\infrastructure;

use app\modules\install\contract\EnvironmentInspectorInterface;
use app\modules\plugin\infrastructure\PluginDependencyLoader;
use Throwable;

/**
 * 在核心迁移之前静态检查随发行包提供的内置渠道依赖。
 */
final readonly class BuiltinChannelDependencyInspector implements EnvironmentInspectorInterface
{
    /**
     * @var array<string, list<string>> 与历史内置渠道登记范围一致，不自动安装第三方包
     */
    private const CHANNELS = [
        'payment' => ['alipay_order_code', 'wechat_native'],
        'sms' => ['aliyun_sms', 'tencent_sms', 'submail_sms', 'smsbao'],
        'mail' => ['smtp', 'aliyun_dm', 'tencent_ses'],
        'identity' => ['alipay_identity', 'tencent_faceid'],
        'storage' => ['aliyun_oss', 'tencent_cos', 'qiniu_storage', 'local_storage'],
        'oauth' => ['wechat_login', 'qq_login'],
        'captcha' => ['image_captcha', 'geetest_v4', 'yidun_captcha', 'tencent_captcha', 'aliyun_captcha'],
    ];

    /**
     * 设置待检查的发行目录，测试可使用独立临时目录。
     * @param string|null $pluginsPath 内置插件根目录，默认使用当前后端的 plugins
     */
    public function __construct(private ?string $pluginsPath = null)
    {
    }

    /**
     * 只读取锁文件和已安装依赖清单，不执行插件或 Composer 自动加载代码。
     * @return array{passed: bool, items: list<array<string, bool|string>>} 固定渠道的生产依赖检测结果
     */
    public function inspect(): array
    {
        $root = $this->pluginsPath ?? base_path('plugins');
        $items = [];
        $passed = true;

        foreach (self::CHANNELS as $category => $ids) {
            foreach ($ids as $id) {
                $folder = $root . '/' . $category . '/' . $id;
                if (!is_dir($folder)) {
                    // 与历史迁移保持一致：发行包没有携带的可选渠道不登记。
                    continue;
                }
                if (!file_exists($folder . '/composer.json')
                    && !file_exists($folder . '/composer.lock')
                    && !file_exists($folder . '/vendor')) {
                    continue;
                }

                $valid = true;
                $missing = !is_file($folder . '/composer.lock')
                    || !is_file($folder . '/vendor/autoload.php')
                    || !is_file($folder . '/vendor/composer/installed.json');
                try {
                    if (is_link($root) || is_link(dirname($folder)) || is_link($folder)) {
                        throw new \RuntimeException('发行目录不能使用符号链接');
                    }
                    PluginDependencyLoader::validate($folder, 'plugins\\' . $category . '\\' . $id . '\\');
                } catch (Throwable) {
                    $valid = false;
                    $passed = false;
                }

                $relative = 'plugins/' . $category . '/' . $id;
                $items[] = [
                    'key' => 'builtin_channel_' . $id . '_dependencies',
                    'name' => '内置渠道依赖：' . $id,
                    'value' => $valid ? '已构建' : ($missing ? '发行依赖缺失' : '依赖校验失败'),
                    'passed' => $valid,
                    'required' => true,
                    'help' => $valid ? '' : '内置渠道 ' . $id . ' 的生产依赖未构建或与锁文件不一致。'
                        . '请在发行构建环境的 backend 目录执行 composer install --working-dir=' . $relative
                        . ' --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins，'
                        . '并完整交付该插件的 composer.json、composer.lock 与 vendor 后重试。',
                ];
            }
        }

        return ['passed' => $passed, 'items' => $items];
    }
}
