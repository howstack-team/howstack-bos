import { useHistory, useLocation } from 'react-router-dom'
import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Empty, Image, Link, Message, Modal, Space, Table, Pagination, Spin, Tooltip, Typography } from '@arco-design/web-react'
import { IconDelete, IconRefresh, IconUpload, IconApps, IconList } from '@arco-design/web-react/icon'
import { filesApi, fileName, fileTypes, type FileAsset, type FileList, type FileStatistics } from '../api/filesApi'
import { useAdminAuth } from '../auth/AuthContext'
import TableActions, { type TableAction } from '../components/TableActions'
import { FileLibrarySidebar, FileLibraryGrid, FileThumbnail } from '../components/FileLibraryViews'
import DocumentPreview from '../components/DocumentPreview'
import MediaPreview from '../components/MediaPreview'
import ResponsiveTablePagination from '../components/ResponsiveTablePagination'
import { supportsDocumentPreview, supportsMediaPreview } from '../utils/filePreview'
import './FileManagementPage.css'
import TableQueryForm from '../components/TableQueryForm'
import useTableQuery from '../hooks/useTableQuery'
import './WebsitePage.css'
const size = (bytes: number) => bytes < 1024 ? `${bytes} B` : bytes < 1048576 ? `${(bytes / 1024).toFixed(1)} KiB` : `${(bytes / 1048576).toFixed(1)} MiB`
export default function FileManagementPage() {
  const history = useHistory(), location = useLocation()
  const view = new URLSearchParams(location.search).get('view') === 'list' ? 'list' : 'grid'
  const [stats, setStats] = useState<FileStatistics | null>(null), [statsError, setStatsError] = useState('')
  const { permissions } = useAdminAuth()
  const query = useTableQuery({ textFields: ['name'], typeValues: Object.keys(fileTypes), sortFields: ['created_at', 'byte_size'] })
  const [list, setList] = useState<FileList | null>(null), [loading, setLoading] = useState(false), [error, setError] = useState('')
  const [selected, setSelected] = useState<string[]>([]), [attempt, setAttempt] = useState(0), [uploading, setUploading] = useState(false), [preview, setPreview] = useState<FileAsset | null>(null), [usage, setUsage] = useState<FileAsset | null>(null)
  const input = useRef<HTMLInputElement>(null), uploadLock = useRef(false), deleteLock = useRef(false)
  const canView = permissions.includes('admin.files.view'), canUpload = permissions.includes('admin.files.upload'), canDelete = permissions.includes('admin.files.delete')
  const params = JSON.stringify({ ...query.queryParams, page: query.page, page_size: query.pageSize })
  function reload() { setAttempt(value => value + 1) }
  useEffect(() => {

    if (!canView) return
    const controller = new AbortController(); queueMicrotask(() => { if (!controller.signal.aborted) { setLoading(true); setError(''); setSelected([]) } })
    void filesApi.list(JSON.parse(params), controller.signal).then(value => { if (!controller.signal.aborted) setList(value) })
      .catch(reason => { if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : '加载失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [canView, params, query.refreshKey, attempt])
  useEffect(() => {
    if (!canView) return
    const controller = new AbortController()
    void filesApi.statistics(controller.signal).then(value => { if (!controller.signal.aborted) { setStats(value); setStatsError('') } }).catch(() => { if (!controller.signal.aborted) { setStats(null); setStatsError('统计加载失败') } })
    return () => controller.abort()
  }, [canView, attempt])
  function updateUrl(key: string, value?: string) {
    const next = new URLSearchParams(history.location.search)
    if (value) next.set(key, value); else next.delete(key)
    if (key === 'type') next.delete('page')
    history.push({ ...history.location, search: next.toString() })
    if (key === 'type') setSelected([])
  }
  async function upload(file?: File) {
    if (!file || uploadLock.current) return
    if (!file.size || file.size > 20 * 1048576) { Message.error('请选择20MiB以内的文件，图片上限5MiB'); return }
    uploadLock.current = true; setUploading(true)
    try { await filesApi.upload(file); Message.success('文件已上传'); reload() }
    catch (reason) { setError(reason instanceof Error ? reason.message : '上传失败') }
    finally { uploadLock.current = false; setUploading(false); if (input.current) input.current.value = '' }
  }
  function remove(ids: string[]) {
    Modal.confirm({ title: '删除文件', content: `将永久删除 ${ids.length} 个文件；已引用文件无法删除。确认继续？`, okButtonProps: { status: 'danger' }, onOk: async () => {
      if (deleteLock.current) return
      deleteLock.current = true
      try { const result = await filesApi.delete(ids); if (result.failed.length) Message.warning(`${result.deleted.length} 个已删除，${result.failed.length} 个删除未确认，可重试`); else Message.success('文件已删除') }
      catch (reason) { Message.error(reason instanceof Error ? reason.message : '删除失败') }
      finally { deleteLock.current = false; reload() }
    } })
  }
  function actions(file: FileAsset): TableAction[] { return [
          ...(file.url ? [{ key: 'preview', label: '预览', onClick: () => { if (file.type === 'image' || supportsDocumentPreview(file) || supportsMediaPreview(file)) setPreview(file); else window.open(file.url!, '_blank', 'noopener,noreferrer') } }, { key: 'download', label: '下载', onClick: () => { const link = document.createElement('a'); link.href = file.url!; link.download = fileName(file); link.rel = 'noopener'; link.click() } }, { key: 'link', label: '复制链接', onClick: () => { void navigator.clipboard.writeText(new URL(file.url!, window.location.origin).href).then(() => Message.success('链接已复制')).catch(() => Message.error('复制失败，请在预览中复制地址')) } }] : []),
          ...(canDelete && file.deletable ? [{ key: 'delete', label: '删除', danger: true, onClick: () => remove([file.id]) }] : []),
        ] }
  if (!canView) return <Empty description="暂无文件查看权限" />
  return <section className="file-library" aria-label="文件管理"><FileLibrarySidebar type={query.type} onType={type => updateUrl('type', type)} stats={stats} error={statsError} />
    <section className="file-library-card file-library-panel"><header className="file-library-heading"><Typography.Title heading={5} style={{ margin: 0 }}>{query.type ? fileTypes[query.type as keyof typeof fileTypes] : '全部文件'}</Typography.Title><Typography.Text type="secondary">{list ? `${list.total} 个文件` : '文件库'}</Typography.Text></header><div className="file-library-content">
    <TableQueryForm fields={[{ key: 'name', label: '文件名称' }]} values={query.draftFields} onFieldChange={query.setField} onSearch={query.search} onReset={query.reset} loading={loading} />
    <div className="file-library-toolbar"><Space className="file-library-batch">{canDelete && <Button className={`file-library-delete${selected.length ? '' : ' is-empty'}`} status="danger" icon={<IconDelete />} disabled={!selected.length || loading} onClick={() => remove(selected)}>批量删除{selected.length ? `（${selected.length}）` : ''}</Button>}</Space>
      <Space className="file-library-global">{canUpload && <Button aria-label="上传文件" type="primary" icon={<IconUpload />} loading={uploading} onClick={() => input.current?.click()}><span className="file-library-desktop-label">上传文件</span><span className="file-library-mobile-label">上传</span></Button>}<Tooltip content="刷新文件"><Button aria-label="刷新" icon={<IconRefresh />} onClick={reload} disabled={loading} /></Tooltip><Button.Group><Button aria-label="网格视图" type={view === 'grid' ? 'primary' : 'default'} icon={<IconApps />} onClick={() => updateUrl('view')} /><Button aria-label="列表视图" type={view === 'list' ? 'primary' : 'default'} icon={<IconList />} onClick={() => updateUrl('view', 'list')} /></Button.Group></Space></div>
    {error && <Alert type="error" content={error} action={permissions.includes('admin.channels.storage.view') ? <Link onClick={() => history.push('/channels/storage')}>配置存储通道</Link> : undefined} />}
    <div className="file-library-results" aria-busy={loading}>{view === 'grid' ? loading ? <div className="file-grid-empty"><Spin /></div> : list?.data.length ? <FileLibraryGrid files={list.data} selected={selected} selectable={canDelete} onSelect={(id, checked) => setSelected(current => checked ? [...current, id] : current.filter(value => value !== id))} actions={actions} onUsage={setUsage} /> : <div className="file-grid-empty"><Empty description={error ? '文件加载失败' : '暂无文件'} /></div> : <Table<FileAsset> rowKey="id" data={list?.data ?? []} loading={loading} scroll={{ x: 1195 }} style={{ minWidth: 0, width: '100%' }} rowSelection={canDelete ? { selectedRowKeys: selected, onChange: keys => setSelected(keys.map(String)), checkboxProps: file => ({ disabled: !file.deletable }) } : undefined}
      columns={[
        { title: '文件名称', width: 260, ellipsis: true, render: (_, file) => <Space><FileThumbnail file={file} size={32} /><span title={file.original_name ?? '历史上传未记录原始文件名'}>{fileName(file)}</span></Space> },
        { title: '类型', width: 95, ...query.filterColumn('type', fileTypes), render: (_, file) => fileTypes[file.type as keyof typeof fileTypes] },
        { title: '格式', dataIndex: 'extension', width: 85 },
        { title: '大小', width: 100, sorter: true, dataIndex: 'byte_size', sortOrder: query.sortColumn('byte_size').sortOrder, render: (_, file) => size(file.byte_size) },
        { title: '上传通道', width: 140, ellipsis: true, render: (_, file) => <Tooltip content={`通道 ID：${file.channel_id}，上传配置修订：${file.channel_revision}`}><span>{file.channel_name ?? `通道 #${file.channel_id}`}</span></Tooltip> },
        { title: '上传人', dataIndex: 'created_by_name', width: 120, ellipsis: true, render: (_, file) => <Tooltip content={file.created_by_name ? `管理员 ID：${file.created_by}` : `上传时管理员 ID：${file.created_by}，账户信息不可用`}><span>{file.created_by_name ?? (file.created_by ? `管理员 #${file.created_by}` : '未知上传人')}</span></Tooltip> },
        { title: '上传时间', width: 175, dataIndex: 'created_at', sorter: true, sortOrder: query.sortColumn('created_at').sortOrder },
        { title: '操作', width: 220, fixed: 'right', render: (_, file) => <TableActions actions={actions(file)} /> },
      ]} onChange={query.onTableChange} pagination={false} />}</div>
    <footer className="file-library-footer"><ResponsiveTablePagination paginationNode={<Pagination current={query.page} pageSize={query.pageSize} total={list?.total ?? 0} showTotal sizeCanChange sizeOptions={[10, 20, 50, 100]} onChange={(page, pageSize) => { if (pageSize !== query.pageSize) query.setPageSize(pageSize); else query.setPage(page) }} />} /></footer>
    </div></section>
    <Image.Preview visible={preview?.type === 'image'} src={preview?.type === 'image' ? preview.url ?? '' : ''} onVisibleChange={visible => { if (!visible && preview?.type === 'image') setPreview(null) }} imgAttributes={{ alt: preview ? fileName(preview) : '' }} />
    <DocumentPreview file={preview && supportsDocumentPreview(preview) ? preview : null} onClose={() => setPreview(null)} />
    <MediaPreview file={preview && supportsMediaPreview(preview) ? preview : null} onClose={() => setPreview(null)} />
    <Modal visible={usage !== null} title="文件使用位置" onCancel={() => setUsage(null)} footer={<Button onClick={() => setUsage(null)}>关闭</Button>} style={{ width: 520, maxWidth: 'calc(100vw - 32px)' }}>
      {usage?.references.map(ref => <Typography.Paragraph key={`${ref.scope}:${ref.owner}`}>{ref.label}</Typography.Paragraph>)}
    </Modal>
    <input hidden type="file" ref={input} aria-label="上传文件" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.docx,.xls,.xlsx,.txt,.md,.csv,.zip,.mp4,.mp3,.sql,.db,.sqlite,.sqlite3" onChange={event => void upload(event.target.files?.[0])} />
  </section>
}
