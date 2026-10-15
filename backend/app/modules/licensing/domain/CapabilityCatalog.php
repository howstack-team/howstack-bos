<?php

declare(strict_types=1);

namespace app\modules\licensing\domain;

use InvalidArgumentException;

/** 实际交付能力目录；首阶段没有商业扩展，生产目录保持为空。 */
final readonly class CapabilityCatalog
{
    /** @var array<string, CapabilityDefinition> */
    private array $definitions;

    /** @param list<CapabilityDefinition> $definitions 仅由受信代码注册 */
    public function __construct(array $definitions = [])
    {
        $items = [];
        foreach ($definitions as $definition) {
            if (isset($items[$definition->id])) {
                throw new InvalidArgumentException('能力标识重复');
            }
            $items[$definition->id] = $definition;
        }
        $this->definitions = $items;
    }

    public function find(string $id): ?CapabilityDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    /** @return array<string, CapabilityDefinition> 实际可识别目录 */
    public function all(): array
    {
        return $this->definitions;
    }
}
