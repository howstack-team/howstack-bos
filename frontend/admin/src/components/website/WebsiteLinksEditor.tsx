import type { ReactNode } from 'react'
import { Button, Empty, Form, Input, Select, Table, type TableColumnProps } from '@arco-design/web-react'
import { IconPlus } from '@arco-design/web-react/icon'
import SectionTitle from '../SectionTitle'
import TableActions from '../TableActions'
import type { WebsitePage } from '../../api/websiteApi'
import type { WebsiteObject, WebsiteValue } from '../../utils/websiteSchema'
interface Props { value: WebsiteValue[]; onChange: (value: WebsiteValue[]) => void; themeId: string; pages: WebsitePage[]; group: 'navigation' | 'footer_links'; disabled: boolean; errors: Record<string, string> }
interface LinkRow { index: number; row: WebsiteObject }
/** 条件导航编辑器，切换目标时仅提交该类型的字段，顺序以完整数组保存。 */
export default function WebsiteLinksEditor({ value, onChange, themeId, pages, group, disabled, errors }: Props) {
  const label = group === 'navigation' ? '顶部导航' : '页脚链接'
  const core = pages.filter(page => page.kind === 'core'), custom = pages.filter(page => page.kind === 'custom')
  function update(index: number, next: WebsiteObject) { onChange(value.map((row, i) => i === index ? next : row)) }
  function move(index: number, delta: number) { const next = [...value]; [next[index], next[index + delta]] = [next[index + delta], next[index]]; onChange(next) }
  function fields({ row, index }: LinkRow) {
    const path = `settings.${group}.${index}`
    const field = (key: string, input: ReactNode) => <Form.Item id={`website-field-${path}.${key}`} validateStatus={errors[`${path}.${key}`] ? 'error' : undefined} help={errors[`${path}.${key}`]} style={{ margin: 0 }}>{input}</Form.Item>
    return {
      name: field('label', <Input aria-label={`${label}${index + 1}名称`} placeholder="请输入导航名称" value={String(row.label ?? '')} disabled={disabled} onChange={label => update(index, { ...row, label })} />),
      type: field('target', <Select aria-label={`${label}${index + 1}类型`} value={String(row.target)} disabled={disabled} options={[{ label: '核心页面', value: 'core' }, { label: '主题页面', value: 'theme_page', disabled: !custom.length }, { label: '外部链接', value: 'external' }]}
        onChange={target => update(index, { label: row.label, target, ...(target === 'core' ? { core_page: core[0]?.id ?? '' } : target === 'theme_page' ? { theme_id: themeId, page_id: custom[0]?.id ?? '' } : { url: '' }) })} />),
      target: <>{errors[path] && <div className="website-field-error">{errors[path]}</div>}{row.target === 'external' ? field('url', <Input aria-label={`${label}${index + 1}地址`} placeholder="https://example.com" value={String(row.url ?? '')} disabled={disabled} onChange={url => update(index, { ...row, url })} />)
        : field(row.target === 'core' ? 'core_page' : 'page_id', <Select aria-label={`${label}${index + 1}页面`} placeholder="请选择页面" value={String(row.target === 'core' ? row.core_page ?? '' : row.page_id ?? '')} disabled={disabled}
          options={(row.target === 'core' ? core : custom).map(page => ({ label: `${page.title} · ${page.path}`, value: page.id }))}
          onChange={id => update(index, row.target === 'core' ? { label: row.label, target: 'core', core_page: id } : { label: row.label, target: 'theme_page', theme_id: themeId, page_id: id })} />)}</>,
    }
  }
  const rows: LinkRow[] = value.map((row, index) => ({ row: row as WebsiteObject, index }))
  const columns: TableColumnProps<LinkRow>[] = [
    { title: '序号', width: 64, render: (_, item) => item.index + 1 },
    { title: '链接名称', width: 180, render: (_, item) => fields(item).name },
    { title: '链接类型', width: 160, render: (_, item) => fields(item).type },
    { title: '目标页面 / 地址', width: 320, render: (_, item) => fields(item).target },
    { title: '操作', width: 160, render: (_, { index }) => <div style={{ whiteSpace: 'nowrap' }}><TableActions actions={disabled ? [] : [
      ...(index > 0 ? [{ key: 'up', label: '上移', onClick: () => move(index, -1) }] : []),
      ...(index < value.length - 1 ? [{ key: 'down', label: '下移', onClick: () => move(index, 1) }] : []),
      { key: 'delete', label: '删除', danger: true, onClick: () => onChange(value.filter((_, i) => i !== index)) },
    ]} /></div> },
  ]
  return <div className="website-schema-list">
    <div className="website-list-heading"><SectionTitle>{label}</SectionTitle><Button icon={<IconPlus />} disabled={disabled || value.length >= 12}
      onClick={() => onChange([...value, { label: '', target: 'core', core_page: core[0]?.id ?? '' }])}>添加链接</Button></div>
    {errors[`settings.${group}`] && <div className="website-field-error" id={`website-field-settings.${group}`}>{errors[`settings.${group}`]}</div>}
    <Table<LinkRow> rowKey="index" aria-label={`${label}配置`} data={rows} columns={columns} pagination={false} scroll={{ x: 884 }} noDataElement={<Empty description={`暂无${label}`} />} />
  </div>
}
