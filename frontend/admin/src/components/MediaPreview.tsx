import { lazy, Suspense } from 'react'
import { Button, Modal, Space, Spin } from '@arco-design/web-react'
import { IconDownload } from '@arco-design/web-react/icon'
import { fileName, type FileAsset } from '../api/filesApi'
import './MediaPreview.css'

const MediaPreviewBody = lazy(() => import('./MediaPreviewBody'))

/** 按需加载西瓜播放器；关闭弹窗即卸载并停止播放。 */
export default function MediaPreview({ file, onClose }: { file: FileAsset | null; onClose: () => void }) {
  return <Modal visible={file !== null} unmountOnExit title={file ? fileName(file) : '媒体预览'} onCancel={onClose}
    style={{ width: file?.type === 'audio' ? 620 : 960, maxWidth: 'calc(100vw - 32px)' }}
    footer={<Space><Button onClick={onClose}>关闭</Button>{file?.url && <Button type="primary" icon={<IconDownload />} href={file.url} download={fileName(file)}>下载文件</Button>}</Space>}>
    {file && <Suspense fallback={<div className="media-preview-loading"><Spin tip="正在加载播放器" /></div>}>
      <MediaPreviewBody key={file.id} file={file} />
    </Suspense>}
  </Modal>
}
