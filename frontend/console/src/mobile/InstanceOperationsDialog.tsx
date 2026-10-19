import Button from '@arco-design/mobile-react/esm/button'
import { useEffect, useRef, useState } from 'react'
import Dialog from '@arco-design/mobile-react/esm/dialog'
import Form, { useForm } from '@arco-design/mobile-react/esm/form'
import Radio from '@arco-design/mobile-react/esm/radio'
import Checkbox from '@arco-design/mobile-react/esm/checkbox'
import { ValidatorType } from '@arco-design/mobile-utils'
import { getInstanceOperations, submitInstanceOperation } from '../api/serviceApi'
import { operationLabels, operationStatuses, operationWarning, operationPasswordError, powerLabels, type InstanceOperations } from '../../../packages/service-operations'
import { renewalRequestKey } from '../../../packages/service-renewal'
import { useFeedback } from './feedback'
import { Field } from './ui'

function Confirmation({ value = false, onChange }: { value?: boolean; onChange?: (value: boolean) => void }) {
  return <Checkbox value="acknowledged" checked={value} onChange={checked => onChange?.(checked)}>我已了解操作影响并做好数据备份</Checkbox>
}
/** 只使用移动组件；密码仅当前弹窗内存保存，前端校验成功后才请求接口。 */
export default function InstanceOperationsDialog({ id, close, changed, openVnc }: { id: number; close: () => void; changed: () => void; openVnc: () => void }) {
  const [form] = useForm(), feedback = useFeedback()
  const [data, setData] = useState<InstanceOperations>(), [error, setError] = useState(''), [action, setAction] = useState('')
  const [password, setPassword] = useState(''), [confirm, setConfirm] = useState(''), [submitting, setSubmitting] = useState(false), [revision, setRevision] = useState(0)
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
  const submit = async () => {
    if (busy.current || !data?.actions.includes(action) || data.busy || (secret && (operationPasswordError(password) || password !== confirm))) return
    busy.current = true; setSubmitting(true)
    try {
      await submitInstanceOperation(id, { action, request_key: key.current, acknowledged: true,
        ...(secret ? { password } : {}), ...(action === 'reinstall' ? { image_id: Number(form.getFieldValue('image_id')) } : {}) })
      feedback('操作已受理，请等待供应商确认结果', 'success'); changed()
      if (mounted.current) { setAction(''); setPassword(''); setConfirm(''); form.resetFields(); key.current = renewalRequestKey(); setRevision(value => value + 1) }
    } catch (failure) { feedback(failure instanceof Error ? failure.message : '操作提交失败') }
    finally { busy.current = false; if (mounted.current) setSubmitting(false) }
  }
  const choose = (value: string) => { setAction(value); setPassword(''); setConfirm(''); form.resetFields(); key.current = renewalRequestKey() }
  return <Dialog visible title="云服务器管理" maskClosable animatingClosable close={close} footer={[
    { content: '关闭', onClick: close },
    { content: submitting ? '正在提交…' : '确认提交', disabled: !action || data?.busy || submitting || Boolean(error), onClick: () => { form.submit(); return true } },
  ]}>
    <div className="m-instance-operations">
      {error ? <p role="alert">{error}</p> : !data ? <p>正在读取实例操作…</p> : <>
        {data.vnc_available && <Button inline size="small" onClick={openVnc}>VNC 远程控制台</Button>}
        <p>{powerLabels[data.power_status ?? 'unknown']}</p>{data.power_checked_at && <p className="m-muted">最近确认：{data.power_checked_at}</p>}
        {data.installed_image && <p>当前系统：{data.installed_image.name}<br />用户名 {data.installed_image.default_username} · 端口 {data.installed_image.default_port}</p>}
        {data.busy ? <p>实例任务处理中，请等待或联系管理员核查。</p> : data.actions.length ? <Radio.Group value={action} options={data.actions.map(value => ({ value, label: operationLabels[value], disabled: submitting }))} onChange={value => choose(String(value))} /> : <p>{data.vnc_available ? '当前仅开放 VNC 远程控制台。' : '当前实例状态或供应插件不支持操作。'}</p>}
        {action && !data.busy && <Form form={form} key={action} layout="vertical" disabled={submitting} onChange={() => { key.current = renewalRequestKey() }} onSubmit={() => { void submit() }}>
          <p className="m-operation-warning">{operationWarning(action)}</p>
          {action === 'reinstall' && <Form.Item field="image_id" label="重装镜像" rules={[{ type: ValidatorType.Custom, validator: (value, callback) => callback(Number.isSafeInteger(value) && Number(value) > 0 ? undefined : '请选择重装镜像') }]}><Radio.Group options={data.images.map(image => ({ value: image.id, label: image.name }))} /></Form.Item>}
          {secret && <><p>新登录密码</p><Field disabled={submitting} label="新密码" type="password" value={password} onChange={setPassword} validate={operationPasswordError} placeholder="请输入新登录密码" maxLength={64} autoComplete="new-password" />
            <p>确认密码</p><Field disabled={submitting} label="确认密码" type="password" value={confirm} onChange={setConfirm} validate={value => value && value === password ? undefined : '两次密码不一致'} placeholder="请再次输入密码" maxLength={64} autoComplete="new-password" /></>}
          <Form.Item field="acknowledged" initialValue={false} rules={[{ type: ValidatorType.Boolean, equal: true, message: '请确认操作影响' }]}><Confirmation /></Form.Item>
        </Form>}
        <h3>最近操作</h3>{data.history.length ? data.history.map(task => <div className="m-operation-history" key={task.id}><p>{operationLabels[task.action]} · {operationStatuses[task.status] ?? '未知状态'}</p><p className="m-muted">{task.created_at}</p></div>) : <p className="m-muted">暂无操作记录</p>}
      </>}
    </div>
  </Dialog>
}
