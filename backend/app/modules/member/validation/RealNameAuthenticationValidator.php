<?php

declare(strict_types=1);

namespace app\modules\member\validation;

use support\validation\Validator;

/** 验证实名认证提交、查询与审核输入。 */
final class RealNameAuthenticationValidator
{
    /** @return array{keyword: string, type: string|null, status: string|null, page: int, page_size: int} */
    public static function page(array $input): array
    {
        $validated = Validator::make([
            'keyword' => $input['keyword'] ?? '', 'type' => $input['type'] ?? null,
            'status' => $input['status'] ?? null, 'page' => $input['page'] ?? 1, 'page_size' => $input['page_size'] ?? 10,
        ], [
            'keyword' => 'string|max:64', 'type' => 'nullable|string|in:personal,enterprise',
            'status' => 'nullable|string|in:pending,approved,rejected',
            'page' => 'required|integer|min:1|max:100000', 'page_size' => 'required|integer|min:1|max:100',
        ])->validate();
        return ['keyword' => trim($validated['keyword']), 'type' => $validated['type'], 'status' => $validated['status'],
            'page' => (int) $validated['page'], 'page_size' => (int) $validated['page_size']];
    }

    /** @return array<string, mixed> */
    public static function personal(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['real_name', 'id_type', 'id_number']));
        return Validator::make($data, [
            'real_name' => 'required|string|min:2|max:64', 'id_type' => 'required|string|in:identity_card,passport',
            'id_number' => ['required', 'string', 'regex:/^[A-Za-z0-9()]{6,32}$/'],
        ])->validate();
    }

    /** @return array<string, mixed> */
    public static function enterprise(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['company_name', 'registration_code', 'legal_representative', 'legal_representative_id_number']));
        return Validator::make($data, [
            'company_name' => 'required|string|min:2|max:128',
            'registration_code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,32}$/'],
            'legal_representative' => 'required|string|min:2|max:64',
            'legal_representative_id_number' => ['required', 'string', 'regex:/^[A-Za-z0-9()]{6,32}$/'],
        ])->validate();
    }

    /** @return array{decision: string, reason: string} */
    public static function review(array $input): array
    {
        $data = array_intersect_key($input, array_flip(['decision', 'reason']));
        $validated = Validator::make($data, ['decision' => 'required|string|in:approved,rejected', 'reason' => 'sometimes|nullable|string|max:255'])->validate();
        return ['decision' => $validated['decision'], 'reason' => trim((string) ($validated['reason'] ?? ''))];
    }
}
