<?php

declare(strict_types=1);

namespace app\modules\finance\validation;

use support\validation\Validator;

final class FinanceInputValidator
{
    public static function resource(mixed $resource): string
    {
        return self::validate(['resource' => $resource], [
            'resource' => 'required|string|in:recharges,refunds,invoices,withdrawals',
        ])['resource'];
    }

    public static function create(string $resource, array $input): array
    {
        $rules = [
            'request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'member_id' => 'required|integer|min:1', 'amount_minor' => 'required|integer|min:1|max:100000000',
            'remark' => 'sometimes|string|max:255',
        ];
        if ($resource === 'recharges') {
            $rules['external_reference'] = 'required|string|max:80';
        } else {
            $rules['source_id'] = 'required|integer|min:1';
        }
        if (in_array($resource, ['refunds', 'withdrawals'], true)) {
            $rules['reason'] = 'required|string|max:255';
        }
        if ($resource === 'invoices') {
            $rules['title'] = 'required|string|max:160';
            $rules['invoice_type'] = 'required|string|in:electronic,paper';
        }
        return self::normalize(self::validate(array_intersect_key($input, $rules), $rules));
    }

    public static function action(string $resource, mixed $id, mixed $action, array $input): array
    {
        $actions = match ($resource) {
            'refunds' => 'approve,reject,execute', 'invoices' => 'approve,reject,issue,red',
            'withdrawals' => 'approve,reject,payout', default => 'none',
        };
        $route = self::validate(['id' => $id, 'action' => $action], [
            'id' => 'required|integer|min:1', 'action' => 'required|string|in:' . $actions,
        ]);
        $rules = ['request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/']];
        if (in_array($action, ['approve', 'reject'], true)) {
            $rules['review_reason'] = ($action === 'reject' || in_array($resource, ['refunds', 'withdrawals'], true) ? 'required' : 'sometimes') . '|string|max:255';
        }
        if (in_array($action, ['issue', 'red', 'payout'], true)) {
            $rules['external_reference'] = 'required|string|max:80';
        }
        if ($action === 'payout') {
            $rules['payout_note'] = 'required|string|max:500';
        }
        return ['id' => (int) $route['id'], 'action' => $route['action'],
            'input' => self::normalize(self::validate(array_intersect_key($input, $rules), $rules))];
    }

    /** @return array<string,mixed> 字段错误统一422，由前端定位字段，不重复弹窗 */
    private static function validate(array $input, array $rules): array
    {
        $input = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $input);
        $validator = Validator::make($input, $rules)->toIlluminate();
        if ($validator->fails()) {
            throw new \app\exception\BusinessException('请检查财务操作字段', \app\shared\http\ApiErrorCode::VALIDATION_FAILED, 422,
                ['errors' => $validator->errors()->toArray()]);
        }
        return $validator->validated();
    }

    private static function normalize(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = in_array($key, ['member_id', 'source_id', 'amount_minor'], true) ? (int) $value
                : (is_string($value) ? trim($value) : $value);
        }
        return $values;
    }
}
