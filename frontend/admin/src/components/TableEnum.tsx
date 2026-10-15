import { Badge } from '@arco-design/web-react'
import { enumMeta, type StatusMap } from '../utils/tableEnum'
import { tableCellText } from '../utils/tableCellText'

interface StatusProps { value: unknown; mapping: StatusMap }

/** 流程状态使用原生圆点加文字；未知值中性展示并保留原始值提示。 */
export function StatusBadge({ value, mapping }: StatusProps) {
  const text = tableCellText(value)
  if (text === '-') return <span>-</span>
  const meta = enumMeta(mapping, value)
  return <span title={meta ? undefined : String(text)} style={{ whiteSpace: 'nowrap' }}>
    <Badge status={meta?.status ?? 'default'} text={meta?.text ?? '未知状态'} />
  </span>
}
