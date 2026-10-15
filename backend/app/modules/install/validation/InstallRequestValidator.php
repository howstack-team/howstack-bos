<?php

declare(strict_types=1);

namespace app\modules\install\validation;

use app\modules\install\exception\InstallationException;
use support\validation\Validator;

/**
 * 验证在线安装连接测试与分阶段执行请求。
 */
final class InstallRequestValidator extends Validator
{
    private const CONNECTION_FIELDS = [
        'configuration.mysql',
        'configuration.mysql.host',
        'configuration.mysql.port',
        'configuration.mysql.database',
        'configuration.mysql.username',
        'configuration.mysql.password',
        'configuration.redis',
        'configuration.redis.host',
        'configuration.redis.port',
        'configuration.redis.password',
        'configuration.redis.database',
    ];

    /**
     * @var array<string, string|list<string>> 安装请求字段验证规则
     */
    protected array $rules = [
        'phase' => 'required|string|in:database,system,configuration,finalize',
        'configuration.agreement_agreed' => 'required|boolean:strict|accepted',
        'configuration.mysql' => 'required|array:host,port,database,username,password',
        'configuration.mysql.host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9._:-]+$/'],
        'configuration.mysql.port' => 'required|integer|between:1,65535',
        'configuration.mysql.database' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/'],
        'configuration.mysql.username' => 'required|string|max:128',
        'configuration.mysql.password' => 'present|string|max:512',
        'configuration.redis' => 'required|array:host,port,password,database',
        'configuration.redis.host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9._:-]+$/'],
        'configuration.redis.port' => 'required|integer|between:1,65535',
        'configuration.redis.password' => 'present|string|max:512',
        'configuration.redis.database' => 'required|integer|between:0,255',
        'configuration.system' => 'required|array:site_title,site_url,admin_path',
        'configuration.system.site_title' => 'required|string|min:2|max:120',
        'configuration.system.site_url' => 'required|string|max:255|url:http,https',
        'configuration.system.admin_path' => [
            'required',
            'string',
            'regex:/^[a-z][a-z0-9-]{2,31}$/',
            'not_in:api,api-docs,console,install,install-assets,assets,plugin-assets',
        ],
        'configuration.admin' => 'required|array:username,nickname,password',
        'configuration.admin.username' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_]{5,31}$/'],
        'configuration.admin.nickname' => 'required|string|min:2|max:32',
        'configuration.admin.password' => [
            'required',
            'string',
            'min:8',
            'max:128',
            'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).+$/',
        ],
    ];

    /**
     * @var array<string, string> 安装请求字段验证提示
     */
    protected array $messages = [
        'phase.required' => '请选择安装阶段',
        'phase.string' => '安装阶段格式不正确',
        'phase.in' => '未知的安装阶段',
        'configuration.agreement_agreed.required' => '请先阅读并同意皓量云擎云业务运营系统软件使用协议',
        'configuration.agreement_agreed.boolean' => '软件使用协议确认状态格式不正确',
        'configuration.agreement_agreed.accepted' => '请先阅读并同意皓量云擎云业务运营系统软件使用协议',
        'configuration.mysql.required' => '请填写 MySQL 配置',
        'configuration.mysql.array' => 'MySQL 配置格式不正确',
        'configuration.mysql.host.required' => '请输入 MySQL 主机地址',
        'configuration.mysql.host.string' => 'MySQL 主机地址格式不正确',
        'configuration.mysql.host.regex' => '请输入有效的 MySQL 主机地址',
        'configuration.mysql.host.max' => 'MySQL 主机地址不能超过 253 个字符',
        'configuration.mysql.port.required' => '请输入 MySQL 端口',
        'configuration.mysql.port.integer' => 'MySQL 端口必须是整数',
        'configuration.mysql.port.between' => 'MySQL 端口必须在 1 至 65535 之间',
        'configuration.mysql.database.required' => '请输入数据库名称',
        'configuration.mysql.database.string' => '数据库名称格式不正确',
        'configuration.mysql.database.regex' => '数据库名称只能包含字母、数字、下划线和连字符',
        'configuration.mysql.database.max' => '数据库名称不能超过 64 个字符',
        'configuration.mysql.username.required' => '请输入 MySQL 用户名',
        'configuration.mysql.username.string' => 'MySQL 用户名格式不正确',
        'configuration.mysql.username.max' => 'MySQL 用户名不能超过 128 个字符',
        'configuration.mysql.password.present' => '请提交 MySQL 密码字段',
        'configuration.mysql.password.string' => 'MySQL 密码格式不正确',
        'configuration.mysql.password.max' => 'MySQL 密码长度不能超过 512 个字符',
        'configuration.redis.required' => '请填写 Redis 配置',
        'configuration.redis.array' => 'Redis 配置格式不正确',
        'configuration.redis.host.required' => '请输入 Redis 主机地址',
        'configuration.redis.host.string' => 'Redis 主机地址格式不正确',
        'configuration.redis.host.regex' => '请输入有效的 Redis 主机地址',
        'configuration.redis.host.max' => 'Redis 主机地址不能超过 253 个字符',
        'configuration.redis.port.required' => '请输入 Redis 端口',
        'configuration.redis.port.integer' => 'Redis 端口必须是整数',
        'configuration.redis.port.between' => 'Redis 端口必须在 1 至 65535 之间',
        'configuration.redis.password.present' => '请提交 Redis 密码字段',
        'configuration.redis.password.string' => 'Redis 密码格式不正确',
        'configuration.redis.password.max' => 'Redis 密码长度不能超过 512 个字符',
        'configuration.redis.database.required' => '请输入 Redis 数据库编号',
        'configuration.redis.database.integer' => 'Redis 数据库编号必须是整数',
        'configuration.redis.database.between' => 'Redis 数据库编号必须在 0 至 255 之间',
        'configuration.system.required' => '请填写系统配置',
        'configuration.system.array' => '系统配置格式不正确',
        'configuration.system.site_title.required' => '请输入网站标题',
        'configuration.system.site_title.string' => '网站标题格式不正确',
        'configuration.system.site_title.min' => '网站标题长度必须在 2 至 120 个字符之间',
        'configuration.system.site_title.max' => '网站标题长度必须在 2 至 120 个字符之间',
        'configuration.system.site_url.required' => '请输入网站地址',
        'configuration.system.site_url.string' => '网站地址格式不正确',
        'configuration.system.site_url.url' => '请输入有效的网站地址，例如 https://example.com',
        'configuration.system.site_url.max' => '网站地址不能超过 255 个字符',
        'configuration.system.admin_path.required' => '请输入后台访问路径',
        'configuration.system.admin_path.string' => '后台访问路径格式不正确',
        'configuration.system.admin_path.regex' => '后台路径须以小写字母开头，只能包含小写字母、数字和连字符，长度为 3 至 32 位',
        'configuration.system.admin_path.not_in' => '该后台路径为系统保留路径，请更换',
        'configuration.admin.required' => '请填写管理员信息',
        'configuration.admin.array' => '管理员信息格式不正确',
        'configuration.admin.username.required' => '请输入管理员账号',
        'configuration.admin.username.string' => '管理员账号格式不正确',
        'configuration.admin.username.regex' => '管理员账号须以英文字母开头，只能包含英文字母、数字和下划线，长度为 6 至 32 位',
        'configuration.admin.nickname.required' => '请输入管理员昵称',
        'configuration.admin.nickname.string' => '管理员昵称格式不正确',
        'configuration.admin.nickname.min' => '管理员昵称长度必须在 2 至 32 个字符之间',
        'configuration.admin.nickname.max' => '管理员昵称长度必须在 2 至 32 个字符之间',
        'configuration.admin.password.required' => '请输入管理员密码',
        'configuration.admin.password.string' => '管理员密码格式不正确',
        'configuration.admin.password.min' => '管理员密码长度必须在 8 至 128 个字符之间',
        'configuration.admin.password.max' => '管理员密码长度必须在 8 至 128 个字符之间',
        'configuration.admin.password.regex' => '管理员密码必须同时包含大写字母、小写字母、数字和特殊字符',
    ];

    /**
     * @var array<string, list<string>> 安装请求验证场景
     */
    protected array $scenes = [
        'connections' => self::CONNECTION_FIELDS,
        'execution' => [
            'phase',
            'configuration.agreement_agreed',
            ...self::CONNECTION_FIELDS,
            'configuration.system',
            'configuration.system.site_title',
            'configuration.system.site_url',
            'configuration.system.admin_path',
            'configuration.admin',
            'configuration.admin.username',
            'configuration.admin.nickname',
            'configuration.admin.password',
        ],
    ];

    /**
     * 验证数据库与缓存连接测试配置。
     * @return array{mysql: array{host: string, port: int, database: string, username: string, password: string}, redis: array{host: string, port: int, password: string, database: int}} 已验证连接配置
     * @throws InstallationException 连接配置字段不符合要求时抛出
     */
    public function validateConnections(): array
    {
        $validated = $this->withScene('connections')->validatedOrFail();

        /** @var array{mysql: array{host: string, port: int, database: string, username: string, password: string}, redis: array{host: string, port: int, password: string, database: int}} $configuration */
        $configuration = $validated['configuration'];
        $this->castConnectionNumbers($configuration);

        return $configuration;
    }

    /**
     * 验证安装阶段和完整安装配置。
     * @return array{phase: string, configuration: array<string, mixed>} 已验证安装执行数据
     * @throws InstallationException 安装阶段或配置字段不符合要求时抛出
     */
    public function validateExecution(): array
    {
        /** @var array{phase: string, configuration: array<string, mixed>} $validated */
        $validated = $this->withScene('execution')->validatedOrFail();
        $this->castConnectionNumbers($validated['configuration']);

        return $validated;
    }

    /**
     * 规范化安装表单中的字符串字段。
     * @return array<string, mixed> 规范化后的验证数据
     */
    public function data(): array
    {
        $data = parent::data();
        $configuration = $data['configuration'] ?? null;
        if (!is_array($configuration)) {
            return $data;
        }

        $this->trimField($configuration, 'mysql', 'host');
        $this->trimField($configuration, 'mysql', 'database');
        $this->trimField($configuration, 'mysql', 'username');
        $this->trimField($configuration, 'redis', 'host');
        $this->trimField($configuration, 'system', 'site_title');
        $this->trimField($configuration, 'system', 'site_url', true);
        $this->normalizeAdminPath($configuration);
        $this->trimField($configuration, 'admin', 'username');
        $this->trimField($configuration, 'admin', 'nickname');
        $data['configuration'] = $configuration;

        if (isset($data['phase']) && is_string($data['phase'])) {
            $data['phase'] = trim($data['phase']);
        }

        return $data;
    }

    /**
     * 验证当前场景并转换为安装模块统一字段错误。
     * @return array<string, mixed> 已验证白名单数据
     * @throws InstallationException 字段不符合要求时抛出
     */
    private function validatedOrFail(): array
    {
        $validator = $this->toIlluminate();
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            throw InstallationException::validationFailed($errors);
        }

        return $validator->validated();
    }

    /**
     * 将已通过整数规则的端口和 Redis 数据库编号转换为整数。
     * @param array<string, mixed> $configuration 已验证安装配置
     * @return void
     */
    private function castConnectionNumbers(array &$configuration): void
    {
        if (isset($configuration['mysql']) && is_array($configuration['mysql'])) {
            $configuration['mysql']['port'] = (int) $configuration['mysql']['port'];
        }

        if (isset($configuration['redis']) && is_array($configuration['redis'])) {
            $configuration['redis']['port'] = (int) $configuration['redis']['port'];
            $configuration['redis']['database'] = (int) $configuration['redis']['database'];
        }
    }

    /**
     * 去除指定安装字段首尾空白。
     * @param array<string, mixed> $configuration 安装配置
     * @param string               $section       配置分组
     * @param string               $field         字段名称
     * @param bool                 $trimSlash     是否同时移除末尾斜杠
     * @return void
     */
    private function trimField(
        array &$configuration,
        string $section,
        string $field,
        bool $trimSlash = false,
    ): void {
        if (!isset($configuration[$section])
            || !is_array($configuration[$section])
            || !isset($configuration[$section][$field])
            || !is_string($configuration[$section][$field])) {
            return;
        }

        $value = trim($configuration[$section][$field]);
        $configuration[$section][$field] = $trimSlash ? rtrim($value, '/') : $value;
    }

    /**
     * 规范化后台访问路径。
     * @param array<string, mixed> $configuration 安装配置
     * @return void
     */
    private function normalizeAdminPath(array &$configuration): void
    {
        if (!isset($configuration['system'])
            || !is_array($configuration['system'])
            || !isset($configuration['system']['admin_path'])
            || !is_string($configuration['system']['admin_path'])) {
            return;
        }

        $configuration['system']['admin_path'] = strtolower(trim(
            $configuration['system']['admin_path'],
            " /\t\n\r\0\x0B",
        ));
    }
}
