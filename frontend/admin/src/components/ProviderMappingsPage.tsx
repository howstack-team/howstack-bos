import { Alert, Drawer, Spin } from '@arco-design/web-react'
import type { MappingConnection } from '../api/cloudProductApi'
import PageFormEditor from './PageFormEditor'
import ProviderResourceMappings from './ProviderResourceMappings'

/** 资源对应操作即时提交；云服务器工作台以抽屉承载，其他入口可保留独立页面。 */
export default function ProviderMappingsPage({ connection, loading, error, onBack, mode = 'page' }: {
  connection?: MappingConnection
  loading: boolean
  error: string
  onBack: () => void
  mode?: 'page' | 'drawer'
}) {
  const title = `资源对应${connection ? ` · ${connection.name}` : ''}`
  const content = error ? <Alert type="error" content={error} /> : loading ? <Spin />
      : connection ? <ProviderResourceMappings key={connection.id} connection={connection} />
        : <Alert type="error" content="资源供应商不存在或无权访问" />
  if (mode === 'drawer') return <Drawer visible title={title} width="min(1040px, calc(100vw - 24px))" footer={null} onCancel={onBack}>{content}</Drawer>
  return <PageFormEditor title={title} saving={false} onCancel={onBack}>
    {content}
  </PageFormEditor>
}
