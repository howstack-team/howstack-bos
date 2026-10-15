import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Form, Input, Message, Modal } from '@arco-design/web-react'
import { channelApi, type ChannelRecord, type MailTestResult } from '../../api/channelApi'

/** 使用已保存配置测试单封邮件，结果未知时不引导自动重发。 */
export default function MailTestDialog({ channel, onClose }: { channel: ChannelRecord; onClose: () => void }) {
  const [form] = Form.useForm<{ recipient: string }>()
  const [sending, setSending] = useState(false)
  const [result, setResult] = useState<MailTestResult | null>(null)
  const [error, setError] = useState('')
  const lock = useRef(false)
  const requestKey = useRef(crypto.randomUUID())
  const mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])

  async function send() {
    if (lock.current || result) return
    let values: { recipient: string }
    try { values = await form.validate() } catch { return }
    lock.current = true
    setSending(true)
    setError('')
    try {
      const response = await channelApi.testMail(channel, values.recipient.trim(), requestKey.current)
      if (mounted.current) setResult(response)
      else if (response.status === 'accepted') Message.success(response.message)
      else Message.warning(response.message)
    } catch (cause) {
      if (mounted.current) setError(`${cause instanceof Error ? cause.message : '未能取得发送结果'}。若已提交，请先确认邮箱；再次点击将使用同一请求标识查询，避免重复发送。`)
      else Message.warning('测试发送未取得结果，请先确认邮箱或服务器记录。')
    } finally {
      lock.current = false
      if (mounted.current) setSending(false)
    }
  }

  return <Modal visible maskClosable title="测试发送邮件" onCancel={onClose}
    style={{ width: 480, maxWidth: 'calc(100vw - 24px)' }}
    footer={<Button type="primary" loading={sending} onClick={result ? onClose : send}>{result ? '关闭' : error ? '查询发送结果' : '发送测试邮件'}</Button>}>
    <Form form={form} layout="vertical" onSubmit={send}>
      <Form.Item field="recipient" label="收件邮箱" rules={[{ required: true, message: '请输入收件邮箱' }, { type: 'email', message: '请输入有效的邮箱地址' }]}
        extra={`使用「${channel.name}」已保存的配置发送一封测试邮件；无需先启用通道。`}>
        <Input placeholder="请输入用于接收测试邮件的邮箱" maxLength={254} disabled={sending || Boolean(result) || Boolean(error)} />
      </Form.Item>
    </Form>
    {error && <Alert type="warning" content={error} />}
    {result && <Alert type={result.status === 'accepted' ? 'success' : result.status === 'unknown' ? 'warning' : 'error'} content={result.message} />}
  </Modal>
}
