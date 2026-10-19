<?php

declare(strict_types=1);

namespace app\modules\plugin\infrastructure;

use app\modules\plugin\contract\PluginPackageRegistryInterface;
use app\shared\plugin\contract\ProviderAdapterInterface;
use app\shared\plugin\contract\MailAdapterInterface;
use app\shared\plugin\contract\PaymentAdapterInterface;
use app\shared\plugin\contract\IdentityAdapterInterface;
use app\modules\plugin\infrastructure\PluginPackageValidationException as RuntimeException;
use Throwable;

/** 分类 JSON 注册；单包错误隔离，不执行配置 PHP 或启动脚本。 */
final class LocalPluginPackageRegistry implements PluginPackageRegistryInterface
{
    public const CATEGORIES = ['provider', 'payment', 'identity', 'sms', 'mail', 'storage', 'oauth', 'captcha', 'product', 'feature'];

    /** @var array<string, array<string, mixed>> 受控包快照 */
    private array $packages = [];

    /** @var list<array{id: string, category: string, message: string}> 安全失败信息 */
    private array $failures = [];

    /**
     * 只扫描已知分类，并校验标识冲突。
     * @param string $root 插件根目录
     * @param string $coreVersion 宿主代码版本
     */
    public function __construct(string $root, string $coreVersion)
    {
        $seen = [];
        foreach (self::CATEGORIES as $category) {
            foreach (glob(rtrim($root, '/') . '/' . $category . '/*/plugin.json') ?: [] as $path) {
                $id = basename(dirname($path));
                try {
                    if (isset($seen[$id])) {
                        unset($this->packages[$id]);
                        throw new RuntimeException('插件标识重复，冲突包全部停用');
                    }
                    $seen[$id] = true;
                    $this->packages[$id] = $this->readPackage($path, $category, $id, $coreVersion);
                } catch (Throwable $error) {
                    $this->failures[] = ['id' => $id, 'category' => $category, 'message' => $error instanceof RuntimeException ? $error->getMessage() : '插件包结构或入口无效，请检查后重新加载'];
                }
            }
        }
    }

    /** @inheritDoc */
    public function packages(): array
    {
        return $this->packages;
    }

    /** @inheritDoc */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * 验证固定清单与接口，只接受当前已实现的 SDK。
     * @param string $path 清单路径
     * @param string $category 目录类别
     * @param string $id 目录身份
     * @param string $coreVersion 核心版本
     * @return array<string, mixed> 宿主内部包资料
     * @throws RuntimeException 包或版本无效
     */
    private function readPackage(string $path, string $category, string $id, string $coreVersion): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $id)) {
            throw new RuntimeException('插件目录标识无效');
        }
        if (is_link(dirname($path)) || is_link(dirname(dirname($path)))) {
            throw new RuntimeException('插件目录不能使用符号链接');
        }
        $raw = $this->json($path);
        $allowed = ['manifest_version', 'id', 'category', 'name', 'version', 'publisher', 'license', 'requires',
            'configuration_schema', 'capabilities', 'provider', 'network', 'permissions', 'frontend', 'migrations', 'data_policy'];
        if (array_diff(array_keys($raw), $allowed) !== [] || ($raw['manifest_version'] ?? '') !== '1.0'
            || ($raw['id'] ?? '') !== $id || ($raw['category'] ?? '') !== $category) {
            throw new RuntimeException('插件清单结构或身份无效');
        }
        foreach (['name', 'publisher', 'license'] as $key) {
            if (!is_string($raw[$key] ?? null) || trim($raw[$key]) === '' || mb_strlen($raw[$key]) > 128) {
                throw new RuntimeException('插件基础信息不完整');
            }
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $raw['version'] ?? '')) {
            throw new RuntimeException('插件版本格式无效');
        }
        $requires = $raw['requires'] ?? [];
        foreach (['core_min', 'core_max_exclusive'] as $key) {
            if (!is_string($requires[$key] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/D', $requires[$key])) {
                throw new RuntimeException('插件兼容范围无效');
            }
        }
        if (version_compare($requires['core_min'], $requires['core_max_exclusive'], '>=')
            || version_compare($coreVersion, $requires['core_min'], '<')
            || version_compare($coreVersion, $requires['core_max_exclusive'], '>=')) {
            throw new RuntimeException('插件不兼容当前系统版本');
        }
        if (($requires['plugin_sdk'] ?? '') !== '1.0' || !is_string($requires['php_min'] ?? null)
            || version_compare(PHP_VERSION, $requires['php_min'], '<')) {
            throw new RuntimeException('插件 SDK 或 PHP 版本不兼容');
        }
        // 其他类别保留目录协议，未交付的契约不能被假装加载为可用能力。
        if (!in_array($category, ['provider', 'mail', 'payment', 'identity', 'sms', 'storage', 'oauth', 'captcha'], true)
            || ($requires[$category . '_sdk'] ?? '') !== '1.0') {
            throw new RuntimeException('当前系统尚未提供该类别或版本的运行契约');
        }
        foreach (['permissions', 'frontend', 'migrations'] as $key) {
            if (($raw[$key] ?? null) !== []) {
                throw new RuntimeException('当前 SDK 不开放自定义路由、前端加载或插件迁移');
            }
        }
        if (($raw['data_policy'] ?? '') !== 'retain' || ($raw['configuration_schema'] ?? '') !== 'configuration.schema.json') {
            throw new RuntimeException('配置路径或数据保留策略无效');
        }
        $folder = dirname($path);
        $schema = $this->json($folder . '/configuration.schema.json');
        $fields = $this->configurationFields($schema);
        $capabilities = $raw['capabilities'] ?? [];
        $catalogs = [];
        if ($category === 'mail') {
            $network = $raw['network'] ?? [];
            if ($capabilities !== ['mail.send' => true] || !is_array($network)
                || array_keys($network) !== ['transport']
                || !in_array($network['transport'], ['smtp', 'https'], true)
                || isset($raw['provider'])) {
                throw new RuntimeException('邮件 v1 需声明单封发送能力及 SMTP 或 HTTPS 传输');
            }
            $entryName = 'MailAdapter';
            $interface = MailAdapterInterface::class;
        } elseif (in_array($category, ['payment', 'identity'], true)) {
            $expectedCapabilities = $category === 'payment'
                ? ['payment.create' => true, 'payment.query' => true, 'payment.close' => true, 'payment.notify' => true]
                : ['identity.initialize' => true, 'identity.query' => true];
            if ($capabilities !== $expectedCapabilities || ($raw['network'] ?? []) !== ['transport' => 'https']
                || isset($raw['provider'])) {
                throw new RuntimeException('支付或身份验证 v1 必须声明固定能力及 HTTPS 传输');
            }
            $network = $raw['network'];
            $entryName = $category === 'payment' ? 'PaymentAdapter' : 'IdentityAdapter';
            $interface = $category === 'payment' ? PaymentAdapterInterface::class : IdentityAdapterInterface::class;
        } elseif (in_array($category, ['sms', 'storage', 'oauth', 'captcha'], true)) {
            $expected = match ($category) {
                'sms' => ['sms.send' => true],
                'storage' => ['storage.put' => true, 'storage.delete' => true, 'storage.signed_url' => true],
                'oauth' => ['oauth.authorize' => true, 'oauth.authenticate' => true],
                'captcha' => ['captcha.initialize' => true, 'captcha.fingerprint' => true, 'captcha.verify' => true],
            };
            $network = $raw['network'] ?? [];
            if ($capabilities !== $expected || !is_array($network) || array_keys($network) !== ['transport']
                || !in_array($network['transport'], in_array($category, ['storage', 'captcha'], true) ? ['https', 'local'] : ['https'], true)
                || isset($raw['provider'])) {
                throw new RuntimeException('通道 v1 需要固定能力及受限传输声明');
            }
            [$entryName, $interface] = match ($category) {
                'sms' => ['SmsAdapter', \app\shared\plugin\contract\SmsAdapterInterface::class],
                'storage' => ['StorageAdapter', \app\shared\plugin\contract\StorageAdapterInterface::class],
                'oauth' => ['OAuthAdapter', \app\shared\plugin\contract\OAuthAdapterInterface::class],
                'captcha' => ['CaptchaAdapter', \app\shared\plugin\contract\CaptchaAdapterInterface::class],
            };
        } else {
            $provider = $this->provider($raw['provider'] ?? []);
            $catalogs = $provider['catalogs'];
            if (!is_array($capabilities) || array_diff(array_keys($capabilities), [
                'provider.connection_test', 'provider.catalog', 'provider.binding_validation', 'cloud_server.provision',
            ]) !== []) {
                throw new RuntimeException('存在未开放的插件能力');
            }
            foreach (['provider.connection_test', 'provider.catalog', 'provider.binding_validation'] as $key) {
                if (($capabilities[$key] ?? null) !== true) {
                    throw new RuntimeException('供应插件缺少必要能力');
                }
            }
            if (!is_bool($capabilities['cloud_server.provision'] ?? null)) throw new RuntimeException('供应生产能力须明确声明');
            $network = $this->network($raw['network'] ?? [], $fields);
            $entryName = 'ProviderAdapter';
            $interface = ProviderAdapterInterface::class;
        }
        $entry = 'plugins\\' . $category . '\\' . $id . '\\' . $entryName;
        $entryPath = $folder . '/' . $entryName . '.php';
        if (!is_file($entryPath)) {
            throw new RuntimeException('固定插件入口缺失');
        }
        $namespace = [];
        $tokens = token_get_all(file_get_contents($entryPath));
        foreach ($tokens as $position => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                for ($next = $position + 1; $next < count($tokens); $next++) {
                    if (is_array($tokens[$next]) && $tokens[$next][0] === T_NAME_QUALIFIED) {
                        $namespace[] = $tokens[$next][1];
                        break;
                    }
                    if ($tokens[$next] === ';' || $tokens[$next] === '{') {
                        break;
                    }
                }
            }
        }
        if ($namespace !== ['plugins\\' . $category . '\\' . $id]) {
            throw new RuntimeException('固定入口命名空间与分类身份不一致');
        }
        $hash = $this->packageHash($folder);
        $this->dependencies($folder, $id, $category);
        try {
            PluginDependencyLoader::validate($folder, 'plugins\\' . $category . '\\' . $id . '\\');
        } catch (\RuntimeException $error) {
            throw new RuntimeException($error->getMessage(), previous: $error);
        }
        if (!is_subclass_of($entry, $interface)) {
            throw new RuntimeException('固定入口不符合该类别 v1 契约');
        }
        if ($category === 'provider' && ($capabilities['cloud_server.provision'] ?? false)
            && !is_subclass_of($entry, \app\shared\plugin\contract\ProviderProvisioningInterface::class)) {
            throw new RuntimeException('声明生产能力的供应插件必须实现创建与查询契约');
        }
        // 可选能力由实际接口识别，清单不重复声明或假装提供审核功能。
        $templateReview = [
            'submit' => is_subclass_of($entry, \app\shared\plugin\contract\SubmitTemplateAdapterInterface::class),
            'query' => is_subclass_of($entry, \app\shared\plugin\contract\QueryTemplateReviewAdapterInterface::class),
        ];
        if (!in_array($category, ['sms', 'mail'], true) && in_array(true, $templateReview, true)) {
            throw new RuntimeException('模板审核能力仅开放给短信和邮件插件');
        }
        return $raw + ['configuration_fields' => $fields, 'configuration_scope' => $schema['scope'],
            'template_review' => $templateReview,
            'catalogs' => $catalogs, 'package_hash' => $hash,
            'manifest_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)), 'entry' => $entry,
            'path' => $folder, 'validated_network' => $network];
    }

    /**
     * 读取有限 JSON；禁止符号链接与远程 Schema 引用。
     * @param string $path 已限定的本地文件
     * @return array<string, mixed> JSON 对象
     * @throws RuntimeException 格式或大小无效
     */
    private function json(string $path): array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > 65536) {
            throw new RuntimeException('清单或配置文件缺失、过大或路径无效');
        }
        try {
            $value = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('插件 JSON 格式无效', previous: $error);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('插件清单须为对象');
        }
        return $value;
    }

    /**
     * 校验本系统受限配置 DSL，未知字段拒绝。
     * @param array<string, mixed> $schema 配置声明
     * @return list<array<string, mixed>> 宿主表单字段
     * @throws RuntimeException 字段声明无效
     */
    private function configurationFields(array $schema): array
    {
        if (array_diff(array_keys($schema), ['schema_version', 'scope', 'fields']) !== []
            || ($schema['schema_version'] ?? '') !== '1.0' || ($schema['scope'] ?? '') !== 'connection'
            || !is_array($schema['fields'] ?? null) || !array_is_list($schema['fields']) || count($schema['fields']) > 16) {
            throw new RuntimeException('配置声明结构无效');
        }
        $keys = [];
        foreach ($schema['fields'] as $field) {
            if (!is_array($field) || array_diff(array_keys($field), [
                'key', 'label', 'type', 'required', 'max_length', 'placeholder', 'default', 'minimum', 'maximum', 'options', 'description', 'unit',
            ]) !== [] || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field['key'] ?? '')
                || !is_string($field['label'] ?? null) || mb_strlen($field['label']) > 64
                || !is_bool($field['required'] ?? null)
                || !in_array($field['type'] ?? '', ['text', 'password', 'url', 'integer', 'boolean', 'select'], true)) {
                throw new RuntimeException('配置字段结构无效');
            }
            if (isset($keys[$field['key']])) {
                throw new RuntimeException('配置字段标识重复');
            }
            $keys[$field['key']] = true;
            if (in_array($field['type'], ['text', 'password', 'url'], true)
                && (!is_int($field['max_length'] ?? null) || $field['max_length'] < 1 || $field['max_length'] > 2048)) {
                throw new RuntimeException('文本长度限制无效');
            }
            if ($field['type'] === 'password' && isset($field['default'])) {
                throw new RuntimeException('私密字段不能在包内设置默认值');
            }
            if ($field['type'] === 'integer' && (!is_int($field['minimum'] ?? null) || !is_int($field['maximum'] ?? null)
                || $field['minimum'] > $field['maximum'])) {
                throw new RuntimeException('整数范围无效');
            }
            if ($field['type'] === 'select') {
                if (!is_array($field['options'] ?? null) || count($field['options']) < 1 || count($field['options']) > 50) {
                    throw new RuntimeException('选择项范围无效');
                }
                foreach ($field['options'] as $option) {
                    if (!is_array($option) || array_diff(array_keys($option), ['label', 'value']) !== [] || !isset($option['label'], $option['value'])
                        || !is_string($option['label']) || !is_string($option['value'])) {
                        throw new RuntimeException('选择项只能声明固定文本值');
                    }
                }
            }
            foreach (['placeholder', 'description', 'unit'] as $key) {
                if (isset($field[$key]) && (!is_string($field[$key]) || mb_strlen($field[$key]) > 256)) {
                    throw new RuntimeException('配置说明无效');
                }
            }
        }
        foreach ($schema['fields'] as $field) {
            if (!array_key_exists('default', $field)) {
                continue;
            }
            $value = $field['default'];
            $valid = match ($field['type']) {
                'integer' => is_int($value) && $value >= $field['minimum'] && $value <= $field['maximum'],
                'boolean' => is_bool($value),
                'select' => is_string($value) && in_array($value, array_column($field['options'], 'value'), true),
                'text', 'url' => is_string($value) && mb_strlen($value) <= $field['max_length'],
                default => false,
            };
            if (!$valid) {
                throw new RuntimeException('配置默认值与字段限制不一致');
            }
        }
        return $schema['fields'];
    }

    /**
     * 校验标准资源与外部目录声明。
     * @param array<string, mixed> $provider 供应声明
     * @return array<string, mixed> 已校验声明
     * @throws RuntimeException 目录或类型无效
     */
    private function provider(array $provider): array
    {
        if (array_diff(array_keys($provider), ['type_version', 'product_types', 'catalogs']) !== []
            || !is_string($provider['type_version'] ?? null) || strlen($provider['type_version']) > 32
            || !is_array($provider['product_types'] ?? null) || count($provider['product_types']) < 1
            || !is_array($provider['catalogs'] ?? null) || count($provider['catalogs']) > 16) {
            throw new RuntimeException('供应类型声明无效');
        }
        foreach ($provider['product_types'] as $type) {
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type)) {
                throw new RuntimeException('产品标识无效');
            }
        }
        $kinds = [];
        foreach ($provider['catalogs'] as $catalog) {
            if (!is_array($catalog) || array_diff(array_keys($catalog), ['resource_kind', 'kind', 'label']) !== []) {
                throw new RuntimeException('资源目录结构无效');
            }
            foreach (['resource_kind', 'kind'] as $key) {
                if (!is_string($catalog[$key] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,23}$/D', $catalog[$key])) {
                    throw new RuntimeException('资源目录标识无效');
                }
            }
            if (!is_string($catalog['label'] ?? null) || mb_strlen($catalog['label']) > 64 || isset($kinds[$catalog['resource_kind']])) {
                throw new RuntimeException('资源目录名称或对应关系无效');
            }
            $kinds[$catalog['resource_kind']] = true;
        }
        return $provider;
    }

    /**
     * 固定访问地址字段和 HTTP 端点白名单。
     * @param array<string, mixed> $network 网络声明
     * @param list<array<string, mixed>> $fields 配置字段
     * @return array<string, mixed> 网络声明
     * @throws RuntimeException 网络范围无效
     */
    private function network(array $network, array $fields): array
    {
        $urlFields = array_column(array_filter($fields, static fn (array $field): bool => $field['type'] === 'url'), 'key');
        if (array_diff(array_keys($network), ['base_url_field', 'endpoints']) !== []
            || !in_array($network['base_url_field'] ?? '', $urlFields, true)
            || !is_array($network['endpoints'] ?? null) || count($network['endpoints']) > 64) {
            throw new RuntimeException('网络地址范围无效');
        }
        foreach ($network['endpoints'] as $endpoint) {
            if (!is_array($endpoint) || array_diff(array_keys($endpoint), ['method', 'path']) !== [] || !in_array($endpoint['method'] ?? '', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
                || !is_string($endpoint['path'] ?? null) || !preg_match('~^(?=.{1,128}$)(?:/[a-zA-Z0-9_-]+|/\{[a-z][a-z0-9_]*\})+$~D', $endpoint['path'])
                || str_contains($endpoint['path'], '//')) {
                throw new RuntimeException('端点必须是固定相对路径');
            }
        }
        $network['endpoints'] = array_map(static fn (array $endpoint): array => ['method' => $endpoint['method'], 'path' => $endpoint['path']], $network['endpoints']);
        return $network;
    }

    /**
     * 静态约束常见违规依赖；同进程 PHP 仍须人工代码审核，不是沙箱。
     * @param string $folder 受控包目录
     * @param string $id 当前包身份
     * @param string $category 已验证类别
     * @return void
     * @throws RuntimeException 依赖宿主内部实现或直接 I/O
     */
    private function dependencies(string $folder, string $id, string $category): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            // 第三方依赖另由依赖加载器审核；入口及本插件所有辅助 PHP 都检查。
            if (str_starts_with(substr($file->getPathname(), strlen($folder) + 1), 'vendor/')) {
                continue;
            }
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                $name = ltrim(strtolower($token[1]), '\\');
                if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $blocked = (str_starts_with($name, 'app\\') && !str_starts_with($name, 'app\\shared\\plugin\\'));
                    // 插件可使用本地 Composer SDK，不采用第三方包名白名单。
                    // 核心模型、容器和原始 I/O 仍不是插件的公开 API。
                    $blockedPrefixes = ['support\\', 'webman\\', 'illuminate\\', 'di\\'];
                    foreach ($blockedPrefixes as $prefix) {
                        $blocked = $blocked || str_starts_with($name, $prefix);
                    }
                    $ownNamespace = 'plugins\\' . $category . '\\' . $id;
                    if ($blocked || (str_starts_with($name, 'plugins\\') && $name !== $ownNamespace && !str_starts_with($name, $ownNamespace . '\\'))) {
                        throw new RuntimeException($category === 'mail'
                            ? '邮件插件 v1 不能依赖核心内部类或其他插件'
                            : ($category === 'provider'
                                ? '供应 SDK v1 不能依赖核心内部类或其他插件'
                                : '通道插件 v1 不能依赖核心内部类或其他插件'));
                    }
                }
                if ($token[0] === T_STRING && in_array($name, ['curl_init', 'curl_exec', 'file_get_contents', 'file_put_contents', 'fopen', 'fsockopen', 'stream_socket_client', 'exec', 'system', 'shell_exec', 'passthru', 'proc_open', 'popen', 'getenv', 'pdo'], true)) {
                    throw new RuntimeException($category === 'mail'
                        ? '邮件插件 v1 通过插件本地客户端发送，不开放原始 I/O、环境变量或进程调用'
                        : ($category === 'provider'
                            ? '供应 SDK v1 不开放入口原始 I/O、环境变量或进程调用'
                            : '通道插件 v1 不开放原始 I/O、环境变量或进程调用'));
                }
                if (in_array($token[0], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                    throw new RuntimeException('插件入口不能执行动态代码或手工加载文件');
                }
            }
        }
    }

    /**
     * 绑定所有运行文件，防止审核后悄悄替换源码；拒绝链接和敏感包内容。
     * @param string $folder 插件目录
     * @return string SHA-256 包摘要
     * @throws RuntimeException 包路径或大小越界
     */
    private function packageHash(string $folder): string
    {
        $files = [];
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($folder) + 1);
            // Finder 元数据不是运行文件；仍拒绝任何符号链接。
            if (!$file->isLink() && $file->getFilename() === '.DS_Store') {
                continue;
            }
            $dependency = str_starts_with($relative, 'vendor/');
            if ($file->isLink() || (!$dependency && (str_contains($relative, '/config/') || str_starts_with($relative, 'config/')))
                || (!$dependency && str_contains($relative, '.env'))
                || preg_match('~(^|/)\.env(?:[./]|$)~', $relative) || str_contains($relative, 'node_modules/')) {
                throw new RuntimeException('插件包包含不允许的路径或秘密文件');
            }
            if (!$file->isFile()) {
                continue;
            }
            $total += $file->getSize();
            if ($total > 104857600 || count($files) >= 10000) {
                throw new RuntimeException('插件包超过文件数或容量限制');
            }
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
