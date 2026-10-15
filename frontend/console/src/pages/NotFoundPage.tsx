import { Button, Result } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'
import { paths } from '../routes'

/** 深层未知路径展示明确的 404，并提供可用的恢复入口。 */
export default function NotFoundPage() {
  const history = useHistory()
  return <Result status="404" title="页面不存在" subTitle="请检查访问地址，或返回控制台。"
    extra={<Button type="primary" onClick={() => history.replace(paths.overview)}>返回控制台</Button>} />
}
