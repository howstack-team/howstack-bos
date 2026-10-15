import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Input, Message, Spin, Typography } from '@arco-design/web-react'
import { IconCopy } from '@arco-design/web-react/icon'
import type { FileAsset } from '../api/filesApi'
import './DocumentPreview.css'
import spreadsheetStylesheet from './DocumentSpreadsheet.css?url'

interface Previewer { preview: (source: ArrayBuffer) => unknown; destroy: () => void }

/** Office 内容在禁止脚本的独立文档中渲染，样式与链接不影响后台页面。 */
export default function DocumentPreviewBody({ file }: { file: FileAsset }) {
  const frame = useRef<HTMLIFrameElement>(null)
  const [ready, setReady] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [selection, setSelection] = useState<{ address: string; value: string } | null>(null)
  const spreadsheet = ['xls', 'xlsx'].includes(file.extension.toLowerCase())

  useEffect(() => {
    if (!ready || !frame.current?.contentDocument || !file.url) return
    const document = frame.current.contentDocument
    const controller = new AbortController()
    const host = document.createElement('div')
    host.style.cssText = 'height:100vh;width:100%;overflow:hidden'
    document.body.replaceChildren(host)
    let previewer: Previewer | undefined
    let settled: Promise<unknown> = Promise.resolve()

    async function render() {
      const response = await fetch(file.url!, { signal: controller.signal, credentials: 'same-origin' })
      if (!response.ok) throw new Error('文件读取失败，请重新打开预览')
      if (Number(response.headers.get('Content-Length')) > 20 * 1048576) throw new Error('文件超出预览大小限制')
      const bytes = await response.arrayBuffer()
      if (!bytes.byteLength || bytes.byteLength > 20 * 1048576) throw new Error('文件内容为空或超出预览大小限制')
      const extension = file.extension.toLowerCase()
      if (extension === 'pdf') {
        const { default: pdf } = await import('@js-preview/pdf')
        if (controller.signal.aborted) return
        const staticFileUrl = new URL(`${import.meta.env.BASE_URL}document-preview/pdf/`, window.location.origin).href
        // PDF 库的 preview 实际通过回调完成，不能仅 await 它的返回值。
        settled = new Promise<void>((resolve, reject) => {
          const options = { staticFileUrl, standardFontDataUrl: `${staticFileUrl}standard_fonts/`,
            isEvalSupported: false, enableXfa: false, onRendered: resolve, onError: reject }
          previewer = pdf.init(host, options)
          previewer.preview(bytes)
        })
      } else if (extension === 'docx') {
        const [{ default: docx }, { default: stylesheet }] = await Promise.all([
          import('@js-preview/docx'), import('@js-preview/docx/lib/index.css?url'),
        ])
        if (controller.signal.aborted) return
        await addStylesheet(document, stylesheet, controller.signal)
        if (controller.signal.aborted) return
        const options = { ignoreWidth: true, useBase64URL: true, renderAltChunks: false }
        previewer = docx.init(host, options)
        settled = Promise.resolve(previewer.preview(bytes))
      } else {
        const [{ default: excel }, { default: stylesheet }] = await Promise.all([
          import('@js-preview/excel'), import('@js-preview/excel/lib/index.css?url'),
        ])
        if (controller.signal.aborted) return
        // 库在初始化时测量容器并创建点击层；样式未就绪会留下过小的点击层。
        await addStylesheet(document, stylesheet, controller.signal)
        if (controller.signal.aborted) return
        await addStylesheet(document, spreadsheetStylesheet, controller.signal)
        if (controller.signal.aborted) return
        const options = { xls: extension === 'xls', minColLength: 0, minRowLength: 0, showContextmenu: false,
          cellSelected: ({ cell, rowIndex, columnIndex }: { cell: unknown; rowIndex: number; columnIndex: number }) => {
            if (controller.signal.aborted || rowIndex < 0 || columnIndex < 0) return
            const value = cell && typeof cell === 'object' && 'text' in cell ? cell.text : ''
            setSelection({ address: `${columnName(columnIndex)}${rowIndex + 1}`, value: typeof value === 'string' || typeof value === 'number' ? String(value) : '' })
          } }
        previewer = excel.init(host, options)
        settled = Promise.resolve(previewer.preview(bytes))
      }
      await settled
      if (!controller.signal.aborted) setLoading(false)
    }
    void render().catch(() => {
      if (!controller.signal.aborted) { setLoading(false); setError('预览失败，文件可能损坏、加密或暂时无法读取，请下载后查看。') }
    })
    return () => {
      controller.abort()
      // 等待渲染结束后释放，避免关闭时库继续访问已销毁的容器。
      void settled.catch(() => undefined).then(() => previewer?.destroy())
    }
  }, [ready, file])

  return <div className="document-preview-body">
    {spreadsheet && <div className="document-preview-cell">
      <Typography.Text>{selection?.address ?? '单元格'}</Typography.Text>
      <Input aria-label="选中单元格内容" readOnly value={selection?.value ?? ''} placeholder="点击单元格查看内容" />
      <Button aria-label="复制单元格内容" icon={<IconCopy />} disabled={!selection?.value} onClick={() => {
        void navigator.clipboard.writeText(selection?.value ?? '').then(() => Message.success('内容已复制')).catch(() => Message.error('复制失败，请选择上方文本手动复制'))
      }} />
    </div>}
    {loading && <div className="document-preview-loading"><Spin tip="正在加载预览" /></div>}
    {error && <div className="document-preview-error"><Alert type="warning" content={error} /></div>}
    {/* Canvas 在禁止脚本的 sandbox 中会退化为后备内容；由 CSP 拒绝文档脚本执行。 */}
    <iframe ref={frame} title="文档预览" sandbox="allow-same-origin allow-scripts" className="document-preview-frame"
      onLoad={() => setReady(true)} style={{ visibility: error ? 'hidden' : 'visible' }}
      srcDoc={'<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'none\'; style-src \'self\' \'unsafe-inline\'; img-src data: blob:; font-src data: blob:;"><style>html,body{margin:0;width:100%;height:100%;background:#fff;font-family:Arial,sans-serif}</style></head><body></body></html>'} />
  </div>
}

function addStylesheet(document: Document, source: string, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal.aborted) { reject(new DOMException('预览已关闭', 'AbortError')); return }
    const link = document.createElement('link')
    const finish = (error?: Error) => {
      signal.removeEventListener('abort', abort)
      link.onload = null
      link.onerror = null
      if (error) { link.remove(); reject(error) } else resolve()
    }
    const abort = () => finish(new DOMException('预览已关闭', 'AbortError'))
    link.rel = 'stylesheet'
    link.href = source
    link.onload = () => finish()
    link.onerror = () => finish(new Error('预览样式加载失败'))
    signal.addEventListener('abort', abort, { once: true })
    document.head.appendChild(link)
  })
}

function columnName(index: number): string {
  let name = ''
  for (let value = index + 1; value > 0; value = Math.floor((value - 1) / 26)) name = String.fromCharCode(65 + (value - 1) % 26) + name
  return name
}
