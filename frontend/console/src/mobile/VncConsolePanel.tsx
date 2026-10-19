import { useEffect, useRef, useState } from 'react'
import Popup from '@arco-design/mobile-react/esm/popup'
import Button from '@arco-design/mobile-react/esm/button'
import { openServiceVnc, checkServiceVnc } from '../api/serviceApi'
import { createVncSession, vncLabels, type VncPhase, type VncSession } from '../../../packages/vnc-runtime'
import { useFeedback } from './feedback'

/** 独立移动远程屏幕，使用本地noVNC和默认移动控件，不加载PC组件。 */
export default function VncConsolePanel({ id, close }: { id: number; close: () => void }) {
  const display = useRef<HTMLDivElement>(null), session = useRef<VncSession | null>(null), keyboard = useRef<HTMLTextAreaElement>(null)
  const request = useRef<AbortController | null>(null), busy = useRef(false), mounted = useRef(true), composing = useRef(false)
  const [phase, setPhase] = useState<VncPhase>('idle'), [fitted, setFitted] = useState(true), [keyboardOpen, setKeyboardOpen] = useState(false)
  const feedback = useFeedback()
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; request.current?.abort(); session.current?.close(); session.current = null } }, [])
  const connect = async () => {
    if (busy.current || !display.current) return
    busy.current = true; setPhase('connecting'); session.current?.close(); session.current = null
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
        if (mounted.current) { setPhase(next); if (message) feedback(message, next === 'error' ? 'error' : 'info') }
      })
      setFitted(true)
    } catch (failure) { if (!controller.signal.aborted && mounted.current) { setPhase('error'); feedback(stage === 'requesting' && failure instanceof Error ? failure.message : '控制台初始化失败，请更新浏览器后重试') } }
    finally { busy.current = false }
  }
  const disconnect = () => { request.current?.abort(); session.current?.close(); session.current = null; setPhase('disconnected') }
  const connected = phase === 'connected'
  const typed = (element: HTMLTextAreaElement) => { if (!composing.current && element.value) { session.current?.text(element.value); element.value = '' } }
  return <Popup visible direction="bottom" maskClosable animatingClosable close={close} unmountOnExit contentStyle={{ width: '100%', height: '100dvh' }}>
    <section className="m-vnc-panel" role="dialog" aria-modal="true" aria-label="VNC 远程控制台">
      <header className="m-vnc-header"><div><strong>VNC 远程控制台</strong><p role="status">{vncLabels[phase]}</p></div><Button inline size="small" type="default" onClick={close}>关闭</Button></header>
      <div className="m-vnc-actions">
        <Button inline size="small" loading={phase === 'connecting'} disabled={connected} onClick={() => void connect()}>连接</Button>
        <Button inline size="small" type="default" disabled={!connected && phase !== 'connecting'} onClick={disconnect}>断开</Button>
        <Button inline size="small" type="default" disabled={!connected} onClick={() => { const value = !fitted; setFitted(value); session.current?.fit(value) }}>{fitted ? '原始尺寸' : '适应屏幕'}</Button>
        <Button inline size="small" type="default" disabled={!connected} onClick={() => { setKeyboardOpen(value => !value); requestAnimationFrame(() => keyboard.current?.focus()) }}>键盘</Button>
      </div>
      <div className="m-vnc-display" ref={display} aria-label="远程屏幕" />
      <div className="m-vnc-actions"><Button inline size="small" type="default" disabled={!connected} onClick={() => session.current?.ctrlAltDel()}>Ctrl+Alt+Del</Button>
        {(['escape', 'tab', 'enter', 'backspace'] as const).map(key => <Button key={key} inline size="small" type="default" disabled={!connected} onClick={() => session.current?.key(key)}>{({ escape: 'Esc', tab: 'Tab', enter: '回车', backspace: '退格' })[key]}</Button>)}
      </div>
      {keyboardOpen && <label className="m-vnc-keyboard">远程键盘
        <textarea ref={keyboard} rows={1} aria-label="远程键盘输入" disabled={!connected} autoComplete="off" autoCapitalize="none" autoCorrect="off" spellCheck={false}
          onCompositionStart={() => { composing.current = true }} onCompositionEnd={event => { composing.current = false; typed(event.currentTarget) }}
          onInput={event => typed(event.currentTarget)} onKeyDown={event => { if (!composing.current && ['Enter', 'Backspace', 'Tab', 'Escape'].includes(event.key)) { event.preventDefault(); session.current?.key(({ Enter: 'enter', Backspace: 'backspace', Tab: 'tab', Escape: 'escape' } as const)[event.key as 'Enter' | 'Backspace' | 'Tab' | 'Escape']) } }}
          placeholder="输入即发送" />
      </label>}
      <p className="m-vnc-note">横屏操作更方便。本次连接最多 15 分钟，关闭即断开；原始尺寸下可拖动画面。</p>
    </section>
  </Popup>
}
