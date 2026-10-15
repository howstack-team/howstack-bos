import { useEffect, useRef, useState } from 'react'
import { Alert, Badge, Button, Card, Form, Input, Link, Message, Modal, Space, Spin, Typography } from '@arco-design/web-react'
import QRCode from 'qrcode'
import { adminMfaApi, type AdminMfaStatus, type AdminMfaSetup, type MfaProof } from '../api/adminMfaApi'
import { useAdminAuth } from '../auth/AuthContext'
import { ApiRequestError } from '../api/httpClient'

/** 本人验证器管理，临时密钥和恢复码仅存在组件内存，关闭即清除。 */
export default function AdminMfaSettings() {
  const { permissions, refreshAccess } = useAdminAuth()
  const [status, setStatus] = useState<AdminMfaStatus | null>(null)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  const [action, setAction] = useState<'setup' | 'disable' | 'recovery-codes' | null>(null)
  const [setup, setSetup] = useState<AdminMfaSetup | null>(null)
  const [qr, setQr] = useState('')
  const [codes, setCodes] = useState<string[]>([])
  const [recovery, setRecovery] = useState(false)
  const [busy, setBusy] = useState(false)
  const lock = useRef(false)
  const generation = useRef(0)
  const [form] = Form.useForm<MfaProof>()
  const allowed = permissions.includes('admin.profile.mfa')
  useEffect(() => {
    const controller = new AbortController()
    void adminMfaApi.status(controller.signal).then(v => { if (!controller.signal.aborted) { setStatus(v); setError('') } }).catch((e: unknown) => { if (!controller.signal.aborted) setError(e instanceof Error ? e.message : '安全设置加载失败') })
    return () => controller.abort()
  }, [retry])
  useEffect(() => {
    let active = true
    if (setup) void QRCode.toDataURL(setup.uri, { width: 200, margin: 2 }).then(v => { if (active) setQr(v) }).catch(() => { if (active) Message.error('二维码生成失败，请使用密钥手动添加验证器') })
    return () => { active = false }
  }, [setup])
  function close() { generation.current++; setAction(null); setSetup(null); setQr(''); setRecovery(false); form.resetFields() }
  useEffect(() => () => { generation.current++ }, [])
  async function submit() {
    if (lock.current || !action) return
    let values: MfaProof
    try { values = await form.validate() } catch { return }
    lock.current = true; setBusy(true)
    const stamp = generation.current
    try {
      if (action === 'setup' && setup === null) {
        const result = await adminMfaApi.setup(values.current_password)
        if (stamp === generation.current) setSetup(result)
        return
      }
      const result = action === 'setup'
        ? await adminMfaApi.enable({ ...values, recovery: false, setup_id: setup?.setup_id ?? '' })
        : await adminMfaApi.change(action, { ...values, recovery })
      setStatus(result)
      // 已提交请求的成功反馈继续保留；关闭浮层后不重新展示一次性秘密。
      if (stamp === generation.current) { close(); setCodes(result.recovery_codes) }
      Message.success(result.enabled ? '两步验证设置已更新' : '已关闭两步验证')
      await refreshAccess()
    } catch (e) {
      let mapped = false
      if (stamp === generation.current && e instanceof ApiRequestError && e.status === 422 && typeof e.data === 'object' && e.data !== null && 'errors' in e.data && typeof e.data.errors === 'object' && e.data.errors !== null) {
        const errors = e.data.errors as Record<string, unknown>
        for (const key of ['current_password', 'code'] as const) {
          const messages = errors[key]
          if (Array.isArray(messages) && messages.every(v => typeof v === 'string')) { form.setFields({ [key]: { error: { message: messages.join('；') } } }); mapped = true }
        }
      }
      if (!mapped) Message.error(e instanceof Error ? e.message : '安全设置保存失败')
    } finally { lock.current = false; setBusy(false) }
  }
  function download() {
    const url = URL.createObjectURL(new Blob(['Howstack BOS 管理员一次性恢复码\n每组仅可使用一次，请离线妥善保存。\n\n' + codes.join('\n')], { type: 'text/plain;charset=utf-8' }))
    const a = document.createElement('a'); a.href = url; a.download = 'howstack-admin-recovery-codes.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
  }
  return <Card title="两步验证" bordered={false}>
    {error ? <Alert type="error" content={error} action={<Button onClick={() => setRetry(v => v + 1)}>重试</Button>} /> : status ? <>
      <Space direction="vertical" size="large">
        <Badge status={status.enabled ? 'success' : 'default'} text={status.enabled ? '已开启验证器验证' : '尚未开启'} />
        <Typography.Paragraph>开启后，管理员登录须在密码验证通过后，再输入验证器验证码或一次性恢复码。</Typography.Paragraph>
        {status.enabled && <Typography.Text>剩余恢复码：{status.recovery_remaining} 组</Typography.Text>}
        {allowed && <Space wrap>{(status.enabled ? ['recovery-codes', 'disable'] as const : ['setup'] as const).map(v => <Button key={v} type={v === 'setup' ? 'primary' : 'secondary'} status={v === 'disable' ? 'danger' : 'default'} disabled={busy} onClick={() => { close(); setAction(v) }}>{v === 'setup' ? '绑定验证器' : v === 'disable' ? '关闭两步验证' : '重置恢复码'}</Button>)}</Space>}
      </Space>
    </> : <Spin />}
    <Modal visible={action !== null} title={action === 'setup' ? '绑定验证器' : action === 'disable' ? '关闭两步验证' : '重置恢复码'} maskClosable onCancel={close} onOk={() => void submit()} confirmLoading={busy} okText={action === 'setup' && !setup ? '下一步' : '确认'} style={{ width: 'min(480px, calc(100vw - 32px))' }}>
      <Form form={form} layout="vertical" disabled={busy}>
        <Form.Item field="current_password" label="当前密码" rules={[{ required: true, message: '请输入当前密码' }, { maxLength: 128, message: '密码不能超过128个字符' }]}><Input.Password visibilityToggle={false} autoComplete="current-password" placeholder="请输入当前管理员密码" maxLength={128} /></Form.Item>
        {setup && <div style={{ textAlign: 'center', marginBottom: 20 }}>
          {qr && <img src={qr} alt="验证器绑定二维码" width="200" height="200" style={{ maxWidth: '100%', objectFit: 'contain' }} />}
          <Typography.Paragraph style={{ overflowWrap: 'anywhere' }}>{setup.secret}</Typography.Paragraph>
          <Typography.Paragraph type="secondary">使用验证器扫描二维码，或手动输入上方密钥。绑定信息10分钟内有效。</Typography.Paragraph>
        </div>}
        {(setup || action !== 'setup') && <>
          <Form.Item field="code" label={recovery ? '恢复码' : '验证器验证码'} rules={[{ required: true, message: recovery ? '请输入恢复码' : '请输入验证码' }, { match: recovery ? /^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{8}){3})$/ : /^[0-9]{6}$/, message: recovery ? '恢复码格式不正确' : '请输入6位数字验证码' }]}><Input placeholder={recovery ? '请输入未使用的恢复码' : '请输入6位验证码'} inputMode={recovery ? 'text' : 'numeric'} autoComplete="one-time-code" maxLength={recovery ? 35 : 6} /></Form.Item>
          {action !== 'setup' && <Link onClick={() => { setRecovery(!recovery); form.setFieldsValue({ code: '' }) }}>{recovery ? '使用验证器' : '使用恢复码'}</Link>}
          <Typography.Paragraph type="secondary">确认后其他浏览器的旧登录将失效。{action === 'recovery-codes' ? '原有恢复码全部作废。' : ''}</Typography.Paragraph>
        </>}
      </Form>
    </Modal>
    <Modal visible={codes.length > 0} title="保存一次性恢复码" maskClosable onCancel={() => setCodes([])} footer={<Space wrap><Button onClick={download}>下载恢复码</Button><Button type="primary" onClick={() => setCodes([])}>已妥善保存</Button></Space>} style={{ width: 'min(520px, calc(100vw - 32px))' }}>
      <Alert type="warning" content="恢复码仅展示这一次，每组只能使用一次。请离线妥善保存，不要与密码放在一起。关闭后无法再次查看，可重新验证后重置。" />
      <div style={{ display: 'grid', gap: 8, paddingTop: 16, fontFamily: 'monospace', overflowWrap: 'anywhere' }}>{codes.map(code => <div key={code}>{code}</div>)}</div>
    </Modal>
  </Card>
}
