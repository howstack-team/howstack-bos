<?php

declare(strict_types=1);

namespace app\modules\content\validation;

use support\validation\Validator;

final class ContentValidator
{
    /** @return array{keyword: string, status: string|null, page: int, page_size: int} */
    public static function page(array $input, bool $categories): array
    {
        $data = Validator::make(array_intersect_key($input, array_flip(['keyword', 'status', 'page', 'page_size'])), [
            'keyword' => 'sometimes|string|max:64',
            'status' => $categories ? 'sometimes|nullable|integer|in:0,1' : 'sometimes|nullable|string|in:draft,published',
            'page' => 'sometimes|integer|min:1|max:100000',
            'page_size' => 'sometimes|integer|in:10,20,50,100',
        ])->validate();
        return ['keyword' => trim((string) ($data['keyword'] ?? '')), 'status' => isset($data['status']) ? (string) $data['status'] : null,
            'page' => (int) ($data['page'] ?? 1), 'page_size' => (int) ($data['page_size'] ?? 10)];
    }

    /** @return array<string, mixed> */
    public static function body(array $input, bool $categories): array
    {
        $rules = $categories ? [
            'name' => 'required|string|min:2|max:64',
            'parent_id' => 'sometimes|nullable|integer|min:1',
            'sort_order' => 'required|integer|min:0|max:9999', 'status' => 'required|integer|in:0,1',
        ] : [
            'category_id' => 'required|integer|min:1', 'title' => 'required|string|min:2|max:200',
            'body_format' => 'sometimes|string|in:markdown,html',
            'summary' => 'sometimes|nullable|string|max:500', 'body' => 'required|string|min:1|max:100000',
            'sort_order' => 'required|integer|min:0|max:9999', 'status' => 'required|string|in:draft,published',
        ];
        return Validator::make(array_intersect_key($input, $rules), $rules)->validate();
    }

    public static function id(mixed $id): int
    {
        $data = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return (int) $data['id'];
    }
}
