import { Spin } from '@arco-design/web-react'

/** 页面与懒加载模块共用的可访问加载状态。 */
export default function PageLoading({ fullPage = false }: { fullPage?: boolean }) {
  return <div className={fullPage ? 'member-loading' : 'page-loading'} role="status" aria-label="正在加载"><Spin size={32} /></div>
}
