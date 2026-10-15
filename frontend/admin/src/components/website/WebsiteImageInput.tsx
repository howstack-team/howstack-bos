import { useEffect, useRef, useState } from 'react'
import { Button, Image, Message, Space, Tooltip, Typography } from '@arco-design/web-react'
import { IconDelete, IconUpload, IconImage } from '@arco-design/web-react/icon'
import { websiteApi, websiteImageUrl } from '../../api/websiteApi'
import FilePicker from '../FilePicker'
import { useAdminAuth } from '../../auth/AuthContext'
interface Props { value: string; onChange: (id: string) => void; disabled: boolean; available: boolean; label: string; onPending: (pending: boolean) => void; compact?: boolean }
/** 图片字段只接收已上传 ID，取消旧上传；清空只取消引用，不删除历史图片。 */
export default function WebsiteImageInput({ value, onChange, disabled, available, label, onPending, compact = false }: Props) {
  const input = useRef<HTMLInputElement>(null), controller = useRef<AbortController | null>(null)
  const { permissions } = useAdminAuth()
  const [picker, setPicker] = useState(false)
  const [uploading, setUploading] = useState(false), [preview, setPreview] = useState(false)
  useEffect(() => () => controller.current?.abort(), [])
  async function upload(file?: File) {
    if (!file || disabled || !available || controller.current) return
    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024 || !file.size) {
      Message.error('请选择5MiB以内的 JPEG、PNG、GIF 或 WebP 图片'); return
    }
    const request = new AbortController(); controller.current = request; setUploading(true); onPending(true)
    try {
      const image = await websiteApi.upload(file, request.signal)
      if (!request.signal.aborted) { onChange(image.id); Message.success('图片上传成功，请保存配置') }
    } catch (reason) { if (!request.signal.aborted) Message.error(reason instanceof Error ? reason.message : '图片上传失败') }
    finally { controller.current = null; if (!request.signal.aborted) setUploading(false); onPending(false); if (input.current) input.current.value = '' }
  }
  const uploadButton = <Button size={compact ? 'small' : 'default'} icon={<IconUpload />} disabled={disabled || !available} loading={uploading} onClick={() => input.current?.click()}>{value ? '替换图片' : '上传图片'}</Button>
  return <div className={`website-image-input${compact ? ' website-image-input-compact' : ''}`}>
    {value ? <><button type="button" className="website-image-preview" aria-label={`预览${label}`} onClick={() => setPreview(true)}>
      <img src={websiteImageUrl(value)} alt={label} />
    </button><Image.Preview src={websiteImageUrl(value)} visible={preview} onVisibleChange={setPreview} imgAttributes={{ alt: label }} /></> : <div className="website-image-empty">未设置图片</div>}
    <div><Space wrap>
      {compact ? <Tooltip content="JPEG / PNG / GIF / WebP，最大5MiB、1600万像素">{uploadButton}</Tooltip> : uploadButton}
      {permissions.includes('admin.files.view') && <Button size={compact ? 'small' : 'default'} icon={<IconImage />} disabled={disabled || uploading} onClick={() => setPicker(true)}>从文件库选择</Button>}
      {value && <Button size={compact ? 'small' : 'default'} icon={<IconDelete />} disabled={disabled} onClick={() => onChange('')}>清空</Button>}
    </Space>{!compact && <Typography.Paragraph type="secondary" style={{ margin: '8px 0 0', fontSize: 12 }}>JPEG / PNG / GIF / WebP，最大5MiB、1600万像素</Typography.Paragraph>}</div>
    <FilePicker visible={picker} onClose={() => setPicker(false)} onSelect={file => onChange(file.id)} />
    <input ref={input} type="file" accept="image/jpeg,image/png,image/gif,image/webp" aria-label={`上传${label}`} hidden onChange={event => void upload(event.target.files?.[0])} />
  </div>
}
