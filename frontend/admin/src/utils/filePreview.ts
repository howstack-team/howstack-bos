import type { FileAsset } from '../api/filesApi'

/** JS Preview 支持的文档格式，不包含旧 DOC 格式。 */
export function supportsDocumentPreview(file: FileAsset): boolean {
  return ['pdf', 'docx', 'xls', 'xlsx'].includes(file.extension.toLowerCase())
}

/** 当前上传白名单内可通过西瓜播放器预览的媒体格式。 */
export function supportsMediaPreview(file: FileAsset): boolean {
  return (file.type === 'audio' && file.extension.toLowerCase() === 'mp3')
    || (file.type === 'video' && file.extension.toLowerCase() === 'mp4')
}
