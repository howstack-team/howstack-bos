import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Drawer, Form, Input, InputNumber, Message, Space, Spin, Switch } from '@arco-design/web-react'
import { channelApi, type ChannelCategory, type ChannelDetail, type ChannelInput, type ChannelPlatform } from '../../api/channelApi'
import { ApiRequestError } from '../../api/httpClient'
import PageFormEditor from '../../components/PageFormEditor'
import ChannelConfigurationFields from './ChannelConfigurationFields'

interface Props {
  category: ChannelCategory
  id: number
  pluginId: string
  platforms: ChannelPlatform[]
  defaultLabel: string | null
  canSave: boolean
  onClose: () => void
  onSaved: () => void
  onPageMode: (pageMode: boolean) => void
}

/** 中等配置使用抽屉，较长动态配置使用带URL状态的完整页面。 */
export default function ChannelEditor({ category, id, pluginId, platforms, defaultLabel, canSave, onClose, onSaved, onPageMode }: Props) {
  const [form] = Form.useForm<ChannelInput>()
  const [editing, setEditing] = useState<ChannelDetail | null>(null)
  const [loading, setLoading] = useState(id > 0)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  const [saving, setSaving] = useState(false)
  const [clearSecrets, setClearSecrets] = useState<string[]>([])
  const [enabled, setEnabled] = useState(false)
  const submitLock = useRef(false)
  const mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])

  useEffect(() => {
    const controller = new AbortController()
    form.resetFields()
    if (id === 0) {
      form.setFieldsValue({ name: '', plugin_id: pluginId, configuration: {}, status: 0, is_default: false, sort_order: 0, description: '', revision: 0 })
      return () => controller.abort()
    }
    void channelApi.get(category, id, controller.signal).then(row => {
      if (controller.signal.aborted) return
      if (row.plugin_id !== pluginId) throw new Error('插件与配置不匹配，请返回列表重新选择')
      setEditing(row)
      setEnabled(row.status === 1)
      form.setFieldsValue({ ...row, configuration: { ...row.configuration } })
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '通道配置加载失败')
    }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [category, id, pluginId, form, retry])

  const platform = platforms.find(item => item.id === pluginId && item.platform_available)
  const fields = editing && !platform ? editing.configuration_fields : platforms.find(item => item.id === pluginId)?.configuration_fields ?? []
  const editable = canSave && Boolean(platform) && !loading && !error
  const formEditable = editable
  const title = `${platforms.find(item => item.id === pluginId)?.name ?? editing?.platform_name ?? '插件'}配置`
  const pageMode = fields.length > 12
  useEffect(() => { onPageMode(pageMode) }, [pageMode, onPageMode])

  async function save() {
    if (!editable || submitLock.current) return
    let values: ChannelInput
    try { values = await form.validate() } catch { return }
    submitLock.current = true
    setSaving(true)
    try {
      const configuration = Object.fromEntries(fields.map(field => [field.key,
        values.configuration?.[field.key] ?? field.default ?? (field.type === 'boolean' ? false : ''),
      ]))
      await channelApi.save(category, {
        name: editing?.name ?? platform?.name ?? '', plugin_id: pluginId, configuration, clear_secrets: clearSecrets,
        status: values.status, is_default: Boolean(defaultLabel) && values.status === 1 && Boolean(values.is_default),
        sort_order: values.sort_order, description: values.description?.trim() ?? '', revision: editing?.revision ?? 0,
      }, id || undefined)
      if (mounted.current) { Message.success('插件配置已保存'); onSaved() }
    } catch (cause) {
      if (!mounted.current) return
      if (cause instanceof ApiRequestError && cause.status === 422 && cause.data && typeof cause.data === 'object' && 'errors' in cause.data) {
        const errors = cause.data.errors
        if (errors && typeof errors === 'object') for (const [field, messages] of Object.entries(errors)) {
          if (Array.isArray(messages) && typeof messages[0] === 'string') form.setFields({ [field]: { error: { message: messages[0] } } })
        }
      }
      Message.error(cause instanceof Error ? cause.message : '通道配置保存失败')
    } finally {
      submitLock.current = false
      if (mounted.current) setSaving(false)
    }
  }

  const content = <>
    {error && <Alert type="error" content={error} action={<Button onClick={() => { setLoading(true); setError(''); setRetry(value => value + 1) }}>重新加载</Button>} />}
    {!loading && !platform && <Alert type="warning" content="服务平台当前不可用，可查看配置；恢复对应应用后再编辑。" style={{ marginBottom: 16 }} />}
    <Spin loading={loading} style={{ width: '100%' }}>
      <Form form={form} layout="vertical" disabled={!formEditable || saving} onSubmit={() => void save()}>
        <ChannelConfigurationFields key={pluginId} fields={fields} configuredSecrets={editing?.configured_secrets ?? {}}
          clearSecrets={clearSecrets} readOnly={!formEditable}
          onClearSecret={key => { setClearSecrets(keys => [...keys.filter(value => value !== key), key]); form.setFieldValue('configuration', { ...(form.getFieldValue('configuration') as ChannelInput['configuration']), [key]: '' }) }}
          onSecretChange={key => setClearSecrets(keys => keys.filter(value => value !== key))} />
        <Form.Item field="sort_order" label="排序" extra="数值越小，展示越靠前。" rules={[{ required: true }, { type: 'number', min: 0, max: 999999 }]}>
          <InputNumber mode="button" precision={0} min={0} max={999999} placeholder="请输入排序值" style={{ width: '100%' }} />
        </Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={value => value === 1} normalize={value => value ? 1 : 0}>
          <Switch type="round" checkedText="启用" uncheckedText="禁用" onChange={value => { setEnabled(value); if (!value) form.setFieldValue('is_default', false) }} />
        </Form.Item>
        {defaultLabel && <Form.Item field="is_default" label={defaultLabel} triggerPropName="checked"
          extra={category === 'storage' ? '新文件使用此存储；已有文件保留原存储位置。启用后才可设置。' : '同类只能设置一个主用通道；业务也可明确指定其他已启用通道。启用后才可设置。'}>
          <Switch type="round" checkedText="是" uncheckedText="否" disabled={!formEditable || !enabled} />
        </Form.Item>}
        <Form.Item field="description" label="备注"><Input.TextArea rows={2} maxLength={500} showWordLimit placeholder="请输入备注（选填，请勿填写密钥或密码）" /></Form.Item>
      </Form>
    </Spin>
  </>
  if (pageMode) return <PageFormEditor title={title} saving={saving} onCancel={onClose}
    onSave={canSave ? () => void save() : undefined} disabled={!editable}>{content}</PageFormEditor>
  return <Drawer title={title} visible maskClosable onCancel={onClose} width="min(560px, calc(100vw - 24px))"
    footer={<Space><Button onClick={onClose}>{canSave ? '取消' : '关闭'}</Button>
      {canSave && <Button type="primary" loading={saving} disabled={!editable} onClick={() => void save()}>保存</Button>}</Space>}>
    {content}
  </Drawer>
}
