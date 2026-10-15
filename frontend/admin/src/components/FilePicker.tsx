import { DEFAULT_TABLE_PAGE_SIZE } from '../utils/tablePagination'
import { useEffect, useState } from 'react'
import { Alert, Button, Empty, Link, Modal, Table } from '@arco-design/web-react'
import TableQueryForm from './TableQueryForm'
import { filesApi, fileName, type FileAsset, type FileList } from '../api/filesApi'
interface Props { visible: boolean; onClose: () => void; onSelect: (file: FileAsset) => void }
/** 只选择完成上传的公开图片；业务保存时服务端再次校验文件可用性。 */
export default function FilePicker({ visible, onClose, onSelect }: Props) {
  const [list, setList] = useState<FileList | null>(null), [loading, setLoading] = useState(false), [error, setError] = useState('')
  const [name, setName] = useState(''), [query, setQuery] = useState(''), [page, setPage] = useState(1), [attempt, setAttempt] = useState(0)
  useEffect(() => {
    if (!visible) return
    const controller = new AbortController(); queueMicrotask(() => { if (!controller.signal.aborted) { setLoading(true); setError('') } })
    void filesApi.list({ name: query, type: 'image', status: 'stored', page, page_size: DEFAULT_TABLE_PAGE_SIZE }, controller.signal).then(value => { if (!controller.signal.aborted) setList(value) })
      .catch(reason => { if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : '加载失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [visible, query, page, attempt])
  function search() { setPage(1); setQuery(name.trim()); setAttempt(value => value + 1) }
  return <Modal title="从文件库选择图片" visible={visible} onCancel={onClose} footer={<Button onClick={onClose}>关闭</Button>} style={{ width: 760, maxWidth: 'calc(100vw - 32px)' }}>
    <TableQueryForm fields={[{ key: 'name', label: '文件名称' }]} values={{ name }} onFieldChange={(_, value) => setName(value)} onSearch={search} onReset={() => { setName(''); setQuery(''); setPage(1); setAttempt(value => value + 1) }} loading={loading} />
    {error ? <Alert type="error" content={error} /> : <Table<FileAsset> rowKey="id" loading={loading} data={list?.data ?? []} scroll={{ x: 580 }} noDataElement={<Empty description="暂无可用图片" />} columns={[
      { title: '预览', width: 80, render: (_, file) => <img src={file.url ?? ''} alt="" style={{ width: 48, height: 36, objectFit: 'contain' }} /> },
      { title: '文件名称', width: 280, ellipsis: true, render: (_, file) => fileName(file) },
      { title: '上传时间', dataIndex: 'created_at', width: 160 },
      { title: '操作', width: 70, render: (_, file) => <Link onClick={() => { onSelect(file); onClose() }}>选择</Link> },
    ]} pagination={{ current: page, pageSize: DEFAULT_TABLE_PAGE_SIZE, total: list?.total ?? 0, onChange: setPage, simple: true }} />}
  </Modal>
}
