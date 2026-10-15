import { lazy, Suspense } from 'react'
import { Button, Modal, Space, Spin } from '@arco-design/web-react'
import { IconDownload } from '@arco-design/web-react/icon'
import { fileName, type FileAsset } from '../api/filesApi'
import './DocumentPreview.css'

const DocumentPreviewBody = lazy(() => import('./DocumentPreviewBody'))

/** 按需加载 JS Preview，无需 Vue 运行时或第三方在线文档服务。 */
export default function DocumentPreview({ file, onClose }: { file: FileAsset | null; onClose: () => void }) {
  return <Modal visible={file !== null} unmountOnExit title={file ? fileName(file) : '文件预览'}
    style={{ width: 1120, maxWidth: 'calc(100vw - 32px)' }} onCancel={onClose}
    footer={<Space><Button onClick={onClose}>关闭</Button>{file?.url && <Button type="primary" icon={<IconDownload />} href={file.url} download={fileName(file)}>下载文件</Button>}</Space>}>
    {file && <Suspense fallback={<div className="document-preview-loading"><Spin tip="正在加载预览组件" /></div>}>
      <DocumentPreviewBody key={file.id} file={file} />
    </Suspense>}
  </Modal>
}
