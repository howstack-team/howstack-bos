<?php

declare(strict_types=1);

namespace app\modules\compute\application;

use app\modules\product\contract\PublishedVersionCatalogInterface;
use app\modules\service_instance\contract\InstanceImageOptionsInterface;

/** 仅允许原版本、原可用区已映射且满足实例实际硬件的镜像。 */
final readonly class GetInstanceImagesService implements InstanceImageOptionsInterface
{
    /**
     * @param PublishedVersionCatalogInterface $versions 原不可变销售版本
     */
    public function __construct(private PublishedVersionCatalogInterface $versions)
    {
    }

    /** @inheritDoc */
    public function images(array $context): array
    {
        $snapshot = $this->versions->snapshot((int) $context['offer_id'], (int) $context['version_id']);
        $config = $context['provisioning'];
        $zone = $config['zone']['id'];
        $binding = $context['delivery_binding'];
        $binding = $binding['zone_bindings'][$zone] ?? $binding;
        $mapping = $binding['mapping']['_zones'][$zone] ?? ($binding['mapping'] ?? []);
        $result = [];
        foreach ($snapshot['resources']['images'] ?? [] as $image) {
            $external = $mapping['images'][$image['id']] ?? null;
            if (!in_array('password', $image['login_methods'] ?? ['password'], true) || !$external || !in_array($zone, $image['zone_ids'] ?? [$image['zone_id'] ?? null], true)
                || ($image['min_cpu'] ?? 0) > ($config['flavor']['cpu'] ?? 0)
                || ($image['min_memory_mib'] ?? 0) > ($config['flavor']['memory_mib'] ?? 0)
                || ($image['min_disk_gib'] ?? 0) > ($config['system_disk']['size_gib'] ?? 0)
                || (isset($image['architecture'], $config['flavor']['architecture']) && $image['architecture'] !== $config['flavor']['architecture'])) {
                continue;
            }
            $result[] = ['id' => (int) $image['id'], 'name' => $image['name'], 'external_id' => (string) $external,
                'default_username' => $image['default_username'] ?? 'root', 'default_port' => (int) ($image['default_port'] ?? 22)];
        }
        return $result;
    }
}
