import { useMemo } from 'react'
import { sanitizeContentHtml } from './sanitizeContentHtml'
import './RichTextEditor.css'

/** 公告富文本只经过白名单清理后显示，不执行正文中的脚本或事件属性。 */
export default function RichTextViewer({ value }: { value: string }) {
  const html = useMemo(() => sanitizeContentHtml(value), [value])
  return <div className="rich-text-body" dangerouslySetInnerHTML={{ __html: html }} />
}
