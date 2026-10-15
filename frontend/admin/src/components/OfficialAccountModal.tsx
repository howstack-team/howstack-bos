import { useCallback, useEffect, useRef, useState } from 'react'
import { Alert, Button, Descriptions, Form, Input, Message, Modal, Space, Typography } from '@arco-design/web-react'
import { useAdminAuth } from '../auth/AuthContext'
import { ApiRequestError } from '../api/httpClient'
import { officialPlatformApi as api, platformError, type OfficialAccount } from '../api/officialPlatformApi'
import { DESCRIPTION_COLUMNS } from '../utils/descriptionColumns'

/** 授权码激活弹窗；校验失败不提交，秘密只在当前未提交表单存在。 */
export default function OfficialAccountModal({ visible, onCancel, onConnected }: {
  visible: boolean
  onCancel: () => void
  onConnected?: () => void
}) {
  const { permissions } = useAdminAuth()
  const [form] = Form.useForm<{ activation_code: string }>()
  const [account, setAccount] = useState<OfficialAccount>()
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const locked = useRef(false)
  const canActivate = permissions.includes('admin.platform.connect') && permissions.includes('admin.license.manage')
  const load = useCallback(async (signal?: AbortSignal) => {
    try { const value = await api.account(signal); if (!signal?.aborted) { setAccount(value); setError('') } }
    catch (cause) { if (!signal?.aborted) setError(platformError(cause)) }
  }, [])
  useEffect(() => {
    if (!visible) return undefined
    const controller = new AbortController()
    form.resetFields()
    void Promise.resolve().then(() => { if (!controller.signal.aborted) void load(controller.signal) })
    return () => controller.abort()
  }, [form, load, visible])
  const activate = async () => {
    if (locked.current || !canActivate) return
    let values: { activation_code: string }
    try { values = await form.validate() } catch { return }
    locked.current = true; setBusy(true)
    try { await api.activate(values.activation_code.trim()); form.resetFields(); await load(); onConnected?.(); Message.success('授权激活成功') }
    catch (cause) {
      const data = cause instanceof ApiRequestError ? cause.data : null
      const fields = data && typeof data === 'object' && 'errors' in data ? data.errors : null
      const errors = fields && typeof fields === 'object' && 'activation_code' in fields ? fields.activation_code : null
      if (Array.isArray(errors) && errors.every(value => typeof value === 'string') && errors.length) form.setFields({ activation_code: { error: { message: errors[0] } } })
      else Message.error(platformError(cause))
    } finally { locked.current = false; setBusy(false) }
  }
  const disconnect = () => Modal.confirm({ maskClosable: true, title: '解除授权绑定？', content: '旧证书租约结束前仍占用安装名额，软件数据和更新记录会保留。', onOk: async () => {
    if (locked.current) return
    locked.current = true; setBusy(true)
    try { await api.disconnect(); await load(); onConnected?.(); Message.success('授权绑定已解除') }
    catch (cause) { Message.error(platformError(cause)); throw cause }
    finally { locked.current = false; setBusy(false) }
  } })
  return <Modal maskClosable title="授权码激活" visible={visible} onCancel={() => { form.resetFields(); onCancel() }} footer={null} style={{ width: 560, maxWidth: 'calc(100vw - 32px)' }}>
    <div className="official-account-panel"><Typography.Paragraph type="secondary">在皓量云擎官网购买后，将“我的授权”中的授权码填入即可。续费后自动同步，无需更换授权码。</Typography.Paragraph>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <Descriptions column={DESCRIPTION_COLUMNS} border tableLayout="fixed" valueStyle={{ overflowWrap: 'anywhere' }} data={[
      { label: '授权版本', value: account?.connection?.edition_name || '社区版' },
      { label: '授权状态', value: account?.connection?.license_valid ? '有效' : account?.connection?.connection_mode === 'licensed' ? '已到期或已解除' : '无需商业授权' },
      { label: '上次同步', value: account?.checked_at || '-' },
    ]} />
    {canActivate && <Form form={form} layout="vertical" style={{ marginTop: 20 }} onSubmit={() => void activate()}>
      <Form.Item field="activation_code" label="授权码" rules={[{ required: true, message: '请输入授权码' }, { match: /^HS-(?:[A-Fa-f0-9]{8}-){5}[A-Fa-f0-9]{8}$/, message: '请输入完整的授权码' }]}>
        <Input.Password placeholder="请输入软件授权码" autoComplete="off" disabled={busy} />
      </Form.Item>
      <Space wrap><Button type="primary" htmlType="submit" loading={busy}>激活授权</Button>
        {account?.connection?.connection_mode === 'licensed' && <Button loading={busy} onClick={async () => { if (locked.current) return; locked.current = true; setBusy(true); try { await api.refreshLicense(); await load(); onConnected?.(); Message.success('授权已同步') } catch (cause) { Message.error(platformError(cause)) } finally { locked.current = false; setBusy(false) } }}>同步授权</Button>}
        {account?.connected && account.connection?.connection_mode === 'licensed' && <Button status="danger" disabled={busy} onClick={disconnect}>解除绑定</Button>}
      </Space>
    </Form>}
    </div>
  </Modal>
}
