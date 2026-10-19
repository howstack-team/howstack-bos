/** VNC凭据只存在于当前连接内存，不写入URL、查询缓存或持久存储。 */
export interface VncTicket { websocket_url: string; password: string; connect_timeout_seconds: number; session_timeout_seconds: number }
export type VncPhase = 'idle' | 'connecting' | 'connected' | 'disconnected' | 'error'
export const vncLabels: Record<VncPhase, string> = { idle: '尚未连接', connecting: '正在连接…', connected: '已连接', disconnected: '已断开', error: '连接失败' }
/** 只描述noVNC公开API；由各应用从已锁定的本地依赖注入构造器。 */
export interface RfbClient extends EventTarget {
  scaleViewport: boolean; clipViewport: boolean; resizeSession: boolean; dragViewport: boolean; viewOnly: boolean; focusOnClick: boolean
  disconnect(): void; focus(): void; sendCtrlAltDel(): void; sendKey(keysym: number, code?: string, down?: boolean): void
}
export interface RfbConstructor { new(target: HTMLElement, url: string, options: { credentials: { password: string } }): RfbClient }
export interface VncSession {
  close(): void; readOnly(value: boolean): void; key(name: 'enter' | 'escape' | 'tab' | 'backspace'): void; text(value: string): void; ctrlAltDel(): void; fit(value: boolean): void
}
/** 对API边界进行独立校验，拒绝HTML链接、明文WS、URL登录信息及异常时长。 */
export function parseVncTicket(value: unknown): VncTicket {
  if (!value || typeof value !== 'object') throw new Error('VNC连接响应格式无效')
  const data = value as Record<string, unknown>
  if (typeof data.websocket_url !== 'string' || data.websocket_url.length > 8192 || /[\u0000-\u0020\u007f\\]/u.test(data.websocket_url)
    || typeof data.password !== 'string' || data.password.length > 2048 || data.password.includes('\0')
    || !Number.isInteger(data.connect_timeout_seconds) || Number(data.connect_timeout_seconds) < 1 || Number(data.connect_timeout_seconds) > 60
    || !Number.isInteger(data.session_timeout_seconds) || Number(data.session_timeout_seconds) < 1 || Number(data.session_timeout_seconds) > 900) throw new Error('VNC连接响应格式无效')
  let url: URL
  try { url = new URL(data.websocket_url) } catch { throw new Error('VNC连接地址无效') }
  if (url.protocol !== 'wss:' || !url.hostname || url.username || url.password || url.hash) throw new Error('VNC必须使用安全的WSS连接')
  return data as unknown as VncTicket
}
/** 管理公开RFB API、连接超时及页面访问续检；关闭时断开连接并清理所有定时器和监听器。 */
export function createVncSession(target: HTMLElement, RFB: RfbConstructor, ticket: VncTicket,
  checkAccess: (signal: AbortSignal) => Promise<void>, onState: (phase: VncPhase, message: string) => void): VncSession {
  let disposed = false, connected = false, checking = false
  const controller = new AbortController()
  target.replaceChildren()
  let rfb: RfbClient | null = new RFB(target, ticket.websocket_url, { credentials: { password: ticket.password } })
  rfb.scaleViewport = true; rfb.clipViewport = true; rfb.resizeSession = false; rfb.viewOnly = false; rfb.focusOnClick = true
  const dispose = () => {
    if (disposed) return
    disposed = true; connected = false; controller.abort()
    clearTimeout(connectTimer); clearTimeout(sessionTimer); clearInterval(accessTimer)
    const previous = rfb; rfb = null
    previous?.removeEventListener('connect', onConnect); previous?.removeEventListener('disconnect', onDisconnect)
    previous?.removeEventListener('securityfailure', onFailure); previous?.removeEventListener('credentialsrequired', onFailure)
    previous?.disconnect(); target.replaceChildren()
  }
  const fail = (message: string) => { if (!disposed) { dispose(); onState('error', message) } }
  const onConnect = () => { if (!disposed) { connected = true; clearTimeout(connectTimer); onState('connected', ''); rfb?.focus() } }
  const onDisconnect = () => { if (!disposed) { const wasConnected = connected; dispose(); onState(wasConnected ? 'disconnected' : 'error', wasConnected ? '连接已结束，可重新申请连接' : '连接失败，请检查供应商WSS网关、证书及网络') } }
  const onFailure = () => fail('VNC认证失败，请重新申请连接')
  const connectTimer = setTimeout(() => fail('连接超时，请检查供应商的WSS网关和网络'), ticket.connect_timeout_seconds * 1000)
  const sessionTimer = setTimeout(() => { if (!disposed) { dispose(); onState('disconnected', '本次控制台访问已到期，请重新连接') } }, ticket.session_timeout_seconds * 1000)
  const check = async () => {
    if (disposed || checking) return
    checking = true
    try { await checkAccess(controller.signal) }
    catch { if (!disposed) fail('当前登录状态、权限或实例资格已变化，请重新连接') }
    finally { checking = false }
  }
  const accessTimer = setInterval(() => { void check() }, 30000)
  rfb.addEventListener('connect', onConnect); rfb.addEventListener('disconnect', onDisconnect)
  rfb.addEventListener('securityfailure', onFailure); rfb.addEventListener('credentialsrequired', onFailure)
  onState('connecting', '')
  return {
    close: dispose,
    readOnly: value => { if (!disposed && rfb) rfb.viewOnly = value },
    fit: value => { if (!disposed && rfb) { rfb.scaleViewport = value; rfb.dragViewport = !value } },
    ctrlAltDel: () => { if (connected && !disposed && rfb) rfb.sendCtrlAltDel() },
    key: name => { if (connected && !disposed && rfb) rfb.sendKey(({ enter: 0xff0d, escape: 0xff1b, tab: 0xff09, backspace: 0xff08 })[name]) },
    text: value => { if (connected && !disposed && rfb) for (const char of Array.from(value).slice(0, 1024)) { const point = char.codePointAt(0)!; rfb.sendKey(point === 10 || point === 13 ? 0xff0d : point > 255 ? 0x01000000 | point : point) } },
  }
}
