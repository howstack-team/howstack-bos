import { useState, type ReactNode } from 'react'
import Button from '@arco-design/mobile-react/esm/button'
import Input from '@arco-design/mobile-react/esm/input'
import Form from '@arco-design/mobile-react/esm/form'
import { ValidatorType } from '@arco-design/mobile-utils'
import Toast from '@arco-design/mobile-react/esm/toast'
import emptyStateImage from '../assets/empty-state.png'
import loadingImage from '../assets/loading.svg?url'
import { useConsoleTheme } from '../ConsoleThemeContext'
import defaultLogo from '../assets/logo.png'

import { FeedbackContext } from './feedback'
/** 声明式 Toast 跟随应用销毁，不调用旧版 ReactDOM.render。 */
export function FeedbackProvider({ children }: { children: ReactNode }) {
  const [notice, setNotice] = useState<{ content: string; type: 'success' | 'error' | 'info'; key: number } | null>(null)
  return <FeedbackContext.Provider value={(content, type = 'error') => setNotice({ content, type, key: Date.now() })}>
    {children}<div aria-live="polite" className="m-sr-only">{notice?.content}</div>
    {notice && <Toast key={notice.key} visible content={notice.content} type={notice.type} duration={3500} close={() => setNotice(null)} />}
  </FeedbackContext.Provider>
}
export function Brand({ small = false }: { small?: boolean }) {
  const site = useConsoleTheme()!.site
  return <div className={`m-brand${small ? ' m-brand-small' : ''}`}><img src={site.logo || defaultLogo} alt="" /><span>{site.name}</span></div>
}
export function Footer() {
  const site = useConsoleTheme()!.site
  return <footer className="m-footer">{site.copyright && <span>{site.copyright}</span>}
    {site.icp_record && <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">{site.icp_record}</a>}
    {site.public_security_record && <a href="https://beian.mps.gov.cn/" target="_blank" rel="noopener noreferrer">{site.public_security_record}</a>}
  </footer>
}
export function Status({ title, text, retry }: { title?: string; text: string; retry?: () => void }) {
  return <section className="m-status"><div className="m-status-icon"><Glyph name="services" /></div>
    {title && <h2>{title}</h2>}<p>{text}</p>{retry && <Button inline size="medium" onClick={retry}>重试</Button>}</section>
}
/** 移动端空数据插画，与后台和 PC 会员中心使用配套品牌素材。 */
export function EmptyState({ text = '暂无数据' }: { text?: string }) {
  return <section className="m-status"><img src={emptyStateImage} className="m-empty-image" alt="" /><p>{text}</p></section>
}
/** 移动端页面加载图只依赖本端素材和样式，不导入 PC 组件。 */
export function Busy() {
  return <div className="m-status" role="status" aria-label="加载中"><img src={loadingImage} className="m-loading-image" alt="" /><p>加载中…</p></div>
}
export function Glyph({ name }: { name: 'home' | 'services' | 'messages' | 'user' | 'wallet' | 'shield' }) {
  const strokes = {
    home: 'M3 11 12 3l9 8M5 10v11h5v-7h4v7h5V10',
    services: 'M4 3h16v7H4zM4 14h16v7H4zM7 6h.1M7 17h.1M11 6h6M11 17h6',
    messages: 'M4 4h16v12H9l-5 4V4zM8 8h8M8 12h5',
    user: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2',
    wallet: 'M3 6h17v15H3V6zM3 6V3h14v3M15 11h5v5h-5z',
    shield: 'M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3zM9 12l2 2 4-4',
  }
  return <svg className="m-glyph" aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={strokes[name]} /></svg>
}
export function Field({ label, value, onChange, error, validate, type = 'text', placeholder, disabled, suffix, autoComplete, maxLength }: {
  label: string; value: string; onChange: (value: string) => void; error?: string; type?: string;
  validate: (value: string) => string | undefined;
  placeholder: string; disabled?: boolean; suffix?: ReactNode; autoComplete?: string; maxLength?: number;
}) {
  return <><Form.Item field={label} label={null} labelName={label} initialValue={value} className="m-field" layout="horizontal" disabled={disabled}
    trigger="onInput" rules={[{ type: ValidatorType.Custom, validateTrigger: 'onInput', validator: (input: unknown, callback) => callback(validate(typeof input === 'string' ? input : '')) }]}>
    <Input className="m-field-input" ariaLabel={label} type={type} placeholder={placeholder}
    disabled={disabled} border="none" clearable={type !== 'password' && autoComplete !== 'one-time-code'} suffix={suffix} maxLength={maxLength}
    // 使用 Form 内输入框的原生高度；minWidth 允许验证码后缀在窄屏正常分配宽度。
    inputStyle={{ minWidth: 0 }}
    nativeProps={{ autoComplete, inputMode: type === 'tel' ? 'tel' : type === 'email' ? 'email' : autoComplete === 'one-time-code' ? 'numeric' : undefined, 'aria-invalid': Boolean(error) }}
    onInput={(_, next) => onChange(next)} /></Form.Item>{error && <p className="m-field-error" role="alert">{error}</p>}</>
}
