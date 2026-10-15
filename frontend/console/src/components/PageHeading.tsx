import { Breadcrumb, Typography } from '@arco-design/web-react'
import type { ReactNode } from 'react'

/** 业务页面负责标题与面包屑，Layout 不重复渲染。 */
export default function PageHeading({ title, group, description, action }: {
  title: string; group?: string; description: string; action?: ReactNode
}) {
  return <header className="page-heading">
    <Breadcrumb><Breadcrumb.Item>会员控制台</Breadcrumb.Item>{group && <Breadcrumb.Item>{group}</Breadcrumb.Item>}<Breadcrumb.Item>{title}</Breadcrumb.Item></Breadcrumb>
    <div className="page-heading-row"><div>
      <Typography.Title heading={4} style={{ margin: '16px 0 8px' }}>{title}</Typography.Title>
      <Typography.Text type="secondary">{description}</Typography.Text>
    </div>{action}</div>
  </header>
}
