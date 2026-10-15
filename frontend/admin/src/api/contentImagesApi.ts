import { request } from './httpClient'
import type { ContentKind } from './supportContentApi'

/** 正文图片上传使用本机通道，客户端不能指定通道、对象键或临时签名。 */
export const contentImagesApi = {
  async options(kind: ContentKind): Promise<{ available: boolean }> {
    const result = await request<unknown>({ url: `/content/${kind}/images` })
    if (!result || typeof result !== 'object' || !('available' in result) || typeof result.available !== 'boolean') throw new Error('存储通道状态无效')
    return { available: result.available }
  },
  async upload(kind: ContentKind, file: File): Promise<{ url: string; alt: string }> {
    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type) || file.size > 5242880) throw new Error('请选择不超过5MiB的 JPG、PNG、GIF 或 WebP 图片')
    const data = new FormData()
    data.append('file', file)
    const result = await request<unknown>({ url: `/content/${kind}/images`, method: 'POST', data, timeout: 60000, headers: { 'Content-Type': 'multipart/form-data' } })
    if (!result || typeof result !== 'object' || !('url' in result) || typeof result.url !== 'string' || !/^\/api\/public\/v1\/content\/images\/[a-f0-9]{32}$/.test(result.url)
      || !('alt' in result) || typeof result.alt !== 'string') throw new Error('图片上传返回数据无效')
    return { url: result.url, alt: result.alt }
  },
}
