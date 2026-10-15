<?php

declare(strict_types=1);

namespace app\modules\business_analysis\validation;

use app\exception\BusinessException;
use app\modules\business_analysis\domain\AnalysisPeriod;
use app\shared\http\ApiErrorCode;
use DateTimeImmutable;
use DateTimeZone;
use support\validation\Validator;

/** 限制查询跨度，严格校验日历日期并提供可定位的字段错误。 */
final class AnalysisQueryValidator
{
    /**
     * @param array<string, mixed> $input 查询参数；未知字段不参与查询
     * @param DateTimeImmutable|null $now 可注入的当前时刻，默认使用系统业务时区
     * @return AnalysisPeriod 已验证的查询区间，最多 366 天
     * @throws BusinessException 日期无效、倒序、未来日期或跨度过大
     */
    public static function validate(array $input, ?DateTimeImmutable $now = null): AnalysisPeriod
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone((string) config('app.default_timezone', 'Asia/Shanghai')));
        $rules = [
            'start_date' => 'sometimes|required|date_format:Y-m-d',
            'end_date' => 'sometimes|required|date_format:Y-m-d|before_or_equal:' . $now->format('Y-m-d'),
            'granularity' => 'sometimes|required|string|in:day,month',
        ];
        $validator = Validator::make(array_intersect_key($input, $rules), $rules);
        if ($validator->fails()) {
            throw self::invalid($validator->errors()->toArray());
        }
        $values = $validator->validated();
        $end = new DateTimeImmutable($values['end_date'] ?? $now->format('Y-m-d'), $now->getTimezone());
        $start = isset($values['start_date'])
            ? new DateTimeImmutable($values['start_date'], $now->getTimezone())
            : $end->modify('-29 days');
        if ($start > $end) {
            throw self::invalid(['end_date' => ['结束日期不能早于开始日期']]);
        }
        $period = new AnalysisPeriod($start, $end, $values['granularity'] ?? 'day', $now);
        if ($period->days() > 366) {
            throw self::invalid(['start_date' => ['单次查询最多包含 366 天']]);
        }

        return $period;
    }

    /** @param array<string, list<string>> $errors 字段错误 */
    private static function invalid(array $errors): BusinessException
    {
        return new BusinessException('参数校验失败', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $errors]);
    }
}
