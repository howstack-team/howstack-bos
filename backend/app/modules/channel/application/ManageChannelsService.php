<?php

declare(strict_types=1);

namespace app\modules\channel\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\channel\domain\ChannelCategories;
use app\modules\channel\validation\ChannelValidator;
use app\shared\http\ApiErrorCode;

/** 七类通道配置用例；不调用支付、发送、认证、上传或登录执行。 */
final readonly class ManageChannelsService
{
    /**
     * @param AdminAccessControlService $access 操作权限
     * @param ChannelRepositoryInterface $channels 通道持久化
     * @param ChannelPlatformRegistryInterface $platforms 可用平台
     * @param ChannelValidator $validator 路径和配置白名单验证
     */
    public function __construct(
        private AdminAccessControlService $access,
        private ChannelRepositoryInterface $channels,
        private ChannelPlatformRegistryInterface $platforms,
        private ChannelValidator $validator,
    ) {
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @param array<string, mixed> $query 查询
     * @return array<string, mixed> 默认展示所有平台，不因浏览列表产生配置记录
     */
    public function catalog(AdminAccount $actor, string $category, array $query): array
    {
        $category = $this->authorize($actor, $category, 'view');
        return $this->channels->catalog($category, $this->platforms->catalog($category), $this->validator->filters($query))
            + ['default_label' => ChannelCategories::DEFAULT_LABELS[$category] ?? null];
    }

    /**
     * 固定插件入口的首次配置，与编辑共用 update 权限；不接受自定义名称或平台切换。
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @param string $pluginId 插件标识
     * @param array<string, mixed> $input 配置
     * @return array<string, mixed> 脱敏结果
     */
    public function configure(AdminAccount $actor, string $category, string $pluginId, array $input): array
    {
        $category = $this->authorize($actor, $category, 'update');
        $platform = $this->platforms->definition($category, $pluginId)
            ?? throw new BusinessException('插件当前不可用，请检查应用状态', ApiErrorCode::CONFLICT, 409);
        $data = $this->validator->values(array_replace($input, ['plugin_id' => $pluginId, 'name' => $platform['name']]));
        $data['platform_configuration'] = true;
        return $this->persist($category, null, $data);
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @param mixed $id 已有配置标识
     * @param array<string, mixed> $input 配置
     * @return array<string, mixed> 脱敏结果
     */
    public function updateConfiguration(AdminAccount $actor, string $category, mixed $id, array $input): array
    {
        $category = $this->authorize($actor, $category, 'update');
        $id = $this->validator->id($id);
        $previous = $this->channels->find($category, $id);
        $data = $this->validator->values(array_replace($input, ['plugin_id' => $previous['plugin_id'], 'name' => $previous['name']]));
        return $this->persist($category, $id, $data);
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @param array<string, mixed> $query 查询
     * @return array<string, mixed> 分页资料
     */
    public function list(AdminAccount $actor, string $category, array $query): array
    {
        $category = $this->authorize($actor, $category, 'view');
        return $this->channels->list($category, $this->validator->filters($query));
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @return list<array<string, mixed>> 本地插件公开声明与可用状态
     */
    public function platforms(AdminAccount $actor, string $category): array
    {
        $category = $this->authorize($actor, $category, 'view');
        return array_map(static fn (array $platform): array => array_diff_key($platform, ['package_hash' => true]),
            $this->platforms->catalog($category));
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @param mixed $id 标识
     * @return array<string, mixed> 脱敏详情
     */
    public function detail(AdminAccount $actor, string $category, mixed $id): array
    {
        $category = $this->authorize($actor, $category, 'view');
        return $this->channels->find($category, $this->validator->id($id));
    }

    /**
     * 空私密输入保留原值，明确清除需另外传入键名；配置校验前后执行宿主规则。
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @param mixed $id 更新标识，创建为NULL
     * @param array<string, mixed> $input 请求资料
     * @return array<string, mixed> 保存后的脱敏详情
     */
    public function save(AdminAccount $actor, string $category, mixed $id, array $input): array
    {
        $category = $this->authorize($actor, $category, $id === null ? 'create' : 'update');
        $id = $id === null ? null : $this->validator->id($id);
        return $this->persist($category, $id, $this->validator->values($input));
    }

    /** @param string $category 类别 @param int|null $id 配置身份 @param array<string, mixed> $data 已验证配置 @return array<string, mixed> 脱敏详情 */
    private function persist(string $category, ?int $id, array $data): array
    {
        if ($data['is_default'] && !isset(ChannelCategories::DEFAULT_LABELS[$category])) {
            throw new BusinessException('此类通道由业务选择，不支持设置统一默认', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $previous = $id === null ? null : $this->channels->find($category, $id);
        if ($previous !== null && $previous['plugin_id'] !== $data['plugin_id']) {
            throw new BusinessException('服务平台不能更换，请配置对应插件', ApiErrorCode::CONFLICT, 409);
        }
        $platform = $this->platforms->definition($category, $data['plugin_id'])
            ?? throw new BusinessException('暂无可用的服务平台，请在应用中心安装并启用对应插件', ApiErrorCode::CONFLICT, 409);
        if ($data['is_default'] && $data['status'] !== 1) {
            throw new BusinessException('默认通道必须处于启用状态', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $secretKeys = array_keys((array) ($previous['configured_secrets'] ?? []));
        foreach ($platform['configuration_fields'] as $field) {
            if ($field['type'] === 'password') {
                $secretKeys[] = $field['key'];
            }
        }
        $clearSecrets = $data['clear_secrets'] ?? [];
        if (array_diff($clearSecrets, $secretKeys) !== []) {
            throw new BusinessException('只能明确清除私密配置字段', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $old = $id === null ? [] : $this->channels->configuration($category, $id);
        $configuration = $data['configuration'];
        foreach ($platform['configuration_fields'] as $field) {
            $key = $field['key'];
            if (in_array($key, $clearSecrets, true)) {
                $configuration[$key] = '';
            } elseif (in_array($key, $secretKeys, true) && ($configuration[$key] ?? '') === '') {
                $configuration[$key] = $old[$key] ?? '';
            }
        }
        $configuration = $this->validator->configuration($platform['configuration_fields'], $configuration);
        $configuration = $this->platforms->normalize($category, $data['plugin_id'], $configuration);
        $data['configuration'] = $this->validator->configuration($platform['configuration_fields'], $configuration);
        return $this->channels->save($category, $id, $data, $platform);
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @param mixed $id 标识
     * @param array<string, mixed> $input 请求
     * @return array<string, mixed> 启停后详情
     */
    public function status(AdminAccount $actor, string $category, mixed $id, array $input): array
    {
        $category = $this->authorize($actor, $category, 'update');
        $data = $this->validator->status($input);
        return $this->channels->status($category, $this->validator->id($id), $data['revision'], $data['status']);
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param string $category 类别
     * @param mixed $id 标识
     * @param array<string, mixed> $input 请求
     * @return void
     */
    public function archive(AdminAccount $actor, string $category, mixed $id, array $input): void
    {
        $category = $this->authorize($actor, $category, 'delete');
        $this->channels->archive($category, $this->validator->id($id), $this->validator->revision($input));
    }

    /**
     * @param AdminAccount $actor 操作人
     * @param string $category 外部类别
     * @param string $action 固定动作
     * @return string 授权后的类别
     */
    private function authorize(AdminAccount $actor, string $category, string $action): string
    {
        $category = $this->validator->category($category);
        $this->access->requirePermission($actor, 'admin.channels.' . $category . '.' . $action);
        return $category;
    }
}
