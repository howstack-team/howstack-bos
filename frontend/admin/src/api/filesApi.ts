import { request } from './httpClient'
export interface FileReference { scope: string; owner: string; label: string }
export interface FileAsset { id: string; original_name: string | null; extension: string; type: string; mime_type: string; byte_size: number; origin: string; status: string; created_by: number; created_by_name: string | null; created_at: string; namespace: string; channel_id: number; channel_revision: number; channel_name: string | null; references: FileReference[]; url: string | null; deletable: boolean }
export interface FileList { data: FileAsset[]; total: number; page: number; page_size: number }
export interface FileStatistics { count: number; byte_size: number; types: { type: string; count: number; byte_size: number }[] }
export const fileTypes = { image: '图片', document: '文档', video: '视频', audio: '音频', other: '其他' }
export const fileOrigins = { website: '官网', help: '帮助文档', news: '新闻公告', content: '历史正文', library: '文件库' }
export const fileStatuses = { stored: '上传完成', pending: '上传中', failed: '上传失败', unknown: '结果未确认', deleting: '删除中', delete_failed: '删除待重试' }
export function fileName(file: FileAsset): string { return file.original_name || `历史文件（${file.id.slice(0, 8)}.${file.extension}）` }
export const filesApi = {
  async statistics(signal?: AbortSignal): Promise<FileStatistics> {
    const value = await request<FileStatistics>({ url: '/files/statistics', signal })
    if (!value || !Number.isSafeInteger(value.count) || value.count < 0 || !Number.isSafeInteger(value.byte_size) || value.byte_size < 0 || !Array.isArray(value.types)
      || value.types.length !== Object.keys(fileTypes).length || value.types.some(item => !Object.hasOwn(fileTypes, item.type) || !Number.isSafeInteger(item.count) || item.count < 0 || !Number.isSafeInteger(item.byte_size) || item.byte_size < 0)
      || new Set(value.types.map(item => item.type)).size !== value.types.length) throw new Error('文件统计响应无效')
    return value
  },
  async list(params: Record<string, string | number>, signal?: AbortSignal): Promise<FileList> {
    const value = await request<FileList>({ url: '/files', params, signal })
    if (!value || !Array.isArray(value.data) || !Number.isSafeInteger(value.total) || value.total < 0 || !Number.isSafeInteger(value.page) || !Number.isSafeInteger(value.page_size)
      || value.data.some(file => !file || !/^[a-f0-9]{32}$/.test(file.id) || !Object.hasOwn(fileTypes, file.type) || !Object.hasOwn(fileOrigins, file.origin) || !Object.hasOwn(fileStatuses, file.status)
        || !Number.isSafeInteger(file.created_by) || file.created_by < 0 || (file.created_by_name !== null && typeof file.created_by_name !== 'string')
        || !Number.isSafeInteger(file.channel_id) || file.channel_id < 1 || !Number.isSafeInteger(file.channel_revision) || file.channel_revision < 1
        || (file.channel_name !== null && typeof file.channel_name !== 'string')
        || !Number.isSafeInteger(file.byte_size) || file.byte_size < 0 || typeof file.deletable !== 'boolean' || !Array.isArray(file.references)
        || file.references.some(ref => typeof ref.label !== 'string' || typeof ref.scope !== 'string' || typeof ref.owner !== 'string')
        || (file.url !== null && !/^\/api\/public\/v1\/(?:website\/images|content\/images|files)\/[a-f0-9]{32}$/.test(file.url)))) throw new Error('文件列表响应无效')
    return value
  },
  async upload(file: File): Promise<void> { const data = new FormData(); data.append('file', file); await request({ url: '/files', method: 'POST', data, timeout: 60000, headers: { 'Content-Type': undefined } }) },
  async delete(ids: string[]): Promise<{ deleted: string[]; failed: string[] }> { return request({ url: '/files/delete', method: 'POST', data: { ids }, timeout: 60000 }) },
}
