import flexible from '@arco-design/mobile-react/tools/flexible'

/** 官方工具以 UMD 发布，兼容 Vite 开发预构建与生产构建的 default 导出。 */
export function setMobileRootPixel(): () => void {
  const module: unknown = flexible
  const factory = typeof module === 'function' ? module : typeof module === 'object' && module !== null && 'default' in module ? module.default : null
  if (typeof factory !== 'function') throw new Error('移动端尺寸工具加载失败')
  return factory(50, 375, 58) as () => void
}
