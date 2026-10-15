import { useEffect, useState } from 'react'

const COMPACT_VIEWPORT = '(min-width: 768px)'

/** 判断当前视口是否适合双列表单排版，不决定弹窗或抽屉类型。 */
export function useCompactFormViewport() {
  const [compact, setCompact] = useState(() => window.matchMedia(COMPACT_VIEWPORT).matches)
  useEffect(() => {
    const media = window.matchMedia(COMPACT_VIEWPORT)
    const update = () => setCompact(media.matches)
    media.addEventListener('change', update)
    return () => media.removeEventListener('change', update)
  }, [])
  return compact
}

/** 双列表单的公开 Arco 布局参数；窄屏使用单列纵向标签。 */
export function useCompactFormLayout() {
  const compact = useCompactFormViewport()
  return {
    className: 'form-dialog-fields',
    layout: compact ? 'horizontal' as const : 'vertical' as const,
    labelCol: compact ? { flex: '112px' } : undefined,
    wrapperCol: compact ? { flex: 'auto' } : undefined,
  }
}
