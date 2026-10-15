import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Button, Drawer, Modal, Space } from '@arco-design/web-react'
import './FormDialog.css'

interface FormDialogProps {
  title: ReactNode
  visible: boolean
  onCancel: () => void
  onOk: () => void
  confirmLoading?: boolean
  okDisabled?: boolean
  width?: number
  /** compact 可根据高度升级抽屉；drawer 固定抽屉；modal 用于抽屉内的编辑，禁止升级叠加抽屉。 */
  mode?: 'compact' | 'drawer' | 'modal'
  children: ReactNode
}

/** 按上下文选择原生 Modal 或 Drawer；抽屉内的 modal 模式始终保留为模态框。 */
export default function FormDialog({ title, visible, onCancel, onOk, confirmLoading = false,
  okDisabled = false, width = 560, mode = 'compact', children }: FormDialogProps) {
  const [body, setBody] = useState<HTMLDivElement | null>(null)
  const [tooTall, setTooTall] = useState(false)
  const [wasVisible, setWasVisible] = useState(visible)
  if (wasVisible !== visible) {
    setWasVisible(visible)
    if (!visible) setTooTall(false)
  }
  useEffect(() => {
    if (!visible) return
    if (mode !== 'compact' || tooTall || !body) return
    // 校验提示、多选标签等展开后仍放不下时，升级为抽屉；本次打开保持该选择。
    const element = body
    const check = () => { if (element.offsetHeight > window.innerHeight - 180) setTooTall(true) }
    const observer = new ResizeObserver(check)
    observer.observe(element)
    window.addEventListener('resize', check)
    check()
    return () => { observer.disconnect(); window.removeEventListener('resize', check) }
  }, [visible, mode, tooTall, body])
  const content = <div ref={setBody}>{children}</div>
  if (mode === 'drawer' || mode === 'compact' && tooTall) {
    return <Drawer maskClosable title={title} visible={visible} onCancel={onCancel}
      width={`min(${width}px, calc(100vw - 24px))`}
      footer={<div className="form-dialog-actions"><Space>
        <Button onClick={onCancel}>取消</Button>
        <Button type="primary" loading={confirmLoading} disabled={okDisabled} onClick={onOk}>确定</Button>
      </Space></div>}>
      {content}
    </Drawer>
  }
  return <Modal maskClosable title={title} visible={visible} onCancel={onCancel} onOk={onOk}
    confirmLoading={confirmLoading} okButtonProps={{ disabled: okDisabled }}
    style={{ width, maxWidth: 'calc(100vw - 24px)' }}>
    {content}
  </Modal>
}
