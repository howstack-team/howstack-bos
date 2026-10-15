import { IconStorage } from '@arco-design/web-react/icon'

const icons = import.meta.glob<string>('../assets/file-types/*.svg', { eager: true, query: '?url&no-inline', import: 'default' })
const extensions: Record<string, string> = {
  jpg: 'image-jpeg', jpeg: 'image-jpeg', png: 'image-png', gif: 'image-gif', webp: 'image-pic', svg: 'image-pic', avif: 'image-pic',
  pdf: 'office-pdf', doc: 'office-doc', docx: 'office-doc', xls: 'office-els', xlsx: 'office-els', csv: 'office-els', numbers: 'office-numbers',
  txt: 'office-txt', md: 'office-txt', ppt: 'office-ppt', pptx: 'office-ppt', mp3: 'music', wav: 'music', mp4: 'video', mov: 'video', avi: 'video',
  zip: 'zip', rar: 'rar-45900891', '7z': 'rar-45900891', js: 'js', css: 'css', json: 'code', xml: 'code', exe: 'exe', apk: 'apk-45900698', ipa: 'ipa-45900697',
}
const categories: Record<string, string> = { image: 'image', document: 'office-txt', video: 'video', audio: 'music', other: 'untitled', all: 'folder' }

/** 本地图标按扩展名匹配，未知格式使用所属分类图标；不发起图标库网络请求。 */
export default function FileTypeIcon({ extension = '', type = 'other', size = 32 }: { extension?: string; type?: string; size?: number }) {
  const key = extension.toLowerCase().replace(/^\./, '')
  if (['sql', 'db', 'sqlite', 'sqlite3'].includes(key)) return <IconStorage aria-hidden style={{ width: size, height: size, flexShrink: 0, color: 'rgb(var(--cyan-6))', verticalAlign: 'middle' }} />
  const name = extensions[key] ?? categories[type] ?? 'file'
  return <img src={icons[`../assets/file-types/${name}.svg`]} alt="" aria-hidden="true" width={size} height={size} style={{ flexShrink: 0, objectFit: 'contain', verticalAlign: 'middle' }} />
}
