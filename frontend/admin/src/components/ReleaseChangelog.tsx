import type { ReactNode } from 'react'
import { Descriptions } from '@arco-design/web-react'
import type { ReleaseChangelog as ReleaseChangelogData } from '../api/officialPlatformApi'
import './ReleaseChangelog.css'

const SECTIONS = [
  ['added', '新增功能'],
  ['improved', '优化改进'],
  ['fixed', '问题修复'],
  ['notices', '升级注意事项'],
] as const

/** 展示已验签的目标版本日志，隐藏空分类，条目只按文本渲染。 */
export default function ReleaseChangelog({ changelog, title = '更新日志', className }: {
  changelog: ReleaseChangelogData
  title?: ReactNode
  className?: string
}) {
  const data = SECTIONS.filter(([key]) => changelog[key].length > 0).map(([key, label]) => ({
    label,
    value: <ul className="release-changelog-items">
      {changelog[key].map((item, index) => <li key={`${key}-${index}`}>{item}</li>)}
    </ul>,
  }))
  return <Descriptions title={title} column={1} tableLayout="fixed" border className={className}
    labelStyle={{ width: 112 }} valueStyle={{ overflowWrap: 'anywhere' }}
    data={data.length ? data : [{ label: '更新说明', value: '暂无更新日志' }]} />
}
