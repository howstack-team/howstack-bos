import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Message, Modal, Space, Switch } from '@arco-design/web-react'
import { openServiceVnc, checkServiceVnc } from '../api/serviceInstanceApi'
import { createVncSession, vncLabels, type VncPhase, type VncSession } from '../../../packages/vnc-runtime'
import './VncConsoleModal.less'

/** 凭据只用于当前弹窗；关闭或离开页面立即断开，不复用上次票据。 */
export default function VncConsoleModal({ id, close }: { id: number; close: () => void }) {
  const display = useRef<HTMLDivElement>(null), panel = useRef<HTMLDivElement>(null), session = useRef<VncSession | null>(null)
  const request = useRef<AbortController | null>(null), busy = useRef(false), mounted = useRef(true)
  const [phase, setPhase] = useState<VncPhase>('idle'), [error, setError] = useState(''), [fitted, setFitted] = useState(true), [viewOnly, setViewOnly] = useState(false)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; request.current?.abort(); session.current?.close(); session.current = null } }, [])
  const connect = async () => {
    if (busy.current || !display.current) return
    busy.current = true; setPhase('connecting'); setError(''); session.current?.close(); session.current = null
    const controller = new AbortController(); request.current = controller
    let stage = 'initializing'
    try {
      const RFB = (await import('@novnc/novnc')).default
      if (controller.signal.aborted) return
      stage = 'requesting'
      const ticket = await openServiceVnc(id, controller.signal)
      if (!mounted.current || controller.signal.aborted || !display.current) return
      stage = 'connecting'
      session.current = createVncSession(display.current, RFB, ticket, signal => checkServiceVnc(id, signal), (next, message) => {
        if (mounted.current) { setPhase(next); setError(message); if (next === 'error' && message) Message.error(message) }
      })
      setFitted(true); setViewOnly(false)
    } catch (failure) {
      if (!controller.signal.aborted && mounted.current) { setPhase('error'); Message.error(stage === 'requesting' && failure instanceof Error ? failure.message : '控制台初始化失败，请更新浏览器后重试') }
    } finally { busy.current = false }
  }
  const disconnect = () => { request.current?.abort(); session.current?.close(); session.current = null; setPhase('disconnected'); setError('') }
  const connected = phase === 'connected'
  return <Modal visible title="VNC 远程控制台" maskClosable onCancel={close} footer={null} unmountOnExit
    style={{ width: 'min(1280px, calc(100vw - 32px))', top: 24 }}>
    <div className="vnc-console" ref={panel}>
      <div className="vnc-toolbar"><Space wrap>
        <span role="status">{vncLabels[phase]}</span>
        <Button type="primary" loading={phase === 'connecting'} disabled={connected} onClick={() => void connect()}>连接控制台</Button>
        <Button disabled={!connected && phase !== 'connecting'} onClick={disconnect}>断开</Button>
        <Button disabled={!connected} onClick={() => session.current?.ctrlAltDel()}>Ctrl+Alt+Del</Button>
        <Button disabled={!connected} onClick={() => session.current?.key('escape')}>Esc</Button>
      </Space><Space wrap>
        <span>适应窗口</span><Switch type="round" checked={fitted} onChange={value => { setFitted(value); session.current?.fit(value) }} />
        <span>只读</span><Switch type="round" checked={viewOnly} disabled={!connected} onChange={value => { setViewOnly(value); session.current?.readOnly(value) }} />
        <Button onClick={() => { if (panel.current?.requestFullscreen) void panel.current.requestFullscreen().catch(() => Message.info('当前浏览器不支持全屏')); else Message.info('当前浏览器不支持全屏') }}>全屏</Button>
      </Space></div>
      {error && phase !== 'error' && <Alert type="info" content={error} />}
      <div ref={display} className="vnc-display" aria-label="远程屏幕" />
      <p className="vnc-note">本次连接最多保留 15 分钟。关闭窗口即断开，重新连接会重新核验权限。</p>
    </div>
  </Modal>
}
