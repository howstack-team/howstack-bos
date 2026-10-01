import { Space } from '@arco-design/web-react'
import { IconCheckCircleFill, IconMinusCircleFill } from '@arco-design/web-react/icon'
import './EnabledStatus.css'

interface EnabledStatusProps {
  /** 后台状态：1 为启用，0 为禁用；其他值显示占位符。 */
  status: number | null | undefined
}

/** 表格中统一展示启用 / 禁用状态。 */
export default function EnabledStatus({ status }: EnabledStatusProps) {
  if (status !== 1 && status !== 0) return <span>-</span>

  return <Space size="mini">
    {status === 1
      ? <IconCheckCircleFill className="enabled-status__success" aria-hidden="true" />
      : <IconMinusCircleFill className="enabled-status__warning" aria-hidden="true" />}
    <span>{status === 1 ? '启用' : '禁用'}</span>
  </Space>
}
