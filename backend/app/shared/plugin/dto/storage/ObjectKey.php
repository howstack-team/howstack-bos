<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\storage;

use InvalidArgumentException;

/** 宿主生成的逻辑对象键，不能是 URL、绝对路径或目录穿越路径。 */
final readonly class ObjectKey
{
    /**
     * @param string $value 逻辑对象键
     * @throws InvalidArgumentException 格式无效
     */
    public function __construct(public string $value)
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,511}$/D', $value) || str_ends_with($value, '/')
            || array_intersect(explode('/', $value), ['', '.', '..']) !== []) {
            throw new InvalidArgumentException('存储对象键无效');
        }
    }

    /**
     * @return string URL 路径编码，保留合法目录分隔符
     */
    public function encoded(): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $this->value)));
    }
}
