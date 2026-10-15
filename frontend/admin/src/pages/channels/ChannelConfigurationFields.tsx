import { Form, Input, InputNumber, Link, Select, Space, Switch } from '@arco-design/web-react'
import type { ChannelConfigurationField } from '../../api/channelApi'

interface Props {
  fields: ChannelConfigurationField[]
  configuredSecrets: Record<string, boolean>
  clearSecrets: string[]
  onClearSecret: (key: string) => void
  onSecretChange: (key: string) => void
  readOnly: boolean
}

/** 宿主渲染受限配置字段；密码不回填，整数按钮模式，单位使用后缀。 */
export default function ChannelConfigurationFields({ fields, configuredSecrets, clearSecrets, onClearSecret, onSecretChange, readOnly }: Props) {
  return <>{fields.map(field => {
    const storedSecret = configuredSecrets[field.key] === true
    const secret = field.type === 'password' || Object.hasOwn(configuredSecrets, field.key)
    const hint = secret && storedSecret
      ? <Space size="mini"><span>{clearSecrets.includes(field.key) ? '保存后清除该设置' : '已设置，留空保持原设置'}</span>
        {!readOnly && !field.required && <Link onClick={() => onClearSecret(field.key)}>清除</Link>}</Space>
      : field.description
    return <Form.Item key={field.key} field={`configuration.${field.key}`} label={field.label}
      initialValue={secret ? '' : field.default ?? (field.type === 'boolean' ? false : undefined)}
      triggerPropName={field.type === 'boolean' && !secret ? 'checked' : 'value'} extra={hint}
      rules={[
        { required: field.required && field.type !== 'boolean' && !(secret && storedSecret), message: `请输入${field.label}` },
        ...(field.max_length ? [{ maxLength: field.max_length }] : []),
        ...(field.type === 'integer' ? [{ type: 'number' as const, min: field.minimum, max: field.maximum }] : []),
        ...(field.type === 'url' && !secret ? [{ type: 'url' as const, message: '请输入有效地址' }] : []),
      ]}>
      {secret ? <Input.Password placeholder={storedSecret ? '留空保持原设置' : field.placeholder || `请输入${field.label}`}
        maxLength={field.max_length} autoComplete="new-password" onChange={value => { if (value) onSecretChange(field.key) }} />
        : field.type === 'boolean' ? <Switch type="round" checkedText="启用" uncheckedText="禁用" />
          : field.type === 'integer' ? <InputNumber mode="button" precision={0} min={field.minimum} max={field.maximum} suffix={field.unit}
            placeholder={field.placeholder || `请输入${field.label}`} style={{ width: '100%' }} />
            : field.type === 'select' ? <Select options={field.options} placeholder={field.placeholder || `请选择${field.label}`} />
              : <Input placeholder={field.placeholder || `请输入${field.label}`} suffix={field.unit} maxLength={field.max_length} autoComplete="off" />}
    </Form.Item>
  })}</>
}
