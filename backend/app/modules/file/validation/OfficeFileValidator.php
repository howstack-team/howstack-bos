<?php

declare(strict_types=1);

namespace app\modules\file\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use ZipArchive;

/** 检查 Office 文件结构和解压上限；仅接收无宏的 DOCX/XLSX 和旧 XLS。 */
final class OfficeFileValidator
{
    /** @return string 校验通过的标准 MIME */
    public function validate(string $bytes, string $extension, string $mime): string
    {
        if ($extension === 'xls') {
            if (!in_array($mime, ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'], true)
                || strlen($bytes) < 512 || !str_starts_with($bytes, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")
                || (!str_contains($bytes, "W\0o\0r\0k\0b\0o\0o\0k\0\0\0") && !str_contains($bytes, "B\0o\0o\0k\0\0\0"))) {
                $this->invalid();
            }
            return 'application/vnd.ms-excel';
        }
        $standardMime = $extension === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        if (!in_array($mime, [$standardMime, 'application/zip', 'application/x-zip'], true)) {
            $this->invalid();
        }
        $path = tempnam(sys_get_temp_dir(), 'howstack-office-');
        if ($path === false) {
            throw new \RuntimeException('无法创建 Office 文件校验临时文件');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            chmod($path, 0600);
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('无法写入 Office 文件校验临时文件');
            }
            $opened = $zip->open($path, ZipArchive::RDONLY) === true;
            if (!$opened || $zip->numFiles > 3000) {
                $this->invalid();
            }
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false || $entry['size'] > 32 * 1048576 || ($entry['encryption_method'] ?? 0) !== 0
                    || str_contains($entry['name'], '..') || str_starts_with($entry['name'], '/')
                    || str_contains($entry['name'], '\\') || str_ends_with(strtolower($entry['name']), 'vbaproject.bin')) {
                    $this->invalid();
                }
                $expanded += $entry['size'];
                if ($expanded > 64 * 1048576) {
                    $this->invalid();
                }
            }
            $types = $zip->getFromName('[Content_Types].xml', 1048577);
            $content = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $mainType = $extension === 'docx'
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
            if ($types === false || strlen($types) > 1048576 || preg_match('/<!DOCTYPE|<!ENTITY/i', $types)
                || $zip->locateName($content) === false) {
                $this->invalid();
            }
            $document = new \DOMDocument();
            // 不展开实体、不加载网络 DTD；格式错误归为上传参数错误。
            if (!@$document->loadXML($types, LIBXML_NONET)) {
                $this->invalid();
            }
            $found = false;
            foreach ($document->getElementsByTagName('Override') as $entry) {
                if ($entry->getAttribute('PartName') === '/' . $content && $entry->getAttribute('ContentType') === $mainType) {
                    $found = true;
                }
            }
            if (!$found) {
                $this->invalid();
            }
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($path);
        }
        return $standardMime;
    }

    private function invalid(): never
    {
        throw new BusinessException('Office 文件内容与格式不符或超出解压限制', ApiErrorCode::VALIDATION_FAILED, 422, [
            'errors' => ['file' => ['请上传有效的 DOCX、XLS 或 XLSX 文件，不支持加密或含宏的 OOXML 文件']],
        ]);
    }
}
