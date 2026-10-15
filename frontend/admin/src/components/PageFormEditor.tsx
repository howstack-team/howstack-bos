import type { ReactNode } from 'react'
import { Button, Space, Typography } from '@arco-design/web-react'
import { IconArrowLeft } from '@arco-design/web-react/icon'
import './PageFormEditor.css'

interface PageFormEditorProps {
  title: string
  children: ReactNode
  onCancel: () => void
  onSave?: () => void
  /** 通过 HTML 表单提交关联复杂编辑器，保留其自身校验和草稿状态。 */
  formId?: string
  saving: boolean
  disabled?: boolean
  saveText?: string
}

/** 复杂表单使用完整页面空间与主内容滚动，返回时由调用方保留列表状态。 */
export default function PageFormEditor({ title, children, onCancel, onSave, saving,
  formId, disabled = false, saveText = '保存' }: PageFormEditorProps) {
  return <div className="page-form-editor">
    <div className="page-form-editor-heading">
      <Button type="text" icon={<IconArrowLeft />} onClick={onCancel}>返回列表</Button>
      <Typography.Title heading={6} style={{ margin: 0 }}>{title}</Typography.Title>
    </div>
    {children}
    {(formId || onSave) && <div className="page-form-editor-actions"><Space>
      <Button onClick={onCancel}>取消</Button>
      <Button type="primary" loading={saving} disabled={disabled} form={formId}
        htmlType={formId ? 'submit' : 'button'} onClick={formId ? undefined : onSave}>{saveText}</Button>
    </Space></div>}
  </div>
}
