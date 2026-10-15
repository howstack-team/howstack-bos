import { Children, useState } from 'react'
import type { ReactNode } from 'react'
import { Button, Drawer, Form, Input, Space } from '@arco-design/web-react'
import { IconDown, IconFilter, IconRefresh, IconSearch, IconUp } from '@arco-design/web-react/icon'
import { useCompactFormViewport } from '../hooks/useCompactFormLayout'

export interface TableSearchField {
  key: string
  label: string
  placeholder?: string
  maxLength?: number
}
interface Props {
  children?: ReactNode
  fields?: readonly TableSearchField[]
  values?: Record<string, string>
  onFieldChange?: (key: string, value: string) => void
  onSearch: () => void
  onReset: () => void
  onCancelFilters?: () => void
  appliedCount?: number
  loading?: boolean
  /** 常显的选择/日期等自定义控件数量；其余随“展开”显示。未设置时沿用原布局。 */
  visibleControls?: number
}

/** 独立查询区：按字段组合筛选，统一查询/重置；低频条件折叠，窄屏在筛选抽屉中填写。 */
export default function TableQueryForm({ children, fields = [], values = {}, onFieldChange,
  onSearch, onReset, onCancelFilters, appliedCount = 0, loading = false, visibleControls }: Props) {
  const desktop = useCompactFormViewport()
  const [expanded, setExpanded] = useState(false)
  const [filterOpen, setFilterOpen] = useState(false)
  const controls = Children.toArray(children)
  const count = fields.length + controls.length
  const controlsCount = visibleControls === undefined ? controls.length : Math.max(0, Math.min(controls.length, visibleControls))
  const visibleCount = desktop ? count > 4 ? Math.max(1, 4 - controlsCount) : fields.length : 1
  const hasCollapsed = count > 4 || controlsCount < controls.length
  const hasMobileFilters = !desktop && count > 1
  const renderField = (field: TableSearchField, drawer = false) => <Form.Item key={field.key} label={desktop || drawer ? field.label : undefined}
    className="table-query-field" style={{ marginBottom: drawer ? 20 : 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}>
    <Input aria-label={field.label} value={values[field.key] ?? ''} allowClear maxLength={field.maxLength ?? 128}
      placeholder={field.placeholder ?? `请输入${field.label}`} onChange={value => onFieldChange?.(field.key, value)}
      style={{ width: drawer ? '100%' : desktop ? 160 : '100%', maxWidth: '100%' }} />
  </Form.Item>
  const submit = () => { if (!loading) { onSearch(); setFilterOpen(false) } }
  const reset = () => { if (!loading) onReset() }
  const cancel = () => { setFilterOpen(false); onCancelFilters?.() }
  return <>
    <Form layout="inline" className={`table-query-form${hasMobileFilters ? ' table-query-form-mobile' : ''}`}
      aria-label="表格查询" onSubmit={submit}>
      {fields.slice(0, visibleCount).map(field => renderField(field))}
      {(desktop || !hasMobileFilters) && controls.slice(0, desktop ? controlsCount : controls.length)}
      {desktop && expanded && fields.slice(visibleCount).map(field => renderField(field))}
      {desktop && expanded && controls.slice(controlsCount)}
      <Form.Item className="table-query-actions" style={{ marginBottom: 0, marginRight: 0, marginLeft: desktop ? 'auto' : 0 }}><Space size="small">
        <Button icon={<IconSearch />} type="primary" htmlType="submit" loading={loading}>查询</Button>
        {!hasMobileFilters && <Button icon={<IconRefresh />} disabled={loading} onClick={reset}>重置</Button>}
        {desktop && hasCollapsed && <Button type="text" icon={expanded ? <IconUp /> : <IconDown />} onClick={() => setExpanded(value => !value)}>{expanded ? '收起' : '展开'}{appliedCount > 0 ? `（${appliedCount}）` : ''}</Button>}
        {hasMobileFilters && <Button icon={<IconFilter />} onClick={() => setFilterOpen(true)}>筛选{appliedCount > 0 ? `（${appliedCount}）` : ''}</Button>}
      </Space></Form.Item>
    </Form>
    {hasMobileFilters && <Drawer title="筛选条件" maskClosable visible={filterOpen} onCancel={cancel} width="min(420px, calc(100vw - 24px))"
      footer={<Space><Button onClick={cancel}>取消</Button><Button icon={<IconRefresh />} disabled={loading} onClick={reset}>重置</Button><Button icon={<IconSearch />} type="primary" loading={loading} onClick={submit}>查询</Button></Space>}>
      <Form layout="vertical" aria-label="高级筛选" onSubmit={submit}>
        {fields.map(field => renderField(field, true))}
        {controls}
      </Form>
    </Drawer>}
  </>
}
