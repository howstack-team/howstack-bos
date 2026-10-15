import { useEffect, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { parseConsoleSite, type ConsoleThemeConfig } from '../../packages/console-theme-runtime'
import { isObject, request } from './api/httpClient'
import { ConsoleThemeContext } from './ConsoleThemeContext'

/** 主题构建固定，品牌资料在返回浏览器标签页时刷新，不替换当前应用或清理表单。 */
export function ConsoleThemeProvider({ initial, children }: { initial: ConsoleThemeConfig; children: ReactNode }) {
  const { data: site } = useQuery({
    queryKey: ['public', 'console-brand'],
    initialData: initial.site,
    queryFn: async ({ signal }) => {
      const config = await request('get', '/bootstrap', undefined, signal)
      if (!isObject(config)) throw new Error('会员中心配置无效')
      return parseConsoleSite(config.site)
    },
    refetchOnMount: false,
    refetchOnWindowFocus: 'always',
  })
  useEffect(() => {
    const icon = document.querySelector<HTMLLinkElement>('link[rel="icon"]') ?? document.createElement('link')
    const original = icon.getAttribute('href') || ''
    icon.rel = 'icon'
    if (!icon.parentNode) document.head.append(icon)
    if (site.favicon) { icon.href = site.favicon; icon.removeAttribute('type') }
    return () => { icon.setAttribute('href', original) }
  }, [site.favicon])
  return <ConsoleThemeContext.Provider value={{ ...initial, site }}>{children}</ConsoleThemeContext.Provider>
}
