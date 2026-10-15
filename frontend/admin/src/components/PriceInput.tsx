import { InputNumber } from '@arco-design/web-react'
import type { InputNumberProps } from '@arco-design/web-react'
import { minorToYuanText, yuanToMinor } from '../utils/money'

/** 组件对外使用整数分，用户始终以元编辑，不改变 Form 或 API 的金额契约。 */
export interface PriceInputProps extends Omit<InputNumberProps, 'value' | 'defaultValue' | 'onChange' | 'min' | 'max' | 'step' | 'precision' | 'strictMode' | 'prefix' | 'suffix' | 'formatter' | 'parser'> {
  /** 接口金额，单位分；undefined 表示尚未填写。 */
  value?: number
  /** 编辑后的整数分；清空输入时返回 undefined。 */
  onChange?: (minor: number | undefined) => void
  /** 上限，单位分；默认沿用云产品接口的 100000000 分。 */
  maxMinor?: number
  /** 展示单位，可附带单价计量单位，如元 / GiB。 */
  unit?: string
}

/** 带 ¥ 前缀和元后缀的 Arco 价格输入，使用字符串模式精确转换到整数分。 */
export default function PriceInput({ value, onChange, maxMinor = 100000000, unit = '元', style, placeholder = '请输入金额', ...props }: PriceInputProps) {
  return <InputNumber {...props} placeholder={placeholder} style={{ width: '100%', ...style }} min={0} max={Number(minorToYuanText(maxMinor))} step={0.01}
    precision={2} strictMode prefix="¥" suffix={unit} value={value === undefined ? undefined : minorToYuanText(value)}
    onChange={(yuan: number | string | undefined) => onChange?.(yuan === undefined || yuan === '' ? undefined : yuanToMinor(yuan))} />
}
