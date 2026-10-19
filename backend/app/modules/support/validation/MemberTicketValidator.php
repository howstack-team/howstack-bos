<?php

declare(strict_types=1);

namespace app\modules\support\validation;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use support\validation\Validator;

/** 会员不能指定身份、状态、优先级或消息作者；统一字段错误。 */
final class MemberTicketValidator
{
    /**
     * 校验分页及既有状态筛选。
     * @param array<string,mixed> $input 未信任的HTTP字段
     * @return array{page:int,page_size:int,status:?string} 有界查询
     */
    public static function page(array $input): array
    {
        $data = self::validate($input, ['page' => 'sometimes|required|integer|min:1|max:100000', 'page_size' => 'sometimes|required|integer|in:10,20,50,100',
            'status' => 'sometimes|nullable|string|in:open,processing,waiting_member,closed']);
        return ['page' => (int) ($data['page'] ?? 1), 'page_size' => (int) ($data['page_size'] ?? 20), 'status' => $data['status'] ?? null];
    }

    /**
     * 校验会话窗口游标。
     * @param array<string,mixed> $input 未信任的HTTP字段
     * @return int|null 已校验结果或统一响应
     */
    public static function cursor(array $input): ?int
    {
        $data = self::validate($input, ['before_id' => 'sometimes|required|integer|min:1|max:4294967295']);
        return isset($data['before_id']) ? (int) $data['before_id'] : null;
    }

    /**
     * 校验新建字段，拒绝客户端身份和状态。
     * @param array<string,mixed> $input 未信任的HTTP字段
     * @return array{type_id:int,service_id:?int,subject:string,content:string,request_key:string} 规范化新建字段
     */
    public static function create(array $input): array
    {
        foreach (['subject', 'content'] as $field) if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]);
        $data = self::validate($input, ['type_id' => 'required|integer|min:1|max:4294967295', 'service_id' => 'sometimes|nullable|integer|min:1|max:4294967295',
            'subject' => 'required|string|min:1|max:200', 'content' => 'required|string|min:1|max:10000', 'request_key' => self::keyRule()]);
        return ['type_id' => (int) $data['type_id'], 'service_id' => isset($data['service_id']) ? (int) $data['service_id'] : null,
            'subject' => $data['subject'], 'content' => $data['content'], 'request_key' => $data['request_key']];
    }

    /**
     * 校验回复或关闭命令字段。
     * @param array<string,mixed> $input 未信任的HTTP字段
     * @param bool $reply 是否为回复动作
     * @return array{request_key:string,content?:string} 命令字段
     */
    public static function command(array $input, bool $reply): array
    {
        if (is_string($input['content'] ?? null)) $input['content'] = trim($input['content']);
        return self::validate($input, ['request_key' => self::keyRule()] + ($reply ? ['content' => 'required|string|min:1|max:10000'] : []));
    }

    /**
     * 返回幂等键格式规则。
     * @return list<string> 键格式规则
     */
    private static function keyRule(): array { return ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/D']; }

    /**
     * 按字段白名单校验输入。
     * @param array<string,mixed> $input 未信任的HTTP字段
     * @param array<string,mixed> $rules 字段白名单及校验规则
     * @return array<string,mixed> 已校验结果或统一响应
     */
    private static function validate(array $input, array $rules): array
    {
        $errors = [];
        foreach (array_diff(array_keys($input), array_keys($rules)) as $field) $errors[$field] = ['不支持此字段'];
        $validator = Validator::make($input, $rules)->toIlluminate();
        if ($validator->fails()) $errors += $validator->errors()->toArray();
        if ($errors !== []) throw new BusinessException('请检查工单字段', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $errors]);
        return $validator->validated();
    }
}
