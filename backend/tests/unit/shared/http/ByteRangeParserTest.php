<?php

declare(strict_types=1);

namespace tests\unit\shared\http;

use app\shared\http\ByteRangeParser;
use PHPUnit\Framework\TestCase;

final class ByteRangeParserTest extends TestCase
{
    public function testRangesAreClampedAndUnsatisfiableRangesAreDistinguished(): void
    {
        $parser = new ByteRangeParser();
        foreach ([
            'bytes=0-0' => ['start' => 0, 'length' => 1],
            'bytes=90-' => ['start' => 90, 'length' => 10],
            'bytes=-10' => ['start' => 90, 'length' => 10],
            'bytes=-999999999999999999999999' => ['start' => 0, 'length' => 100],
            'bytes=10-9999999999999999999999' => ['start' => 10, 'length' => 90],
            'bytes=100-' => false,
            'bytes=999999999999999999999999-' => false,
            'bytes=90-10' => false,
            'bytes=-0' => false,
        ] as $header => $expected) {
            self::assertSame($expected, $parser->parse($header, 100), $header);
        }
        self::assertFalse($parser->parse('bytes=0-', 0));
    }

    public function testMalformedAndMultipartHeadersUseFullResponse(): void
    {
        $parser = new ByteRangeParser();
        foreach (['', 'bytes=-', 'bytes=0-2,4-8', 'items=0-2', 'bytes=foo-12', 'bytes=0-12extra'] as $header) {
            self::assertNull($parser->parse($header, 100));
        }
    }
}
