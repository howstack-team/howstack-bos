import { DEFAULT_TABLE_PAGE_SIZE } from '../../utils/tablePagination'
import { useEffect, useState } from 'react'
import { Alert, Drawer, Message, Table } from '@arco-design/web-react'
import { cloudError, cloudProductApi, type OfferVersion } from '../../api/cloudProductApi'
import TableActions from '../TableActions'
import { useCloudWorkspace } from './workspace'

/** 发布记录作为辅助入口，查看不会重新发布或覆盖历史配置。 */
export default function ConfigurationHistory({ onClose, onPreview }: { onClose: () => void; onPreview?: (version: number) => void }) {
  const state = useCloudWorkspace()
  const [versions, setVersions] = useState<OfferVersion[]>([])
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    let active = true
    if (state.offer) cloudProductApi.versions(state.offer.id).then(rows => { if (active) setVersions(rows) }).catch(e => { if (active) Message.error(cloudError(e)) }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [state.offer])
  return <Drawer visible title="配置记录" width={720} footer={null} onCancel={onClose}>
    <Alert content="每次生效保留当时的配置、价格与提供者对应，后续编辑不会改变这些记录。" style={{ marginBottom: 16 }} />
    <Table rowKey="id" data={versions} loading={loading} scroll={{ x: 560 }} columns={[
      { title: '版本', width: 100, render: (_, row) => `V${row.version}` },
      { title: '记录时间', dataIndex: 'created_at', width: 200 },
      { title: '操作', width: 110, render: (_, row) => <TableActions actions={onPreview ? [{ key: 'preview', label: '预览', onClick: () => onPreview(row.id) }] : []} /> },
    ]} pagination={{ defaultPageSize: DEFAULT_TABLE_PAGE_SIZE }} />
  </Drawer>
}
