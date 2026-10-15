<?php

declare(strict_types=1);

namespace app\modules\file\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;
use Webman\Http\UploadFile;

/** 文件列表、删除与上传的外部参数白名单。 */
final class FileInputValidator
{
    /** @return array<string, mixed> 已验证并转换分页类型的条件 */
    public function query(array $input): array
    {
        $rules = [
            'page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|in:10,20,50,100',
            'name' => 'nullable|string|max:200',
            'type' => 'nullable|in:image,document,video,audio,other',
            'origin' => 'nullable|in:website,help,news,content,library',
            'status' => 'nullable|in:stored,pending,failed,unknown,deleting,delete_failed',
            'namespace' => 'nullable|in:website,content,files',
            'sort_by' => 'nullable|in:created_at,byte_size',
            'sort_order' => 'nullable|in:asc,desc',
        ];
        $values = $this->validate($input + ['page' => 1, 'page_size' => 10], $rules);
        $values['page'] = (int) $values['page'];
        $values['page_size'] = (int) $values['page_size'];
        return $values;
    }

    /** @return list<string> 去重后的文件标识 */
    public function deletion(array $input): array
    {
        $values = $this->validate($input, [
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D'],
        ]);
        if (!array_is_list($values['ids'])) {
            throw new BusinessException('请选择有效的文件列表', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['ids' => ['文件列表必须为数组']]]);
        }
        return array_values(array_unique($values['ids']));
    }

    public function upload(mixed $file, array $fields): UploadFile
    {
        $this->validate(['file' => $file, ...$fields], ['file' => ['required', static function (string $attribute, mixed $value, \Closure $fail): void {
            if (!$value instanceof UploadFile || !$value->isValid() || $value->getSize() === 0 || $value->getSize() > 20971520) {
                $fail('请选择20MiB以内的有效文件');
            }
        }]]);
        return $file;
    }

    private function validate(array $input, array $rules): array
    {
        $keys = array_filter(array_keys($rules), static fn (string $key): bool => !str_contains($key, '.'));
        $validator = Validator::make(['input' => $input], ['input' => 'array:' . implode(',', $keys), ...array_combine(array_map(static fn (string $key): string => 'input.' . $key, array_keys($rules)), array_values($rules))]);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $key => $messages) $errors[preg_replace('/^input\./', '', $key)] = $messages;
            throw new BusinessException('文件参数校验失败', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $errors]);
        }
        return $validator->validated()['input'];
    }
}
