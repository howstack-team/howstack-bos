import { lazy, Suspense, useState } from 'react'
import { Button, Checkbox, Dropdown, Menu, Tooltip, Typography } from '@arco-design/web-react'
import { IconApps, IconMore } from '@arco-design/web-react/icon'
import FileTypeIcon from './FileTypeIcon'
import { fileName, fileTypes, type FileAsset, type FileStatistics } from '../api/filesApi'
import type { TableAction } from './TableActions'
import { useCompactFormViewport } from '../hooks/useCompactFormLayout'

const formatFileSize = (bytes: number) => bytes < 1024 ? `${bytes} B` : bytes < 1048576 ? `${(bytes / 1024).toFixed(1)} KiB` : bytes < 1073741824 ? `${(bytes / 1048576).toFixed(1)} MiB` : `${(bytes / 1073741824).toFixed(1)} GiB`
const categories = [{ key: '', name: '全部', icon: 'all' }, ...Object.entries(fileTypes).map(([key, name]) => ({ key, name, icon: key }))]
const colors: Record<string, string> = { image: 'rgb(var(--gold-6))', document: 'rgb(var(--primary-6))', video: 'rgb(var(--purple-6))', audio: 'rgb(var(--green-6))', other: 'var(--color-text-4)' }
const FileStorageChart = lazy(() => import('./FileStorageChart'))

/** 文件分类与统计采用服务端真实登记值，上传容量仅计算成功上传的公开素材。 */
export function FileLibrarySidebar({ type, onType, stats, error }: { type?: string; onType: (type: string) => void; stats: FileStatistics | null; error?: string }) {
  const desktop = useCompactFormViewport()
  return <aside className="file-library-sidebar">
    <section className="file-library-card file-library-categories"><h2><IconApps />文件类型</h2><nav aria-label="文件分类">
      {categories.map(category => <button type="button" key={category.key} aria-current={(type ?? '') === category.key ? 'page' : undefined} className={`file-category${(type ?? '') === category.key ? ' is-active' : ''}`} onClick={() => onType(category.key)}>
        <FileTypeIcon type={category.icon} size={30} /><span>{category.name}</span><small>{stats ? category.key ? stats.types.find(item => item.type === category.key)?.count ?? 0 : stats.count : ''}</small>
      </button>)}
    </nav></section>
    <section className="file-library-card file-library-statistics" aria-label="文件统计">{error ? <Typography.Text type="secondary">统计暂不可用，请刷新重试</Typography.Text> : <><div className="file-stat-values"><div><Tooltip content="仅统计上传成功的公开文件，不代表存储服务商实时计费容量。"><span>已上传容量</span></Tooltip><strong>{stats ? formatFileSize(stats.byte_size) : '统计中'}</strong></div><div><span>文件数量</span><strong>{stats?.count ?? '统计中'}</strong></div></div>
      <div className="file-storage-chart" role="group" aria-label="文件容量分布" data-empty={!stats?.byte_size}>
        {desktop && stats && stats.byte_size > 0 && <Suspense fallback={null}><FileStorageChart stats={stats} /></Suspense>}
        <div className="file-storage-chart-total"><small>文件总数</small><strong>{stats?.count ?? '…'}</strong></div>
      </div>
      <div className="file-stat-legend">{stats?.types.filter(item => item.count > 0).map(item => <div key={item.type}><span className="file-stat-dot" style={{ background: colors[item.type] }} /><span>{fileTypes[item.type as keyof typeof fileTypes]}</span><small>{formatFileSize(item.byte_size)}</small></div>)}</div>
      </>}
    </section>
  </aside>
}
/** 图片在网格和列表均展示缩略图，加载失败及非图片使用对应格式图标。 */
export function FileThumbnail({ file, size }: { file: FileAsset; size: number }) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  return file.type === 'image' && file.url && failedUrl !== file.url
    ? <img src={file.url} alt="" width={size} height={size} loading="lazy" style={{ objectFit: 'contain', flexShrink: 0, verticalAlign: 'middle' }} onError={() => setFailedUrl(file.url)} />
    : <FileTypeIcon extension={file.extension} type={file.type} size={size} />
}
function Thumbnail({ file, onPreview }: { file: FileAsset; onPreview: () => void }) {
  return <button className="file-tile-preview" type="button" aria-label={`预览 ${fileName(file)}`} disabled={!file.url} onClick={onPreview}>
    <FileThumbnail file={file} size={file.type === 'image' ? 72 : 48} />
  </button>
}
interface GridProps { files: FileAsset[]; selected: string[]; selectable: boolean; onSelect: (id: string, checked: boolean) => void; actions: (file: FileAsset) => TableAction[]; onUsage: (file: FileAsset) => void }
/** 网格素材展示复用列表操作和删除权限；图片失败时回退到文件类型图标。 */
export function FileLibraryGrid({ files, selected, selectable, onSelect, actions, onUsage }: GridProps) {
  return <div className="file-grid">{files.map(file => <article key={file.id} className={`file-tile${selected.includes(file.id) ? ' is-selected' : ''}`} aria-label={fileName(file)}>
    <div className="file-tile-controls">{selectable ? <Tooltip content={file.deletable ? '选择文件' : file.references.length ? '文件正在使用，不能删除' : '文件处理中，不能删除'}><Checkbox aria-label={`选择 ${fileName(file)}`} disabled={!file.deletable} checked={selected.includes(file.id)} onChange={checked => onSelect(file.id, checked)} /></Tooltip> : <span />}
      {actions(file).length > 0 && <Dropdown trigger="click" position="br" droplist={<Menu>{actions(file).map(action => <Menu.Item key={action.key} onClick={action.onClick}><span style={action.danger ? { color: 'rgb(var(--danger-6))' } : undefined}>{action.label}</span></Menu.Item>)}{file.references.length > 0 && <Menu.Item key="usage" onClick={() => onUsage(file)}>查看引用</Menu.Item>}</Menu>}><Button aria-label={`操作 ${fileName(file)}`} type="text" size="mini" icon={<IconMore />} /></Dropdown>}
    </div><Thumbnail file={file} onPreview={() => actions(file).find(action => action.key === 'preview')?.onClick()} />
    <Tooltip content={file.references.length ? `${fileName(file)}（使用中）` : file.original_name ?? '历史上传未记录原始文件名'}><div className="file-tile-name">{fileName(file)}</div></Tooltip>

  </article>)}</div>
}
