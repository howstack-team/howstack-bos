import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Drawer, Form, Input, Message, Select } from '@arco-design/web-react'
import { orderManagementApi } from '../api/orderApi'
import type { OrderProduct } from '../api/orderApi'
import type { ComputeSelection, PricePreview } from '../api/cloudProductApi'
import ComputeConfigurationPreview from './ComputeConfigurationPreview'
import '../pages/cloud-products.css'

export default function OrderCreateDrawer({ visible, onCancel, onSaved }: { visible: boolean; onCancel: () => void; onSaved: (id: number) => void }) {
  const [products, setProducts] = useState<OrderProduct[]>([])
  const [members, setMembers] = useState<Array<{ id: number; label: string }>>([])
  const [memberId, setMemberId] = useState<number>()
  const [offerId, setOfferId] = useState<number>()
  const [remark, setRemark] = useState('')
  const [memberSearch, setMemberSearch] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const attempt = useRef<{ signature: string; key: string } | null>(null)
  const product = products.find(row => row.id === offerId)
  useEffect(() => {
    if (!visible) return
    let active = true
    const timer = window.setTimeout(() => {
      setProducts([]); setMembers([]); setMemberId(undefined); setOfferId(undefined); setRemark(''); setError(''); setMemberSearch(''); setLoading(true)
      attempt.current = null
      void orderManagementApi.products().then(data => { if (active) setProducts(data) })
        .catch(reason => { if (active) setError(reason instanceof Error ? reason.message : '产品加载失败') })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [visible])
  useEffect(() => {
    if (!visible) return
    let active = true
    const timer = window.setTimeout(() => {
      void orderManagementApi.members(memberSearch).then(data => { if (active) setMembers(data) })
        .catch(reason => { if (active) setError(reason instanceof Error ? reason.message : '会员加载失败') })
    }, 300)
    return () => { active = false; window.clearTimeout(timer) }
  }, [visible, memberSearch])
  const create = async (selection: ComputeSelection, quote: PricePreview) => {
    if (!memberId) { setError('请先选择会员'); return }
    if (!product || saving) return
    const payload = { member_id: memberId, offer_id: product.id, version_id: product.version_id, selection, expected_amount_minor: quote.amount_minor, remark }
    const signature = JSON.stringify(payload)
    if (attempt.current?.signature !== signature) attempt.current = { signature, key: crypto.randomUUID() }
    setSaving(true); setError('')
    try {
      const result = await orderManagementApi.create({ ...payload, request_key: attempt.current.key })
      Message.success('订单已创建，等待付款'); onSaved(result.id)
    } catch (reason) { setError(reason instanceof Error ? reason.message : '创建订单失败') }
    finally { setSaving(false) }
  }
  return <Drawer title="创建订单" width="min(960px, calc(100vw - 24px))" visible={visible} maskClosable={!saving}
    onCancel={() => { if (!saving) onCancel() }} footer={<Button disabled={saving} onClick={onCancel}>取消</Button>}>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <Form layout="vertical" style={{ marginBottom: 16 }}>
      <div className="cloud-form-grid">
        <Form.Item label="会员" required><Select placeholder="搜索会员昵称、手机号、邮箱或 ID" showSearch filterOption={false} onSearch={setMemberSearch} value={memberId} onChange={setMemberId} disabled={saving} options={members.map(row => ({ value: row.id, label: row.label }))} /></Form.Item>
        <Form.Item label="产品" required extra={!loading && products.length === 0 ? '暂无已发布并启用的产品，请先完成产品配置并发布。' : undefined}>
          <Select placeholder="请选择已发布产品" loading={loading} disabled={saving} value={offerId} onChange={setOfferId} options={products.map(row => ({ value: row.id, label: row.name }))} />
        </Form.Item>
      </div>
      <Form.Item label="备注"><Input placeholder="请输入订单备注（选填）" maxLength={255} value={remark} disabled={saving} onChange={setRemark} /></Form.Item>
    </Form>
    {product?.product_type === 'cloud_server' && <ComputeConfigurationPreview key={`${product.id}:${product.version_id}`} offerId={product.id} versionId={product.version_id} onCreate={(selection, price) => void create(selection, price)} creating={saving} />}
    {product && product.product_type !== 'cloud_server' && <Alert type="info" content="该产品尚未提供后台购买配置界面。" />}
  </Drawer>
}
