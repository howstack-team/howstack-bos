import type { ReactNode } from 'react'
import { Button, ColorPicker, Empty, Form, Input, InputNumber, Select, Space, Switch, Table, Tooltip, Typography, type TableColumnProps } from '@arco-design/web-react'
import { IconDelete, IconDown, IconPlus, IconUp } from '@arco-design/web-react/icon'
import SectionTitle from '../SectionTitle'
import TableActions from '../TableActions'
import { newWebsiteValue, type WebsiteSchema, type WebsiteValue, type WebsiteObject } from '../../utils/websiteSchema'
interface Props {
  schema: WebsiteSchema; value: WebsiteValue | undefined; onChange: (value: WebsiteValue) => void; disabled: boolean
  path?: string; errors: Record<string, string>; labels?: Record<string, string>
  hideLabel?: boolean
  image: (value: string, onChange: (id: string) => void, label: string, compact?: boolean) => ReactNode
}
/** 受控的有限 Schema 表单；完整值由宿主持有，不加载脚本、远程引用或模板代码。 */
export default function WebsiteSchemaFields({ schema, value, onChange, disabled, path = 'settings', errors, labels = {}, image, hideLabel = false }: Props) {
  const last = path.split('.').at(-1)!
  const label = labels[path] ?? schema.title ?? (/^\d+$/.test(last) ? `第${Number(last) + 1}项` : last)
  const childProps = { disabled, errors, labels, image }
  const error = errors[path]
  let input: ReactNode
  if (schema.enum) {
    input = <Select aria-label={label} placeholder={`请选择${label}`} value={schema.enum.findIndex(item => JSON.stringify(item) === JSON.stringify(value)) >= 0
      ? String(schema.enum.findIndex(item => JSON.stringify(item) === JSON.stringify(value))) : undefined}
      disabled={disabled} options={schema.enum.map((option, index) => ({ label: typeof option === 'object' ? JSON.stringify(option) : String(option), value: String(index) }))}
      onChange={index => onChange(structuredClone(schema.enum![Number(index)]))} />
  } else if (schema.type === 'object') {
    const object = value && typeof value === 'object' && !Array.isArray(value) ? value as WebsiteObject : {}
    return <div className="website-schema-group" id={`website-field-${path}`}>
      {path !== 'settings' && !/^\d+$/.test(last) && <SectionTitle>{label}</SectionTitle>}
      {schema.description && <Typography.Text type="secondary">{schema.description}</Typography.Text>}
      {error && <Typography.Text type="error">{error}</Typography.Text>}
      {Object.entries(schema.properties ?? {}).map(([key, child]) => <WebsiteSchemaFields key={key} {...childProps} schema={child} value={Object.hasOwn(object, key) ? object[key] : undefined}
        path={`${path}.${key}`} onChange={next => onChange({ ...object, [key]: next })} />)}
    </div>
  } else if (schema.type === 'array') {
    const rows = Array.isArray(value) ? value : []
    function move(index: number, delta: number) { const next = [...rows]; [next[index], next[index + delta]] = [next[index + delta], next[index]]; onChange(next) }
    interface Row { index: number; value: WebsiteObject }
    const tableItems = schema.items!.type === 'object' && !schema.items!.enum
    const columns: TableColumnProps<Row>[] = [
      { title: '序号', width: 64, render: (_, row) => row.index + 1 },
      ...Object.entries(schema.items!.properties ?? {}).map(([key, child]) => ({
        title: child.description ? <Tooltip content={child.description}>{child.title ?? key}</Tooltip> : child.title ?? key,
        width: child.format === 'image-id' ? 280 : key === 'description' || child['x-widget'] === 'textarea' ? 260 : 180,
        render: (_: unknown, row: Row) => <WebsiteSchemaFields {...childProps} hideLabel schema={child} value={row.value[key]} path={`${path}.${row.index}.${key}`}
          onChange={next => onChange(rows.map((item, index) => index === row.index ? { ...row.value, [key]: next } : item))} />,
      })),
      { title: '操作', width: 160, render: (_, row) => <div style={{ whiteSpace: 'nowrap' }}>
        {errors[`${path}.${row.index}`] && <div className="website-field-error">{errors[`${path}.${row.index}`]}</div>}
        <TableActions actions={disabled ? [] : [
          ...(row.index > 0 ? [{ key: 'up', label: '上移', onClick: () => move(row.index, -1) }] : []),
          ...(row.index < rows.length - 1 ? [{ key: 'down', label: '下移', onClick: () => move(row.index, 1) }] : []),
          ...(rows.length > (schema.minItems ?? 0) ? [{ key: 'delete', label: '删除', danger: true, onClick: () => onChange(rows.filter((_, index) => index !== row.index)) }] : []),
        ]} />
      </div> },
    ]
    return <div className="website-schema-list" id={`website-field-${path}`}>
      <div className="website-list-heading"><SectionTitle>{label}</SectionTitle><Button icon={<IconPlus />} disabled={disabled || rows.length >= schema.maxItems!}
        onClick={() => onChange([...rows, newWebsiteValue(schema.items!)])}>添加</Button></div>
      {schema.description && <Typography.Text type="secondary">{schema.description}</Typography.Text>}
      {error && <Typography.Text type="error">{error}</Typography.Text>}
      {tableItems ? <Table<Row> rowKey="index" aria-label={`${label}配置`} columns={columns} data={rows.map((value, index) => ({ index, value: value as WebsiteObject }))}
        pagination={false} scroll={{ x: columns.reduce((width, column) => width + Number(column.width), 0) }} noDataElement={<Empty description={`暂无${label}`} />} /> : <>
      {!rows.length && <Empty description={`暂无${label}`} />}
      {rows.map((row, index) => <div className="website-list-item" key={index}><div className="website-list-item-heading"><span>{label} {index + 1}</span><Space>
        <Button aria-label={`上移${label}${index + 1}`} icon={<IconUp />} size="small" disabled={disabled || index === 0} onClick={() => move(index, -1)} />
        <Button aria-label={`下移${label}${index + 1}`} icon={<IconDown />} size="small" disabled={disabled || index === rows.length - 1} onClick={() => move(index, 1)} />
        <Button aria-label={`删除${label}${index + 1}`} icon={<IconDelete />} size="small" disabled={disabled || rows.length <= (schema.minItems ?? 0)} onClick={() => onChange(rows.filter((_, i) => i !== index))} />
      </Space></div><WebsiteSchemaFields {...childProps} schema={schema.items!} value={row} path={`${path}.${index}`} onChange={next => onChange(rows.map((item, i) => i === index ? next : item))} /></div>)}</>}
    </div>
  } else if (schema.format === 'image-id') input = image(typeof value === 'string' ? value : '', onChange, label, hideLabel)
  else if (schema.format === 'color') input = <ColorPicker value={typeof value === 'string' ? value : ''} mode="single" format="hex" disabledAlpha disabled={disabled}
    triggerProps={{ className: 'website-color-popup' }} showText
    triggerElement={<Button aria-label={`选择${label}`} disabled={disabled}><Space><span className="website-color-sample" style={{ backgroundColor: typeof value === 'string' ? value : 'transparent' }} />{String(value ?? '')}</Space></Button>}
    onChange={next => { if (typeof next === 'string') onChange(next) }} />
  else if (schema.type === 'boolean') input = <Switch type="round" aria-label={label} checked={value === true} disabled={disabled} onChange={onChange} />
  else if (schema.type === 'number' || schema.type === 'integer') input = <InputNumber aria-label={label} placeholder={`请输入${label}`} mode="button" precision={schema.type === 'integer' ? 0 : undefined}
    value={typeof value === 'number' ? value : undefined} min={schema.minimum} max={schema.maximum} disabled={disabled} style={{ width: '100%' }} onChange={next => onChange(next ?? null)} />
  else if (schema['x-widget'] === 'textarea') input = <Input.TextArea aria-label={label} placeholder={`请输入${label}`} value={typeof value === 'string' ? value : ''} disabled={disabled} autoSize={{ minRows: 3, maxRows: 8 }} onChange={onChange} />
  else input = <Input aria-label={label} placeholder={`请输入${label}`} value={typeof value === 'string' ? value : ''} disabled={disabled} onChange={onChange} />
  return <Form.Item label={hideLabel ? undefined : label} tooltip={schema.description} validateStatus={error ? 'error' : undefined} help={error} id={`website-field-${path}`} style={{ margin: 0, maxWidth: 680 }}>{input}</Form.Item>
}
