import { useMemo } from 'react'
import type { IPieChartSpec } from '@visactor/vchart/esm/chart/pie'
import { fileTypes, type FileStatistics } from '../api/filesApi'
import AnalysisChart from './AnalysisChart'

const colors: Record<string, string> = { image: '#F7BA1E', document: '#165DFF', video: '#722ED1', audio: '#00B42A', other: '#86909C' }
const capacity = (value: number) => value < 1024 ? `${value} B` : value < 1048576 ? `${(value / 1024).toFixed(1)} KiB` : value < 1073741824 ? `${(value / 1048576).toFixed(1)} MiB` : `${(value / 1073741824).toFixed(1)} GiB`

/** 环形图按容量占比展示；文件数量和容量采用同一份服务端统计。 */
export default function FileStorageChart({ stats }: { stats: FileStatistics }) {
  const spec = useMemo<IPieChartSpec>(() => {
    const values = stats.types.filter(item => item.byte_size > 0).map(item => ({
      ...item, name: fileTypes[item.type as keyof typeof fileTypes] ?? '其他',
      share: `${(item.byte_size / stats.byte_size * 100).toFixed(1)}%`,
    }))
    return { type: 'pie', data: { id: 'file-storage', values }, valueField: 'byte_size', categoryField: 'name',
      innerRadius: 0.75, outerRadius: 1, padding: 0, animation: false, background: 'transparent',
      label: { visible: false }, legends: { visible: false }, color: values.map(item => colors[item.type] ?? colors.other),
      tooltip: { visible: true, activeType: ['mark'], className: 'file-storage-tooltip',
        mark: { title: { value: datum => String(datum?.name ?? '') }, content: [
          { key: '文件数量', value: datum => `${datum?.count ?? 0} 个` },
          { key: '已上传容量', value: datum => capacity(Number(datum?.byte_size ?? 0)) },
          { key: '容量占比', value: datum => String(datum?.share ?? '') },
        ] } },
    }
  }, [stats])
  return <AnalysisChart spec={spec} label="按文件类型统计上传容量" />
}
