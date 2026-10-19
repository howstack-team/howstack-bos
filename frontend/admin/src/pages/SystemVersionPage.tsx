import { useEffect, useState } from 'react'
import { Alert, Button, Descriptions, Spin } from '@arco-design/web-react'
import { request } from '../api/httpClient'

/** 只读取本机版本，不连接商业官网或触发在线升级。 */
export default function SystemVersionPage() {
  const [version, setVersion] = useState(''), [error, setError] = useState(''), [loading, setLoading] = useState(true)
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    void request<{ current_version: string }>({ url: '/system/version', signal: controller.signal }).then(data => {
      if (!data || typeof data.current_version !== 'string' || !data.current_version.trim()) throw new Error('版本信息格式无效')
      if (!controller.signal.aborted) { setVersion(data.current_version); setError('') }
    }).catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '版本读取失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [revision])
  return <section className="page-card">
    {error && <Alert type="error" content={error} action={<Button onClick={() => setRevision(value => value + 1)}>重试</Button>} />}
    <Spin loading={loading}><Descriptions title="系统版本" column={1} data={[
      { label: '当前版本', value: error ? '-' : version || '-' },
      { label: '交付范围', value: '社区版 1.0' },
      { label: '社区核心', value: '无需商业授权' },
      { label: '系统维护', value: '升级前请备份数据库、私有配置与用户文件，由服务器管理员按发行说明执行。' },
    ]} /></Spin>
  </section>
}
