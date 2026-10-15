import { Button, Form, Input, InputNumber, Select, Switch, Typography } from '@arco-design/web-react'
import { IconPlus, IconDelete } from '@arco-design/web-react/icon'
import type { ProviderParameterRule } from '../api/cloudProductApi'
const sources = ['region.external_id', 'zone.external_id', 'line.external_id', 'flavor_group.external_id', 'flavor.external_id', 'flavor.cpu', 'flavor.memory_mib', 'flavor.architecture', 'image.external_id', 'image.default_username', 'image.default_port', 'system_disk.type.external_id', 'system_disk.size_gib', 'data_disks', 'network.egress_mbps', 'network.ingress_mbps', 'network.ipv4_count', 'protection.external_id', 'quantity', 'duration.count', 'duration.unit', 'login_method']
/** 只配置无凭据的标准字段；复杂数组组装在供应插件中完成。 */
export default function ProviderParameterEditor({ value = [], onChange }: { value?: ProviderParameterRule[]; onChange?: (v: ProviderParameterRule[]) => void }) {
  const update = (index: number, patch: Partial<ProviderParameterRule>) => onChange?.(value.map((row, i) => i === index ? { ...row, ...patch } : row))
  return <div><Typography.Text type="secondary">资源编号先按可用区对应，再转换为供应商请求参数。留空使用插件原生转换；密码由独立凭据接口处理。</Typography.Text>
    {value.map((row, index) => <section key={index} className="cloud-section">
      <div className="cloud-form-grid">
        <Form.Item label="标准字段"><Select placeholder="请选择标准字段" showSearch value={row.source} options={sources} onChange={source => update(index, { source })} /></Form.Item>
        <Form.Item label="供应参数"><Input placeholder="例如 server.cpu" maxLength={128} value={row.target} onChange={target => update(index, { target })} /></Form.Item>
        <Form.Item label="转换方式"><Select placeholder="请选择转换方式" value={row.transform} options={[{ value: 'copy', label: '原值' }, { value: 'string', label: '转为文本' }, { value: 'multiply', label: '乘系数' }, { value: 'divide', label: '除系数' }, { value: 'lookup', label: '枚举对应' }]} onChange={transform => update(index, { transform })} /></Form.Item>
        {['multiply', 'divide'].includes(row.transform) && <Form.Item label="转换系数"><InputNumber mode="button" placeholder="请输入系数" min={1} max={1000000} precision={0} value={row.factor} onChange={factor => update(index, { factor: factor ?? 1 })} /></Form.Item>}
        <Form.Item label="必须传递"><Switch type="round" checked={row.required} onChange={required => update(index, { required })} /></Form.Item>
      </div>
      {row.transform === 'lookup' && <Form.Item label="枚举对应" extra='JSON对象，例如 {"month":"monthly"}。' ><Input.TextArea placeholder='{"month":"monthly"}' value={typeof row.lookup === 'string' ? row.lookup : JSON.stringify(row.lookup)} onChange={text => { try { const parsed: unknown = JSON.parse(text); update(index, { lookup: parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed as Record<string, string | number> : text }) } catch { update(index, { lookup: text }) } }} /></Form.Item>}
      <Button icon={<IconDelete />} onClick={() => onChange?.(value.filter((_, i) => i !== index))}>删除对应</Button>
    </section>)}
    <Button icon={<IconPlus />} disabled={value.length >= 64} onClick={() => onChange?.([...value, { source: 'flavor.cpu', target: '', transform: 'copy', factor: 1, lookup: {}, required: true }])}>增加参数对应</Button>
  </div>
}
