import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Checkbox, Form, Input, Message, Modal, Radio, Select, Spin, Typography } from '@arco-design/web-react'
import { getInstanceOperations, submitInstanceOperation } from '../api/serviceInstanceApi'
import { operationLabels, operationStatuses, operationWarning, operationPasswordError, powerLabels, type InstanceOperations } from '../../../packages/service-operations'
import { renewalRequestKey } from '../../../packages/service-renewal'

/** 仅保留当前弹窗的密码，关闭即销毁；受理后清空密码，任务状态独立轮询。 */
export default function InstanceOperationsModal({ id, close, changed, openVnc }: { id: number; close: () => void; changed: () => void; openVnc: () => void }) {
  const [form] = Form.useForm(), [data, setData] = useState<InstanceOperations>(), [action, setAction] = useState('')
  const [error, setError] = useState(''), [submitting, setSubmitting] = useState(false), [revision, setRevision] = useState(0)
  const key = useRef(renewalRequestKey()), busy = useRef(false), mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => {
    const controller = new AbortController()
    void getInstanceOperations(id, controller.signal).then(value => { if (!controller.signal.aborted) { setData(value); setError('') } })
      .catch(failure => { if (!controller.signal.aborted) setError(failure instanceof Error ? failure.message : '实例操作读取失败') })
    return () => controller.abort()
  }, [id, revision])
  useEffect(() => {
    if (!data?.busy) return
    const timer = window.setInterval(() => { if (document.visibilityState === 'visible') setRevision(value => value + 1) }, 5000)
    return () => window.clearInterval(timer)
  }, [data?.busy])
  const secret = ['reinstall', 'reset_password'].includes(action)
  const choose = (value: string) => { setAction(value); form.resetFields(); key.current = renewalRequestKey() }
  const submit = async () => {
    if (busy.current || !action || !data?.actions.includes(action) || data.busy) return
    let values: Record<string, unknown>
    try { values = await form.validate() } catch { return }
    if (busy.current) return
    busy.current = true; setSubmitting(true)
    try {
      await submitInstanceOperation(id, { action, request_key: key.current, acknowledged: true,
        ...(secret ? { password: String(values.password) } : {}), ...(action === 'reinstall' ? { image_id: Number(values.image_id) } : {}) })
      Message.success('操作已受理，请等待供应商确认结果')
      changed()
      if (mounted.current) { form.resetFields(); setAction(''); setRevision(value => value + 1); key.current = renewalRequestKey() }
    } catch (failure) { Message.error(failure instanceof Error ? failure.message : '操作提交失败') }
    finally { busy.current = false; if (mounted.current) setSubmitting(false) }
  }
  return <Modal title="云服务器管理" visible maskClosable onCancel={close} unmountOnExit style={{ width: 'min(640px, calc(100vw - 32px))' }}
    footer={<><Button onClick={close}>关闭</Button><Button type="primary" loading={submitting} disabled={!action || data?.busy || Boolean(error)} onClick={() => void submit()}>确认提交</Button></>}>
    {error && <Alert type="error" content={error} action={<Button onClick={() => setRevision(value => value + 1)}>重试</Button>} />}
    {!data && !error && <Spin />}
    {data && <>
      {data.vnc_available && <Button onClick={openVnc} style={{ marginBottom: 12 }}>VNC 远程控制台</Button>}<p>{powerLabels[data.power_status ?? 'unknown']}{data.power_checked_at && ` · 最近确认 ${data.power_checked_at}`}</p>
      {data.installed_image && <p>当前系统：{data.installed_image.name} · 用户名 {data.installed_image.default_username} · 端口 {data.installed_image.default_port}</p>}
      {data.busy ? <Alert type="info" content="实例已有运维任务，请等待完成；长时间未确认时联系管理员核查。" /> : data.actions.length ? <Radio.Group type="button" value={action} disabled={submitting} onChange={choose} style={{ display: 'flex', flexWrap: 'wrap', marginBottom: 16 }}>
        {data.actions.map(value => <Radio key={value} value={value}>{operationLabels[value]}</Radio>)}
      </Radio.Group> : <Alert type="info" content={data.vnc_available ? '当前仅开放 VNC 远程控制台。' : '当前实例状态或供应插件不支持运维操作，或没有操作权限。'} />}
      {action && !data.busy && <Form form={form} layout="vertical" disabled={submitting} onValuesChange={() => { key.current = renewalRequestKey() }}>
        <Alert type={action === 'reinstall' ? 'error' : 'warning'} content={operationWarning(action)} style={{ marginBottom: 16 }} />
        {action === 'reinstall' && <Form.Item label="重装镜像" field="image_id" rules={[{ required: true, message: '请选择重装镜像' }]}><Select placeholder="选择适合此实例的镜像" options={data.images.map(image => ({ value: image.id, label: `${image.name}（${image.default_username} / ${image.default_port}）` }))} /></Form.Item>}
        {secret && <><Form.Item label="新登录密码" field="password" rules={[{ required: true, message: '请输入新登录密码' }, { validator: (value, callback) => callback(operationPasswordError(value ?? '')) }]}><Input type="password" maxLength={64} autoComplete="new-password" /></Form.Item>
          <Form.Item label="确认密码" field="confirm_password" rules={[{ required: true, message: '请再次输入新密码' }, { validator: (value, callback) => callback(value === form.getFieldValue('password') ? undefined : '两次密码不一致') }]}><Input type="password" maxLength={64} autoComplete="new-password" /></Form.Item></>}
        <Form.Item field="acknowledged" triggerPropName="checked" rules={[{ validator: (value, callback) => callback(value === true ? undefined : '请确认操作影响') }]}><Checkbox>我已了解操作影响{action === 'reinstall' ? '，并已备份系统盘数据' : ''}</Checkbox></Form.Item>
      </Form>}
      <Typography.Title heading={6}>最近操作</Typography.Title>
      {data.history.length ? data.history.map(task => <div key={task.id} style={{ display: 'flex', gap: 12, justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid var(--color-border-2)', flexWrap: 'wrap' }}>
        <span>{operationLabels[task.action]} · {operationStatuses[task.status] ?? '未知状态'}</span><Typography.Text type="secondary">{task.created_at}</Typography.Text>
      </div>) : <Typography.Text type="secondary">暂无操作记录</Typography.Text>}
    </>}
  </Modal>
}
