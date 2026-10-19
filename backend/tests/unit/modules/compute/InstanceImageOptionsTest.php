<?php

declare(strict_types=1);

namespace tests\unit\modules\compute;

use app\modules\compute\application\GetInstanceImagesService;
use app\modules\product\contract\PublishedVersionCatalogInterface;
use PHPUnit\Framework\TestCase;

/** 原销售版本、可用区映射及所有硬件下限均参与重装镜像筛选。 */
final class InstanceImageOptionsTest extends TestCase
{
    public function testOnlyPinnedVersionMappedCompatiblePasswordImagesAreReturned(): void
    {
        $base = ['id' => 1, 'name' => 'Ubuntu', 'zone_ids' => [3], 'architecture' => 'x86_64', 'min_cpu' => 2, 'min_memory_mib' => 2048,
            'min_disk_gib' => 20, 'login_methods' => ['password'], 'default_username' => 'ubuntu', 'default_port' => 2222];
        $rows = [$base];
        foreach ([['zone_ids' => [4]], ['architecture' => 'arm64'], ['min_cpu' => 4], ['min_memory_mib' => 4096],
            ['min_disk_gib' => 40], ['login_methods' => ['ssh_key']], []] as $index => $changes) {
            $rows[] = array_replace($base, ['id' => $index + 2], $changes);
        }
        $versions = $this->createMock(PublishedVersionCatalogInterface::class);
        $versions->expects(self::once())->method('snapshot')->with(9, 10)->willReturn(['resources' => ['images' => $rows]]);
        $context = ['offer_id' => 9, 'version_id' => 10, 'provisioning' => ['zone' => ['id' => 3],
            'flavor' => ['cpu' => 2, 'memory_mib' => 2048, 'architecture' => 'x86_64'], 'system_disk' => ['size_gib' => 20]],
            'delivery_binding' => ['zone_bindings' => [3 => ['mapping' => ['images' => [1 => 'wrong-default'],
                '_zones' => [3 => ['images' => [1 => 'original-zone-image', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7']]]]]]]];
        self::assertSame([['id' => 1, 'name' => 'Ubuntu', 'external_id' => 'original-zone-image', 'default_username' => 'ubuntu', 'default_port' => 2222]],
            (new GetInstanceImagesService($versions))->images($context));
    }
}
