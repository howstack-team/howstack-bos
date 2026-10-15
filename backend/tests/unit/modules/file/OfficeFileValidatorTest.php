<?php

declare(strict_types=1);

namespace tests\unit\modules\file;

use app\exception\BusinessException;
use app\modules\file\validation\OfficeFileValidator;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class OfficeFileValidatorTest extends TestCase
{
    public function testOOXMLPackagesMustMatchTheirDocumentType(): void
    {
        $validator = new OfficeFileValidator();
        foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml'] as $extension => $main) {
            $bytes = $this->package($extension, [$main => '<document/>']);
            self::assertStringStartsWith('application/vnd.openxmlformats-officedocument.', $validator->validate($bytes, $extension, 'application/zip'));
            $this->assertRejected($bytes, $extension === 'docx' ? 'xlsx' : 'docx');
        }
        $this->assertRejected('not an excel document', 'xls', 'application/vnd.ms-excel');
        $this->assertRejected('not a word document', 'docx', 'text/plain');
    }

    public function testMacrosTraversalAndCompressedOversizeAreRejected(): void
    {
        foreach ([
            ['word/document.xml' => '<document/>', 'word/vbaProject.bin' => 'macro'],
            ['word/document.xml' => '<document/>', '../escape.xml' => 'outside'],
            ['word/document.xml' => str_repeat('a', 33 * 1048576)],
            ['wrong.xml' => '<document/>'],
        ] as $entries) {
            $this->assertRejected($this->package('docx', $entries), 'docx');
        }
    }

    private function assertRejected(string $bytes, string $extension, string $mime = 'application/zip'): void
    {
        try {
            (new OfficeFileValidator())->validate($bytes, $extension, $mime);
            self::fail('格式错误的文件应拒绝');
        } catch (BusinessException $error) {
            self::assertSame(422, $error->httpStatus());
        }
    }

    /** @param array<string, string> $entries */
    private function package(string $extension, array $entries): string
    {
        $main = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
        $type = $extension === 'docx' ? 'wordprocessingml.document' : 'spreadsheetml.sheet';
        $path = tempnam(sys_get_temp_dir(), 'howstack-office-test-');
        try {
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/' . $main . '" ContentType="application/vnd.openxmlformats-officedocument.' . $type . '.main+xml"/></Types>');
            foreach ($entries as $name => $value) {
                $zip->addFromString($name, $value);
            }
            $zip->close();
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
