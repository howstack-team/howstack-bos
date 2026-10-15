import { Button, Form, Input, Modal } from '@arco-design/web-react'
import { IconImage } from '@arco-design/web-react/icon'
import { useState } from 'react'
import FilePicker from './FilePicker'
import { useAdminAuth } from '../auth/AuthContext'
import type { BytemdEditorContext } from 'bytemd'

interface Props {
  context: BytemdEditorContext
  onClose: () => void
}
interface Values { url: string; alt?: string }

/** 在当前光标或选区插入远程图片 Markdown；不上传文件，也不保存正文。 */
export default function MarkdownImageDialog({ context, onClose }: Props) {
  const [picker, setPicker] = useState(false)
  const { permissions } = useAdminAuth()
  const [form] = Form.useForm<Values>()
  const from = context.editor.getCursor('from'), to = context.editor.getCursor('to')
  async function insert() {
    let values: Values
    try { values = await form.validate() } catch { return }
    const url = new URL(values.url.trim()).href
    const alt = (values.alt ?? '').trim().replace(/[\r\n]+/g, ' ').replace(/[\\[\]]/g, '\\$&')
    context.editor.replaceRange(`![${alt}](<${url}>)`, from, to)
    onClose()
    context.editor.focus()
  }
  return <><FilePicker visible={picker} onClose={() => setPicker(false)} onSelect={file => { if (file.url) { context.editor.replaceRange(`![图片](<${file.url}>)`, from, to); onClose(); context.editor.focus() } }} /><Modal visible={!picker} maskClosable title="插入图片" okText="插入" onCancel={onClose} onOk={() => void insert()}
    style={{ width: 480, maxWidth: 'calc(100vw - 24px)' }} wrapStyle={{ zIndex: 1200 }} maskStyle={{ zIndex: 1199 }}>
    {permissions.includes('admin.files.view') && <Button icon={<IconImage />} onClick={() => setPicker(true)} style={{ marginBottom: 16 }}>从文件库选择</Button>}
    <Form form={form} layout="vertical" initialValues={{ alt: context.editor.getSelection().replace(/[\r\n]+/g, ' ').slice(0, 200) }}>
      <Form.Item field="url" label="图片地址" extra="填写可访问的 HTTP 或 HTTPS 图片地址。" rules={[
        { required: true, message: '请输入图片地址' },
        { maxLength: 2000, message: '图片地址不能超过 2000 个字符' },
        { validator: (value: unknown, callback) => {
          try {
            const url = new URL(typeof value === 'string' ? value.trim() : '')
            if (!['http:', 'https:'].includes(url.protocol)) throw new Error('protocol')
          } catch { callback('请输入有效的 HTTP 或 HTTPS 图片地址'); return }
          callback()
        } },
      ]}>
        <Input placeholder="https://example.com/image.png" allowClear />
      </Form.Item>
      <Form.Item field="alt" label="图片说明" rules={[{ maxLength: 200, message: '图片说明不能超过 200 个字符' }]}>
        <Input placeholder="请输入图片说明（选填）" maxLength={200} allowClear />
      </Form.Item>
    </Form>
  </Modal></>
}
