<?php

declare(strict_types=1);

namespace app\modules\message\validation;

use support\validation\Validator;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 消息查询白名单；总体结果与逐渠道投递结果分别校验。 */
final class MessageQueryValidator
{
    /**
     * 验证分页、独立筛选字段和记录日期范围。
     * @param array<string, mixed> $input 请求查询参数
     * @return array<string, int|string|null> 已验证并转换类型的查询条件
     * @throws BusinessException 字段无效时返回可定位的校验错误
     */
    public function query(array $input): array
    {
        $input += ['page' => 1, 'page_size' => 10, 'category' => '', 'topic' => '', 'scene' => '', 'business_type' => '', 'sort_order' => 'desc'];
        $validator = Validator::make($input, ['page' => 'required|integer|min:1|max:100000',
            'page_size' => 'required|integer|in:10,20,50,100', 'category' => 'nullable|string|max:24',
            'topic' => 'nullable|string|max:32', 'scene' => 'nullable|string|max:64', 'business_type' => 'nullable|string|max:64',
            'purpose' => 'nullable|in:verification,notification,marketing,system',
            'channel' => 'nullable|in:email,sms,message,wechat,dingtalk,wecom,feishu',
            'delivery_status' => 'nullable|in:pending,processing,accepted,delivered,failed,unknown',
            'status' => 'nullable|in:inactive,pending,processing,accepted,delivered,partial,failed,unknown',
            'audience' => 'nullable|in:customer,admin,robot',
            'business_id' => 'nullable|integer|min:1|max:9007199254740991',
            'created_from' => 'nullable|date_format:Y-m-d',
            'created_to' => 'nullable|date_format:Y-m-d|after_or_equal:created_from',
            'sort_order' => 'required|in:asc,desc'], [
            'created_from.date_format' => '开始日期格式无效',
            'created_to.date_format' => '结束日期格式无效',
            'created_to.after_or_equal' => '结束日期不能早于开始日期',
            'business_id.integer' => '业务ID必须为整数',
            'business_id.min' => '业务ID必须大于零',
            'business_id.max' => '业务ID超出支持范围',
        ]);
        if ($validator->fails()) {
            throw new BusinessException($validator->errors()->first(), ApiErrorCode::VALIDATION_FAILED, 422,
                ['errors' => $validator->errors()->toArray()]);
        }
        $values = $validator->validate();
        $values['page'] = (int) $values['page'];
        $values['page_size'] = (int) $values['page_size'];
        if (isset($values['business_id']) && $values['business_id'] !== '') {
            $values['business_id'] = (int) $values['business_id'];
        }
        return $values;
    }
}
