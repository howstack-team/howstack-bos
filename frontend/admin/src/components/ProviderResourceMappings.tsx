import { IconDownload } from '@arco-design/web-react/icon'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Form, Alert, Button, Select, Table, Typography } from '@arco-design/web-react'
import { cloudProductApi, cloudError, RESOURCE_LABELS, type CatalogEntry, type MappingConnection, type MappingResource, type ResourceKind, type ResourceMapping } from '../api/cloudProductApi'
import { useAdminAuth } from '../auth/AuthContext'
import TableActions from './TableActions'
import { tableCellText } from '../utils/tableCellText'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'

/** 供应商页面和交付规则共用资源对应编辑器；不同可用区可以分别对应外部资源。 */
export default function ProviderResourceMappings({ connection }: { connection: MappingConnection }) {
  const { permissions } = useAdminAuth()
  const can = (action: string) => permissions.includes(`admin.provider-connections.${action}`)
  const [mappings, setMappings] = useState<ResourceMapping[]>([])
  const [resources, setResources] = useState<Partial<Record<ResourceKind, MappingResource[]>>>({})
  const [kind, setKind] = useState<ResourceKind>(connection.adapter_catalogs[0]?.resource_kind || 'zones')
  const [localId, setLocalId] = useState<number>()
  const [zoneId, setZoneId] = useState(0)
  const [entries, setEntries] = useState<CatalogEntry[]>([])
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [total, setTotal] = useState(0)
  const [reading, setReading] = useState(false)
  const [mappingBusy, setMappingBusy] = useState(false)
  const [error, setError] = useState('')
  const sequence = useRef(0)
  const invalidate = useCallback(() => { sequence.current++ }, [])
  useEffect(() => {
    let active = true
    Promise.all([cloudProductApi.mappings(connection.id), cloudProductApi.mappingReferences()]).then(([rows, references]) => {
      if (active) { setMappings(rows); setResources(references) }
    }).catch((error: unknown) => { if (active) setError(cloudError(error)) })
    return () => { active = false; invalidate() }
  }, [connection.id, invalidate])
  const local = resources[kind]?.find((row) => row.id === localId)
  const supportsScope = ['flavor_groups', 'flavors', 'images', 'disk_types', 'lines'].includes(kind)
  const zoneIds = kind === 'flavor_groups' ? (resources.flavors || []).filter((row) => row.group_id === localId).flatMap((row) => row.zone_ids || []) : local?.zone_ids || []
  const read = async (nextPage = 1, size = pageSize) => {
    const descriptor = connection.adapter_catalogs.find((row) => row.resource_kind === kind)
    if (!descriptor || mappingBusy) return
    const current = ++sequence.current
    setReading(true); setError('')
    try {
      const result = await cloudProductApi.catalog(connection.id, descriptor.kind, nextPage, size)
      if (current === sequence.current) { setEntries(result.data); setTotal(result.total); setPage(nextPage); setPageSize(size) }
    } catch (error) { if (current === sequence.current) setError(cloudError(error)) }
    finally { if (current === sequence.current) setReading(false) }
  }
  const map = async (entry: CatalogEntry) => {
    if (!localId || mappingBusy) return
    const current = sequence.current
    setMappingBusy(true); setError('')
    try {
      await cloudProductApi.map(connection.id, kind, localId, entry.external_id, supportsScope ? zoneId : 0)
      const rows = await cloudProductApi.mappings(connection.id)
      if (current === sequence.current) setMappings(rows)
    } catch (error) { if (current === sequence.current) setError(cloudError(error)) }
    finally { setMappingBusy(false) }
  }
  return <div>
    <Alert content="为本系统配置选择供应商对应资源。共享资源可以按可用区分别设置，指定可用区的对应关系优先于默认关系。CPU、内存、磁盘容量、带宽和 IP 数量会作为配置数值交付。" style={{ marginBottom: 16 }} />
    {connection.requires_retest && <Alert type="warning" content="供应商尚未测试或配置已变化，请先测试连接。" style={{ marginBottom: 16 }} />}
    <div className="page-toolbar"><Form layout="inline" className="table-query-form" aria-label="资源对应配置">
      <Form.Item className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select aria-label="对应资源类型" value={kind} disabled={mappingBusy} style={{ width: 150 }} options={connection.adapter_catalogs.map((row) => ({ value: row.resource_kind, label: RESOURCE_LABELS[row.resource_kind] }))} onChange={(value: ResourceKind) => { sequence.current++; setReading(false); setKind(value); setLocalId(undefined); setZoneId(0); setEntries([]); setTotal(0); setPage(1); setError('') }} /></Form.Item>
      <Form.Item className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select aria-label="本系统资源" value={localId} disabled={mappingBusy} onChange={(value) => { setLocalId(value); setZoneId(0) }} placeholder="选择本系统资源" showSearch style={{ width: 220 }} options={(resources[kind] || []).map((row) => ({ value: row.id, label: row.name }))} /></Form.Item>
      {supportsScope && <Form.Item className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select aria-label="对应可用区" value={zoneId} disabled={mappingBusy} onChange={setZoneId} style={{ width: 220 }} options={[{ value: 0, label: '默认对应（适用关联可用区）' }, ...(resources.zones || []).filter((row) => zoneIds.includes(row.id)).map((row) => ({ value: row.id, label: row.name }))]} /></Form.Item>}
      {can('sync') && <Form.Item className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Button icon={<IconDownload />} type="primary" disabled={connection.requires_retest || !connection.adapter_catalogs.length || mappingBusy} loading={reading} onClick={() => void read()}>获取供应商资源</Button></Form.Item>}
    </Form></div>
    {localId && <Typography.Paragraph>当前供应商资源编号：{tableCellText(mappings.find((row) => row.resource_kind === kind && row.local_id === localId && row.scope_zone_id === (supportsScope ? zoneId : 0))?.external_id)}</Typography.Paragraph>}
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <Table rowKey="external_id" data={entries} loading={reading || mappingBusy} scroll={{ x: 560 }} columns={[
      { title: '供应商资源编号', dataIndex: 'external_id', width: 150, ellipsis: true }, { title: '供应商资源名称', dataIndex: 'name', width: 220, ellipsis: true },
      { title: '操作', width: 190, render: (_, row) => <TableActions actions={can('map') && connection.adapter_accepting && localId ? [{ key: 'map', label: '设为对应资源', onClick: () => void map(row) }] : []} /> },
    ]} pagination={{ current: page, pageSize, total, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS, onChange: (nextPage) => void read(nextPage), onPageSizeChange: (size) => void read(1, size) }} />
    <Typography.Title heading={6}>已设置的资源对应关系</Typography.Title>
    <Table rowKey="id" data={mappings} scroll={{ x: 700 }} columns={[
      { title: '资源类型', width: 120, render: (_, row) => RESOURCE_LABELS[row.resource_kind] },
      { title: '本系统资源', width: 200, ellipsis: true, render: (_, row) => tableCellText(resources[row.resource_kind]?.find((resource) => resource.id === row.local_id)?.name) },
      { title: '适用可用区', width: 180, ellipsis: true, render: (_, row) => row.scope_zone_id ? tableCellText(resources.zones?.find((zone) => zone.id === row.scope_zone_id)?.name) : '默认对应' },
      { title: '供应商资源编号', dataIndex: 'external_id', width: 170, ellipsis: true },
    ]} pagination={{ defaultPageSize: DEFAULT_TABLE_PAGE_SIZE, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS }} />
  </div>
}
