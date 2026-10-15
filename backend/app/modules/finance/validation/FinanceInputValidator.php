<?php

declare(strict_types=1);

namespace app\modules\finance\validation;

use support\validation\Validator;

final class FinanceInputValidator
{
    public static function resource(mixed $resource): string
    {
        return Validator::make(['resource' => $resource], [
            'resource' => 'required|string|in:recharges,refunds,invoices,withdrawals',
        ])->validate()['resource'];
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
        return self::normalize(Validator::make(array_intersect_key($input, $rules), $rules)->validate());
    }

    public static function action(string $resource, mixed $id, mixed $action, array $input): array
    {
        $actions = match ($resource) {
            'refunds' => 'approve,reject,execute', 'invoices' => 'approve,reject,issue,red',
            'withdrawals' => 'approve,reject,payout', default => 'none',
        };
        $route = Validator::make(['id' => $id, 'action' => $action], [
            'id' => 'required|integer|min:1', 'action' => 'required|string|in:' . $actions,
        ])->validate();
        $rules = ['request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/']];
        if (in_array($action, ['approve', 'reject'], true)) {
            $rules['review_reason'] = ($action === 'reject' ? 'required' : 'sometimes') . '|string|max:255';
        }
        if (in_array($action, ['issue', 'red', 'payout'], true)) {
            $rules['external_reference'] = 'required|string|max:80';
        }
        return ['id' => (int) $route['id'], 'action' => $route['action'],
            'input' => self::normalize(Validator::make(array_intersect_key($input, $rules), $rules)->validate())];
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
