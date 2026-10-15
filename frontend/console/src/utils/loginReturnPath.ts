import { paths } from '../routePaths.ts'

/** 仅恢复控制台内的路由，排除外部 URL、反斜杠和登录循环。 */
export function loginReturnPath(state: unknown): string {
  if (typeof state !== 'object' || state === null || !('from' in state) || typeof state.from !== 'string') return paths.overview
  const from = state.from
  if (!from.startsWith('/') || from.startsWith('//') || from.includes('\\')
    || Array.from(from).some((char) => char.charCodeAt(0) < 32)) return paths.overview
  const pathname = from.split(/[?#]/)[0].replace(/\/+$/, '')
  if ([paths.login, paths.register, '/console/login', '/console/register'].includes(pathname)) return paths.overview
  return from
}
