<?php

declare(strict_types=1);

namespace app\modules\support\validation;

use support\validation\Validator;

final class SupportValidator
{
    /** @return array{keyword: string, status: string|null, page: int, page_size: int} */
    public static function page(array $input, bool $ticket = false): array
    {
        $data = Validator::make(array_intersect_key($input, array_flip(['keyword', 'status', 'page', 'page_size'])), [
            'keyword' => 'sometimes|string|max:64',
            'status' => $ticket ? 'sometimes|nullable|string|in:open,processing,waiting_member,closed' : 'sometimes|nullable|integer|in:0,1',
            'page' => 'sometimes|integer|min:1|max:100000',
            'page_size' => 'sometimes|integer|in:10,20,50,100',
        ])->validate();
        return ['keyword' => trim((string) ($data['keyword'] ?? '')), 'status' => isset($data['status']) ? (string) $data['status'] : null,
            'page' => (int) ($data['page'] ?? 1), 'page_size' => (int) ($data['page_size'] ?? 10)];
    }

    /** @return array<string, mixed> */
    public static function catalog(string $kind, array $input): array
    {
        $fields = $kind === 'types' ? ['name', 'code', 'sort_order', 'status'] : ['title', 'content', 'sort_order', 'status'];
        return Validator::make(array_intersect_key($input, array_flip($fields)), $kind === 'types' ? [
            'name' => 'required|string|min:2|max:64',
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'sort_order' => 'required|integer|min:0|max:9999', 'status' => 'required|integer|in:0,1',
        ] : [
            'title' => 'required|string|min:2|max:100', 'content' => 'required|string|min:1|max:5000',
            'sort_order' => 'required|integer|min:0|max:9999', 'status' => 'required|integer|in:0,1',
        ])->validate();
    }

    public static function id(mixed $id): int
    {
        $data = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return (int) $data['id'];
    }

    public static function reply(array $input): string
    {
        $data = Validator::make(array_intersect_key($input, ['content' => true]), [
            'content' => 'required|string|min:1|max:10000',
        ])->validate();
        return (string) $data['content'];
    }
}
