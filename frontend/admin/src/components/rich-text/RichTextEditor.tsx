import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import { EditorContent, useEditor, useEditorState } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import { TableKit } from '@tiptap/extension-table'
import TextAlign from '@tiptap/extension-text-align'
import Placeholder from '@tiptap/extension-placeholder'
import { Alert, Button, Dropdown, Input, Menu, Message, Modal, Space, Tooltip } from '@arco-design/web-react'
import { IconAlignCenter, IconAlignLeft, IconAlignRight, IconBold, IconCodeBlock, IconDown, IconExpand, IconImage, IconItalic, IconLink, IconMinus, IconOrderedList, IconQuote, IconRedo, IconShrink, IconStrikethrough, IconUnderline, IconUndo, IconUnorderedList, IconUpload } from '@arco-design/web-react/icon'
import { Link } from 'react-router-dom'
import { contentImagesApi } from '../../api/contentImagesApi'
import type { ContentKind } from '../../api/supportContentApi'
import { useAdminAuth } from '../../auth/AuthContext'
import { sanitizeContentHtml, validateContentUrl } from './sanitizeContentHtml'
import FilePicker from '../FilePicker'
import RichTextViewer from './RichTextViewer'
import './RichTextEditor.css'

interface Props {
  value: string
  onChange?: (value: string) => void
  kind: ContentKind
  disabled?: boolean
  onUploadingChange?: (uploading: boolean) => void
}

/** 受控 HTML 编辑器，工具和图片上传复用后台组件与宿主接口。 */
export default function RichTextEditor({ value = '', onChange, kind, disabled = false, onUploadingChange }: Props) {
  const [full, setFull] = useState(false)
  const [preview, setPreview] = useState(false)
  const [picker, setPicker] = useState(false)
  const [dialog, setDialog] = useState<'image' | 'link' | null>(null)
  const [url, setUrl] = useState('')
  const [alt, setAlt] = useState('')
  const [urlError, setUrlError] = useState('')
  const [uploading, setUploading] = useState(false)
  const [storage, setStorage] = useState<boolean | null>(null)
  const [storageError, setStorageError] = useState('')
  const input = useRef<HTMLInputElement>(null)
  const { permissions } = useAdminAuth()
  const editor = useEditor({
    extensions: [StarterKit.configure({ link: { openOnClick: false, autolink: false } }), Image.configure({ allowBase64: false }), TableKit.configure({ table: { resizable: false } }), TextAlign.configure({ types: ['heading', 'paragraph'] }), Placeholder.configure({ placeholder: '请输入公告正文，支持粘贴排版内容' })],
    content: sanitizeContentHtml(value),
    editable: !disabled,
    editorProps: { attributes: { class: 'rich-text-body', 'aria-label': '公告正文' } },
    onUpdate: ({ editor: current }) => onChange?.(current.isEmpty ? '' : current.getHTML()),
  })
  const state = useEditorState({ editor, selector: ({ editor: current }) => {
    if (!current) return null
    return { bold: current.isActive('bold'), italic: current.isActive('italic'), underline: current.isActive('underline'), strike: current.isActive('strike'),
      heading: current.getAttributes('heading').level as number | undefined, bullet: current.isActive('bulletList'), ordered: current.isActive('orderedList'),
      quote: current.isActive('blockquote'), code: current.isActive('codeBlock'), table: current.isActive('table'),
      left: current.isActive({ textAlign: 'left' }), center: current.isActive({ textAlign: 'center' }), right: current.isActive({ textAlign: 'right' }),
      undo: current.can().undo(), redo: current.can().redo() }
  } })
  useEffect(() => {
    if (editor && value !== editor.getHTML() && !(editor.isEmpty && value === '')) editor.commands.setContent(sanitizeContentHtml(value), { emitUpdate: false })
  }, [editor, value])
  // 同步只读状态不属于正文变更，不能触发表单的 onChange 校验。
  useEffect(() => { editor?.setEditable(!disabled, false) }, [editor, disabled])
  useEffect(() => {
    if (!full) return
    const escape = (event: KeyboardEvent) => { if (event.key === 'Escape' && !dialog) setFull(false) }
    window.addEventListener('keydown', escape)
    return () => window.removeEventListener('keydown', escape)
  }, [full, dialog])
  useEffect(() => {
    if (dialog !== 'image') return
    let active = true
    void contentImagesApi.options(kind).then(result => { if (active) { setStorage(result.available); setStorageError('') } })
      .catch((error: unknown) => { if (active) { setStorage(null); setStorageError(error instanceof Error ? error.message : '存储通道状态获取失败') } })
    return () => { active = false }
  }, [dialog, kind])

  function openDialog(type: 'image' | 'link') {
    setDialog(type); setUrl(type === 'link' ? String(editor?.getAttributes('link').href ?? '') : ''); setAlt(''); setUrlError('')
  }
  function insertUrl() {
    const valid = validateContentUrl(url, dialog === 'image')
    if (!valid) { setUrlError('请输入有效的 HTTP 或 HTTPS 地址'); return }
    if (dialog === 'image') editor?.chain().focus().setImage({ src: valid, alt }).run()
    else editor?.chain().focus().extendMarkRange('link').setLink({ href: valid }).run()
    setDialog(null)
  }
  async function upload(file: File | undefined) {
    if (!file || uploading) return
    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type) || file.size > 5242880) { Message.error('请选择不超过5MiB的 JPG、PNG、GIF 或 WebP 图片'); return }
    setUploading(true); onUploadingChange?.(true)
    try {
      const result = await contentImagesApi.upload(kind, file)
      if (!editor || editor.isDestroyed) return
      editor.chain().focus().setImage({ src: result.url, alt: result.alt }).run()
      setDialog(null); Message.success('图片已插入')
    } catch (error) { Message.error(error instanceof Error ? error.message : '图片上传失败') }
    finally { setUploading(false); onUploadingChange?.(false); if (input.current) input.current.value = '' }
  }
  function toolStyle(active = false, unavailable = false, editingOnly = true): CSSProperties {
    const inactive = unavailable || (editingOnly && (disabled || preview))
    return {
      color: inactive ? 'var(--color-text-4)' : active ? 'rgb(var(--primary-6))' : 'var(--color-text-2)',
      backgroundColor: active && !inactive ? 'var(--color-primary-light-1)' : undefined,
    }
  }
  function menuTriggerStyle(width: number, active = false): CSSProperties {
    return {
      ...toolStyle(active),
      width,
      fontSize: 14,
    }
  }
  function tool(label: string, icon: ReactNode, action: () => void, active = false, unavailable = false) {
    return <Tooltip content={label} key={label}><Button htmlType="button" type="text" status="default" style={toolStyle(active, unavailable)}
      size="small" icon={icon} aria-label={label} aria-pressed={active} disabled={disabled || preview || unavailable} onClick={action} /></Tooltip>
  }
  return <div className={`rich-text-editor${full ? ' rich-text-editor-full' : ''}`}>
    <div className="rich-text-toolbar" role="toolbar" aria-label="正文编辑工具">
      {/* 工具菜单不使用表单选择框，避免继承正文字段的校验背景。 */}
      <Dropdown trigger="click" triggerProps={{ autoAlignPopupWidth: true }} disabled={disabled || preview} droplist={
        <Menu style={{ width: '100%', padding: 4 }} selectedKeys={[String(state?.heading ?? 0)]} onClickMenuItem={key => {
          const level = Number(key)
          if (level === 1 || level === 2 || level === 3) editor?.chain().focus().setHeading({ level }).run()
          else editor?.chain().focus().setParagraph().run()
        }}>
          {['正文', '标题一', '标题二', '标题三'].map((label, level) => <Menu.Item key={String(level)}
            style={{ ...toolStyle(level === (state?.heading ?? 0)), height: 32, lineHeight: '32px', padding: '0 12px', fontSize: 14 }}>
            {label}
          </Menu.Item>)}
        </Menu>
      }>
        <Button aria-label="段落样式" aria-haspopup="menu" htmlType="button" size="small" type="text"
          className="rich-text-menu-trigger" disabled={disabled || preview}
          style={menuTriggerStyle(96, Boolean(state?.heading))}>
          {['正文', '标题一', '标题二', '标题三'][state?.heading ?? 0]}<IconDown style={{ fontSize: 12 }} />
        </Button>
      </Dropdown>
      {tool('加粗', <IconBold />, () => { editor?.chain().focus().toggleBold().run() }, state?.bold)}
      {tool('斜体', <IconItalic />, () => { editor?.chain().focus().toggleItalic().run() }, state?.italic)}
      {tool('下划线', <IconUnderline />, () => { editor?.chain().focus().toggleUnderline().run() }, state?.underline)}
      {tool('删除线', <IconStrikethrough />, () => { editor?.chain().focus().toggleStrike().run() }, state?.strike)}
      <span className="rich-text-toolbar-divider" />
      {tool('左对齐', <IconAlignLeft />, () => { editor?.chain().focus().setTextAlign('left').run() }, state?.left)}
      {tool('居中', <IconAlignCenter />, () => { editor?.chain().focus().setTextAlign('center').run() }, state?.center)}
      {tool('右对齐', <IconAlignRight />, () => { editor?.chain().focus().setTextAlign('right').run() }, state?.right)}
      {tool('无序列表', <IconUnorderedList />, () => { editor?.chain().focus().toggleBulletList().run() }, state?.bullet)}
      {tool('有序列表', <IconOrderedList />, () => { editor?.chain().focus().toggleOrderedList().run() }, state?.ordered)}
      {tool('引用', <IconQuote />, () => { editor?.chain().focus().toggleBlockquote().run() }, state?.quote)}
      {tool('代码块', <IconCodeBlock />, () => { editor?.chain().focus().toggleCodeBlock().run() }, state?.code)}
      {tool('分割线', <IconMinus />, () => { editor?.chain().focus().setHorizontalRule().run() })}
      <span className="rich-text-toolbar-divider" />
      {tool('设置链接', <IconLink />, () => openDialog('link'))}
      {tool('插入图片', <IconImage />, () => openDialog('image'))}
      <Button size="small" htmlType="button" type="text" style={toolStyle()} disabled={disabled || preview} onClick={() => editor?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}>插入表格</Button>
      {state?.table && <Dropdown trigger="click" triggerProps={{ autoAlignPopupWidth: true }} disabled={disabled || preview} droplist={
        <Menu style={{ width: '100%', padding: 4 }} selectable={false} onClickMenuItem={action => {
          const chain = editor?.chain().focus()
          if (action === 'row') chain?.addRowAfter().run()
          else if (action === 'column') chain?.addColumnAfter().run()
          else if (action === 'deleteRow') chain?.deleteRow().run()
          else if (action === 'deleteColumn') chain?.deleteColumn().run()
          else chain?.deleteTable().run()
        }}>
          {[['row', '添加行'], ['column', '添加列'], ['deleteRow', '删除行'], ['deleteColumn', '删除列'], ['delete', '删除表格']]
            .map(([key, label]) => <Menu.Item key={key}
              style={{ ...toolStyle(), height: 32, lineHeight: '32px', padding: '0 12px', fontSize: 14 }}>{label}</Menu.Item>)}
        </Menu>
      }>
        <Button aria-label="表格操作" aria-haspopup="menu" htmlType="button" size="small" type="text"
          className="rich-text-menu-trigger" style={menuTriggerStyle(116)} disabled={disabled || preview}>表格操作<IconDown style={{ fontSize: 12 }} /></Button>
      </Dropdown>}
      {tool('清除格式', <span>Tx</span>, () => { editor?.chain().focus().unsetAllMarks().clearNodes().run() })}
      {tool('撤销', <IconUndo />, () => { editor?.chain().focus().undo().run() }, false, !state?.undo)}
      {tool('重做', <IconRedo />, () => { editor?.chain().focus().redo().run() }, false, !state?.redo)}
      <Button htmlType="button" size="small" type="text" style={toolStyle(preview, false, false)} aria-pressed={preview} onClick={() => setPreview(!preview)}>{preview ? '继续编辑' : '预览'}</Button>
      <Button htmlType="button" size="small" type="text" style={toolStyle(full, false, false)} aria-pressed={full} icon={full ? <IconShrink /> : <IconExpand />} aria-label={full ? '退出全屏' : '全屏编辑'} onClick={() => setFull(!full)} />
    </div>
    <div className="rich-text-content">{preview ? <RichTextViewer value={value} /> : <EditorContent editor={editor} />}</div>
    <div className="rich-text-footer">{editor?.getText().length ?? 0} 字<span>图片支持上传或链接插入</span></div>
    <FilePicker visible={picker} onClose={() => setPicker(false)} onSelect={file => { if (file.url) editor?.chain().focus().setImage({ src: file.url, alt: file.original_name ?? '' }).run() }} />
    <Modal maskClosable title={dialog === 'image' ? '插入图片' : '设置链接'} visible={dialog !== null} style={{ width: 480 }}
      wrapStyle={{ zIndex: 1200 }} maskStyle={{ zIndex: 1199 }} onCancel={() => setDialog(null)} onOk={insertUrl} confirmLoading={uploading} okButtonProps={{ disabled: uploading }}>
      {dialog === 'image' && <div className="rich-text-image-upload">
        <input ref={input} type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden onChange={event => void upload(event.target.files?.[0])} />
        <Space><Button htmlType="button" icon={<IconUpload />} loading={uploading} disabled={storage !== true || disabled} onClick={() => input.current?.click()}>上传本地图片</Button>{permissions.includes('admin.files.view') && <Button icon={<IconImage />} disabled={disabled || uploading} onClick={() => { setDialog(null); setPicker(true) }}>从文件库选择</Button>}<span className="rich-text-hint">最大5MiB，上传后可公开访问</span></Space>
        {storage === false && <Alert type="warning" content={<span>请先配置并启用默认存储通道。{permissions.includes('admin.channels.storage.view') && <Link to="/channels/storage">去配置</Link>}</span>} />}
        {storageError && <Alert type="error" content={storageError} />}
      </div>}
      <label className="rich-text-dialog-label" htmlFor="content-link-url">{dialog === 'image' ? '图片地址' : '链接地址'}</label>
      <Input id="content-link-url" value={url} onChange={value => { setUrl(value); setUrlError('') }} placeholder="https://example.com/..." maxLength={2000} status={urlError ? 'error' : undefined} />
      {urlError && <div className="rich-text-url-error">{urlError}</div>}
      {dialog === 'image' ? <><label className="rich-text-dialog-label" htmlFor="content-image-alt">图片说明</label><Input id="content-image-alt" value={alt} onChange={setAlt} placeholder="请输入图片说明（选填）" maxLength={200} /></>
        : <Button htmlType="button" type="text" onClick={() => { editor?.chain().focus().unsetLink().run(); setDialog(null) }}>移除链接</Button>}
    </Modal>
  </div>
}
