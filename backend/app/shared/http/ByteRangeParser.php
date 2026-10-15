<?php

declare(strict_types=1);

namespace app\shared\http;

/** 单段字节范围；语法无效或多段请求忽略，范围不可满足时返回 false。 */
final class ByteRangeParser
{
    /** @return array{start: int, length: int}|false|null null 表示完整响应 */
    public function parse(string $header, int $size): array|false|null
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $parts) || ($parts[1] === '' && $parts[2] === '')) {
            return null;
        }
        if ($size === 0) {
            return false;
        }
        if ($parts[1] === '') {
            $length = $this->boundedInteger($parts[2], $size);
            return $length === 0 ? false : ['start' => $size - $length, 'length' => $length];
        }
        $start = $this->boundedInteger($parts[1], $size);
        if ($start >= $size) {
            return false;
        }
        $end = $parts[2] === '' ? $size - 1 : $this->boundedInteger($parts[2], $size - 1);
        return $end < $start ? false : ['start' => $start, 'length' => $end - $start + 1];
    }

    /** 避免超长数字溢出，范围上界可以按文件大小截断。 */
    private function boundedInteger(string $value, int $maximum): int
    {
        $value = ltrim($value, '0');
        $limit = (string) $maximum;
        if (strlen($value) > strlen($limit) || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0)) {
            return $maximum;
        }
        return (int) $value;
    }
}
